# AI Edible Cake Topper — Implementation Plan

Supersedes `idea.md`. Where the two disagree, this file wins; §23 lists what changed and why.

Written 2026-08-02. API prices verified same day (§8, sources at end).

---

## 1. Decisions locked

| Question | Decision |
|---|---|
| Multi-up sheets (cupcakes) | **One design × N copies.** One generated image, tiled N times onto the sheet. One design record per order line. |
| Custom text (names, greetings) | **Server-side text layer.** Separate input field, composited with a real font at print resolution. The AI is instructed to produce *no text*. |
| Customer photo upload | **Out of scope.** Not v1, not designed for. Revisit only if v1 succeeds. |
| SKU model | **A separate WooCommerce product per diameter/count.** Not variations. Simplifies a lot — see §4. |
| Print file format | **PNG only.** No PDF. Removes the last hard Imagick dependency from the output path. |
| Image engine | **GD is the target, Imagick an optional enhancement.** The client cannot install extensions on the live host. Testbed develops on the GD path by default (§9.1). |
| Order file retention | **Permanent.** Order images live in their own folder, keyed by order, never auto-deleted. Reorder/reprint is a first-class feature. |
| Testbed | `http://100.127.55.45:8080/` — Docker, full control, SSH open. |
| Production | `valgomosdekoracijos.lt` — **plain WordPress, no additional services.** |

### Verified testbed environment (2026-08-02)

| | |
|---|---|
| WordPress | **7.0.2** |
| WooCommerce | **10.9.4** |
| Theme | Blocksy + `valgomos` child theme |
| Cart / checkout | **Classic shortcode, not blocks** (`/krepselis/` confirmed) |
| Store API | Present (`wc/store/v1`) but unused by the active cart |
| Other plugins | WooPayments, PayPal, MailPoet, Unisend, Jetpack, Pinterest, Google Listings & Ads, WooCommerce POS |

Classic checkout is the good outcome: the hooks in §13.2 work directly, and the Store API
integration is deferred until (unless) the live site moves to block checkout.

### The production constraint drives the architecture

No Redis, no Node sidecar, no external render worker, no message broker, no ImageMagick CLI shellout.
Everything runs inside PHP-FPM + MySQL + whatever WooCommerce already ships.

Two hard consequences:

1. **Zero runtime dependencies.** No Composer packages at runtime, no build step. The plugin is a
   folder you upload. (Composer may still be used in dev for PHPCS/PHPUnit — `vendor/` never ships.)
2. **The PHP worker pool is the scarcest resource.** Typical shared hosting runs 4–8 PHP workers
   *for the whole site*. A 10-second blocking generation request occupying one worker means five
   simultaneous customers take the storefront down. This single fact decides §6.

Design rule for every choice below: *does this still work on a €5/month host with 4 workers,
128 MB memory, GD but maybe not Imagick, and no cron access?* If not, it needs a fallback.

---

## 2. What the customer actually does

```
Product page (variable product: "A4 sheet" / "20 cm round" / "24× cupcake")
   │
   ├─ 1. Picks a variation           ← this sets shape + aspect ratio + print size
   ├─ 2. Types a prompt in Lithuanian
   ├─ 3. (optional) Types name/greeting text, picks a font + colour + placement
   ├─ 4. Clicks "Generate" → job queued → polls → watermarked preview in the real shape
   ├─ 5. Regenerates / tweaks / picks from session history
   └─ 6. Adds to cart → pays
             │
             ▼ (background, post-payment only)
        upscale (only if needed) → text layer at print res → shape + bleed
             → imposition (N-up) → print file → order flips to "Awaiting approval"
                                                          │
                                                          ▼
                                             Admin reviews → approves → prints
```

Step 1 before step 2 is deliberate and is a real UX constraint, not a preference — see §4.3.

---

## 3. Print geometry — the numbers everything else derives from

300 DPI = **11.811 px/mm**. All sizing flows from one function:

```php
px = ceil( mm * dpi / 25.4 )
```

| SKU | Physical | + 3 mm bleed | Pixels needed @300 DPI |
|---|---|---|---|
| A4 icing sheet | 210 × 297 mm | 216 × 303 | **2551 × 3579** |
| Round topper 20 cm | ⌀200 mm | ⌀206 | **2433 × 2433** |
| Round topper 15 cm | ⌀150 mm | ⌀156 | **1843 × 1843** |
| Cupcake circle 4.5 cm | ⌀45 mm | ⌀51 | **603 × 603** |
| Cupcake circle 6 cm | ⌀60 mm | ⌀66 | **780 × 780** |

A native 1024×1024 generation is 86.7 mm at 300 DPI.

### 3.1 The upscale is conditional, not automatic

`idea.md` assumed every order needs a 4× upscale. It does not.

**A 4.5 cm cupcake circle needs 603 px. The native 1024 px generation already exceeds it by 70%.**
Cupcake sheets — probably the highest-volume SKU — need **no upscale at all**, just a downscale.

So the rule is: compute required px from the variation's print spec, compare against what the
generator produced, and upscale **only if short**. Roughly:

| SKU | Native 1024 enough? | Action |
|---|---|---|
| Cupcake sheets (any count) | Yes, comfortably | Downscale per circle |
| 15 cm round | No (needs 1843) | 2× upscale → 2048, downscale to 1843 |
| 20 cm round | No (needs 2433) | 4× upscale → 4096, downscale to 2433 |
| A4 | No (needs 3579 tall) | 4× upscale, then crop |

This removes a paid API call from the majority of orders.

### 3.2 Aspect ratio and the A4 problem

A4 is 1:1.414. No image model offers that ratio.

- **Round / cupcake** → generate 1:1. Clean.
- **A4** → generate 2:3 (1.5, taller than needed) and centre-crop the height down to 1.414.
  Loses ~6% of vertical content. Mitigated by a prompt suffix that asks for centred subjects
  and generous margins.
- FLUX on fal accepts **arbitrary pixel dimensions**, so it can hit 1:1.414 exactly. Gemini is
  restricted to a fixed ratio list. Minor point in FLUX's favour for the A4 SKU.

### 3.3 Bleed and safe zone

Two different margins, both needed, easy to conflate:

- **Bleed** (+3 mm outside the trim line) — image extends past the cut so a slightly-off cut
  doesn't leave a white sliver. Achieved by scaling the image up 3 mm beyond trim, not by
  adding a border.
- **Safe zone** (−5 mm inside the trim line) — nothing important, *especially text*, within
  5 mm of the edge. Enforced by the text renderer, and drawn as a guide in the admin preview.

### 3.4 Usable area — the number everything derives from (D-037)

**No printer margins.** Compose on the full A4, and let the printer driver do whatever it does
(Ruslan, D-039 — he prints ⌀20 cm circles routinely, so the margins are not what a spec sheet
would predict). The only deduction is the **15 mm at the right that carries no icing**:

```
long axis   297 − 15 (bare icing, right)  = 282 mm
short axis  210                           = 210 mm
```

**Usable area: 282 × 210 mm.** The 15 mm is a setting; the rest is the paper.

Imposition maths uses the *usable* area, never the paper size.

**Placement inside it is free** (D-037). Ruslan's rule: the design must *fit* the page and the
**physical size must be exact** — a 5 cm cupcake is 5 cm. How pieces are arranged within the
usable region is not important, so the pipeline does not need to centre precisely on A4, and it
must not, because the usable region is not centred on the sheet.

⌀20 cm is the declared maximum and it fits: 200 + 6 mm bleed = 206 against 210. Four millimetres
of slack, which is why the side margins had to go to zero for it to work.

Ruslan prints and checks every format physically before launch; corrections come from that, not
from more arithmetic here.

### 3.5 Imposition — how "24 on A4" actually works

Given usable area 210 × 282 mm (§3.4) and a circle of ⌀45 mm, pitched on the **trim** diameter
because adjacent bleeds overlap and you cut between them:

- columns = floor(210 / 45) = **4**
- rows = floor(282 / 45) = **6**
- total = **24** ✓

Other sizes fall out of the same formula:

| Circle | Cols × Rows | Per sheet |
|---|---|---|
| 4.0 cm | 5 × 7 | 35 |
| 4.5 cm | 4 × 6 | **24** |
| 5.0 cm | 4 × 5 | 20 |
| 6.0 cm | 3 × 4 | 12 |

Single circles come out of the identical formula — ⌀20…15 cm yield 1, ⌀14…11 cm yield 2, ⌀10 cm
yields 4 — which is why the two wizard paths are one mechanism with two lists (D-038).

So the count is *derived*, not typed in, and this table is what the wizard **shows the customer**.
Leftover slack is distributed as gutters; exact distribution does not matter (§3.4).

Cut guides: optional, off by default. A printed guide line is printed in *edible ink on the
product* — visible on the finished topper. Better to rely on a physical circle cutter. If
enabled, render at 0.15 mm in 15% grey.

---

## 4. Product and data model

### 4.1 One product. Sheet type is the variation. Format lives on the design. (D-035)

Registering a custom product type is still wrong — it forfeits variable-product support and
fights every theme and extension for no benefit.

**And there is only one AI product.** Under D-033 every order is one A4 sheet, so ⌀20 cm and
"24 cupcakes" are not different products — they are different artwork layouts on the same
physical thing, at the same cost to produce. Ten products for one product is ten things to keep
in sync.

