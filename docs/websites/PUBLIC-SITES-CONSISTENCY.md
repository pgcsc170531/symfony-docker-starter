# Public pages — consistency standard

**Status:** discussion draft (19 Oct 2026). Nothing here is built unless marked ✅.
**Scope:** every page a person can reach **without signing in**, across both sites.
**Companions:** `EDUS-PUBLIC-SITE.md`, `SCHOOL-PUBLIC-SITE.md`,
`docs/architecture/UI-SPEC.md`, `docs/architecture/MOBILE-FIRST.md`.

> Two sites, one visual language. The Edus site sells software; a school site
> sells a school. They must be recognisably the same product without looking
> identical — same type scale, same spacing rhythm, same component shapes, same
> rules about colour. Today they are not: each page was built separately and it
> shows.

---

## 1. The page inventory (what exists today)

### Edus marketing — `%app.base_domain%`

| Route | Template | State |
|---|---|---|
| `/` | `landlord/home/index.html.twig` | dark hero, 9 sections, ~660 lines |
| `/features` | `landlord/home/features.html.twig` | long tour, screenshots |
| `/partners` | `landlord/home/partners.html.twig` | agency programme |
| `/pricing` ✅ | `landlord/home/pricing.html.twig` | built 18 Oct, tokens, roll rule, calculator |
| `/manual` | `landlord/home/manual.html.twig` | standalone HTML, not `base.html.twig` |
| `/sitemap.xml`, `/robots.txt` ✅ | `landlord/seo/*` | built 18 Oct |
| `/login` | `security/login.html.twig` | tenant sign-in (shared) |

### School site — `{subdomain}.%app.base_domain%`

| Route | Template | State |
|---|---|---|
| `/` | `tenant/home/index.html.twig` | 3-slide autoplay hero, **three `<h1>`s**, stats bar |
| `/about` | `tenant/home/about.html.twig` | story, values, map |
| `/admissions` | `tenant/home/admissions.html.twig` | apply CTA, steps, documents |
| `/contact` | `tenant/home/contact.html.twig` | four info cards, map — **no form, no `tel:`** |
| layout | `tenant/home/layout.html.twig` | header/nav/footer for the four pages |

---

## 2. The contract every public page must meet

### 2.1 Colour
- `accent-*` for every interactive element; `ink-*` for every grey.
- `paid` / `pending` / `failed` only for status.
- A **school's own colour** is an accent, not a surface: validate contrast, darken
  for text/buttons if needed, keep the original as a tint.
- **No `gray-*`, `indigo-*`, `slate-*`, `blue-*` in new or touched markup.** They
  are drift, not a palette. Converge as pages are rewritten.

**Current breaches:** home (slate/blue/indigo/gray), features (blue/gray), partners
(blue/gray), school pages (gray/blue + arbitrary hex), `/pricing` ✅ clean.

### 2.2 Type
| Role | Size (mobile → desktop) | Weight |
|---|---|---|
| Display (hero) | 30 → 48 px | 800 |
| Section heading | 22 → 28 px | 700–800 |
| Card title | 16 → 18 px | 700 |
| Body | 16 → 17 px | 400, line-height 1.6 |
| Caption / meta | 12 → 13 px | 500 |
| Button | 14 → 15 px | 600 |

No `text-6xl` on a phone. No all-caps paragraph text. `tracking-[0.3em]` only on a
short eyebrow label, never on a sentence.

### 2.3 Spacing & layout
- Page owns the gutter: `px-4 sm:px-6 lg:px-8`.
- Section rhythm: `py-12 sm:py-16`; cards `p-4 sm:p-5`; stack gap `gap-4`.
- Container: `max-w-3xl` for prose, `max-w-5xl` for cards, `max-w-7xl` for grids.
- **Mobile first**: one column, then `sm:grid-cols-2`, `lg:grid-cols-3`.
- No horizontal scroll at 320 px. Tap targets ≥ 44 px. Inputs ≥ 16 px.

### 2.4 Components (must be shared, not re-invented)
| Component | Rule |
|---|---|
| Marketing nav | brand mark + 5 links + one filled CTA. Dark bar on the Edus site only |
| School nav | school logo + name, 5 links max, **Apply** as the filled CTA |
| Footer | school once (school site) or brand once (Edus site), then © line. Never both marks in one viewport |
| Button | `primary` (accent fill), `secondary` (ink border), `quiet` (text link). One primary per screen section |
| Card | white, `border-ink-200`, `rounded-xl`, `shadow-card`, `p-4/5` |
| Section header | eyebrow + heading + one-line sub, left-aligned on school pages, centred on Edus |
| FAQ | native `<details>`, 44 px summary row, no JS |
| Form field | label above, 44 px control, 16 px text, inline error, no placeholder-only labels |
| Contact actions | `tel:` and `wa.me` links, never plain text numbers |
| Status badge | text always says the status; colour is reinforcement only |

### 2.5 Discovery & sharing
- Every page: `<title>`, description, canonical (query stripped), OG/Twitter,
  1200×630 image. ✅ defaults exist in `base.html.twig`.
