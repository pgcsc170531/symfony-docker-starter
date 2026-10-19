<?php

namespace App\Service\Results;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A short, stable code for a whole-school performance report.
 *
 * Printed QR codes must stay scannable after the 30-day share link has expired,
 * so the QR cannot contain a signed URL with an expiry and a long hash. The code
 * is an HMAC of the term and year group: short enough for a low-density QR, and
 * verifiable without a database row.
 */
final class PerformanceReportCode
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function for(int $termId, int $yearGroupId): string
    {
        return strtoupper(substr(
            hash_hmac('sha256', 'performance|' . $termId . '|' . $yearGroupId, $this->secret),
            0,
            12
        ));
    }

    public function formatted(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');

        return implode('-', str_split($code, 4));
    }
}
