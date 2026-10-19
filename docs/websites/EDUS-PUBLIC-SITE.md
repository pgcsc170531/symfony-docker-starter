# The Edus public website — audit & rebuild plan

**Status:** discussion draft. Nothing here is built yet.
**Date:** 18 Oct 2026.
**Audience:** owner (sign-off), then whoever builds the pages.
**Related:** `docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md` §2D (the roll rule),
`docs/architecture/UI-SPEC.md`, `docs/architecture/MOBILE-FIRST.md`.

> This document exists because the site should be **rebuilt on purpose**, not
> repainted. It records what is there now, what is wrong with it, what the new
> site is for, and the order we change it in. The `/pricing` page described in §6
> is the first slice and can ship on its own.

---

## 1. What exists today (checked 18 Oct 2026)

| Route | Template | Purpose |
|---|---|---|
| `/` | `templates/landlord/home/index.html.twig` | Marketing home — 9 sections, ~660 lines |
| `/features` | `templates/landlord/home/features.html.twig` | Feature tour with screenshots |
| `/partners` | `templates/landlord/home/partners.html.twig` | Agency/partner programme + commission |
| `/manual` | `templates/landlord/home/manual.html.twig` | User manual (long-form) |
| — | `templates/base.html.twig` | Public pages share the app base (no shell when signed out) |

Everything is hosted on `%app.base_domain%`; tenant subdomains fall through to the
school site (§ other document).

**Home page sections, in order**

1. Announcement bar ("Edus 2.0 is live")
2. Sticky dark navbar
3. Hero ("Don't just track fees. **Run your entire school.**") + screenshot
4. Module marquee
5. Heritage ("This system didn't start yesterday")
6. What's new in Edus 2.0
7. The complete feature catalogue (**~270 lines of markup**)
8. Bento "small details"
9. Pain points ("Are you still running your school the hard way?")
10. Final CTA + footer

**There is no pricing page.** The nav has Features, What's New, Screenshots,
Partners, User Manual, Sign In, Start Free Trial (WhatsApp link).

---

## 2. Audit — what is actually wrong

### 2.1 Commercial gaps (the expensive problems)

| # | Problem | Why it costs money |
|---|---|---|
| 1 | **No pricing page.** Price is only available by WhatsApp or inside the billing doc. | Every serious buyer looks for a price before contacting sales. A missing price reads as "too expensive to publish". |
| 2 | **No "which plan fits me?" answer.** The product now has two tracks (per-pupil, licence) and nobody on the site explains the choice. | The roll rule (600/800) is the single best sales asset we have and it is invisible. |
| 3 | **No proof.** No named schools, no testimonials, no case study, no usage numbers on the public site. | Nigerian proprietors buy from people like them. Stock screenshots do not substitute. |
| 4 | **No security / data-ownership page.** | A bursar's first objection is "who can see my fees and my parents' data?" |
| 5 | **No FAQ.** Term length, mid-term joiners, roll changes, exports, what happens when a subscription expires. | These are the actual sales calls. |
| 6 | **Partner page leads with commission**, not with what the agency can sell. | Attracts commission-hunters instead of operators. |

### 2.2 Content & structure

| # | Problem | Evidence |
|---|---|---|
| 7 | The feature catalogue is a wall. | Section 5 is ~270 lines of markup; a reader scrolls past it. |
| 8 | Two "what's new" areas (announcement + section 4) say the same thing. | Repetition without progression. |
| 9 | Headline copy is good, but the page never states **who it is for** (primary? secondary? group of schools?). | One sentence would fix it. |
| 10 | Screenshots are full-page PNGs from a local dev server. | `public/images/manual` is **8.8 MB**; largest file 332 KB; captures show `gobarau.localhost:8082` in the browser frame. That reads as unfinished to a buyer. |
| 11 | No sitemap, robots policy or internal linking between marketing pages beyond the nav. | Weak discoverability. |

### 2.3 Technical & design debt

