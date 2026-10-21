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

        $q = trim((string) $request->query->get('q', ''));

        // Searching a subject is also how you find out whether it already exists
        // before creating a duplicate — so name, code and department all match.
        $qb = $this->em->getRepository(Subject::class)->createQueryBuilder('s')
            ->leftJoin('s.department', 'd')->addSelect('d')
            ->orderBy('s.name', 'ASC');

        if ($q !== '') {
            $qb->andWhere('LOWER(s.name) LIKE :q OR LOWER(s.code) LIKE :q OR LOWER(d.name) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        return $this->render('tenant/subject/index.html.twig', [
            'subjects' => $qb->getQuery()->getResult(),
            'departments' => $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC']),
            'q' => $q,
        ]);
    }

    #[Route('/{id}/move', name: 'app_tenant_subject_move', methods: ['POST'])]
    public function move(Subject $subject, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('subject' . $subject->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Try again.');
            return $this->redirectToRoute('app_tenant_subject_index');
        }

        // A subject filed under the wrong department leaves its HOD with an empty
        // queue, and there was no way to correct it: subjects could be created and
        // deleted, never edited.
        $departmentId = $request->request->get('department');
        if ($departmentId) {
            $department = $this->em->getRepository(Department::class)->find($departmentId);
            if (!$department) {
                $this->addFlash('error', 'That department could not be found.');
                return $this->redirectToRoute('app_tenant_subject_index');
            }
            $subject->setDepartment($department);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s moved to %s.', $subject->getName(), $department->getName()));
        } else {
            $subject->setDepartment(null);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s removed from its department.', $subject->getName()));
        }

        return $this->redirectToRoute('app_tenant_subject_index');
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
