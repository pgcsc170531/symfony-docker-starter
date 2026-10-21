<?php

namespace App\Service\Payment;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\ApplicationEvent;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
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

        // One settlement path for both journeys; only the bookkeeping differs.
        if ($intent->isApplicationFee()) {
            return $this->settleApplicationFee($intent, $rawPayload);
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
     * An application fee: the applicant is not a pupil yet, so there is no
     * invoice. The ledger row carries the money and the reference; the
     * `Application` carries the link and the gate.
     */
    private function settleApplicationFee(PaymentIntent $intent, string $rawPayload): bool
    {
        $application = $intent->getApplication();
        if (!$application instanceof Application) {
            $this->logger->error('Application fee settlement failed for {reference}: intent has no application', [
                'reference' => $intent->getReference(),
            ]);

            return false;
        }

        // The bursar may have confirmed an uploaded receipt while this intent was
        // still open. The money is already counted then; a second Payment row
        // would invent revenue. Record the attempt and leave the books alone.
        if (in_array($application->getFeeStatus(), [Application::FEE_PAID, Application::FEE_WAIVED], true)) {
            $this->logger->warning('Application fee already settled for {application}; online payment {reference} needs a refund check', [
                'application' => $application->getId(),
                'reference' => $intent->getReference(),
            ]);

            $intent->setStatus(PaymentIntent::STATUS_PAID);
            $intent->setPaidAt(new \DateTimeImmutable());
            if ($rawPayload !== '') {
                $intent->setRawPayload($rawPayload);
            }
            $this->em->flush();

            return false;
        }

        $settledAmount = (string) $intent->getInvoiceAmount();

        try {
            $this->em->wrapInTransaction(function () use ($intent, $application, $settledAmount, $rawPayload): void {
                $payment = new Payment();
                $payment->setInvoice(null); // no invoice exists before enrolment
                $payment->setAmount($settledAmount);
                $payment->setMethod('ONLINE');
                $payment->setStatus('CONFIRMED');
                $payment->setReferenceCode($intent->getReference()); // unique → double-settle guard
                $payment->setReference($intent->getProviderReference());
                $payment->setConfirmedAt(new \DateTimeImmutable());
                $payment->setConfirmedBy('ONLINE GATEWAY');
                $this->em->persist($payment);

                $application->setFeePayment($payment);
                $application->setFeeStatus(Application::FEE_PAID);
                $application->setFeeAmount(number_format((float) $settledAmount, 2, '.', ''));
                $application->setFeeChannel(Application::CHANNEL_ONLINE);
                $application->setFeeReference($intent->getProviderReference() ?: $intent->getReference());
                $application->setFeePaidAt(new \DateTimeImmutable());

                // Pay-first: settling the fee unlocks the form. It does NOT
                // submit the application — the parent still has to fill it in.
                if ($application->getStatus() === Application::STATUS_AWAITING_PAYMENT) {
                    $from = $application->getStatus();
                    $application->setStatus(Application::STATUS_DRAFT);

                    $event = new ApplicationEvent();
                    $event->setFromStatus($from);
                    $event->setToStatus(Application::STATUS_DRAFT);
                    $event->setNote('Application fee paid online. You can now complete the application form.');
                    $event->setActorLabel('ONLINE GATEWAY');
                    $event->setCreatedAt(new \DateTimeImmutable());
                    $application->addEvent($event);
                }

                $this->em->persist($application);

                $intent->setStatus(PaymentIntent::STATUS_PAID);
                $intent->setPaidAt(new \DateTimeImmutable());
                $intent->setPayment($payment);
                if ($rawPayload !== '') {
                    $intent->setRawPayload($rawPayload);
                }
                $this->em->persist($intent);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Application fee settlement write failed for {reference}: {message}', [
                'reference' => $intent->getReference(),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('Application fee settled: {reference} — ₦{amount} on application {application}', [
            'reference' => $intent->getReference(),
            'amount' => $settledAmount,
            'application' => $application->getId(),
        ]);

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
