---
name: pdf-documents
description: "Build any printable Edus artefact (report card, transcript, performance report, Value & Billing statement, receipt, invoice) so it is A4, carries a QR verification code and matches every other document. Use whenever asked to generate, restyle, preview or export a PDF, or to add a printable view of an existing screen."
---

# Edus PDF documents

**One engine, one paper size, one footer.** A PDF is a document a school hands to
a board, a bank, an inspector or a parent. Two Edus documents that look like they
came from two different companies read as carelessness, and an A4 sheet that
prints as a narrow strip is unusable in a file.

This skill is normative. If a request conflicts with it, say so before writing
code.

---

## 1. The rules that are not negotiable

| # | Rule | Why |
|---|---|---|
| 1 | **Render through `App\Service\Pdf\A4PdfRenderer`.** Never construct `new Dompdf()` anywhere else. | Paper, font, chroot and failure mode are fixed in one place. |
| 2 | **A4 portrait, always** — 210 × 297 mm (595.28 × 841.89 pt). Never Letter, never a custom box, never landscape because a table is wide. | Every school prints on A4. |
| 3 | **The template says `@page { size: A4 portrait; margin: 6mm 8mm 4mm 8mm; }`** and nothing overrides it. | Dompdf silently keeps the *smaller* of CSS and `setPaper()`, which is how a document ends up as a strip on a sheet. |
| 4 | **A verification code and QR go in the footer of every issued document.** Use `App\Service\Pdf\QrCodeRenderer`. The QR encodes a public *verification URL*, never the document. | A board or a bank must be able to tell a real document from a forged one. |
| 5 | **Plain CSS and tables only.** No Tailwind, no flexbox, no grid, no `gap`, no `position: sticky`, no CSS variables, no web fonts. | Dompdf has no layout engine for modern CSS. Tailwind in a PDF produces a blank or collapsed page. |
| 6 | **No network access.** Images are data URIs from `A4PdfRenderer::assetDataUri()` / `uploadedImageDataUri()`. | Documents are generated on servers with no outbound access, and a self-contained PDF survives being forwarded. |
| 7 | **The print template is separate from the screen template.** `foo.html.twig` navigates; `foo.pdf.twig` prints. | The two have opposite constraints. Do not `include` one in the other. |
| 8 | **`?format=html` must render the *same context*** as the PDF. | The on-screen proof and the printed page may never disagree. |

---

## 2. Where everything lives

```
src/Service/Pdf/A4PdfRenderer.php     the only Dompdf entry point
src/Service/Pdf/QrCodeRenderer.php    600px PNG data URI, medium ECC
templates/**/…pdf.twig                print templates (plain CSS + tables)
src/Service/**/*PdfRenderer.php       one class per document: builds context, calls A4PdfRenderer
```

Existing documents — copy the nearest one:

| Document | Renderer | Template |
|---|---|---|
| Terminal report card | `Results\DompdfReportPdfRenderer` | `templates/tenant/reports/pdf/card.pdf.twig` |
| Session transcript | `Results\TranscriptPdfRenderer` | `templates/tenant/reports/pdf/transcript.pdf.twig` |
| Whole-school performance | `Results\SchoolPerformancePdfRenderer` | `templates/tenant/reports/pdf/performance.pdf.twig` |
| Value & Billing statement | `Subscription\SubscriptionStatementPdfRenderer` | `templates/tenant/subscription/statement.pdf.twig` |
| Subscription invoice | `Subscription\SubscriptionInvoicePdfRenderer` | `templates/tenant/subscription/invoice.pdf.twig` |
| Subscription receipt | `Subscription\SubscriptionReceiptPdfRenderer` | `templates/tenant/subscription/receipt.pdf.twig` |

### Adding a new document

```php
final class ReceiptPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
    ) {}

    public function render(array $data): string
    {
        $data['verification'] = [
            'code'  => $code,                     // short, human-readable
            'url'   => $absoluteVerifyUrl,        // public page, no amounts
            'qr'    => $this->qr->dataUri($absoluteVerifyUrl),
        ];

        return $this->pdf->render('tenant/finance/receipt.pdf.twig', $data);
    }
}
```

The controller then streams it — see §5.

---

## 3. The shared skeleton

Every print template is the same shape. Keep it; only the middle changes.

