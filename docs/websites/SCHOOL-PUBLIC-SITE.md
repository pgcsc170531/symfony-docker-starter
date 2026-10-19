# The school public website — audit & rebuild plan

**Status:** discussion draft. Nothing here is built yet.
**Date:** 18 Oct 2026.
**Audience:** owner (sign-off), then whoever builds the pages.
**Related:** `docs/websites/EDUS-PUBLIC-SITE.md`, `docs/parent-application/DESIGN.md`,
`docs/architecture/MOBILE-FIRST.md`, `docs/architecture/UI-SPEC.md`.

> Every school gets a public site at `school.edus.ng` (locally
> `school.localhost:8082`). For most schools **this page is the school's website** —
> they do not have another one. It therefore has to do a different job from the
> Edus marketing site: it is not selling software, it is persuading a parent to
> trust the school and apply.

---

## 1. What exists today (checked 18 Oct 2026)

| Route | Template | Purpose |
|---|---|---|
| `/{subdomain}/` | `templates/tenant/home/index.html.twig` | Home: hero carousel, stats, about preview, programmes, why-us, visit, enrol CTA |
| `/{subdomain}/about` | `.../about.html.twig` | Story, values, contact details, map |
| `/{subdomain}/admissions` | `.../admissions.html.twig` | Apply online (application mode), how to apply, documents, office |
| `/{subdomain}/contact` | `.../contact.html.twig` | Address / phone / email / website cards, link to admissions |
| shared | `.../layout.html.twig` | Sticky header, mobile menu, footer |

It extends `base.html.twig`, which gives it the design tokens and the public-page
treatment. It already **reads real school data**, not hard-coded content:

`name`, `motto`, `logoFilename`, `address`, `phoneNumber`, `email`, `website`,
`primaryColor`, `heroTagline`, `heroImageFilename`, `aboutContent`,
`admissionsIntro`, `mapEmbedUrl`, `facebookUrl`, `twitterUrl`, `instagramUrl`,
`applicationsOpen`, `applicationClassesNote`, `applicationFeeAmount`.

That is a better foundation than most school websites. The problem is not the
data — it is the shop window.

---

## 2. Audit — what is actually wrong

### 2.1 Conversion gaps (parent can't act)

| # | Problem | Evidence / impact |
|---|---|---|
| 1 | **The phone number is plain text.** No `tel:` link anywhere in the four templates. | On a phone — the only device that matters — the parent cannot tap to call. |
| 2 | **No WhatsApp link at all.** | In Nigeria this is the default contact channel. A school site without a WhatsApp button loses the parent at the moment of highest intent. |
| 3 | **No contact form.** The "Contact" page is four cards and a map. | A parent with a question has no way to ask it except leaving the site. |
| 4 | **No fees information.** Not a range, not a fee page, not "fees on request". | This is the #1 question before an application. Silence sends the parent to a competitor who answers it. |
| 5 | **No term dates / academic calendar.** | The school already has calendar data internally; none of it is public. |
| 6 | **No staff, no results, no testimonials, no photographs of the school.** | The page proves nothing except that the school exists. |
| 7 | **No upcoming events.** A `SchoolEvent` entity already exists (title, dates, type, flash notice, description) and is used in the admin. | Publishing it is nearly free and makes the site feel alive. |

### 2.2 Structural & content

| # | Problem | Evidence |
|---|---|---|
| 8 | **Hero is a 3-slide auto-rotating carousel** that changes every 5 s with no pause control. | It contains **three `<h1>` elements** in the same document, moves content under the reader's thumb, and is announced badly by screen readers. |
| 9 | **"Enrolled Students: {{ student_count }}"** is public. | It leaks the roll and, on a new school, prints `0` — a claim that the school is empty. |
| 10 | The second and third hero slides say what the first one said ("Learn More" / "Discover More" / "Start Application"). | Three slides, one message. |
| 11 | The stats bar mixes one real number with three slogans ("360°", "Safe", "24/7"). | The real number is the weakest of the four. |
| 12 | **Footer gives the school name three times** and the brand as a raw text line "Powered by Edus". | Violates "say each thing once" and the brand-mark rule. |
| 13 | No page for academics/programmes; "Learning at Every Stage" is a home-only section. | Parents comparing schools want a page they can link to. |

### 2.3 Technical & design debt

