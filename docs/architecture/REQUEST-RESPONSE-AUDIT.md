# Edus — Request/Response Audit: how our "cloud operations" work today

> **Status:** 🟡 Findings + plan. **Implementation deferred** until the core Parent Application
> module ships (your instruction: core first, UI/JSON polish later).
> **Question this answers:** are our GET/POST routes sending/receiving JSON? How are forms loaded?
> What must change so the app stays light on a 2G connection?

---

## 1. The one-line answer

**No — the app is HTML-first, not JSON-first.** Out of **190 routes** only **7** speak JSON
(4 read APIs + 3 write APIs). Everything else renders a full Twig page and, for writes, does a
classic HTML form `POST` followed by a `302` redirect. That is actually the *right* default for 2G
(a form POST needs no JavaScript at all) — the problem is not JSON usage, it is **what we ship
alongside the HTML** (§5).

---

## 2. Current shape of the app (measured, 24 Sep 2026)

| Metric | Value |
|---|---|
| Routes | **190** — 43 `GET`-only · 43 `POST`-only · 45 `GET\|POST` · **59 `ANY`** |
| Tenant controllers | 40 files, 145 public actions |
| JSON endpoints | **7** (3.7% of routes) — inventory in §3 |
| Templates using `fetch()` | 5 |
| Templates using Alpine (`x-data`) | 6 |
| Turbo frames / `data-turbo` | **0** — Turbo 7.3 is installed but unused |
| Stimulus controllers | 2 (`hello`, `csrf_protection`) — barely used |
| Upload sites (`->move()`) | 8, each with its own naming/validation logic |
| Templates loading Tailwind from the CDN | **13** (including every print view) |

---

## 3. Complete JSON inventory (everything async in the system today)

### 3.1 Read APIs (`GET` → JSON)

| Route | Path | Controller | Client |
|---|---|---|---|
| `app_tenant_api_states` | `/admission/api/states/{id}` | `AdmissionController` (`new JsonResponse`) | `student/edit`, `admission/register` (state → LGA cascade) |
| `app_tenant_api_lgas` | `/admission/api/lgas/{id}` | `AdmissionController` (`new JsonResponse`) | same |
| `app_tenant_api_students` | `/enrollment/api/students/{sessionId}/{classId}` | `EnrollmentController` (`$this->json`) | `enrollment/promote` |
| `app_tenant_api_product_details` | `/store/sales/api/product/{id}` | `StoreSalesController` (`$this->json`) | `store/cart_sale` |

### 3.2 Write APIs (`POST` + **JSON request body**)

| Route | Path | Controller | Body parsed with |
|---|---|---|---|
| `tenant_result_entry_save` | `/results/entry/{id}/save` | `ResultEntryController` | `json_decode($request->getContent(), true)` |
| `app_tenant_store_checkout_student` | `/store/checkout/student/{id}` | `StoreSalesController` | same |
| `app_tenant_store_checkout_walkin` | `/store/checkout/walkin` | `StoreSalesController` | same |

**Response conventions already in use** (worth keeping, they are good):

```php
// success
$this->json(['success' => true]);                       // result entry
$this->json(['redirect' => $this->generateUrl(...)]);   // store checkout → client redirects
// failure — note the status codes already chosen for meaning
$this->json(['success' => false, 'error' => 'Results are locked…'], 423); // 423 Locked
$this->json(['success' => false, 'error' => 'Already submitted.'], 409);  // 409 Conflict
$this->json(['success' => false, 'error' => 'Cart is empty.'], 400);      // 400 Bad Request
```

So there is **already an established pattern** — the new application module must follow it, not
invent a second one. The only inconsistency: success payloads are sometimes `{'success': true}`,
sometimes `{'redirect': …}`. Standardise on §6.1.

### 3.3 Everything else (≈96% of the app)

- **Page loads** — `GET` → `$this->render('tenant/…')` → full HTML document.
- **Writes** — Symfony Forms, POST, CSRF token, `$this->addFlash('success', …)` +
  `return $this->redirectToRoute(…)` (Post/Redirect/Get). No JSON, no XHR.
- **Uploads** — `multipart/form-data` on the same POST, then `->move()` (8 sites: logos, hero image,
  student photo, payment proof, wallet proof, subscription proof…).
- **`ANY` method routes (59)** — pages that accept any verb; no method discipline, which is also why
  a wrong-verb request can hit a write action.

---

## 4. How forms are actually loaded

1. Symfony Form is built in the controller → `createView()` → rendered by a Twig block.
2. The browser receives **HTML with the form markup already in it** (server-rendered, no client-side
   form builder).
3. Submit → single `POST` (urlencoded, or multipart when a file is present) → server validates →
   `302` to a results page with a flash message.

**Cost profile on 2G:** one request, no JS, no XHR waterfall — this is *good*. The page weight
is where the pain is, and it is dominated by §5.

---

## 5. Findings — the real 2G problems (ranked)

### 🔴 F1. Tailwind is loaded as a **runtime JIT compiler from a CDN**
`templates/base.html.twig` line 7:

```html
<script src="https://cdn.tailwindcss.com"></script>
```

