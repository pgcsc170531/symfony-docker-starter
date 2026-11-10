# Taking Edus international — decision paper

> **Status: discussion only. Nothing here is implemented.**
> Answer the questions in §8 and I will turn the agreed answers into a phased build plan.
> Written 2026-11-10, after the country/state seeding work.
>
> **§9 is the decision taken on 2026-11-10: build geography + currency only.** Languages,
> RTL, Arabic documents and international gateways wait for a signed customer. Read §9 first.

---

## 1. What is being asked

Three requests, arriving at once:

1. **Geography** — admission must offer every country, with that country's states/regions, not just Nigeria. Interest is concrete in **Iraq**, **West Africa** and **Southern Africa**.
2. **Languages** — the interface *and the printed documents* in **English, French and Arabic**, where each is needed.
3. **Payments** — accept money from schools/parents outside Nigeria; can Paystack carry us, or is there a better option?

They are not one project. They are three programmes that share one dependency — **money and text have to stop assuming Nigeria** — and the order matters, because currency blocks an Iraq launch while staff-side translation does not.

---

## 2. What the code does today (the honest starting point)

| Area | Today | Where | Consequence |
|---|---|---|---|
| Geography | `Country` → `State` → `LGA`, **tenant tables**, seeded with Nigeria only (1 / 37 / 774) | `src/Service/Geo/`, `app:tenant:seed-nigeria` | Nationality dropdown has exactly one option |
| Region concept | `State` is shaped like a Nigerian state; `LGA` is a Nigerian-only third level | `src/Entity/Tenant/State.php`, `LGA.php` | Iraq has governorates, Ghana regions, South Africa provinces — no LGAs at all |
| Labels | Form labels are fixed English: "State of origin", "Local Government Area" | `StudentAdmissionType`, `ParentApplicationType` | Wrong words in every non-Nigerian market |
| Currency | **₦ hard-coded in 98 Twig templates** plus private `money()` helpers in several services | `templates/**`, `SchoolMailer`, `DashboardDataProvider` | An Iraqi school cannot bill in IQD; a French-speaking one cannot show XOF |
| Currency in data | `PaymentIntent.currency` defaults to `NGN`; `PaystackClient` sends `'currency' => 'NGN'` in **three** places | `src/Service/Payment/PaystackClient.php:70,496,544` | Even a correct invoice would be charged in the wrong currency |
| School record | **No** `country`, `currency`, `locale`, `timezone` field | `src/Entity/Tenant/School.php` | The system has no opinion about where a school is |
| Language | `translations/` is **empty**; `default_locale: en`; all strings inline in Twig/PHP | `config/packages/translation.yaml` | There is no i18n to switch on — it has to be built |
| Phone | Nigerian-only: strips trunk `0`, assumes `234`, builds parent login as `<phone>@edus.ng` | `src/Service/Phone/PhoneNumber.php` | An Iraqi or Ghanaian number is mangled, and phone-login is unsafe abroad |
| PDF | **Dompdf** with DejaVu Sans | `src/Service/Pdf/A4PdfRenderer.php` | **Dompdf does not shape Arabic** — letters come out disconnected and unreadable. This is the single biggest technical risk in the whole request |
| Payments | `PaymentGatewayInterface` + `GatewayFactory` + `FakeGateway` already exist; Paystack is one implementation | `src/Service/Payment/` | **Good news** — adding a second processor is a known seam, not a rewrite |
| Offline money | Bank transfer + proof of payment + reconciliation already works | `SettlementService`, bursar screens | A market with no usable PSP can still launch |
| Domains | One `BASE_DOMAIN`, schools get `<subdomain>.edus.ng` | `compose.yaml`, `School.subdomain` | Nothing for `school.edus.iq`; no custom domains |

**One line summary:** the payment layer is already pluggable, the geography layer is close, and the **language + currency + Arabic-PDF** layers do not exist yet.

---

## 3. The three programmes and their real size

| Programme | What it means | Size |
|---|---|---|
| **A. Geography** | every country + its regions, correct labels per country | Small — days, mostly data curation |
| **B. Money** | per-school currency, formatting, invoices, gateways | Medium — touches almost every screen |
| **C. Language** | translator, three locales, RTL, translated documents | **Large** — hundreds of strings, plus a PDF engine change for Arabic |

