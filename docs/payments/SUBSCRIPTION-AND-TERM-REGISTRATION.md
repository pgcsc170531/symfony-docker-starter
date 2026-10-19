# Subscription & term registration — the design

**Status:** partially implemented (18 Oct 2026). The school-facing pricing screen and
Standard term registration are live; the rest of the billing engine is still design-only.
See §16 for the exact boundary.
**Date:** 18 Oct 2026.
**Scope:** how a school pays Edus, how it declares its roll each term, what a large
school gets for registering early, and who pays the online transaction charges.

> **Do not conflate two different money flows.**
>
> | Flow | Payer | Payee | Lives in |
> |---|---|---|---|
> | **SaaS subscription** | the school | Edus | landlord database |
> | **School fees** | a parent | the school | tenant database |
>
> A subscription invoice is **not** a school-fee invoice. They must never share a
> number series, a ledger, a Paystack subaccount or a report. Mixing them is how a
> bursar ends up reconciling a parent's ₦5,000 against a school's ₦250,000 plan.

---

## 1. What exists today

| Piece | Where | What it does |
|---|---|---|
| `Plan` | `src/Entity/Landlord/Plan.php` | Flat price for a duration (4 months by default), `minStudents`/`maxStudents`, free credit bonus, trial flag. |
| `Subscription` | `src/Entity/Landlord/Subscription.php` | One active subscription per school: plan, start, end, status (`ACTIVE`, `EXPIRED`, `GRACE`). |
| `SubscriptionPayment` | `src/Entity/Landlord/SubscriptionPayment.php` | Manual proof-of-payment record: plan, amount, `PENDING`/`VERIFYING`/`APPROVED`/`DECLINED`, uploaded receipt. |
| `SubscriptionService` | `src/Service/SubscriptionService.php` | Create, renew (stack time), upgrade (fresh cycle), wallet bonus. |
| Landlord screens | `/admin/subscription/*` | Pending payments, approve, reject, active list, generate slip, reminder stub. |
| School screen | `/dashboard/settings` and related | Plan selection and payment proof (current onboarding path). |
| Wallet | `WalletTransaction` | SMS/email credits; `Plan.freeCreditAmount` is awarded on paid activation/renewal. |

**Current prices in the landlord DB**

| Plan | Price | Pupil band | Duration | Trial |
|---|---:|---|---|---|
| Starter | ₦50,000 | 0–100 | 4 months | no |
| Growth Plan | ₦120,000 | 101–400 | 4 months | no |
| Scale Plan | ₦250,000 | unlimited (min 0) | 4 months | no |
| Free Trial Plan | ₦0 | unlimited | 4 months (service gives 14 days) | yes |

The gap is obvious: a 1,200-pupil school and a 30-pupil school both land on
**Scale at ₦250,000**. The price does not know how big the school is, and there is
no per-term moment where the school confirms its roll. That is what this design
fixes.

---

## 2. Goals

1. A school can pay for **one term at a time**, without a sales call.
2. The price reflects the **declared roll**, with a clear cap and overage rule.
3. The school receives a **term registration link** and can load/confirm its
   register before the term starts.
4. Large schools have a path that does not involve typing 1,500 names by hand.
5. Online gateway charges are **visible and decided**, not absorbed silently.
6. Failure is graceful: expired subscription makes the tenant **read-only**, never
   deletes data.

### Non-goals (for this phase)

- Replacing the school-fee invoice/payment engine.
- Per-parent billing.
- Auto-charging a card without the school's explicit consent.
- A full accounting ledger (debits, credits, journals). This is billing, not accounting.

---

## 2A. Revision — the ₦1.2m licence proposal (18 Oct 2026)

The first commercial proposal was **₦1,200,000 upfront + ₦450,000 per year**.
That is a **perpetual-licence** shape, not a SaaS subscription. It is not
recommended as the standard offer.

| Pupils | Year 1 cost | Per pupil, year 1 | 3-year cost | Per pupil, 3 years |
|---:|---:|---:|---:|---:|
| 100 | ₦1,650,000 | ₦16,500 | ₦2,550,000 | ₦25,500 |
| 500 | ₦1,650,000 | ₦3,300 | ₦2,550,000 | ₦5,100 |
| 1,200 | ₦1,650,000 | ₦1,375 | ₦2,550,000 | ₦2,125 |

**The final Enterprise offer is slightly different:** the annual fee is
**₦550,000** (the old ₦450,000 plus the mandatory ₦100,000 email service), and
implementation is **₦300,000**. That makes year 1 **₦2,050,000** and the 3-year
total **₦3,150,000** — see §2C.

**Why it is the wrong default**

1. **Regressive.** A 100-pupil school pays 12× more per pupil than a 1,200-pupil
   school. The smallest schools — the ones that need the software most — face the
   highest per-child cost.
2. **Upfront barrier.** ₦1.2m before the school has used the product is a board
   decision, not a bursar decision. It kills the self-service funnel.
3. **Disconnected from support cost.** A 1,500-pupil school generates more
   training, data, SMS and support than a 100-pupil school, but pays the same
   annual fee.
4. **It is not what the market does.** The education SaaS market has moved to
   per-student subscription with an onboarding fee; perpetual licences survive in
   on-premise/enterprise deals, not in cloud SIS.

**When the licence shape still makes sense:** a self-hosted/on-premise deployment,
a custom integration, a multi-campus group that wants a perpetual right, or a
government contract with procurement rules. Keep it as an **enterprise option**,
not the default.

---

## 2B. What the market actually does (checked 18 Oct 2026)

| Product | Public model | Onboarding / implementation | Transaction fees |
|---|---|---|---|
| **Classe365** | **$100/month for 1–100 students**, then priced by student band. Pricing is purely by student count; unlimited staff. 15-day free trial. | **No setup fee**; optional paid onboarding/migration. | Payment gateway integration; fees are between the school and its gateway. |
| **Sycamore** | **$4 per student per month**, all modules included. Volume tiers for larger schools; custom district/network quote. | **Onboarding minimum $2,500** (migration lead, data validation, training). | **3.9% cards, 1.8% ACH + 1%**, configurable per school: absorb, split, or direct pay. |
| **Gradelink** | Quote-only; the school answers a short questionnaire and receives a quote. | Implementation/onboarding quoted. | Payment processing available; fees configured per school. |
| **PowerSchool / Alma / Blackbaud** | Enterprise quote; per-student or flat contract, usually annual. | Implementation and training are paid projects. | Typically passed to families or absorbed; varies by district. |
| **SchoolTry / Edves (Nigeria)** | No public price; demo/quote. Annual per-school contracts. | Onboarding/training quoted. | Payment collection is usually an add-on. |