**A simple product, priced the way the shop already prices everything** (D-036). Not a variable
product — the live site does not use variations for this. Base price plus **WC Fields Factory**
fields carrying named surcharges onto the line item:

```
Lakštas … — 3,50 €
  Lakšto tipas:  Krakmolo lakštas (0.3–0.4 mm)      +0,00 €
                 Storas krakmolo lakštas (0.6 mm)   +1,00 €
                 Cukrinis lakštas (0.6 mm)          +1,50 €
  AI paveikslėlio mokestis                          +1,00 €   ← the one we add
```

**No separate charge for text** (D-037). The existing `Užrašo mokestis` does not carry over: the
wizard *is* the customisation, so composing text is part of it. The AI surcharge is the only
thing the plugin adds, and it is charged whenever a generated image was used.

Sheet type changes price and print notes but **not** shape, size or aspect ratio, so it never
invalidates a generated design.

**The plugin owns no pricing.** No variations, no settings field, no `set_price()`, no
`add_fee()`. Fields Factory already applies surcharges per line and already renders each one as
its own labelled row on the cart and the order — which is the whole mechanism, in production, on
~2500 products. Prices are edited where they are edited today.

**Format — shape, size, copies — is a wizard choice, recorded on the design row.** Not a fixed
catalogue of SKUs but **three format types** (D-037):

| Type | What the customer chooses | Geometry |
|---|---|---|
| **A4 visas lapas** | nothing | rect, the whole usable area |
| **Vienas apskritimas** | a diameter from a fixed list — 20…10 cm in 1 cm steps | round, ×N as fits |
| **Keksiukams** | one predefined case showing **count and diameter** | round, ⌀ as listed, ×N |

Both are **comboboxes over a hardcoded list of offered sizes** (D-038) — no free numeric input,
so there is no ⌀17.5 cm and no floor to define.

**The list is hardcoded; the arrangement is not** (D-038). `SheetLayout` derives cols, rows and
positions from the chosen size and the usable area, so "⌀5 cm, 20 vnt" is arithmetic rather than
a maintained table and a count can never disagree with the geometry.

An admin screen renders every offered size's derived layout on one page, so all of them can be
reviewed at once without any of them going stale. Ruslan prints every format physically before
launch; that is what corrections come from.

> **Format is a property of the design, not of the product.**

Price does not vary with format — every one of them is one A4 sheet, which is the whole point of
D-035. A ⌀5 cm circle and a ⌀19 cm circle cost the same, because they cost the same to make.

The "geometry must be known before generation" requirement that drove the old model still holds —
the aspect ratio differs (1:1 round, 2:3 for A4, §3.2). The wizard satisfies it by fixing format
at step 1, before anything is generated (D-034). It does not need separate products.

The open risk is that the wizard must set the AI field **programmatically** — the customer never
sees a "did you use AI?" control, it is implied by having generated an image — and it must still
fire its price rule and render on the line. Verify against **WCFF 4.1.9 specifically** before
relying on it (D-036).

Shipping does not enter this model at all — default methods, independent of size and product.

### 4.2 Where the print spec lives

**Primarily on the design row, from the format catalogue** (§4.1, D-035). `PrintSpec` resolves in
this order:

> **design's format → variation meta → product meta → global default**

The three later sources are kept because they cost nothing and they are the escape hatch for a
one-off product that needs geometry of its own. The format catalogue is the source of truth for
everything the wizard offers, and each entry carries the same fields:

```
_aicake_enabled           bool
_aicake_shape             round | rect
_aicake_width_mm          int          (diameter if round)
_aicake_height_mm         int          (ignored if round)
_aicake_bleed_mm          int    default 3
_aicake_safe_mm           int    default 5
_aicake_copies            int    1 = single topper, >1 = N-up sheet
_aicake_sheet             a4 | custom  (+ _aicake_sheet_w_mm / _h_mm)
_aicake_dpi               int    default 300
_aicake_style_preset      slug   which prompt suffix / house style
_aicake_max_regenerations int    0 = use global default
```

A "24× cupcake" format is `shape=round, width_mm=45, copies=24, sheet=a4`. Nothing about it
is special-cased in code — the imposition falls out of the geometry (§3.5). Under D-033 the
canvas is always A4, so `_aicake_sheet` no longer varies in practice; it stays because the
maths reads it and a custom sheet costs nothing to keep supported.

Variation-level meta is still read as an **override** if present, so if a sheet type ever does
need a different bleed or DPI, that works without restructuring.

The catalogue editor — and the product screen, where meta is still used — shows a live computed
summary as the admin types:

> ⌀45 mm + 3 mm bleed → 603 px @300 DPI · 4 × 6 = **24 per A4 sheet** · no upscale needed

That single line prevents most of the ways this can be misconfigured.

### 4.4 Tables

Two tables, both prefixed, both created via `dbDelta` with a stored schema version.

**`{prefix}aicake_designs`** — one row per generated image.

```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
public_id       CHAR(32)      NOT NULL,       -- unguessable external handle
session_key     VARCHAR(64)   NOT NULL,
ip_hash         CHAR(64)      NOT NULL,
user_id         BIGINT UNSIGNED DEFAULT NULL,
product_id      BIGINT UNSIGNED DEFAULT NULL,
variation_id    BIGINT UNSIGNED DEFAULT NULL,
prompt_raw      TEXT          NOT NULL,       -- as typed, Lithuanian
prompt_en       TEXT          DEFAULT NULL,   -- translated
prompt_final    TEXT          DEFAULT NULL,   -- + style suffix, what was actually sent
text_payload    JSON          DEFAULT NULL,   -- {text, font, colour, size, placement, arc}
provider        VARCHAR(40)   DEFAULT NULL,
model           VARCHAR(80)   DEFAULT NULL,
seed            BIGINT        DEFAULT NULL,
aspect          VARCHAR(12)   DEFAULT NULL,
status          VARCHAR(20)   NOT NULL,       -- see state machine §6.3
moderation      JSON          DEFAULT NULL,   -- verdict + reasons + which layer
file_master     VARCHAR(255)  DEFAULT NULL,   -- clean generated image, never served
file_preview    VARCHAR(255)  DEFAULT NULL,   -- watermarked, shaped, downscaled
file_print      VARCHAR(255)  DEFAULT NULL,   -- final print file, post-payment only
cost_usd        DECIMAL(10,5) DEFAULT 0,
error_code      VARCHAR(40)   DEFAULT NULL,
error_message   TEXT          DEFAULT NULL,
created_at      DATETIME      NOT NULL,
updated_at      DATETIME      NOT NULL,
UNIQUE KEY (public_id),
KEY (ip_hash, created_at),
KEY (session_key, created_at),
KEY (user_id, created_at),
KEY (status, created_at)
```

**`{prefix}aicake_jobs`** — the work queue (see §6).

```sql
id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
design_id     BIGINT UNSIGNED NOT NULL,
type          VARCHAR(24)   NOT NULL,   -- preview | fulfil
status         VARCHAR(16)  NOT NULL,   -- queued|claimed|running|done|failed
attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
claimed_at    DATETIME      DEFAULT NULL,
claim_token   CHAR(32)      DEFAULT NULL,
payload       JSON          DEFAULT NULL,
created_at    DATETIME      NOT NULL,
KEY (status, created_at),
KEY (design_id)
```

### 4.5 Images do **not** go in the Media Library

`idea.md` stores an `attachment_id`. Do not do this.

Every abandoned preview would become a `wp_posts` row plus 4–6 `wp_postmeta` rows plus
generated thumbnail sizes. A few thousand browsing sessions makes the Media Library unusable,
bloats the posts table, and slows every admin screen. It also makes the files
**publicly reachable by design** — the Media Library is a public URL scheme.

Files live on disk under our own path, tracked by the `aicake_designs` row. Only the *final
approved print file* optionally gets attached to the order as a real attachment, and even
then only if it needs to appear in an email.

---

## 5. Pipeline

Each stage is a small class with one public method, composable and individually testable.

```
                    ┌─ preview path (pre-payment) ────────────────────────┐
prompt (LT) ──► Sanitise ──► Blocklist ──► LLM(translate+moderate) ──►
                    ──► Generate (1 MP) ──► [store master] ──► Shape ──►
                    ──► TextLayer(preview res) ──► Watermark ──► Downscale ──► preview.webp
                    └──────────────────────────────────────────────────────┘

                    ┌─ fulfilment path (post-payment) ────────────────────┐
master ──► Upscale (only if short §3.1) ──► TextLayer(print res) ──►
       ──► Shape + bleed ──► Imposition (if copies>1) ──► DPI metadata ──► print.png
                    └──────────────────────────────────────────────────────┘
```

Note the text layer is applied **twice**, at two resolutions, never upscaled. Upscaling
rendered text is what makes cheap print shops look cheap.

The preview is rendered at the true output aspect and shape, so what the customer approves is
what they get.

---

## 6. Sync vs async — decided: **always async**

### 6.1 Why

Three independent reasons, any one sufficient:

1. **Worker pool.** 4–8 workers on shared hosting. Blocking one for 5–15 s per generation
   means a handful of concurrent customers takes the whole site down, storefront included.
2. **Provider portability.** fal and Replicate are queue-based; Google and OpenAI are single
   POST. A job abstraction hides the difference so the provider can change without touching
   the frontend.