A is a prerequisite for credibility; B is a prerequisite for an Iraq launch; C is a prerequisite for a *good* Iraq launch and for Senegal/Côte d'Ivoire.

---

## 4. Decisions

Each decision gives the options, my recommendation, and what it costs.

### D1 — How regions are modelled

| Option | Verdict |
|---|---|
| Keep `State` + `LGA`, add `State.kind` (`state`/`region`/`province`/`governorate`) | **Recommended.** No table rename, no data migration, one new column |
| Rename `State` → `Region`, `LGA` → `District` | Cleaner vocabulary, but a rename across entities, forms, APIs, templates and every tenant schema |
| Separate table per level | Over-engineered for a level that is decorative in most countries |

**Recommendation:** add `state.kind` (a string, default `state`) and a per-country **display label** ("Governorate" for Iraq, "Region" for Ghana, "Province" for South Africa, "State" for Nigeria). `LGA` stays, and becomes **optional and Nigeria-only** — the admission form shows it only when the chosen country has LGAs. Cost: ~1 day of code, plus labels per country.

### D2 — Where the data comes from and how it is seeded

| Option | Verdict |
|---|---|
| Curated JSON in the repo (`resources/geo/*.json`), one seeder, idempotent, per-country command | **Recommended.** Reviewable in a PR, versioned with the code, no external API at runtime |
| Fetch from an API at seed time | Fragile: a provider outage or a changed response breaks provisioning |
| Hand-typed arrays in PHP (today) | Does not scale past one country |

**Recommendation:** **all ~250 ISO 3166-1 countries** (the nationality dropdown must never be "Nigeria only" again) + **regions for a priority list** (Appendix A). `app:tenant:seed-geography --country=IQ` seeds one; no flag seeds all. Cost: ~3 days, most of it checking names in the local language.

Why not seed every country's subdivisions (≈4 600)? Storage is trivial, but the *review* is not: a wrong region name is a visible error in a school's own country. Grow the list as markets open.

### D3 — Required fields per country

In Nigeria, state of origin is meaningful. In Iraq, a pupil's governorate is where the family is registered. In Ghana, region. Some markets have neither.

**Recommendation:** keep all three fields **nullable except nationality**, and let the school's country (new field, D4) decide which are *labelled* and *suggested*. Never hard-require a region — an international school in Dubai takes pupils from everywhere, and the "state of origin" of a French pupil is not a dropdown you can fill. Cost: included in D1.

### D4 — Currency (the blocking decision)

| Option | Verdict |
|---|---|
| One currency per school (`School.currency`), stored on every money row | **Recommended** |
| One currency for the whole platform | Breaks the moment a second country is added |
| Currency per invoice only, school has none | Formatting defaults everywhere would be guesswork |

**Recommendation:** add `School.currency` (ISO 4217, default `NGN`), `School.country` (ISO 3166-1 alpha-2), `School.locale`, `School.timezone`. Introduce **one** money formatter (`App\Service\Money\Money`) using PHP `intl` (already installed in the image): `money(1234.5)` → `₦1,234.50`, `IQD 1,234.500`, `1 234,50 €`. Remove the ₦ literal from the 98 templates and the private `money()` helpers — they are already duplicated five different ways.

Store currency on `Invoice` and `PaymentIntent` (the intent already has the column, default `NGN` → must come from the school).

**Not in scope:** converting between currencies, FX rates, or a school billing in two currencies in one term. A school bills in its own currency; that is the whole model.

Cost: **8–12 days** (the templates are the work, not the service).

> ⚠️ Note the IQD detail: Iraqi dinars are quoted to **three** decimal places. A money formatter that assumes 2 will be wrong by 100× on rounding rules. This is exactly why the formatter has to be one class, not 98 literals.

### D5 — Locale, translation and scope

| Option | Verdict |
|---|---|
| Symfony Translator + ICU messages, `School.locale`, per-user override | **Recommended** — the framework answer |
| Per-locale duplicated templates | Unmaintainable |
| Third-party translation SaaS at runtime | Adds a network dependency to every page render |

