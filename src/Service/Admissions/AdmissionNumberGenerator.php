<?php

namespace App\Service\Admissions;

use App\Entity\Tenant\Session;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One place that decides what an admission number looks like.
 *
 * There used to be two: admissions produced `ADM/2026/0001`, the bulk import
 * produced `26/6EDD7` (a two-digit year and five random hex characters). A school
 * therefore issued two different shapes of number for the same thing, could not
 * set its own — "SMC/26/0001" was impossible — and had no incrementing integer
 * anywhere in the import path.
 *
 * The school now writes a pattern once, in its settings:
 *
 *     ADM/{YEAR}/{SEQ}      ADM/2026/0001, ADM/2026/0002, …
 *     SMC/{YY}/{SEQ:3}      SMC/26/001, SMC/26/002, …
 *     2026/{SEQ}            2026/0001, …
 *
 * Tokens: {YEAR} the session's first year, {YY} its last two digits, {SEQ} the
 * next number in this school's sequence (padding: `{SEQ:4}`).
 *
 * The next number is derived from the highest number already issued **that
 * matches the pattern**, never from a row id — so deleting a pupil cannot hand
 * their number to somebody else.
 */
final class AdmissionNumberGenerator
{
    public const TOKENS = '{YEAR} {YY} {SEQ}';

    /** Highest number issued this request, per resolved prefix. */
    private array $issued = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The pattern in force, or the default when the school has not chosen one. */
    public function pattern(): string
    {
        $school = $this->em->getRepository(School::class)->findOneBy([]);
        $pattern = trim((string) $school?->getAdmissionNumberPattern());

        return $pattern !== '' ? $pattern : 'ADM/{YEAR}/{SEQ}';
    }

    public function next(?Session $session = null): string
    {
        $pattern = $this->pattern();
        $year = $this->yearFor($session);

        [$prefix, $width] = $this->resolve($pattern, $year);

        $start = max($this->highestIssued($prefix), $this->issued[$prefix] ?? 0) + 1;

        for ($n = $start; $n < $start + 5000; $n++) {
            $candidate = $prefix . str_pad((string) $n, $width, '0', STR_PAD_LEFT);

            if (!$this->exists($candidate)) {
                $this->issued[$prefix] = $n;

                return $candidate;
            }
        }

        throw new \RuntimeException('Could not issue an admission number: the pattern has no room left.');
    }

    /**
     * Explain the pattern to a human, using a sample number.
     * "SMC/26/{SEQ:3} → SMC/26/001, SMC/26/002".
     */
    public function describe(): string
    {
        $pattern = $this->pattern();
        $year = $this->yearFor(null);
        [$prefix, $width] = $this->resolve($pattern, $year);

        return sprintf(
            '%s → %s, %s',
            $pattern,
            $prefix . str_pad('1', $width, '0', STR_PAD_LEFT),
            $prefix . str_pad('2', $width, '0', STR_PAD_LEFT)
        );
    }

    /**
     * Split the pattern into everything before the sequence and the padding
     * width. Anything after {SEQ} is dropped on purpose: a moving suffix makes
     * "what comes next" unanswerable from the numbers already issued.
     *
     * @return array{0: string, 1: int}
     */
    private function resolve(string $pattern, int $year): array
    {
        $width = 4;

        if (preg_match('/\{SEQ(?::(\d+))?\}/', $pattern, $m)) {
            $width = isset($m[1]) && $m[1] !== '' ? max(1, (int) $m[1]) : 4;
        }

        $head = preg_replace('/\{SEQ(?::\d+)?\}.*$/', '', $pattern) ?? $pattern;
        $head = str_replace(['{YEAR}', '{YY}'], [(string) $year, substr((string) $year, -2)], $head);

        return [$head, $width];
    }

    private function highestIssued(string $prefix): int
    {
        $highest = $this->em->getConnection()->fetchOne(
            'SELECT MAX(CAST(SUBSTRING(admission_number, ?) AS UNSIGNED))
               FROM student
              WHERE admission_number LIKE ?',
            [strlen($prefix) + 1, $prefix . '%']
        );

        return (int) $highest;
    }

    private function exists(string $number): bool
    {
        return $this->em->getRepository(Student::class)->findOneBy(['admissionNumber' => $number]) !== null;
    }

    private function yearFor(?Session $session): int
    {
        $name = trim((string) $session?->getName());

        if (preg_match('/^(\d{4})/', $name, $m)) {
            return (int) $m[1];
        }

        return (int) (new \DateTimeImmutable())->format('Y');
    }
}
