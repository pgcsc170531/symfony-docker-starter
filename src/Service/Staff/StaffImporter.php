<?php

namespace App\Service\Staff;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\User;
use App\Service\Phone\PhoneNumber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bulk staff registration from a CSV file.
 *
 * Follows the student importer (QuickAdmissionController::uploadCsv) for the two
 * things that make a 1,000-row upload survivable — lift the execution limit and
 * hash the shared default password ONCE — but deliberately differs in one place:
 * that importer keeps every entity in the unit of work until a single flush, and
 * on a large file it runs out of memory. Here the batch is flushed and the unit
 * of work cleared every {@see self::BATCH} rows, so memory stays flat whether the
 * file has 20 rows or 5,000.
 *
 * Rows are never half-applied: a row is validated before anything is persisted,
 * and a row that fails is reported and skipped instead of aborting the file.
 */
final class StaffImporter
{
    /**
     * The password every imported account starts with. Staff are forced to
     * change it on first sign-in, so this is a doorway, not a credential.
     */
    public const DEFAULT_PASSWORD = '12345678';

    /**
     * Rows per flush. Big enough that a 1,000-row file is ~10 round trips,
     * small enough that the change-set never balloons.
     */
    private const BATCH = 100;

    /** Header aliases → the field they mean. Lowercased, punctuation stripped. */
    private const COLUMNS = [
        'fullname' => 'name',
        'name' => 'name',
        'staffname' => 'name',
        'phone' => 'phone',
        'phonenumber' => 'phone',
        'mobile' => 'phone',
        'telephone' => 'phone',
        'email' => 'email',
        'emailaddress' => 'email',
        'homeaddress' => 'address',
        'address' => 'address',
        'jobtitle' => 'job_title',
        'role' => 'job_title',
        'title' => 'job_title',
        'staffnumber' => 'employee_number',
        'employeenumber' => 'employee_number',
        'staffid' => 'employee_number',
        'employeeid' => 'employee_number',
    ];

