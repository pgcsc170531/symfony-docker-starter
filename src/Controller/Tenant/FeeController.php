<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use App\Service\Finance\ClassLevels;
use App\Service\Finance\FeeSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request; // 🟢 Needed for query params
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/finance/fees')]
#[IsGranted('ROLE_BURSAR')]
class FeeController extends AbstractController
{
   #[Route('/schedule', name: 'app_tenant_fee_schedule')]
    public function schedule(Request $request, EntityManagerInterface $em): Response
    {
        $school = $em->getRepository(School::class)->find(1);

        // 1. FETCH HISTORY (For Dropdown)
        $allTerms = $em->getRepository(Term::class)->createQueryBuilder('t')
            ->join('t.session', 's')
            ->orderBy('s.name', 'DESC')
            ->addOrderBy('t.startDate', 'DESC')
            ->getQuery()
            ->getResult();

        // 2. DETERMINE TERM
        $selectedTermId = $request->query->get('term');
        if ($selectedTermId) {
            $term = $em->getRepository(Term::class)->find($selectedTermId);
        } else {
            $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
            if (!$term && count($allTerms) > 0) $term = $allTerms[0];
        }

        if (!$term) {
            $this->addFlash('warning', 'No terms found.');
            return $this->redirectToRoute('app_tenant_dashboard');
        }

        // 3. CHECK IF FIRST TERM (To hide session fees)
        $termName = strtolower($term->getName());
        $isFirstTerm = (str_contains($termName, '1st') || str_contains($termName, 'first') || str_starts_with($termName, '1'));

        // 4. FETCH FEES
        $queryBuilder = $em->getRepository(FeeStructure::class)->createQueryBuilder('fs')
            ->join('fs.feeItem', 'fi')
            ->join('fs.classroom', 'c')
            ->where('fs.term = :term')
            ->setParameter('term', $term)
            ->orderBy('fi.position', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        // Filter: Hide 'SESSION' fees if not 1st term
        if (!$isFirstTerm) {
            $queryBuilder->andWhere("fi.frequency != 'SESSION'");
        }

        $structures = $queryBuilder->getQuery()->getResult();

        // 5. GROUP LEVELS — one column per level (Nursery 1), not per arm.
        //    Arms that agree print one number; arms that differ print "from ₦X"
        //    with every arm listed underneath. The old code kept the last arm read
        //    and presented it as the level price — see FeeSchedule.
        $classrooms = $em->getRepository(Classroom::class)->findAll();
        $levels = ClassLevels::group($classrooms);
        $levelByClassroom = ClassLevels::index($levels);

        $rows = [];
        /** @var FeeStructure $fs */
        foreach ($structures as $fs) {
            $classroom = $fs->getClassroom();
            $item = $fs->getFeeItem();

            $rows[] = [
                'item' => (string) $item->getName(),
                'itemId' => (int) $item->getId(),
                'level' => $levelByClassroom[(int) $classroom->getId()] ?? $classroom->getName(),
                'arm' => (string) $classroom->getName(),
                'amount' => (float) $fs->getAmount(),
                'oneTime' => $item->getFrequency() === 'ONETIME',
            ];
        }

        $schedule = FeeSchedule::build($rows, array_keys($levels));
        // 7. PREPARE ROWS FOR DISPLAY
        $feeItems = $em->getRepository(FeeItem::class)->findBy([], ['position' => 'ASC']);
        
        $recurringItems = array_filter($feeItems, function($i) use ($isFirstTerm) {
            return $i->getFrequency() !== 'ONETIME' && ($isFirstTerm || $i->getFrequency() !== 'SESSION');
        });

        $oneTimeItems = array_filter($feeItems, fn($i) => $i->getFrequency() === 'ONETIME');

        return $this->render('tenant/onboarding/step6_summary.html.twig', [
            'school' => $school,
            'term' => $term,
            'allTerms' => $allTerms,
            'levels' => array_keys($levels),
            'levelClasses' => $levels,
            'recurringItems' => $recurringItems,
            'oneTimeItems' => $oneTimeItems,
            'cells' => $schedule['cells'],
            'totals' => $schedule['totals'],
            'isFirstTerm' => $isFirstTerm
        ]);
    }
}