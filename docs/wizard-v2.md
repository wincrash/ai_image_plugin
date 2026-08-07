# Wizard v2 — the design

**Status: agreed in discussion 2026-08-07, not built.** Ruslan's instruction was *"discuss, plan,
decide, and only then implement."* This is the plan half. Decisions are recorded individually as
D-054 → D-062; this document is the shape they add up to, and it is the thing to read first when
picking the work up cold.

`PLAN.md` §4–§6 describe the v1 wizard. Where this document disagrees with them, **this document
wins** and the superseded sections are listed in §13.

---

## 1 · Why this exists

The v1 wizard does one thing: a Lithuanian prompt becomes an AI picture, which becomes a cart
line. It works end to end (D-033 → D-045).

Ruslan's scope for v2, in his words: *"as user i want to have just cupcakes with my only custom
text, so here is no ai image at all. next case user may upload his image, cut circle from it and
use it for circle/cupcake decoration with posiblity add text. the third user may want to generate
ai image, add text and so on. and for future will be one more, use llm to get image from internet."*

So the product stops being "an AI generator" and becomes **a decoration designer with four ways of
getting a picture into it**, one of which is AI.

---

## 2 · The shape, in one line

**One wizard, one WooCommerce product, five steps, and exactly one of those steps branches.**

Not four wizards. The four paths differ in *where the picture comes from* and in nothing else —
same formats, same editor, same proof, same cart, same print file. Four wizards would mean four
cart hand-offs and four sets of the same bugs. From the customer's side it may well *look* like
separate wizards; that is presentation, and it is free.

---

## 3 · The five steps

| # | Step | Text only | Upload | AI | Search |
|---|---|---|---|---|---|
| 1 | **What are you making** — pick a source | shared | shared | shared | shared |
| 2 | **Format** — shape + size, and sheet type | shared | shared | shared | shared |
| 3 | **Artwork** | *skipped* | upload → crop | prompt → generate | query → pick |
| 4 | **Text and layout** — the existing editor | shared | shared | shared | shared |
| 5 | **Proof → cart** | shared | shared | shared | shared |

Step 3 is the only branch. Steps 4 and 5 are the code that already exists and is verified, and
they must survive this refactor untouched in substance — `editor.js`, `ProofPipeline`,
`CartIntegration`, `Fulfilment`.

**Step 1 disappears when only one source is enabled** (§9). A wizard that opens on a single card
asking you to choose is worse than no step at all.

---

## 4 · The four sources

`source` becomes a column on `aicake_designs`, one of:

| `source` | Picture comes from | Costs us | Moderation that applies |
|---|---|---|---|
| `none` | nothing — text on a blank sheet | nothing | layers 0–1 on the typed text |
| `upload` | the customer's own file | nothing | **nothing automatable** — see below |
| `ai` | fal `flux/dev` | $0.012 | layers 0–2 on the prompt |
| `search` | an image-search result | per-query, small | layers 0–1 on the query |

**`source` is the spine of v2.** Pricing reads it (§8), the toggles gate it (§9), retention does
not care about it, and the print path is identical for all four because by step 4 there is either
a master bitmap or there isn't.

> **Two of the four sources are unmoderatable by software, and that is now written down.** For
> `upload` there is no prompt to read and the bitmap is arbitrary; for `search` the picture was
> chosen by a machine from the open internet. Moderation layers 0–2 are blind to both. The
> control is **Ruslan looking at every sheet before he prints it** — which is real, and which he
> already does (D-047), but it is a person and not code, and it holds only as long as he prints
> personally. See D-060.

---

## 5 · Format collapses to shape + size

Ruslan: *"a4, circle, cupcakes, really almost the same, so i dont like treat it seperatly like we
do now."* He is right, and the code already half agrees — `SheetLayout` derives every count from
geometry and nothing is tabulated (D-038).

What is wrong is the *exposure*: `FormatCatalogue` publishes three **types** (`sheet`, `circle`,
`cupcake`) with three separate size lists, which is the old ten-product model showing through.

**One axis instead:**

| Choice | Is really |
|---|---|
| A4 sheet | one rectangle, 210 × 282 mm |
| ⌀15 cm topper | one circle |
| ⌀4,5 cm cupcakes | 24 circles |

