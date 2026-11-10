<?php

namespace App\Service\Geo;

use App\Entity\Tenant\Country;
use App\Entity\Tenant\LGA;
use App\Entity\Tenant\State;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Intl\Countries;

/**
 * Puts the world's countries — and the regions Edus knows — into one tenant.
 *
 * The nationality dropdown on the admission form renders every `Country` row,
 * so a tenant seeded with Nigeria alone offers exactly one nationality. That was
 * the state of every school until this class existed; a school in Iraq could not
 * even record where its own pupils came from.
 *
 * Three levels, three sources:
 *
 *  - **Countries** come from `symfony/intl` (249 of them, ICU's names) so they
 *    are never hand-typed and never drift.
 *  - **First-level regions** come from {@see RegionData} — the markets we are
 *    actually asked about, with each country's own word for that level.
 *  - **Nigeria keeps its third level**, the 774 LGAs in {@see NigeriaGeoData}.
 *
 * Idempotent throughout: countries match on ISO code, regions and LGAs on name.
 * Safe to run on every deploy, and safe to run inside provisioning.
 */
final class GeographySeeder
{
    /**
     * @return array{countries_created:int, countries_total:int, regions_added:int, lgas_added:int}
     */
    public function seedAll(EntityManagerInterface $em): array
    {
        $countriesCreated = $this->seedCountries($em);

        /** @var array<string, Country> $byIso */
        $byIso = [];
        foreach ($em->getRepository(Country::class)->findAll() as $country) {
            $iso = strtoupper((string) $country->getIsoCode());
            if ($iso !== '') {
                $byIso[$iso] = $country;
            }
        }

        $regionsAdded = 0;
        foreach (RegionData::regions() as $iso => $names) {
            if (!isset($byIso[$iso])) {
                continue;
            }

            $regionsAdded += $this->seedRegions($em, $byIso[$iso], $names);
        }

        // Nigeria is the only country with a second level underneath.
        $statesAdded = 0;
        $lgasAdded = 0;
        if (isset($byIso['NG'])) {
            [$statesAdded, $lgasAdded] = $this->seedNigeria($em, $byIso['NG']);
        }

        $em->flush();

        return [
            'countries_created' => $countriesCreated,
            'countries_total' => count(Countries::getNames('en')),
            'regions_added' => $regionsAdded + $statesAdded,
            'lgas_added' => $lgasAdded,
        ];
    }

    /**
     * Every ISO country, with the word that country uses for its regions.
     * Existing rows are updated, not duplicated — so a country seeded before
     * this class (Nigeria) simply gains its label.
     */
    private function seedCountries(EntityManagerInterface $em): int
    {
        $existing = [];
        foreach ($em->getRepository(Country::class)->findAll() as $country) {
            $existing[strtoupper((string) $country->getIsoCode())] = $country;
        }

        $created = 0;

        foreach (Countries::getNames('en') as $iso => $name) {
            $country = $existing[$iso] ?? null;

            if ($country === null) {
                $country = (new Country())->setIsoCode($iso);
                $em->persist($country);
                $created++;
            }

            $country->setName($name)->setRegionLabel(RegionData::label($iso));
        }

        $em->flush();

        return $created;
    }

    /**
     * @param list<string> $names
     */
    private function seedRegions(EntityManagerInterface $em, Country $country, array $names): int
    {
        $existing = [];
        foreach ($em->createQueryBuilder()
            ->select('s.name')
            ->from(State::class, 's')
            ->where('s.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->getArrayResult() as $row) {
            $existing[(string) $row['name']] = true;
        }

        $added = 0;

        foreach ($names as $name) {
            if (isset($existing[$name])) {
                continue;
            }

            $em->persist((new State())->setName($name)->setCountry($country));
            $added++;
        }

        return $added;
    }

    /**
     * Nigeria's 36 states + FCT and their 774 LGAs.
     *
     * @return array{0:int, 1:int} [states added, LGAs added]
     */
    private function seedNigeria(EntityManagerInterface $em, Country $nigeria): array
    {
        $existingStates = [];
        foreach ($em->createQueryBuilder()
            ->select('s.id', 's.name')
            ->from(State::class, 's')
            ->where('s.country = :country')
            ->setParameter('country', $nigeria)
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
            ->setParameter('country', $nigeria)
            ->getQuery()
            ->getArrayResult() as $row) {
            $existingLgas[(string) $row['stateName']][(string) $row['lgaName']] = true;
        }

        $statesAdded = 0;
        $lgasAdded = 0;

        foreach (NigeriaGeoData::states() as $stateName => $lgaNames) {
            $state = null;

            if (!isset($existingStates[$stateName])) {
                $state = (new State())->setName($stateName)->setCountry($nigeria);
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
            // enough to write the foreign key.
            $parent = $state ?? $em->getReference(State::class, $existingStates[$stateName]);

            foreach ($missing as $lgaName) {
                $em->persist((new LGA())->setName($lgaName)->setState($parent));
                $lgasAdded++;
            }
        }

        return [$statesAdded, $lgasAdded];
    }
}
