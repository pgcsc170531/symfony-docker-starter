<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\Subscription;
use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Landlord\TermRegistration;
use App\Service\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ONE code path that turns a confirmed payment into an active subscription.
 *
 * Both the landlord's manual approval and the online gateway callback/webhook
 * call this. If they diverged, a school would get a different period depending
 * on how the money arrived — which is exactly the kind of bug nobody notices
 * until a term ends early.
 *
 * ⚠️ `Subscription::$endDate` is mapped as Doctrine `datetime` (mutable), so
 * every value written here must be a `DateTime`. Writing a `DateTimeImmutable`
 * throws at flush time — after the school has already paid.
 */
final class SubscriptionActivationService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * @return bool true if this call changed anything, false if the payment was
     *              already fully settled (idempotent for webhook retries)
     */
    public function activate(SubscriptionPayment $payment): bool
    {
        $school = $payment->getSchool();
        $plan = $payment->getPlan();
        if ($school === null || $plan === null) {
            return false;
        }

        $registration = $payment->getTermRegistration();

        // An overage invoice settles money; it never grants another period.
        if ($payment->isSupplementary()) {
            if ($payment->getStatus() === 'APPROVED') {
                return false;
            }

            $payment->setStatus('APPROVED');
            $payment->setVerifiedAt(new \DateTime());
            $this->doctrine->getManager('landlord')->flush();

            return true;
        }

        // Fully settled: approved AND (no registration, or its period applied).
        // This also lets a failed post-payment step be repaired by retrying.
        if ($payment->getStatus() === 'APPROVED'
            && ($registration === null || $registration->getStatus() === TermRegistration::STATUS_PAID)) {
            return false;
        }

        if ($payment->getStatus() !== 'APPROVED') {
            $payment->setStatus('APPROVED');
            $payment->setVerifiedAt(new \DateTime());
        }

        $current = $school->getSubscription();

        if ($registration === null) {
            // Legacy flat plans: the plan's own duration decides the period.
            if ($current === null) {
                $this->subscriptions->createSubscription($school, $plan, false);
            } elseif ($current->getPlan()?->getId() === $plan->getId()) {
                $this->subscriptions->renewSubscription($current);
            } else {
                $this->subscriptions->upgradeSubscription($current, $plan);
            }
        } else {
            // A registration knows the period it paid for. Do not also let
            // renewSubscription() stack 4 months on top of it.
            if ($current === null) {
                $subscription = $this->subscriptions->createSubscription($school, $plan, false);
            } elseif ($current->getPlan()?->getId() === $plan->getId()) {
                $subscription = $current;
            } else {
                $this->subscriptions->upgradeSubscription($current, $plan);
                $subscription = $current;
            }

            $this->applyRegistrationPeriod($subscription, $registration);
        }

        $this->doctrine->getManager('landlord')->flush();

        return true;
    }

    /**
     * One 4-month term, or the whole year when it was prepaid.
     *
     * A subscription created/upgraded in this activation starts now, so its end
     * is `start + period`. An existing subscription being renewed keeps its
     * remaining days and the period is added to the end date.
     */
    private function applyRegistrationPeriod(Subscription $subscription, TermRegistration $registration): void
    {
        $months = $registration->getBillingCycle() === TermRegistration::CYCLE_ANNUAL ? 12 : 4;

        $now = new \DateTime();
        $start = $subscription->getStartDate();

        $freshlyStarted = $start !== null && $start >= (new \DateTime('-1 day'));

        // Doctrine maps this column as mutable `datetime`; never hand it an immutable.
        $base = $freshlyStarted
            ? \DateTime::createFromInterface($start)
            : ($subscription->getEndDate() !== null && $subscription->getEndDate() > $now
                ? \DateTime::createFromInterface($subscription->getEndDate())
                : clone $now);

        $subscription->setEndDate($base->modify(sprintf('+%d months', $months)));
        $subscription->setStatus('ACTIVE');

        $registration->setStatus(TermRegistration::STATUS_PAID);
    }
}
