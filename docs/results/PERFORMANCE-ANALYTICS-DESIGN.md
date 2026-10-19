# School performance reporting — design proposal

**Status:** proposal for review. Nothing built yet.
**Date:** 17 October 2026.
**Audience:** proprietor / board, ministry or inspectorate, accreditation and
scholarship agencies, and the school's own leadership.
**Related:**
`docs/results/RESULT-CARD-DESIGN.md` (individual report card),
`docs/exam-collection/SKILL.md` §12 (six reporting levels),
`ATTENDANCE-AND-BEHAVIOUR-DESIGN.md` (attendance and behaviour data),
`docs/architecture/UI-SPEC.md` and `MOBILE-FIRST.md` (presentation rules).

---

## 1. The question this has to answer

An agency does not ask *"what did Sadiq score in Mathematics?"* It asks:

1. **Is this school performing?** What is the overall level, and how many pupils
   are actually reaching a defensible standard?
2. **Where is it strong and where is it weak?** Which subjects, classes and
   year groups pull the average up or down?
3. **Is it getting better?** Is the direction of travel positive, flat or
   falling — term on term and session on session?
4. **Can the figures be trusted?** What share of expected results is included,
   and are they final-approved?

The parent report card answers a different question and is already built. This
document is about the **aggregate, storytelling layer** that sits above it.

---

## 2. What is already decided (and where)

The *intention* is recorded. The *specification* is not.

| Source | What was decided | Status |
|---|---|---|
| `docs/exam-collection/SKILL.md` §12 | Six reporting levels: teacher, form teacher, HOD, year-group master, **admin school-wide analytics and grade distribution**, parent. | Admin level exists only as a flat table. |
| `FEATURES.md` §4 | "Admin dashboard — school-wide grade distribution (A1–F9) and averages." | Built, minimal. |
| `RESULT-CARD-DESIGN.md` §5 and page 2 | A parent-facing trend strip across the three terms. | ❌ Not built (phase 3). |
| `RESULT-CARD-DESIGN.md` §10 phase 3 | Session cumulative + transcript, batch class print, permanent archive. | ❌ Not built. |
| `RESULT-CARD-DESIGN.md` §3 principle 3 | "Every number needs a reference point." | Applied to the card, not to school analytics. |
| `RESULT-CARD-DESIGN.md` §9 | Only final-approved results may be rendered; audit who generated/shared/viewed. | Applied to the card and share links. |
| `ATTENDANCE-AND-BEHAVIOUR-DESIGN.md` §10 item 10 | Admin whole-school completion view — "the proprietor's oversight screen." | ⬜ Not built. |
| `MOBILE-FIRST.md`, `UI-SPEC.md` | Mobile-first, one accent, ink ramp, colour only for status. | Must apply to every new screen. |

**Bottom line:** nobody has yet decided what an agency brief contains, how its
metrics are defined, how small cohorts are protected, or how it is exported.
That is the gap this document fills.

---

## 3. What we ship today (honest baseline)

### 3.1 Admin dashboard (`/reports/admin`)

One table: class · subject · pupil count · average · A1 · B2 · … · F9.

Problems:

- **No headline.** A reader has to scan dozens of rows to form an opinion.
- **No denominator.** "Average 62" is bare. 62 out of what, compared to what?
- **No pass rate, no distinction rate, no credit count.** The WAEC-style
  question — *how many pupils passed five subjects including English and
  Mathematics?* — cannot be answered at all.
- **Grade columns are hard-coded** to A1–F9, even though `GradingScale` is
  configurable. A school on a different scale gets nine meaningless columns.
- **No term choice.** It uses the active term for the chosen session.
- **No coverage or approval state.** A mid-term table looks exactly like a
  final one.
- **No trend.** There is no previous-term or previous-session comparison.
- **Not mobile-safe.** The grade table is not wrapped and the palette is
  `gray-*`/`blue-*`, not the design tokens.

### 3.2 Other report screens