**What the market agrees on**

1. **No perpetual licence for cloud SIS.** The buyer pays for a period, not for
   the code.
2. **The roll is the price basis.** Per-student is the norm; flat “unlimited” plans
   are rare at the top end.
3. **Onboarding is a separate, paid project** — migration, data validation and
   training are real costs.
4. **Transaction fees are a configuration, not a secret.** The school chooses
   whether to absorb, split or pass them to the parent.
5. **Annual prepay is rewarded** with a discount; monthly/term billing carries a
   higher unit price.

> **Reality check on naira vs dollar pricing.** Sycamore at $4/student/month is
> roughly **₦72,000 per pupil per year** at ₦1,500/$. Edus cannot charge that in
> the Nigerian private-school market. The recommended price below is deliberately
> **about 1% of the global per-pupil price**; profitability must come from
> volume, onboarding and the add-on stack — not from matching dollar pricing.

---

## 2C. Revised plan structure — 18 Oct 2026 (owner clarification)

Two live tracks.

| Track | Who it is for | Price | Pupil counting | Term registration |
|---|---|---|---|---|
| **Standard (per pupil)** | Most schools, from start-ups to ~1,000 pupils | **₦750/pupil per 4-month term** termly, or **₦500/pupil/term** when the year is paid up front | Yes — the roll is the bill | Sets the bill |
| **Enterprise (licence)** | 1,000+ pupils, multi-campus, integration needs | **₦1,200,000 one-off licence + ₦550,000/year (email included) + ₦300,000 implementation** | **No pupil counting** — unlimited pupils | Confirms the roll for provisioning only |

The old flat Starter/Growth/Scale prices are **grandfathered** for existing
contracts until renewal. New contracts use the per-pupil Standard rate. The old
names can remain as feature/roll labels, but the invoice is per pupil.

> **The roll rule (state it on the pricing page):** under **600 pupils Standard is
> cheaper**, over **800 pupils Enterprise is cheaper**, and 600–800 depends on
> whether the school pays termly or annually. Full arithmetic in §2D.

---

### Standard — the per-pupil track

| Billing | Rate | Invoice |
|---|---|---:|
| **Termly** | ₦750/pupil per 4-month term | pupils × ₦750 |
| **Annual prepay** | ₦500/pupil/term, paid for 3 terms | pupils × ₦1,500 |

- **Termly minimum:** ₦50,000/term.
- **Annual minimum:** ₦100,000/year.
- **Annual discount:** 33% against the termly rate.
- The school chooses **termly** or **annual** at renewal; the annual option is paid
  in full before the first term.
- Term registration is the billing trigger. The school sees the count and the
  calculation before accepting.

| Pupils | Termly (per term) | Termly (3 terms/year) | Annual prepay |
|---:|---:|---:|---:|
| 100 | ₦75,000 | ₦225,000 | ₦150,000 |
| 400 | ₦300,000 | ₦900,000 | ₦600,000 |
| 800 | ₦600,000 | ₦1,800,000 | ₦1,200,000 |
| 1,200 | ₦900,000 | ₦2,700,000 | ₦1,800,000 |

Compared with the old flat plans, this is a deliberate increase:

| Pupils | Old flat (annual, 3 terms) | New annual prepay | Change |
|---:|---:|---:|---:|
| 100 | ₦150,000 (Starter) | ₦150,000 | same |
| 400 | ₦360,000 (Growth) | ₦600,000 | +67% |
| 800 | ₦750,000 (Scale) | ₦1,200,000 | +60% |
| 1,200 | ₦750,000 (Scale) | ₦1,800,000 | +140% |

The termly option is more expensive than annual by design; it is the cash-flow
choice for a school that cannot pay the year up front.

---

### Enterprise — the licence track (₦1.2m + ₦550k/year)

The original ₦1.2m licence proposal is the **Enterprise** offer. It fits schools
that:

- have **1,000+ pupils**, several campuses, or a board/procurement process;
- do **not** want to count pupils for billing;
- need SSO/API integration, a custom domain, migration, an SLA and a named
  success manager;
- need a server/hosting arrangement sized for their capacity.

**Price**

| Item | Amount |
|---|---:|
| Licence (one-off) | ₦1,200,000 |
| Annual renewal | **₦550,000/year from year 1** — includes mandatory email notifications |
| **Implementation charge** | **₦300,000 one-off** (server/hosting setup, migration, training) |
| **Year 1 total** | **₦2,050,000** (₦1,200,000 licence + ₦300,000 implementation + ₦550,000 annual) |
| **3-year total** | **₦3,150,000** (₦2,050,000 + 2 × ₦550,000) |
| Initial term | 3 years recommended; annual renewal thereafter |

**Included**

| Item | Detail |
|---|---|
| Pupils | Unlimited; the roll is shown for capacity/support, never invoiced |
| Onboarding | Dedicated migration lead, data validation, staff training |
| Integration | SSO, API access, custom domain, bulk import/export |
| Support | Named success manager, priority queue, SLA |
| Multi-campus | Consolidated roll and reporting across school rows |
| Audit | Exportable usage and billing statements for the board |
| Email | **Mandatory and included** in the ₦550,000 annual fee |

The implementation charge is not optional: it pays for the server/hosting
capacity, migration and training that an Enterprise school consumes before it
pays its first renewal. Do not bury it in the licence.

**Email is mandatory.** Standard plans include email notifications in their price;
the Enterprise annual fee of **₦550,000** is the old ₦450,000 renewal plus the
**₦100,000 email service**. There is no Enterprise option without email.

---

### When does Enterprise beat Standard?

Let:

- `P` = pupils;
- Standard termly annual cost = `P × ₦2,250`;
- Standard annual-prepay cost = `P × ₦1,500`;
- Enterprise year 1 = `₦2,050,000` (licence + implementation + annual);
- Enterprise renewal = `₦550,000/year`;
- Enterprise 3-year total = `₦3,150,000`.

With **implementation = ₦300,000** and email included:

| Enterprise compared with… | Year 1 break-even | Year 2 break-even | 3-year break-even |
|---|---:|---:|---:|
| **Standard termly** | **911 pupils** | 245 pupils | **467 pupils** |
| **Standard annual prepay** | **1,367 pupils** | 367 pupils | **700 pupils** |

**What this means**

- A school paying **termly** reaches the Enterprise break-even at about **467
  pupils over three years** — because termly Standard is expensive.
- A school paying **annually** gets the 33% discount, so it needs about **700
  pupils over three years** before Enterprise is cheaper.
