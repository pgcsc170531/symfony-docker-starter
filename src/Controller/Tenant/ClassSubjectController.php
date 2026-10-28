<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Subject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/class-subjects')]
#[IsGranted('ROLE_ADMIN')]
class ClassSubjectController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'app_tenant_class_subject_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('class_subject_bulk', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please try again.');

                return $this->redirectToRoute('app_tenant_class_subject_index');
            }

            $subjectId = $request->request->get('subject');
            $sessionId = $request->request->get('session');
            $category = $request->request->get('category', ClassSubject::CATEGORY_CORE);

            /*
             * One subject, many classes, one submit.
             *
             * A subject is a property of the school, not of a single arm: JSS 1 A
             * and JSS 1 B both take Mathematics, and making the registrar repeat
             * the form per arm is how classes end up with a different subject
             * list by accident. Every ticked class becomes one ClassSubject row —
             * the table still holds one row per (subject, class, session), so
             * results, approvals and term locking keep working unchanged.
             */
            $classroomIds = array_values(array_unique(array_map(
                'intval',
                array_filter((array) ($request->request->all()['classrooms'] ?? []), 'is_scalar')
            )));

            if (!$subjectId || !$sessionId || $classroomIds === []) {
                $this->addFlash('error', 'Pick a subject, a session and at least one class.');

                return $this->redirectToRoute('app_tenant_class_subject_index');
            }

            $subject = $this->em->getRepository(Subject::class)->find($subjectId);
            $session = $this->em->getRepository(Session::class)->find($sessionId);
            $classrooms = $this->em->getRepository(Classroom::class)->findBy(['id' => $classroomIds]);

            if (!$subject || !$session || $classrooms === []) {
                $this->addFlash('error', 'That subject, session or class no longer exists.');

                return $this->redirectToRoute('app_tenant_class_subject_index');
            }

            // One query for the whole submission instead of one per ticked class.
            $existingByClassroom = [];
            foreach ($this->em->getRepository(ClassSubject::class)->findBy([
                'subject' => $subject,
                'session' => $session,
            ]) as $row) {
                $existingByClassroom[(int) $row->getClassroom()?->getId()] = true;
            }

            $category = $category === ClassSubject::CATEGORY_ELECTIVE
                ? ClassSubject::CATEGORY_ELECTIVE
                : ClassSubject::CATEGORY_CORE;

            $created = 0;
            $skipped = 0;

            foreach ($classrooms as $classroom) {
                if (isset($existingByClassroom[(int) $classroom->getId()])) {
                    ++$skipped;

                    continue;
                }

                $classSubject = new ClassSubject();
                $classSubject->setSubject($subject)
                    ->setClassroom($classroom)
                    ->setSession($session)
                    ->setCategory($category);
                $this->em->persist($classSubject);
                ++$created;
            }

            $this->em->flush();

            $name = (string) $subject->getName();
            if ($created > 0) {
                $message = sprintf(
                    '%s added to %d class%s for %s.',
                    $name,
                    $created,
                    $created === 1 ? '' : 'es',
                    (string) $session->getName()
                );
                if ($skipped > 0) {
                    $message .= sprintf(' %d class%s already had it.', $skipped, $skipped === 1 ? '' : 'es');
                }
                $this->addFlash('success', $message);
            } else {
                $this->addFlash('info', sprintf(
                    '%s was already registered in %d class%s for %s — nothing was added.',
                    $name,
                    $skipped,
                    $skipped === 1 ? '' : 'es',
                    (string) $session->getName()
                ));
            }

            return $this->redirectToRoute('app_tenant_class_subject_index');
        }

        $subjects = $this->em->getRepository(Subject::class)->findBy([], ['name' => 'ASC']);
        $subjectGroups = [];
        foreach ($subjects as $subject) {
            $label = $subject->getDepartment() ? $subject->getDepartment()->getName() : 'No Department';
            $subjectGroups[$label][] = $subject;
        }
        ksort($subjectGroups);

        // One field matches whatever the person can see in the row: the subject,
        // its department, the class or the session. A registrar looking for
        // "JSS 2" should not have to remember whether that is a class or a session.
        $q = trim((string) $request->query->get('q', ''));

        $qb = $this->em->getRepository(ClassSubject::class)->createQueryBuilder('cs')
            ->join('cs.subject', 'sub')->addSelect('sub')
            ->join('cs.classroom', 'classroom')->addSelect('classroom')
            ->join('cs.session', 'sess')->addSelect('sess')
            ->leftJoin('sub.department', 'dept')->addSelect('dept')
            ->orderBy('sess.id', 'DESC')
            ->addOrderBy('sub.name', 'ASC')
            ->addOrderBy('classroom.name', 'ASC');

        if ($q !== '') {
            $qb->andWhere('LOWER(sub.name) LIKE :q'
                    . ' OR LOWER(sub.code) LIKE :q'
                    . ' OR LOWER(classroom.name) LIKE :q'
                    . ' OR LOWER(sess.name) LIKE :q'
                    . ' OR LOWER(dept.name) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        /*
         * The list reads the way the register is now written: one card per
         * subject per session, with every class it is taught in shown as a chip.
         * Listing one row per class would turn "Mathematics" into twelve
         * near-identical rows the moment a school registers it properly.
         */
        $rows = $qb->getQuery()->getResult();
        $groups = [];
        foreach ($rows as $cs) {
            $key = sprintf(
                '%d|%d|%s',
                (int) $cs->getSession()?->getId(),
                (int) $cs->getSubject()?->getId(),
                (string) $cs->getCategory()
            );

            $groups[$key] ??= [
                'session' => $cs->getSession(),
                'subject' => $cs->getSubject(),
                'category' => $cs->getCategory(),
                'rows' => [],
            ];

            $groups[$key]['rows'][] = $cs;
        }

        // Pick list for the register form: every class, grouped under its level
        // so "all the JSS 1 arms" is one glance rather than a scan of a flat list.
        $classroomGroups = [];
        foreach ($this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']) as $classroom) {
            $classroomGroups[$classroom->getYearGroup()?->getName() ?? 'Other classes'][] = $classroom;
        }
        uksort($classroomGroups, 'strnatcasecmp');

        return $this->render('tenant/class_subject/index.html.twig', [
            'groups' => $groups,
            'classSubjectCount' => count($rows),
            'subjectGroups' => $subjectGroups,
            'classroomGroups' => $classroomGroups,
            'sessions' => $this->em->getRepository(Session::class)->findBy([], ['id' => 'DESC']),
            'q' => $q,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_class_subject_delete', methods: ['POST'])]
    public function delete(ClassSubject $classSubject, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('cs' . $classSubject->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'That change expired. Please try again.');

            return $this->redirectToRoute('app_tenant_class_subject_index');
        }

        $label = sprintf(
            '%s in %s',
            $classSubject->getSubject()?->getName() ?? 'That subject',
            $classSubject->getClassroom()?->getName() ?? 'that class'
        );

        /*
         * Scores, elective enrolments and result approvals all point at this
         * row with ON DELETE RESTRICT. Removing it while they exist is a data
         * loss question, not a bug to report as a 500 — say what is holding it.
         */
        try {
            $this->em->remove($classSubject);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s removed.', $label));
        } catch (\Throwable) {
            $this->addFlash('error', sprintf(
                '%s cannot be removed — marks or approvals are already recorded against it.',
                $label
            ));
        }

        return $this->redirectToRoute('app_tenant_class_subject_index');
    }
}
