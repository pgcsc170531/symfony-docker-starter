<?php

namespace App\Service\SchoolLife;

use App\Entity\Tenant\SchoolEvent;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Which days was the school actually open?"
 *
 * The denominator on the report card comes from here, not from however many
 * registers a form master remembered to take. A day counts when it is:
 *   • inside the term's start/end dates,
 *   • not a Saturday or Sunday,
 *   • not covered by a holiday `SchoolEvent`.
 *
 * Holidays are recognised by `type` OR `title` mentioning holiday / break /
 * vacation / public holiday — schools enter these as free text, so matching on the
 * type alone loses half of them.
 */
final class SchoolCalendar
{
    private const HOLIDAY_PATTERN = '/holiday|break|vacation|mid[- ]?term|public\s+holiday/i';

    /** @var array<int, array<string, \DateTimeImmutable>> term id → Y-m-d => date */
    private array $cache = [];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, \DateTimeImmutable> keyed by Y-m-d, ascending
     */
    public function schoolDays(Term $term): array
    {
        $id = $term->getId();
        if ($id !== null && isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $start = $term->getStartDate();
        $end = $term->getEndDate();
        if (!$start || !$end) {
            return [];
        }

        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($end < $start) {
            return [];
        }

        $holidays = $this->holidayRanges($start, $end);

        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if (in_array((int) $day->format('N'), [6, 7], true)) {
                continue;
            }
            if ($this->withinHoliday($day, $holidays)) {
                continue;
            }
            $days[$day->format('Y-m-d')] = $day;
        }

        if ($id !== null) {
            $this->cache[$id] = $days;
        }

        return $days;
    }

    public function isSchoolDay(Term $term, \DateTimeImmutable $day): bool
    {
        return array_key_exists($day->format('Y-m-d'), $this->schoolDays($term));
    }

    /** How many school days have passed up to and including $day. */
    public function schoolDaysUpTo(Term $term, \DateTimeImmutable $day): int
    {
        $limit = $day->format('Y-m-d');
        $count = 0;
        foreach ($this->schoolDays($term) as $key => $_) {
            if ($key > $limit) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /** @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> */
    private function holidayRanges(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SchoolEvent::class, 'e')
            ->where('e.startDate <= :end')
            ->andWhere('e.endDate >= :start OR (e.endDate IS NULL AND e.startDate >= :start)')
            ->setParameter('end', $end->setTime(23, 59, 59))
            ->setParameter('start', $start)
            ->getQuery()
            ->getResult();

        $ranges = [];
        foreach ($events as $event) {
            /** @var SchoolEvent $event */
            $haystack = ($event->getType() ?? '') . ' ' . ($event->getTitle() ?? '');
            if (!preg_match(self::HOLIDAY_PATTERN, $haystack)) {
                continue;
            }

            $from = \DateTimeImmutable::createFromInterface($event->getStartDate())->setTime(0, 0);
            $to = $event->getEndDate()
                ? \DateTimeImmutable::createFromInterface($event->getEndDate())->setTime(0, 0)
                : $from;

            $ranges[] = [$from, $to];
        }

        return $ranges;
    }

    /** @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges */
    private function withinHoliday(\DateTimeImmutable $day, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($day >= $from && $day <= $to) {
                return true;
            }
        }

        return false;
    }
}