- **At 1,000+ pupils — the target — Enterprise is the rational choice:** it is
  cheaper than annual Standard over three years (₦3.15m vs ₦4.5m) and much
  cheaper than termly (₦3.15m vs ₦6.75m).
- At **800 pupils**, Enterprise is already cheaper than annual Standard over three
  years (₦3.15m vs ₦3.6m) and far cheaper than termly (₦3.15m vs ₦5.4m).
- At **600 pupils**, annual Standard is cheaper over three years (₦2.7m vs
  ₦3.15m); termly Standard is more expensive, so a termly school should still
  consider Enterprise.

---

## 2D. The roll rule — what the pricing page must say out loud

This is the single most useful sentence in the whole document. It belongs on the
pricing page, in the sales script, and in the quote email — **above** the feature
lists.

> **Under 600 pupils, Standard is cheaper. Over 800 pupils, Enterprise is
> cheaper. Between 600 and 800 it depends on how the school pays.**
>
> All three comparisons are over the **3-year term**, with the mandatory email
> service already included in the ₦550,000 Enterprise annual fee.

| Pupils | Cheaper over 3 years | At 800 pupils, the actual arithmetic |
|---|---|---|
| **≤ 600** | **Standard (annual prepay)** — ₦2.7m at 600 vs ₦3.15m Enterprise | — |
| **600 – 800** | **Depends on payment mode** | — |
| **≥ 800** | **Enterprise** — ₦3.15m | vs **₦3.6m** annual Standard, **₦5.4m** termly |

The middle band is not vague — it is decided by one question: *“are you paying
termly or for the year?”*

| Pupils | vs Standard **termly** (₦6,750/pupil/3yrs) | vs Standard **annual** (₦4,500/pupil/3yrs) | Sell |
|---|---|---|---|
| under 467 | Standard cheaper | Standard cheaper | Standard |
| **467 – 700** | **Enterprise cheaper** | Standard cheaper | Standard if they can pay annually; Enterprise if they must pay termly |
| **701 – 799** | **Enterprise cheaper** | **Enterprise cheaper** | Enterprise, but the saving is thin — lead with support, not price |
| **800 +** | **Enterprise cheaper** | **Enterprise cheaper** | Enterprise |

### Two honest caveats — say them, don't hide them

1. **The 800 rule is a 3-year rule, not a year-1 rule.** At 800 pupils the
   Enterprise year-1 bill is **₦2,050,000** (licence + implementation + annual)
   against **₦1,200,000** for annual Standard. Enterprise is *more* expensive in
   year 1 and only wins from year 3. A school that will not commit to the term
   should stay on Standard. Never quote “800 pupils” as a first-year saving.
2. **The rule already includes email.** ₦550,000 = the old ₦450,000 renewal +
   the ₦100,000 email service. If email had stayed optional, the 3-year total
   would be ₦2,950,000 and the annual-prepay break-even would fall to **656**
   pupils. The rule is deliberately computed on the dearer, email-included
   number, so the recommendation never under-states what the school will pay.

**The rule at a glance, for the page**

```
       0 ────────── 600 ────────── 800 ────────── 1,500+
         Standard          compare          Enterprise
         cheaper         (how do you pay?)    cheaper
```

> **Built 18 Oct 2026:** the public page lives at `/pricing`
> (`templates/landlord/home/pricing.html.twig`) and reads every number from
> `src/Service/Pricing/PricingCatalog.php` — including the mandatory email fee.
> `tests/Service/Pricing/PricingCatalogTest.php` locks the totals and crossovers.
> Billing itself is still **not** implemented.

**Implementation sensitivity** (3-year break-even)

| Implementation | vs Standard termly | vs Standard annual |
|---:|---:|---:|
| ₦0 | 422 pupils | 633 pupils |
| ₦300,000 | 467 pupils | 700 pupils |
| ₦500,000 | 496 pupils | 744 pupils |
| ₦1,000,000 | 570 pupils | 856 pupils |

Even at a ₦1m implementation charge, a 1,000-pupil school is still better off on
Enterprise over three years than on annual Standard (₦3.85m vs ₦4.5m). Below
~700 pupils, annual Standard remains the better deal.

---

### What the school has generated vs what it has paid

Every track gets a **Value & Billing** view. This is the answer to “I want to see
what we generated and what we paid for the system.”

| Panel | What it shows |
|---|---|
| Plan | track, rate, licence status, renewal date, pupil cap (or “unlimited”) |
| Roll | students, staff, classes, active term — shown, not billed on Enterprise |
| Usage this term | report cards generated, transcripts, SMS sent/remaining, online collections processed, storage |
| Billing | invoices, payments, balance, next renewal, proof status |
| Value statement | one printable page: “1,200 report cards, 3,400 SMS, ₦12.4m collected online; licence + annual + implementation paid: ₦2,050,000 this year” |

The statement is exportable as PDF/CSV so a bursar can put it in front of the
board. It is also the honest answer to “what am I paying for?”

### Profitability stack

| Revenue line | How it works |
|---|---|
| **Standard subscriptions** | ₦750/pupil/term termly, or ₦500/pupil/term annual prepay |
| **Enterprise licence + annual + implementation** | ₦1.2m one-off + ₦550k/year (email included) + ₦300k implementation |
| **Online collection handling** | `ProcessingFeeCalculator`: **₦200 handling** per online parent payment; transfer total capped at **₦500**; paid by the parent — **not** deducted from the school |
| **SMS / email wallet** | Prepaid units; registration bonus creates usage, overage is margin |
| **Premium modules** | branded report cards, advanced analytics/PDF exports, API access |

**Recommendation:** launch Standard per-pupil termly/annual; keep Enterprise for
1,000+ schools with a quoted implementation charge; grandfather the old flat
prices until renewal. This raises revenue per pupil, gives schools a genuine
annual discount, and makes Enterprise cheaper than Standard exactly where the
school is large enough to need it.

---

## 3. Pricing models

> The three models below are the earlier analysis. **§2C is the recommendation**;
> this section explains why the alternatives were rejected.

### 3.1 Model A — flat band (what exists)

`Starter ₦50k · Growth ₦120k · Scale ₦250k` per term.

**Good for:** small schools, one decision, one invoice.
**Bad for:** large schools; Scale is unlimited and under-priced at 1,500 pupils,
over-priced at 450.

### 3.2 Model B — per pupil per term (recommended for the long tail)

```
term invoice = declared pupils × price per pupil per term
             (subject to a minimum invoice)
```

Illustrative only — **the actual number is an owner decision**:

| Pupils | Price/pupil/term | Invoice/term |
|---:|---:|---:|
| 50 | ₦1,000 | ₦50,000 (minimum applies) |
| 150 | ₦1,000 | ₦150,000 |
| 400 | ₦900 | ₦360,000 |
| 800 | ₦750 | ₦600,000 |
| 1,200 | ₦650 | ₦780,000 |
| 2,000 | ₦550 | ₦1,100,000 |

**Good for:** fairness, predictable revenue, easy to explain to a proprietor.
**Bad for:** a school with a fluctuating roll needs a clear “declared vs verified”
rule (see §4.4).

### 3.3 Model C — hybrid base + overage (recommended transition)

Keep the existing flat bands as the entry product, then charge for pupils above
the band ceiling.

```
Scale Plan: ₦250,000 covers up to 600 pupils
            + ₦250 per pupil per term above 600
```

| Pupils on Scale | Invoice/term |
|---:|---:|
| 400 | ₦250,000 |
| 600 | ₦250,000 |
| 800 | ₦300,000 |
| 1,200 | ₦400,000 |
| 2,000 | ₦600,000 |

This is the least disruptive: existing Starter/Growth customers see no change;
large schools start paying for the roll they actually have. It also gives the
sales team a simple “₦250 per extra pupil per term” sentence.

**Recommendation:** adopt **Model C** for the next school year, and expose
**Model B** only for new “Enterprise” contracts above 1,500 pupils.

---

## 4. Term registration

### 4.1 The moment

```
Term N ends
   │
   ├─ results final-approved, reports available
   │
Term N+1 is created (landlord or school admin)
   │
   ├─ 14 days before start: term registration link is generated
   │
   ├─ school confirms roll ──► invoice ──► payment ──► ACTIVE for Term N+1
   │
   └─ first day of term: results entry opens; no active term = read-only
```

### 4.2 The link

`GET /term/register/{token}`

- The token is a random 32-byte value, stored **hashed** (like `ReportShareToken`).
- It identifies **school + session + term**, never a pupil.
- It expires at the end of the first week of term.
- It can be used more than once **before submission** (a bursar will open it on a
  phone, then finish on a laptop), but only one **submission** is accepted per
  school/term. A later correction creates a revision, not a second invoice.
- The page requires the school admin to be signed in, **or** the token itself
  grants a limited “registration only” session. Recommendation: require login,
  and email the link to the admin; a forwarded link then cannot change the roll.

### 4.3 The flow (small school)

```
1. Open link → see session/term, school name, current roll count.
2. Review the auto-filled roll (Enrollment for the session).
3. Add/withdraw pupils (with a reason), or upload a CSV.
4. See the quote for your track:
      standard termly → declared pupils × ₦750;
      standard annual → declared pupils × ₦1,500, paid up front;
      enterprise → the annual renewal date and licence status, not a per-pupil bill.
5. Accept the declaration (checkbox + name).
6. Pay online, or upload a transfer receipt.
7. Status becomes PENDING_PAYMENT → ACTIVE when confirmed.
8. The term’s results-entry screens unlock.
```

### 4.3a What the registration bills (by plan)

| Track | What the registration is for | What it bills |
|---|---|---|
| **Standard** | Declaring the billable roll and the classes | **Termly:** pupils × ₦750. **Annual prepay:** pupils × ₦1,500, paid before the first term. |
| **Enterprise** | Provisioning, support capacity, multi-campus roll | **Nothing per pupil.** The annual fee runs on the licence anniversary; the registration is a roll/support check. |

A Standard school sees the count and the calculation before accepting. An
Enterprise school is never asked to count pupils for billing — only to keep the
roll accurate for support.

### 4.4 Declared vs verified

- **Declared** is what the school submits. On **Standard** it is the billing basis;
  on **Enterprise** it is the provisioning/support basis.
- **Verified** is the count of active `Enrollment` rows after the first week.
- On **Standard**, if verified exceeds declared by more than **5% or 10 pupils**
  (whichever is larger), create a **supplementary invoice** at the same rate, or
  carry a credit to the next term; do not suspend.
- On **Enterprise**, a variance does **not** change the invoice. It updates the
  roll used for support and capacity only.
- If verified is lower on Standard, **do not auto-refund**; carry a credit to the
  next term.
- This rule must be on the page in plain language before the school accepts.

### 4.5 The flow (large school, 500+)

The same page, with a bulk path:

1. **Download CSV template**: `admission_no, full_name, class, status`.
2. **Upload**: server validates each admission number against the tenant’s
   students and current enrollment.
3. **Preview**: matched / unmatched / duplicates / withdrawn, with a downloadable
   error file. Nothing is committed until “Confirm”.
4. **Chunked processing**: 2,000 rows should not time out. Process in batches of
   250 and show progress; store the upload as a `TermRegistrationBatch`.
5. **Manual overrides**: add a pupil who joined after the export; mark a leaver.
6. **Quote and accept** as above.
7. **Enterprise option**: a signed API endpoint or a PO/invoice flow for schools
   that will not use a card. The registration can be submitted without payment;
   the subscription is activated when the bursar confirms the transfer.

### 4.6 What the school sees on the page

- Current track, term, dates, roll count.
- Price per pupil, termly vs annual, the 33% annual saving, subtotal, discount,
  VAT, gateway fee, total.
- “What you get this term” (see §5).
- A clear expiry date for the link.
- A “talk to us” link for a roll above the enterprise threshold.

---

## 5. Registration benefits

Registration must be worth doing **before** the deadline, not just a compliance
gate.

### 5.1 For the school

| Benefit | Why it matters |
|---|---|
| **Price lock** | Standard: the per-pupil rate is fixed for the term or the prepaid year. Enterprise: the annual fee is fixed for the contract year. |
| **Early activation** | Results entry, attendance and report cards are ready on day one. |
| **Roll carried over** | Classes, enrollments, guardians and fee history stay intact; no re-import. |
| **SMS/email credits** | A registration bonus (e.g. 500 units) lands in the wallet; larger schools get a larger bucket. |
| **Priority support** | Registered schools go to the front of the queue during the first two weeks. |
| **Term analytics** | The whole-school performance PDF and subject/class comparisons are available from the first approval. |
| **Value statement** | A printable page shows what the school generated and what it paid — report cards, SMS, online collections, invoices and balance. |
| **Predictable invoice** | Standard: one declared-roll invoice per term, or one prepaid annual invoice. Enterprise: one annual invoice. No surprise overage on Enterprise. |

### 5.2 For Edus

