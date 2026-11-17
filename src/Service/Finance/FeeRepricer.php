<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-prices bills that were already generated, after the school changes a price.
 *
 * Changing an amount on the fee schedule used to affect only *future* invoices.
 * A bursar who corrected tuition from ₦50,000 to ₦55,000 after the term had been
 * billed saw nothing happen, and the families kept the old figure — so the change
 * was applied by hand, one pupil at a time, or not at all.
 *
 * The rules that make an automatic re-price safe:
 *
 *  - **Only the term and the arms that were edited.** A price change on JSS 1 is
 *    not a reason to touch SS 2's bills.
 *  - **Only ACADEMIC invoices.** A store sale is not a fee.
 *  - **Only lines the schedule still prices.** An item the school removed from
 *    the arm keeps the amount the family was already billed; silently deleting a
 *    line from a bill a parent has seen is worse than leaving it.
 *  - **Money already paid is never erased.** A bill is never pushed below what
 *    the family has handed over; if a decrease would do that, the total stops at
 *    the paid amount and the excess is a credit the office settles.
 *  - **The discount is re-applied**, because it is a percentage of the new gross.
 */
final class FeeRepricer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiscountCalculator $discounts,
    ) {
    }

    /**
     * @param list<Classroom> $classrooms the arms whose prices were just changed
     *
     * @return array{invoices: int, changed: int, untouched: int, kept_lines: int, from: float, to: float}
     */
    public function repriceTerm(Term $term, array $classrooms): array
    {
        $result = ['invoices' => 0, 'changed' => 0, 'untouched' => 0, 'kept_lines' => 0, 'from' => 0.0, 'to' => 0.0];

        $arms = [];
        foreach ($classrooms as $classroom) {
            if ($classroom instanceof Classroom) {
                $arms[$this->classroomKey($classroom)] = $classroom;
            }
        }

        if ($arms === []) {
            return $result;
        }

        // arm key → fee item key → amount, from the schedule as it now stands.
        /** @var array<string, array<string, float>> $prices */
        $prices = [];
        foreach ($this->em->getRepository(FeeStructure::class)->findBy(['term' => $term]) as $structure) {
            $classroom = $structure->getClassroom();
            $item = $structure->getFeeItem();

            if ($classroom === null || $item === null || !isset($arms[$this->classroomKey($classroom)])) {
                continue;
            }

            $prices[$this->classroomKey($classroom)][$this->itemKey($item)] = (float) $structure->getAmount();
        }

        if ($prices === []) {
            return $result;
        }

        // One query per arm (a level has a handful), which keeps the service
        // testable without a live query builder and limits the blast radius to the
        // arms whose prices were actually edited.
        $invoices = [];
        foreach ($arms as $classroom) {
            // ACADEMIC is the current type; 'TUITION' is what older rows were
            // written with before the constant existed. A school that has been
            // running for a while has both, and a re-price that ignores half its
            // bills is worse than no re-price at all.
            foreach ([Invoice::TYPE_ACADEMIC, 'TUITION'] as $type) {
                foreach ($this->em->getRepository(Invoice::class)->findBy([
                    'term' => $term,
                    'type' => $type,
                    'classroom' => $classroom,
                ]) as $candidate) {
                    if ($candidate instanceof Invoice) {
                        $invoices[$candidate->getId() ?? spl_object_id($candidate)] = $candidate;
                    }
                }
            }
        }

        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }

            ++$result['invoices'];

            $classroomId = (int) $invoice->getClassroom()?->getId();
            $schedule = $prices[$this->classroomKey($invoice->getClassroom() ?? new Classroom())] ?? [];

            foreach ($invoice->getItems() as $item) {
                $feeItem = $item->getFeeItem();

                if ($feeItem === null) {
                    continue;
                }

                $key = $this->itemKey($feeItem);

                if (!isset($schedule[$key])) {
                    // The school no longer prices this line for this arm: leave the
                    // family's bill exactly as it was billed.
                    ++$result['kept_lines'];

                    continue;
                }

                $quantity = max(1, (int) ($item->getQuantity() ?? 1));
                $item->setAmount(number_format(round($schedule[$key] * $quantity, 2), 2, '.', ''));
            }

            $gross = $this->discounts->grossFromItems($invoice->getItems());
            $tuitionBase = $this->discounts->tuitionBaseFromItems($invoice->getItems());
            $student = $invoice->getStudent();
            $discount = $student !== null
                ? $this->discounts->calculateDiscount($student, $tuitionBase, $gross)
                : 0.0;

            $paid = (float) $invoice->getPaidAmount();
            $oldTotal = (float) $invoice->getTotalAmount();
            $newTotal = max($paid, max(0.0, $gross - $discount));

            $label = $discount > 0.005 && $student !== null
                ? $this->discounts->describe($student)
                : null;
            $labelChanged = (string) $invoice->getDiscountLabel() !== (string) $label;

            if (abs($newTotal - $oldTotal) < 0.005
                && abs((float) $invoice->getDiscountApplied() - $discount) < 0.005
                && !$labelChanged) {
                ++$result['untouched'];

                continue;
            }

            $invoice->setDiscountApplied(number_format($discount, 2, '.', ''));
            $invoice->setDiscountLabel($label);
            $invoice->setTotalAmount(number_format($newTotal, 2, '.', ''));
            $invoice->setStatus(Invoice::statusFor($paid, $newTotal));

            ++$result['changed'];
            $result['from'] += $oldTotal;
            $result['to'] += $newTotal;
        }

        if ($result['changed'] > 0) {
            $this->em->flush();
        }

        $result['from'] = round($result['from'], 2);
        $result['to'] = round($result['to'], 2);

        return $result;
    }

    /**
     * A stable key for an arm. Doctrine hands back one instance per row, so the id
     * is enough; a not-yet-persisted arm falls back to the object identity.
     */
    private function classroomKey(Classroom $classroom): string
    {
        return $classroom->getId() !== null ? 'c' . $classroom->getId() : 'o' . spl_object_id($classroom);
    }

    /** A stable key for a fee item, on the same terms. */
    private function itemKey(\App\Entity\Tenant\FeeItem $item): string
    {
        return $item->getId() !== null ? 'i' . $item->getId() : 'o' . spl_object_id($item);
    }
}
