<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\GradingScale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/grading-scale')]
#[IsGranted('ROLE_ADMIN')]
class GradingScaleController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'app_tenant_grading_scale_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $grade = trim((string) $request->request->get('grade'));
            $minScoreRaw = $request->request->get('min_score');
            $maxScoreRaw = $request->request->get('max_score');
            $points = trim((string) $request->request->get('points'));
            $remark = trim((string) $request->request->get('remark'));

            if ($grade !== '' && $minScoreRaw !== null && $minScoreRaw !== '' && $maxScoreRaw !== null && $maxScoreRaw !== '') {
                $existing = $this->em->getRepository(GradingScale::class)->findOneBy(['grade' => $grade]);
                if (!$existing) {
                    $scale = new GradingScale();
                    $scale->setGrade($grade);
                    $scale->setMinScore((float) $minScoreRaw);
                    $scale->setMaxScore((float) $maxScoreRaw);
                    $scale->setPoints($points !== '' ? $points : null);
                    $scale->setRemark($remark !== '' ? $remark : null);
                    $this->em->persist($scale);
                    $this->em->flush();
                    $this->addFlash('success', sprintf('Grade "%s" added.', $grade));
                } else {
                    $this->addFlash('warning', sprintf('Grade "%s" already exists.', $grade));
                }
            }

            return $this->redirectToRoute('app_tenant_grading_scale_index');
        }

        return $this->render('tenant/grading_scale/index.html.twig', [
            'scales' => $this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_grading_scale_delete', methods: ['POST'])]
    public function delete(GradingScale $gradingScale, Request $request): Response
    {
        if ($this->isCsrfTokenValid('gs' . $gradingScale->getId(), $request->request->get('_token'))) {
            $this->em->remove($gradingScale);
            $this->em->flush();
            $this->addFlash('success', 'Grading scale removed.');
        }

        return $this->redirectToRoute('app_tenant_grading_scale_index');
    }
}