```twig
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ school.name }} — {{ doc_title }}</title>
<style>
    @page { size: A4 portrait; margin: 6mm 8mm 4mm 8mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111827; }

    /* ── Masthead ─────────────────────────────────────────────────────────
       Brand lockup left (mark + wordmark on one baseline, tagline beneath),
       document identity right. An accent rule closes the band. */
    .header { width: 100%; border-collapse: collapse; background: #16223C; color: #ffffff; }
    .header td { padding: 7pt 9pt; vertical-align: bottom; }
    .brand-cell { width: 58%; }
    .mark { font-family: "DejaVu Serif", serif; font-size: 21pt; font-weight: bold; color: #ffffff; line-height: 1; }
    .wordmark { font-family: "DejaVu Serif", serif; font-size: 17pt; font-weight: bold; color: #ffffff; margin-left: 3pt; }
    .tagline { display: block; font-size: 7.6pt; color: #B8C4D8; letter-spacing: .05em; margin-top: 3pt; }
    .doc-cell { text-align: right; }
    .doc-type { display: block; font-size: 7.6pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.1pt; color: #C3CFE2; }
    .doc-no { display: block; font-family: "DejaVu Sans Mono", monospace; font-size: 12pt; font-weight: bold; color: #ffffff; margin-top: 3pt; white-space: nowrap; }
    .accent-rule { height: 2.2pt; background: #2563EB; margin-bottom: 6pt; }

    /* ── Meta strip ───────────────────────────────────────────────────────
       A tinted band with separators — never a bordered grid per cell. */
    .identity { width: 100%; border-collapse: collapse; background: #F9FAFB; border-bottom: .8pt solid #D1D5DB; }
    .identity td { padding: 4pt 7pt; vertical-align: top; border-left: .6pt solid #E5E7EB; }
    .identity td:first-child { border-left: 0; }
    .label { display: block; font-size: 7pt; text-transform: uppercase; letter-spacing: .5pt; color: #6B7280; margin-bottom: 1pt; }
    .value { font-size: 9.8pt; font-weight: bold; }
    .value.small { font-size: 8.8pt; font-weight: normal; color: #374151; }
    .value.nowrap { white-space: nowrap; }

    /* ── Data grid ──────────────────────────────────────────────────────── */
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #F3F4F6; border: .6pt solid #D1D5DB; padding: 2.2pt 2.6pt;
                    font-size: 8.4pt; text-transform: uppercase; letter-spacing: .2pt; text-align: left; }
    table.grid td { border: .6pt solid #E5E7EB; padding: 1.6pt 2.6pt; font-size: 9.6pt; }
    table.grid td.num, table.grid th.num { text-align: right; white-space: nowrap; }
    table.grid tbody tr:nth-child(even) td { background: #FAFAFA; }

    /* ── Callout, signatures, footer ─────────────────────────────────────── */
    .notes { background: #F9FAFB; border-left: 2.5pt solid #2563EB; padding: 4pt 7pt; margin-top: 10pt; font-size: 8.4pt; }
    .sign-role { font-size: 8.2pt; color: #374151; margin-bottom: 1pt; }
    .sign-line { border-top: .5pt solid #9CA3AF; height: 16pt; }
    .sign-name { font-size: 7.6pt; color: #6B7280; padding-top: 2pt; }
    .footer { font-size: 7.8pt; color: #6B7280; border-top: .5pt solid #E5E7EB; padding-top: 2pt; margin-top: 10pt; }
</style>
</head>
<body>
    … masthead … accent rule … meta strip … sections … signature … footer …
</body>
</html>
```

**Borders are a hierarchy, not decoration.** Use exactly three weights: `0.6pt
#E5E7EB` between cells, `0.8pt #D1D5DB` to close a band, `2.2pt #2563EB` for the
one accent rule under the masthead. Never put an outline around a container that
already contains a table — that is what makes a document look like a web form.
A callout gets a tinted background and a single accent edge, not a full box.

**Colour carries meaning only for status** — `#059669` paid/approved, `#B45309`
pending/due, `#6B7280` neutral. Never use colour for decoration.

---

## 4. Verification footer (every document)

