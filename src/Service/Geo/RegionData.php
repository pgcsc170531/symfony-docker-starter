<?php

namespace App\Service\Geo;

/**
 * First-level divisions for the countries Edus is being asked about, and what
 * each country calls that level.
 *
 * Nigeria is deliberately absent: its states and 774 LGAs live in
 * {@see NigeriaGeoData} and are seeded with a second level under them. Every
 * other country here has one level — a governorate, region, province, district
 * or county — and no LGA underneath.
 *
 * Adding a market is one array in `regions()` and one line in `LABELS`; nothing
 * else in the code needs to know. Names are the country's own (English form),
 * because that is what a school there recognises: "Nineveh", not "Ninawa";
 * "Murang'a", not "Muranga".
 */
final class RegionData
{
    /**
     * ISO 3166-1 alpha-2 => the word that country uses for its first-level
     * division. Anything unlisted falls back to "Region", which is true for
     * most of the world and wrong for none of it.
     */
    public const LABELS = [
        'NG' => 'State',
        'IQ' => 'Governorate',
        'GH' => 'Region',
        'CI' => 'District',
        'SN' => 'Region',
        'ZA' => 'Province',
        'KE' => 'County',
        'AE' => 'Emirate',
        'SA' => 'Region',
        'QA' => 'Municipality',
        'JO' => 'Governorate',
        'LB' => 'Governorate',
        'EG' => 'Governorate',
        'CM' => 'Region',
    ];

    public static function label(string $isoCode): string
    {
        return self::LABELS[strtoupper($isoCode)] ?? 'Region';
    }

    /**
     * @return array<string, list<string>> country code => region names
     */
    public static function regions(): array
    {
        return [
            'IQ' => [ // Governorates
                'Anbar', 'Babil', 'Baghdad', 'Basra', 'Dhi Qar', 'Diyala', 'Duhok', 'Erbil',
                'Karbala', 'Kirkuk', 'Maysan', 'Muthanna', 'Najaf', 'Nineveh', 'Qadisiyyah',
                'Saladin', 'Sulaymaniyah', 'Wasit',
            ],
            'GH' => [ // Regions
                'Ahafo', 'Ashanti', 'Bono', 'Bono East', 'Central', 'Eastern', 'Greater Accra',
                'North East', 'Northern', 'Oti', 'Savannah', 'Upper East', 'Upper West',
                'Volta', 'Western', 'Western North',
            ],
            'CI' => [ // Districts
                'Abidjan', 'Bas-Sassandra', 'Comoé', 'Denguélé', 'Gôh-Djiboua', 'Lacs',
                'Lagunes', 'Montagnes', 'Sassandra-Marahoué', 'Savanes', 'Vallée du Bandama',
                'Woroba', 'Yamoussoukro', 'Zanzan',
            ],
            'SN' => [ // Regions
                'Dakar', 'Diourbel', 'Fatick', 'Kaffrine', 'Kaolack', 'Kédougou', 'Kolda',
                'Louga', 'Matam', 'Saint-Louis', 'Sédhiou', 'Tambacounda', 'Thiès', 'Ziguinchor',
            ],
            'ZA' => [ // Provinces
                'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
                'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
            ],
            'KE' => [ // Counties
                'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa',
                'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi',
                'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu',
                'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa',
                "Murang'a", 'Nairobi City', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua',
                'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi',
                'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot',
            ],
        ];
    }
}