| # | Problem | Evidence |
|---|---|---|
| 12 | **No SEO metadata.** No `<meta name="description">`, no Open Graph, no Twitter card, no canonical, no JSON-LD. | `templates/base.html.twig` has viewport + favicon only. Every share on WhatsApp — the main channel — renders a bare link. |
| 13 | **Tailwind is the runtime CDN.** | Browser console warns: "cdn.tailwindcss.com should not be used in production". Adds a blocking script and a build-time JIT on every visit. |
| 14 | **Palette drift.** `slate-950`, `blue-600`, `indigo-600`, `cyan-300`, `gray-*` and `accent-*` all appear on the same page. | Marketing pages are the least tokenised in the repo. |
| 15 | Design is desktop-first in places: `lg:grid-cols-12` hero with a `lg` screenshot column; the mobile experience is the fallback rather than the design. | Violates the repo's prime directive. |
| 16 | Heavy decorative animation (`float-slow`, marquee, reveal-on-scroll) with no `prefers-reduced-motion` for the marquee. | Motion is polished but costly on a cheap Android. |
| 17 | No analytics or conversion events. | We cannot say which CTA produces trials. |

### 2.4 What is genuinely good — keep it

- The **hero proposition** ("Don't just track fees. Run your entire school.") is sharp.
- The **heritage** angle ("didn't start yesterday") is a real differentiator for a
  market where most competitors are two years old.
- The **brand mark** is used correctly in the nav (no filled tile).
- The **partner economics** are concrete (setup fees 100% to the agency, 20% recurring).
- The **manual** is a genuine trust asset; almost no competitor publishes one.

---

## 3. What the site is for

Not "describe the software". Three jobs, in priority order:

1. **Convince a proprietor in under 60 seconds** that Edus runs a whole school, not
   just fees.
2. **Answer "how much?" without a phone call** — and answer it honestly.
3. **Produce a trial or a WhatsApp conversation** from a phone, one-handed, on a
   slow connection.

### Success measures (pick one primary)

| Measure | Why |
|---|---|
| **Pricing page → trial CTA click rate** | proves the price is defensible in public |
| Pricing page → WhatsApp click | the real sales channel today |
| Bounce on `/` at ≤ 360 px | proves the mobile rebuild worked |

No analytics exists yet, so step 0 is a lightweight, cookie-free page-view +
outbound-click counter (server-side, no third-party script) or Plausible-style
self-host. Decide in §9.

---

## 4. Proposed information architecture

```
/                 Home — proposition, proof, product in 3 screens, price teaser
/features         Product tour, grouped by job (Admissions, Fees, Results, …)
/pricing          Two tracks + roll rule + calculator + FAQ      ← build first
/schools          Case studies / named schools (even 3 is enough)
/security         Data ownership, isolation, backups, who can see what
/partners         Agency programme (rewritten around selling, not commission)
/manual           User manual (unchanged, linked from pricing FAQ)
/contact          WhatsApp, phone, email, demo request
/login            Sign in
```

Nav (desktop): Features · Pricing · Schools · Partners · Manual · **Sign in** ·
**Start free trial**. On mobile the same list in a drawer, with two full-width
buttons at the bottom (Start free trial, Sign in).

---

## 5. Design direction

Constraints from the existing system, not a new style:

- **One accent, one grey ramp.** `accent-*` for every interactive element, `ink-*`
  for every grey. The marketing site currently uses five blues; converge as pages
  are rewritten, page by page.
- **Light surface, not dark.** The dark hero is dramatic but the rest of the site
  is white; pick one and commit. Recommendation: keep a **deep ink hero** only on
  the home page, and use light surfaces everywhere else including pricing.
- **Type scale:** one display size (40/48 px), one section heading (28/32), body
  16/17 with 1.6 line-height. No `text-6xl` on a 320 px screen.
- **Photography over screenshots.** Real school photographs (with permission)
  beat a browser frame of a local dev URL. Screenshots stay on `/features`, cropped
  and sized for mobile.
- **The brand mark lives in the nav and the footer.** Never in a filled rounded
  tile. The footer carries `▮ Edus — The school operating system` + © line.
- **Motion:** reveal-on-scroll allowed; marquee and float removed or gated behind
  `prefers-reduced-motion`. Target: no animation on a `prefers-reduced-motion`
  device and no layout shift.
- **Mobile first, literally:** design each section at 360 px first, then add
  `sm:`/`lg:`. No horizontal scrolling at 320 px. Tap targets ≥ 44 px. Inputs ≥ 16 px.

---

## 6. The `/pricing` page — specification

This is the first shippable slice and the page the owner has already signed off on
conceptually (roll rule from `SUBSCRIPTION-AND-TERM-REGISTRATION.md` §2D).

### 6.1 Structure (top to bottom, mobile order)

1. **H1:** "Priced by your roll, not by a sales call."
2. **Roll-rule callout** (the sentence that sells):
   > **Under 600 pupils, Standard is cheaper. Over 800 pupils, Enterprise is
   > cheaper. Between 600 and 800 it depends on how you pay.**
   With the honest caveat: the 800 rule is a **3-year** rule — Enterprise bills
   more in year 1.