| # | Problem | Evidence |
|---|---|---|
| 14 | **No per-school SEO metadata.** Title only; no description, no OG image, no canonical, no JSON-LD. | A school sharing its own link on WhatsApp gets a bare URL. `School` has address, phone and name — everything needed for `School`/`LocalBusiness` structured data. |
| 15 | **Hero image is a CSS `background-image`.** | It is discovered late (after CSS), so it is the slowest thing to paint — the LCP element on the most important screen. |
| 16 | **No image sizing strategy.** Uploads are served as uploaded. | A 3 MB phone photo in `uploads/hero/` is a 3 MB hero. |
| 17 | **Arbitrary brand colour is injected inline everywhere** (`style="background-color: {{ brand }}"`). | A school can pick `#FFEE00` and get white-on-yellow buttons. No contrast guard. |
| 18 | Mobile menu button has `aria-label` but **no `aria-expanded` / `aria-controls`**. | Screen-reader state never changes. |
| 19 | `gray-*` / `blue-600` used instead of `ink-*` / `accent-*`. | Drift from the design system; each school site becomes its own palette (school colour + Tailwind grey + blue link). |
| 20 | `divide-x` on a 2-column mobile grid draws borders in odd places. | Small, but visible at 320 px. |
| 21 | CTA uses a hard-coded `href="/apply"` rather than a route name. | Breaks if the path changes; also misses the subdomain-aware helper. |

### 2.4 Keep

- **Real data everywhere** — no fake "Welcome to our school" lorem text.
- **Application mode**: when `applicationsOpen` is true the primary CTA goes
  straight to `/apply`. That is the single best conversion feature on the page.
- **Admissions page ordering** (apply → how → documents → office) is correct.
- **School palette support** is the right idea; it only needs guard rails.
- **Map embed** and social links already exist as data.

---

## 3. What the site is for

1. **Reassure a parent** that the school is real, safe and academically credible.
2. **Answer the practical questions** — fees, term dates, location, how to apply.
3. **Get the application started** (or the phone call made) in one tap.

### Success measures

| Measure | Target |
|---|---|
| Hero → Apply click | the primary conversion |
| Tap-to-call / WhatsApp clicks | the fallback conversion |
| Applications started per 100 site visits | the only number that matters |

Instrument with the same cookie-free counter as the Edus site (§9 there).

---

## 4. Proposed structure

```
/                  Home — static hero, quick actions, about, programmes, events, CTA
/about             Story, values, leadership, location
/admissions        Fees, term dates, requirements, apply online
/academics         Programmes by stage (creche → secondary)
/events            Upcoming events & notices (from SchoolEvent)
/gallery           Photographs (optional, later)
/contact           Form + tap-to-call + WhatsApp + map
/portal            Parent sign-in (existing app_login)
```

Nav (phone): **Apply** as the filled button, then the menu. Desktop: Home · About ·
Admissions · Academics · Events · Contact · **Apply**.

### Home page, top to bottom

1. **Static hero** — school name, motto, one photograph, two buttons: *Apply
   online* and *Call the school*. No carousel.
2. **Quick actions row** — Call · WhatsApp · Directions · Portal. Full-width on a
   phone, thumb-sized.
3. **One-sentence trust line** — e.g. "Nursery – JSS3 · Est. 2009 · [area]".
4. **About preview** with a link to `/about`.
5. **Programmes** (3 cards → `/academics`).
6. **Upcoming events** (only if any exist — never an empty box).
7. **Admissions CTA** with the application fee, if set.
8. **Contact block** with the map.
9. **Footer** — school once, then `▮ Edus` brand mark linking to `edus.ng`.

---

## 5. Design direction

- **The school is the brand; Edus is the colophon.** Header: school logo + name.
  Footer: school contact once, then the Edus mark, small, linking out. Never put
  the Edus mark in the header of a school site.
- **School colour as an accent, not a surface.** Validate contrast at seed time:
  if the chosen hex fails 4.5:1 against white, auto-darken it for text/buttons and
  keep the original only for tints. Show the school a live preview in settings.
- **One grey ramp** (`ink-*`) under the school colour. Links use the school
  accent, not `blue-600`.
- **Type:** 40 px hero (not 60), 28 px section headings, 17 px body, 1.6 leading.
  A parent on a 360 px phone should read without zooming.
- **Photographs, not gradients.** One real photograph of the school beats three
  decorative blurs. If there is no photo, use the school colour as a flat surface —
  do not fake a photo.
- **No auto-playing motion.** No carousel, no marquee, no parallax. Motion only as
  a response to touch.
- **Mobile first, literally:** build at 360 px, then widen. No horizontal scroll at
  320 px. Every tap target ≥ 44 px.

---

## 6. Content model — what schools must fill

| Field | Today | Proposal |
|---|---|---|
| `name`, `motto`, `logoFilename` | yes | keep |
| `address`, `phoneNumber`, `email` | yes | keep; **render phone as `tel:`** |
| `whatsappNumber` | **missing** | new field; if empty, WhatsApp falls back to `phoneNumber` |
| `heroImageFilename` | yes | keep; add upload guidance (landscape, ≥ 1600 px, < 500 KB, auto-resize) |
| `aboutContent` | yes | keep |
| `admissionsIntro`, `applicationFeeAmount`, `applicationClassesNote` | yes | keep; show fee on the CTA |
| `mapEmbedUrl`, socials | yes | keep |
| `feesSummary` / `feesDocument` | **missing** | new: short text or PDF upload → `/admissions` |
| `termDates` | calendar exists | derive next term start/end from the academic calendar |
| `showStudentCount` | **missing** | new bool, default **off**; and never render `0` |
| `news/gallery` | **missing** | Phase 3; `SchoolEvent` covers events first |