| Screen | What it answers | Limitation |
|---|---|---|
| Teacher report | One class + subject grid | Operational, not analytical |
| Form teacher broadsheet | Whole class across subjects | Per class only |
| HOD subject master | One subject across arms | Per department |
| Year-group board | Approval status and averages | Status, not performance |
| Parent card / PDF | One pupil, one term | Individual |
| Attendance / behaviour | Per pupil and per class | Not joined to attainment |

### 3.3 Data we already hold

- `ResultComponentScore` — every CA/exam component per pupil, subject, term.
- `GradingScale` — grade, min/max, **points**, remark.
- `AssessmentScheme` — CA count, exam mode, maxima.
- `ResultApproval` — three-stage sign-off; `yearGroupStatus` is the final gate.
- `Enrollment` / `Student` / `Classroom` / `YearGroup` / `Department` / `Subject`.
- `AttendanceRegister` / `AttendanceEntry`.
- `BehaviourRating` / `BehaviourTrait`.
- `Session` / `Term` — including `startDate` / `endDate`.

**Nothing needs to be collected.** The missing layer is computation and
presentation.

---

## 4. The gap, in one table

| Capability | Today | Needed for an agency brief |
|---|---|---|
| School average | Per subject only | Whole-school, per class, per year group, per subject |
| Pass / credit / distinction rates | ❌ | ✅ Defined against `GradingScale` |
| Five credits incl. English & Maths | ❌ | ✅ Configurable WAEC-style gate |
| Coverage (entered ÷ expected) | ❌ | ✅ Always shown with the figures |
| Approval confidence | ❌ | ✅ "Final" vs "Provisional" |
| Term-on-term movement | ❌ | ✅ Points and direction |
| Session-on-session movement | ❌ | ✅ With same-term comparison |
| Class gap | ❌ | ✅ Highest − lowest, named |
| Subject heatmap | ❌ | ✅ Ranked, with school reference line |
| Attendance context | Per pupil | ✅ Cohort rate beside attainment |
| Narrative | ❌ | ✅ Three findings in plain English |
| Export | ❌ | ✅ PDF brief + CSV |
| Agency share | ❌ | ✅ Aggregate-only signed link |
| Small-cohort protection | ❌ | ✅ Suppress n < 5 |
| Mobile layout | ❌ | ✅ Cards, bars, no sideways scroll |

---

## 5. Design principles

1. **Answer first, then evidence.** The top of the page states the verdict in
   one sentence and five numbers. Charts come after.
2. **Every number carries a denominator and a comparison.** `58.4%` is not a
   finding; `58.4%, +3.1 pts on last term, 8.2 pts below the year-group
   average` is.
3. **Trust is visible.** Coverage, approval state and generation date sit next
   to the headline — not buried in a footnote.
4. **The agency version contains no child.** Aggregates only. Ever.
5. **One accent, one ink ramp.** Bars use accent/ink; semantic colour is
   reserved for "improving / declining / at risk" and always carries a label.
6. **No chart library.** Bars, sparklines and stacked strips in HTML/CSS (and
   simple server-side SVG for the PDF) keep the payload small and the phone
   fast. A canvas library is not welcome on a 360px Android phone.
7. **The screen, the PDF and the shared link read the same computed
   structure** — the lesson already learned in `ResultSheetBuilder`.
8. **Provisional is never disguised as final.** An incomplete term shows a
   banner and changes the wording from "was" to "is so far".

---

## 6. The product: **School Performance Brief**

One hub, four screens, one exportable document.

### 6.1 Screen A — Executive Brief (`/reports/performance`)

