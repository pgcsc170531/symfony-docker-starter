# Edus role playbooks — agent walkthroughs + user handouts

This folder is the operating manual **for the people who run and test Edus**, not for
schools. It exists because the product is finished enough that the next risk is not a
missing feature — it is a role nobody has actually walked end to end.

Every role has two halves, in one file:

| Half | Who reads it | What it is |
|---|---|---|
| **Part A — Agent playwright** | The AI agent | An ordered script of URLs, actions and assertions, with the expected result at each step |
| **Part B — User handout** | A real human in that role | The same journey in plain language, for someone with no agent and no training |

Part A proves the role works. Part B is what we print, email, or hand over.

---

## The files

| File | Role | Actor |
|---|---|---|
| `AGENT-PLAYWRIGHT-STANDARD.md` | — | **Read this first.** How to run a walkthrough, what to record, when to stop |
| `DEFECT-LOG.md` | — | The single running list of what broke and what we want changed |
| `01-landlord.md` | Platform owner | Creates schools, plans, invitations, invoices |
| `02-tenant-admin.md` | School administrator | Builds the school in Edus |
| `03-bursar-offline-payments.md` | Bursar / cashier | Takes money offline, verifies proofs, receipts |
| `04-subject-teacher.md` | Subject teacher | Enters and submits scores |
| `05-form-teacher.md` | Form teacher | Confirms the class, register, behaviour, remarks |
| `06-hod.md` | Head of department | Approves or rejects a subject's results |
| `07-year-group-master.md` | Year group master | Final approval and the year-group pack |
| `08-parent.md` | Parent / guardian | Applies, pays, downloads the statement, reads results |
| `09-onboarding.md` | First-hour setup | The six-step wizard, run by the admin with the agent watching |
| `DEMO-DATA-1200.md` | — | Generating and importing ~1,200 pupils, JSS 1–SS 3 |
| `USER-GUIDE-TEMPLATE.md` | — | Structure every Part B handout follows |

---

## The one rule that matters most

> **Stuck = stop, record, fix, re-run.**
>
> A walkthrough is a test, not a repair-free exercise. When a step fails, stop at that
> step, write it in `DEFECT-LOG.md` with *did / saw / expected*, **fix the cause**, then
> restart the run from that step and prove it passes. A run that was worked around proves
> nothing about what a school will actually experience.
>
> Ideas are different: "this button should be on the left" is not a failure. It goes in
> the same log under *Post-playwright backlog* and waits its turn — it does not get built
> in the middle of a test run.

---

## Order to run them

```
01 landlord → 09 onboarding → 02 admin → 03 bursar
   → 04 subject teacher → 05 form teacher → 06 HOD → 07 year group master
   → 08 parent
```

A later role cannot succeed if an earlier one left the data wrong — a teacher cannot
enter a score for a class subject that the admin never created. Run them in order, and
if a run is blocked by the previous one, record that as a defect against the earlier
role rather than working around it.

## Printed handouts

One plain-language guide per actor lives in [`handouts/`](handouts/README.md) —
written from the run records here, with the paths that were actually walked.
