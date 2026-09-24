<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Department;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/departments')]
#[IsGranted('ROLE_ADMIN')]
class DepartmentController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'app_tenant_department_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            $description = trim((string) $request->request->get('description'));

            if ($name !== '') {
                $existing = $this->em->getRepository(Department::class)->findOneBy(['name' => $name]);
                if (!$existing) {
                    $department = new Department();
                    $department->setName($name);
                    $department->setDescription($description !== '' ? $description : null);
                    $this->em->persist($department);
                    $this->em->flush();
                    $this->addFlash('success', sprintf('Department "%s" created.', $name));
                } else {
                    $this->addFlash('warning', sprintf('Department "%s" already exists.', $name));
                }
            }

            return $this->redirectToRoute('app_tenant_department_index');
        }

        return $this->render('tenant/department/index.html.twig', [
            'departments' => $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_department_delete', methods: ['POST'])]
    public function delete(Department $department, Request $request): Response
    {
        if ($this->isCsrfTokenValid('dept' . $department->getId(), $request->request->get('_token'))) {
            // Detach subjects before removing the department.
            foreach ($department->getSubjects() as $subject) {
                $subject->setDepartment(null);
            }
            $this->em->remove($department);
            $this->em->flush();
            $this->addFlash('success', 'Department deleted.');
        }

        return $this->redirectToRoute('app_tenant_department_index');
    }
}
