<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Department;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Entity\Tenant\YearGroup;
use App\Entity\Tenant\YearGroupMaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/staff-assignments')]
#[IsGranted('ROLE_ADMIN')]
class StaffAssignmentController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/year-group-master', name: 'app_tenant_year_group_master', methods: ['GET', 'POST'])]
    public function yearGroupMaster(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $staffId = $request->request->get('staff');
            $yearGroupId = $request->request->get('year_group');
            $sessionId = $request->request->get('session');

            if ($staffId && $yearGroupId && $sessionId) {
                $staff = $this->resolveStaff((int) $staffId);
                $yearGroup = $this->em->getRepository(YearGroup::class)->find($yearGroupId);
                $session = $this->em->getRepository(Session::class)->find($sessionId);

                if ($staff && $yearGroup && $session) {
                    $existing = $this->em->getRepository(YearGroupMaster::class)->findOneBy([
                        'staff' => $staff,
                        'yearGroup' => $yearGroup,
                        'session' => $session,
                    ]);

                    if (!$existing) {
                        $ygm = new YearGroupMaster();
                        $ygm->setStaff($staff)
                            ->setYearGroup($yearGroup)
                            ->setSession($session);
                        $this->em->persist($ygm);
                        $this->em->flush();
                        $this->addFlash('success', 'Year Group Master assigned.');
                    } else {
                        $this->addFlash('warning', 'That assignment already exists.');
                    }
                }
            }

            return $this->redirectToRoute('app_tenant_year_group_master');
        }

        return $this->render('tenant/staff_assignment/year_group_master.html.twig', [
            'staffOptions' => $this->staffOptions(),
            'yearGroups' => $this->em->getRepository(YearGroup::class)->findBy([], ['name' => 'ASC']),
            'sessions' => $this->em->getRepository(Session::class)->findBy([], ['id' => 'DESC']),
            'assignments' => $this->em->getRepository(YearGroupMaster::class)->findBy([], ['session' => 'DESC']),
        ]);
    }

    #[Route('/year-group-master/{id}/remove', name: 'app_tenant_year_group_master_remove', methods: ['POST'])]
    public function removeYearGroupMaster(YearGroupMaster $assignment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('ygm' . $assignment->getId(), $request->request->get('_token'))) {
            $this->em->remove($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Assignment removed.');
        }

        return $this->redirectToRoute('app_tenant_year_group_master');
    }

    #[Route('/form-teacher', name: 'app_tenant_form_teacher', methods: ['GET', 'POST'])]
    public function formTeacher(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $staffId = $request->request->get('staff');
            $classroomId = $request->request->get('classroom');
            $termId = $request->request->get('term');

            if ($staffId && $classroomId && $termId) {
                $staff = $this->resolveStaff((int) $staffId);
                $classroom = $this->em->getRepository(Classroom::class)->find($classroomId);
                $term = $this->em->getRepository(Term::class)->find($termId);

                if ($staff && $classroom && $term) {
                    $existing = $this->em->getRepository(FormTeacher::class)->findOneBy([
                        'staff' => $staff,
                        'classroom' => $classroom,
                        'term' => $term,
                    ]);

                    if (!$existing) {
                        $formTeacher = new FormTeacher();
                        $formTeacher->setStaff($staff)
                            ->setClassroom($classroom)
                            ->setTerm($term);
                        $this->em->persist($formTeacher);
                        $this->em->flush();
                        $this->addFlash('success', 'Form Teacher assigned.');
                    } else {
                        $this->addFlash('warning', 'That assignment already exists.');
                    }
                }
            }

            return $this->redirectToRoute('app_tenant_form_teacher');
        }

        return $this->render('tenant/staff_assignment/form_teacher.html.twig', [
            'staffOptions' => $this->staffOptions(),
            'classrooms' => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'terms' => $this->em->getRepository(Term::class)->findBy([], ['id' => 'DESC']),
            'assignments' => $this->em->getRepository(FormTeacher::class)->findBy([], ['term' => 'DESC']),
        ]);
    }

    #[Route('/form-teacher/{id}/remove', name: 'app_tenant_form_teacher_remove', methods: ['POST'])]
    public function removeFormTeacher(FormTeacher $assignment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('ft' . $assignment->getId(), $request->request->get('_token'))) {
            $this->em->remove($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Assignment removed.');
        }

        return $this->redirectToRoute('app_tenant_form_teacher');
    }

    #[Route('/hod', name: 'app_tenant_hod', methods: ['GET', 'POST'])]
    public function hod(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $staffId = $request->request->get('staff');
            $departmentId = $request->request->get('department');
            $sessionId = $request->request->get('session');

            if ($staffId && $departmentId && $sessionId) {
                $staff = $this->resolveStaff((int) $staffId);
                $department = $this->em->getRepository(Department::class)->find($departmentId);
                $session = $this->em->getRepository(Session::class)->find($sessionId);

                if ($staff && $department && $session) {
                    $existing = $this->em->getRepository(DepartmentHead::class)->findOneBy([
                        'staff' => $staff,
                        'department' => $department,
                        'session' => $session,
                    ]);

                    if (!$existing) {
                        $hod = new DepartmentHead();
                        $hod->setStaff($staff)
                            ->setDepartment($department)
                            ->setSession($session);
                        $this->em->persist($hod);
                        $this->em->flush();
                        $this->addFlash('success', 'HOD assigned.');
                    } else {
                        $this->addFlash('warning', 'That assignment already exists.');
                    }
                }
            }

            return $this->redirectToRoute('app_tenant_hod');
        }

        return $this->render('tenant/staff_assignment/hod.html.twig', [
            'staffOptions' => $this->staffOptions(),
            'departments' => $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC']),
            'sessions' => $this->em->getRepository(Session::class)->findBy([], ['id' => 'DESC']),
            'assignments' => $this->em->getRepository(DepartmentHead::class)->findBy([], ['session' => 'DESC']),
        ]);
    }

    #[Route('/hod/{id}/remove', name: 'app_tenant_hod_remove', methods: ['POST'])]
    public function removeHod(DepartmentHead $assignment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('hod' . $assignment->getId(), $request->request->get('_token'))) {
            $this->em->remove($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Assignment removed.');
        }

        return $this->redirectToRoute('app_tenant_hod');
    }

    private function staffOptions(): array
    {
        $options = [];
        foreach ($this->em->getRepository(User::class)->findBy([], ['id' => 'ASC']) as $user) {
            if ($this->isStaffAccount($user)) {
                $options[] = [
                    'id' => $user->getId(),
                    'name' => $user->getFullName() ?? $user->getEmail(),
                ];
            }
        }

        return $options;
    }

    private function isStaffAccount(User $user): bool
    {
        foreach (['ROLE_ADMIN', 'ROLE_BURSAR', 'ROLE_STORE', 'ROLE_TEACHER'] as $role) {
            if (in_array($role, $user->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveStaff(?int $userId): ?Staff
    {
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            return null;
        }

        $staff = $this->em->getRepository(Staff::class)->findOneBy(['user' => $user]);
        if (!$staff) {
            $staff = new Staff();
            $staff->setUser($user);
            $this->em->persist($staff);
        }

        return $staff;
    }

    #[Route('/subject-teacher', name: 'app_tenant_subject_teacher', methods: ['GET', 'POST'])]
    public function subjectTeacher(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $staffId = $request->request->get('staff');
            $subjectId = $request->request->get('subject');
            $classroomId = $request->request->get('classroom');
            $termId = $request->request->get('term');

            if ($staffId && $subjectId && $classroomId && $termId) {
                $staff = $this->resolveStaff((int) $staffId);
                $subject = $this->em->getRepository(Subject::class)->find($subjectId);
                $classroom = $this->em->getRepository(Classroom::class)->find($classroomId);
                $term = $this->em->getRepository(Term::class)->find($termId);

                if ($staff && $subject && $classroom && $term) {
                    $existing = $this->em->getRepository(SubjectTeacher::class)->findOneBy([
                        'staff' => $staff,
                        'subject' => $subject,
                        'classroom' => $classroom,
                        'term' => $term,
                    ]);

                    if (!$existing) {
                        $assignment = new SubjectTeacher();
                        $assignment->setStaff($staff)
                            ->setSubject($subject)
                            ->setClassroom($classroom)
                            ->setTerm($term);
                        $this->em->persist($assignment);
                        $this->em->flush();
                        $this->addFlash('success', 'Subject teacher assigned.');
                    } else {
                        $this->addFlash('warning', 'That assignment already exists.');
                    }
                }
            }

            return $this->redirectToRoute('app_tenant_subject_teacher');
        }

        return $this->render('tenant/staff_assignment/subject_teacher.html.twig', [
            'staffOptions' => $this->staffOptions(),
            'subjectGroups' => $this->subjectGroups(),
            'classrooms' => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'terms' => $this->em->getRepository(Term::class)->findBy([], ['id' => 'DESC']),
            'assignments' => $this->em->getRepository(SubjectTeacher::class)->findBy([], ['term' => 'DESC']),
        ]);
    }

    #[Route('/subject-teacher/{id}/remove', name: 'app_tenant_subject_teacher_remove', methods: ['POST'])]
    public function removeSubjectTeacher(SubjectTeacher $assignment, Request $request): Response
    {
        if ($this->isCsrfTokenValid('st' . $assignment->getId(), $request->request->get('_token'))) {
            $this->em->remove($assignment);
            $this->em->flush();
            $this->addFlash('success', 'Assignment removed.');
        }

        return $this->redirectToRoute('app_tenant_subject_teacher');
    }

    private function subjectGroups(): array
    {
        $groups = [];
        foreach ($this->em->getRepository(Subject::class)->findBy([], ['name' => 'ASC']) as $subject) {
            $label = $subject->getDepartment() ? $subject->getDepartment()->getName() : 'No Department';
            $groups[$label][] = $subject;
        }
        ksort($groups);

        return $groups;
    }
}
