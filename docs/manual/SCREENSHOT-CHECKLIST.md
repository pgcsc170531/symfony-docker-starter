# Manual screenshot checklist (v2.0)

The manual at `/manual` is written but its pictures are still 1.0. This is the
shooting list, in chapter order, so the capture pass can be done in one sitting.

**Why this file exists:** screenshots cannot be captured by the coding agent —
the integrated browser runs in an isolated sandbox and `page.screenshot()` has
nowhere to write inside the workspace, so the images have to come from a normal
browser on a machine that can save files into `public/images/manual/`.

## Before you start

- Log in as the **demo tenant** (`demo.localhost:8082`, `demo@gmail.com` / `demo123`)
  for staff shots, and as `7058813998@edus.ng` / `12345678` for parent shots.
- Two viewports only: **360px** wide for anything a parent or teacher uses on a
  phone, **1024px** for anything that is a desk task (reports, settings, imports).
- The dev toolbar (`_profiler`) must not appear: append `?_profiler=0` or shoot on
  a production-mode instance.
- No real pupil data. The demo tenant's names are fictional and may be shown.
- Save as **PNG**, width ≤ 1400px, and name it `NN-slug.png` (chapter prefix).
  Do not reuse the old `screencapture-*.png` names — replacing those files under a
  cached filename is how a manual ends up showing the wrong screen forever.

## The list

| # | Shot | URL | Viewport | Goes in |
|---|---|---|---|---|
| 01 | Login page | `/login` | 360 | §1.1 |
| 02 | Wizard step 1 — identity | `/onboarding/identity` (fresh school) | 1024 | §1.2 |
| 03 | Wizard step 5 — fee prices | `/onboarding/fees/prices` | 1024 | §1.2 |
| 04 | Setup complete / fee schedule | `/finance/fees/schedule` | 1024 | §1.3 |
| 05 | Sidebar groups | `/dashboard` (drawer open) | 360 | §1.4 |
| 06 | Quick enrolment + template | `/quick-enroll/` | 1024 | §2.1 |
| 07 | Bulk import result report | `/quick-enroll/` after an upload | 1024 | §2.2 |
| 08 | New admission — parent search | `/admission/` | 1024 | §3.1 |
| 09 | Admission success + register sibling | `/admission/register` (success) | 1024 | §3.2 |
| 10 | Student profile, billing not generated | `/students/361/profile` | 1024 | §3.3 |
| 11 | Payment terminal, three options | `/finance/payment/terminal/361` | 1024 | §3.4 |
| 12 | Parent dashboard (phone) | `/parent/dashboard` | 360 | §3.6.1 |
| 13 | Parent fees + **Download statement** button | `/parent/student/1/finance` | 360 | §3.6.2 and §13.3 |
| 14 | Downloaded statement PDF, first page | `…/statement.pdf?download=1` opened in a viewer | — | §12.4 |
| 15 | Store products + bulk CSV guide | `/store/products/` | 1024 | §4.1 |
| 16 | POS cart for a pupil | `/store/sales/cart/361` | 1024 | §4.2 |
| 17 | Walk-in receipt | `/finance/payment/receipt/13` | 1024 | §4.2 |
| 18 | Payment terminal search | `/finance/payment/` | 1024 | §5.1 |
| 19 | Verify a bank transfer | `/finance/payment/verify` | 1024 | §5.2 |
| 20 | Student directory, filters + actions | `/students/` | 1024 | §6.1 |
| 21 | Student profile | `/students/361/profile` | 1024 | §6.2 |
| 22 | Admission letter print view | `/admission/student/361/print-letter` | 1024 | §6.3 |
| 23 | Promotion module, roster loaded | `/enrollment/promote` | 1024 | §6.5 |
| 24 | Expense manager + categories | `/finance/expenses/` | 1024 | §7.1–7.2 |
| 25 | Profit & loss dashboard | `/finance/report/profit-loss` | 1024 | §7.3 |
| 26 | Subscription — how you pay | `/subscription/` | 1024 | §8.1 |
| 27 | Wallet + top-up | `/wallet/` | 1024 | §8.2 |
| 28 | Settings — identity + banking | `/settings/general` | 1024 | §8.3 |
| 29 | Staff assignments, form teacher | `/staff-assignments/form-teacher` | 1024 | §9.9 |
| 30 | Result entry with scores | `/tenant/results/entry/{id}` | 1024 | §10.2 |
| 31 | Approvals hub, items waiting | `/tenant/approvals` | 1024 | §10.3 |
| 32 | Report card preview | `/reports/student` | 1024 | §10.6 |
| 33 | Attendance register (phone) | `/my-class/attendance/{date}` | 360 | §11.1 |
| 34 | Fee reports overview | `/finance/reports` | 1024 | §12.2 |
| 35 | Debtors by guardian, expanded | `/finance/reports/debtors` | 1024 | §12.3 |
| 36 | Term summary PDF, first page | `/finance/reports/summary.pdf` | — | §12.5 |
| 37 | Verification page | `/verify/fee-report/{code}` | 360 | §14 |

## Replacing the 1.0 images on the page

Every `<img src="{{ asset('images/manual/…') }}">` in
`templates/landlord/home/manual.html.twig` is a 1.0 capture. When a shot is
retaken, change the `src` to the new `NN-slug.png` **and** the `alt` text, then
delete the old file — leaving both in `public/images/manual/` guarantees a future
editor picks the wrong one.

## Definition of done for the pass

- Every `<img>` in the page resolves (no 404 in the network tab).
- No screenshot shows the debug toolbar, a real pupil, or a browser chrome.
- Phone shots are 360px wide and show a full-width primary action.
- Each image sits directly under the step it illustrates, not at the end of the
  chapter.