Shape ∈ {circle, rectangle}, plus a size. **Count is always derived, never chosen and never
tabulated.**

**The chooser is drawn, not described.** A grid of cards, each showing the actual sheet layout —
24 circles arranged on an A4 — with the count under it. `Admin/FormatsPage::diagram()` already
renders exactly this from `SheetLayout::plan()`; the work is moving it to the frontend, not
inventing it. This is Ruslan's parked "live diagram beside the size choice" idea becoming the
primary UI rather than a nice-to-have.

**The diagrams are drawn client-side from a small JSON layout plan.** They are on the page for
every visitor, which by §6's rule puts them firmly on the browser's side. Server-rendering sixteen
diagrams per page view would be the single worst thing on the page.

> **The one rule that must not be broken:** the diagram keeps deriving from `SheetLayout`. Fixed
> pictures would let the preview and the print drift apart silently — D-038's whole argument, and
> the ⌀4.0 cm case really did move 35 → 30 → 35 in one afternoon as the usable area was corrected.

Ruslan's instruction on this: **build v1 of it, then he reviews the representation.** If it is
wrong it is wrong in presentation only, which is cheap to change.

---

## 6 · The client/server seam

Ruslan's rule, and it is sharper than "heavy work goes to the client":

> **Work that scales with the number of *browsing users* moves to the client.
> Work that scales with the number of *orders* stays on the server.**

His reasoning: the host is small and the site is already slow; a spike once per order is
affordable, a spike per visitor is not.

| Work | Scales with | Side | Why |
|---|---|---|---|
| Format diagrams | page views | **client** | on the page for everyone who looks |
| Photo decode / downscale / crop | uploads | **client** | a 12 MP phone photo is ~48 MB decoded in GD |
| Text layer rasterisation | designs | **client** | already is (D-033) |
| Proof compositing | designs | **client** | the browser already drew it; a capture, not a second rendering |
| Preview (mask + watermark) | generations | **server** | not load — the master is never servable, and a client-side watermark means handing over the unwatermarked master |
| AI generation, translation, moderation | generations | **server** | keys, and verdicts must bind |
| Ownership, throttle, budget | requests | **server** | it is the control |
| **Final print file at 300 DPI** | **orders** | **server** | Ruslan's call, and correct |

**The final print file stays on the server.** It is the 339 MB path (D-023), but it runs when
Ruslan presses „Atsisiųsti spausdinimui" — once per order, in wp-admin, never with a customer
waiting (D-048).

> **A number to re-measure before treating M0.3 as real work.** The 339 MB was measured rendering
> **a 15 cm round *and* a 24-up sheet in one pass** — that is the check doing two formats in one
> process. A real order item is one sheet. **The per-item peak has never been measured**, and it
> may already be under production's 256 MB. Measure before optimising.

---

## 7 · The browser is never trusted to have drawn anything

This is the constraint that shapes everything client-side, and it comes from a measurement.

**What the wizard already does today:** `editor.js` `exportLayer()` allocates a canvas at the
**true print size** — 2481 × 3331, **8.3 megapixels**, for a cupcake sheet — and calls
`toDataURL('image/png')` on it. That is shipping code, and until 2026-08-07 no browser check in
this project had ever run on a phone.

**Measured (`tools/phone-canvas-check.html`):** a POCO X3 Pro — 2021 mid-range, not a flagship —
cleared **35 megapixels**, and encoded the real sheet in 117 ms. Android has room to spare.

**But Android is not the audience.** The live shop's own statistics: **iOS 16.1% against Android
11.1%**, and on mobile **Mobile Safari beats Chrome Mobile close to two to one**. Desktop's 67% is
inflated by crawlers (GNU/Linux 14%, IE 1.5%), so the mobile share is a floor. Facebook's in-app
browser is another 2.2% and on iOS that is WKWebView — same engine, same ceiling, tighter memory.

**iOS remains unmeasured.** Ruslan has no iPhone. So:

> **The design assumes iOS cannot build the sheet, and treats Android's headroom as a bonus.**
> An iOS measurement arriving later can only relax constraints, never add them.

