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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        $form = $this->createForm(StaffType::class, $user, [
            'allow_super_admin' => $this->isGranted('ROLE_TENANT_SUPER_ADMIN'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $user->getEmail()));
            $user->setEmail($email);

            if ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                $form->get('email')->addError(new FormError('Another account already uses that email address.'));
            } else {
                // Hash the password
                $plainPassword = $form->get('plainPassword')->getData();
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

                $entityManager->persist($user);

                // Link a Staff profile so the user appears in academic assignments.
                $staff = new Staff();
                $staff->setUser($user);
                $entityManager->persist($staff);

                $entityManager->flush();

                $this->addFlash('success', 'New staff member created successfully!');

                return $this->redirectToRoute('app_tenant_staff_index');
            }
        }

        return $this->render('tenant/staff/new.html.twig', [
            'form' => $form,
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
