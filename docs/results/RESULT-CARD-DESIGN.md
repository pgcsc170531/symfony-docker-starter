# Result presentation — design proposal

**Status:** phase 1 built (17 Oct 2026). Screen, PDF, share link and form-teacher
remarks are live; phase 2 items are marked below.
**Question:** a parent currently sees *"Mathematics — 85 — A1"*. What should they
see instead, and how does it reach their phone?

---

## 1. What we ship today (the honest baseline)

`/reports/student` walks every `ResultComponentScore` for the parent's children
and renders subject → total → grade:

```
🎓 My Results
Sadiq Auwal Nasir
First Term (2026/2027)
┌─────────────┬───────┬───────┐
│ Subject     │ Total │ Grade │
├─────────────┼───────┼───────┤
│ Mathematics │  85   │  A1   │
└─────────────┴───────┴───────┘
```

The data behind it is much richer than the screen: the schema stores
`ResultComponentScore` per **component** (`ca1`, `ca2`, `ca3`, `theory`,
`practical`), a `GradingScale` with min/max/points/remark, a three-stage
`ResultApproval` (form teacher → HOD → year-group master), `Enrollment`,
`Term.startDate/endDate`, and the guardian link.

So the problem is **presentation and computation, not data collection**. That is
good news: most of the work below is arithmetic and one very good template.

---

## 2. How the market actually does it

Researched August–October 2026. Three distinct schools of thought:

| Approach | Where | What the parent receives |
|---|---|---|
| **Nigerian terminal report** | edunile, SLAET, Edunostics, myschoolreg, most private schools | Subject grid: **CA1 · CA2 · CA3/Project · Exam · Total · Grade · Position in class · Teacher's remark**. Plus cumulative average across terms, form-teacher / head-of-academics / principal remarks with signatures, attendance summary, affective + psychomotor ratings, next term's resumption date. Printed on letterhead. |
| **Standards / competency report** | UK, US, IB, Montessori | No ranking and often no marks. Per-skill descriptors (*emerging / secure / exceeding*), narrative comments per strand, effort and behaviour separately from attainment. Deliberately avoids a single number. |
| **Parent-portal dashboard** | PowerSchool, FACTS, Classter, legacy portals | Live percentages, per-assignment detail, trend graphs, notifications. The *document* is a secondary export. |

### What that tells us

1. **The Nigerian secondary layout is not negotiable** for our primary market.
   A parent, a proprietor, and a WAEC inspector all recognise one shape: CA
   columns, exam, total, grade, position, three remarks, three signatures. Our
   competitors all converge on it because schools demand it.
2. **The differentiating details are the ones nobody prints well:** class
   average per subject, highest in class, term-over-term movement, the
   CA-vs-exam split shown as a bar rather than a number. These are what make a
   result *mean* something — "85 in Mathematics" is a number, "85 against a
   class average of 52" is a story.
3. **Ranking is contested.** Many international schools refuse to publish class
   position, because it demotivates the bottom half and turns parents into
   league-table readers. Nigerian schools publish it. → make it a **school
   setting**, defaulted on, with a "hide from the child, show to the parent"
   middle option.
4. **Delivery is WhatsApp, not email.** Every Nigerian vendor leads with
   "report card as a PDF on WhatsApp". Email is the backup channel.

---

## 3. Principles for our card

1. **The school's document, not ours.** Its letterhead, its colour, its crest,
   its voice. Edus appears once, small, in the footer.
2. **One page, answerable in ten seconds.** Name, class, term, percentage,
   position — then the detail. A parent scanning on a bus should get the verdict
   before scrolling.
3. **Every number needs a reference point.** A mark alone is meaningless; a mark
   beside the class average is information.
4. **Nothing on it that the school cannot defend.** Every figure must be
   traceable to scores that passed the approval chain, and the document must say
   so.
5. **The PDF is the artefact; the screen is the explanation.** The PDF gets
   forwarded, printed, and shown to relatives. It must stand alone.

---

## 4. The proposal — the report sheet