3. **Retrofitting is expensive.** Adding polling later means rewriting the JS, the REST
   contract, the error handling, and the loading UX. Doing it first costs roughly a day.

The frontend therefore *always* polls, even when the underlying provider is sub-second.

### 6.2 Dispatch on plain WordPress

We cannot install a worker. Three mechanisms, layered, each backing up the last:

1. **Loopback spawn (primary).** The `POST /generate` handler writes the job row, then fires
   `wp_remote_post( admin_url('admin-post.php'), ['blocking' => false, 'timeout' => 0.01] )`
   at a runner endpoint and returns immediately (~100 ms). The runner does the real work in a
   separate worker.
2. **Poll-triggered execution (fallback).** Some hosts block loopback requests. If a job is
   still `queued` after ~3 s, the *polling request itself* claims and runs it. Slower and it
   does occupy a worker, but the site keeps working. Detected once at activation by a
   self-test that pings the runner and records whether loopback works.
3. **Action Scheduler (safety net).** A recurring AS action sweeps for jobs stuck in `queued`
   or `claimed` past a timeout and re-runs or fails them. This is the only reliable route on
   hosts where both of the above misbehave, and it is what guarantees post-payment fulfilment
   eventually happens.

Post-payment fulfilment uses **Action Scheduler directly** — no latency requirement, and AS
gives retries, logging and an admin UI for free. It already ships with WooCommerce.

### 6.3 Job state machine

```
queued ──► claimed ──► running ──┬──► done
   ▲                             ├──► failed        (terminal, error shown)
   └───── (timeout sweep) ───────┴──► queued        (attempts < 3)
                                      rejected      (moderation, terminal, no retry)
```

`claimed` uses an atomic `UPDATE ... SET status='claimed', claim_token=? WHERE id=? AND
status='queued'` and checks `affected_rows`. This is the only correct way to prevent two
workers running the same job when loopback and poll-trigger race. **Idempotency matters here
because a duplicate run costs real money.**

### 6.4 Concurrency cap

A global in-flight limit (default **3**, admin setting) protects the worker pool. Jobs beyond
the cap stay `queued` and the UI shows a position. Implemented as a `COUNT(*) WHERE status IN
('claimed','running')` guard at claim time — cheap and index-backed.

### 6.5 Polling contract

```
POST /wp-json/aicake/v1/generate      → 202 { job_id, design_id, poll_after_ms }
GET  /wp-json/aicake/v1/job/{job_id}  → 200 { status, progress, preview_url?, error? }
```

Poll every 1.5 s, backing off to 3 s after 15 s, hard timeout at 90 s. The status endpoint is
one indexed SELECT — it must not bootstrap anything heavy.

---

## 7. The caching gotcha

Any production WordPress will have page caching. **A cached product page serves a stale nonce,
and every generation request from a logged-out visitor fails with 403.** This breaks the
entire product for exactly the anonymous visitors who make up most traffic, and it will look
like a random intermittent bug.

Fix: never print the nonce into the cached HTML. The JS fetches it on first interaction from
an uncached endpoint:

```
GET /wp-json/aicake/v1/session   → { nonce, session_key, remaining_generations }
```

That endpoint sends `Cache-Control: no-store` and is excluded from page cache by path. It also
sets the `session_key` cookie, which conveniently means the throttle identity is established
before the first generation.

### 7.1 …and the half that only applies to anonymous visitors (D-025)

Everything above is about **anonymous** traffic — which is the only traffic a page cache serves.
Logged-in requests bypass every page cache worth the name, because `wordpress_logged_in_*` is a
standard bypass condition, so there is no stale-nonce risk for them.

There is, however, a chicken-and-egg that makes the endpoint unable to serve them at all. Core's
`rest_cookie_check_errors()` authenticates a cookie-carrying REST request **only when a valid
nonce is already present**. `/session` deliberately sends none, so WordPress treats the caller as
user 0 and mints a nonce for user 0 — which then fails against the real login cookie on the next
request with `rest_cookie_invalid_nonce`.

So the rule is per-audience:

| Visitor | Nonce comes from | Because |
|---|---|---|
| Anonymous | `GET /session` | their page is cached; a printed nonce would be stale |
| Logged in | printed into the page | their page is never cached, and the endpoint can only mint them a user 0 nonce |

The JS prefers a printed nonce whenever one is present, including on the `/session` call itself —
which is what makes that call authenticate, and therefore what makes `remaining_generations`
report the logged-in allowance (§11.3) instead of the anonymous one.

### 7.2 The same rule, for everything a browser issues on its own (D-028)

This is not a quirk of `/session`. **Any request to our REST routes carrying only cookies is
user 0**, which includes every plain link, `<img src>`, form post and redirect — anything the
browser issues without our JavaScript attaching a header.

It bit a second time on the admin order screen, where the print-file download is an ordinary
link gated on `manage_woocommerce`: a real shop manager clicking it got 404, because the
capability check ran against user 0. Those URLs carry `?_wpnonce=` for exactly that reason.

So, for any endpoint reached outside our own `fetch()` calls: **the caller supplies a nonce, or
the endpoint must not depend on who is asking.**

---

## 8. Providers — verified 2026-08-02

### 8.1 The Imagen 4 problem

**`idea.md` recommends Google Imagen 4 Fast. Imagen 4 is deprecated and shuts down on
2026-08-17 — fifteen days from this writing.** Do not build on it.

Its replacement in the Google line is Gemini 3.1 Flash Image ("Nano Banana 2").

### 8.2 Current pricing

Image generation:

| Model | Cost | Notes |
|---|---|---|
| FLUX.2 [klein] (fal) | **$0.009 / MP** | Sub-second. Arbitrary dimensions. Cheapest usable tier. |
| FLUX.2 [dev] (fal) | $0.012 / MP | |
| FLUX.2 [pro] (fal) | $0.03 / MP | |
| Gemini 3.1 Flash Image | $0.045 @512px · **$0.067 @1K** · $0.101 @2K · $0.151 @4K | Fixed aspect list. Native 4K ≈ 16 MP. |
| Gemini 3.1 Flash Lite Image | $0.0336 @1K | |
| Gemini 2.5 Flash Image | $0.039 (batch $0.0195) | |
| GPT Image 2 | $0.005 – $0.211 | Token-metered by quality tier + size. |
| GPT Image 1 Mini | from $0.005 @1024² low | |
| ~~Imagen 4 Fast~~ | ~~$0.02~~ | **Shutting down 2026-08-17.** |

Upscaling:

| Option | Cost | Notes |
|---|---|---|
| Real-ESRGAN (fal / Replicate / ModelsLab) | ~$0.005 | Faithful. Excellent on flat illustration and cartoon art — which is exactly our content. |
| Clarity upscaler (Replicate) | ~$0.01–0.03 | Adds invented detail. Risk: changes the approved design. |
| Crystal (Replicate) | ~$0.025 | Faithful. |
| Imagick Lanczos (local) | $0 | No detail added, but for 2× on clean vector-ish art it is genuinely acceptable. Free fallback. |

### 8.3 Recommendation

**Primary preview: FLUX.2 [klein] on fal — $0.009/MP.**
Sub-second, arbitrary dimensions (solves the A4 ratio), cheapest tier, and fal exposes a
synchronous `fal.run/...` endpoint so the adapter stays simple.

**Quality tier / fallback: Gemini 3.1 Flash Image.**
Better prompt adherence when klein produces something incoherent. Single POST, image returned
inline — the simplest possible `wp_remote_post()` integration.

**Upscale: Real-ESRGAN, with local Lanczos as the free fallback.**
Faithful, not creative. A creative upscaler that "improves" the design after the customer
approved it is a refund waiting to happen.

Two important consequences of the server-side text decision:

- **Text rendering ability no longer matters when picking a model.** The single hardest
  benchmark for image models is now irrelevant to us, which frees the choice to be made on
  illustration quality and price alone. This is a bigger deal than it sounds.
- We can and should push "no text, no letters, no writing" hard in the negative prompt.

### 8.4 Why generate-at-4K is *not* the answer

Gemini can emit 4K natively for $0.151, which superficially removes the upscaler. Rejected:

- The customer approved a *specific image*. Re-generating at 4K post-payment produces a
  **different image** unless the model is seed-deterministic across resolutions, which is not
  guaranteed. Printing something the customer never saw is the worst possible failure.
- Generating 4K up front means paying $0.151 for every abandoned preview.

Upscaling the approved master is the only approach that guarantees the printed image is the
approved image. Keep it.

### 8.5 Provider abstraction

Three interfaces, so a provider swap is a settings change:

```php
interface ImageProvider {
    public function generate( GenerationRequest $r ): GenerationResult;
    public function supportedAspects(): array;
    public function supportsArbitraryDimensions(): bool;
    public function estimateCost( int $megapixels ): float;
    public function id(): string;
}

interface UpscaleProvider {
    public function upscale( string $path, int $factor ): string;
    public function maxFactor(): int;
    public function estimateCost( int $megapixels ): float;
}

interface TextProvider {                    // translation + moderation in one call
    public function analyse( string $promptLt ): PromptAnalysis;
}
```

`ProviderRegistry` resolves primary → fallback chain from settings. On timeout, 5xx, or
malformed response, it falls through and records which provider actually served the request
(the `provider` column) so cost and quality can be compared later.