- Predictable cash flow per term.
- Accurate roll for capacity planning and support staffing.
- Fewer “we thought it was included” disputes, because the declaration and the
  quote are on one page.
- A natural upgrade moment: the quote shows the annual saving and the Enterprise
  break-even for a large roll.

### 5.3 For large schools — the Enterprise track (1,000+)

- **No pupil counting for billing.** The licence covers unlimited pupils; the roll
  is shown for support and capacity only.
- **Integration and SLA**: SSO/API, custom domain, dedicated migration, named
  success manager and a contractual response time.
- **Multi-campus**: one contract, several school rows, one consolidated invoice.
- **Board-ready value statement**: report cards generated, SMS used, online
  collections processed, licence and annual fee paid.
- **Annual prepay** is the norm; the licence is a one-off, the annual fee renews.

### 5.4 What the school has generated vs what it has paid

Every plan shows a **Value & Billing** statement, exportable as PDF/CSV:

```
Plan: Growth · ₦120,000/term · billed: yes
Roll: 361 pupils · 42 staff · 9 classes (shown, not billed on this plan)
This term: 361 report cards · 1,420 SMS · ₦4.8m collected online
Paid: ₦120,000 on 12 Sep 2026 · Balance: ₦0
Next renewal: 8 Jan 2027
```

This is the answer to “I want to see what we generated and what we paid for the
system”, and it is the same page a bursar can show the board.

---

## 6. Online charges — two different pockets

> **The school-fee payment page is not the subscription pricing page.** A parent
> paying fees is paying the **school**; the processing charge belongs on the
> parent’s payment screen. A school paying Edus sees a **subscription invoice**
> with its own fee line. Never send a parent to the subscription checkout or a
> school to the parent fee page.

### 6.1 Parent pays school fees online (the school's money)

The fee is **on top of the invoice**, and the school is settled the **full invoice
amount**. The charge never comes out of the school’s pocket unless the school
explicitly chooses to absorb it.

**Transfer / dedicated virtual account**

- **1%** of the invoice, capped at **₦300** gateway fee;
- **7.5% VAT on the gateway fee only** — not on the school fees;
- **₦200 handling**;
- total processing **capped at ₦500**.

**Card**

- **1.5% + ₦100**, gateway fee capped at **₦2,000**;
- **7.5% VAT on the gateway fee only**;
- **₦200 handling**;
- the card total is **not** capped at ₦500, because the gateway cost is higher;
  it is shown separately before the parent pays.

| School fee | Transfer processing | Card processing | Parent sees |
|---:|---:|---:|---|
| ₦5,000 | ₦253.75 | ~₦388.13 | Both, and chooses |
| ₦50,000 | ₦500 (cap) | ₦1,113.75 | Both, and chooses |
| ₦250,000 | ₦500 (cap) | ₦2,350.00 | Both, and chooses |

The parent sees the breakdown before paying:

```
School fees            ₦50,000.00
Transfer processing       ₦500.00
Card processing         ₦1,113.75
```

**The 7.5% is VAT on the gateway fee, not 7.5% of the school fees.** On a ₦50,000
transfer the gateway fee is ₦300, VAT is ₦22.50, handling is ₦200, and the total
is capped at ₦500. Nobody is charging 7.5% of ₦50,000.

The **₦200 handling** is Edus revenue. The gateway fee and VAT are passed through
to the provider.

**If a school wants to absorb the fee:** configure the school as `absorb`. The
parent then pays the invoice only, and the school nets invoice − fee. Do not make
this the default; it silently cuts the school’s income on every online payment.

### 6.2 Parent transfers (offline)

- The slip shows the school’s bank name, account number and account name.
- The parent uploads proof (JPG/PNG/WEBP/PDF) from the payment slip.
- The payment remains **PENDING** until a bursar verifies it against the bank.
- **No processing fee** is added to a transfer.
- The proof file, uploader, timestamp and verifier are audited.
- This flow already exists in `PaymentController::uploadProof()` and the bursar
  verification screen; the subscription work does not replace it.

### 6.3 The school chooses the method

`School::PAYMENT_ONLINE`, `PAYMENT_OFFLINE`, `PAYMENT_BOTH` already exist and are
set in Settings. The parent sees only the methods the school allows:

| Setting | What the parent can do |
|---|---|
| `online` | Pay by card/transfer through the gateway; processing fee shown |
| `offline` | Transfer to the school and upload proof; no processing fee |
| `both` (recommended default) | Choose either; the fee difference is visible |

### 6.4 The school pays Edus (Edus’s money)

This is a separate invoice with a separate fee:

- **Card:** 1.5% + ₦100, capped at ₦2,000, plus 7.5% VAT on that gateway fee, plus
  the ₦200 handling.
- **Transfer/DVA:** 1% capped at ₦300, plus 7.5% VAT on that gateway fee, plus
  the ₦200 handling; the parent-side total is capped at ₦500 (the subscription
  invoice uses its own line, not the parent cap).
- Show the fee as its own line; do not reuse the parent `MAX_FEE = ₦500` cap for a
  large subscription invoice.
- **Email notifications:** included in Standard; **included in the Enterprise ₦550,000 annual fee** (mandatory).
- **Recommendation:** the school pays the fee as a separate line, or Edus absorbs
  it on annual prepay as a sales incentive.
- A bank transfer with an uploaded receipt is allowed for schools that will not
  use a card; the landlord verifies it in `/admin/subscription/pending`.

### 6.5 Other pass-through costs

| Cost | Who pays | How |
|---|---|---|
| SMS units | school | prepaid wallet; plan bonus on registration |
| Email delivery | Included in Standard; included in the Enterprise ₦550,000 annual fee | — |
| Payment proof verification | Edus | manual today; a bank-transfer reconciliation later |
| VAT on the Edus subscription | depends on jurisdiction | show as a separate line if Edus is VAT-registered; do not guess |

### 6.6 What not to do

- Do not deduct the parent’s processing fee from the school’s settlement unless
  the school chose `absorb`.
- Do not silently add the gateway fee to the plan price and then advertise a
  different number.
- Do not let a failed payment leave the school suspended when the money left
  their bank; use `PENDING_PAYMENT` + grace.
- Do not charge a second time for a duplicate webhook; the payment reference is
  idempotent.
- Do not use the parent fee calculator for a subscription invoice; the caps and
  handling are different.

---

## 7. Lifecycle and access

```
TRIAL ──► PENDING_PAYMENT ──► ACTIVE ──► GRACE ──► SUSPENDED
             │                    │          │
             └── expired link ────┘          └── payment lands: back to ACTIVE
```

