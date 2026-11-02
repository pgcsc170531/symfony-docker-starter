<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Term;
use App\Service\Finance\ClassLevels;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fee amounts, per arm.
 *
 * The fee is stored per arm already (`fee_structure.classroom_id`), and invoices
 * have always billed the pupil's own arm — so SS 1 Science could always have cost
 * more than SS 1 Arts. What was missing was a screen to say so: the schedule
 * priced a level and copied the figure to every arm, then printed one number for
 * the level as if the arms could not differ.
 *
 * This screen edits the arms directly. A level is still one click away — the
 * quick-set box writes one amount to every arm of the level — but a single arm
 * may be given its own amount, which is what a science levy on SS 1 Science
 * actually needs.
 */
#[Route('/finance/fees/amounts', name: 'app_tenant_fee_amounts')]
#[IsGranted('ROLE_BURSAR')]
final class FeeAmountsController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function amounts(Request $request, EntityManagerInterface $em): Response
    {
        $terms = $em->getRepository(Term::class)->createQueryBuilder('t')
            ->join('t.session', 's')
            ->orderBy('s.name', 'DESC')
            ->addOrderBy('t.startDate', 'DESC')
            ->getQuery()
            ->getResult();

        $term = null;
        if ($termId = $request->query->get('term')) {
            $term = $em->getRepository(Term::class)->find($termId);
        }
        $term ??= $em->getRepository(Term::class)->findOneBy(['isActive' => true]) ?? ($terms[0] ?? null);

        if ($term === null) {
            $this->addFlash('warning', 'No terms yet — create the academic calendar first.');

            return $this->redirectToRoute('app_tenant_dashboard');
        }

        /** @var array<string, list<Classroom>> $levels level name → its arms */
        $levels = ClassLevels::group($em->getRepository(Classroom::class)->findAll());
        $levelNames = array_keys($levels);

        $level = (string) $request->query->get('level', '');
        if (!isset($levels[$level])) {
            $level = $levelNames[0] ?? '';
        }

        /** @var list<FeeItem> $items */
        $items = $em->getRepository(FeeItem::class)->findBy([], ['position' => 'ASC']);

        // Term fees keyed classroom|item, so the save loop is a lookup.
        /** @var array<string, FeeStructure> $existing */
        $existing = [];
        foreach ($em->getRepository(FeeStructure::class)->findBy(['term' => $term]) as $structure) {
            $existing[$this->key($structure->getClassroom()?->getId(), $structure->getFeeItem()?->getId())] = $structure;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fee_amounts', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please try again.');

                return $this->redirectToRoute('app_tenant_fee_amounts', ['term' => $term->getId(), 'level' => $level]);
            }

            $classroomsById = [];
            foreach ($levels as $arms) {
                foreach ($arms as $arm) {
                    $classroomsById[(int) $arm->getId()] = $arm;
                }
            }
            $itemsById = [];
            foreach ($items as $item) {
                $itemsById[(int) $item->getId()] = $item;
            }

            $changed = 0;
            $removed = 0;

            foreach ((array) $request->request->all()['amounts'] as $classroomId => $itemValues) {
                $classroom = $classroomsById[(int) $classroomId] ?? null;
                if ($classroom === null || !is_array($itemValues)) {
                    continue;
                }

                foreach ($itemValues as $itemId => $raw) {
                    $item = $itemsById[(int) $itemId] ?? null;
                    if ($item === null) {
                        continue;
                    }

                    $mapKey = $this->key($classroom->getId(), $item->getId());
                    $value = trim((string) $raw);

                    if ($value === '') {
                        // A cleared box means "this arm does not pay this item".
                        if (isset($existing[$mapKey])) {
                            $em->remove($existing[$mapKey]);
                            unset($existing[$mapKey]);
                            ++$removed;
                        }

                        continue;
                    }

                    $amount = round((float) preg_replace('/[^0-9.\-]/', '', $value), 2);

                    if ($amount < 0) {
                        continue;
                    }

                    if (isset($existing[$mapKey])) {
                        if (abs((float) $existing[$mapKey]->getAmount() - $amount) > 0.004) {
                            $existing[$mapKey]->setAmount(number_format($amount, 2, '.', ''));
                            ++$changed;
                        }

                        continue;
                    }

                    $structure = new FeeStructure();
                    $structure->setClassroom($classroom)
                        ->setTerm($term)
                        ->setFeeItem($item)
                        ->setAmount(number_format($amount, 2, '.', ''));
                    $em->persist($structure);
                    $existing[$mapKey] = $structure;
                    ++$changed;
                }
            }

            $em->flush();

            $this->addFlash($changed + $removed > 0 ? 'success' : 'info', match (true) {
                $changed > 0 && $removed > 0 => sprintf('%d amount%s set and %d cleared for %s.', $changed, $changed === 1 ? '' : 's', $removed, $level),
                $changed > 0 => sprintf('%d amount%s set for %s.', $changed, $changed === 1 ? '' : 's', $level),
                $removed > 0 => sprintf('%d amount%s cleared for %s.', $removed, $removed === 1 ? '' : 's', $level),
                default => 'Nothing changed.',
            });

            return $this->redirectToRoute('app_tenant_fee_amounts', ['term' => $term->getId(), 'level' => $level]);
        }

        // level name → classroom name → item id → amount (as typed)
        $values = [];
        foreach ($levels[$level] ?? [] as $arm) {
            foreach ($items as $item) {
                $row = $existing[$this->key($arm->getId(), $item->getId())] ?? null;
                $values[(int) $arm->getId()][(int) $item->getId()] = $row === null ? '' : (string) $row->getAmount();
            }
        }

        return $this->render('tenant/fee/amounts.html.twig', [
            'terms' => $terms,
            'term' => $term,
            'levels' => $levelNames,
            'level' => $level,
            'arms' => $levels[$level] ?? [],
            'items' => $items,
            'values' => $values,
        ]);
    }

    private function key(?int $classroomId, ?int $itemId): string
    {
        return $classroomId . '|' . $itemId;
    }
}