An admin **"Test provider"** button runs a fixed prompt against each configured provider and
shows the results side by side with cost and latency. Cheap to build, and it is how the
provider decision actually gets made — on real cake-topper prompts, not benchmarks.

### 8.6 Cost per order

Assume 6 previews generated per completed order (5 free + 1 after signup):

| Line | Cost |
|---|---|
| 6 × translate+moderate LLM call | ~$0.0006 |
| 6 × FLUX.2 klein preview @1 MP | $0.054 |
| 1 × Real-ESRGAN upscale (only for large SKUs) | $0.005 |
| **Total per order** | **~$0.06 ≈ €0.055** |

Cupcake sheet orders skip the upscale entirely (§3.1), so ~$0.055.

Against a €10–20 product this is under 0.5%. **Do not optimise the model choice for cost.
Optimise for abuse prevention and output quality.** The dominant cost risk is not per-call
price, it is an unthrottled endpoint being hammered — §11.

---

## 9. Imaging engine

### 9.1 GD is the target. Imagick is not available.

**Confirmed on the live host, 2026-08-02** (wp-admin → Site Health → Media Handling):

```
Active editor        WP_Image_Editor_GD
ImageMagick version  none
Imagick version      none
GD version           bundled (2.1.0 compatible)
GD formats           GIF, JPEG, PNG, WebP, BMP
Ghostscript          not detected
```

The host is a managed platform, not a Linux machine — the client can install PHP libraries and
WordPress plugins, but no system packages. So this is settled, not a risk to monitor: **there is
no Imagick in production and there will not be.**

Two useful details in that output: **WebP is supported**, so watermarked previews can be WebP as
planned; and Ghostscript is absent, which would have mattered only for PDF — already dropped
(D-009).

This inverts the usual framing. GD is not a degraded fallback we tolerate; **GD is the
platform**, and every feature must be complete and good-looking on it. Imagick, where present,
is used for a handful of quality improvements.

Everything the product needs is achievable in GD:

| Operation | GD implementation | Imagick, if available |
|---|---|---|
| Downscale | `imagecopyresampled` — bicubic, genuinely fine for downscaling | Lanczos, marginally sharper |
| Upscale (local fallback) | Bicubic — soft. Real weakness; see below | Lanczos, noticeably better |
| Circle mask | Row-span alpha fill + anti-aliased annulus (§9.1.1) | `setImageMask` |
| Straight text | `imagettftext` with TTF, full UTF-8 | `annotateImage` |
| Text outline / shadow | Draw the glyph run 8× offset, then the fill on top | Stroke settings |
| **Arc / curved text** | **Achievable** — per-character placement with per-call rotation (§9.4) | `distortImage(ARC)`, smoother |
| N-up imposition | `imagecopyresampled` per cell | `compositeImage` |
| PNG output | `imagepng` | — |
| DPI metadata | **Replace** the `pHYs` chunk in the PNG bytes — GD writes its own at 96 DPI, so inserting a second one is malformed and reads as 96 (D-027) | `setImageResolution` |
| WebP preview | `imagewebp` (present in effectively all modern builds) | — |
| CMYK soft proof | **Not possible without ICC** — see §9.5 | `profileImage` with an ICC profile |

Only **one** feature is genuinely lost on GD: true ICC colour-managed soft-proofing. §9.5 was
already scheduled as v1.5 and off by default, so nothing in v1 depends on it.

The other real cost is **upscale quality**. GD has no Lanczos, so the free local upscaler is
weaker than it would be with Imagick — which raises the value of the paid Real-ESRGAN path for
the large SKUs. Phase 0 Suite B should therefore compare Real-ESRGAN against **GD bicubic**,
not against Imagick Lanczos, or it will measure a fallback we will not have.

Note that dropping PDF output (D-009) removed what would otherwise have been a second
Imagick-only feature. PNG-only was a lucky decision.

### 9.1.1 Circle masking in GD without being slow

The naive approach — loop every pixel, test whether it is inside the circle, set alpha — is
5.9 M iterations of PHP for a 2433 px topper. Slow enough to matter.

Instead, for each row compute the circle's x-span analytically and clear the two outside
segments with `imagefilledrectangle`. That is ~2 fills per row, about 4 900 operations instead
of 5.9 M. Then anti-alias only the ~2 px annulus at the boundary per-pixel — roughly 30 k
pixels, negligible.

Result is visually indistinguishable from Imagick's mask at a fraction of the effort.

**For the final print file, flatten onto white rather than keeping alpha.** On a white icing
sheet, "no ink" and "white" are the same output, and some printer drivers mishandle alpha in
PNGs. Keep alpha only for the on-screen preview, where the round shape needs to read as round.

### 9.1.2 The one remaining unknown: FreeType

Site Health does not report it, and **the entire text layer depends on it.**
`imagettftext()` needs GD compiled with FreeType. Without it there is no TrueType rendering at
all — GD's built-in bitmap fonts are tiny, ugly and unusable on a cake topper.

Bundled GD is compiled with FreeType on essentially every managed WordPress host. There is also
direct evidence in the Site Health output above: **that GD build supports WebP**, which requires
an explicit `--with-webp` at compile time — a *rarer* configure flag than FreeType. A build with
WebP almost certainly has FreeType too.

So we proceed on the assumption it is present, and confirm before Phase 4 rather than now. The
client reasonably declined to upload a diagnostic to a live shop, and nothing in Phases 0–3
touches text rendering.

Three ways to confirm, cheapest first:

1. Hosting control panel → PHP info / extensions. Read-only, uploads nothing.
2. The plugin's own Site Health panel, which reports it at activation — i.e. on the day we
   install on live, before any customer sees anything.
3. `tools/host-check.php`, held in reserve. It goes further than a capability flag by rendering
   `ĄČĘĖĮŠŲŪŽ ąčęėįšųūž` and counting ink, proving diacritics actually come out.

If FreeType turned out to be missing, that — and only that — would justify reconsidering the
architecture. Everything else already works on GD.

### 9.1.3 Do we need an external render server? No.

Worth answering explicitly, because it is the natural next thought when a host turns out to be
this restricted.

Everything in the pipeline is covered by **GD + pure PHP + the AI APIs we are already calling**:

| Concern | Answer |
|---|---|
| Masks, bleed, text, imposition, watermark, PNG | GD, all of it |
| DPI metadata | Hand-written `pHYs` chunk, pure PHP |
| Upscaling | Already an external API (Real-ESRGAN via fal) — by design, since before payment |
| Colour management | Calibrated LUT in pure PHP (§9.5) |
| Background jobs | Action Scheduler, already shipped with WooCommerce |

The two things Imagick would have added are Lanczos resampling and ICC soft-proofing. Lanczos is
displaced by the paid upscaler, which is an external service we were always going to use.
ICC is displaced by the LUT.

So the "external small server" option is *already satisfied* by the provider APIs — without
running, paying for, securing, monitoring or backing up a second machine, and without adding a
single point of failure between the storefront and a customer's order. Keep it in reserve; do
not build it.

### 9.1.4 Develop against GD, not against Imagick

The testbed has Imagick installed. Production probably will not. If we develop against Imagick
we will discover the difference at the worst possible moment.

So: **`AICAKE_FORCE_GD` defaults to on in the testbed.** All development and all screenshots
happen on the GD path. Imagick gets switched on deliberately, to compare output quality and to
confirm the enhancement path still works.

Capability detection runs at activation, is stored in an option, and is surfaced in a **Site
Health** panel — so before go-live we can see exactly what the real host provides rather than
guessing.

### 9.2 Memory

An A4 sheet at 300 DPI is 2480 × 3508 = 8.7 M pixels. In Imagick that is ~35 MB at 8-bit RGBA,
more during compositing. 4096² intermediates are ~67 MB. Realistically the fulfilment path
needs **256 MB** and will be uncomfortable at 128 MB.

Mitigations, all worth doing:
- `Imagick::setResourceLimit(MEMORY)` and stream to disk rather than holding intermediates.
- Destroy each intermediate explicitly (`$img->clear(); $img->destroy();`) — PHP's GC will not
  do this in time.
- **Tile the imposition**: composite each circle then free it, rather than loading 24 copies.
- Check `memory_limit` at activation and warn loudly if under 256 M.
- For cupcake sheets, downscale the master to the per-circle size *once* and reuse that small
  image 24 times. A 603 px circle composited 24 times is trivial; a 4096 px master composited
  24 times is not.

### 9.3 Watermarking

Composited into the pixels, server-side, on the **derivative only**. The clean master is never
served under any URL.

- Diagonal tiled text across the whole image, not a corner logo. **~42% opacity, dark ink with
  a light halo** — the original 25% white was measured against nothing and vanished into the
  white background the house style produces (D-032). Tunable via `watermark_opacity`.
- Preview served at ~800 px max edge — big enough to judge, too small to print.
- Serve as WebP (smaller, and one more small obstacle to casual reuse).
- Also apply the shape mask to the preview so the customer sees a circle, not a square.

### 9.4 Text layer

The feature that makes this product actually sell, and the one with the most hidden traps.

**Fonts must cover Lithuanian.** `ą č ę ė į š ų ū ž` and their capitals. Most decorative
display fonts — exactly the ones that look good on a cake — omit `ė` and `ū`. A missing glyph
renders as a blank box on a printed cake nobody can refund.