```twig
<div class="footer">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 38%;">{{ issuer }} · {{ reference }} · {{ generated_at|date('j M Y') }}</td>
            <td style="width: 40%; text-align: center; font-size: 7pt; line-height: 1.3;">
                {% if verification %}
                    Scan or visit to verify:<br>
                    <span style="font-size: 6.4pt;">{{ verification.url }}</span><br>
                    Code: <strong style="font-family: 'DejaVu Sans Mono', monospace;">{{ verification.code }}</strong>
                {% endif %}
            </td>
            <td style="width: 22%; text-align: right;">
                {% if verification %}<img src="{{ verification.qr }}" style="width: 34pt; height: 34pt;" alt=""><br>{% endif %}
                Generated by Edus
            </td>
        </tr>
    </table>
</div>
```

**Codes are random, not derived.** Use an entity + unique constraint + a service
that allocates once and reuses on reprint:

- `Results\ReportVerificationService` → one code per pupil per term.
- `Subscription\StatementVerificationService` → one code per school per document
  reference, with a `kind` (`statement`, `invoice`, `receipt`) so the public page
  can say “Genuine invoice” rather than calling every paper a statement.
  ⚠️ Receipts are keyed on `RCPT-<invoice reference>` — the invoice already owns
  `<reference>`, and the unique index is `(school_id, reference)`.

**A receipt exists only for an APPROVED payment.** Printing one for a pending or
cancelled invoice would be evidence that money never arrived.
- Alphabet excludes `O`, `0`, `I`, `1` — people retype these off paper.
- Format `ABCD-EFGH`.

The verification page **must show no amounts and no marks** — only who issued
what, to whom, and when. Set `X-Robots-Tag: noindex, nofollow` and
`Cache-Control: private, no-store`.

---

## 5. Serving it

```php
#[Route('/statement.pdf', name: 'tenant_subscription_statement', methods: ['GET'])]
public function statement(Request $request, …, SubscriptionStatementPdfRenderer $renderer): Response
{
    $data = [ … scalars only … ];

    // Screen proof. Same context as the PDF, so no QR goes missing.
    if ($request->query->get('format') === 'html') {
        return $this->render('tenant/subscription/statement.pdf.twig', $renderer->context($data, …));
    }

    $pdf = $renderer->render($data, …);

    return new Response($pdf, 200, [
        'Content-Type'   => 'application/pdf',
        'Content-Length' => (string) strlen($pdf),   // a viewer fed an unsized stream can paint a blank sheet
        'Content-Disposition' => sprintf('%s; filename="%s"',
            $request->query->getBoolean('download') ? 'attachment' : 'inline', $filename),
        'Cache-Control'  => 'private, no-cache, must-revalidate',
    ]);
}
```

- Filename: lowercase, slugged, dated — `edus-statement-gobarau-acadamy-2026-10-19.pdf`.
- `?format=html` is a **fallback**, not a second document: it appears as a quiet
  line under the download link, never as a competing primary action.
- `?download=1` forces `attachment`.

---

## 6. Verify before you say it is done

```bash
# 1. It parses
docker compose exec -T php php -l src/Service/Foo/BarPdfRenderer.php
docker compose exec -T php php -d memory_limit=512M bin/console lint:twig templates/tenant/… 

# 2. It is really A4 and really a PDF
curl -s -b cookies.txt "$URL" -o /tmp/doc.pdf
head -c 5 /tmp/doc.pdf            # → %PDF-
grep -a MediaBox /tmp/doc.pdf      # → [0 0 595.28 841.89]  (210 x 297 mm)

# 3. The QR is present and resolves
#    open ?format=html and confirm the footer renders url + code + square

# 4. Re-print is stable
#    generate twice; the code and MediaBox must be identical
```

`MediaBox` is the ground truth. If it is not `595.28 × 841.89` the document is
not A4, whatever the CSS says.

---

## 7. Failure modes seen in this repo

| Symptom | Cause |
|---|---|
| Blank or collapsed page | Tailwind classes, flexbox or grid in the print template. |
| Blank dark panel in the editor | The embedded browser has no PDF plugin. Not a bug — use `?format=html` or a real browser tab. |
| Page is a narrow strip on a big sheet | CSS `@page` size smaller than `setPaper()`. Make them agree. |
| Caption grows a border in print | One class doing two jobs (`.note` used for both a KPI caption and a footnote box). Give each its own class. |
| Headline figure wraps mid-number | `white-space: nowrap` missing on `td.num`, or the masthead reference is not on its own line. |
| Logo missing | Remote URL, or a path outside `public/` (the chroot). Use a data URI. |
| QR missing in the preview but present in the PDF | The HTML branch skipped the renderer's context. Build the context once and use it twice. |
