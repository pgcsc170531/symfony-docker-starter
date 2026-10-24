<?php

namespace App\Service\Payment;

use App\Entity\Tenant\AccountCredit;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts an **unassigned credit** onto a bill, when a human says which one.
 *
 * This is the only way an unmatched transfer becomes money the school can see on
 * a pupil's account — never an automatic guess across siblings
 * (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §5.5). The credit is applied **in
 * full**: a credit larger than the bill is refused rather than split, because a
 * split is a decision with two questions in it ("which bill, and what happens to
 * the rest?") and we would rather the bursar answer both.
 */
final class AccountCreditApplicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LedgerPoster $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{ok: bool, message?: string} */
    public function apply(AccountCredit $credit, Invoice $invoice, string $by = 'parent'): array
    {
        if (!$credit->isUnassigned()) {
            return ['ok' => false, 'message' => 'That money has already been applied.'];
        }

        $amount = (float) $credit->getAmount();
        $balance = (float) $invoice->getBalanceDue();

        if ($balance <= 0) {
            return ['ok' => false, 'message' => 'That bill is already fully paid.'];
        }

        if ($amount > $balance + 0.01) {
            return [
                'ok' => false,
                'message' => 'That transfer is larger than this bill. Ask the bursar to split it across bills.',
            ];
        }

        try {
            $this->em->wrapInTransaction(function () use ($credit, $invoice, $amount, $by): void {
                $payment = new Payment();
                $payment->setInvoice($invoice);
                $payment->setAmount(number_format($amount, 2, '.', ''));
                $payment->setMethod('TRANSFER');
                $payment->setStatus('CONFIRMED');
                $payment->setReferenceCode('CREDIT-' . $credit->getId()); // unique per credit
                $payment->setReference($credit->getProviderReference());
                $payment->setConfirmedAt(new \DateTimeImmutable());
                $payment->setConfirmedBy(strtoupper($by));
                $this->em->persist($payment);

                $newPaid = (float) $invoice->getPaidAmount() + $amount;
                $invoice->setPaidAmount(number_format($newPaid, 2, '.', ''));
                $invoice->setStatus(
                    $newPaid >= ((float) $invoice->getTotalAmount() - 0.01) ? 'PAID' : 'PARTIAL'
                );
                $this->em->persist($invoice);

                $credit->markApplied($invoice, $payment, $by);
                $this->em->persist($credit);

                // The books: whatever the school was credited is theirs, the
                // remainder of the transfer is our charge (LEDGER §6.3).
                $this->ledger->creditApplied($credit);
            });
        } catch (\Throwable $e) {
            $this->logger->error('Applying credit {credit} to invoice {invoice} failed: {message}', [
                'credit' => $credit->getId(),
                'invoice' => $invoice->getId(),
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'We could not apply that payment. Please try again.'];
        }

        return ['ok' => true];
    }
}