### Page 1

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ▌ [CREST]   GOBARAU ACADAMY                          TERMINAL REPORT     │
│ ▌          Knowledge · Character · Service              SHEET            │
│ ▌          12 Zoo Road, Kano · 0803 123 4567                            │
├──────────────────────────────────────────────────────────────────────────┤
│ ┌──────┐  NAME      Sadiq Auwal Nasir          SESSION  2026/2027        │
│ │      │  ADM. NO   ADM/2026/0001              TERM     First Term       │
│ │ PHOTO│  CLASS     JSS 1A (Sciences)          TERM ENDS 12 Dec 2026      │
│ │      │  NO. IN   41 pupils                   NEXT TERM 12 Jan 2027      │
│ └──────┘  CLASS                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│ SUBJECT        CA1  CA2  CA3  EXAM  TOTAL  GRADE  CLASS  HIGH  POS  REMARK│
│ Mathematics     12   15   13    45    85     A1     52    91   3/41 Exc. │
│ English Language …                                                        │
│ Basic Science    …                                                        │
├──────────────────────────────────────────────────────────────────────────┤
│ TOTAL OBTAINED  742/900      AVERAGE  82.4%      GRADE  A1                │
│ POSITION IN CLASS  3rd of 41        CLASS AVERAGE 64.1%                   │
│ HIGHEST IN CLASS   91.2%            LOWEST 38.0%                         │
├──────────────────────────────────────────────────────────────────────────┤
│ ATTENDANCE   School opened 62 · Present 59 · Absent 3 · 95.2%            │
├──────────────────────────────────────────────────────────────────────────┤
│ AFFECTIVE DOMAIN     5 4 3 2 1 │ PSYCHOMOTOR        5 4 3 2 1           │
│ Punctuality          ●            Handwriting        ●                   │
│ Neatness             ●            Verbal fluency     ●                   │
│ Honesty              ●            Games & sports     ●                   │
│ Politeness           ●            Handling tools     ●                   │
│ Attentiveness        ●            Drawing & craft    ●                   │
├──────────────────────────────────────────────────────────────────────────┤
│ FORM TEACHER'S REMARK                                                    │
│ Sadiq has had an excellent term…            ______________  12 Dec 2026 │
│                                             Adamu Bello, Form Teacher    │
│ HEAD OF ACADEMICS                                                        │
│ …                                           ______________  12 Dec 2026 │
│ PRINCIPAL                                                                │
│ …                                           ______________  12 Dec 2026 │
│                                                    [ school stamp ]     │
├──────────────────────────────────────────────────────────────────────────┤
│ GRADING  A1 75-100 · B2 70-74 · B3 65-69 · C4 60-64 · C5 55-59           │
│          C6 50-54 · D7 45-49 · E8 40-44 · F9 0-39                        │
│ Verify: edus.ng/v/7KQ2-9XMT   ·   Generated by Edus   ·   Page 1 of 2    │
└──────────────────────────────────────────────────────────────────────────┘
```

Decisions baked into that layout:

- **CA columns are dynamic**, not fixed at three. `AssessmentScheme.ca_count`
  already exists, so a school on 15/15/70 prints two CA columns and a school on
  10/10/10/70 prints three. The card must never show a column the school does
  not use.
- **`CLASS` and `HIGH` columns** are the single biggest readability win. They
  cost nothing but arithmetic and turn a grid of marks into a comparison.
- **Position is per subject *and* overall.** Subject position ("3rd of 41 in
  Mathematics") is what a parent actually argues about.
- **Remarks are short** (`Excellent`, `Very good`, `Fair`, `Needs work`) pulled
  from the `GradingScale.remark` that already exists, not paragraphs.
- **Signatures are names + dates, not images**, in phase 1. A scanned signature
  is a phase-2 nicety; a printed name and date is what makes it defensible.

### Page 2 — the part paper cannot do

```
┌──────────────────────────────────────────────────────────────────────────┐
│ PERFORMANCE ACROSS THE SESSION                          Sadiq · JSS 1A    │
│                                                                          │
│ Mathematics    First 78 ─ Second 81 ─ Third 85          ▁▂▃▅▆▇  ▲ +7    │
│ English        First 64 ─ Second 61 ─ Third 68          ▇▆▇    ▲ +4    │
│ Basic Science  First 70 ─ Second 66 ─ Third 63          ▆▅▄    ▼ -7    │
│                                                                          │
│ SESSION AVERAGE 76.1%   ·   BEST SUBJECT Mathematics   ·                  │
│ MOST IMPROVED English Language (+4)   ·   NEEDS ATTENTION Basic Science   │
├──────────────────────────────────────────────────────────────────────────┤
│ SUBJECT-LEVEL COMPARISON (this term)     you ▓▓▓▓▓▓▓░░░ class ▓▓▓▓▓░░░   │
└──────────────────────────────────────────────────────────────────────────┘
```

This is the page that sells the platform. A printed card shows one term; a
digital one can show whether the child is *getting better*, which is the
question parents actually ask.

---

## 5. The screen (parent portal)

Not a PDF viewer. A purpose-built page:

1. **Hero:** child photo, name, class, term, and two large stats —
   `82.4%` average and `3rd of 41`.
2. **Subject rows**, each expandable: the CA/Exam split as a stacked bar, the
   mark against the class average, subject position.
3. **Trend strip:** one row per subject across the three terms.
4. **Remarks** as cards, attributed and dated.
5. **Sticky action bar** (mobile-first, thumb-height): `Download PDF` ·
   `Share` · `Email to me`.

Mobile rules apply unchanged: single column, no horizontal scroll, 44px targets,
16px inputs.

---

## 6. Sharing — and the honest mechanics

You asked for WhatsApp sharing. It is worth being precise about what a browser
can and cannot do, because several products oversell this:

| Mechanism | Works? | Notes |
|---|---|---|
| Attach a PDF directly to a WhatsApp message | ❌ | WhatsApp Web/desktop has no API for it. No browser can do this. |
| `navigator.share({ files: [pdf] })` | ✅ Android Chrome, iOS Safari | Opens the **native share sheet** with the file attached; WhatsApp appears in the list. This is the closest to "share the PDF". Not available on desktop. |
| `wa.me/?text=…&url=…` | ✅ everywhere | Shares a **link** + a pre-written message. The recipient taps the link and gets the PDF. |
| Email the PDF to the parent | ✅ | Already have the mailer; good for the record. |

**Recommendation — do all three, degrade gracefully:**

1. **A signed, expiring link is the primary artefact.** `Share` produces
   `https://<school>/r/<token>`, valid 30 days, revocable, viewable without
   login (this is the point — a grandmother should be able to open it). The
   link page shows the report and a `Download PDF` button.