**Recommendation — phased, because doing all of it at once is a trap:**

- **Phase 1 (infrastructure, ~5–7 d):** `School.locale` (`en`/`fr`/`ar`), locale resolution per request, `translations/messages.{en,fr,ar}.yaml`, `dir` on `<html>`, a language switcher on the public site and login.
- **Phase 2 (parent-facing only, ~15–20 d):** public site, apply flow, parent portal, receipts, invoices, statements, report cards. This is what a parent in Baghdad or Dakar actually reads, and it is a bounded set of templates.
- **Phase 3 (staff UI, later):** the admin/bursar screens. Staff at an international school often work in English; do not let this block a launch.

Translation itself is **not** an engineering task: budget a native reviewer per language, and treat machine-only output as a draft. Arabic is right-to-left, and a bad translation is more damaging than an English screen.

### D6 — Arabic and RTL

Tailwind is the CDN build, so RTL cannot be switched by a config flag; it is a **template discipline** question:

- `dir="rtl"` on `<html>` when locale is `ar`.
- Prefer **logical** utilities (`ms-*`/`me-*`, `ps-*`/`pe-*`, `start-*`/`end-*`, `text-start`) over left/right, and fix the current `left-*`/`right-*` uses as we touch screens.
- Numbers, dates and money stay **Western digits** unless a school asks otherwise — Arabic-Indic digits in a fees table confuse more than they help.
- Icons that imply direction (arrows, "next") must flip.

Cost: **8–12 days** for the parent-facing surface, plus a visual pass on 320/360 px in RTL. Staff UI later.

### D7 — Arabic in PDF (the biggest technical risk)

Dompdf **does not do Arabic shaping or bidi reordering** — it renders isolated, unjoined letters. There is no configuration that fixes it. Options:

| Option | Verdict |
|---|---|
| **mPDF** for AR/FR documents, Dompdf stays for EN | **Recommended.** mPDF has Arabic (OpenType shaping) built in, keeps A4/HTML templates, and `A4PdfRenderer` is already the single seam every document goes through |
| Headless Chromium for PDFs | Best fidelity, but adds ~400 MB to the image and a second runtime to operate |
| Keep Dompdf and accept broken Arabic | Not shippable — the report card is the product |

**Recommendation:** `A4PdfRenderer` picks the engine by locale: Dompdf (current, unchanged) or mPDF (ar). The document templates stay HTML/CSS; they need a **layout pass** in mPDF because the current ones are tuned to Dompdf's limitations (no flexbox, fixed-height tables). Budget verification on every document: report card, transcript, statement, invoice, receipt, admission letter — **each one, in Arabic, page count and QR footer included**.

Cost: **5–8 days** + one round of template re-tuning.

### D8 — Phones, logins and identity

`PhoneNumber` assumes Nigeria: strips the trunk `0`, prefixes `234`, and builds parent logins as `<phone>@edus.ng`.

**Recommendation:** store **E.164** (`+9647712345678`, `+233201234567`) with the dialling country code taken from the pupil's country; keep the Nigerian display form where the school is Nigerian. Phone-derived logins keep working for existing accounts but **new non-Nigerian accounts get an email login** — a phone number is not a safe identity across borders, and SMS delivery is the next problem (Termii is Nigeria-focused).

Cost: **3–4 days**, plus choosing an SMS provider per market (out of scope here, but the same pattern as payments).

### D9 — Dates, numbers, time

- One `School.timezone` (default `Africa/Lagos`); "today", terms and reminders are computed in it.
- Dates and numbers formatted through `intl` per `School.locale` — `01/02/2026` means different days in Lagos and Paris.
- Week start (Sunday vs Monday) affects the timetable and calendar screens.

Cost: **2–3 days**.

### D10 — Payments (the commercial question)

First, separate the **two money flows** — they have different answers:

**(a) Schools paying Edus** (subscription). Edus is the merchant. If Edus is a Nigerian company, Paystack can charge **cards from anywhere**, but the *merchant account* must be in a country Paystack onboards. As at Nov 2026 Paystack's own pricing pages list **Nigeria, Ghana, South Africa, Kenya and Côte d'Ivoire** — **Iraq is not one of them**, and no amount of integration changes that: it is a licensing/entity question.