### 7.1 · The contract

**Every canvas the browser produces is verified before it is trusted.** Write known pixels into
three corners, read all three back, and put a byte floor under the encoded image.

This is not defensive programming for its own sake. **Safari on iOS does not throw when a canvas
exceeds its area budget** — it returns one that reads back transparent, and `toDataURL()` then
produces a perfectly valid *blank* PNG. A check that asks "did it throw" reports success on a
broken device.

Then, in order:

1. **Verify.** Corners read back, encoded bytes above the floor.
2. **If it fails, probe downward** once per session for the largest canvas this device can do, and
   cache the answer.
3. **Never send a blank layer.** Refuse, say so in Lithuanian, and fall back.

### 7.2 · This is a live bug, and it is fixed first

**Corrected 2026-08-07 by reading the code.** An earlier draft of this section said a silent
canvas failure would print a sheet with no names on it. That is **wrong**: `LayerInspector`
already refuses a zero-ink layer (`empty` → 422), and `wizard.js`'s `finishText()` does not
advance on a failed save. Nothing bad prints and no order completes.

**The real failure is a dead end with a message that blames the customer.** They type a name,
press save, and are told **„Užrašas tuščias."** — *your text is empty* — while looking at their
text on the screen. They cannot proceed, cannot fix it, and nothing they can change is the
problem. On what the statistics say is the majority mobile platform, that is a silently lost sale
reported as "the wizard is broken", with nothing in the logs pointing at the cause.

**Fixed as its own small change, before the refactor starts** (§14, step 0), and scoped to three
things: **detect** the device failure in the browser, **say something true** about it, and **log
it** so it stops being invisible.

> **Recovery is deliberately not in step 0.** Rendering the layer smaller and letting the server
> scale it up collides with `FulfilPipeline`'s rule that a layer is *never* scaled — stretching
> one puts text across a cut line while still producing a plausible file. Trading that for
> slightly soft text is a real decision and it is **Ruslan's**, not a detail to settle inside a
> bug fix. See §15.6.

### 7.3 · Why there is no cheap fallback

The obvious fallback — send a *recipe* (text, positions, fonts, colours as ratios) and let the
server rasterise — **is the design D-033 deleted.** It means two renderers that must agree
pixel-for-pixel and drift apart the moment either changes. Rebuilding it as an iOS-only path
resurrects exactly the problem this project spent a phase escaping.

So the fallback is *degrade the canvas*, not *move the renderer*.

---

## 8 · Pricing

Ruslan: *"base for now is 3.5, while depending on what you use ai/search/blank/uploaded can have
diffirent additional cost like +1 or more eur"* and *"we will adjust it by fields ... just like
laksto tipas."*

**The plugin owns no pricing.** That is D-036 and it does not move. WC Fields Factory prices
everything, and Ruslan edits amounts in wp-admin where he edits every other price.

| Field | Options | Today |
|---|---|---|
| „Lakšto tipas" | krakmolo / storas / cukrinis | +0 / +1,00 / +1,50 — exists |
| **„Piešinio tipas"** (new) | `none` / `upload` / `ai` / `search` | **amounts are Ruslan's** |

This replaces today's binary „AI paveikslėlis: taip/ne" field with a four-option one.

**The value is derived server-side from the design's `source` and never posted.** That is D-044's
control and the reason it exists: *a posted flag about whether money was spent can never be
trusted.* Hiding the field is not a control; server-side derivation is.

Field keys stay resolved by label at runtime (`FieldsFactory::field_key()`) — WCFF generates them
randomly, so nothing may hardcode one.

**Sheet type is asked at step 2**, Ruslan's call, revisitable: *"laksto tipas can be in begining
or in the end ... for now lets do in beginign, later we will see."* Cheap to move; WCFF owns it
either way. The upside of asking early is that the quoted price is complete and correct from step
2 onward.

---

## 9 · Sources are switchable, and a disabled source does not exist

Ruslan wants AI generation and image search independently switchable in settings — partly as
insurance, partly as a rollout lever: ship the editor first, turn AI on later as a marketing
moment.

**A disabled source is gone from the wizard.** Ruslan: *"it should dont show in wizard meniu at
all."* Not greyed out, not "coming soon" — absent.

