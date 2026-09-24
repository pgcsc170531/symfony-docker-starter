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
            $subjectId = $request->request->get('subject');
            $classroomId = $request->request->get('classroom');
            $sessionId = $request->request->get('session');
            $category = $request->request->get('category', ClassSubject::CATEGORY_CORE);

            if ($subjectId && $classroomId && $sessionId) {
                $subject = $this->em->getRepository(Subject::class)->find($subjectId);
                $classroom = $this->em->getRepository(Classroom::class)->find($classroomId);
                $session = $this->em->getRepository(Session::class)->find($sessionId);

                if ($subject && $classroom && $session) {
                    $existing = $this->em->getRepository(ClassSubject::class)->findOneBy([
                        'subject' => $subject,
                        'classroom' => $classroom,
                        'session' => $session,
                    ]);

                    if (!$existing) {
                        $classSubject = new ClassSubject();
                        $classSubject->setSubject($subject)
                            ->setClassroom($classroom)
                            ->setSession($session)
                            ->setCategory($category === ClassSubject::CATEGORY_ELECTIVE ? ClassSubject::CATEGORY_ELECTIVE : ClassSubject::CATEGORY_CORE);
                        $this->em->persist($classSubject);
                        $this->em->flush();
                        $this->addFlash('success', 'Class subject registered.');
                    } else {
                        $this->addFlash('warning', 'That class subject already exists.');
                    }
                }
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

        return $this->render('tenant/class_subject/index.html.twig', [
            'classSubjects' => $this->em->getRepository(ClassSubject::class)->findBy([], ['session' => 'DESC', 'classroom' => 'ASC', 'subject' => 'ASC']),
            'subjectGroups' => $subjectGroups,
            'classrooms' => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'sessions' => $this->em->getRepository(Session::class)->findBy([], ['id' => 'DESC']),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_class_subject_delete', methods: ['POST'])]
    public function delete(ClassSubject $classSubject, Request $request): Response
    {
        if ($this->isCsrfTokenValid('cs' . $classSubject->getId(), $request->request->get('_token'))) {
            $this->em->remove($classSubject);
            $this->em->flush();
            $this->addFlash('success', 'Class subject removed.');
        }

        return $this->redirectToRoute('app_tenant_class_subject_index');
    }
}
