<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request; // 🟢 Needed for query params
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/finance/fees')]
#[IsGranted('ROLE_ADMIN')]
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

        // 5. GROUP LEVELS
        $classrooms = $em->getRepository(Classroom::class)->findAll();
        $levels = [];
        foreach ($classrooms as $class) {
            $name = $class->getName();
            $parts = explode(' ', $name);
            if (count($parts) > 1) {
                $last = end($parts);
                if ((strlen($last) <= 2 && !is_numeric($last)) || in_array(strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'])) {
                    array_pop($parts);
                    $levelName = implode(' ', $parts);
                } else {
                    $levelName = $name;
                }
            } else {
                $levelName = $name;
            }
            $levels[$levelName][] = $class;
        }
        ksort($levels, SORT_NATURAL);

        // 6. CALCULATE TOTALS (With Duplicate Protection 🛡️)
        $matrix = [];
        $totals = []; 

        // Initialize totals
        foreach (array_keys($levels) as $lvl) {
            $totals[$lvl] = ['recurring' => 0, 'onetime' => 0];
        }

        // 🛡️ THE FIX: This array remembers what we have already added
        $processedItems = []; // Format: "ItemID_LevelName"

        /** @var FeeStructure $fs */
        foreach ($structures as $fs) {
            $itemName = $fs->getFeeItem()->getName();
            $itemId   = $fs->getFeeItem()->getId();
            $freq     = $fs->getFeeItem()->getFrequency();
            $isOneTime = ($freq === 'ONETIME');
            
            // Map Class -> Level
            $cName = $fs->getClassroom()->getName();
            $levelName = $cName; 
            foreach (array_keys($levels) as $lvl) {
                if (str_starts_with($cName, $lvl)) {
                    $levelName = $lvl;
                    break;
                }
            }

            // A. Always update the Visual Matrix
            $matrix[$itemName][$levelName] = $fs->getAmount();

            // B. Calculate Totals (ONLY ONCE per Level)
            $uniqueKey = $itemId . '_' . $levelName;

            // 🛡️ CHECK: Have we added this item for this level yet?
            if (!isset($processedItems[$uniqueKey])) {
                
                if ($isOneTime) {
                    $totals[$levelName]['onetime'] += $fs->getAmount();
                } else {
                    $totals[$levelName]['recurring'] += $fs->getAmount();
                }

                // Mark as done so we don't add it again for "JSS 1B"
                $processedItems[$uniqueKey] = true; 
            }
        }
        
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
            'recurringItems' => $recurringItems,
            'oneTimeItems' => $oneTimeItems,
            'matrix' => $matrix,
            'totals' => $totals,
            'isFirstTerm' => $isFirstTerm
        ]);
    }
}