Therefore:
- Bundle a **curated set of 6–8 fonts**, each verified glyph-by-glyph against the full
  Lithuanian alphabet at build time by a test that fails if coverage regresses.
- Ship the licence file for each (SIL OFL or similar — must permit embedding and commercial
  use). No Google Fonts CDN — production has no external services and CSP/GDPR both argue
  against it.
- Verify coverage with a script over the TTF `cmap` table, not by eyeballing.

Renderer features, in priority order:

1. Straight text, configurable size / colour / position (top / centre / bottom).
2. Outline or drop shadow — essential for legibility over a busy generated background.
3. Auto-fit: shrink to fit the safe zone, wrap to a second line, never overflow.
4. **Arc text** following the circle edge. Extremely common on round toppers, so it needs to
   work on the GD path — and it can. Rather than warping a rendered strip (`distortImage(ARC)`,
   Imagick only), place each character individually: walk the arc, and for every character call
   `imagettftext` at its own position and its own tangent angle. `imagettfbbox` gives the
   advance width, so spacing stays correct.
   Kerning between adjacent pairs is lost, which is invisible on the short strings this is for
   ("Su gimtadieniu", a name). Where Imagick is available, use `distortImage` instead for a
   smoother result — same feature, better rendering, not a different capability.

The text is rendered independently at preview res and at print res from the same spec — never
scaled up from the preview.

### 9.5 CMYK soft proofing

Edible ink has a narrow gamut. Saturated blues, greens and purples print noticeably duller
than the screen preview, which generates "it looked brighter online" complaints.

Three levels, in the order we would actually build them:

- **v1 (cheap, and the only one certain to work):** the style suffix steers toward light
  backgrounds and bright-but-not-neon palettes, plus a short honest note under the preview.
  Costs nothing and removes most of the complaint.
- **v1.5, GD-compatible:** a fixed gamut-compression LUT applied in pure PHP — desaturate the
  colours edible ink cannot reach and slightly lift the darkest tones. Not colorimetrically
  correct, but it makes the preview *directionally* honest, which is the entire point. Works
  without Imagick. The compression curve can be calibrated once against a real printed test
  sheet, which is far more accurate for this specific printer and paper than a generic profile.
- **v2, only where Imagick exists:** true `profileImage` sRGB → CMYK → sRGB round trip with an
  ICC profile.

Given the production host probably lacks Imagick (§9.1), the middle option is the realistic
target. Calibrating it needs a printed test chart — worth doing once, at the point where real
orders start.

Dark backgrounds are worth blocking outright in the style suffix: they soak ink, look muddy,
and genuinely taste bitter.

**A useful detail:** the sheet is already white, so "white background" means *no ink at all* —
cheaper, faster to dry, and cleaner-looking. The house style should lean on this.

---

## 10. Moderation

Still the biggest business risk, and the ordering matters — each layer is cheaper than the next.

> **Every layer below is switchable, and the built-in blocklist is editable term by term
> (D-049).** This section describes the shipped defaults, not a policy the plugin enforces on the
> shop. Copyright exposure is Ruslan's, so the setting is his.
>
> One consequence worth reading before assuming what "off" does: **switching layer 2 off does not
> skip the call.** It is the same request that translates the prompt to English, which the image
> providers need — so off means the verdict stops being binding, not that the money is saved.

### Layer 0 — input sanity
Length cap 500 chars, strip control characters, reject empty/gibberish. Free.

### Layer 1 — blocklist, before spending anything

Editable from admin, one term per line, no code deploy.

**The Lithuanian-specific trap:** Lithuanian inflects nouns heavily, so a naive substring match
on "Elsa" misses `Elsos`, `Elsai`, `Elsą`, `Elsa's`. Character names are usually translated
outright — Spider-Man is `Žmogus-voras`, and that declines too (`Žmogaus voro`). Paw Patrol is
`Šunyčiai patruliai`.

So the matcher needs:
- Unicode normalisation + diacritic folding (`Žmogus` → `zmogus`) so `zmogus voras` also hits.
- **Stem matching**, not exact match — strip common LT endings (`-as -is -ys -us -os -ai -ą
  -ų -ai -ei -o -e`) from both sides before comparing.
- Both the Lithuanian *and* the English name in the list, since the check runs pre-translation.
- Word-boundary aware, to avoid a blocklist entry silently banning innocent words.

Ship a starter list covering the big franchises (Disney/Frozen, Marvel, Bluey, Peppa, Paw
Patrol, Pokémon, Minecraft, Roblox, Hello Kitty, Super Mario, Sonic, Barbie, Harry Potter…)
in both languages. Expect to grow it from real rejected prompts — so log every rejection.

### Layer 2 — LLM: translate + moderate in one call

One call, JSON out, ~$0.0001. Combining them halves latency and call count, and the classifier
sees the original Lithuanian rather than a lossy translation.

```json
{
  "prompt_en": "a smiling cartoon dinosaur with a birthday hat, on white background",
  "verdict": "allow",                    // allow | review | block
  "reasons": [],
  "categories": {
    "copyright_character": false,        // catches "the ice princess from that film"
    "real_person": false,                // likeness rights
    "brand_logo": false,
    "sexual": false,
    "violence": false,
    "hate_symbol": false
  },
  "confidence": 0.93
}
```

Three verdicts, not two. `review` generates the preview but flags the order for mandatory
human attention — it avoids frustrating false-positive rejections on innocent prompts while
still catching the ambiguous ones.

Design notes:
- **Fail closed on parse errors.** A malformed LLM response must not become an `allow`.
- Prompt-injection resistant: the user's text goes in a clearly delimited block with an
  instruction that content inside is data, never instructions.
- Cache verdicts by prompt hash — repeat prompts are free.
- The classifier only needs a cheap fast model. Claude Haiku, Gemini Flash Lite and GPT mini
  are all fine; pick whichever key the client already has.

### Layer 3 — a human sees the image before it prints. Non-negotiable, and **not software**.

> **Amended by D-047 (2026-08-04).** The requirement stands; the screen that was built for it is
> deleted. Ruslan does not review orders in wp-admin — he sees every image when he loads the
> icing sheet and presses print, so layer 3 was satisfied before any code existed. What follows
> is the original text, kept because the *reasoning* is still right and matters for anything
> that bypasses a prompt (the parked photo-upload idea, above all).
>
> **There is no approval status, no approval screen, and no rejection email.** The plugin sends
> the customer nothing, ever. If layer 3 ever has to move back into software — because somebody
> other than Ruslan is printing — that is a conversation to have, not a screen to rebuild
> quietly.

~~Nothing reaches the printer automatically. Order lands in `wc-awaiting-approval`, admin looks
at the actual rendered image for ten seconds, approves or rejects.~~ This catches everything the
first three layers miss, and it is the only layer that sees the *image* rather than the prompt.

~~Rejection triggers a templated apology email and a WooCommerce refund.~~ Whether a customer
hears anything, and whether money moves, is the shop's decision and is made in WooCommerce's own
tools.

### Supporting requirements
- Terms text **next to the prompt input**, not in a footer link: no copyrighted characters, no
  real people, orders may be cancelled and refunded.
- Log every rejection with prompt and layer — this is the data that grows the blocklist.

---

## 11. Rate limiting, identity and budget

### 11.1 Custom table, not transients

Correct call in `idea.md` and worth restating: with Redis or Memcached object caching,
transients can be evicted early and **the limiter silently stops limiting**. The failure is
invisible until the bill arrives. Use the `aicake_designs` table — it is the audit log and the
rate-limit source in one.

### 11.2 Composite identity

IP alone is weak in Lithuania specifically: mobile carriers use CGNAT so hundreds of customers
share one address, and IPv6 prefixes rotate. Key on three things:

- `SHA256(ip + AICAKE_IP_SALT)` — never the raw address
- `session_key` cookie set by the plugin
- `user_id` when logged in

Count against the **loosest** of the three for legitimate users, but apply a **hard per-IP
ceiling** as a separate, higher limit — otherwise clearing cookies resets the quota and the
whole thing is decorative.

Correct client IP resolution behind Cloudflare/proxies (`CF-Connecting-IP`,
`X-Forwarded-For` left-most-untrusted) with an admin setting for which header to trust.
Trusting `X-Forwarded-For` blindly lets anyone spoof unlimited identities.

### 11.3 Limits

- **5 free generations per session**, then require an account. Admin setting.
- Logged-in users get a higher allowance.
- Per-IP hard ceiling (default 30/day) regardless of cookies.
- Global concurrency cap (§6.4).
- Minimum 3 s between requests from one identity, to stop rapid-fire clicking.

### 11.4 Budget guard — the one that actually protects the bank account

A daily and monthly spend ceiling, checked before every paid call, summing `cost_usd`.

On breach: generation disabled site-wide, a clear "temporarily unavailable" message on the
product page, and an immediate admin email. Default €5/day, €50/month, both editable.

This is the difference between a bad day and a bad month. It is a small amount of code and it
is the single highest-value safety feature in the plugin. Build it in phase 1, not later.

---

## 12. Storage and protection

### 12.1 A configurable storage root, ideally outside the webroot

The storage location is a single constant, so it can move without touching code:

```php
define( 'AICAKE_STORAGE_DIR', '/var/lib/aicake' );   // anywhere, ideally outside the webroot
```