Options for Edus's own subscriptions:

| Option | Fit |
|---|---|
| **Paystack (current)** | Keep for NG/GH/ZA/KE/CI. Cheapest, already built |
| **Paddle / Lemon Squeezy (merchant of record)** | **Recommended for everything else.** They sell as the merchant, handle VAT/sales tax and payouts to a Nigerian company; cards worldwide. More expensive per transaction, no Iraqi entity needed |
| Stripe direct | Not available to a Nigerian entity without a foreign company |
| Manual invoice + bank transfer | Fine for the first five Iraqi schools; not a business |

**(b) Parents paying a school's fees.** Here the merchant is the **school**, and the money must land in the school's own country. That is a per-market decision:

| Market | Realistic collection |
|---|---|
| Nigeria, Ghana, South Africa, Kenya, Côte d'Ivoire | **Paystack**, school's own subaccount (already built) |
| Iraq | Card penetration is low. **ZainCash**, **FIB (First Iraqi Bank)**, **Asia Hawala**, Qi Card are what parents actually use. Onboarding needs a local entity and Arabic paperwork |
| Senegal, Mali, Burkina, Niger | Mobile money (Orange Money, Wave) via a regional aggregator; school bank transfer until then |

**Recommendation for launch:** in Iraq, **ship v1 with offline transfer + proof of payment** — that path already exists (`SettlementService`, bursar confirmation) and is how many Iraqi schools collect today — and add a local wallet adapter (ZainCash first) behind the existing `PaymentGatewayInterface` in a later phase. Do not block the Iraq launch on a PSP integration.

Good news: the abstraction exists (`PaymentGatewayInterface`, `GatewayFactory`, `FakeGateway`), so a second processor is an **adapter + config + webhook route**, roughly **6–10 days per gateway** — not a redesign. What must change first is the NGN assumption in `PaystackClient` (three literals) and `PaymentIntent.currency`.

### D11 — Domains

- Today: `<subdomain>.edus.ng` for every school, from one `BASE_DOMAIN`.
- Iraq/French-speaking markets read `edus.ng` as a Nigerian product; for a *credibility* launch you want `edus.iq`, `edus.sn`, or the school's own domain.
- The app already keys the tenant off the host, so a second base domain is mostly configuration + certificate work; **custom school domains** need a verification flow (CNAME + per-host certificate) — that is a real feature, not configuration.

**Recommendation:** launch on `edus.ng` subdomains, add a **country domain per market** when the first paying school signs (2–3 days each), and treat custom domains as a separate paid feature later.

### D12 — Data, residency and honesty

Schools outside Nigeria will ask where their children's data lives. Today: one Oracle instance (wherever it is provisioned) and one MySQL volume. There is no per-country residency, and building it is a different project.

**Recommendation:** state it plainly in contracts ("data is hosted in <region>"), keep taking encrypted backups, and revisit residency only when a customer makes it a condition. Do not claim compliance (GDPR/PDPL) that the platform does not yet evidence — an audit trail and retention policy come first.

---

## 5. Recommended sequencing

| Phase | Contents | Why this order | Est. |
|---|---|---|---|
| **0. Decide** | Answers to §8 | Everything below depends on them | — |
| **1. Geography** | D1, D2, D3 — all countries, regions for the priority list, per-country labels | Cheap, visible, unblocks credible demos | **4–6 d** |
| **2. Money** | D4, D9 — `School.currency/country/timezone`, one formatter, invoices carry currency | **Iraq cannot bill in IQD without it.** Blocks everything | **10–14 d** |
| **3. Language core** | D5 phase 1, D6 for public/parent surfaces | Parent-facing first; staff UI can wait | **13–19 d** |
| **4. Arabic documents** | D7 — mPDF engine for `ar`, every document verified | The report card is the product | **5–8 d** |
| **5. Launch gate** | D8 phone/identity, D11 domain, load + RTL QA | | **5–7 d** |
| **6. Payments** | D10 (b) local wallet adapter, (a) Paddle for non-Paystack countries | Can follow the first paying school | **6–10 d each** |

