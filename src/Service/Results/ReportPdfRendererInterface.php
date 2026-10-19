<?php

namespace App\Service\Results;

/**
 * The PDF engine is swappable from day one.
 *
 * Dompdf is the v1 renderer (RESULT-CARD-DESIGN.md §8). If the design later needs
 * true flex/grid, a Gotenberg-backed implementation replaces this one class and
 * the print template keeps working with minor CSS changes.
 */
interface ReportPdfRendererInterface
{
    /**
     * @param array<string, mixed> $sheet the structure returned by ResultSheetBuilder::build()
     */
    public function render(array $sheet): string;
}
