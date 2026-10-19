# 01 — Platform owner (landlord)

**Actor:** the company. Creates schools, sells plans, issues invoices, runs the platform.
**Where:** the base domain, `/admin` · **Browser:** desktop (1024), landlord screens are a desk task.

---

## Part A — Agent playwright

Sign in at `/admin/login` with the landlord account. Every step below is
`http://localhost:8082/...`.

| # | Do | Expect |
|---|---|---|
| 1 | Open `/admin/` | 200; dashboard with school count, revenue and a pending queue |
| 2 | Open `/admin/school/` | 200; every tenant listed with subdomain and status |
| 3 | Open `/admin/school/new` and submit **without** a name | Validation error on `name`; no record created |
| 4 | Create a school: name, subdomain, **principal name / email / password**, active | Redirect to the school page; a database and admin user exist for it |
| 5 | Open the new school's page, then **Fix schema** (`/admin/school/{id}/fix-schema`) | Schema report comes back clean — every table present |
| 6 | Open `/admin/plan/` | Standard and Enterprise listed with pricing models |
| 7 | Create a plan with a required field blank | Validation error; nothing saved |
| 8 | Open `/admin/subscription/registrations` | Declared roll, counted roll, variance, invoice, status per row |
| 9 | Open a registration and set **Pupils counted** (`/verify-roll`) | Variance recalculates; inside tolerance shows OK, outside shows overage |
| 10 | On an over-tolerance registration, raise the overage invoice (`/supplementary`) | One PENDING invoice for `variance × unit price`; a second click is refused |
| 11 | Open `/admin/subscription/invoices` and filter by school | Totals for collected and outstanding match the rows shown |
| 12 | Send a reminder on one PENDING invoice | Flash confirms; Mailpit receives it; clicking again is blocked as already sent |
| 13 | Open `/admin/subscription/active` and invite a school to register | Flash carries a link; Mailpit receives the invitation; the token works once |
| 14 | Open `/admin/credit-request/` and approve one | Wallet credited exactly once; the row leaves the pending list |
| 15 | Open `/admin/agent/` and create an agent | Agent can sign in at `/agent` and sees only that portal |
| 16 | Open `/admin/settings/` and save a value | Saves; the value is reflected on the public pricing/checkout |

**Assert while you are there:** a school's subdomain never shows the landlord nav; a
signed-in school user cannot open `/admin/` (expect 403); the landlord session does not
authenticate on a school subdomain.

### Known-correct 403s

- `/admin/*` as any school user.
- `/agent/*` as the landlord — separate firewall and session context.

---

### Run 2026-10-19 — landlord — agent

Environment `http://localhost:8082` · `admin@gmail.com` · viewport 1024 (desk task)

**First pass:** 11 / 16, blocked at step 4 by **P-LANDLORD-01** (`$provisioner`), which
also made step 5 worthless (**P-LANDLORD-02**, stub repair).
**Both fixed during the run, then steps 4–5 were re-run from scratch** after deleting the
half-created school row.

| Step | First pass | After fix |
|---|---|---|
| 1 Dashboard | pass | pass |
| 2 School list | pass | pass |
| 3 Empty new-school form | pass (HTML5 `required`) | pass |
| 4 Create school | **FAIL** — P-LANDLORD-01 | **pass** — `provtest` → 51 tables, `school_settings`, active term, principal login; `provtest2026` signs in and lands on `/onboarding/identity` |
| 5 Fix schema | **FAIL** — P-LANDLORD-02 | **pass** — *"Schema verified for school_stmarys — session 2026/2027, First Term ready"*; **Repair schema** now on the school page |
| 6 Plans | pass (6 plans; legacy tiers — B-01) | pass |
| 7 Empty plan form | pass | pass |
| 8 Registrations | pass | pass |
| 9 Save counted roll | pass — "Roll verified: 8 declared, 8 counted" | pass |
| 10 Overage invoice | not exercised (inside tolerance) | not exercised |
| 11 Invoices | pass — ₦150,000 collected, ₦0 outstanding | pass |
| 12 Remind | not exercised (no pending invoice) | not exercised |
| 13 Invitation | pass | pass |
| 14 Credit requests | pass (empty state) | pass |
| 15 Agents | pass — created `Walkthrough Agent` | pass |
| 16 Global settings | pass — `sms_price` edit form | pass |