2. **On mobile, offer "Share as file"** via the Web Share API — real PDF
   attachment into WhatsApp.
3. **On desktop, fall back to `wa.me`** with a templated message: *"Dear Mr
   Nasir, Sadiq's First Term report is ready. Position: 3rd of 41. Average:
   82.4%. [link]"*.
4. **Keep "Download PDF" always visible** — some parents will forward the file
   through their own channel.

Two things to get right or the feature becomes a privacy incident:

- **The token must be unguessable and expiring**, not `?student=361`.
- **When a school enables the fee gate**, sharing must be blocked server-side,
  not hidden in the UI.

---

## 7. What it costs — data we have vs data we must build

| Element | Status | Work |
|---|---|---|
| CA1/CA2/CA3/Exam breakdown | ✅ exists (`ResultComponentScore.component`) | none |
| Grade + remark per score band | ✅ exists (`GradingScale`) | none |
| Dynamic CA column count | ✅ exists (`AssessmentScheme`) | none |
| School name, logo, address, phone, motto, colour | ✅ exists (`School`) | none |
| Pupil photo, admission no, class | ✅ exists (`Student`) | none |
| Term dates / resumption | ✅ exists (`Term.startDate`) | look up **next** term |
| Approval state | ✅ exists (`ResultApproval`) | **must gate on `yearGroupStatus`** |
| Percentage, class average, highest/lowest | 🔶 computable | aggregation queries + tie rules |
| **Position in class / per subject** | 🔶 computable | competition ranking (`1,2,2,4`), never `1,2,2,3` |
| Session cumulative across terms | 🔶 computable | session rollup |
| **Form teacher / HOD / principal remarks** | ✅ form teacher built | `StudentTermRemark` + `/my-class/remarks` + remark suggestions. HOD and principal remarks remain phase 2. |
| **Affective & psychomotor ratings** | ✅ built | `BehaviourTrait` + `BehaviourRating` + rating grid. See `docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md` |
| **Attendance summary** | ✅ built | `AttendanceRegister` + `AttendanceEntry` + the form master's daily register. Same document as above |
| **Principal name/title/signature** | ❌ new `School` fields | 3 fields + optional upload — **phase 2** |
| **PDF rendering** | ✅ built | `DompdfReportPdfRenderer` + `templates/tenant/reports/pdf/card.pdf.twig` — see §8 |
| **Share tokens + public view page** | ✅ built | Signed 30-day token (hash stored), public view, revoke, view count |
| **QR verification** | ❌ new | public verify route — **phase 2** |

