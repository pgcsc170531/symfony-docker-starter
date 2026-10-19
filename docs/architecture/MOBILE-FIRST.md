# Mobile-first standard

> **Status: mandatory.** The short version of this lives in `.github/copilot-instructions.md`.
> This file is the detailed standard, the rationale, and the patterns to copy.

---

## 1. Why this is the default, not an option

Edus is used by **parents** and **teachers**, and almost all of them reach it on a
**mobile phone**. Typical conditions:

- A mid-range or low-end **Android** handset, **360px** wide, sometimes 320px.
- Intermittent 3G/4G, or nothing at all for minutes at a time.
- One hand, standing up, in a corridor or a shop — not a desk with a mouse.
- Data costs money, so heavy pages are a real cost to the user.

A head teacher checking attendance, a parent applying for a child, a bursar confirming a
fee — all of them are on a phone. **If it does not work well on a phone, it does not work.**

---

## 2. The phones we design for

| Width | Device class | Priority |
|---|---|---|
| **320px** | iPhone SE 1st gen, old Android | The floor. Must not break. |
| **360px** | Most Android phones | **Primary target — design here first.** |
| **390px** | iPhone 12/13/14 | Very common |
| **414 / 428px** | iPhone Plus / Max, large Androids | Common |
| ≥ 640px | Tablet / desktop | Progressive enhancement |

### Breakpoints — Tailwind defaults only

| Token | Width | Meaning for us |
|---|---|---|
| *(none)* | 0px+ | **The phone layout. This is the real layout.** |
| `sm:` | ≥ 640px | Large phone landscape / small tablet |
| `md:` | ≥ 768px | Tablet; the sidebar becomes permanent here |
| `lg:` | ≥ 1024px | Desktop; multi-column grids appear |

**Never invent a breakpoint.** If a layout needs a width that isn't in this table, the
layout is wrong.

---

## 3. Layout rules

1. **Single column is the default.** Widening is the enhancement:
   `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">`.
2. **No horizontal page scrolling at any width.** Check `scrollWidth <= innerWidth`.
   `main` has `overflow-x-hidden` as a safety net — that is a net, not a licence.
3. **Gutters:** `p-4` on phones, `sm:p-6` from 640px. Don't waste pixels on a 320px screen.
4. **Never fix a fixed pixel width** on content. Use `w-full`, `max-w-*`, `flex-1`, `min-w-0`.
   Any flex/grid child holding text needs **`min-w-0`** or it will refuse to shrink.
5. **Long tokens must break:** `break-words` on cards, `break-all` on codes
   (`APP/2026/0001`, emails, URLs).
6. **Respect the notch:** `env(safe-area-inset-*)` for anything pinned to a screen edge —
   `base.html.twig` already does this for the sidebar.

---

## 4. Touch and input

| Rule | Why |
|---|---|
| Interactive targets **≥ 44 × 44 px** | Apple's 44pt / Material's 48dp guidance. Below this, people miss. |
| Focused inputs **≥ 16px font-size** | Below 16px, **iOS Safari zooms the viewport** on focus. The layout jumps and is hard to recover from. |
| Primary actions **full width** on phones | `w-full sm:w-auto` — much easier to hit with a thumb. |
| Primary action **first in thumb order** | In a stacked action list, the main action goes nearest the thumb. Use `order-*` to keep desktop order sane. |
| `type="button"` on non-submit buttons | Prevents accidental form submission. |
| Visible `active:` state | Touch has no hover; give feedback on press (`active:bg-*`). |
| Labels above inputs, never placeholder-only | Placeholders vanish as soon as the user types. |

---

## 5. Patterns to copy

### 5.1 The off-canvas sidebar

Already implemented in `templates/base.html.twig`. It must:

- Be hidden off-screen by default and slide in over the content (`-translate-x-full`).
- Have a **scrim** (`#sidebar-backdrop`) that is tappable to close.
- Close on **Escape**, on **menu item tap**, and on **resize to ≥ md**.
- Lock body scroll while open (`document.body.style.overflow = 'hidden'`).
- Leave a **44px toggle button** that does **not** overlap content — `main` carries
  `pt-16` below `md` purely to stay clear of it.

### 5.2 Action rows — full width on a phone

```html
<div class="flex flex-col sm:flex-row sm:items-center gap-3">
  <!-- primary first: nearest the thumb after scrolling -->
  <button class="order-1 sm:order-3 w-full sm:w-auto inline-flex justify-center items-center
                 px-6 py-3.5 rounded-xl bg-indigo-600 text-white font-bold text-base sm:text-sm">
    Continue
  </button>
  <button class="order-3 sm:order-2 w-full sm:w-auto px-5 py-3.5 rounded-xl border ...">
    Save &amp; finish later
  </button>
</div>
```

### 5.3 Wide tables

Tables are the most common cause of horizontal overflow. Always wrap:

```html
<div class="overflow-x-auto -mx-4 px-4">
  <table class="min-w-[640px] w-full">…</table>
</div>
```

The negative margin lets the table bleed to the screen edge while the page keeps
its gutters.

