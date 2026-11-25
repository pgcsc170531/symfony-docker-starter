<?php

namespace App\Command;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\User;
use App\Service\Phone\PhoneNumber;
use App\Service\Students\FamilyLinker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconnects two logins that are one person.
 *
 * `/staff/new` and the staff CSV already promote an existing *parent* login when
 * the office hires them — one account, two doors. But a school that registered
 * the teacher **first** (or used `app:tenant:create-user`, or hired them before
 * that promotion existed) ends up with two rows: `aisha@school.com`
 * with ROLE_TEACHER, and `8039658200@edus.ng` with ROLE_PARENT and the children.
 * Signing in with the phone lands on the parent login only, the two never meet,
 * and there is no screen that can join them.
 *
 * This does that join, deliberately:
 *
 *   • the **staff** login survives — it is the one the office administers and
 *     carries the timetable, assignments and staff number;
 *   • every Guardian record (and therefore every child, application and bank
 *     account) moves to it, and it gains ROLE_PARENT;
 *   • the parent login is removed once nothing points at it any more.
 *
 * The parent login's password is NOT carried over — the person signs in with the
 * staff login from then on, which is why this is dry-run unless `--apply` and
 * prints both accounts in full before touching anything.
 */
#[AsCommand(
    name: 'app:tenant:link-staff-login',
    description: 'Reconnects a parent login to the staff login of the same person (dry-run unless --apply)',
)]
final class LinkStaffLoginCommand extends TenantAwareCommand
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        Connection $tenantConnection,
        EntityManagerInterface $tenantEm,
        private readonly FamilyLinker $familyLinker,
    ) {
        parent::__construct($landlordEm, $tenantConnection, $tenantEm);
    }

    protected function configure(): void
    {
        $this
            ->addOption('staff', null, InputOption::VALUE_REQUIRED, 'Staff login: their email address, or the phone number on their staff record')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Parent login: the login email, or the phone number it was built from')
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school, by subdomain')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (otherwise this is a dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔗 Reconnecting a parent login to a staff login');

        $staffRef = trim((string) $input->getOption('staff'));
        $parentRef = trim((string) $input->getOption('parent'));
        $apply = (bool) $input->getOption('apply');

        if ($staffRef === '' || $parentRef === '') {
            $io->error('Both --staff and --parent are required — they name the two logins that are one person.');

            return Command::INVALID;
        }

        $schools = $this->schools((string) $input->getOption('school'));

        if ($schools === []) {
            $io->error('No active school matched.');

            return Command::FAILURE;
        }

        $linked = 0;
        $failed = 0;

        foreach ($schools as $school) {
            $io->section(sprintf('%s (%s)', $school->getName(), $school->getSubdomain()));
            $this->switchTo($school);

            $outcome = $this->linkSchool($io, $staffRef, $parentRef, $apply);

            match ($outcome) {
                self::OUTCOME_LINKED => $linked++,
                self::OUTCOME_NOTHING => null,
                default => $failed++,
            };
        }

        $io->newLine();
        $io->text(sprintf('%d school(s): %d linked, %d left alone.', count($schools), $linked, $failed));

        if (!$apply && $linked > 0) {
            $io->warning('Dry run — nothing was written. Re-run the same command with --apply.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private const OUTCOME_LINKED = 1;
    private const OUTCOME_NOTHING = 2;
    private const OUTCOME_FAILED = 3;

    private function linkSchool(SymfonyStyle $io, string $staffRef, string $parentRef, bool $apply): int
    {
        $staffUser = $this->resolveStaff($staffRef);
        $parentUser = $this->resolveLogin($parentRef);

        if ($staffUser === null) {
            $io->text(sprintf('  ✗ No staff login matches "%s" here.', $staffRef));

            return self::OUTCOME_NOTHING;
        }

        if ($parentUser === null) {
            $io->text(sprintf('  ✗ No login matches "%s" here.', $parentRef));

            return self::OUTCOME_NOTHING;
        }

        if ($staffUser->getId() === $parentUser->getId()) {
            $io->text('  • These two references already resolve to the same account — nothing to reconnect.');

            return self::OUTCOME_NOTHING;
        }

        if (!$staffUser->holdsStaffRole()) {
            $io->text(sprintf('  ✗ %s is not a staff login (roles: %s). Nothing changed.', $staffUser->getEmail(), implode(', ', $staffUser->getRoles())));

            return self::OUTCOME_FAILED;
        }

        if ($parentUser->getStudent() !== null) {
            $io->text(sprintf('  ✗ %s is a pupil login, not a parent login. Nothing changed.', $parentUser->getEmail()));

            return self::OUTCOME_FAILED;
        }

        if ($parentUser->holdsStaffRole()) {
            $io->text(sprintf('  • %s already holds a staff role — it is not a parent-only login. Nothing to reconnect.', $parentUser->getEmail()));

            return self::OUTCOME_NOTHING;
        }

        if (!in_array('ROLE_PARENT', $parentUser->getRoles(), true)) {
            $io->text(sprintf('  ✗ %s is not a parent login (roles: %s). Nothing changed.', $parentUser->getEmail(), implode(', ', $parentUser->getRoles())));

            return self::OUTCOME_FAILED;
        }

        $sources = $this->tenantEm->getRepository(Guardian::class)->findBy(['user' => $parentUser], ['id' => 'ASC']);
        $target = $this->tenantEm->getRepository(Guardian::class)->findOneBy(['user' => $staffUser]);

        if ($sources === []) {
            $io->text(sprintf('  ✗ %s owns no parent record (Guardian row), so there are no children to move. Nothing changed.', $parentUser->getEmail()));

            return self::OUTCOME_FAILED;
        }

        // One Guardian row per login (it is a OneToOne), so a staff login that
        // already owns a family row can only absorb the other by merging.
        $repoint = null;
        $merges = $sources;

        if ($target === null) {
            $repoint = array_shift($merges);
            $target = $repoint;
        }

        foreach ($merges as $source) {
            $reason = $this->familyLinker->mergeBlockedReason($source, $target);

            if ($reason !== null) {
                $io->text(sprintf('  ✗ %s cannot be merged into the staff family row: %s', $parentUser->getEmail(), $reason));

                return self::OUTCOME_FAILED;
            }
        }

        $io->table(
            ['', 'Staff login — survives', 'Parent login — retired'],
            [
                ['id', (string) $staffUser->getId(), (string) $parentUser->getId()],
                ['email', (string) $staffUser->getEmail(), (string) $parentUser->getEmail()],
                ['name', (string) $staffUser->getFullName(), (string) $parentUser->getFullName()],
                ['roles', implode(', ', $staffUser->getRoles()), implode(', ', $parentUser->getRoles())],
                ['password', $staffUser->isMustChangePassword() ? 'default — must be changed' : 'the person’s own', 'discarded'],
            ]
        );

        foreach ($sources as $source) {
            $io->text(sprintf(
                '  • Guardians on %s move across: %s',
                $parentUser->getEmail(),
                $this->childrenLabel($source)
            ));
        }

        if (!$apply) {
            $io->text('  → would add ROLE_PARENT to the staff login, move the family row(s), and delete the parent login.');
            $io->text(sprintf('  → afterwards the person signs in ONLY as %s.', $staffUser->getEmail()));

            return self::OUTCOME_LINKED;
        }

        if ($repoint !== null) {
            $repoint->setUser($staffUser);
            $this->tenantEm->flush();
        }

        foreach ($merges as $source) {
            $result = $this->familyLinker->merge($source, $target, 'app:tenant:link-staff-login');

            if ($result['moved'] !== []) {
                $io->text(sprintf('  + merged %s into the staff family row.', implode(', ', $result['moved'])));
            }
        }

        $staffUser->setRoles(array_values(array_unique(array_filter(
            array_merge($staffUser->getRoles(), ['ROLE_PARENT']),
            static fn (string $role): bool => $role !== 'ROLE_USER'
        ))));

        $stillUsed = (int) $this->tenantEm->getRepository(Guardian::class)->count(['user' => $parentUser]);
        $retired = false;

        if ($stillUsed === 0) {
            $this->tenantEm->remove($parentUser);
            $retired = true;
        }

        $this->tenantEm->flush();

        if ($retired) {
            $io->success(sprintf(
                '%s now holds both doors (staff + parent) and signs in as %s. %s no longer exists.',
                $staffUser->getFullName() ?: $staffUser->getEmail(),
                $staffUser->getEmail(),
                $parentUser->getEmail()
            ));
        } else {
            $io->warning(sprintf(
                '%s now holds both doors, but %s is still referenced by %d parent record(s) and was kept. '
                . 'Re-run after resolving those rows, or leave it — the person no longer needs it.',
                (string) $staffUser->getEmail(),
                (string) $parentUser->getEmail(),
                $stillUsed
            ));
        }

        return self::OUTCOME_LINKED;
    }

    /** A staff login, by email or by the phone on their Staff record. */
    private function resolveStaff(string $reference): ?User
    {
        $byLogin = $this->resolveLogin($reference);

        if ($byLogin !== null) {
            return $byLogin;
        }

        if (str_contains($reference, '@')) {
            return null;
        }

        $staff = $this->tenantEm->getRepository(Staff::class)->createQueryBuilder('s')
            ->andWhere('s.phone IN (:numbers)')
            ->setParameter('numbers', PhoneNumber::searchVariants($reference))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $staff instanceof Staff ? $staff->getUser() : null;
    }

    /** Any login, by email or by the phone its email was derived from. */
    private function resolveLogin(string $reference): ?User
    {
        $reference = trim($reference);

        if (!str_contains($reference, '@')) {
            $reference = PhoneNumber::loginEmail($reference);
        }

        return $this->tenantEm->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.email IN (:emails)')
            ->setParameter('emails', PhoneNumber::loginCandidates($reference))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** "Aisha (JSS 1), Bello (JSS 2)" — enough to confirm the right family. */
    private function childrenLabel(Guardian $guardian): string
    {
        $names = [];

        foreach ($guardian->getStudents() as $student) {
            $names[] = sprintf(
                '%s (%s)',
                $student->getFullName() ?: 'a pupil',
                $student->getCurrentClass()?->getName() ?: 'no class'
            );
        }

        return $names === [] ? 'no children on this row' : implode(', ', $names);
    }
}