| State | What the school can do | What Edus does |
|---|---|---|
| `TRIAL` | full product for 14 days | reminder on day 10 |
| `PENDING_PAYMENT` | enter results, add pupils, export; **no new report release** | show a banner; no data loss |
| `ACTIVE` | everything | — |
| `GRACE` (7 days) | read-only + payment page | daily reminder |
| `SUSPENDED` | sign-in, billing, export; **no writes** | keep data, no delete |
| `CANCELLED` | export for 30 days | archive after 90 days |

**Never hard-block a school from exporting its own data.** The exit path is part
of trust.

### 7.1 What “read-only” means precisely

- Allowed: sign in, view dashboards, download report cards and performance PDFs,
  export students/invoices, pay.
- Blocked: creating a term, entering scores, marking attendance, approving
  results, sending SMS/email.
- A parent’s already-shared report link continues to work until its own expiry;
  a lapsed subscription must not break a document already given to a parent.

---

## 8. Data model proposal

### 8.1 Extend `Plan`

| Field | Type | Purpose |
|---|---|---|
| `pricingModel` | string: `per_student`, `licence` | Standard or Enterprise |
| `termPricePerStudent` | decimal(10,2), nullable | Standard termly: ₦750 |
| `annualPricePerStudent` | decimal(10,2), nullable | Standard annual: ₦500/term (₦1,500/year) |
| `minimumTermInvoice` | decimal(12,2), nullable | ₦50,000 |
| `minimumAnnualInvoice` | decimal(12,2), nullable | ₦100,000 |
| `licenceFee` | decimal(12,2), nullable | Enterprise ₦1,200,000 |
| `annualFee` | decimal(12,2), nullable | Enterprise ₦550,000/year (email included) |
| `implementationFee` | decimal(12,2), nullable | Enterprise ₦250k–₦500k |
| `features` | json | what the plan unlocks (reports, SMS, API) |
| `isPublic` | bool | show on the pricing page |
| `legacyUntil` | date, nullable | grandfather an old flat price until renewal |

Add a `PlanRate` history table (plan, effective from, term rate, annual rate,
licence, annual fee) so a price change never rewrites an issued invoice.

Keep the old `price` field for legacy flat contracts only; do not overload it for
the new per-pupil model.

### 8.2 New `TermRegistration`

| Field | Notes |
|---|---|
| `id`, `school`, `session`, `term` | unique `(school, term)` |
| `track` | `standard` or `enterprise` |
| `billingCycle` | `termly` or `annual` (Standard) |
| `declaredStudents` | what the school submits |
| `verifiedStudents` | enrollment count at first-week check |
| `unitPrice`, `subtotal`, `discount`, `gatewayFee`, `vat`, `total` | snapshot, never recomputed later |
| `implementationFee` | Enterprise only; snapshot |
| `status` | `DRAFT`, `SUBMITTED`, `INVOICED`, `PAID`, `VERIFIED`, `CANCELLED` |
| `tokenHash` | only the hash; clear token shown once |
| `expiresAt`, `submittedAt`, `submittedBy` | audit |
| `notes` | bursar’s reason for a manual adjustment |
| `batch` | optional link to `TermRegistrationBatch` for large uploads |

`TermRegistrationBatch`: file name, row count, matched, unmatched, error file,
created by, created at.

### 8.3 `SubscriptionInvoice` (or extend `SubscriptionPayment`)

Recommend a separate entity because an invoice is not a payment:

- `registration`, `school`, `plan`, `reference`, `lineItems` (json), `subtotal`,
  `gatewayFee`, `vat`, `total`, `status`, `dueAt`, `paidAt`, `proofFilename`.

`SubscriptionPayment` remains the record of money received; one invoice can have
one or more payment attempts.

### 8.4 `Subscription`

Add optional `term`, `studentCount`, `registration`, `track`, `billingCycle`,
`licencePaidAt`, `implementationFee` so a subscription can be traced back to the
roll and the track it was priced on. Keep `startDate`/`endDate`; a Standard term
subscription should end at the term end.

---

## 9. Routes and screens

### School admin (tenant)

| Route | Purpose |
|---|---|
| `GET /subscription` | current plan, term, roll, invoice history |
| `GET /subscription/term/{token}` | the registration page (signed token) |
| `POST /subscription/term/{token}/preview` | parse CSV, return matched/unmatched |
| `POST /subscription/term/{token}/submit` | declare the roll, create invoice |
| `POST /subscription/invoice/{id}/pay` | initiate gateway payment |
| `POST /subscription/invoice/{id}/proof` | upload a transfer receipt |

### Landlord (Edus)

| Route | Purpose |
|---|---|
| `GET /admin/subscription/pending` | existing; add invoice/registration links |
| `POST /admin/subscription/verify/{id}` | existing; activate on payment |
| `GET /admin/subscription/registrations` | all term registrations, declared vs verified |
| `POST /admin/subscription/registration/{id}/verify-roll` | first-week check |
| `POST /admin/subscription/registration/{id}/supplementary` | create an overage invoice |

### Email

- T-14: “Your Term 2 registration link” (admin only).
- T-7: reminder with a one-line quote.
- T-1: “registration closes tomorrow”.
- On submit: invoice + payment instructions.
- On activation: “Term 2 is active; here is what is unlocked”.

---

## 10. Security and abuse

- **Token**: 32 random bytes, hashed at rest, expiry, one submission, audit who
  opened/submitted. No pupil names in the URL.
- **Login**: require an authenticated school admin; the token identifies the
  registration, not the person.
- **CSRF** on every POST; rate-limit the registration endpoints.
- **Roll changes** are audited: who added/removed a pupil and why.
- **No privilege escalation**: a school admin cannot change the unit price; only
  the landlord can override a price, and the override is logged.
- **Double payment**: idempotency key on the invoice; a duplicate webhook must
  not extend the subscription twice.
- **Refund**: only a landlord action, with a reason and an audit row.

---

## 11. Edge cases to decide before coding

| Case | Proposed handling |
|---|---|
| Standard: a pupil joins in week 2 | Supplementary invoice at the same rate, or a credit next term; do not suspend. |
| Standard: registers 1,200 but verifies 1,050 | No automatic refund; carry the credit to the next term. |
| Standard: roll grows a lot mid-term | Supplementary invoice at the same rate; the following term uses the new count. |
| Enterprise: roll grows past the contract assumption | No per-pupil bill; review support/hosting capacity at renewal. |
| School pays twice | Second payment becomes a credit on the next invoice, not a refund by default. |
| Payment lands after suspension | Reactivate immediately; keep the attendance/result data. |
| Two admins submit at once | First submission wins; the second gets “already submitted, here is the revision link”. |
| School changes track mid-term | Upgrade from today, pro-rate the remaining days; downgrade at next renewal. |
| Multi-campus group | One Enterprise contract, several school rows, consolidated invoice. |
| Term dates change after registration | Do not re-price; show the new dates, keep the invoice. |
| School never registers | After day 7, read-only; data remains; a “register now” banner everywhere. |
| Free trial school | No invoice; convert to Standard or Enterprise at trial end. |