- Marketing pages set `title_suffix` to empty; school pages already do.
- Structured data: `Organization` (Edus), `SoftwareApplication` (pricing),
  `School` (per school, not yet built).
- Sitemap + robots on the Edus site ✅; per-tenant still missing.

---

## 3. Page-by-page target shape

### Edus `/` — keep the story, lose the sprawl
Hero → three jobs → one screenshot → module strip → proof → pricing teaser →
final CTA. The 270-line feature catalogue moves to `/features` entirely.
Remove the second "what's new" block.

### Edus `/features` — grouped by job, not by module
Admissions · Fees & invoices · Results & reports · Staff & classes · Parent portal
· Store. Each group: one paragraph, one screenshot, three bullet outcomes. Sticky
sub-nav on desktop; accordion on mobile.

### Edus `/pricing` ✅ — done
Roll rule, toggle-free static comparison, calculator, exclusions, FAQ.

### Edus `/partners` — lead with "what you can sell"
Three paragraphs of economics, then the commission table, then the application
form. Commission is the *second* thing a partner wants to know, not the first.

### Edus `/manual` — keep, but bring into the shell
Same tokens, same nav, searchable headings, print stylesheet. Today it is a
standalone document that ignores the design system.

### School `/` — static, confident, actionable
Static hero (photo + name + motto) → four quick actions (Apply · Call · WhatsApp ·
Portal) → about preview → programmes → events → admissions CTA → contact/map.
No carousel, one `<h1>`, no public pupil count unless the school opts in.

### School `/about`, `/admissions`, `/contact` — same skeleton
Page header (school colour band) → content in one column → one CTA band. Contact
gains a real form; admissions gains fees summary and next-term dates.

---

## 4. What is inconsistent today (the list to work through)

| # | Inconsistency | Where | Fix |
|---|---|---|---|
| 1 | Five blues on one page | home | converge to `accent`/`ink` |
| 2 | Buttons: pill, rounded-lg, rounded-md, no min-height | all marketing + school | one button component |
| 3 | Section spacing varies 8/12/16/20/24 | all | one rhythm |
| 4 | Two different nav designs for one product | Edus vs school | shared structure, different brand block |
| 5 | Footers: 3 brand treatments (text, mark, lockup) | index, school layout, pricing ✅ | one footer per site |
| 6 | Hero carousel vs static hero | school home | static hero |
| 7 | Contact info as plain text | school contact | `tel:`/`mailto:`/`wa.me` |
| 8 | Emoji as icons in places | partners, school login | line icons (`_nav_icons`) |
| 9 | `manual` outside the shell | manual | adopt base + tokens |
| 10 | No per-tenant sitemap/JSON-LD | school site | add in the school rebuild |
| 11 | Tailwind CDN on every page | all | built stylesheet (Phase 5) |

---

## 5. Roadmap (revised order)

| Phase | Work | Why first |
|---|---|---|
| **A** ✅ | Pricing page + nav links + SEO defaults + sitemap/robots | done |
| **B** | **School site rebuild** (hero, contact actions, form, fees/dates, events, per-school SEO) | parents are the largest audience; worst current experience |
| **C** | Shared public components extracted (`_public_nav`, `_public_footer`, `_button`, `_card`, `_faq`) | stops the drift returning |
| **D** | Marketing convergence: home + features + partners to `accent`/`ink`, remove marquee/float | consistency |
| **E** | Proof: 3 case studies, photographs, testimonials | trust |
| **F** | `/security` + `/contact` + FAQ expansion | objections |
| **G** | Built Tailwind stylesheet (drop CDN on public pages) | speed, production correctness |
| **H** | Analytics: cookie-free page views + CTA clicks | makes the rest measurable |

---

## 6. Acceptance for "consistent"

- [ ] At 360 px, every public page has the same gutter, the same heading size, the
      same card padding, and one primary action in view
- [ ] No `gray-*`/`indigo-*`/`slate-*`/`blue-*` in touched files (`grep` clean)
- [ ] Nav and footer rendered from one include per site
- [ ] Every phone number tappable; every page has a contact path in one tap
- [ ] Every page has title + description + OG image; canonical correct
- [ ] No carousel, no autoplay, no motion without `prefers-reduced-motion`
- [ ] Lighthouse mobile: LCP < 2.5 s, CLS < 0.1 on a throttled profile

---

## 7. Open decisions

| # | Decision | Recommendation |
|---|---|---|
| 1 | School hero: photo or colour block? | photo if the school uploads one, colour block otherwise — never a fake photo |
| 2 | Public pupil count | off by default, opt-in, never render `0` |
| 3 | Fees on the school site | optional `feesSummary`; school decides |
| 4 | Marketing hero: keep dark? | yes, home only; every other marketing page light |
| 5 | Testimonials | only with a real photo + named school; otherwise omit |
| 6 | Extract shared components now or after school rebuild? | after — the school pages will reveal the real component boundaries |
