<?php

namespace App\Repository\Landlord;

use App\Entity\Landlord\GlobalSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GlobalSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalSetting::class);
    }

    /**
     * Optimized helper to get a value directly
     */
    public function getValue(string $key, string $default = ''): string
    {
        $setting = $this->findOneBy(['settingKey' => $key]);
        return $setting ? $setting->getSettingValue() : $default;
    }
}