Rule: **a section with no data disappears, it does not render empty and it never
renders a zero.** That is the same rule the app screens already follow.

---

## 7. Technical work

1. **Routes/templates**
   - keep `app_tenant_home/about/admissions/contact`;
   - add `/academics`, `/events`;
   - replace `href="/apply"` with the route helper.
2. **Contact form** — `name`, `phone`, `email?`, `message`; CSRF; honeypot; rate
   limit per IP; sends to `school.email` via the existing mailer; success state on
   the same page. Never expose the school's address to spam by putting it raw in
   `mailto:` only.
3. **SEO per tenant**
   - `<title>` = "{school} — {city} | Admissions & Portal";
   - meta description from motto + address;
   - OG image = hero photo (or logo);
   - `School` JSON-LD: name, address, telephone, url, logo;
   - `sitemap.xml` and `robots.txt` per subdomain.

> **Partly done 18 Oct 2026:** `tenant/home/layout.html.twig` now clears
> `title_suffix` (titles no longer read "GAK - Welcome - Edus School SaaS") and
> overrides `meta_description` with the school's name, motto and address, so every
> school site already shares sensibly on WhatsApp.
>
> **Done 19 Oct 2026 — verified against `demo.localhost:8082`:**
>
> | Item | Where |
> |---|---|
> | Per-school OG image (hero → logo → generic Edus card) | `tenant/home/layout.html.twig` `{% block og_image %}` |
> | `School` JSON-LD (name, url, telephone, email, PostalAddress, logo, sameAs) | same file, `{% block structured_data %}` |
> | Per-school `sitemap.xml` | `Tenant\HomeController::sitemap` — the six public pages plus `/apply` |
> | Per-school `robots.txt` | `Tenant\HomeController::robots` — portal and workflow blocked, sitemap advertised |
>
> ⚠️ **The two files must agree.** The sitemap lists `/apply` while robots blocks
> `/apply/` (with the slash) — "apply online to <school>" is a real search, but
> `/apply/verify/{id}` shows one family's application and must never be crawled.
> A URL in the sitemap that robots disallows is a wasted crawl and is ignored by
> search engines anyway, so change both files together.
>
> robots also blocks the tokenised sharing endpoints (`/verify/`, `/r/`,
> `/p/performance`, `/pv/`, `/payments/`): a token that reaches an index is a token
> leaked.
4. **Images** — `<img>` with `width`/`height`, `srcset`, `loading="eager"` +
   `fetchpriority="high"` for the hero; convert uploads to WebP on save.
5. **Contrast guard** in `School::setPrimaryColor()` or a `BrandColor` service.
6. **Accessibility** — one `<h1>` per page; `aria-expanded` on the menu; visible
   focus rings; skip link; alt text on every photo.
7. **Performance budget** — LCP < 2.5 s on a throttled 3G profile; total page
   weight < 500 KB excluding the map.

---

## 8. Mobile-first acceptance

- [ ] No horizontal scroll at 320 px and 360 px
- [ ] Phone number is a `tel:` link; WhatsApp is a `wa.me` link
- [ ] Apply CTA is full width and ≥ 44 px on a phone
- [ ] Exactly one `<h1>` per page
- [ ] Hero text readable with the image blocked
- [ ] No section renders empty or shows `0` pupils
- [ ] Footer names the school once and the brand once
- [ ] Tested at 320 px **and** 1024 px

---

## 9. Open decisions (owner sign-off)

| # | Decision | Recommendation | Why |
|---|---|---|---|
| 1 | Remove the hero carousel? | **Yes** — one static hero. | Three `<h1>`s, motion under the thumb, one message repeated. |
| 2 | Show the pupil count publicly? | **No by default**, opt-in per school, never `0`. | Leaks the roll and embarrasses a new school. |
| 3 | Publish fees? | **Offer it** (`feesSummary`), school decides. | Answering the #1 question converts; hiding it loses the parent. |
| 4 | WhatsApp field | **Add `whatsappNumber`** with phone fallback. | Highest-intent channel in the market. |
| 5 | Contact form destination | School email + copy to Edus support? | **School only** — it is the school's site; Edus gets a bounce log, not parent messages. |
| 6 | Events before news/gallery | **Events first** — data already exists. | Cheapest path to a site that feels alive. |
| 7 | Brand colour guard | **Auto-darken for contrast**, keep original as tint. | Prevents white-on-yellow buttons. |
| 8 | Edus mark on school footer | **Small mark + `edus.ng` link**, no lockup. | Colophon, not co-branding. |