**And the endpoint refuses it too.** Both, not either. Hiding a control is not a control — the
same lesson as the WCFF field, learned twice already in this project. The missing button is the
UI; the endpoint check is the lock behind it.

Consequences:

- Only one source enabled → **step 1 does not render at all**, and the wizard opens on format.
- Zero sources enabled → the shortcode renders a plain Lithuanian "not available" message rather
  than a broken wizard.
- Switches live in `Support/Settings` and the existing `Admin/SettingsPage`, alongside the
  moderation switches — the D-049 pattern, which already works and is already tested.

---

## 10 · Uploads

Ruslan: *"User can upload what he wants (must be image, maybe need think only on security). thats
it."* So: no gating, no review step, no rights workflow. Security only.

### 10.1 · The customer never hears the word "format"

Ruslan: *"lots users have now idea what is png, so maybe accept other formats also."*

**The browser decodes, the browser converts.** The customer picks a file, the client loads it into
a canvas, and uploads PNG or JPEG from there. That means we accept anything *the browser* can
decode — JPEG, PNG, WebP, GIF, BMP, and **HEIC, which is what iPhones shoot by default** and which
GD could never read. The conversion is free because the client is already downscaling and cropping.

### 10.2 · The security boundary

The threat is not a rude picture — Ruslan sees that at the printer. It is **a file that is not
really an image**.

| Control | Why this one |
|---|---|
| **Re-encode through GD, discard the original** | The actual defence. Decode to pixels, re-save. Strips EXIF, ICC, embedded payloads, PHP in a comment chunk. What survives is provably pixels |
| **Check dimensions *before* decoding** | A 200 KB PNG can decode to 30000 × 30000 and kill the worker. `getimagesize()` first, refuse absurd dimensions, *then* decode. **This is the one that could take the site down** |
| **Reject SVG outright** | It is a document, not a bitmap — script and external entities |
| `finfo` MIME, never the filename or extension | Extensions are customer-supplied strings |
| Stored in `sessions/`, outside the webroot | Already how everything works |
| Served only via ownership-checked `FileEndpoint` | Already how everything works |
| Byte cap on the request | Before anything is read into memory |

**The client downscales before uploading, and the server never trusts that it did.**

> A one-line "you have the rights to this image" checkbox was proposed and Ruslan declined it —
> it is a checkbox, not a workflow, and the offer stands if he changes his mind.

---

## 11 · Storage and retention

Ruslan: *"what about for all temporal files use files with experation ... so that way we dont need
even cron jobs? Also i hope all these final order images is in seperate folder, not in global
upload."*

**The second half is already true.** Everything lives in `/var/lib/aicake/` with two zones,
`sessions/` and `orders/`, **outside the webroot entirely**. Nothing of this plugin has ever been
written to the media library. Production's preflight confirmed the target is writable under
`open_basedir` (`docs/migration.md` §1).

**On self-expiry:** nothing on a filesystem deletes itself. Something has to run — but it does
**not** have to be cron.

**Opportunistic sweep**, the pattern PHP's own session GC uses: on a small fraction of generation
requests, delete a bounded batch of expired designs. No Action Scheduler, no wp-cron, bounded work
per request, and it self-regulates — no traffic means no growth to clean either.

| | |
|---|---|
| Authority | the **database row**, not the file |
| Candidate | `created_at + N days` old **and** `order_id IS NULL` |
| Sliding expiry | touch a timestamp on access — trivial on the row |
| Batch | bounded (≈20), so no request ever pays much |
| **Never a candidate** | **anything with an `order_id`. Ever.** |

`order_id` exists on the design row precisely so this is answerable without a query per file.

> **The first assertion written, and the first one falsified: an ordered design is never
> collected.** Everything else in retention is a tuning question; this one is a customer's paid
> order disappearing.

This replaces Phase 8's item (d), the last thing Phase 8 had left.

---

## 12 · What lives in the browser, and what has to reach the server

Ruslan's theoretical question: *"maybe some files store directly in user session browser? and
server will hold only last final images?"*

**The line: the browser holds work in progress; the server holds anything money has touched.**