The only screen most agency visitors will need.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ SCHOOL PERFORMANCE                             First Term · 2026/2027    │
│ GOBARAU ACADAMY                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│ In First Term, 338 pupils sat 9 subjects. The school averaged 57.8%,     │
│ 3.1 points above Second Term, but 6.4 points below the 2024/2025         │
│ same-term figure. 64% passed five subjects including English and          │
│ Mathematics; Basic Science is the weakest subject and the largest        │
│ decline.                                                                  │
│                                                                          │
│  ┌──────────┬──────────┬──────────┬──────────┬──────────┬──────────┐    │
│  │ AVERAGE  │ PASS RATE│ 5+ CRED. │DISTINCTION│ COVERAGE │ATTENDANCE│    │
│  │  57.8%   │   78.4%  │  64.0%   │   11.2%  │   98.6%  │  93.1%   │    │
│  │ +3.1 pts │ +2.0 pts │ +1.4 pts │ −0.3 pts │  final   │ +0.8 pts │    │
│  └──────────┴──────────┴──────────┴──────────┴──────────┴──────────┘    │
│                                                                          │
│  THREE THINGS THAT MATTER                                                │
│  ▲ Mathematics improved 5.2 points and is now the strongest subject.    │
│  ▼ Basic Science fell 6.4 points; 3 of 9 classes are below 50%.          │
│  ● JSS 1A is 12.3 points above JSS 1C — the widest class gap this term.  │
│                                                                          │
│  [ Subjects ]  [ Classes ]  [ Movement ]        [ Download brief ]      │
├──────────────────────────────────────────────────────────────────────────┤
│ Coverage: 3,042 of 3,086 expected subject entries · all results final-   │
│ approved · generated 17 Oct 2026 14:20 · Edus                            │
└──────────────────────────────────────────────────────────────────────────┘
```

Rules for the headline sentence:

- Generated by **rules**, not an AI model. Templates keyed to the numbers:
  direction, size of change, strongest/weakest subject, widest class gap.
- Maximum two sentences on a phone; the desktop version may show three.
- If coverage is below a threshold (say 90%), the sentence starts with
  **"Provisional:"** and the banner is amber.
- If there is no previous term to compare with, the comparison clause is
  omitted rather than invented.

### 6.2 Screen B — Subjects

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Subjects                                      Term ▾   Year group ▾      │
├──────────────────────────────────────────────────────────────────────────┤
│ Subject             Entered  Avg    Pass   Dist.   vs school   vs last   │
│ Mathematics           338    64.2%  86.1%  18.0%    +6.4        ▲ +5.2   │
│ English Language      338    61.0%  91.0%  12.4%    +3.2        ▲ +1.8   │
│ Basic Science         336    44.8%  52.4%   3.1%   −13.0        ▼ −6.4   │
│ …                                                                        │
│                                                                          │
│ AVERAGE BY SUBJECT                     school average 57.8% ──────────── │
│ Mathematics  ████████████████████████████████ 64.2%                      │
│ English      █████████████████████████████ 61.0%                         │
│ Basic Sci.   █████████████████████ 44.8%                                 │
│                                                                          │
│ SPOTLIGHT  Strongest: Mathematics · Weakest: Basic Science               │
│            Most improved: Mathematics (+5.2) · Largest fall: Basic Sci.  │
└──────────────────────────────────────────────────────────────────────────┘
```

- The reference line is the school average, drawn across every bar — the
  single most useful visual in the report.
- A subject with **no results** shows "not entered", not `0%`.
- `Entered` is the denominator. A subject with 12 of 338 entries is flagged
  and is not allowed to define the school's story.
- Departments are a secondary roll-up (`Department` already exists).