Default if undefined: `wp-content/uploads/aicake/`. **Production should override it** to a path
above `public_html`. Most Lithuanian hosts give you a writable directory beside the webroot;
if `valgomosdekoracijos.lt` does not, the uploads default still works — it just leans harder
on unguessable names.

Putting the root outside the webroot is what makes the rest of this section easy: if HTTP
cannot reach the directory at all, filenames no longer have to be cryptographically obscure,
and they can instead be **organised for a human**.

### 12.2 Two zones with opposite lifecycles

```
$AICAKE_STORAGE_DIR/
│
├── sessions/                        ← ephemeral, hashed, auto-cleaned
│   └── 2026/08/
│       ├── <32-hex>-master.png          clean generation, never served
│       └── <32-hex>-preview.webp        watermarked, shaped, ≤800 px
│
└── orders/                          ← permanent, human-readable, never auto-deleted
    └── 2026/08/
        └── 10432/                       WooCommerce order number
            ├── item-57-master.png       clean source, kept for reprints
            ├── item-57-print.png        final print file, 300 DPI
            ├── item-57-preview.webp     what the customer approved
            └── item-57.json             full reproduction record
```

**When an order is paid, the design is *moved* from `sessions/` to `orders/`** and the DB row
repointed. From that moment it is outside the reach of every cleanup job.

This directly serves the requirement: browse to `orders/2026/08/10432/` and the files are
there, named obviously, no database lookup needed. On the testbed that directory is
bind-mounted to the host, so it is a normal folder you can open (§ infra).

### 12.3 The `.json` sidecar

Cheap and worth far more than it costs on a project this long:

```json
{
  "order_id": 10432, "order_number": "10432", "item_id": 57,
  "created_at": "2026-08-02T14:31:09+03:00",
  "product": { "id": 88, "name": "Keksiukų dekoracijos ⌀4.5 cm, 24 vnt" },
  "print_spec": { "shape": "round", "width_mm": 45, "bleed_mm": 3,
                  "copies": 24, "sheet": "a4", "dpi": 300, "px": 603 },
  "prompt": { "raw_lt": "linksmas dinozauras su tortu",
              "en": "a cheerful cartoon dinosaur with a birthday cake",
              "final": "…, clean white background, bright colours, no text" },
  "text": { "value": "Su gimtadieniu, Emilija", "font": "Fredoka-Bold",
            "colour": "#E4007C", "placement": "bottom-arc" },
  "generation": { "provider": "fal", "model": "flux-2-klein", "seed": 8471209,
                  "aspect": "1:1", "cost_usd": 0.009 },
  "upscale": null,
  "moderation": { "verdict": "allow", "layer": "llm", "confidence": 0.94 }
}
```

Three payoffs: the order folder is self-describing without the database; a reprint is fully
reproducible; and if the DB is ever lost or migrated badly, the print files are still
identifiable. It is one `file_put_contents` at fulfilment time.

### 12.4 Protection

If the root is outside the webroot, HTTP cannot reach any of it and the rest is belt-and-braces.
If it is under `uploads/` (the fallback), these carry the weight:

1. **128 bits of unguessable filename** in `sessions/`. `random_bytes(16)` hex — not the design
   ID, not a prompt hash, not sequential; those are all enumerable.
2. **Never emit master or print URLs anywhere.** Not in HTML, not in JSON, not in emails. Only
   the watermarked, downscaled preview is ever public.
3. **A PHP gateway** for anything privileged, checking order ownership or `manage_woocommerce`:
   `GET /wp-json/aicake/v1/file/{public_id}/{variant}` → capability check → `readfile()` with
   `Content-Disposition`, correct type, and `X-Content-Type-Options: nosniff`.
4. `index.php` + `.htaccess` in each directory. **`.htaccess` does nothing on nginx** — it is
   free insurance on Apache, never a control we rely on.

Note the `orders/` tree deliberately uses *guessable* names, which is only safe because it is
either outside the webroot or served exclusively through the gateway. **If the client's
production host cannot provide a directory outside the webroot, `orders/` must switch to
hashed names too** — an admin-visible warning, not a silent downgrade.

Directory creation must fail loudly at activation if the root is not writable, rather than
silently at the first sale.

### 12.5 Retention

Two policies, because the two zones have opposite requirements:

| | `sessions/` | `orders/` |
|---|---|---|
| Files | Delete after **30 days** (admin setting) | **Never auto-deleted** |
| DB row | Keep prompt + verdict; null file paths | Kept indefinitely |
| Hard delete | After 12 months, or on GDPR erasure request | Only on GDPR erasure, or manual admin action |

Daily Action Scheduler task handles `sessions/` cleanup plus an orphan sweep in both directions
(files with no DB row, rows with no file). It must never touch `orders/`.

Storage growth is modest: a 603 px cupcake PNG is ~400 KB, an A4 print file ~8 MB. A thousand
orders a year of mixed sizes is a few gigabytes — cheap, and the reorder capability is worth it.

### 12.6 Reorder and reprint

Because `orders/` is permanent, both are straightforward and should ship in v1:

- **Reprint** (admin) — a button on the order screen: re-render from the stored master, or just
  re-download the existing print file. For damaged shipments and printer misfeeds. No API cost.
- **Reorder** (customer) — WooCommerce's native "Order again" copies line items; a hook copies
  the design reference across so the customer gets the identical design rather than an empty
  prompt field. This is the "two weeks later they want the same again" case, and without the
  hook Woo would silently drop the design and print a blank.

Both are cheap now and awkward to retrofit, because retrofitting means backfilling design
references onto historical orders.

---

## 13. WooCommerce integration

### 13.1 HPOS is mandatory, and it changes things

High-Performance Order Storage is the default in current WooCommerce. Two concrete
consequences that break naive plugins:

- **Order meta must use `$order->update_meta_data()` / `$order->save()`**, never
  `update_post_meta( $order_id, ... )`. The latter writes to a table WooCommerce no longer
  reads.
- Custom order statuses need registration through **`woocommerce_register_shop_order_post_statuses`**
  in addition to `register_post_status` + the `wc_order_statuses` filter, and bulk actions need
  hooking on **both** `bulk_actions-woocommerce_page_wc-orders` (HPOS) and
  `bulk_action-edit-shop_order` (legacy).

Declare compatibility explicitly at plugin load:

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
```

`cart_checkout_blocks` matters too — the block-based checkout is the default for new stores,
and classic-only cart integrations silently show nothing there.

**Confirmed 2026-08-02: this site uses the classic shortcode cart** (`/krepselis/` renders
classic markup, no block markers). So the §13.2 hooks work directly and the Store API
integration is not needed. Declare block compatibility anyway — it costs one line and prevents
a scary incompatibility warning in wp-admin — but do not build the Store API path until the
live site actually moves to block checkout.

### 13.2 Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_before_add_to_cart_button` | prompt UI, text fields, preview area |
| `woocommerce_add_to_cart_validation` | reject if no design selected, or design not owned by this session |
| `woocommerce_add_cart_item_data` | attach `design_id` + a unique key so two designs don't merge into one line |
| `woocommerce_get_item_data` | thumbnail + prompt in cart and checkout |
| `woocommerce_checkout_create_order_line_item` | persist design to order item meta |
| `woocommerce_order_status_processing` | enqueue fulfilment job |
| `woocommerce_admin_order_item_headers` / `_values` | print file + preview in the admin order screen |
| `woocommerce_email_order_meta` | proof image in the customer email |

The unique-key detail matters: without it, two different designs on the same variation collapse
into one cart line with quantity 2, and the customer receives two copies of one design.

**Ownership check on add-to-cart is a real security control**, not a nicety — otherwise anyone
can put an arbitrary `design_id` in the request and buy someone else's design.

### 13.3 Order statuses — **there are none. Superseded by D-047.**

The shop runs the ordinary WooCommerce flow and moves orders by hand:

```
sustabdytas  →  vykdomas  →  įvykdytas       (on-hold → processing → completed)
```

The plugin adds nothing to that and **never calls `update_status()`**. Rendering happens in the
background off the `processing` transition and reports itself in **private order notes**, which
are admin-only and email nobody.

> The five statuses this section used to define — `aicake-rendering`, `-approval`, `-approved`,
> `-rejected`, `-failed` — were a second order process running beside the one the shop actually
> uses. Deleted with the screen that drove them.

### 13.4 Fulfilment — **there is no job system. Superseded by D-048.**

The shop presses **Atsisiųsti spausdinimui** on the order. If the print file is on disk it is
served; if not it is rendered, archived and served. That is the whole of fulfilment.

```
Download  →  archived file?  →  yes: serve it
                             →  no:  render (~1 s) → archive → serve
```

~~`woocommerce_order_status_processing` → enqueue one Action Scheduler job per line item →
render → attach print file → when *all* line items are done, flip the order status.~~

> All of that existed to keep a slow render off the request. **Measured: 0.75–1.1 s** for a full
> A4 sheet at 300 DPI. Under a second does not need a queue, a retry ladder, an attempt counter,
> a „Ruošiama…" state or a failure email. Deleted, all of it.
>
> `CLAUDE.md`'s second constraint is untouched: it forbids blocking a worker on anything
> **customer-facing**. This is wp-admin, one shop manager, by deliberate click.

Still idempotent — the archived file is checked first, so pressing the button twice serves the
same bytes rather than making them again.

On failure: the reason is shown to the person who pressed the button, on the order screen they
are already looking at. **Nothing is emailed and nothing is written to the order.** There is no
background any more, so there is no silent failure to surface — and pressing the button again
*is* the retry.