---

## 12. Rollout phases

| Phase | What ships | Why |
|---|---|---|
| **0 — today** | Manual slip + proof + landlord approval | keep current customers working |
| **1 — term registration (Standard)** | Signed link, roll review, termly/annual quote, invoice, proof upload, activation | the core ask; per-pupil billing; no gateway dependency |
| **2 — online payment** | Paystack card/transfer, webhook, auto-activation, fee line | removes manual verification for small schools |
| **3 — Enterprise** | Licence + annual invoice + implementation charge, 1,000+ threshold, integration/SLA checklist, provisioning, consolidated multi-campus roll | the premium track; sales-assisted, not self-service |
| **4 — retention** | Annual prepay, value statement, referral credit, win-back | after the core is stable |

Do not start Phase 2 before Phase 1 has been used for a full term; the roll
declaration is the part that must be right, and a gateway only moves money.

---

## 13. Open decisions (owner sign-off required)

| # | Decision | Recommendation | Why it matters |
|---|---|---|---|
| 1 | Plan structure | **Standard (per pupil) + Enterprise (licence)** | one model cannot serve a 60-pupil start-up and a 1,500-pupil group |
| 2 | Standard termly rate | **₦750/pupil per 4-month term** | the “proper” rate after the flat plans; 1.5–3× the old per-pupil economics |
| 3 | Standard annual prepay | **₦500/pupil/term, paid for 3 terms (₦1,500/pupil/year)** — 33% off | cash up front; rewards the school that can pay for the year |
| 4 | Minimum invoices | **₦50,000/term** termly; **₦100,000/year** annual | protects small schools from uneconomic invoices |
| 5 | Enterprise threshold | **1,000+ pupils, multi-campus, or integration requirement** | protects the premium positioning |
| 6 | Enterprise price | **₦1.2m one-off + ₦550k/year (email included)**, 3-year initial term | funds migration/integration; predictable support |
| 7 | Enterprise implementation | **₦300,000 one-off** (server/hosting, migration, training) | the school’s capacity costs money before year 1 |
| 8 | Enterprise email | **Mandatory; included in the ₦550,000 annual fee** | email is too important to be optional |
| 9 | Enterprise inclusions | SSO/API, custom domain, migration, SLA, named success manager, unlimited pupils | justifies the licence |
| 10 | Parent online charge | **Parent pays; ₦200 handling; transfer total capped at ₦500**; school nets full invoice | matches the updated `ProcessingFeeCalculator`; school is not out of pocket |
| 11 | Parent transfer | **No fee; proof upload; bursar verifies** | already built; fallback for schools without a gateway |
| 12 | Edus subscription online charge | **School pays as a separate line**, or Edus absorbs on annual prepay | keeps the SaaS margin visible |
| 13 | Registration deadline | 14 days before term; closes end of week 1 | enough time to fix a roll |
| 14 | Link security | signed token **+ admin login** | a forwarded link must not change a roll |
| 15 | Grace period | 7 days after expiry | avoids punishing a late transfer |
| 16 | Roll variance rule | Standard: 5% or 10 pupils, whichever is larger; Enterprise does not reprice | Standard is per pupil; Enterprise is not |
| 17 | Value statement | every school gets usage + billing statement (PDF/CSV) | answers “what have we generated and what have we paid?” |
| 18 | Roll rule on the pricing page | **Under 600 pupils → Standard (annual); over 800 → Enterprise; 600–800 depends on termly vs annual** — stated above the feature lists | the one comparison a school can act on without doing arithmetic |

---

## 14. Acceptance criteria for Phase 1

- [ ] A school admin can open a term registration link from an email, on a
      phone, and see the correct session, term and roll.
- [ ] **Standard:** the page shows the declared roll and the termly/annual quote; the school can see exactly how the number was reached.
- [ ] **Enterprise:** the page shows the licence/annual status, unlimited pupils, and the renewal date; it never asks the school to count pupils for billing.
- [ ] The annual prepay option shows the 33% saving and is paid before the first term.
- [ ] A 1,500-row CSV can be uploaded without a timeout, with a downloadable
      error file.
- [ ] Submitting twice does not create two invoices.
- [ ] Paying (or uploading a valid proof) activates the term; the results-entry
      screen accepts scores.
- [ ] An expired/unpaid term becomes read-only, and export still works.
- [ ] The landlord can see declared vs verified; a supplementary invoice is
      offered **only** on Standard, never on Enterprise.
- [ ] Every school can open a **Value & Billing** statement showing usage, plan,
      invoices, payments, balance and next renewal; it exports as PDF/CSV.
- [ ] The registration page passes the mobile rules: 320px, 44px targets, no
      horizontal scroll, 16px inputs.
- [ ] Every price, discount and fee is an audit row; no number is recomputed
      after the invoice is issued.

---

## 15. One-line mental model

> **Standard bills the declared roll — termly at ₦750/pupil, or annually at
> ₦500/pupil/term. Enterprise pays a licence, an annual fee and an implementation
> charge, and never counts pupils for the bill. The term unlocks after payment,
> and the Value & Billing statement shows what the school generated and paid.**

---

## 16. Implementation status (18 Oct 2026)

### Built

| Piece | Where |
|---|---|
| `Plan` pricing model | `src/Entity/Landlord/Plan.php` — `pricingModel` (`flat`/`per_student`/`licence`), `termPricePerStudent`, `annualPricePerStudent`, minimums, `licenceFee`, `annualFee`, `implementationFee`, `isPublic`, `features`, `legacyUntil` |
| `TermRegistration` | `src/Entity/Landlord/TermRegistration.php` — roll, track, cycle, **snapshot** unit price / subtotal / discount / total, status, unique `(school, session, term)` |
| Invoice link | `SubscriptionPayment.termRegistration` — the existing slip/proof flow now settles a registration |
| Quote maths | `src/Service/Subscription/TermQuoteService.php` — integer naira, minimums, 33% annual discount, Enterprise totals |
| Plan seeding | `app:landlord:seed-plans` (idempotent; keeps legacy flat plans) |
| School screen | `/subscription` — roll, current status, both tracks, open invoice, registrations |
| Term registration | `/subscription/register` — declare the roll, see the arithmetic, confirm → one invoice |
| Admin form | `PlanType` now edits all the new fields |

