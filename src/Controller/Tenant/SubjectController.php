<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Department;
use App\Entity\Tenant\Subject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/subjects')]
#[IsGranted('ROLE_ADMIN')]
class SubjectController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'app_tenant_subject_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            $code = trim((string) $request->request->get('code'));
            $departmentId = $request->request->get('department');

            if ($name !== '') {
                $existing = $this->em->getRepository(Subject::class)->findOneBy(['name' => $name]);
                if (!$existing) {
                    $subject = new Subject();
                    $subject->setName($name);
                    $subject->setCode($code !== '' ? $code : null);
                    if ($departmentId) {
                        $department = $this->em->getRepository(Department::class)->find($departmentId);
                        $subject->setDepartment($department);
                    }
                    $this->em->persist($subject);
                    $this->em->flush();
                    $this->addFlash('success', sprintf('Subject "%s" created.', $name));
                } else {
                    $this->addFlash('warning', sprintf('Subject "%s" already exists.', $name));
                }
            }

            return $this->redirectToRoute('app_tenant_subject_index');
        }

        return $this->render('tenant/subject/index.html.twig', [
            'subjects' => $this->em->getRepository(Subject::class)->findBy([], ['name' => 'ASC']),
            'departments' => $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_subject_delete', methods: ['POST'])]
    public function delete(Subject $subject, Request $request): Response
    {
        if ($this->isCsrfTokenValid('subject' . $subject->getId(), $request->request->get('_token'))) {
            $this->em->remove($subject);
            $this->em->flush();
            $this->addFlash('success', 'Subject deleted.');
        }

        return $this->redirectToRoute('app_tenant_subject_index');
    }
}