---

## 14. Admin

1. **Settings** — providers + keys (constant-first, §16), limits, budget caps, retention,
   printer usable area, watermark text, style presets, "Test provider" button.
2. **Blocklist editor** — textarea, one term per line, plus a list of recent rejections with a
   one-click "add to blocklist".
3. ~~**Review queue**~~ — **built, then deleted at Ruslan's instruction (D-047).** He does not
   review orders in wp-admin; he sees every image at the printer. Do not rebuild it.
4. ~~**Print queue**~~ — **cut by Ruslan (D-047).** The print file is a download button on the
   order screen, which is where he already is. The v1.5 idea underneath it is still good and
   still unscheduled: combine several *single* toppers from different orders onto one A4 to save
   sheets — a direct materials saving.
5. ~~**Cost dashboard**~~ — **cut by Ruslan (D-047).** `BudgetGuard` already mails him when a
   ceiling is crossed, and the data stays in `aicake_designs` if this is ever wanted.

> **What survives of §14 is 1, 2, the formats page (D-038) and the design column on the order
> screen.** The rule D-047 sets: this plugin does not add screens the shop has to visit. Anything
> it needs to say, it says on the order the shop is already looking at.

---

## 15. Frontend UX

Most cake orders are placed on a phone. Design mobile-first.

- Prompt field with 3–4 example prompts in Lithuanian as clickable chips. People do not know
  what to type; examples raise output quality more than any prompt engineering.
- Live character counter, disabled state until a variation is chosen.
- Generate button shows remaining free generations.
- While polling: a progress indicator with rotating status text. It takes 5–15 s and silence
  feels broken.
- **Session history strip** — thumbnails of everything generated this session, click to
  reselect. Customers routinely prefer generation #2 after seeing #5, and losing it is
  infuriating. This costs nothing; the images are already stored.
- Preview shown in the *actual shape* (circle for round SKUs) with the safe zone marked.
- Text controls: text input, font picker rendering each name in that font, colour, position.
- Terms notice directly under the prompt field.
- Clear "add to cart" state showing which design is selected.
- Explicit lead-time note ("pagaminame per X d. d.").

Progressive enhancement: no design chosen → the add-to-cart button is disabled with an
explanation, never a silent failure.

---

## 16. Security

- **API keys via constants first.** Settings page reads `AICAKE_*` constants if defined and
  falls back to options only if not; the field shows "defined in wp-config.php" and disables
  itself. Keys in `wp_options` sit in every database backup.
  ```php
  define( 'AICAKE_FAL_KEY',    '...' );
  define( 'AICAKE_GEMINI_KEY', '...' );
  define( 'AICAKE_LLM_KEY',    '...' );
  define( 'AICAKE_IP_SALT',    '...' );   // change invalidates all IP-based limits
  ```
- Nonce on every REST request; nonce fetched from an uncached endpoint (§7).
- `permission_callback` on every route — never `__return_true` on anything that spends money.
- Capability checks (`manage_woocommerce`) on all admin routes.
- All rate limiting server-side. Frontend limits are cosmetic.
- Prompt sanitised and length-capped before it reaches any API.
- Design ownership verified on add-to-cart and on every file request.
- `$wpdb->prepare()` everywhere; escape on output; no direct `$_REQUEST` reads.
- Outbound calls: explicit timeouts (10 s connect, 60 s total), TLS verification on, response
  size cap, and never log a full API key.
- Uninstall routine that actually removes tables, options and files — behind a "delete all
  data" confirmation.

---

## 17. i18n

- Text domain from day one, every string in `__()` / `esc_html__()`.
- Ship a `.pot`; Loco Translate handles the Lithuanian from there.
- **Lithuanian plural forms are `nplurals=3`** (1, 2–9, 10–20/…) — not the 2-form English
  pattern. Use `_n()` correctly or counts will read wrong.
- Currency, date and decimal formatting via WooCommerce helpers, not `sprintf`.
- Admin can stay English if the client prefers; customer-facing strings must all be
  translatable.

---

## 18. Compliance

Not legal advice — confirm each before launch.

- **VMVT registration.** Anyone placing food on the market in Lithuania must be registered as a
  *maisto tvarkymo subjektas* (food handling business) with their territorial Valstybinė maisto
  ir veterinarijos tarnyba department. Registration is indefinite, may or may not involve an
  on-site inspection depending on activity type, and requires hygiene-compliant premises plus a
  *savikontrolės* (self-control) system.
  **Almost certainly already in place here** — valgomosdekoracijos.lt already sells edible
  decorations, so the client is already a food business. The AI feature changes *which image*
  goes on a product already being sold; it does not change food status. Worth confirming the
  existing registration covers printing, but not expected to be a new obligation.
- **Allergen declaration** on packaging. Get the exact statement from the icing sheet supplier;
  typically starch, sugar, sometimes soy lecithin.
- **GDPR.** IP hashing (never raw), stated retention windows, cleanup cron, and prompts
  included in the privacy policy and in the WordPress personal-data export/erase hooks —
  prompts are user-generated content and can contain personal data. Wiring into
  `wp_privacy_personal_data_exporters` / `_erasers` is maybe an hour's work and makes a data
  request trivial to answer.
- **Distance selling.** Custom-made goods are generally exempt from the EU 14-day withdrawal
  right, but the exemption must be *clearly disclosed before purchase* to apply. A checkbox at
  checkout is the usual mechanism. Verify.
- **EU AI Act Article 50** requires machine-readable marking of AI-generated content. Its scope
  for a printed cake decoration is genuinely unclear, but the low-cost hedge is: keep C2PA /
  SynthID metadata that providers embed rather than stripping it, and state on the product page
  that images are AI-generated. Both are free. Worth a question to the client's accountant or
  lawyer, not a blocker.

---

## 19. Plugin structure

Split by responsibility, not by hook. **No Composer at runtime** — a simple SPL autoloader maps
`AiCake\Foo\Bar` → `src/Foo/Bar.php`. Deployment is "upload the folder". Composer is dev-only
for PHPCS/PHPUnit and `vendor/` never ships.

```
ai-cake-topper/
├── ai-cake-topper.php           bootstrap: constants, autoloader, HPOS declaration, activation
├── uninstall.php                gated full teardown
├── readme.txt
│
├── src/
│   ├── Plugin.php               composition root — wires everything, registers hooks
│   ├── Installer.php            dbDelta, schema version, capabilities, directory creation
│   ├── Capabilities.php         Imagick/GD/memory/loopback detection → Site Health
│   │
│   ├── Support/
│   │   ├── Settings.php         constant-first config resolution (§16)
│   │   ├── Logger.php
│   │   ├── Http.php             wp_remote_* wrapper: timeouts, retries, key redaction
│   │   └── Mm.php               mm ⇄ px, bleed, safe zone — the §3 maths, unit-tested
│   │
│   ├── Domain/
│   │   ├── Design.php  DesignRepository.php
│   │   ├── Job.php     JobRepository.php      atomic claim lives here
│   │   ├── PrintSpec.php        shape, size, bleed, copies, sheet — read from variation meta
│   │   ├── TextSpec.php         text, font, colour, placement, arc
│   │   └── PrintFile.php        a rendered print file and what it took to make it
│   │
│   ├── Providers/
│   │   ├── ImageProvider.php  UpscaleProvider.php  TextProvider.php     (interfaces)
│   │   ├── ProviderRegistry.php        primary → fallback chain
│   │   ├── Image/     FalFluxProvider.php  GeminiImageProvider.php  OpenAiImageProvider.php
│   │   ├── Upscale/   FalUpscaler.php  ReplicateUpscaler.php  LocalLanczosUpscaler.php
│   │   └── Text/      GeminiTextProvider.php  ClaudeTextProvider.php  OpenAiTextProvider.php
│   │
│   ├── Pipeline/
│   │   ├── PreviewPipeline.php   orchestrates the pre-payment path
│   │   ├── FulfilPipeline.php    orchestrates the post-payment path
│   │   └── Stage/
│   │       ├── SanitiseStage.php    ModerationStage.php   GenerateStage.php
│   │       ├── UpscaleStage.php     TextLayerStage.php    ShapeStage.php
│   │       ├── ImpositionStage.php  WatermarkStage.php    ProofStage.php
│   │
│   ├── Imaging/
│   │   ├── ImageEngine.php      interface
│   │   ├── ImagickEngine.php  GdEngine.php
│   │   ├── TextRenderer.php     fonts, outline, auto-fit, arc
│   │   ├── FontCatalogue.php    bundled fonts + LT glyph coverage
│   │   ├── SheetLayout.php      cols × rows from circle size + usable area (§3.5)
│   │   └── Watermarker.php
│   │
│   ├── Moderation/
│   │   ├── Blocklist.php        diacritic folding + LT stem matching
│   │   ├── LtNormaliser.php     the inflection handling, unit-tested against real declensions
│   │   └── Verdict.php
│   │
│   ├── Throttle/
│   │   ├── IdentityResolver.php ip hash + cookie + user, proxy-header aware
│   │   ├── RateLimiter.php
│   │   └── BudgetGuard.php      daily / monthly spend ceiling
│   │
│   ├── Queue/
│   │   ├── Dispatcher.php       loopback spawn → poll fallback → AS sweeper (§6.2)
│   │   ├── Runner.php           claims and executes one job
│   │   └── Scheduler.php        Action Scheduler registration
│   │
│   ├── Storage/
│   │   ├── PrivateStorage.php   unguessable paths, write, delete, orphan sweep
│   │   └── OrderArchive.php     sessions/ → orders/, DB repoint, the .json sidecar
│   │                            (delivery is Rest/FileEndpoint.php, not a separate gateway)
│   │
│   ├── Rest/
│   │   ├── RestController.php   route registration
│   │   ├── SessionEndpoint.php  uncached nonce + session_key (§7)
│   │   ├── GenerateEndpoint.php JobStatusEndpoint.php  FileEndpoint.php
│   │
│   ├── WooCommerce/
│   │   ├── ProductFields.php    VariationFields.php
│   │   ├── CartIntegration.php  OrderIntegration.php
│   │   ├── OrderStatuses.php    HPOS-correct registration
│   │   ├── Fulfilment.php       AS jobs, idempotency, status transitions
│   │   └── Emails.php
│   │
│   ├── Admin/
│   │   ├── SettingsPage.php     BlocklistPage.php
│   │   ├── ReviewQueue.php      PrintQueue.php      CostDashboard.php
│   │   └── OrderScreen.php
│   │
│   ├── Privacy/  Exporter.php  Eraser.php
│   └── Cron/     Cleanup.php   Retention.php
│
├── assets/
│   ├── js/generator.js          prompt UI, polling, session history
│   ├── js/admin-review.js
│   └── css/
├── fonts/                       curated LT-safe TTFs + licence files
├── profiles/                    optional CMYK ICC profile
├── templates/                   overridable frontend partials
├── languages/                   ai-cake-topper.pot
└── tests/                       PHPUnit — Mm, SheetLayout, LtNormaliser, atomic claim
```

