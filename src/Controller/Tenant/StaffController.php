<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Entity\Tenant\YearGroupMaster;
use App\Form\StaffEditType;
use App\Form\StaffType;
use App\Service\Staff\StaffImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/staff')]
#[IsGranted('ROLE_ADMIN')] // Only Principal can access
class StaffController extends AbstractController
{
    /**
     * What the role filter accepts.
     *
     * The first five are login roles. The last three are *assignments* — a form
     * teacher, an HOD or a year group master holds no extra login role, so
     * without this they were invisible in the only directory that lists staff.
     */
    private const ROLE_FILTERS = ['super', 'admin', 'admission', 'bursar', 'store', 'teacher', 'hod', 'form', 'ygm'];

    #[Route('/', name: 'app_tenant_staff_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $role = (string) $request->query->get('role', '');
        if (!in_array($role, self::ROLE_FILTERS, true)) {
            $role = '';
        }

        // List only staff accounts (exclude parents and any non-staff users)
        $allUsers = $entityManager->getRepository(User::class)->findAll();
        $users = array_values(array_filter(
            $allUsers,
            fn (User $user) => $this->isStaffAccount($user)
        ));
        $total = count($users);

        $assignments = $this->assignmentMap($entityManager);
        $staffIds = $this->staffIdByUser($entityManager);
        $needle = $q === '' ? '' : mb_strtolower($q);

        $users = array_values(array_filter($users, function (User $user) use ($role, $needle, $assignments, $staffIds): bool {
            $staffId = $staffIds[(int) $user->getId()] ?? null;
            $mine = $staffId !== null ? ($assignments[$staffId] ?? []) : [];

            if ($role !== '' && !$this->matchesRole($user, $role, $mine)) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // Search what is on the row, including the assignment names — typing
            // "Sciences" should find the HOD, and "JSS 1A" should find the form
            // teacher, without the office knowing which table that lives in.
            $haystack = mb_strtolower(implode(' ', array_filter([
                $user->getFullName(),
                $user->getEmail(),
                $mine['form'] ?? null,
                $mine['hod'] ?? null,
                $mine['ygm'] ?? null,
            ])));

            return str_contains($haystack, $needle);
        }));