### 5.4 Bulletproof multi-step forms

Two traps that cost real debugging time — both already handled in
`templates/tenant/parent/applications/form.html.twig`, copy the approach:

1. **`novalidate` on the form.** Native HTML5 validation cannot report on a `required`
   field inside a hidden step — the browser logs
   *"An invalid form control with name='…' is not focusable"* and **silently refuses to
   submit**. Validate server-side instead, and jump the wizard to the first step that
   contains an error.
2. **Hide steps with `:style`, not `x-show`.** With Alpine 3.x from the floating
   `alpinejs@3.x.x` CDN, `x-show`'s effect ran once at init and never re-ran, so panels
   froze on step 1 while `x-text`, `:style` and `:value` updated normally. Use
   `:style="step === 1 ? '' : 'display: none'"`. *(Re-check this when the Alpine CDN is
   pinned to a specific version.)*

Also: fields in hidden steps are still submitted, which is what you want — never swap
`display: none` for removing nodes, or answers will be lost on submit.

---

## 6. Performance is a mobile feature

A 2G/3G parent feels every kilobyte. See `docs/architecture/REQUEST-RESPONSE-AUDIT.md` §6.4.

- The **Tailwind CDN JIT** (`cdn.tailwindcss.com`) is a dev convenience that should not
  ship to production — it is a real cost on a slow phone. Tailwind 4.1.17 is already in
  `importmap.php` and unused.
- `alpinejs@3.x.x` is a **floating version** — pin it.
- Prefer server-rendered HTML over client-side hydration for anything on the critical path.
- The child application form must stay usable on a dropped connection (IndexedDB draft +
  service worker + Background Sync — `docs/parent-application/DESIGN.md` §3.4).

---

## 7. How to verify (do this before saying "done")

The VS Code integrated browser scales its viewport by **1.25×**. To emulate a real CSS
width, request `width / 1.25`:

| Real CSS width | Request |
|---|---|
| 320px | 256 |
| 360px | 288 |
| 390px | 312 |
| 414px | 331 |

Then assert on the live DOM — don't eyeball a screenshot:

```js
const vw = window.innerWidth;

// 1. no horizontal scrolling
console.log('h-overflow', document.documentElement.scrollWidth - vw);   // must be <= 0

// 2. touch targets
document.querySelectorAll('main button, main a, main input[type=submit]').forEach(el => {
  const r = el.getBoundingClientRect();
  if (r.width > 0 && r.height < 44) console.warn('small target', el.innerText, r.height);
});

// 3. iOS zoom trap
document.querySelectorAll('main input, main select, main textarea').forEach(el => {
  const s = parseFloat(getComputedStyle(el).fontSize);
  if (s < 16) console.warn('sub-16px input', el.name, s);
});

// 4. text must shrink with the screen, not stay a desktop size
[...document.querySelectorAll('main *')]
  .filter(el => !el.children.length && el.textContent.trim())
  .map(el => ({ t: el.textContent.trim().slice(0, 24), fs: parseFloat(getComputedStyle(el).fontSize) }))
  .filter(x => x.fs >= 26)
  .forEach(x => console.warn('oversized text on a phone', x.fs, x.t));
// must be empty, or only an icon glyph. Scale: docs/architecture/UI-SPEC.md §3.0

// 5. the fixed menu button must not sit on the first heading
const t = document.getElementById('sidebar-toggle')?.getBoundingClientRect();
const h = document.querySelector('main h1')?.getBoundingClientRect();
if (t && h) console.log('toggle overlaps h1', !(t.right < h.left || t.left > h.right || t.bottom < h.top || t.top > h.bottom));
```

Check 4 is the one that gets skipped, because nothing *visually breaks* when it
fails — the text just looks like a desktop screenshot on a phone. A fixed `text-3xl`
is exactly the same on a 360px handset and a 27-inch monitor; on the invoice screen
that meant a 30px number in a 250px card. Every size class needs an `sm:` neighbour
unless it is already a phone size.

**Run the list of checks at 320px and 360px for every screen you touch.**

---

## 8. Current status

Applied and verified on 25 Sep 2026:

| Area | State |
|---|---|
| `base.html.twig` shell | ✅ `viewport-fit=cover`, `theme-color`, 16px mobile inputs, 44px buttons, drawer scrim + Escape + scroll lock, no toggle/content overlap |
| Parent dashboard | ✅ single column, full-width action card |
| Applications list | ✅ stacked cards, full-width actions, 44px links |
| Application wizard | ✅ stacked fields, thumb-ordered full-width actions, `:style` panels |
| Application detail | ✅ stacked, full-width actions |

Known gaps to close:

- **Wide tables** elsewhere in the tenant area (invoices, results, staff lists) still
  need `overflow-x-auto` wrappers — audit with the §7 snippet.
- **Tailwind + Alpine are still loaded from CDNs**; pin them and self-host to cut the
  mobile payload.
- The **child application form has no offline support yet** — a parent who loses
  connection mid-form loses the page (design §3.4).
