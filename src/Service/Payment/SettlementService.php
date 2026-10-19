<?php

namespace App\Service\Payment;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\PaymentIntent;
use App\Service\SchoolMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a *confirmed* payment attempt into money in the ledger.
 *
 * This is the only place an online payment becomes real. Both entry points —
 * the parent returning from the gateway, and the gateway's webhook arriving
 * later or twice — come through here, so the two can never disagree.
 *
 * Idempotent by construction:
 *   • `PaymentIntent::isSettleable()` is checked first, and
 *   • the ledger row uses the intent's reference as its unique `reference_code`,
 *     so even two concurrent callers cannot both write one.
 */
final class SettlementService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SchoolMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $rawPayload the gateway's own payload, stored for disputes
     *
     * @return bool true when this call is the one that settled it
     */
    public function settle(PaymentIntent $intent, string $rawPayload = ''): bool
    {
        // Already decided — a repeat webhook, or the callback arriving after the
        // webhook already did the work. Nothing to do, and nothing to undo.
        if (!$intent->isSettleable()) {
            $this->logger->info('Settlement skipped for {reference}: already {status}', [
                'reference' => $intent->getReference(),
                'status' => $intent->getStatus(),
            ]);

            return false;
        }

        $invoice = $intent->getInvoice();
        if (!$invoice instanceof Invoice) {
            $this->logger->error('Settlement failed for {reference}: intent has no invoice', [
                'reference' => $intent->getReference(),
            ]);

            return false;
        }

        // Only the school-fees slice is revenue. The processing fee the parent
        // paid on top belongs to the gateway, never to the school's ledger.
        $settledAmount = (string) $intent->getInvoiceAmount();

        try {
            $this->em->wrapInTransaction(function () use ($intent, $invoice, $settledAmount, $rawPayload): void {
                $payment = new Payment();
                $payment->setInvoice($invoice);
                $payment->setAmount($settledAmount);
                $payment->setMethod('ONLINE');
                $payment->setStatus('CONFIRMED');
                $payment->setReferenceCode($intent->getReference()); // unique → double-settle guard
                $payment->setReference($intent->getProviderReference());
                $payment->setConfirmedAt(new \DateTimeImmutable());
                $payment->setConfirmedBy('ONLINE GATEWAY');
                $this->em->persist($payment);

                $newPaid = (float) $invoice->getPaidAmount() + (float) $settledAmount;
                $invoice->setPaidAmount(number_format($newPaid, 2, '.', ''));
                $invoice->setStatus(
                    $newPaid >= ((float) $invoice->getTotalAmount() - 0.01) ? 'PAID' : 'PARTIAL'
                );
                $this->em->persist($invoice);

                $intent->setStatus(PaymentIntent::STATUS_PAID);
                $intent->setPaidAt(new \DateTimeImmutable());
                $intent->setPayment($payment);
                if ($rawPayload !== '') {
                    $intent->setRawPayload($rawPayload);
                }
                $this->em->persist($intent);
            });
        } catch (\Throwable $e) {
            // Most likely the unique reference_code — i.e. someone else won the
            // race. That is a success, not a failure.
            $this->logger->warning('Settlement write failed for {reference}: {message}', [
                'reference' => $intent->getReference(),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('Payment settled: {reference} — ₦{amount} on invoice {invoice}', [
            'reference' => $intent->getReference(),
            'amount' => $settledAmount,
            'invoice' => (string) $invoice->getInvoiceNumber(),
        ]);

        // Receipt email. The parent already has their money's worth on screen;
        // a mail failure must not roll the settlement back.
        $school = $this->em->getRepository(\App\Entity\Tenant\School::class)->findOneBy([]);
        if ($school !== null && $intent->getPayment() !== null) {
            $this->mailer->paymentReceipt($school, $intent->getPayment());
        }

        return true;
    }

    /**
     * Mark an attempt as failed so the parent can try again cleanly. The invoice
     * is deliberately left untouched — a failed attempt owes nothing.
     */
    public function fail(PaymentIntent $intent, ?string $reason = null): void
    {
        if ($intent->isPaid()) {
            return;
        }

        $intent->setStatus(PaymentIntent::STATUS_FAILED);
        if ($reason !== null) {
            $intent->setRawPayload($reason);
        }
        $this->em->flush();
    }
}