### 6.3 Screen C — Classes and cohorts

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Classes                                                                  │
├──────────────────────────────────────────────────────────────────────────┤
│ Class     Pupils  Average  Pass   5+ cred.  Attendance  vs school   Trend │
│ JSS 1A       41     66.1%  85.4%    70.7%      94.2%      +8.3       ▲    │
│ JSS 1B       40     58.0%  77.5%    62.5%      93.0%      +0.2       ▲    │
│ JSS 1C       41     53.8%  70.7%    56.1%      91.7%      −4.0       ▼    │
│ …                                                                        │
│                                                                          │
│ GRADE MIX (whole school)                                                 │
│ A/B  ██████ 18.4%   C  ██████████ 31.2%   D/E  ███████ 26.0%   F 24.4%  │
│                                                                          │
│ Widest gap: JSS 1A − JSS 1C = 12.3 points.                               │
│ Year-group roll-up: JSS 1 58.9% · JSS 2 61.4% · JSS 3 55.0%              │
└──────────────────────────────────────────────────────────────────────────┘
```

- Grade mix uses **four bands** (A/B, C, D/E, F), not nine colours. The bands
  are derived from the configured `GradingScale` order, not from hard-coded
  grade names.
- A class with fewer than the suppression threshold shows "suppressed", never
  a number that could identify a handful of children.
- Gender or arm comparisons are offered only when the data is complete; the
  screen states the sample size and never implies causation.

### 6.4 Screen D — Movement across the session

This is the page paper cannot do, and the one that makes the brief feel like a
story rather than a spreadsheet.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Movement                                          Session 2026/2027      │
├──────────────────────────────────────────────────────────────────────────┤
│ SCHOOL AVERAGE    First 54.7 ─ Second 56.2 ─ Third 57.8        ▲ +3.1   │
│                                                                          │
│ SUBJECT                 FIRST   SECOND   THIRD    CHANGE                 │
│ Mathematics             59.0     61.4     64.2     ▲ +5.2                │
│ English Language        59.2     60.1     61.0     ▲ +1.8                │
│ Basic Science           51.2     49.4     44.8     ▼ −6.4                │
│                                                                          │
│ MOST IMPROVED   Mathematics (+5.2) · Civic Education (+3.9)             │
│ NEEDS ATTENTION Basic Science (−6.4) · JSS 1C (−4.0)                    │
│ SESSION CONTEXT 2024/2025 same term: 64.2% → this term: 57.8% (−6.4)    │
└──────────────────────────────────────────────────────────────────────────┘
```

- Term columns only appear if the terms exist and are final-approved.
- The session-on-session comparison uses the **same term** in the previous
  session, never "last term vs this term" relabelled.
- Sparklines use a single accent stroke and a flat ink baseline.

### 6.5 The PDF and the shared link

The PDF is the artefact an agency files. It is not the screen printed badly.

- **A4, 3–4 pages:** Brief → Subjects → Classes → Method & evidence.
- Prepared for: `[Agency name]` · `[School]` · `[Session/Term]` ·
  `generated [date]`.
- Same computed structure as the screens, so the PDF cannot disagree.
- Dompdf, plain CSS, tables and bars. No Tailwind, no flex/grid, no chart
  library. The report-card print lessons apply unchanged.
- A signed link (`/p/<token>`) mirrors the brief with no login. The token is
  hashed, expiring, revocable and records views. **No individual pupil data is
  reachable from an agency token.**
- CSV export for agencies that want to do their own analysis. The CSV obeys
  the same suppression rules.

---

## 7. Metric definitions (must be agreed before code)

These definitions are the contract. If the school and the agency disagree on
what "pass" means, the brief is worse than no brief.

| Metric | Definition | Formula / rule |
|---|---|---|
| **Expected entries** | Every pupil in the class roster × every class subject in the term | Used only as the denominator for coverage |
| **Coverage** | Share of expected subject entries that have a result | `entered ÷ expected × 100`, one decimal |
| **Pupil percentage** | The same rule as the report card | Obtained marks ÷ obtainable marks for the subjects the pupil actually sat |
| **School average** | Mean of pupil percentages | Not the mean of subject averages — that lets a small subject carry equal weight |
| **Pass** | Grade at or above the configured passing grade | Default: D7 / 40%. Read from `GradingScale`, never hard-coded |
| **Credit** | Grade at or above the configured credit grade | Default: C6 / 50% |
| **Distinction** | Grade at or above the configured distinction grade | Default: B3 / 65% (review — see §11) |
| **Pass rate** | Pupils passing at least one subject? **No.** | Pupils whose **overall term percentage** is at or above the pass mark, over pupils with a result |
| **5+ credits incl. English & Maths** | WAEC-style readiness gate | Count only when English and Mathematics are both offered and sat; otherwise show "n/a" |
| **Five-credit rate** | Pupils with ≥5 credits including English and Mathematics ÷ pupils with a result | Senior classes only; hidden for primary |
| **Class average** | Mean of pupil percentages in the class | Same denominator rule as the school average |
| **Subject average** | Mean of pupil percentages in that subject | Not the mean of raw totals (different maxima) |
| **Improvement** | Current − previous, in percentage points | A positive number is better; sign is always printed |
| **Rank** | Competition ranking | `1, 2, 2, 4` — never `1, 2, 2, 3` |
| **Attendance rate** | `(present + late) ÷ registers taken` | Registers actually taken, never term length |
| **Provisional** | Any term not fully final-approved | Coverage banner + "so far" wording |
| **Suppression** | Any published breakdown with n < 5 | Show "fewer than 5 pupils — withheld" |