That table is the real answer to "how big is this": **the academic grid is
cheap; the pastoral layer is the expensive half.**

---

## 8. PDF engine

No PDF library is installed today. The candidates:

| Option | Fidelity | Cost | Verdict |
|---|---|---|---|
| **Dompdf** (`dompdf/dompdf`) | Tables, floats, inline CSS. **No real flexbox/grid.** | pure PHP, ~2 MB, no new container | ✅ **recommended for v1** |
| mPDF | Better tables/typography, RTL, fonts | pure PHP, larger memory | good alternative if Arabic/Hausa typography matters |
| Gotenberg (Chromium) or Browsershot | Pixel-perfect — full CSS grid/flex/web fonts | +1 container (~400 MB) or a headless browser in the image | 🔶 best *design* fidelity; adds infrastructure |
| wkhtmltopdf | dated renderer, unmaintained | binary in image | ❌ |

**Recommendation: Dompdf, with a dedicated print template.**

The critical consequence: **the PDF must not reuse the screen template.** Our
screens are Tailwind-CDN utilities with `flex`/`grid`, which Dompdf cannot lay
out. The PDF gets its own Twig file — plain CSS, `<table>` for the subject grid,
the school's `primaryColor` as a CSS variable, and a `@page` rule for A4 margins.
That is not a compromise; report cards are tabular documents and tables are
exactly what Dompdf does well.

Escape hatch: if the design later demands true flex/grid, swap the renderer for
Gotenberg behind the same service interface and the template keeps working with
minor CSS changes. Keep the renderer behind a `ReportPdfRenderer` interface from
day one so that swap is a one-class change.

