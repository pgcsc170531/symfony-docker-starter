<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\YearGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/year-groups')]
#[IsGranted('ROLE_ADMIN')]
class YearGroupController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'app_tenant_year_group_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            if ($name !== '') {
                $existing = $this->em->getRepository(YearGroup::class)->findOneBy(['name' => $name]);
                if (!$existing) {
                    $yearGroup = new YearGroup();
                    $yearGroup->setName($name);
                    $this->em->persist($yearGroup);
                    $this->em->flush();
                    $this->addFlash('success', sprintf('Year group "%s" created.', $name));
                } else {
                    $this->addFlash('warning', sprintf('Year group "%s" already exists.', $name));
                }
            }

            return $this->redirectToRoute('app_tenant_year_group_index');
        }

        return $this->render('tenant/year_group/index.html.twig', [
            'yearGroups' => $this->em->getRepository(YearGroup::class)->findBy([], ['name' => 'ASC']),
            'classrooms' => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}/assign-classroom', name: 'app_tenant_year_group_assign_classroom', methods: ['POST'])]
    public function assignClassroom(YearGroup $yearGroup, Request $request): Response
    {
        $classroomId = $request->request->get('classroom');
        if ($classroomId) {
            $classroom = $this->em->getRepository(Classroom::class)->find($classroomId);
            if ($classroom) {
                $classroom->setYearGroup($yearGroup);
                $this->em->flush();
                $this->addFlash('success', 'Classroom assigned to year group.');
            }
        }

        return $this->redirectToRoute('app_tenant_year_group_index');
    }

    #[Route('/classroom/{classroom}/unassign', name: 'app_tenant_year_group_unassign_classroom', methods: ['POST'])]
    public function unassignClassroom(Classroom $classroom): Response
    {
        $classroom->setYearGroup(null);
        $this->em->flush();
        $this->addFlash('success', 'Classroom removed from its year group.');

        return $this->redirectToRoute('app_tenant_year_group_index');
    }

    #[Route('/{id}/delete', name: 'app_tenant_year_group_delete', methods: ['POST'])]
    public function delete(YearGroup $yearGroup, Request $request): Response
    {
        if ($this->isCsrfTokenValid('yg' . $yearGroup->getId(), $request->request->get('_token'))) {
            foreach ($yearGroup->getClassrooms() as $classroom) {
                $classroom->setYearGroup(null);
            }
            $this->em->remove($yearGroup);
            $this->em->flush();
            $this->addFlash('success', 'Year group deleted.');
        }

        return $this->redirectToRoute('app_tenant_year_group_index');
    }
}