**First international launch (Iraq, English + Arabic, IQD, offline payments): roughly 35–50 engineering days**, excluding translation review and landline-level QA on real devices. A France/Arabic-only "demo" is possible after phases 1–3 (**~27–39 d**).

---

## 6. Risks worth naming now

| Risk | Impact | Mitigation |
|---|---|---|
| **Arabic PDF** (Dompdf cannot shape) | Report cards unreadable — the product fails in its main market | mPDF path, verified document by document |
| **Currency precision** (IQD 3 decimals) | 100× errors on rounding | one money class, tests for IQD/XOF/NGN |
| **Translation quality** | A badly translated French portal is worse than English | native review, parent-facing first |
| **RTL regressions** | Broken layouts on Arabic mobile | logical utilities, 320/360 px RTL pass |
| **Cross-border collections** | Money cannot legally land where expected | school-local gateways + MoR for Edus's own billing |
| **Scope creep into "multi-currency school"** | Never ships | one currency per school, explicitly |
| **Termii (SMS)** | Notifications fail outside Nigeria | provider per market, same adapter pattern |

---

## 7. What I would **not** do

- **Not** translate the staff UI in the first release. It doubles phase 3 for little commercial gain.
- **Not** seed every subdivision on earth up front. Correct names in the countries we sell in.
- **Not** build multi-currency invoicing, FX conversion or a tax engine.
- **Not** claim GDPR/PDPL/PCI compliance. The gateway carries PCI; residency is a separate programme.
- **Not** rename `State`/`LGA` tables. A migration with no user-visible benefit, on a live multi-tenant fleet.

---

## 8. Questions — please answer these