Also required for correct PDFs: **fonts must be bundled, not CDN**. A PDF
generated on a server with no internet must still render. Ship one sans family
(Inter or the system stack rendered via Dompdf's bundled DejaVu) plus a serif
for the school name.

---

## 9. Release gating, privacy and the verification code

This is where a report module usually goes wrong.

1. **Only approved results may be rendered.** Gate on
   `ResultApproval.yearGroupStatus === 'approved'`. ⚠️ Today
   `ResultComponentScore.status` stays `submitted` even after final approval
   (verified on a live run) — so gating on the *score* status would either leak
   unpublished results or hide published ones. Gate on the approval row.
2. **A parent may only see their own children.** Enforced by guardian link, not
   by a query-string id.
3. **A shared token is a capability, not a session.** Store
   `token_hash`, `student`, `term`, `created_by`, `expires_at`, `revoked_at`,
   `view_count`. Never store the token in clear.
4. **Verification code.** Each PDF carries a short code and a QR pointing at a
   public `/verify/<code>` page that says *"This is a genuine GAK First Term
   2026/2027 report for ADM/2026/0001"* — and nothing more. This is what makes a
   printed PDF trustworthy to a third party (a prospective school, an embassy).
5. **Fee gating (optional, per school).** Withheld reports must return a clear
   *"Report available when outstanding fees are cleared"* page, not a 403.
6. **Audit.** Record who generated, who shared, and who viewed. A bursar will
   ask "did the parent receive it?" and the answer must be a timestamp.

---

## 10. Phases

**Phase 1 — the card that makes a parent say "wow"**
Identity strip · dynamic CA grid · totals, percentage, grade · class average,
highest, subject position, class position · grading key · form-teacher remark ·
school letterhead · **PDF download** · signed share link + WhatsApp message ·
screen redesign in the parent portal · **attendance register and its card block** ·
**affective + psychomotor rating grid and their card blocks**.

Attendance and behaviour are in phase 1 because both are the form master's own
duties and both appear on the card — a card missing them is not a card a school
can hand to a parent. Their full specification, including how a teacher uses
them, is `docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md`.

**Phase 2 — the remaining pastoral layer**
HOD and principal remarks with signatures · principal name/title on `School` ·
QR verification · fee gate · email delivery with the PDF attached.

**Phase 3 — the long game**
Page-2 trend analysis across terms · session cumulative + transcript ·
batch class print for PTA day · permanent archive.

The cohort/agency-facing version of that trend work is specified separately in
`docs/results/PERFORMANCE-ANALYTICS-DESIGN.md` (proposal, not yet built).

### Build status (17 Oct 2026)

| Piece | Status |
|---|---|
| `ResultSheetBuilder` — one computed structure for screen and PDF | ✅ built |
| Screen card (`templates/tenant/reports/card.html.twig`) | ✅ built, verified at 360px and 1280px |
| Letterhead, identity strip, headline stats | ✅ |
| Dynamic CA grid, class average, highest, subject position, remark | ✅ |
| Attendance + behaviour blocks | ✅ |
| Grading key + "term in progress" warning | ✅ |
| **PDF download (Dompdf + print template)** | ✅ built — `ReportPdfRendererInterface` / `DompdfReportPdfRenderer`, `templates/tenant/reports/pdf/card.pdf.twig`, `/reports/student/pdf` |
| **Signed share link + WhatsApp** | ✅ built — `ReportShareToken` + `ReportShareService`, public `/r/{token}` and `/r/{token}/pdf`, revoke from the card, `wa.me` + native share |
| **Form-teacher remark block** | ✅ built — `StudentTermRemark`, `/my-class/remarks` for the form master, printed with the teacher's name and term end date |

**How the three remaining pieces work (17 Oct 2026):**

- **PDF.** `/reports/student/pdf?term=<id>&student=<id>` renders the *same*
  `ResultSheetBuilder` structure as the screen, so the download can never disagree
  with the page. It is a dedicated plain-CSS/table template — no Tailwind, no flex,
  bundled DejaVu fonts, embedded logo — and fits on one A4 page. The renderer sits
  behind `ReportPdfRendererInterface` so a Gotenberg swap is one class.
- **Share.** The parent presses *Share as link*. A 32-byte random token is returned
  once and only its SHA-256 hash is stored. The link is public, expires after 30
  days, records views, and can be revoked from the card. Creation is refused unless
  `TermRelease` says the term is final-approved. WhatsApp gets the signed link (and
  the mobile share sheet gets the link as a file/share target); a browser cannot
  attach the PDF directly to WhatsApp, so the link is the reliable route.
- **Remark.** The form master writes one paragraph per pupil in **My Class →
  Remarks**, starting from a grade-aware suggestion and editing it. It locks when
  the term is released. The card prints the remark, the form teacher's name/title
  and the term end date.

### Phase 2 and Phase 3 build status (17 Oct 2026)

| Piece | Status |
|---|---|
| Form teacher, HOD and principal remarks | ✅ online card shows all three; `StudentTermRemark` + `/reports/remarks` for HOD/principal |
| Principal name/title/signature on `School` | ✅ settings fields + signature upload; shown on the online card |
| QR verification + short code | ✅ every PDF carries a code and QR; public `/verify/{code}` shows identity only, no marks |
| Fee gate | ✅ per-school switch; screen, PDF and share link return a clear withheld page, not a 403 |
| Email report with PDF attached | ✅ parent-initiated `Email to me`; depends on the school's mailer configuration |
| Session trend + cumulative | ✅ `ResultTrendBuilder`; online card section + PDF page 2 when there is history |
| Transcript | ✅ `/reports/student/transcript` + PDF |
| Batch class print | ✅ `/reports/class/{id}/print`, one pupil per A4 page |
| Permanent archive | ✅ `ReportArchive` records SHA-256, generator and timestamp; admin archive screen |

**Deliberate layout decision (17 Oct 2026):** the printed PDF carries **one remark
only** — the form teacher's — so the legal document stays a single A4 page. HOD and
principal remarks remain an online-view extension. The behaviour block is printed
in two columns; the domain headings are omitted from the PDF to keep it on one page,
while the online card keeps the headings.

**Column and type decision (17 Oct 2026):** subject columns run
**Total · High · Average · Position · Grade · Remark** on both the screen card and
the PDF, so **Grade is the last scored column after Position**. The PDF type is
larger than the first draft for parents reading on paper — 9.8pt table cells, 12pt
headline figures and 7.2/9.8pt identity labels — and the page is still **one A4
portrait page**. Do not raise these sizes without re-checking that the footer has
not been pushed onto a second (blank) page.

**Remark length (17 Oct 2026):** the form teacher's remark is capped at
**180 characters** (`StudentTermRemark::TEXT_MAX`) so it prints in at most two
lines of 9pt text. Both remark entry screens show the limit, both save endpoints
reject longer text, and the PDF truncates any older over-long remark as a last
resort. Raising the cap needs a card re-render: a third line pushes the footer onto
page two.

**Two data lessons from the first live render:**

1. **The percentage denominator must only include subjects the pupil sat.** The first
   version summed a pupil's totals but divided by *every* subject's maximum, which
   produced a class average of 0.2% and put a pupil who had sat one of ten subjects
   top of the class. Fixed in `ResultSheetBuilder`.
2. **The card must say when the term is incomplete.** It now carries
   *"Term in progress — figures are based on 1 of 10 subjects"*, so a mid-term figure
   is never read as a final one.

**Open defect found while building it:** `result_entry`'s *Save All Scores* persists a
`0` for **every** pupil in the class, not just the ones the teacher touched. Those
zeros are then treated as real marks, which is why a class average can read 1.7%.
Until that changes, an incomplete term produces a defensible but ugly class average.
Fix direction: mark rows as touched and only persist those.

---

## 11. Decisions

**Settled 17 Oct 2026.** Every recommendation below was accepted, with one
correction: **attendance and the affective/psychomotor domain move into phase 1**
(decisions 3 and 4 were overruled — they are the form master's duties and belong on
the card a school actually hands over).

| # | Decision | Settled as |
|---|---|---|
| 1 | **Single-page or two-page card?** | Two pages. Page 1 is the legal document; page 2 is the differentiator. Ship page 1 in phase 1. |
| 2 | **Publish class position by default?** | Yes, with a school toggle and a "parents only, not the child" option. |
| 3 | **Attendance in phase 1?** | ✅ **Yes — phase 1.** Requires building the register; specification in `docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md`. |
| 4 | **Affective/psychomotor domain in phase 1?** | ✅ **Yes — phase 1.** Requires a new entity set and a rating grid teachers will actually use; same specification document. |
| 5 | **PDF engine** | Dompdf + a dedicated print template. Upgrade to Gotenberg only if fidelity is unacceptable. |
| 6 | **WhatsApp mechanism** | Signed link is primary; native file-share on mobile; `wa.me` text on desktop. Do not promise "PDF attached to WhatsApp" from a browser. |
| 7 | **School colour on the document** | Yes — use `School.primaryColor` for the header band. Accent/ink stay for the *screen*; the document belongs to the school. |
| 8 | **Edus credit on the PDF** | One small footer line: `Generated by Edus`. Consistent with the "school in the header, brand in the footer" rule. |
| 9 | **Remarks: typed by teachers or generated?** | Generated suggestions keyed to the grade band, editable by the form teacher. Typing 41 paragraphs is why teachers abandon these systems. |
| 10 | **Primary school variant?** | Same engine, different body: subjects become *Literacy / Numeracy / …*, ratings become descriptors instead of marks. Design for it now, build in phase 3. |

Remaining gates before code: the seven open questions in
`docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md` §9.

---

## Appendix — sources consulted

- edunile.ng — *Digital report cards* (Nigerian PDF layout, three-stage approval,
  WhatsApp delivery, fee gate)
- getslaet.com — *CA1, CA2, exam: building a grading system that matches
  Nigerian education* (CA weightings, grade bands, per-term rollup)
- openeducat.org — Nigerian gradebook / continuous-assessment composition
- myschoolreg.com — terminal positions, cumulative GPA, WAEC vs custom scales
- edunostics.com — configurable card fields (photo, CA, grade, position,
  traits, signatures)
- ericae.net — *How to design report cards that inform* (clarity principles)
- skoolping.com — digital report card practice for schools