#### Two lessons for the next runs

1. **Provisioning takes 10–20 s** (~50 tables via `SchemaTool`). The click returns before
   the redirect settles; a second action fired during that window raced the first and left
   a school with a default `password123` principal. Wait for the destination flash.
2. **A failed create leaves a half school.** Delete `landlord_db.subscription` then the
   `school` row before re-running, or the subdomain is taken and the repair runs against a
   half-built tenant.

#### Records created by this run

| Record | Value |
|---|---|
| `landlord_db.school` id 8 | **St Mary's College** (`stmarys`) — provisioned, principal `principal@stmarys.ng` / `password123` (created by the repair during the race; re-set it before demoing) |
| `landlord_db.school` id 9 | **Provisioning Test School** (`provtest`) — full end-to-end proof, principal `principal@provtest.ng` / `provtest2026` |
| `landlord_db.agent` id 3 | `agent-walkthrough@edus.ng` / `agent-walkthrough-2026`, 10% commission |
| Roll verified | GOBARAU ACADEMY, 8 declared / 8 counted, variance 0 |

**Downstream:** the new-school path works, so `09-onboarding.md` can now run on
`stmarys`, and the 1,200-pupil import (`DEMO-DATA-1200.md`) has its target school.



---

## Part B — User handout

### You are the platform owner

You run Edus itself. You do not touch a school's pupils or fees — you create the
school, sell it a plan and issue its invoice.

### 1. Create a school

1. Sign in at **`/admin/login`** on the Edus address (not a school's).
2. Open **Schools → New school**.
3. Enter the school's name and the subdomain you agreed with them — this is the word
   before `.edus.ng`, and it cannot be changed later without breaking links.
4. Enter the principal's name, email and a starting password. That person becomes the
   school's administrator.
5. Save, then open the school and click **Fix schema**. Run it once on every new school;
   it guarantees the database has every table the modules expect.
6. Send the principal their address, email and password.

### 2. Sell a plan

1. Open **Subscription → Active** and find the school.
2. Click **Invite to register**. The school receives a link; the link works once.
3. When they register, open **Subscription → Registrations**. You will see the number
   of pupils they declared.
4. Check their real number against the declared one. If the difference is large, correct
   it in **Pupils counted** — the system recalculates the variance.
5. If the school is outside the tolerance, raise the **overage invoice**. One invoice
   only; the system refuses a second while the first is unpaid.

### 3. Get paid

- **Subscription → Invoices** lists every invoice with its status. Filter by school.
- **Remind** sends one email. You cannot accidentally send twice for the same day.
- When a transfer arrives, verify it against your bank statement before approving —
  the system trusts you, not the bank, so a wrong approval gives away a term.
- An unpaid subscription does not delete anything: the school goes **read-only** until
  it pays. Say this to a school that is late; it is usually what they are afraid of.

### 4. Agents and credits

- **Agents** are resellers. They get their own portal at `/agent` and cannot see the
  landlord or a school's records.
- **Credit requests** are schools topping up their SMS wallet. Approve only when the
  money is in your account; approval credits the wallet once.

### When it goes wrong

| What you see | What to do |
|---|---|
| A new school's pages all error | Open the school and run **Fix schema**, then retry |
| The principal never got the invitation | Re-send from the school page; the old link is not reusable |
| A school says "we paid" but the invoice is pending | Check your bank statement, then verify the payment — never the other way round |
| An agent can see `/admin` | Record it as a defect immediately: the firewalls are separate and this should be impossible |