    /**
     * Job title in the file → the login role it grants. Anything unrecognised
     * becomes a class teacher, which is the safe default: the office can promote
     * the person from the staff screen afterwards.
     *
     * There is deliberately no way to bulk-create a Super Admin.
     */
    private const ROLE_BY_TITLE = [
        'teacher' => User::ROLE_TEACHER,
        'classteacher' => User::ROLE_TEACHER,
        'subjectteacher' => User::ROLE_TEACHER,
        'formteacher' => User::ROLE_TEACHER,
        'bursar' => 'ROLE_BURSAR',
        'finance' => 'ROLE_BURSAR',
        'financeofficer' => 'ROLE_BURSAR',
        'accountant' => 'ROLE_BURSAR',
        'store' => 'ROLE_STORE',
        'storekeeper' => 'ROLE_STORE',
        'admissionofficer' => 'ROLE_ADMISSION_OFFICER',
        'admissions' => 'ROLE_ADMISSION_OFFICER',
        'admin' => User::ROLE_ADMIN,
        'administrator' => User::ROLE_ADMIN,
        'principal' => User::ROLE_ADMIN,
        'headteacher' => User::ROLE_ADMIN,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @throws \RuntimeException when the file cannot be read or has no usable header
     */
    public function import(UploadedFile $file): StaffImportResult
    {
        // 1,000 accounts in one request: no time limit, and room for the change-set
        // between flushes. (The student importer learned this the hard way.)
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('The uploaded file could not be opened.');
        }

        try {
            return $this->read($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function read($handle): StaffImportResult
    {
        $result = new StaffImportResult();

        // One hash for every row: argon2/bcrypt per row is what makes a large
        // import crawl. Every imported account shares this identical hash, and
        // every one of them is forced to change it on first login.
        $defaultHash = $this->hasher->hashPassword(new User(), self::DEFAULT_PASSWORD);

        // Emails already taken, in one query, so the per-row check is a lookup
        // and not 1,000 SELECTs.
        $taken = [];
        foreach ($this->em->createQuery('SELECT LOWER(u.email) FROM ' . User::class . ' u')
                     ->getSingleColumnResult() as $email) {
            $taken[(string) $email] = true;
        }

        $header = fgetcsv($handle);
        $map = $this->mapHeader($header);

        if ($map === null) {
            throw new \RuntimeException(
                'That file does not look like a staff list. The first row must name the columns — '
                . 'one of them has to be "Full Name". Download the template to see the expected layout.'
            );
        }

        $rowNumber = 1;
        $pending = 0;

        while (($row = fgetcsv($handle)) !== false) {
            ++$rowNumber;

            // Blank line — a trailing newline, or the gap Excel leaves behind.
            if (array_filter($row, static fn ($v) => trim((string) $v) !== '') === []) {
                continue;
            }

            ++$result->rows;

            $data = $this->rowData($row, $map);

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $result->skip($rowNumber, 'no full name.');

                continue;
            }

            // Stored form of the number — no trunk zero, no country code — because
            // that is what the guardian record holds and what the parent's login is
            // built from. "0803…" and "+234803…" must reach the same person.
            $phone = PhoneNumber::national((string) ($data['phone'] ?? ''));
            $typedEmail = mb_strtolower(trim((string) ($data['email'] ?? '')));

            if ($typedEmail === '' && $phone === '') {
                $result->skip($rowNumber, 'needs a phone number or an email address — one of them becomes the login.');

                continue;
            }

            // The phone is the login when the office did not supply an inbox.
            $email = $typedEmail !== '' ? $typedEmail : PhoneNumber::loginEmail($phone);

            if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
                $result->skip($rowNumber, sprintf('"%s" is not a valid email address.', $email));

                continue;
            }

            $jobTitle = trim((string) ($data['job_title'] ?? ''));
            $role = self::roleForTitle($jobTitle);

            if (isset($taken[$email])) {
                /*
                 * The address already belongs to somebody. If that somebody is a
                 * parent here, this row means "the same person also works here" —
                 * so give the existing login a staff profile instead of refusing
                 * it, and leave their password alone: it is their own, chosen by
                 * them, and resetting it would lock them out of their children.
                 */
                $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                $promoted = false;

                if ($existing !== null && $existing->getStaff() === null) {
                    $staff = new Staff();
                    $staff->setUser($existing)
                        ->setPhone($phone !== '' ? $phone : null)
                        ->setAddress($this->trimTo($data['address'] ?? null, 255))
                        ->setEmployeeNumber($this->trimTo($data['employee_number'] ?? null, 50))
                        ->setTitle(self::titleFor($jobTitle, $role));

                    $existing->setRoles(array_values(array_unique(array_filter(
                        array_merge($existing->getRoles(), [$role, 'ROLE_PARENT']),
                        static fn (string $r): bool => $r !== 'ROLE_USER'
                    ))));

                    $this->em->persist($staff);
                    ++$result->promoted;
                    ++$pending;
                    $promoted = true;
                }

                if (!$promoted) {
                    $result->skip($rowNumber, sprintf('%s is already used by another account.', $email));

                    continue;
                }

                $taken[$email] = true;

                continue;
            }

            $user = new User();
            $user->setFullName($name);
            $user->setEmail($email);
            $user->setRoles([$role]);
            $user->setPassword($defaultHash);
            // They were handed a password they did not choose.
            $user->setMustChangePassword(true);

            $staff = new Staff();
            $staff->setUser($user)
                ->setPhone($phone !== '' ? $phone : null)
                ->setAddress($this->trimTo($data['address'] ?? null, 255))
                ->setEmployeeNumber($this->trimTo($data['employee_number'] ?? null, 50))
                ->setTitle(self::titleFor($jobTitle, $role));

            $this->em->persist($user);
            $this->em->persist($staff);

            /*
             * Teachers are very often parents here too. If the office has already
             * admitted their child, the guardian row is sitting there with their
             * phone number and no login of its own — give it this one. The person
             * then signs in once and is asked which portal they want.
             *
             * Only an unclaimed guardian is taken: one that already has a login
             * belongs to somebody who set their own password, and silently moving
             * it would lock them out of their own children.
             */
            if ($phone !== '') {
                $guardian = $this->unclaimedGuardianFor($phone);

                if ($guardian !== null) {
                    $guardian->setUser($user);
                    // The login is now two things at once: this person works here
                    // and is a parent here. Both roles are stored; which portal
                    // they open is chosen at sign-in.
                    $user->setRoles([$role, 'ROLE_PARENT']);
                    ++$result->linked;
                }
            }

            // Guard against the same address twice inside one file, which the
            // database lookup above cannot see until the first flush.
            $taken[$email] = true;
            ++$result->created;
            ++$pending;

            if ($pending >= self::BATCH) {
                $this->em->flush();
                $this->em->clear();
                $pending = 0;
            }
        }

        if ($pending > 0) {
            $this->em->flush();
            $this->em->clear();
        }

        return $result;
    }

    /**
     * Turn the header row into "field key => column index".
     *
     * @param list<string>|false $header
     * @return array<string, int>|null null when there is no recognisable name column
     */
    private function mapHeader(array|false $header): ?array
    {
        if ($header === false) {
            return null;
        }

        $map = [];
        foreach ($header as $index => $label) {
            $key = self::COLUMNS[preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $label)))] ?? null;
            if ($key !== null && !isset($map[$key])) {
                $map[$key] = (int) $index;
            }
        }

        return isset($map['name']) ? $map : null;
    }

    /**
     * @param list<string|null>   $row
     * @param array<string, int>  $map
     * @return array<string, string|null>
     */
    private function rowData(array $row, array $map): array
    {
        $data = [];
        foreach ($map as $key => $index) {
            $value = $row[$index] ?? null;
            $data[$key] = $value === null ? null : trim((string) $value);
        }

        return $data;
    }

    /** Phone digits only: "0803 123 4567" and "+234-803-123-4567" both reduce. */
    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * The guardian record that belongs to this phone and has no login yet.
     *
     * Numbers are stored both with and without the trunk zero ("0803…" from the
     * old admission form, "803…" from the importer), so both spellings are tried.
     */
    private function unclaimedGuardianFor(string $national): ?Guardian
    {
        return $this->em->getRepository(Guardian::class)->createQueryBuilder('g')
            ->andWhere('g.phoneNumber IN (:numbers)')
            ->andWhere('g.user IS NULL')
            ->setParameter('numbers', array_values(array_unique([
                $national, '0' . $national, '+234' . $national, '234' . $national,
            ])))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function trimTo(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    public static function roleForTitle(string $jobTitle): string
    {
        return self::ROLE_BY_TITLE[preg_replace('/[^a-z0-9]/', '', mb_strtolower($jobTitle))] ?? User::ROLE_TEACHER;
    }

    /**
     * What to store in Staff::$title — the human job title, never the role key.
     * The column is 20 characters, which is why "School Administrator" fits
     * exactly and anything longer is cut rather than rejected.
     */
    public static function titleFor(string $jobTitle, string $role): string
    {
        $typed = trim($jobTitle);

        if ($typed !== '') {
            return mb_substr($typed, 0, 20);
        }

        return match ($role) {
            User::ROLE_ADMIN => 'Administrator',
            'ROLE_BURSAR' => 'Bursar',
            'ROLE_STORE' => 'Store Keeper',
            'ROLE_ADMISSION_OFFICER' => 'Admissions Officer',
            default => 'Class Teacher',
        };
    }

    /**
     * The header the template file is written with — kept beside the parser so
     * the two cannot drift.
     *
     * @return list<string>
     */
    public static function templateHeader(): array
    {
        return ['Full Name', 'Phone', 'Email', 'Home Address', 'Job Title', 'Staff Number'];
    }
}