That is ~a hundred KB of JavaScript that must be **downloaded and executed on every device** before
styling exists, it emits a browser-console warning ("should not be used in production"), and it
requires the device to be online. It is loaded by **13 templates** (login, receipts, ID cards,
admission letters, score sheets, onboarding steps…).

Meanwhile the repo **already has Tailwind 4.1.17 in `importmap.php`** and a compiled CSS pipeline
(`assets/styles/app.css`, `assets/vendor/tailwindcss/`) that `base.html.twig` never references.
Two Tailwind systems exist; the slow one is live.

### 🔴 F2. Alpine is loaded from a CDN as well
`<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js">` — third-party
origin, unpinned `3.x.x`, and it can never be cached offline by a service worker (cross-origin).

### 🟠 F3. The importmap pipeline is dead code
`app.js`, Stimulus, **Turbo 7.3** and Tailwind 4.1.17 are declared in `importmap.php`, but
`base.html.twig` never calls `importmap()` and no template uses `turbo-frame`. We ship nothing from
it and pay nothing for it today — but it means our "modern" stack is unproven. Either adopt Turbo
deliberately (§6.3) or drop it from the map.

### 🟠 F4. No method discipline
59 `ANY` routes. Print views, receipts and read pages accept `POST`, and a mistyped verb can reach a
write action. Cheap to fix, and it protects the new public routes.

### 🟠 F5. Uploads are hand-rolled 8 times
Each `->move()` site invents its own filename, extension whitelist and error handling. The new module
adds 4 more upload targets (photo, birth certificate, previous result, fee proof) — without a shared
service we will add 4 more copies.

### 🟡 F6. Offline is impossible today
No service worker exists, so nothing is cached and no action can be queued. (For the application
module this is the feature you asked for — see `DESIGN.md` §"Offline-first drafts".)

---

## 6. Plan

### 6.1 One JSON convention (for anything async)

```json
{ "ok": true,  "data": { … } }                 // 200
{ "ok": false, "error": "Human readable." }    // 400 / 403 / 409 / 423
```

Keep the existing status-code discipline (`409` conflict, `423` locked). Never return HTML with a
JSON status, and never return a bare string. Add `Cache-Control: no-store` to these responses.

### 6.2 Page vs. JSON — the rule we will follow

| Situation | Use |
|---|---|
| Moving the user to a new screen | **HTML** (form POST + redirect, or a plain link) |
| Updating part of the page the user is already on | **JSON** + Alpine |
| Dependent dropdowns (state → LGA) | **JSON** ✅ already the pattern |
| Anything the user must not lose (a long form) | **JSON autosave to a draft** (new module) |
| Bulk results of an operation (CSV import summary) | **HTML** page (large tables do not belong in JSON) |

### 6.3 Prioritised backlog (after the core module)

| # | Change | Impact on 2G | Effort |
|---|---|---|---|
| 1 | **Compile Tailwind once** and link the built CSS in `base.html.twig` + the 13 templates; keep the CDN only as a temporary fallback | 🔥 Removes ~100–200 KB of JS and the online requirement; enables offline caching | M |
| 2 | Serve **Alpine via importmap** (same origin, pinned version, cacheable) | Removes a third-party request | S |
| 3 | Extend the JSON convention (§6.1) to the *new* module only | Consistency | S |
| 4 | `FileUploadService` (mime + size + random name policy) used by all upload sites | Fewer failed uploads on flaky links (fail fast, clear error) | S |
| 5 | Replace `ANY` with real verbs on read/print routes | Security + clarity | S |
| 6 | Server gzip/brotli + long-lived cache headers on immutable assets | 60–80% smaller transfers | S |
| 7 | **Turbo Drive/Frames** for list filters, pagination and the review queue | Removes full-page round trips | M |
| 8 | Service worker + IndexedDB drafts (application module) | **Works with no network at all** | L |
| 9 | Deferred: convert remaining high-traffic interactions (payment verify click, badge counters) to JSON | Fewer reloads for bursars on slow links | M |

### 6.4 Budgets to hold ourselves to

| Metric | Target |
|---|---|
| First load (HTML + CSS + JS, compressed) | **≤ 100 KB** |
| Subsequent page navigation | **≤ 25 KB** |
| Print views (receipt, ID card, letter, score sheet) | **≤ 15 KB**, no JS at all |
| Requests per screen | 1 (pages), +1 for async extras |
| Any feature that needs a JS framework | ❌ not without a written reason |

---

## 7. For the new Parent Application module (immediate implications)

| Need | Decision |
|---|---|
| Multi-step form | **HTML steps + Alpine** for field-level behaviour; no JSON needed to move between steps |
| Offline drafts | **Alpine + IndexedDB/localStorage + Service Worker** (see `DESIGN.md`) |
| Draft autosave when online | **JSON** `POST /parent/applications/{id}/draft` (if authenticated) using §6.1 |
| State → LGA cascade | Reuse the existing `/admission/api/lgas/{id}` JSON endpoint — **no new endpoint** |
| Document upload | Reuse the established `multipart` POST + `FileUploadService`; show progress with XHR |
| Submit | Classic `POST` → redirect to the application status page (works without JS; the service worker replays it when offline) |
| Payment (online) | Redirect to the gateway (no JS needed); webhook confirms server-side (see `docs/payments/PAYMENT-COLLECTION.md`) |