3. **Two plan cards**, one column on mobile, side by side from `md:`:
   - **Standard** — ₦750/pupil per 4-month term, or **₦1,500/pupil/year** paid up
     front (33% off). Minimums ₦50,000/term, ₦100,000/year. Unlimited staff.
   - **Enterprise** — **₦1,200,000** one-off licence + **₦550,000/year** (email
     included) + **₦300,000** implementation. Unlimited pupils.
4. **Calculator** (client-side, no request):
   - one input: number of pupils;
   - shows three numbers: Standard termly per year, Standard annual, Enterprise
     over 3 years;
   - one plain sentence: *"At 720 pupils, annual Standard costs ₦3.24m over three
     years; Enterprise costs ₦3.15m."*
   - must round the same way as the billing code, and must never show a saving the
     billing engine would not honour.
5. **"Which is cheaper?" table** — the 4-band crossover table (under 467 / 467–700
   / 701–799 / 800+), stated in naira, not formulas.
6. **What is included / what is not** — including the honesty that gateway charges
   on **parent** payments (₦200 handling, capped ₦500 on transfer) are separate
   from the subscription.
7. **FAQ** (accordion, real questions): term length, mid-term joiners, roll changes
   (5% or 10 pupils), what happens at expiry (read-only, never deleted), data
   export, email included, implementation, multiple campuses.
8. **CTA band:** WhatsApp "Start free trial" + "Book a 20-minute walkthrough".

### 6.2 Pricing must have ONE source

Do **not** hard-code the numbers in the template. The same figures will be used by
the billing engine. Proposal:

- `src/Service/Pricing/PricingCatalog.php` — constants/array for rates, minimums,
  licence, annual, implementation, discount.
- `templates/pricing/_tiers.html.twig` and `_roll_rule.html.twig` — includes used
  by `/pricing`; the calculator reads its numbers from a single `data-` JSON blob
  rendered from `PricingCatalog`.
- Admin `Plan` rows remain the billing record; `PricingCatalog` is the public
  catalogue and must be validated against it in a test.

That prevents the classic failure: the website says ₦1,500 and the invoice says
₦1,650.

### 6.3 Copy rules

- Naira only, `₦` prefix, `number_format` without decimals on the page.
- Never say "unlimited" and then meter something in the same card.
- The word **"term"** is always "4-month term" on first use.
- No "contact us for pricing" anywhere on the page — that is the whole point.

---

## 7. Work plan (phased)

| Phase | Work | Outcome |
|---|---|---|
| **0** ✅ | `PricingCatalog` + `/pricing` route + template + calculator + FAQ | **built 18 Oct 2026** — see §7.1 |
| **1** ✅ | Meta/OG/Twitter/JSON-LD per page, sitemap.xml, robots.txt, WhatsApp preview image | **built 18 Oct 2026** — see §7.2 |
| 2 | Palette + type convergence on home and features; remove marquee/float; mobile pass at 320/360 | site stops looking like a different product |
| 3 | Proof: 3 named schools, 1 case study, 1 testimonial, real photographs | trust |
| 4 | `/security` + `/contact` + FAQ expansion | objection handling |
| 5 | Replace Tailwind CDN with a built stylesheet for public pages | performance + production correctness |

Each phase is independently shippable. Phase 0 is the one to do now.

### 7.1 Phase 0 — what was built

| File | Role |
|---|---|
| `src/Service/Pricing/PricingCatalog.php` | the only place a price is typed; email fee is part of the Enterprise annual constant |
| `src/Controller/Landlord/HomeController.php` | `app_pricing` route on `%app.base_domain%` |
| `templates/landlord/home/pricing.html.twig` | hero, roll rule, plan cards, calculator, crossover table, exclusions, FAQ, CTA |
| `config/packages/security.yaml` | `^/pricing` is `PUBLIC_ACCESS` (it was falling through to `ROLE_USER` and redirecting to `/login`) |
| `tests/Service/Pricing/PricingCatalogTest.php` | 9 tests / 61 assertions locking totals, discount, minimums, crossovers and the roll bands |

Verified at 288 and 1024 CSS px in the integrated browser: no horizontal overflow
(`scrollWidth - innerWidth = -15`), no tap target under 44 px, input renders at 16 px,
and the calculator agrees with the published table at 599 / 600 / 799 / 800 / 900 pupils.

