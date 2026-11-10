<?php

namespace App\Service\Geo;

use App\Entity\Tenant\Country;
use App\Entity\Tenant\School;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The word this school uses for a first-level region.
 *
 * Lives in a service (not only in the Twig helper) because form types need it
 * too: `StudentAdmissionType` labels the field at build time, and a label is not
 * something a template can always override.
 *
 * Resolved at most once per request. Never throws: a label is not worth a 500,
 * so landlord screens (no tenant connection) and a school with no row yet get
 * "State", which is the Nigerian default every early tenant was built on.
 */
final class SchoolRegionLabel
{
    private ?string $label = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function label(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        $this->label = 'State';

        try {
            $school = $this->em->getRepository(School::class)->findOneBy([]);

            if ($school !== null) {
                $country = $this->em->getRepository(Country::class)
                    ->findOneBy(['isoCode' => $school->getCountry()]);

                if ($country !== null) {
                    $this->label = $country->getRegionLabel();
                }
            }
        } catch (\Throwable) {
            // No tenant connection — keep the default rather than break the form.
        }

        return $this->label;
    }
}