Two structural notes:

- **`Plugin.php` is the only place that knows how things are wired.** Every other class takes
  its dependencies in the constructor. That is what makes phases 1–5 testable without
  WooCommerce loaded at all.
- **`Support/Mm.php`, `Imaging/SheetLayout.php` and `Moderation/LtNormaliser.php` are pure
  functions with no WordPress dependency.** They hold the logic most likely to be subtly wrong
  — print maths, imposition, Lithuanian stemming — so they get real unit tests.

---

## 20. Failure modes

Each of these will happen; each needs a defined behaviour rather than a stack trace.

| Failure | Behaviour |
|---|---|
| Provider times out (preview) | Fall to next provider; if all fail, `failed` + friendly LT message + **no quota consumed** |
| Provider times out (post-payment) | ~~AS retry ×3 → `render-failed` + admin email~~ **D-048:** the Download button reports the reason on the order screen; pressing it again is the retry. The production upscaler is GD in PHP, so this path needs no provider at all |
| Loopback blocked by host | Poll-triggered execution (§6.2); flagged in Site Health |
| Imagick missing | GD path; arc text, CMYK proof and PDF hidden |
| `memory_limit` too low for A4 | Detected at activation, warned; imposition tiles to stay under |
| Budget cap hit | Generation disabled site-wide, clear message, admin emailed |
| LLM returns unparseable JSON | Fail closed — treat as `review`, never `allow` |
| Two workers claim one job | Atomic claim (§6.3); loser exits |
| Customer switches variation after generating | Warn + free regeneration (§4.3) |
| Order paid, design row deleted | Fulfilment regenerates from stored prompt + seed, flags for review |
| Disk full | Activation and pre-write checks; fail the job cleanly rather than writing truncated files |

---

## 21. Build order

Phases 1–4 are testable with no WooCommerce involvement at all — a WP-CLI command and an admin
test page are enough. That keeps the fiddly image work out of the checkout flow.

**Phase 1 — foundation**
Plugin skeleton, autoloader, tables, settings with constant-first keys, capability detection,
Site Health panel, logger. Rate limiter + **budget guard**. Nothing spends money until the
guard exists.

**Phase 2 — providers**
Three interfaces, FLUX/fal + Gemini adapters, LLM translate+moderate adapter, registry with
fallback, "Test provider" admin screen. Verify real prices against real invoices here.

**Phase 3 — job system**
Jobs table, atomic claim, loopback dispatcher, poll fallback, AS sweeper, REST endpoints,
concurrency cap. Test explicitly with loopback deliberately broken.

**Phase 4 — imaging**
ImageEngine + Imagick/GD, shape mask, bleed, watermark, text renderer with font coverage tests,
imposition, DPI metadata. This is the largest phase; budget accordingly.

**Phase 5 — moderation**
Blocklist with LT stemming, LLM classifier, three-verdict handling, rejection logging.

**Phase 6 — storefront**
Product + variation fields, generator UI, polling JS, session history, cart integration, add-to-
cart validation and ownership checks.

**Phase 7 — orders and fulfilment**
Custom statuses (HPOS-correct), AS fulfilment jobs, idempotency, print file storage, gated
download, admin order screen.

**Phase 8 — operations**
Review queue, print queue, cost dashboard, cleanup cron, emails.

**Phase 9 — hardening and ship**
i18n pass + `.pot`, privacy hooks, uninstall, security review, load test on the testbed with
loopback disabled and a low memory limit to simulate cheap hosting, then production deploy.

---

## 22. Open questions

Nothing here blocks Phase 1.

1. **Real cupcake diameter sold.** I have assumed 4.5 cm to reach 24 per A4. If it is 5 cm the
   sheet yields 20, and the SKU name must say 20. Affects product naming and pricing, not code.
2. **Printer make and model** — for the usable print area default (§3.4). Currently 200 × 287 mm,
   a reasonable guess. Output format is settled: PNG.
3. **Exact icing sheet dimensions.** Client says all paper is A4 but the icing sheet is slightly
   shorter; to be corrected at a late stage. Until then the sheet size is an admin setting and
   the imposition maths reads it, so the fix is a number, not a code change.
4. **Which LLM for translate + moderate.** Decided by Phase 0 Suite C. Gemini Flash Lite pairs
   naturally if Gemini is also the image fallback — one vendor, one key.
5. **Free generation limit** — 5 is a guess. Tune against real conversion data once live.

Resolved during planning: block vs classic checkout (**classic**, §13.1) · print file format
(**PNG**, D-009) · reprint and reorder (**both in v1**, D-010) · SKU model (**product per size,
material as variation**, D-005).

---

## 23. What changed from `idea.md`

| Area | `idea.md` | Here | Why |
|---|---|---|---|
| Google model | Imagen 4 Fast | Gemini 3.1 Flash Image | **Imagen 4 shuts down 2026-08-17** |
| Image storage | WP Media Library `attachment_id` | Own table + private paths | Media Library bloat; attachments are public by design |
| Upscaling | Always 4× post-payment | Only when the SKU needs it | Cupcake sheets need 603 px; native 1024 already exceeds it |
| Text on toppers | Not addressed | Server-side text layer, curated LT-safe fonts | Diacritics; also removes text rendering from model selection |
| Async | Open question | Decided: always async, 3-tier dispatch | 4–8 worker pool on shared hosting |
| Nonce | "verify a nonce" | Uncached endpoint for anonymous, printed for logged-in (§7.1) | Page cache serves stale nonces → 403 for anonymous; the endpoint can only mint a user 0 nonce → 403 for logged-in |
| Order storage | `register_post_status` | HPOS-correct registration + meta API | HPOS is the current default |
| Blocklist | Substring match | Diacritic folding + LT stem matching | `Elsos`, `Žmogaus voro` — Lithuanian inflects |
| Budget | Not addressed | Hard daily/monthly spend cap | Highest-value safety feature per line of code |
| Imposition | "24 on A4" | Derived from circle size + usable area | Count is a consequence of geometry, not a setting |
| Sheet slack | Not addressed | Printer non-printable margin as a setting | 200×287 usable, not 210×297 |

---

## Sources

Prices and API status verified 2026-08-02:

- [Gemini Developer API pricing](https://ai.google.dev/gemini-api/docs/pricing) — Imagen 4 deprecation date, Gemini 3.1 Flash Image tiers
- [Gemini 3.1 Flash Image model docs](https://ai.google.dev/gemini-api/docs/models/gemini-3.1-flash-image) — resolution and aspect ratio support
- [FLUX.2 on fal](https://fal.ai/flux-2) and [FLUX.2 pro](https://fal.ai/models/fal-ai/flux-2-pro) — per-megapixel pricing
- [FLUX.2 Klein 4B](https://openrouter.ai/black-forest-labs/flux.2-klein-4b) — klein tier
- [GPT Image API pricing](https://pricepertoken.com/gpt-image-pricing) — GPT Image 2 / 1.5 / mini tiers
- [OpenAI image pricing breakdown](https://www.aifreeapi.com/en/posts/openai-image-generation-api-pricing)
- [Replicate super-resolution collection](https://replicate.com/collections/super-resolution) — upscaler options
- [Real-ESRGAN API pricing](https://modelslab.com/real-esrgan)
- [Action Scheduler performance docs](https://actionscheduler.org/perf/) — loopback behaviour, worker pool exhaustion
- [HPOS-compatible custom order statuses](https://www.tychesoftwares.com/how-to-show-custom-order-status-in-bulk-actions-on-woocommerce-orders-page-compatible-with-hpos-order-tables/)
