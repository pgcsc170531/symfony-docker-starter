---
description: "Rules for generating any PDF in Edus — A4 only, shared A4PdfRenderer, QR verification footer, plain-CSS print templates. Load whenever a PDF, printable view, report card, transcript, statement or receipt is being created or changed."
applyTo: "src/Service/**/*Pdf*.php, src/Service/Pdf/**/*.php, templates/**/*pdf*.twig, templates/**/pdf/**/*.twig, src/Controller/**/*Controller.php"
---

# PDF generation rules

Full standard and copy-ready skeleton: **`.github/skills/pdf-documents/SKILL.md`**.

## The five that catch people out

1. **One entry point.** All Dompdf work goes through `App\Service\Pdf\A4PdfRenderer`.
   Never `new Dompdf()` in a controller or a second renderer.
2. **A4 portrait, always.** 595.28 × 841.89 pt. `@page { size: A4 portrait; margin: 6mm 8mm 4mm 8mm; }`
   in the template, and nothing overrides it. A narrower CSS page silently wins
   over `setPaper()`, producing a strip on a sheet.
3. **Every issued document carries a QR + short code** in the footer, via
   `App\Service\Pdf\QrCodeRenderer`. The QR encodes a public verification URL —
   never the document, never amounts.
4. **Print templates are plain CSS and tables.** No Tailwind utility, no flex,
   no grid, no CSS variables, no web fonts. Screen template and print template
   stay separate files.
5. **Images are data URIs** (`assetDataUri()` / `uploadedImageDataUri()`), because
   remote loading is disabled and the PDF must survive being forwarded.

## Serving

`Content-Type: application/pdf`, an explicit `Content-Length`, a slugged dated
filename, and a `?format=html` branch that renders the **same context** as the
PDF so the screen proof can never disagree with the print.

## Before calling it done

- `php -l` the renderer, `lint:twig` the template, clear the cache.
- `grep -a MediaBox` on the generated file → must be `595.28 841.89`.
- The footer shows the verification URL, the code and the QR square.
- Printing twice gives the same code.
