<?php

namespace App\Service\Geo;

use App\Entity\Tenant\Country;
use App\Entity\Tenant\LGA;
use App\Entity\Tenant\State;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts the Nigerian Country, States and LGAs into one tenant database.
 *
 * The student admission form (and the parent application form) renders three
 * `EntityType` dropdowns — Nationality, State of origin, LGA — over these
 * tables. Without rows they are three empty selects, so this runs:
 *
 *  - automatically for every new school, from {@see \App\Service\TenantProvisioner};
 *  - on demand for existing schools, from `app:tenant:seed-nigeria`.
 *
 * Idempotent: matches the country on its ISO code and states/LGAs on their
 * name, so running it twice adds nothing. It never touches rows a school has
 * edited, and it does not flush-and-clear the EntityManager, so it is safe to
 * call from inside a larger unit of work (provisioning).
 */
final class GeographySeeder
{
    /**
     * @return array{country_created:bool, states_added:int, lgas_added:int}
     */
    public function seedNigeria(EntityManagerInterface $em): array
    {
        $countryCreated = false;
        $country = $em->getRepository(Country::class)
            ->findOneBy(['isoCode' => NigeriaGeoData::ISO_CODE]);

        if ($country === null) {
            $country = (new Country())
                ->setName(NigeriaGeoData::COUNTRY_NAME)
                ->setIsoCode(NigeriaGeoData::ISO_CODE);
            $em->persist($country);
            $countryCreated = true;
        }

        // Two queries instead of ~810 existence checks: load what is already
        // there, then persist only the difference.
        $existingStates = [];
        foreach ($em->createQueryBuilder()
            ->select('s.id', 's.name')
            ->from(State::class, 's')
            ->where('s.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->getArrayResult() as $row) {
            $existingStates[(string) $row['name']] = (int) $row['id'];
        }

        $existingLgas = [];
        foreach ($em->createQueryBuilder()
            ->select('s.name AS stateName', 'l.name AS lgaName')
            ->from(LGA::class, 'l')
            ->join('l.state', 's')
            ->where('s.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->getArrayResult() as $row) {
            $existingLgas[(string) $row['stateName']][(string) $row['lgaName']] = true;
        }

        $statesAdded = 0;
        $lgasAdded = 0;

        foreach (NigeriaGeoData::states() as $stateName => $lgaNames) {
            $state = null;

            if (!isset($existingStates[$stateName])) {
                $state = (new State())
                    ->setName($stateName)
                    ->setCountry($country);
                $em->persist($state);
                $statesAdded++;
            }

            $missing = [];
            foreach ($lgaNames as $lgaName) {
                if (!isset($existingLgas[$stateName][$lgaName])) {
                    $missing[] = $lgaName;
                }
            }

            if ($missing === []) {
                continue;
            }

            // A state that already existed is not loaded above — a reference is
            // enough to write its foreign key.
            $parent = $state ?? $em->getReference(State::class, $existingStates[$stateName]);

            foreach ($missing as $lgaName) {
                $em->persist((new LGA())->setName($lgaName)->setState($parent));
                $lgasAdded++;
            }
        }

        $em->flush();

        return [
            'country_created' => $countryCreated,
            'states_added' => $statesAdded,
            'lgas_added' => $lgasAdded,
        ];
    }
}