### Online payment (Paystack), built 18 Oct 2026

| Piece | Where |
|---|---|
| Gateway client | `src/Service/Payment/PaystackClient.php` — entity-free: initialize, verify, signed webhook |
| Checkout | `POST /subscription/pay/{id}` — invoice + gateway charge, redirect to Paystack |
| Callback | `GET /subscription/payment/callback` — verifies server-side, then activates |
| Webhook | `POST /subscription/payment/webhook` — `charge.success`, HMAC-SHA512, idempotent |
| One activation path | `App\Service\Subscription\SubscriptionActivationService` — used by the callback, the webhook **and** the landlord's manual approval |
| Charge bearer | the **school** pays the gateway fee: 1.5% + ₦100 capped ₦2,000, +7.5% VAT (`ProcessingFeeCalculator::schoolGatewayFeeFor`) |
| Read-only on expiry | `src/EventListener/SubscriptionReadOnlyListener.php` — GET everything, block every write, banner in `base.html.twig` |
| Landlord registrations | `/admin/subscription/registrations` — declared vs counted, variance, tolerance (5% or 10 pupils), verify-roll action |
| School invoices | `/subscription/invoices` — every invoice, status, roll, pay link for open ones |
| Landlord revenue ledger | `/admin/subscription/invoices` — every school, search by school/reference, status filter, collected vs outstanding totals |
| Reminders | `SubscriptionMailer` — invoice reminder from the ledger, renewal reminder from Active Subscriptions (both CSRF-protected POSTs) |
| Overage invoice | “Bill N extra pupils” on a Standard registration outside tolerance → `SubscriptionPayment.supplementary` (settles without granting a term) |
| Reminder schedule | `app:subscription:reminders` — T-14/T-7/T-1 renewals and invoices unpaid for 3 days; `--dry-run`; once per day per row via `lastReminderSentAt` |
| Value & Billing PDF | `/subscription/statement.pdf` — plan, roll, invoiced/settled/outstanding, invoice list, renewal date (Dompdf, A4) |

Rules the code enforces:

- The callback is a **hint**; only `verify()` may settle.
- Amount is compared with the invoice before activation; a mismatch is logged and
  refused (webhook: acknowledged with 200 so Paystack stops retrying).
- Replaying the webhook does not extend a term twice (`activate()` returns false
  when the payment is already `APPROVED` **and** its registration is `PAID`).
- A payment that is `APPROVED` but whose registration is still `INVOICED` (because
  something failed after the money arrived) is **repaired** by the next callback
  or webhook instead of being silently skipped.
- ⚠️ `Subscription::$endDate` is Doctrine `datetime` (**mutable**). Writing a
  `DateTimeImmutable` throws at flush — after the customer has paid. Always convert
  with `\DateTime::createFromInterface()`.
- A period is set from the registration, never from `Plan::durationMonths`: annual
  prepay = 12 months, termly = 4. A freshly created/upgraded subscription counts from
  its start date; an existing one being renewed stacks on its current end date.
- The receipt email is the school's `principalEmail`, not the staff login — a
  gateway rejects non-deliverable addresses such as `qa-billing@edus.test`.
- With no `PAYSTACK_SECRET_KEY`, the online button is hidden and the school falls
  back to bank transfer + proof upload.

**Configure:** `PAYSTACK_SECRET_KEY=sk_test_…` in `.env.local` (gitignored), then
clear the cache. Production webhook URL:
`https://<school-host>/subscription/payment/webhook` — on the server the app must
be reachable from Paystack; locally, the callback is enough.

End-to-end test on 18 Oct 2026 (test mode): ₦50,000 invoice + ₦913.75 charge =
**₦50,913.75** paid on Paystack checkout; callback activated the school as
`ACTIVE` until 18 Feb 2027 (4 months), payment `APPROVED`, no manual step. Signed
webhook replay → `200 OK`, end date unchanged. Signed webhook with a wrong amount
→ `200 OK`, payment stays `PENDING`. Unsigned webhook → `401`.

### Expiry = read-only (built 18 Oct 2026)

`SubscriptionReadOnlyListener` runs on every tenant request:

- **GET/HEAD** always pass; the listener flags the request and `base.html.twig`
  shows a banner: *"Your subscription has ended — this account is read-only."*
  with a link to `/subscription`.
- **POST/PUT/PATCH/DELETE** are stopped before the controller, with a flash and a
  redirect to `/subscription` (JSON callers get `403`).
- Exempt on purpose: `/login`, `/logout`, `/subscription*` (renew + Paystack),
  `/register`, `/apply`, `/payments*`, `/verify`, `/r/`, `/pv/`, `/p/performance`.
  A lapsed school can still renew, still receive applications, and still export.
- No `Subscription` row at all (free/trial accounts) is left untouched — this is
  an enforcement of non-payment, not a paywall.

Verified on Gobarau by moving `end_date` to yesterday: `/students/` still returned
200 with the banner and the Export link; `POST /settings/behaviour-traits/add`
redirected to `/subscription/` and **created no row**. After restoring the date,
the banner disappeared and the same POST reached its controller again.


**Verified** in the browser on a real tenant (Gobarau): roll 8 → termly ₦50,000
(minimum), annual ₦100,000, saving ₦50,000 (33%); 500 pupils → ₦375,000 termly /
₦750,000 annual (−₦375,000). A second submission with an open invoice redirects to
the existing slip and creates **no** second row; a POST without the CSRF token is
rejected with 403. No horizontal overflow and no tap target under 44 px at 320 and
360 CSS px.

**Tests:** `tests/Service/Subscription/TermQuoteServiceTest.php` (8 tests) —
rate × roll, termly minimum, annual discount, annual minimum, Enterprise totals,
zero roll, unconfigured plan.

### Not built yet

- CSV roll upload (`TermRegistrationBatch`) and the downloadable error file.
- Registration-by-email token link (`/subscription/term/{token}`) — schools must
  log in to register today.
- `SubscriptionInvoice` as a separate entity (today the invoice is a
  `SubscriptionPayment` snapshot).
- Value & Billing statement (usage + billing PDF/CSV).
- `PlanRate` price history.
- Enterprise self-serve renewal / licence tracking.
- The reminder **schedule** exists as `app:subscription:reminders` (T-14/T-7/T-1
  renewals + invoices unpaid for 3 days, idempotent per day), but **no cron entry
  is installed** — it must be added on the server (e.g. daily at 08:00).
