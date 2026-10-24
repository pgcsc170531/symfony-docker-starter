<?php

namespace App\Service\Payment;

use App\Entity\Landlord\VirtualAccountIndex;
use App\Entity\Tenant\AccountCredit;
use App\Service\Tenant\TenantConnectionSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The webhook the payment provider actually calls: one URL for the whole account.
 *
 * Schools live in separate databases, but Paystack knows nothing about our
 * subdomains — so this handler runs on the **public domain**, reads the customer
 * code off the payload, looks the school up in the landlord routing table
 * (`virtual_account_index`), switches the tenant connection, and only then hands
 * the money to {@see VirtualAccountCreditService}.
 *
 * Consequences that matter:
 *   • nothing tenant-side is queried before the switch, so a webhook cannot read
 *     the wrong school's data;
 *   • an unknown customer is logged and **acknowledged** — returning an error
 *     would make Paystack retry a payload we can never place;
 *   • an inactive school is refused the same way, and the money is left at
 *     Paystack's side for the operator to settle by hand.
 */
final class PlatformWebhookService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        private readonly TenantConnectionSwitcher $switcher,
        private readonly VirtualAccountCreditService $credits,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data the webhook's `data` object
     */
    public function handle(array $data): ?AccountCredit
    {
        $customerCode = (string) ($data['customer']['customer_code'] ?? $data['authorization']['customer_code'] ?? '');

        if ($customerCode === '') {
            $this->logger->warning('Platform webhook without a customer code; nothing to route.');

            return null;
        }

        $index = $this->landlordEm->getRepository(VirtualAccountIndex::class)->findOneBy([
            'customerCode' => $customerCode,
        ]);

        if ($index === null) {
            $this->logger->warning('Platform webhook for unknown customer {customer} — no school owns it yet.', [
                'customer' => $customerCode,
            ]);

            return null;
        }

        $school = $index->getSchool();

        if ($school === null || !$school->isActive()) {
            $this->logger->warning('Platform webhook for an inactive school ({school}); left unplaced.', [
                'school' => $index->getCustomerCode(),
            ]);

            return null;
        }

        // From here on, the default connection is this school's database.
        $this->switcher->switchTo($school);

        return $this->credits->ingest($data);
    }
}
