<?php

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * The one way this application produces a document.
 *
 * Every printable artefact — report card, school performance report, transcript,
 * Value & Billing statement, receipt — goes through this class so that they share
 * a paper size, a font stack, an empty margin model and a failure mode. A PDF is
 * something a school hands to a board, a bank or an inspector; inconsistency
 * between two of them reads as carelessness.
 *
 * What is fixed here and must not be overridden per document:
 *
 *  - **Paper**: A4 portrait, 595.28 x 841.89 pt. Never Letter, never a custom box.
 *  - **Font**: DejaVu Sans, which ships with Dompdf, so a document can be built
 *    on a server with no network access and still embed its glyphs.
 *  - **No remote fetches**: `setIsRemoteEnabled(false)`. Images are data URIs.
 *  - **Chroot**: `public/`, so an accidental `file:///etc/passwd` cannot be read.
 *
 * The templates that feed it stay deliberately dumb: plain CSS and tables only.
 * Dompdf has no flexbox and no grid, and Tailwind utilities on a PDF produce a
 * blank or collapsed page.
 */
final class A4PdfRenderer
{
    /** A4 in PostScript points — 210mm x 297mm. */
    private const A4 = ['595.28', '841.89'];

    private readonly string $publicDir;

    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->publicDir = rtrim($projectDir, '/') . '/public';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context): string
    {
        return $this->renderHtml($this->twig->render($template, $context));
    }

    /**
     * Render already-built HTML. Useful when the caller had to assemble the
     * document itself (for example a merged multi-section report).
     */
    public function renderHtml(string $html): string
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('A4');
        $options->setDefaultPaperOrientation('portrait');
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot($this->publicDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');

        // Explicit A4 box. `@page { size: A4 }` in the template agrees with this;
        // if the two ever disagree Dompdf silently keeps the smaller of the two,
        // which is how a document ends up looking like a narrow strip on a sheet.
        $dompdf->setPaper([0, 0, (float) self::A4[0], (float) self::A4[1]], 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Read a file under `public/` and return it as a data URI.
     *
     * Dompdf cannot fetch over the network here, and a data URI also means the
     * document is self-contained: forwarded, archived or printed, the logo is
     * still there.
     *
     * @param string $relativePath e.g. `images/brand/edus-mark.png`
     */
    public function assetDataUri(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $path = $this->publicDir . '/' . $relativePath;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));
    }

    /**
     * The same, for an optional uploaded logo whose filename lives on the entity.
     */
    public function uploadedImageDataUri(?string $filename, string $directory = 'uploads/logos'): ?string
    {
        $filename = $filename === null ? '' : basename(trim($filename));
        if ($filename === '') {
            return null;
        }

        return $this->assetDataUri(trim($directory, '/') . '/' . $filename);
    }
}
