# The agent playwright standard

How the agent runs a role walkthrough, what counts as evidence, and when it must stop
and write something down instead of changing code.

---

## 1. Preconditions

| Item | Value |
|---|---|
| App | `docker compose up -d` — php on **:8082**, Mailpit on **:8025** |
| Marketing / landlord | `http://localhost:8082` |
| A school | `http://<subdomain>.localhost:8082` — `demo` is the main demo tenant |
| Browser | The VS Code integrated browser (Playwright). Its viewport is scaled **1.25×**: request `width / 1.25` for a true CSS width |
| Viewports | **288** = a 360px phone (the target) · **1024** = a laptop. A run is not finished until both have been seen |

### Accounts

| Role | Sign in | Where |
|---|---|---|
| Landlord | see `docs/playbooks/01-landlord.md` | `/admin/login` on the **base domain** |
| Tenant admin | `demo@gmail.com` / `demo123` | `/login` on the school's subdomain |
| Bursar | demo admin also holds `ROLE_BURSAR` | `/login` |
| Parent | `7058813998@edus.ng` / `12345678` | `/login` (parent portal under `/parent`) |
| Teacher / HOD / form teacher / year master | staff accounts created in `02-tenant-admin.md` | `/login` |

Create the staff accounts in the admin run and **write their credentials into the run
record** — every later role needs them.

---

## 2. What a run looks like

For each numbered step in a role file:

1. **Navigate** to the URL.
2. **Assert the status** — `200`, `302` to the expected place, or `403` *only* where the
   file says 403 is correct.
3. **Assert the content** — the heading, the row, the figure the step claims exists.
   A 200 is not a pass; a 200 with an empty table is a defect.
4. **Assert the layout at 288 CSS px** — using the four checks below.
5. **Record** — one line per step in the run record.

Run the four DOM checks on every screen you touch:

```js
const vw = window.innerWidth;
// 1. no sideways scrolling
document.documentElement.scrollWidth - vw;                        // <= 0
// 2. tap targets
[...document.querySelectorAll('main a, main button')]
  .filter(e => { const r = e.getBoundingClientRect(); return r.height > 0 && r.height < 44; });
// 3. inputs small enough to trigger iOS zoom
[...document.querySelectorAll('main input, main select, main textarea')]
  .filter(e => parseFloat(getComputedStyle(e).fontSize) < 16);
// 4. text that never shrank for the phone
[...document.querySelectorAll('main *')]
  .filter(e => !e.children.length && e.textContent.trim())
  .map(e => ({ t: e.textContent.trim().slice(0, 24), fs: parseFloat(getComputedStyle(e).fontSize) }))
  .filter(x => x.fs >= 26);                                        // empty, or emoji only
```

Anything non-empty from checks 1–4 is a defect. Record it; do not restyle the page.

---

## 3. The stopping rule

> **Stuck = stop, record, FIX, then restart the run from that step.**

A walkthrough is a test of the product, not a test of the tester's patience. When a step
does not do what the file says it does:

1. **Stop** the run at that step. Do not try three more ways to get past it.
2. **Record** it in `DEFECT-LOG.md` — severity, URL, **did / saw / expected**, evidence.
3. **Fix it.** This is the point of the exercise: we are testing so that the defect gets
   fixed, not so that it gets a nice paragraph. Fix the cause, not the symptom. If the
   fix needs a schema change, run `app:tenant:update-schema`.
4. **Restart fresh from the blocked step** — re-authenticate, delete a half-created
   record if the failure left one, and re-run that step and the ones after it. A run that
   was worked around proves nothing about what a school will actually experience.
5. **Close the defect** with the re-run evidence (the same step, now passing), and only
   then continue to the next role.

### What still waits for later

Ideas are not defects. "This button should be on the left", "this table should be cards" —
those go to **Post-playwright backlog** and are not implemented during a run. The
boundary is simple:

| Situation | Action |
|---|---|
| The step cannot be completed, or completes wrongly | **Stop, fix, re-run** |
| The step completes correctly but feels wrong | Record in the backlog, continue |
| A fix would need a product decision (naming, layout, new feature) | Backlog, continue |
| A fix would quietly change someone else's data | Backlog, continue — restore the data first |

### Slow steps are not stuck steps

Provisioning a new school builds ~50 tables and takes **10–20 seconds**. A playwright
step that clicks *Create* must wait for the flash to arrive on the destination page
before the next action, or a second action can run against a half-built tenant. Waiting
is part of the step; guessing is not.

---

## 4. Run record (write one per role per run)

Append to the bottom of the role file, or keep it in the PR description:

```md
### Run 2026-10-19 — demo tenant — agent

Environment: demo.localhost:8082 · admin demo@gmail.com · viewports 288 / 1024
Steps passed: 14 / 17
Blocked at: step 11 (paid figure not reflected on the dashboard)

| Step | Result | Note |
|---|---|---|
| 1 | pass | /dashboard 200, school name in header |
| 2 | pass | … |
| 11 | **FAIL** | P-BURSAR-02 — balance stayed ₦67,000 after confirmation |
| 12–17 | not run | blocked by step 11 |

Defects raised: P-BURSAR-02 (major)
Backlog added: "confirm button should be sticky on mobile"
```

---

## 5. Evidence rules

- **Screenshot** at each milestone, at 288 CSS px, unless the step is a desktop-only
  task — then at 1024. Name them `<role>-<step>-<viewport>.png`.
- **Never screenshot a real school's data.** Only demo tenants.
- A passing step needs *one* piece of evidence: the assertion output or the screenshot.
  A failing step needs both, plus the response status.
- The dev toolbar (`_profiler`) must not appear in a screenshot — append `?_profiler=0`
  or run with `APP_DEBUG=0` when capturing for a handout.

---

## 6. Producing the handout (Part B)

Part B is written **from the run record**, not from the code. If a step had to be
retried with a different URL, the handout says the URL that actually worked — the
whole point is that a human can follow it without help.

Structure and tone: `USER-GUIDE-TEMPLATE.md`. Rules that matter:

- Start each section with **where you are** ("Sign in at your school's address, then
  open **Finance → Reports**").
- One action per numbered line. No "simply", no "just".
- Every claim about money or permissions is stated plainly: *"Nothing is sent to a
  parent automatically."*
- No screenshots required — the agent may not be able to capture them, and a numbered
  path is more durable than a picture of a UI that moves.
- End with **what to check when it goes wrong**, in the user's words, not the system's.

---

## 7. Definition of done for a role

- [ ] Every step has a pass/fail line in the run record
- [ ] Every screen touched passed the four DOM checks at 288 and was seen at 1024
- [ ] Defects filed for everything that failed; nothing fixed in the same run
- [ ] Backlog updated with every suggestion raised
- [ ] Part B handout written or updated, and readable by someone who has never seen Edus
- [ ] The next role in the chain is unblocked — its preconditions exist in the data
