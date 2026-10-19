# User guide template

Every Part B handout follows this shape, so a bursar's guide and a parent's guide feel
like they came from the same company. Fill it from the **run record**, not from the code.

---

## Rules

| # | Rule | Why |
|---|---|---|
| 1 | **Write the paths you actually used.** | If the run only worked at a different URL, the handout says that one |
| 2 | **One action per numbered line.** | "Open Finance → Reports, filter to today, then check the total" is three lines |
| 3 | **No "simply", "just", "obviously".** | They tell a confused reader that they are the problem |
| 4 | **Name what you see, not what it does internally.** | "the balance drops" beats "the ledger recomputes" |
| 5 | **State the money and permission rules plainly.** | "Nothing is sent to a parent automatically" prevents a real incident |
| 6 | **Every section ends with what to check when it goes wrong.** | In the user's words: "it says verifying for a week" |
| 7 | **No screenshots required.** | A numbered path survives a UI change; a screenshot becomes a lie |
| 8 | **Never promise a feature that has not run.** | If it is unbuilt, it goes to the backlog, not the handout |

---

## Shape

```md
# <NN> — <Role name>

**Your job:** one sentence, in the user's words.
**Where you work:** which address, which menu group, phone or desk.

## <Task 1 — the thing they do daily>

1. …
2. …

**What you should see:** …

## <Task 2 — the thing they do weekly>

1. …

## What you should never do

- **Never …** — because <the consequence, in money or in trust>.

## When it goes wrong

| What you see | What to do |
|---|---|
| <the user's description of the symptom> | <the next action, not a diagnosis> |
```

---

## Length and tone

- **One page per role.** If it needs two, the role has two jobs — split the file.
- **Second person, present tense.** "You open", not "the user opens".
- **Short sentences.** A bursar reading this is standing at a counter with a parent.
- **Say the number.** "Confirming the same reference twice is refused" is checkable;
  "be careful when confirming" is not.

---

## Checklist before handing it over

- [ ] Every numbered step was performed in the walkthrough and passed
- [ ] No step depends on a screen the role cannot open
- [ ] The "never do" list has at least one money rule and one permission rule
- [ ] The troubleshooting table uses the user's words, not error codes
- [ ] No internal jargon: no "ledger", "entity", "tenant", "token", "webhook"
- [ ] A person who has never seen Edus can finish the first task unaided

---

## Handover pack

When a school receives Edus, send them **three** documents, not twelve:

1. **Their own onboarding handout** (`09-onboarding.md`, Part B) — the first hour.
2. **The admin handout** (`02-tenant-admin.md`, Part B) — the standing routine.
3. **The bursar handout** (`03-bursar-offline-payments.md`, Part B) — money.

Everything else (teacher, HOD, year master, parent) is given to the person doing that job,
individually. A school given seven manuals reads none of them.