        return $this->render('tenant/staff/index.html.twig', [
            'users' => $users,
            'assignments' => $assignments,
            'staffIds' => $staffIds,
            'q' => $q,
            'role' => $role,
            'total' => $total,
        ]);
    }

    #[Route('/new', name: 'app_tenant_staff_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = new User();

        // Everyone starts as a class teacher. The office can promote the person
        // afterwards; the role dropdown no longer starts on an empty option that
        // only failed after the form was submitted.
        $user->setRoles([User::ROLE_TEACHER]);

        $form = $this->createForm(StaffType::class, $user, [
            'allow_super_admin' => $this->isGranted('ROLE_TENANT_SUPER_ADMIN'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $phone = preg_replace('/\D+/', '', (string) $form->get('phone')->getData()) ?? '';
            $email = mb_strtolower(trim((string) $user->getEmail()));

            // The phone is the login when no inbox was given — same derivation
            // the student importer uses for parents.
            if ($email === '') {
                if ($phone === '') {
                    $form->get('email')->addError(new FormError(
                        'Give a phone number or an email address — the account signs in with one of them.'
                    ));
                } else {
                    $email = $phone . '@edus.ng';
                }
            }

            if ($email === '') {
                // Already reported above; fall through to re-render.
            } elseif ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                $form->get('email')->addError(new FormError(sprintf('Another account already uses %s.', $email)));
            } else {
                $user->setEmail($email);

                $plainPassword = trim((string) $form->get('plainPassword')->getData());
                $usingDefault = $plainPassword === '';

                // A password the person did not choose is a doorway, not a
                // credential: they are stopped at the door and asked for their own.
                $user->setMustChangePassword($usingDefault);
                $user->setPassword($passwordHasher->hashPassword(
                    $user,
                    $usingDefault ? StaffImporter::DEFAULT_PASSWORD : $plainPassword
                ));

                $entityManager->persist($user);

                $role = current(array_filter($user->getRoles(), static fn (string $r): bool => $r !== 'ROLE_USER'))
                    ?: User::ROLE_TEACHER;

                // Link a Staff profile so the user appears in academic assignments.
                $staff = new Staff();
                $staff->setUser($user)
                    ->setPhone($phone !== '' ? $phone : null)
                    ->setAddress($this->cleanOrNull($form->get('address')->getData(), 255))
                    ->setEmployeeNumber($this->cleanOrNull($form->get('employeeNumber')->getData(), 50))
                    ->setTitle(StaffImporter::titleFor('', $role));
                $entityManager->persist($staff);

                $entityManager->flush();

                $this->addFlash('success', $usingDefault
                    ? sprintf('%s created. Sign in as %s with the default password %s — they will be asked to change it.', $user->getFullName(), $email, StaffImporter::DEFAULT_PASSWORD)
                    : sprintf('%s created.', $user->getFullName()));

                return $this->redirectToRoute('app_tenant_staff_index');
            }
        }

        return $this->render('tenant/staff/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Bulk registration from a CSV file.
     *
     * The same idea as the student import: one file, one click, every account
     * created with the shared default password, and a report of exactly which
     * rows were left alone and why. Staff are forced to change that password on
     * first sign-in (MustChangePasswordSubscriber), so handing out the same
     * password to a whole school is not the security problem it looks like.
     */
    #[Route('/import', name: 'app_tenant_staff_import', methods: ['GET', 'POST'])]
    public function import(Request $request, StaffImporter $importer): Response
    {
        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('staff_import', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please try again.');

                return $this->redirectToRoute('app_tenant_staff_import');
            }

            $file = $request->files->get('csv_file');

            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $this->addFlash('error', 'Choose a CSV file to upload.');

                return $this->redirectToRoute('app_tenant_staff_import');
            }

            try {
                $result = $importer->import($file);

                if ($result->created > 0) {
                    $message = sprintf(
                        '%d staff account%s created. Everyone signs in with %s and is asked to change it.',
                        $result->created,
                        $result->created === 1 ? '' : 's',
                        StaffImporter::DEFAULT_PASSWORD
                    );

                    if ($result->linked > 0) {
                        $message .= sprintf(
                            ' %d of them %s matched to an existing parent record, so the same sign-in offers both portals.',
                            $result->linked,
                            $result->linked === 1 ? 'was' : 'were'
                        );
                    }

                    $this->addFlash('success', $message);
                }

                if ($result->promoted > 0) {
                    $this->addFlash('success', sprintf(
                        '%d existing parent account%s %s given a staff profile — same login, and it now asks which portal to open. Their password was not touched.',
                        $result->promoted,
                        $result->promoted === 1 ? '' : 's',
                        $result->promoted === 1 ? 'was' : 'were'
                    ));
                }

                if ($result->skipped !== []) {
                    $this->addFlash('warning', sprintf(
                        '%d row%s skipped — see the list below.',
                        $result->skippedCount(),
                        $result->skippedCount() === 1 ? '' : 's'
                    ));
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('tenant/staff/import.html.twig', [
            'result' => $result,
            'default_password' => StaffImporter::DEFAULT_PASSWORD,
            'columns' => StaffImporter::templateHeader(),
        ]);
    }

    /** The CSV to fill in — same header the importer parses. */
    #[Route('/import/template', name: 'app_tenant_staff_import_template', methods: ['GET'])]
    public function importTemplate(): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, StaffImporter::templateHeader(), ',', '"', '\\');
        fputcsv($handle, ['Aisha Bello', '08031234567', '', '12 Kofar Kaura, Katsina', 'Class Teacher', 'STF-001'], ',', '"', '\\');
        fputcsv($handle, ['Musa Ibrahim', '08037654321', 'musa@school.com', '5 Sabon Gari, Katsina', 'Bursar', 'STF-002'], ',', '"', '\\');
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="staff-template.csv"',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_staff_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $this->assertCanManage($user);

        /** @var User|null $me */
        $me = $this->getUser();
        $isSelf = $me !== null && $me->getId() === $user->getId();

        $form = $this->createForm(StaffEditType::class, $user, [
            'allow_super_admin' => $this->isGranted('ROLE_TENANT_SUPER_ADMIN'),
            'lock_role' => $isSelf,
            'staff' => $user->getStaff(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $user->getEmail()));
            $user->setEmail($email);

            $clash = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($clash !== null && $clash->getId() !== $user->getId()) {
                $form->get('email')->addError(new FormError('Another account already uses that email address.'));
            } else {
                $plainPassword = $form->get('plainPassword')->getData();
                if (is_string($plainPassword) && $plainPassword !== '') {
                    $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                }

                // Phone, home address and staff number live on the Staff profile.
                // A user without one (an old account) gets it here rather than
                // silently dropping what the office typed.
                $staff = $user->getStaff();
                if ($staff === null) {
                    $staff = new Staff();
                    $staff->setUser($user);
                    $entityManager->persist($staff);
                }

                $staff->setPhone(preg_replace('/\D+/', '', (string) $form->get('phone')->getData()) ?: null)
                    ->setAddress($this->cleanOrNull($form->get('address')->getData(), 255))
                    ->setEmployeeNumber($this->cleanOrNull($form->get('employeeNumber')->getData(), 50));

                $entityManager->flush();

                $this->addFlash('success', sprintf('%s updated.', $user->getFullName() ?: $user->getEmail()));

                return $this->redirectToRoute('app_tenant_staff_index');
            }
        }

        return $this->render('tenant/staff/edit.html.twig', [
            'form' => $form->createView(),
            'staffUser' => $user,
            'isSelf' => $isSelf,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Who may edit whom
    // ──────────────────────────────────────────────────────────────────

    /** Blank text from an optional field means "nothing", not an empty string. */
    private function cleanOrNull(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function assertCanManage(User $target): void
    {
        if (!$this->isStaffAccount($target)) {
            throw $this->createNotFoundException('That account is not a staff member.');
        }

        /** @var User|null $me */
        $me = $this->getUser();
        $iAmOwner = $me !== null && in_array(User::ROLE_TENANT_SUPER_ADMIN, $me->getRoles(), true);
        $isSelf = $me !== null && $me->getId() === $target->getId();
        $targetIsOwner = in_array(User::ROLE_TENANT_SUPER_ADMIN, $target->getRoles(), true);
        $targetIsAdmin = in_array(User::ROLE_ADMIN, $target->getRoles(), true);

        // The owner account is the owner's, full stop.
        if ($targetIsOwner && !$iAmOwner) {
            throw $this->createAccessDeniedException('Only the school owner can change the owner account.');
        }

        // A principal manages staff, not other principals. Everyone may edit
        // their own name, email and password; the role field is locked then.
        if (!$iAmOwner && !$isSelf && $targetIsAdmin) {
            throw $this->createAccessDeniedException('Only the school owner can change an administrator account.');
        }
    }

    private function isStaffAccount(User $user): bool
    {
        foreach (['ROLE_TENANT_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_ADMISSION_OFFICER', 'ROLE_BURSAR', 'ROLE_STORE', 'ROLE_TEACHER', 'ROLE_HOD'] as $role) {
            if (in_array($role, $user->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{form?: string, hod?: string, ygm?: string} $mine
     */
    private function matchesRole(User $user, string $role, array $mine): bool
    {
        return match ($role) {
            'hod' => isset($mine['hod']) || in_array(User::ROLE_HOD, $user->getRoles(), true),
            'form' => isset($mine['form']),
            'ygm' => isset($mine['ygm']),
            default => $this->primaryRole($user->getRoles()) === $role,
        };
    }

    /**
     * The seat the account sits in — the same priority the dashboard uses, so the
     * directory badge and the role filter can never tell two different stories.
     *
     * @param list<string> $roles
     */
    private function primaryRole(array $roles): string
    {
        foreach ([
            User::ROLE_TENANT_SUPER_ADMIN => 'super',
            User::ROLE_ADMIN => 'admin',
            User::ROLE_ADMISSION_OFFICER => 'admission',
            'ROLE_BURSAR' => 'bursar',
            'ROLE_STORE' => 'store',
            User::ROLE_TEACHER => 'teacher',
            User::ROLE_HOD => 'hod',
        ] as $constant => $key) {
            if (in_array($constant, $roles, true)) {
                return $key;
            }
        }

        return 'staff';
    }

    /**
     * Assignment names by staff id, for the active term/session.
     *
     * @return array<int, array{form?: string, hod?: string, ygm?: string}>
     */
    private function assignmentMap(EntityManagerInterface $em): array
    {
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $session = $term?->getSession() ?? $em->getRepository(Session::class)->findOneBy(['isActive' => true]);

        $map = [];

        if ($term) {
            foreach ($em->getRepository(FormTeacher::class)->findBy(['term' => $term]) as $assignment) {
                $staffId = $assignment->getStaff()?->getId();
                if ($staffId !== null) {
                    $map[$staffId]['form'] = $assignment->getClassroom()?->getName();
                }
            }
        }

        if ($session) {
            foreach ($em->getRepository(DepartmentHead::class)->findBy(['session' => $session]) as $assignment) {
                $staffId = $assignment->getStaff()?->getId();
                if ($staffId !== null) {
                    $map[$staffId]['hod'] = $assignment->getDepartment()?->getName();
                }
            }

            foreach ($em->getRepository(YearGroupMaster::class)->findBy(['session' => $session]) as $assignment) {
                $staffId = $assignment->getStaff()?->getId();
                if ($staffId !== null) {
                    $map[$staffId]['ygm'] = $assignment->getYearGroup()?->getName();
                }
            }
        }

        return $map;
    }

    /** @return array<int, int> user id → staff id */
    private function staffIdByUser(EntityManagerInterface $em): array
    {
        $rows = $em->createQueryBuilder()
            ->select('s.id AS staffId, IDENTITY(s.user) AS userId')
            ->from(Staff::class, 's')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['staffId'];
        }

        return $map;
    }
}
