<?php

namespace App\Service\Students;

use App\Entity\Tenant\Student;
use App\Service\Wording\LearnerWording;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a pupil, and everything that belongs only to them.
 *
 * Built for one situation: a school has uploaded its roll, a row is wrong, and
 * there is no other way to take it back. It is NOT a way to erase a pupil who
 * owes money or has paid — the guard below refuses those outright, because a
 * receipt, a statement or a verification code that points at a pupil who no
 * longer exists is worse than a duplicate row.
 *
 * What is deleted: enrolment, invoices and their lines, result component scores,
 * attendance, behaviour ratings, term remarks, subject choices and exemptions,
 * discounts, report archives/share tokens/verification codes, pending payment
 * intents. What is kept: the guardian (they may have other children) and the
 * parent login, and any application that produced the pupil — its `student_id`
 * is nulled so the application history survives with the pupil removed.
 *
 * Runs inside one transaction: a foreign key we did not anticipate rolls the
 * whole thing back rather than leaving a half-deleted child.
 */
final class StudentRemover
{
    /** Tables keyed directly by `student_id`, deleted after the invoice lines. */
    private const STUDENT_OWNED_TABLES = [
        'attendance_entry',
        'behaviour_rating',
        'enrollment',
        'report_archive',
        'report_share_token',
        'report_verification',
        'result_component_score',
        'student_advance',
        'student_discount',
        'student_subject',
        'student_subject_exemption',
        'student_term_remark',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LearnerWording $wording,
    ) {
    }

    /**
     * Why this pupil must not be deleted, in words a bursar can act on — or
     * null when deletion is safe.
     */
    public function blocker(Student $student): ?string
    {
        $id = (int) $student->getId();
        $conn = $this->em->getConnection();
        $who = $student->getFullName();

        $payments = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM payment p
               INNER JOIN invoice i ON i.id = p.invoice_id
              WHERE i.student_id = ?',
            [$id]
        );

        if ($payments > 0) {
            return sprintf(
                '%s has %d payment(s) on record. A %s who has paid cannot be deleted — the receipt and the statement would keep pointing at a %s who no longer exists. Reverse the payment first if the whole row was a mistake.',
                $who,
                $payments,
                $this->wording->singular(),
                $this->wording->singular()
            );
        }

        $credits = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM account_credit ac
               INNER JOIN invoice i ON i.id = ac.invoice_id
              WHERE i.student_id = ?',
            [$id]
        );

        if ($credits > 0) {
            return sprintf(
                '%s has %d credit note(s) on record. Credit is money too — clear it before deleting the %s.',
                $who,
                $credits,
                $this->wording->singular()
            );
        }

        $advances = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM student_advance WHERE student_id = ?',
            [$id]
        );

        if ($advances > 0) {
            return sprintf(
                '%s has an advance payment on record. That is money received — refund or move it before deleting the %s.',
                $who,
                $this->wording->singular()
            );
        }

        return null;
    }

    /**
     * @throws \Throwable when a foreign key we did not anticipate still points
     *                    at the pupil — the transaction is rolled back first.
     */
    public function remove(Student $student): void
    {
        $id = (int) $student->getId();
        $conn = $this->em->getConnection();

        $conn->beginTransaction();

        try {
            // 1. Invoice children first (payment and account_credit are empty —
            //    blocker() refused otherwise — but a sub-select keeps the order
            //    honest if one ever slips through).
            $conn->executeStatement(
                'DELETE FROM payment_intent WHERE invoice_id IN (SELECT id FROM invoice WHERE student_id = ?)',
                [$id]
            );
            $conn->executeStatement(
                'DELETE FROM invoice_item WHERE invoice_id IN (SELECT id FROM invoice WHERE student_id = ?)',
                [$id]
            );
            $conn->executeStatement('DELETE FROM invoice WHERE student_id = ?', [$id]);

            // Intents that were never attached to an invoice.
            $conn->executeStatement('DELETE FROM payment_intent WHERE student_id = ?', [$id]);

            // 2. Everything else keyed by the pupil.
            foreach (self::STUDENT_OWNED_TABLES as $table) {
                $conn->executeStatement(sprintf('DELETE FROM %s WHERE student_id = ?', $table), [$id]);
            }

            // 3. History that outlives the pupil keeps its row, loses the link.
            $conn->executeStatement('UPDATE application SET student_id = NULL WHERE student_id = ?', [$id]);
            $conn->executeStatement('UPDATE user SET student_id = NULL WHERE student_id = ?', [$id]);

            // 4. The pupil.
            $conn->executeStatement('DELETE FROM student WHERE id = ?', [$id]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        // The row is gone; drop the managed entity so nothing can flush it back.
        $this->em->clear();
    }
}