Two consequences worth stating explicitly:

1. **A zero is not a missing mark.** The known *Save All Scores* defect writes
   `0` for untouched pupils (`RESULT-CARD-DESIGN.md` §10). Until that is fixed,
   an analytics figure can be technically consistent and still misleading. The
   coverage and "not entered" flags exist partly to make that visible.
2. **Rounding happens once, at the end.** Store the precise means; round only
   for display, so the headline and the rows cannot disagree by 0.1.

---

## 8. Privacy rules for an agency brief

| Rule | Why |
|---|---|
| **Aggregate only** — no pupil names, admission numbers, photos or individual rows | An agency has no need for a child's identity to judge a school |
| **Suppress any cell with n < 5** | Five pupils in a class plus a named teacher is re-identifiable |
| **No cross-filter that can isolate one pupil** | For example, "JSS 3A · female · boarding · Basic Science" |
| **Signed, expiring, revocable link** | The same capability model as a report-card share link |
| **Record who generated, shared and viewed** | The bursar/proprietor will ask |
| **Do not include fee balances** | Financial status is not an academic judgement |
| **State the approval state** | A provisional figure presented as final is a misrepresentation |
| **Watermark the audience** | "Prepared for [Agency] · [date]" discourages silent re-sharing |

---

## 9. Technical shape

```
ResultComponentScore ─┐
ResultApproval ───────┤
Enrollment ───────────┼──► SchoolPerformanceBuilder ──► one array
ClassSubject ─────────┤        (single pass, one query set)        │
GradingScale ─────────┤                                           ├─► Screen A–D
AttendanceEntry ──────┘                                           ├─► PDF (Dompdf)
                                                                  ├─► CSV
                                                                  └─► Public /p/<token>
```

- **`SchoolPerformanceBuilder`** — the analytics equivalent of
  `ResultSheetBuilder`. One structure, computed once, used by every output.
- **No per-pupil `ResultSheetBuilder::build()` loop.** That would be N pupils ×
  class-wide queries. Instead, load the term's component scores in one pass,
  group by pupil and subject, and aggregate.
- **Approval gate.** Include a subject only when its `ResultApproval` row is
  `yearGroupStatus = approved`; otherwise count it as unentered and mark the
  brief provisional. This mirrors the report card.
- **Cache.** Cache the finished structure per `term + filters` and invalidate
  on approval changes. A 361-pupil school should render the hub in well under a
  second.
- **PDF** — `SchoolPerformanceBriefRenderer` behind an interface, same pattern
  as the report card renderer.
- **Routes (proposed):**
  - `GET /reports/performance` — Executive Brief
  - `GET /reports/performance/subjects`
  - `GET /reports/performance/classes`
  - `GET /reports/performance/movement`
  - `GET /reports/performance/brief.pdf`
  - `POST /reports/performance/share`
  - `GET /p/{token}` and `GET /p/{token}/pdf` — public agency link
- **Permissions:** `ROLE_ADMIN` for the full brief; HOD may be scoped to their
  department later; an agency token grants read-only aggregate access only.

---

## 10. What changes in the existing interface

The report area is the oldest part of the UI and has not had the token/mobile
pass the rest of the app has had.

| Current | Change |
|---|---|
| `/reports` hub: five cards, emoji icons, `gray-*`/`blue-*` | Token-based cards with line icons; add **School Performance** as the first admin card |
| Admin dashboard: one flat table | Becomes **Screen A**, with links to Subjects / Classes / Movement |
| Hard-coded A1–F9 columns | Render from `GradingScale` |
| No term selector | Session + term + year-group + class filters, remembered in the URL |
| No empty/provisional state | Explicit "no results yet", "provisional, 62% coverage", "not enough pupils to publish" |
| Tables not wrapped | `overflow-x-auto` + `min-w-[640px]`; on phones the data becomes cards, not a squeezed table |
| Emoji headers | Line icons from `_nav_icons.html.twig` |
| No export | Download brief (PDF) + CSV, both respecting suppression |