> Local URL note: `BASE_DOMAIN` is `localhost` in `compose.yaml`, so the marketing
> site is `http://localhost:8082/pricing` while a school is
> `http://<subdomain>.localhost:8082/`.

Still to do in this phase: the page has **no meta description / OG tags yet** (Phase 1)
and no "Schools" proof section (Phase 3).

### 7.2 Phase 1 — what was built

| File | Role |
|---|---|
| `templates/base.html.twig` | `<html lang>`; a `meta_description` block, `canonical` (query string dropped), full OG/Twitter tags, `og:image:alt`, an `og_type` block and a `structured_data` block. Defaults mean **every** page — including tenant pages — now shares with a title, description and image. |
| `templates/landlord/home/{index,features,partners,pricing}.html.twig` | per-page description, `title_suffix` cleared (no more “Pricing — Edus - Edus School SaaS”), and JSON-LD |
| `src/Controller/Landlord/SeoController.php` | `/sitemap.xml` and `/robots.txt`, both pinned to `%app.base_domain%` |
| `templates/landlord/seo/{sitemap.xml,robots.txt}.twig` | sitemap of the five public pages; robots disallows `/admin`, `/agent`, `/login`, `/dashboard` and points at the sitemap |
| `config/packages/security.yaml` | `^/sitemap\.xml` and `^/robots\.txt` are `PUBLIC_ACCESS` |
| `bin/generate-og-image.php` | renders `public/images/brand/og-edus.png` (1200 × 630, 48 KB) with dompdf's DejaVu fonts — re-run when the wordmark changes |

Structured data:

- `/` → `Organization` (name, url, logo, areaServed NG, sales contact).
- `/pricing` → `SoftwareApplication` with five `Offer` entries read from `PricingCatalog`
  (₦750 termly, ₦1,500 annual, ₦1.2m licence, ₦550k annual, ₦300k implementation).

Verified with `curl`: correct `<title>`, description, canonical, OG/Twitter tags on
`/`, `/features`, `/partners`, `/pricing`; both JSON-LD blocks parse; `robots.txt`
and `sitemap.xml` return the right content type; `og-edus.png` serves 200.

Still open: nothing blocking. Tenant pages used to inherit the marketing `<meta>`;
they now override title suffix, description, OG image and JSON-LD in
`tenant/home/layout.html.twig`, and `Tenant\HomeController` serves a per-school
`sitemap.xml` / `robots.txt`. See `SCHOOL-PUBLIC-SITE.md` §7 for the
sitemap-vs-robots rule that keeps `/apply` indexable while blocking
`/apply/verify/{id}`.

**Nav link added 18 Oct 2026:** `/pricing` is now in the desktop and mobile nav plus
the footer of `/`, `/features` and `/partners` — the page was previously reachable
only by typing the URL.

---

## 8. Mobile-first acceptance (run on every page touched)

- [ ] No horizontal scroll at 320 px and 360 px (`scrollWidth - innerWidth <= 0`)
- [ ] Every button/link ≥ 44 px tall on a phone
- [ ] No input below 16 px
- [ ] Primary CTA full width on a phone (`w-full sm:w-auto`)
- [ ] Table (pricing comparison) wrapped in `overflow-x-auto`, `min-w-[640px]`
- [ ] Works with images blocked/slow: hero text readable without the photo
- [ ] No layout shift when fonts/screenshots load (width/height set)
- [ ] Tested at 320 px **and** 1024 px

---

## 9. Open decisions (owner sign-off)

| # | Decision | Recommendation | Why |
|---|---|---|---|
| 1 | Publish real prices? | **Yes**, all three tracks, on `/pricing`. | Price opacity is the biggest leak in the funnel. |
| 2 | Show Enterprise implementation fee publicly? | **Yes**, as a separate line. | Hiding it later feels like a bait-and-switch. |
| 3 | Calculator on the page? | **Yes**, client-side, no request. | The roll rule becomes personal in one keystroke. |
| 4 | Case studies now or later? | **3 short ones now** (one small, one mid, one group). | Proof beats copy. |
| 5 | Dark hero or light? | **Dark hero on home only**, light everywhere else. | Keeps drama where it belongs. |
| 6 | Analytics | Self-hosted, cookie-free page view + outbound click. | Needed to judge this rebuild; avoid consent banners. |
| 7 | Tailwind build vs CDN | **Build** for public pages in Phase 5. | Removes a production warning and a blocking script. |
| 8 | Pricing copy source | `PricingCatalog` service, mirrored into a test against `Plan`. | Website and invoice must never disagree. |