| # | Question | My default if you say "use your judgement" |
|---|---|---|
| Q1 | Which countries are **launch** markets vs "someday"? (I assume: Iraq, Ghana, Côte d'Ivoire/Senegal, South Africa) | Iraq + the three Paystack-supported ones |
| Q2 | Per school, one currency — agreed? | Yes |
| Q3 | Which **first launch** language pairs? (`en`+`ar` for Iraq; add `fr` when Senegal/CI signs) | `en`+`ar` first, `fr` in the same release if a French market is signed |
| Q4 | **Staff UI** translated too, or parents-only at launch? | Parents-only at launch |
| Q5 | Arabic report cards must be **native-quality** at launch, or is a later phase acceptable to the first school? | Native at launch — it is the product |
| Q6 | Is Edus willing to open a **Paddle (or similar MoR)** account for its own subscriptions? | Yes, and keep Paystack for NG/GH/ZA/KE/CI |
| Q7 | Iraq v1 may collect by **bank transfer with proof** (no gateway) — acceptable? | Yes |
| Q8 | One `edus.ng` subdomain per school at launch, country domains later? | Yes |
| Q9 | Who provides **native reviewers** for French and Arabic? | Client/owner side, per language |
| Q10 | Budget expectation for the first international launch (cash, not just days)? | Land after answer — translation + mPDF + testing are the cash items |

---

## Appendix A — region data for a credible first list

| Country | Regions | Count | Label in that country |
|---|---|---|---|
| Iraq | Governorates | 18 | Governorate |
| Nigeria | States + FCT | 37 | State (*already seeded*, + 774 LGAs) |
| Ghana | Regions | 16 | Region |
| Côte d'Ivoire | Districts | 14 | District |
| Senegal | Regions | 14 | Région |
| South Africa | Provinces | 9 | Province |
| Cameroon | Regions | 10 | Region |
| Benin, Togo, Burkina Faso, Mali, Niger, Guinea, Sierra Leone, Liberia, Gambia, Mauritania | Regions | ~150 total | Region |
| Saudi Arabia | Regions | 13 | Region |
| United Arab Emirates | Emirates | 7 | Emirate |
| Qatar | Municipalities | 8 | Municipality |
| Jordan | Governorates | 12 | Governorate |
| Lebanon | Governorates | 8 | Governorate |
| Egypt | Governorates | 27 | Governorate |

≈ **250 countries** for nationality + ≈ **340 regions** across the 19 countries above. That is a few hundred rows per tenant — free in storage, easy to review.

## Appendix B — shape of the work (no code written)

- `resources/geo/countries.json` — ISO 3166-1: `{code, name_en, name_fr, name_ar, dial_code, currency, region_label}`
- `resources/geo/regions/NG.json`, `IQ.json`, … — `{code, name, local_name?}`
- `app:tenant:seed-geography [--country=IQ] [--school=gakprimary]` — idempotent, matches on code then name
- `School.country`, `School.currency`, `School.locale`, `School.timezone` + one migration + `app:tenant:update-schema`
- `App\Service\Money\Money::format($amount, $currency, $locale)` — replaces ₦ and the five private `money()` helpers
- `A4PdfRenderer` gains an engine choice keyed on locale (Dompdf → mPDF for `ar`)
- `PaymentGatewayInterface` gains a second implementation per market; nothing else changes

---

**Next step:** answer §8 (or tell me to proceed with the defaults) and I will rewrite this as a phased implementation plan with tickets, files touched and acceptance checks per phase.

---

## 9. Decision taken (2026-11-10) — ship geography + currency, defer the rest

**The owner's reasoning, which is correct:** a school in Iraq showing interest, or asking for a
trial, is not a customer. Building Arabic RTL, translated reports and a local payment processor
for a prospect who may never sign is spending the scarcest resource — engineering time — on a
maybe. Country and currency, by contrast, are cheap and make the product *usable and credible*
in that country today.

### In scope now (the "international-ready" slice)

| # | Work | Why it cannot wait | Est. |
|---|---|---|---|
| A1 | All ~250 countries in the nationality/school-country list; regions for the markets showing interest (Iraq + the Paystack countries first) | Right now the dropdown says "Nigeria" and nothing else — that is the first thing a foreign school sees | 4–6 d |
| A2 | Per-country region labels (`State` / `Region` / `Governorate` / `Province` / `Emirate`) and LGA optional/Nigeria-only | "State of origin" and "LGA" read as a system built for one country | 1 d |
| B1 | `School.country`, `School.currency`, `School.timezone`; one `Money` formatter replacing the ₦ literals; invoices/intents carry currency | An Iraqi school must bill in **IQD** (3 decimals — a 2-decimal assumption is 100× wrong). This is the minimum for the system to be *usable* there | 10–14 d |
| B2 | Phone safety: store non-Nigerian numbers as entered/E.164 instead of mangling them through the `234` assumption | Otherwise the first real pupil record entered abroad is corrupt | 1–2 d |

**Total: roughly 16–23 engineering days** — the price of being able to say "yes, we run in your
country, in your currency" at a demo, with everything else honestly marked as roadmap.

### Out of scope until a customer signs

- `fr` / `ar` interface translation, RTL layout, language switcher
- Arabic PDF rendering (mPDF) and the document re-tuning that comes with it
- Any new payment gateway (ZainCash, FIB, Asia Hawala, mobile money)
- Country domains (`edus.iq`) and custom school domains
- SMS provider per market, data residency

### What a demo must say out loud

So that interest is not mistaken for a sale — and so nobody feels misled later:

- The interface is **English** today; Arabic and French are on the roadmap.
- Fees are recorded and invoiced in the school's **own currency**, but online card payment is
  **Nigeria/Ghana/South Africa/Kenya/Côte d'Ivoire** only; elsewhere it is **bank transfer with
  proof of payment**, confirmed by the bursar — which is how many schools already collect.
- Report cards and invoices print in English until the Arabic document work ships.

### The trigger to build the rest

Invest in §5 phases 3–6 when **any** of these happens:

1. a school **signs and pays** (not "trials"), or
2. **three** serious prospects in one non-English market, or
3. one buyer explicitly makes Arabic (or French) documents a condition of the contract.

At that point the estimates in §5 stand: language core 13–19 d, Arabic documents 5–8 d, then a
gateway per market at 6–10 d each. Nothing here is wasted work — the `Money` class, the
geography data and the existing `PaymentGatewayInterface` are the foundation those phases build on.