Mobile shape of Screen A: the six KPIs become a 2 × 3 grid of compact tiles
(not a sideways-scrolling strip); findings become three stacked cards; the
action buttons are full-width and thumb-height.

---

## 11. Open decisions (recommendations in bold)

| # | Decision | Options | Recommendation |
|---|---|---|---|
| 1 | What counts as a **pass**? | D7/40, C6/50, E8/45 | **Configurable, default D7/40**, derived from `GradingScale` |
| 2 | What counts as a **distinction**? | A1 only, A1–B2, A1–B3 | **A1–B3 / 65%**, configurable |
| 3 | Is the 5-credit rule in scope? | Always, senior-only, never | **Senior classes only**, hidden elsewhere |
| 4 | Publish class position/rank to agencies? | Yes, no, school toggle | **No individual ranks**; class averages only |
| 5 | Small-cohort suppression threshold | 3, 5, 10 | **5 pupils** |
| 6 | Include attendance in the brief? | Yes, no, separate page | **Yes, one KPI + class column** — it changes how results are read |
| 7 | Compare with the previous session? | Same term, last term, both | **Both, labelled separately** |
| 8 | Show provisional data to agencies? | Never, always with banner, on request | **Only with a prominent provisional banner**; never silently |
| 9 | Brief branding | School only, Edus only, both | **School owns the header; one small "Generated by Edus"** footer — same rule as the card |
| 10 | Share-link lifetime | 30 days, 90 days, custom | **90 days for agencies**, 30 for parents, revocable |
| 11 | CSV export | Yes, no | **Yes**, aggregate rows only |
| 12 | Teacher-level comparison in the brief? | Yes, no | **No in v1** — it turns an agency brief into a staff-performance document |

---

## 12. Phased plan

| Phase | Scope | Why first |
|---|---|---|
| **A — Trustworthy school analytics** | `SchoolPerformanceBuilder`, Screen A–C, definitions, coverage + provisional banner, mobile layout, token pass on report screens | Without correct denominators and coverage, every later chart is decoration |
| **B — Movement** | Previous term / previous session comparison, sparklines, most improved / declined, Screen D | The storytelling differentiator, and it needs historical terms to exist |
| **C — Agency brief** | PDF renderer + print template, signed aggregate-only link, CSV, "prepared for" watermark, view audit | The external-facing product |
| **D — Targets and benchmarks** | School target setting, benchmark bands, trend alerts | Needs several terms of clean history to be meaningful |

Batch class printing and the permanent transcript archive remain phase 3 of
`RESULT-CARD-DESIGN.md` and are not part of this brief.

---

## 13. Acceptance checklist (phase A–C)

- [ ] No horizontal scrolling at **320px** and **360px**; every action ≥ 44px
- [ ] Every headline number has a denominator and a comparison
- [ ] Provisional terms are labelled at the top, not in a footnote
- [ ] Coverage percentage is always visible
- [ ] Every published breakdown with n < 5 is suppressed **server-side**
- [ ] No pupil name, admission number or photo appears in an agency view
- [ ] The screen, PDF and shared link read the same computed structure
- [ ] Grade names and pass/credit/distinction marks come from `GradingScale`
- [ ] Tested with: a complete term, an incomplete term, a tie in ranking, a
      one-pupil class, zero results, and a term with no previous comparison
- [ ] Revoked link returns a friendly 410 page
- [ ] Generation time and approval state are printed on the brief

---

## 14. What this deliberately is not

- **Not AI-written.** The narrative is generated from rules and the actual
  numbers; a model is not allowed to invent a cause or an adjective.
- **Not a public school league table.** The link is a capability held by the
  school and the agency, not an open web page.
- **Not a replacement for the report card.** The card is the defensible
  document for one child; the brief is the defensible document for the cohort.
- **Not a chart gallery.** If a number is not needed to answer one of the four
  questions in §1, it does not go on the screen.
