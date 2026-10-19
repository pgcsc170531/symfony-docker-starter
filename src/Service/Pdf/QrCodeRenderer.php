<?php

namespace App\Service\Pdf;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * The square mark in the footer of every issued document.
 *
 * It is a PNG data URI rather than a file on disk, because Dompdf runs with
 * remote loading disabled and no writable scratch directory. It encodes a public
 * verification URL, never the document itself — a scan must confirm authenticity
 * without handing over the contents.
 */
final class QrCodeRenderer
{
    /** Pixels at the source. Printed at ~30pt, Dompdf scales it down cleanly. */
    private const SIZE = 600;

    public function dataUri(string $data): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::SIZE,
            margin: 4,
        ))->build();

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
}