IndexedDB can hold the master and the composition state during editing — `localStorage` cannot, it
is 5 MB. That is genuinely useful and saves round-trips.

Two things force the server's hand and neither is negotiable:

- **The generation cost $0.012 before the customer did anything.** If it lives only in their
  browser and they clear it, they regenerate and the shop pays twice.
- **The shop takes bank transfer.** Between add-to-cart and a paid order there is a redirect and
  possibly days. If the artwork is only in the browser, the order arrives with no picture.

So: the master lands server-side the moment it is generated; the final composition lands the
moment it is added to cart; everything between those two points may live in the browser.

---

## 13 · What survives, what changes, what is new

**Survives untouched** — most of the 658 committed assertions:

`editor.js` · `generation.js` · the REST and polling contract · all of `Moderation/` ·
`SheetLayout` / `PrintSpec` / `Mm` geometry · `FulfilPipeline` · `OrderArchive` · `Admin/OrderScreen` ·
the WCFF money path · `Throttle/` · `Admin/SettingsPage` · `Storage/PrivateStorage`

**Changes:**

| File | Change |
|---|---|
| `Domain/FormatCatalogue.php` | three types → shape + size |
| `Frontend/Wizard.php`, `templates/wizard.php`, `assets/js/wizard.js` | step 1, drawn format grid, the step-3 branch |
| `Domain/DesignRepository.php`, `Installer.php` | `source` column, schema **5** (current is 4) |
| `WooCommerce/CartIntegration.php`, `FieldsFactory.php` | binary AI flag → four-option „Piešinio tipas" |
| `Rest/GenerateEndpoint.php` | refuse a disabled source |
| `Rest/TextLayerEndpoint.php` | refuse a blank layer |
| `assets/js/editor.js` | canvas verification, downward probe |

**New:** an upload endpoint · a client-side crop tool · client-side format diagrams · the search
provider and its endpoint · the retention sweep.

**Superseded in `PLAN.md`:** §4.1 (already superseded by D-035, further by D-055), §6.1–§6.3 (the
one-source wizard), and §12.5's cron-based retention.

---

## 14 · Implementation order

Each stage has a gate, and **no stage starts with the previous one unverified.** Every gate is a
committed, re-runnable check under `tools/` — verification that is not committed is not
verification.

| # | Stage | Gate |
|---|---|---|
| **0** | **Refuse a blank text layer** (§7.2) — the live bug | falsified by forcing a blank canvas; the endpoint must refuse and the customer must see a Lithuanian reason |
| 1 | `source` column, schema 5, retention sweep | an ordered design survives the sweep; an unordered expired one does not |
| 2 | Format model collapse + drawn grid | counts still match §3.5 exactly; `wizard-check` stays green |
| 3 | Step 1 and the source toggles | a disabled source is absent **and** the endpoint refuses it |
| 4 | Text-only path end to end | a cart line with no picture, priced by its own WCFF option |
| 5 | Upload + crop | the security list in §10.2, each item falsified |
| 6 | AI path re-verified on the new spine | the existing suites, unchanged |
| 7 | Image search | behind its toggle, off by default |

Stage 0 is independent of everything else and ships on its own.

---

## 15 · Assumptions, stated so they are visible

1. **iOS is unmeasured.** §7. The design assumes the worst; a later measurement can only relax it.
2. **The 339 MB peak may be an artefact of the check**, not the product. §6. Measure per item
   before treating M0.3 as work.
3. **Ruslan sets every price.** The field and the rules are built; the amounts are his (§8).
4. **Ruslan is the only moderation for `upload` and `search`.** §4, D-060. True today, and it is a
   person rather than code.
5. **The migration stays paused** until this lands. D-053's review must read the code that ships,
   and this changes the code that ships.
6. **How an affected device recovers is undecided, and it is Ruslan's call.** If a phone cannot
   build the 8.3 MP layer, the only route to a completed sale is rendering it smaller and letting
   the server scale it up — which breaks `FulfilPipeline`'s never-scale rule and costs sharpness
   in the printed text. The alternative is that those customers buy artwork without text. Neither
   is obviously right; step 0 makes the failure *visible* so the decision can be made on evidence
   instead of guesses.
