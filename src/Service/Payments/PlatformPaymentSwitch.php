<?php

namespace App\Service\Payments;

use App\Entity\Landlord\GlobalSetting;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The platform's own switch for online payments.
 *
 * A school can already choose offline / online / both for itself. What was
 * missing is the layer above it: the operator's ability to stop online
 * collection everywhere at once — when a provider is down, when a merchant
 * account is suspended, or when the platform is being wound down for the term.
 *
 * Stored as a landlord global setting so it is one row, not a schema change on
 * every tenant database. Missing row means enabled: the platform's default is
 * that schools may collect online, and a school that does not want it turns it
 * off in its own settings.
 */
final class PlatformPaymentSwitch
{
    public const KEY = 'online_payments_enabled';

    private ?bool $cached = null;

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function isEnabled(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        try {
            $setting = $this->doctrine
                ->getManager('landlord')
                ->getRepository(GlobalSetting::class)
                ->findOneBy(['settingKey' => self::KEY]);
        } catch (\Throwable) {
            // A tenant request must never fall over because the landlord schema
            // is being rebuilt; the safe default is "as configured before".
            return $this->cached = true;
        }

        if ($setting === null) {
            return $this->cached = true;
        }

        return $this->cached = !in_array(strtolower(trim((string) $setting->getSettingValue())), ['0', 'false', 'off', 'no'], true);
    }

    public function disable(): void
    {
        $this->store('0');
    }

    public function enable(): void
    {
        $this->store('1');
    }

    private function store(string $value): void
    {
        $em = $this->doctrine->getManager('landlord');
        $setting = $em->getRepository(GlobalSetting::class)->findOneBy(['settingKey' => self::KEY]);

        if ($setting === null) {
            $setting = new GlobalSetting();
            $setting->setSettingKey(self::KEY);
            $setting->setDescription('Platform-wide switch for online payment. 1 = schools may collect online; 0 = nobody may.');
            $em->persist($setting);
        }

        $setting->setSettingValue($value);
        $em->flush();
        $this->cached = $value === '1';
    }
}
