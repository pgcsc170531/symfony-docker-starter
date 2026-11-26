<?php

namespace App\Service\Phone;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Find a family by the phone number somebody typed.
 *
 * The database holds the same subscriber in every shape the office has typed over
 * the years: `08039658200` from today's form, `8039658200` from the importer that
 * dropped the trunk zero, `0803 965 8200` from a CSV, `+234-803-965-8200` from an
 * admission slip. Comparing strings misses all but one of them, which is exactly
 * how "the same person" ends up as two logins.
 *
 * So the comparison happens on digits, in SQL: strip everything that is not a
 * number, keep the last ten (the subscriber number, without the trunk zero or the
 * country code), and compare that. `0171034567` and `+234171034567` are the only
 * shapes a Nigerian mobile can take.
 */
final class PhoneMatcher
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Family rows carrying this number, in any stored spelling.
     *
     * @return list<Guardian>
     */
    public function guardiansByPhone(string $phone, int $limit = 2, bool $unclaimedOnly = false): array
    {
        $national = PhoneNumber::national($phone);

        // Anything shorter than a subscriber number is not worth matching on.
        if (strlen($national) < 10) {
            return [];
        }

        $sql = 'SELECT id FROM guardian'
            . " WHERE RIGHT(REGEXP_REPLACE(phone_number, '[^0-9]', ''), 10) = ?";

        if ($unclaimedOnly) {
            $sql .= ' AND user_id IS NULL';
        }

        $sql .= ' ORDER BY id LIMIT ' . max(1, $limit);

        $ids = $this->em->getConnection()->fetchFirstColumn($sql, [$national]);

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (int|string $id): ?Guardian => $this->em->find(Guardian::class, (int) $id),
            $ids
        )));
    }

    /**
     * The login behind a phone number, through the family record.
     *
     * A teacher promoted from a parent account no longer signs in with the phone —
     * their login is a real inbox now — but the family row still holds the number,
     * and that is what the office tells them to use.
     */
    public function userByPhone(string $phone): ?User
    {
        foreach ($this->guardiansByPhone($phone, 2) as $guardian) {
            if ($guardian->getUser() !== null) {
                return $guardian->getUser();
            }
        }

        return null;
    }

    /** The same number as stored, so the caller can show what it matched. */
    public static function sqlDigits(string $column): string
    {
        return sprintf("REGEXP_REPLACE(%s, '[^0-9]', '')", $column);
    }
}
