# Project state

**Updated:** 2026-08-07
**Phase:** **Migrating to production.** Phases 1–7 built, the wizard track finished
(D-033 → D-045), Phase 8 almost entirely cut (D-047, D-048). Phase 0 deferred (D-018).

> **🚚 The current work is the migration to `valgomosdekoracijos.lt`, and
> `docs/migration.md` is the plan.** Ruslan's decision on 2026-08-07: **straight to live**, no
> staging copy (the site is 17.8 GB), with the wizard page hidden behind an unguessable slug.
> *"dont disturb users"* is therefore the acceptance criterion for every step until a real order
> has been placed.
>
> Two blockers came out of production's Site Health dump and both are in §2 of that file:
> **`memory_limit` is 256M against our 339 MB measured peak**, and **Really Simple Security may
> block the REST API for logged-out visitors**, which is the wizard's entire audience.
>
> **🛑 The first upload to production is gated on a full code review in a fresh session**
> (D-053). Ruslan's instruction, and it is not a formality — there is no staging copy. See
> `CLAUDE.md`, top of file.

> **The preflight has been run against the live shop (2026-08-07) and both blockers are gone.**
> `ini_set( 'memory_limit', '512M' )` **sticks**, `open_basedir` is `/home/vaijos/:/tmp:/usr/share/pear`
> so the storage target **is writable**, GD FreeType is **confirmed** (668 samples of
> `ĄČĘĖĮŠŲŪŽ`), loopback works, and anonymous `/wp-json/` returns **200** — Really Simple
> Security is not blocking it. Full output in `docs/migration.md` §1.
>
> It also found the thing nobody was looking for: **production has no sodium**, so the openssl
> branch of the key store is the only one that will ever run there. That is D-052.

> **👉 WIZARD v2 IS DESIGNED AND NOT BUILT (2026-08-07). Read `docs/wizard-v2.md` first.**
> The wizard stops being "an AI generator" and becomes **a decoration designer with four sources**
> — text only, uploaded photo, AI, and image search — as **one wizard branching at one step**
> (D-054 → D-062). Everything below describing the wizard as an AI-only flow is v1.
>
> **🔴 D-063 — the biggest find so far, and it was live: no anonymous customer
> could ever save their text.** `editor.js` read `config.nonce`, which is empty
> for anonymous visitors on purpose, so `/text-layer` and `/layout` were posted with **no nonce
> at all** and every one of them saw „Sesija pasibaigė." That is the wizard's whole audience.
> Third and fourth instance of D-025's mechanism. Fixed: the editor is handed the nonce by its
> host. **`rest-check.sh` now knocks on all three nonced endpoints — it had only ever called
> `/generate`**, which is exactly why this survived.

> **✅ Steps 2–4 are done (2026-08-07).** **Step 2:** one format question, sixteen drawn cards,
> the type derived from the diameter (D-055) — `wizard-check` 61. **Step 3:** four sources,
> each switchable, each refused at the endpoint as well as hidden from the page (D-059) —
> falsified by watching `/generate` return **202 and queue a paid generation** with AI switched
> off. **Step 4:** the text-only path end to end, verified in a browser **as an anonymous
> visitor** — blank sheet with all 24 cut circles, a name typed, saved, proof rendered, cart
> reached. A text-only design gets **a plain white master**, so preview, proof, cart and print
> all work unchanged rather than five pipelines learning that a master is optional.
>
> **✅ Step 5 is done (2026-08-09) — the customer's own photograph.** `POST /upload`,
> `assets/js/cropper.js`, and **`tools/upload-check.php` — 18 assertions, mostly refusals.**
> The browser crops and scales, then posts a JPEG of one finished piece; the server re-encodes it
> to PNG and throws the original away, which **is** the security boundary (D-062, D-065).
> Proven in a browser: a ⌀15 cm crop arrives as **1843 × 1843**, exactly what `FulfilPipeline`
> builds, with no provider and no cost.
>
> **The control that protects the shop rather than the customer is the dimension check**, and it
> reads the PNG header *before* any decode. Falsified: without it, a forged 4 kB header declaring
> 20000 × 20000 reaches GD. The check's own fixture had to be rewritten first — the original built
> a real 20000² image and was quietly testing the byte cap instead.
>
> **✅ Steps 6 and 7 are done (2026-08-09). Wizard v2 is complete — all four sources work.**
>
> **Step 6** re-verified the AI path logged out with a real fal generation, and found the bug that
> was blocking it: `renderFontChoices()` threw at init, which killed `engine.loadSession()`
> because that was the *last* statement in the block — so an anonymous visitor had no nonce and
> every generation 403'd as „Sesija pasibaigė." The session call now runs **first** (D-066).
>
> **Step 7** is image search, and it is **Openverse** rather than a general web search — filtered
> to `license_type=commercial,modification`, which is exactly what printing and selling a
> decoration is (D-067). That answers D-060's licensing objection instead of accepting it.
> **Falsified: remove that one parameter and the first search returns `BY-NC`, `BY-NC-ND` and
> `BY-NC-SA`** — licences that forbid commercial use. Off by default.
>
> **`tools/search-check.php` (18) and `tools/upload-check.php` (18) are new.** Twelve suites, 706
> assertions, all green.

> **✅ Step 1 is done (2026-08-07) — `source`, schema 5, and retention without cron.**
> `aicake_designs` gains **`source`** (`ai` | `upload` | `search` | `none`), D-054's spine.
> It defaults to `'ai'` so **the column default *is* the backfill** — every row that existed
> before schema 5 was an AI generation — and no migration query was needed.
> `Storage/Retention.php` collects expired unpurchased designs **opportunistically from the job
> runner**, 1-in-4, bounded batch, no Action Scheduler and no wp-cron (D-061). New index
> `sweep (order_id, updated_at)`; `retention_days` 14 and `retention_batch` 20 in settings, and
> **0 days switches it off**.
> `tools/retention-check.php` is new — **11 assertions**, falsified by removing `order_id IS NULL`,
> which turns 3 red including the paid design's row *and* its files.
>
> **Expiry slides for free, and finding that out cost a debugging round.**
> `DesignRepository::update()` stamps `updated_at` on every call, so any touch of a design pushes
> its expiry out — which is exactly what Ruslan asked for, and which also means **that method
> cannot be used to fabricate an old row in a fixture.** The first run of the check aged three
> designs through it, got three fresh ones, and reported a sweep that correctly collected nothing.
> The fixture ages rows with a direct `$wpdb->update()` now, and says why.

> **✅ Step 0 is done (2026-08-07).** The empty-layer refusal now says two different things.
> `editor.js` probes the export canvas in two corners before drawing and remembers whether the
> device held them; `TextLayerEndpoint` tells a customer who typed nothing that their text is
> empty, and a customer who typed something that **their device could not build the image** —
> because for that second customer the words are on the screen and nothing they can change is the
> problem. The refusal is now logged **with the user agent**, so this stops being invisible.
> `text-check` is **35**, was 30; falsified by collapsing the two messages back into one, which
> turns exactly 2 red.
>
> **What step 0 deliberately did *not* do: recovery.** An affected customer still cannot buy text.
> Rendering the layer smaller and letting the server scale it breaks `FulfilPipeline`'s never-scale
> rule and costs sharpness — that is Ruslan's call, and `docs/wizard-v2.md` §15.6 holds it open.
>
> **And the client-side probe has never been seen returning false on a real device.** It uses the
> identical technique `tools/phone-canvas-check.html` used to return *true* on the POCO, so the
> mechanism works; the failing branch is reasoned, not witnessed. It needs an iPhone to witness.

> **Original finding, kept because it is what to look for:** `editor.js` allocates an 8.3 MP
> canvas and `toDataURL()`s it. On a device that cannot, the customer is told **„Užrašas
> tuščias."** — *your text is empty* — while looking at their text, and cannot proceed. Nothing
> prints wrong (`LayerInspector` refuses zero ink and `finishText()` does not advance), so this is
> **a silently lost sale, not a bad print** — an earlier draft of D-057 got that backwards and
> reading the code corrected it. iOS is the majority mobile platform here (16.1% against Android's
> 11.1%) and iOS is exactly where the canvas fails silently. Fix on its own, first (D-057).

> **👉 Current work: Ruslan is updating the wizard** (from 2026-08-07). The migration is paused
> on purpose until that lands — the code review (D-053) has to read the code that ships, so
> reviewing a wizard that is about to change would be wasted.
>
> **A session picking up the wizard** wants D-033 → D-045 for how it got its present shape, then
> `Frontend/Wizard.php`, `templates/wizard.php`, `assets/js/wizard.js`, `editor.js` and
> `generation.js`. `tools/wizard-check.php` (39) and `tools/text-check.php` (30) are the gates;
> `tools/rest-check.sh` is the only one that authenticates, and D-025/D-026 are what happens
> without it. Deploy with `tools\sync.ps1` before running any of them — they test the deployed
> copy.
>
> **Two standing rules that bite here.** Functional CSS only, no cosmetics against the testbed
> theme (Ruslan does those at ship time on the real theme, and the live theme is a separate
> project). And a 429 in any check is the throttle, not the thing under test — see the
> testbed-state warning further down, which has now cost time twice.

> **M0.1 and M0.2 are done (D-050, D-051).** There is a settings screen —
> **AI Cake Topper → Nustatymai** — carrying API keys (encrypted, constants still win), the
> throttle and budget limits, the house style suffix, a read-only host panel, and the
> generation counters with a reset button. `tools/settings-check.php`, 34 assertions, falsified
> four ways.
>
> **Next: M0.3, cutting peak memory below 256M.** It is the one blocker that is ours to fix.

> **A reset session picking this up:** read D-033 → D-045, then **D-047 and D-048**, which
> between them reverse D-046 and §13.4 and are what changes how you should think about this
> project. The wizard runs end to end — a Lithuanian prompt becomes a cart line at the right
> price with the finished picture on it — all server-side text rendering is deleted, and from the
> shop's side the whole plugin is now **a thumbnail and a download button on the order**.
> **Start here: the retention cleanup job, the last thing Phase 8 has left.**

> **The shape of the system after D-048, in one line.** Customer designs in the wizard → orders
> → Ruslan moves the order by hand exactly as he always has → he presses **Atsisiųsti
> spausdinimui** on the order and gets the A4 file, rendered on the spot in ~1 s. No statuses, no
> queue, no notes, no emails to anyone.

> **⚠ The scope rule this project keeps re-learning, now in its strongest form (D-047).**
>
> `PLAN.md` describing a workflow is **not** evidence the shop wants that workflow. §10 said a
> human must review every image, so I built a review queue; Ruslan does not review orders,
> because he already sees every image when he loads the icing sheet and presses print. The
> requirement was met before I wrote a line of it.
>
> What that screen actually was: **a second order process running beside the one the shop uses.**
> The shop moves orders sustabdytas → vykdomas → įvykdytas by hand and has done for years.
>
> **The plugin now touches no order status and sends the customer nothing, ever.** Both are
> asserted in `order-check` and both falsify. If you find yourself adding a status, a customer
> note or a screen the shop must visit daily, that is the thing to ask about first.
>
> This is the same rule as „customer-facing text and money are Ruslan's" — one level up. That
> one is about sentences; this one is about processes.

> **Ruslan is printing and checking formats** — corrections to geometry come from that
> (D-039, D-040), not from arithmetic.

> Read `WORKFLOW.md` for how we work, `PLAN.md` for the design, `DECISIONS.md` for why.

---

## Where we are

Phases 1–7 are built and verified on the testbed, most of it against live APIs. The chain runs
from a customer's Lithuanian prompt all the way to a 300 DPI print file in `orders/`.

**The last unknown is retired (D-030).** fal.ai is funded, and a *successful* generation has now
been seen end to end: `POST /generate` → 202 → job claimed → fal → master and preview on disk →
design `done`, inside an ordinary `rest-check.sh` run. That same real master went through
`FulfilPipeline` to a 1843×1843 print file at the 15 cm spec. Phase 7's synthetic master is no
longer the only thing the fulfilment chain has been fed.

**477 committed assertions, all green**, across seven suites — see the full list further down. The
product/pricing model changed substantially on 2026-08-03 (D-035 → D-039): **one AI product
rather than ten**, geometry on the design rather than the product, and **the plugin prices
nothing** — WC Fields Factory does.

> **Run `tools/order-check.php` as `-u www-data`, never `--allow-root`** (D-031). Run as root it
> leaves `orders/YYYY/MM` root-owned, and every subsequent real order then fails with „Nepavyko
> įrašyti spausdinimo failo." on a paid order. The gate passes and the shop breaks. Site Health
> now probes both zones and catches it; `find /var/lib/aicake -uid 0` should return nothing.

| Phase | Status |
|---|---|
| 0 · API evaluation | **Deferred** to a calibration step after the plugin runs end to end (D-018). |
| 1 · Foundation | **Done and verified on the testbed.** |
| 2 · Providers | **Done and verified against the live APIs.** |
| 3 · Job system | **Done and verified live, both dispatch paths.** |
| 4 · Imaging | **Done and verified on real print output.** |
| 5 · Moderation | **Done — all three automatable layers verified.** |
| 6 · Storefront | **Gate met — a prompt now becomes a preview.** Verified logged in *and* out, success path and failure path (D-030). |
| 7 · Orders | **Gate met — a test order produces print files**, from a real master as well as a synthetic one. 54 committed assertions. |
| 8–9 | Not started |

### Phase 7 — what exists and was verified

| File | What it does |
|---|---|
| `WooCommerce/Fulfilment.php` | **`ensure_print_file()`** — render on demand, idempotent, reorder carry-over (D-048) |
| `Pipeline/FulfilPipeline.php` | master → upscale → shape → text at 300 DPI → imposition → flatten → PNG |
| `Storage/OrderArchive.php` | `sessions/` → `orders/`, DB repoint, the `.json` sidecar |
| `Domain/PrintFile.php` | The rendered file and what it took to make it |
| `Admin/OrderScreen.php` | **The only screen the shop uses** — thumbnail + „Atsisiųsti spausdinimui", which renders if needed |
| `tools/order-check.php` | **The gate, committed and re-runnable — 54 assertions** |

Produced and inspected, not just asserted, from a real
`woocommerce_order_status_processing` transition:

- **15 cm topper** — 1843 px square, 300 DPI, 156.0 × 156.0 mm, circle-masked with arc text.
- **24-up cupcake sheet** — 2363 × 3390 px, 200.1 × 287.0 mm, 4 × 6 evenly gutterred.
- **The order folder** — `orders/2026/08/<id>/` with `item-N-print.png`, `-master.png`,
  `-preview.webp` and `item-N.json`, browsable on the SMB share exactly as §12.2 promises.

Also verified, re-run after D-048: paying schedules **no** background work and produces no file
on its own; the download button renders every item; pressing it twice serves the same bytes
rather than re-rendering; a missing master returns a Lithuanian reason and no path, and pressing
the button again after the master is restored produces the file; an ordinary sale with no design
has nothing to render; and "Order again" carries the design across.

**The order's status is unchanged by all of it, and no note is written** — that is D-047, and it
holds on the happy path, the failure path and the recovery path independently. The note count is
measured as a delta across `ensure_print_file()`, because WooCommerce writes its own
status-change notes and counting the whole order would measure WooCommerce rather than us.

Two bugs found while verifying, both fixed and both the same shape — the end-to-end result
looked correct:

- **D-027: every print file declared two resolutions.** GD writes its own `pHYs` at 96 DPI and
  we appended a second at 300. Malformed, and read as 96 by any decoder preferring the last
  chunk — the exact wrong-size print the chunk exists to prevent. Found from a libpng warning
  on stderr, not an assertion. Now covered by `GdEngineTest`.
- **D-028: the admin download button 404'd every time.** A plain link sends cookies and no
  nonce, so a shop manager is user 0 and the capability check fails. D-025's mechanism in a
  second place.

### Phase 6 — where it actually stands

| File | What it does |
|---|---|
| `Domain/PrintSpec.php` | `_aicake_*` meta → geometry, variation → product → default (§4.2) |
| `WooCommerce/ProductFields.php` | "AI Topper" tab, live summary computed server-side |
| `WooCommerce/CartIntegration.php` | Add-to-cart validation, ownership, cart display, order hand-off |
| `Pipeline/PreviewPipeline.php` | master → shape → watermark → WebP |
| `assets/js/generation.js` | The §6.5 polling contract and D-025's nonce rules |

> **`Frontend/Generator.php`, `templates/generator.php` and `assets/js/generator.js` are deleted**
> (D-047). The product-page generator was superseded by the wizard at D-034 and kept alive only
> because nothing had said to remove it. Everything below describing "the generator" as a
> product-page feature is history — the wizard is the only generation UI there is.

**Verified working:** five real products created and rendering; geometry correct per SKU read
straight off product meta (4.5 cm → 603 px, no upscale, 24/sheet; 15 cm → 1843 px, 2×; A4 →
2552×3579, 4×, generated 2:3); a configured count that disagrees with the geometry raises ⚠ in
the summary; the generator renders inside the live Blocksy theme with chips, counter, terms
notice and remaining-count; **no nonce in the markup**; the design field posts inside the
add-to-cart form; add-to-cart refuses a missing, unknown or someone-else's design and leaves
ordinary products alone.

Also fixed while looking at the rendered page: `[hidden]` lost to the theme on specificity so the
spinner showed permanently, and the button label ran into the remaining-count. Assets now version
by `filemtime()` when `WP_DEBUG` is on, because `AICAKE_VERSION` never changes during development
and every CSS edit appeared not to work.

### The logged-in nonce bug is fixed — D-026

The nonce is printed for logged-in users only, `/session` still serves anonymous ones, and the JS
prefers a printed nonce whenever there is one. Verified over real HTTP as a `customer`-role user:
`generate` returns **202** with the printed nonce and still **403**s with the user 0 nonce the old
path handed out; the anonymous path is unchanged and the cacheable HTML carries `"nonce":""`.

The bigger find: `/session` now authenticates, so **logged-in customers get allowance 20 instead
of the anonymous 5**. They had been quietly served the anonymous allowance — the exact thing
§11.3 offers as the reason to create an account, silently not working.

There is now a `testuser` / password `TestPass123` **customer** account on the testbed. The reason
this bug survived two phases is that every test ran as an admin or logged out; an admin-only
testbed tests one audience twice.

### Phase 5 — what exists and was verified

| File | What it does |
|---|---|
| `Moderation/LtNormaliser.php` | Diacritic folding + Lithuanian stemming. Pure, unit-tested |
| `Moderation/Blocklist.php` | Word-boundary stem matching, ~90 starter terms in both languages |
| `Moderation/Sanitiser.php` | Layer 0 — length, control characters, gibberish |
| `Moderation/Verdict.php` | Layer 0/1 result, same JSON shape as the LLM's |
| `Moderation/Moderator.php` | Layer ordering, verdict caching, customer-facing wording, **the three on/off switches** |
| `Admin/BlocklistPage.php` | Switch layers, edit terms **including the built-in ones**, try a prompt |
| `tools/moderation-check.php` | **The gate for the switches — 34 assertions, no network, no cost** |

> **All three layers are switchable and every built-in term is removable (D-049).** Ruslan asked
> for both on 2026-08-06. The one counter-intuitive part: **switching the AI classifier off does
> not skip the call** — it is the same request that translates the prompt to English, which the
> image providers need. Off means the verdict stops being binding, not that the money is saved.
> The admin screen says so on the setting itself.
>
> Two things the override deliberately does not do: it does not turn a **failed** call into an
> allow (a transport outage is not a verdict, and §10 fails closed), and it does not generate from
> an empty prompt when the classifier blocked without translating — it falls back to the
> Lithuanian and logs a warning.
>
> Built-in removals are stored as an **exclusion list**, so a later version can still add terms. A
> saved copy of the whole list would freeze it at whatever shipped the day it was first edited.

**39 stack assertions and 140 unit assertions, all passing.** The headline result is D-024: the
blocklist now catches for free, in zero milliseconds, every declension case D-019 measured the
LLM catching for $0.0001 and 790 ms — including `Elsos suknelė`, `Žmogaus voro tinklas`, and
`noriu torto su Šunyčiais patruliais`. Six false-positive checks pass.

The LLM still earns its place: `mėlynas ežiukas, kuris greitai bėga` → `block / franchise:sonic`,
with no proper noun in the prompt at all.

Also verified: rejections are logged with prompt and layer, **no job is queued and nothing is
spent**, the customer message never names the matched term, and a refused prompt does not consume
the free allowance (though it does count toward the per-IP daily ceiling).

### Phase 4 — what exists and was verified on real output

| File | What it does |
|---|---|
| `Support/Mm.php` | The §3 print maths. Pure, unit-tested |
| `Imaging/SheetLayout.php` | Imposition — 24-up derived, not typed. Pure, unit-tested |
| `Imaging/GdEngine.php` | Mask, cover/crop, flatten, PNG + WebP, `pHYs` DPI injection |
| `Imaging/TtfCmap.php` | TrueType cmap reader |
| `Imaging/FontCatalogue.php` | Bundled fonts + Lithuanian coverage gate |
| `Imaging/TextRenderer.php` | Straight, outlined, auto-fit, wrapped, and arc text |
| `Imaging/Watermarker.php` | Diagonal tiled watermark |
| `Domain/TextSpec.php` | Resolution-independent text layer |
| `fonts/` | DejaVu Sans + Serif, regular and bold, with licence |
| `tests/` | **83 assertions, 0 failures** — Mm, SheetLayout, font coverage |

Produced and inspected, not just asserted:

- **15 cm round print file** — 1024 px master → 2× upscale → cover to 1843 px → circle mask →
  arc text `ĄČĘĖĮŠŲŪŽ ąčęėįšųūž` → straight text wrapped to two lines → flattened on white →
  PNG declaring **300 DPI**, measuring 156.0 × 156.0 mm.
- **24-up cupcake sheet** — 4 × 6 at 2363 × 3390 px = 200.1 × 287.0 mm, evenly gutterred.
- **800 px preview** — shaped, texted, watermarked, 31 KB WebP.

**GD FreeType is present on the testbed** and every bundled font passes a cmap-level check for
all nine Lithuanian letters in both cases.

> **Peak memory was 339 MB**, above the 256 MB `PLAN.md` §9.2 predicts, with its mitigations
> already applied (D-023). Production's limit is unverified and needs checking before go-live.

Run the tests with:

```bash
docker compose exec wordpress php wp-content/plugins/ai-cake-topper/tests/run.php
```

### Phase 3 — what exists and was verified live

| File | What it does |
|---|---|
| `Domain/Job.php`, `JobRepository.php` | State machine + the **atomic claim** |
| `Queue/Dispatcher.php` | Socket-spawn loopback, URL override, spawn-path self-test |
| `Queue/Runner.php` | Claims and executes; moderation, budget, generation, storage |
| `Queue/Scheduler.php` | Action Scheduler sweep, wp-cron fallback |
| `Rest/RestController.php` | Route registration, explicit nonce verification |
| `Rest/SessionEndpoint.php` | Uncached nonce + session cookie (§7) |
| `Rest/GenerateEndpoint.php` | 202 in ~209 ms |
| `Rest/JobStatusEndpoint.php` | Polling + poll-triggered execution (§6.2 layer 2) |
| `Rest/FileEndpoint.php` | Ownership-checked delivery; master is never servable |
| `Storage/PrivateStorage.php` | The two zones, with containment checks |
| `Pipeline/PromptBuilder.php` | House style suffix, one place to tune |

**Verified live — 17/17 end to end over real HTTP, plus 18 mechanics assertions:**

- Atomic claim: second claimant loses, attempts increments exactly once.
- Concurrency cap holds; refused jobs stay `queued`, and the sweeper ignores the cap so a stuck
  queue can still recover.
- Stale claim recovery, and giving up after 3 attempts rather than looping.
- Ownership: a stranger polling someone else's job gets **404, not 403** — no id enumeration.
- `POST /generate` without a nonce → 403.
- **Both dispatch layers proven.** With loopback blocked, the job ran inside poll 1. With
  loopback working, polls returned instantly while the job progressed `running → running → done`
  in another worker.
- The failure path was proven by a real provider outage, not a simulation (D-022): retry,
  requeue, terminal failure, generic customer message, cost still recorded.

Two bugs the tests caught, both fixed:

- **The runner marked a job `done` when the image could not be stored**, producing a design row
  pointing at nothing and a polling contract reporting success with no preview. Now retries, then
  fails.
- **Site Health checked the wrong directory.** It tested the storage *root*; writes happen in
  `sessions/YYYY/MM/`. Activating over WP-CLI as root — normal on managed hosts — leaves those
  owned by root while PHP runs as the web user, so the root looks fine and every write fails. It
  now performs a real probe write, cached for five minutes.

### Phase 2 — what exists and was verified live

26 PHP files parse clean; the whole chain was exercised against the real Replicate and Gemini
APIs, not mocks.

| File | What it does |
|---|---|
| `Support/HttpClient.php`, `Http.php`, `HttpResponse.php` | Transport seam + `wp_remote_*` implementation: timeouts, bounded retries, `Retry-After`, size cap, redaction |
| `Providers/{Image,Upscale,Text}Provider.php` | The §8.5 interfaces |
| `Domain/GenerationRequest.php`, `GenerationResult.php`, `PromptAnalysis.php` | Value objects |
| `Providers/Image/ReplicateProvider.php` | `flux-dev`; sync **and** polling forms, so Phase 3 needs no retrofit |
| `Providers/Image/FalFluxProvider.php`, `GeminiImageProvider.php` | Written to interface, billing-gated |
| `Providers/Text/GeminiTextProvider.php` | Translate + moderate, structured output, injection-fenced |
| `Providers/Upscale/GdUpscaler.php` | Bicubic, free, the production fallback |
| `Providers/ProviderRegistry.php` | Primary → fallback, records who served |
| `Domain/DesignRepository.php` | Persistence, so the budget guard has something to sum |
| `Admin/TestProviderPage.php` | The screen §8.5 says makes the provider decision |

**Verified live:**

- **Moderation 6/6 on the hard Lithuanian cases** (D-019) — genitive franchise names, a
  Lithuanian-translated character name in the genitive, a franchise described but never named,
  a real public figure, and both false-positive checks. ~790 ms, $0.000639 for all six.
- **Generation** through the registry: 1024×1024 PNG, 4.8 s, via Replicate `flux-dev`.
- **Upscale**: GD bicubic 1024² → 2048², free.
- **Fallback chain**: forced onto a blocked model it walked Replicate → fal → Gemini and
  returned the last failure instead of dying on the first.
- **Admin screen** renders without fatals.

The style suffix is now positive-phrased and produces the actual product — flat vector, clean
outlines, white background, single subject. See D-019 for the two tuning items (drop shadow,
centring), neither blocking.

> **Cost recording is list price, and that is now correct for the provider that matters.**
> `estimate_cost()` records list price because neither API says whether a call was billed.
> Over-recording is the safe direction for a spend guard, and with fal primary and funded
> (D-030) the recorded $0.012 is what was actually charged. Replicate's figure still reads high
> for any historical free call.

### Phase 1 — what exists and was verified running

Plugin activates clean on the testbed at version 0.1.0, no notices in `debug.log`, front page
200, admin 302. 22 smoke-test assertions pass.

| File | What it does |
|---|---|
| `ai-cake-topper.php` | Constants, SPL autoloader, PHP floor, HPOS declaration, activation hooks |
| `src/Plugin.php` | Composition root — the only class that knows the wiring |
| `src/Installer.php` | `dbDelta` both tables, schema version, storage root + zones |
| `src/Capabilities.php` | GD/FreeType/WebP/memory/storage detection → two Site Health tests |
| `src/Support/Settings.php` | Constant-first config; secrets never touch `wp_options` |
| `src/Support/Logger.php` | Levelled logging with two-pass key redaction |
| `src/Throttle/IdentityResolver.php` | Salted IP hash + session cookie + user, proxy-header aware |
| `src/Throttle/RateLimiter.php` | Composite identity, per-IP daily ceiling, minimum interval |
| `src/Throttle/BudgetGuard.php` | Daily/monthly USD ceiling, self-clearing, admin email |

Verified on the running testbed: `wp_aicake_designs` (25 columns, 6 indexes),
`wp_aicake_jobs` (9 columns, 3 indexes), `/var/lib/aicake/{sessions,orders}` writable and
outside the webroot.

**GD FreeType is present on the testbed** — the Site Health panel that reports it is now the
mechanism for answering the same question on production, with nothing uploaded to the live shop.

Two deviations from `PLAN.md`, both deliberate and commented in the code:

- **JSON columns are declared `LONGTEXT`.** `dbDelta` cannot compare a JSON column against its
  own definition and re-issues an `ALTER` on every page load. MariaDB aliases JSON to LONGTEXT
  anyway, so nothing is lost.
- **Secrets have no `wp_options` fallback.** `PLAN.md` §16 allows one; `CLAUDE.md` forbids it and
  wins. A missing key is a configuration error the settings screen reports.

## Blocked on

**Nothing.** There is a complete free stack and Phase 1 contains nothing provider-specific.

### Provider access — fal funded and primary, re-probed 2026-08-03

Keys for fal, Google and Replicate are in `.env` and all three authenticate. Every claim below
was tested against the live API, not read off a pricing page.

| Provider | Verdict |
|---|---|
| **fal.ai** | **Funded and working — the primary (D-030).** `fal-ai/flux/dev` returned 992×992 PNG in 4.7 s at $0.012. |
| **Replicate** | `402` — the free window closed mid-session (D-022). Kept as a *fallback*, never a dependency (D-017). |
| **Google — text** | **Free and working.** `gemini-3.1-flash-lite` translates Lithuanian correctly. |
| **Google — image** | `429 … free_tier_requests, limit: 0`. Explicitly zero on the free tier. |

The stack we now run on:

| Layer | Provider | Cost |
|---|---|---|
| Image generation | fal `fal-ai/flux/dev` | $0.012 / MP |
| Translate + moderate | Google `gemini-3.1-flash-lite` | free |
| Upscale | GD bicubic in PHP | free, and it is the production fallback anyway |

> **Free Replicate access is undocumented and must never be a production dependency** (D-017).
> The split followed no pattern — the cheapest model was blocked, the top tier free — and it has
> since stopped entirely. It sits behind fal in the chain and nothing depends on it.

A 1 MP 1:1 request yields **992×992**, not 1024²: `GenerationRequest::dimensions()` rounds to a
multiple of 32. Harmless — the 15 cm spec's 2× upscale still clears 1843 px — but it is why
masters are not the round number you might expect.

`AICAKE_OPENAI_KEY` and `AICAKE_LLM_KEY` are still empty. `AICAKE_REPLICATE_KEY` was added and is
not yet in `infra/.env.example`.

The testbed is up and the repository is on GitHub.

## Environment — verified 2026-08-02

### Access available to Claude Code

| | |
|---|---|
| HTTP | `curl http://100.127.55.45:8080` works from the Bash tool |
| SMB | `Z:\ruslan\wordpress-test` — readable **and writable** |
| SSH | `ssh ruslan@ruslan-server` — key auth, works non-interactively |
| Browser | Ruslan's Chrome is connectable if a logged-in session is needed |

**`sudo` over SSH needs an interactive password — it is not available to Claude Code.** But
`ruslan` is in the `docker` group, which is root-equivalent and needs no password. That is the
route for anything requiring privilege:

```bash
docker run --rm -v /home/ruslan/wordpress-test/plugins:/t wordpress-test-wordpress chown -R 1000:1000 /t
```

**Docker creates missing bind-mount directories as `root:root`.** `plugins/` and
`plugins/ai-cake-topper/` were root-owned, so `sync.ps1` failed with `ERROR 5 Access is denied`.
Fixed by the command above. It will recur if those directories are ever deleted and recreated.

**SSH is restricted by agreement to `/home/ruslan/wordpress-test`.** Do not touch anything else
on that server.

### Testbed — compose applied and running

| | |
|---|---|
| URL | `http://100.127.55.45:8080` (also `localhost:8080` on the server) |
| Containers | `wordpress-test-wordpress-1`, `-db-1`, `-mailpit-1` — all up |
| WordPress | 7.0.2 · WooCommerce 10.9.4 |
| Theme | Blocksy 2.1.49 (parent) + **`valgomos` child 2.7.38** — the live theme, copied 2026-08-07. See below |
| Cart / checkout | **Classic shortcode**, not blocks (`/krepselis/`) |
| PHP memory | **512M** (was 128M) |
| Imagick | **Present** — but see below, we do not build against it |
| GD | Present |
| Accounts | `ruslan` (administrator) · `testuser` / `TestPass123` (**customer**, D-026) · `testmanager` / `TestPass123` (**shop_manager**, D-028) |
| Mailpit | `http://100.127.55.45:8025` |
| DB | `wp_user` / `wp_password` / `wordpress` |
| Other plugins | **WC Fields Factory 4.1.9** (D-036), WooPayments, PayPal, MailPoet, Jetpack, Pinterest, Reddit, Snapchat, Google Listings & Ads, LithuaniaPost, YITH AJAX nav |

### The testbed now runs the live theme — baseline `valgomos` **2.7.38**, 2026-08-07

**Write this number down; it is the merge base.** Whatever the wizard needs from the theme will
eventually be merged back into the live theme, and that diff is only meaningful against the
version we started from. That version is **2.7.38**.

| | |
|---|---|
| Source of truth | `C:\VALGOMOS_DEKORACIJOS\themes\valgomos` — a **monorepo** that will also hold this plugin under `plugins\` |
| Was on the testbed | 2.7.0 (22 July) — 38 versions stale, backed up before overwriting |
| Now on the testbed | **2.7.38**, active, `style.css?ver=2.7.38` served |
| Production | **2.7.38 as well** — so the testbed and the live shop now match on the child theme |
| Parent | Blocksy **2.1.49** on the testbed vs **2.1.51** live — still a two-patch gap, not closed |

Copied with `robocopy /MIR`, 22 files. Verified from inside the container: version 2.7.38, **no
dotfiles and no `.git` in the theme directory**, front page / wizard page / cart all 200, no fatals
in the log.

> **The `.git`-in-the-theme rule is the monorepo's reason for existing.** On 2026-08-07 an uploaded
> `.git` was found publicly readable at `/wp-content/themes/valgomos/.git/` on the live shop — full
> history, owner's name and e-mail. Git now lives only at the monorepo root. **The same rule will
> apply to this plugin when it merges in:** no `.git`, no changelog, no dotfiles inside the
> uploaded folder. The merge itself is `git subtree add --prefix=plugins/<name>`, which preserves
> our history, so developing here separately costs nothing later.

### Phones — the canvas ceiling, measured 2026-08-07

**Ruslan's Android phone builds a 35 MP canvas and encodes it. The wizard needs 8.3 MP.**

The wizard has always asked the browser for a canvas at the **true print size** — `editor.js`
`exportLayer()` allocates `2481 × 3331` for a cupcake sheet and calls `toDataURL()` on it — and
every browser check in this project until now ran on a desktop. Most of the shop's customers are
on phones, so this was an unmeasured assumption sitting under shipped code.

| Size | MP | Draw | PNG | ms |
|---|---|---|---|---|
| 1843 × 1843 · ⌀15 cm topper | 3.4 | ok | ok | 45 |
| **2481 × 3331 · A4 sheet / cupcakes — what the wizard builds** | **8.3** | **ok** | **ok** | **117** |
| 2552 × 3579 · largest current format | 9.1 | ok | ok | 89 |
| 3000 × 4000 | 12.0 | ok | ok | 119 |
| 4000 × 5000 | 20.0 | ok | ok | 126 |
| 5000 × 7000 | 35.0 | ok | ok | 223 |

Device: **POCO X3 Pro** (2021 mid-range, Snapdragon 860), Android, Chrome 150, 8 cores,
393 × 873 @ DPR 2.75. Tested with `tools/phone-canvas-check.html`.

The model matters to how much this result is worth: it is **not** a flagship. A four-year-old
mid-range phone clearing 35 MP puts the Android floor well below the 8.3 MP we need, so the
margin is not a top-end artefact.

> **⚠ This is Android Chrome, and Android Chrome was never the risk.** **iOS Safari is untested**
> and it is the one with a hard canvas-area ceiling — and its failure mode is silent: it returns a
> canvas that reads back transparent, and `toDataURL()` then produces a valid, blank PNG. The
> check is built around exactly that (three corner markers written and read back, plus a byte-size
> floor on the PNG), so it will catch it — but only when it is finally run on an iPhone.
>
> **Do not read this row as "phones are fine."** Read it as "Android is fine, iOS is unknown."

> **The byte figures are not representative of a real payload.** The test canvas is white with
> three squares, so it compresses to almost nothing. A real text layer is mostly transparent and
> lands in the same range, but a composited photo would be megabytes.

**And the live shop's own statistics say iOS is the platform that matters** (read 2026-08-07 from
the statistics plugin on production, ~97 000 visitors):

| OS | Share | | Browser | Share |
|---|---|---|---|---|
| Windows | 36.1% | | Chrome | 55.4% |
| **iOS** | **16.1%** | | **Mobile Safari** | **12.7%** |
| Mac | 16.0% | | **Chrome Mobile** | **6.6%** |
| GNU/Linux | 14.0% | | Firefox | 4.1% |
| **Android** | **11.1%** | | Safari | 3.4% |

**iOS outnumbers Android, and on mobile specifically Mobile Safari beats Chrome Mobile roughly
two to one.** Devices: desktop 67.3%, smartphone 24.6%, phablet 1.1%, unset 6.2%.

> **This inverts the assumption this project has been carrying.** A previous session reasoned that
> Android dominates in Lithuania and therefore Android was the case to check. For *this shop* that
> is simply false — **the one browser engine we have never tested is the one most mobile customers
> use.** The POCO result is real, but it measures the smaller half of the mobile audience.

Two caveats on the figures, both pushing the same way:

- **Desktop is inflated by crawlers.** GNU/Linux at 14% and Internet Explorer at 1.5% are not
  Lithuanian cake decorators. Strip the bots and the mobile share rises — so 24.6% is a floor,
  not an estimate.
- **Facebook's in-app browser is 2.2%**, and the shop actively drives traffic from Facebook. On
  iOS that is WKWebView — the same engine as Safari, with the same canvas ceiling and usually a
  tighter memory budget. It is not a separate, safer bucket.

**What it unlocks:** client-side rendering is viable on Android with room to spare — the format
diagrams, the proof, photo decode/downscale/crop, and in principle even the 300 DPI print file.
The print file stays on the server anyway, by Ruslan's decision: a spike once per *order* is not
the same problem as a spike per *visitor*.

### WC Fields Factory on the testbed — read from the database 2026-08-03

Installed and active at **4.1.9**, matching production. Ruslan created one field group,
`wccpf` post **#683 "AI_IMAGE"** (`ai_image`), carrying the live sheet types verbatim:

| Field | |
|---|---|
| Key | `wccpf_qkKQtVWBjYfI` — **randomly generated** |
| Type | `radio`, label „Lakšto tipas" |
| Choices | `Krakmolo lakštas` · `Storas krakmolo lakštas` · `Cukrinis lakštas` |
| Price rules | Cukrinis **+1.50** „Cukrinio lakšto mokestis" · Storas **+1.00** „Storo krakmolo mokestis" |
| Flags | `order_meta: yes`, `email_meta: yes`, `visibility: yes`, `cart_editable: no` |

With a €3.50 base that reproduces the live prices exactly: 3.50 / 4.50 / 5.00.

Two facts that matter for the integration:

- **Field keys are random**, so the plugin can never hardcode one. Any WCFF field we depend on
  has to be resolved at runtime — by label, or by a key stored in settings and set once.
- **The group is not yet bound to a product.** `wccpf_condition_rules` is
  `[{"context":"product","logic":"==","endpoint":"-1"}]` — endpoint `-1`, i.e. unset. Ruslan
  flagged this himself as a later step; it needs the consolidated AI product from D-035 to exist
  first. Until then the group applies to nothing and no cart test will show a surcharge.

**D-036's open risk is resolved — read from the WCFF source, 2026-08-03.** The wizard can drive a
Fields Factory field, and by the robust route rather than the fragile one:

| | |
|---|---|
| `wcff_persister.php::persist_fields()` | mines **`$_REQUEST` by field key** on `woocommerce_add_cart_item_data` |
| `wcff_negotiator.php::handle_custom_pricing()` | iterates **cart-item keys** matching `wccpf_*` that carry `pricing_rules` + `user_val`, then `set_price()` |

So the wizard's add-to-cart form just posts `wccpf_<key>=<value>` like any other field, and WCFF
builds the whole structure itself — price, cart display, order meta, email. **No coupling to
WCFF internals and no pricing code of ours.**

The fragile alternative — hand-building the `wccpf_*` cart-item array so the negotiator finds it
— also works, since the negotiator never reads `$_POST`. Do not use it: it depends on an
undocumented internal shape that a WCFF update can change silently.

**Proven by running it — `tools/wcff-check.php`, 18 assertions, all green.** The check is
committed, idempotent and creates its own fixture, so it re-runs from nothing.

| | |
|---|---|
| Product | `ai-paveikslelis` — „Valgomas paveikslėlis (AI)", **simple**, €3.50 |
| Group | `ai_image` bound to it; AI field added with a +1.00 „AI paveikslėlio mokestis" rule |
| Charged | 3.50 / 4.50 / 5.00 by sheet type, **+1.00 with AI** — from real `add_to_cart` + `calculate_totals` |
| Keys | resolved **by label** at runtime via `FieldsFactory::field_key()`, never hardcoded |

**Falsified before being trusted:** tampering the AI rule from 1.00 to 2.00 turns 3 of the 18
red, and it is caught independently by both the read-only `surcharge()` path and the real cart
price — so those two agree with each other rather than sharing a bug.

> **One WCFF trap, cost an hour.** `split_cart_item_for_cloning()` flips its own
> `is_native_add_to_cart` to false after the *first* add-to-cart in a process, and
> `fields_persister()` only mines `$_REQUEST` while that flag is true. Correct for a real request;
> in any script doing several add-to-carts, every scenario after the first silently prices at
> base — which reads exactly like WCFF being broken. The check resets the flag through the hook
> registry between scenarios.

Still open: the AI field is currently a **visible radio**, so on a plain product page a customer
could answer it themselves — pay €1 without AI, or use AI and not pay. **The fix is not to hide
the field.** `CartIntegration` must derive the value from whether the design actually has a
generated image and overwrite what was posted, because a posted flag about whether money was
spent can never be trusted. Hiding is cosmetic; the server-side derivation is the control.

### Production — full Site Health dump read 2026-08-07

> **We are migrating. `docs/migration.md` is the authoritative plan** — production's verified
> facts, the four things that can stop us, and the ordered steps M0 → M6. Read it before doing
> anything production-shaped. What follows is the summary.

`valgomosdekoracijos.lt` — **~2500 products**, **11 133 registered users**. A **managed platform,
not a Linux machine**: PHP libraries and WordPress plugins can be added, system packages cannot.
Ruslan has **FTP and wp-admin only** — no shell, no WP-CLI.

| | |
|---|---|
| Path | `/home/vaijos/domains/valgomosdekoracijos.lt/public_html` — DirectAdmin layout |
| PHP | **8.4.14**, `cgi-fcgi`, Apache · `max_execution_time` 300 s · upload 64M |
| `memory_limit` | **256M** — ⚠ our measured peak is **339 MB** (D-023). See below |
| WordPress | **7.0.2**, `lt_LT`, https, `environment_type: production` |
| WooCommerce / WCFF | **10.9.4** / **4.1.9** — both exactly the testbed's builds |
| Theme | `valgomos` **2.7.38**, child of Blocksy **2.1.51** (testbed: 2.1.49) |
| Database | MariaDB 10.6.17, **742 MB** |
| Disk | uploads **15.87 GB**, total **17.81 GB** |
| Image editor | `WP_Image_Editor_GD` — **no Imagick**, GD bundled 2.1.0, formats include **WebP** |
| OPcache | on and **full** — 33 MB of 33 MB, interned strings 100%, hit rate 19.5% |
| `WP_DEBUG` / `WP_DEBUG_LOG` | false / false — **plugin errors surface in WooCommerce → Status → Logs**, nowhere else |
| Filesystem | `wp-content`, `plugins`, `uploads`, `mu-plugins` all writable |
| Backups | Installatron is present (mu-plugin), so a one-click full backup exists |

**Production matches the testbed on everything that decides behaviour.** Same WP, same
WooCommerce, same WC Fields Factory, same GD-without-Imagick path we have developed on since
D-013. The theme is the real difference, and that is cosmetic work that belongs to the theme
project (`docs/migration.md` §6).

> **⚠ `memory_limit` 256M against a 339 MB measured peak is the one hard blocker.** A render that
> hits the ceiling dies *after the customer has paid*. Three responses, and only the third is
> ours to guarantee: ask the host to raise it, probe whether `ini_set()` can raise it, and **cut
> the peak below ~200 MB and prove it with the limit pinned at 256M on the testbed**.
> `docs/migration.md` §2.1.

> **⚠ Really Simple Security 9.7.0 is active on production.** If its hardening disables the REST
> API for logged-out users, the wizard is dead for exactly its audience, and it will look like our
> bug. Check before installing. `docs/migration.md` §2.2.

Three other active plugins worth knowing about: **Code Snippets 3.9.6** (an escape hatch for
one-off PHP where there is no WP-CLI), **Complianz** cookie consent (our session cookie is
functional/necessary — document it), and **All in One SEO** (how the wizard page gets its
`noindex` while it is hidden).

> **The testbed has Imagick; production does not.** GD is the target engine and
> `AICAKE_FORCE_GD` defaults on, so development happens on the production path.
> See `PLAN.md` §9.1 and D-013/D-015.

**No external render server is needed** — GD + pure PHP + the AI APIs cover everything
(`PLAN.md` §9.1.3, D-015).

**GD FreeType: still assumed present, still not verified — and the stake is much smaller now.**
Site Health does not report it. D-045 deleted all server-side text rendering, so the only thing
left that draws a glyph is the **watermark**. No FreeType means no watermark, not a product with
no text on it.

It is answered in the migration preflight (`docs/migration.md` §M1) along with `open_basedir`,
whether `ini_set()` can raise memory, loopback, and anonymous REST reachability — one upload of
`tools/host-check.php`, read, delete.

Confidence is high on indirect evidence: **the reported GD build supports WebP**, which requires
an explicit `--with-webp` at compile time. That is a *rarer* flag than FreeType, so a build with
WebP almost certainly has FreeType too, which is near-universal in distro and control-panel PHP
builds. Call it >95%.

**Needs resolving before Phase 4** (imaging), not before. Phases 0–3 do not touch text rendering.
Three ways, cheapest first:
1. Hosting control panel → PHP info / extensions page. Read-only, uploads nothing. Look for
   `freetype`.
2. The plugin's own Site Health panel reports it at activation, before any customer sees anything.
3. `tools/host-check.php` (token `sJE1SqqPpbsqAjX7HKOjhl-0`) — upload, read, delete. Also checks
   large-canvas allocation, a writable dir outside the webroot, loopback, and outbound
   reachability to fal/Google. Held in reserve.

Note: the separate **theme** project also lives on this share (`Z:\...\themes\`) with its own
`CLAUDE.md`. This project does not touch it.

## Git

| | |
|---|---|
| Remote | `github.com/wincrash/ai_image_plugin` (private) |
| Branch | `main`, tracking `origin/main` |
| Push from Windows | **Works.** SSH key registered, authenticates as `wincrash`. |

The git-bundle-through-the-server bootstrap (D-014) is retired — just `git push origin main`.

## Repository layout

```
C:\AI_IMAGE\
├── README.md                project overview
├── CLAUDE.md                entry point for a reset session
├── PLAN.md                  the design (23 sections)
├── WORKFLOW.md              how we work
├── STATE.md                 this file
├── DECISIONS.md             append-only decision log (34 entries)
├── idea.md                  original brief, superseded by PLAN.md
├── docs\api-evaluation.md   Phase 0 plan
├── docs\pipeline.md         the built system: what runs where, what costs money
├── infra\                   testbed Docker config — applied
├── tools\sync.ps1           C:\AI_IMAGE  ->  Z:\
├── tools\rest-check.sh      REST over real HTTP, logged out and logged in
├── tools\order-check.php    a real order through to print files (Phase 7's gate)
├── tools\moderation-check.php  the moderation switches (D-049), no network
├── tools\settings-check.php    the encrypted key store and the counter reset (D-050/051)
└── plugin\                  the plugin itself
```

## Next actions

**Nothing is blocked.** fal is funded and the success path is verified (D-030).

### ✅ Fixed: „Užrašo dydis netinka." on saving the text — D-043

**Ruslan reported it; it is fixed, falsified and verified in a browser.**

Diagnosed before being fixed, which mattered: STATE.md listed two candidate causes and a query
over the designs table ruled one out. **Every wizard design (product 684) carries a format** —
`circle 200`, `cupcake 60`, `cupcake 45`, `circle 150`. The 108 NULL-format rows are all products
646/649, the product-page generator and the check scripts, which legitimately send none. So the
cause was the second one: the format changed at step 1 after the design was generated, and
nothing cleared the design.

The rule now, and it is the D-033 argument applied one level up: **the design is the authority on
its own geometry.** `FormatCatalogue::layout_key()` builds the key in one place,
`JobStatusEndpoint` sends `layout_key` with the preview, and `wizard.js` looks the layout up by
it rather than deriving one from the step-1 selection. Changing the format now clears the design
and says so — the generation aspect comes from the format (§3.2), so the picture really is the
wrong shape.

**The size check was not relaxed.** It is what stands between a layer and being composited at the
wrong scale.

Falsified: keying `Wizard::layouts()` independently turns **3 of the 35** wizard-check assertions
red; removing the `layout_key` emission turns 1 red. In a real browser on a real fal generation:
`designLayout` arrives as `cupcake|45`, the editor draws 2481 × 3331 instead of 1843², the layer
saves and reaches step 4, and switching to a circle afterwards clears the design and disables
„Toliau".

Two smaller things fixed with it: `editor.mount()` is callable more than once (a second
generation used to leave the first picture on the canvas), and `format_type`/`format_mm` are now
declared `/generate` route args.

> **`floatval` cannot be a `sanitize_callback`.** WP calls sanitisers with three arguments and an
> internal function refuses them in PHP 8 — `absint` and `sanitize_key` survive only because they
> are userland. The declared `type` is what casts. Caught by `wizard-check.php`, as a fatal.

### „Pasiūlyk dizainą" — parked by Ruslan, and it works today

**Ruslan, 2026-08-03: he no longer remembers what he saw and asked to leave it** — he will report
it again if it recurs. Not closed as fixed, closed as unreproducible. Two things were measured
before parking it, so a future report starts from evidence:

- **The suggestion path works against the live API.** „Su gimtadieniu Ąžuolas 5 metai" came back
  as three lines, the name largest and gold, outlined, every word preserved.
- **The word-preservation clamp has never fired over real HTTP.** Not one
  `Layout suggestion changed the text and was discarded` line exists in the wc-logs — so the
  candidate the previous session called most likely is the one thing ruled out.

**The likeliest remaining explanation, and it is a design tension rather than a fault:** two
presses in a row return **429 „Palaukite akimirką ir bandykite dar kartą."** — measured, first
200, second refused. Temperature is 0.9 *specifically so that pressing twice offers something
different*, which invites the second press that the 3 s cooldown then refuses. Whether the
cooldown should be shorter, or the button disabled with a countdown instead, is Ruslan's call —
left alone deliberately.

Also worth knowing when it comes back:

- **Every failure is deliberately quiet** — an unconfigured key, a refused call and a useless
  answer all return 200 with no lines (D-041). Good for customers, poor for diagnosis: the
  browser cannot tell those apart. Consider a distinguishing field for logged-in admins.
- **`arrange()` runs only on a suggestion**, not on manual edits. If the complaint is about
  overlap *after* editing, that is why, and it is by design — re-flowing on every change would
  undo dragging.
- The endpoint, the clamp and the word check have 16 unit assertions over a stubbed `HttpClient`
  (`tests/LayoutSuggesterTest.php`) and need no network. If those pass, the fault is in the live
  call, the prompt, or the browser side — not in the clamp.

---

**Steps 1–3 of the wizard are done** (D-033, D-041, D-042) — see "Being built now" for what
exists and what was measured. What follows is what is left.

**1. Wizard step 4 is done — the proof and the cart hand-off (D-044).** The wizard now runs end
to end: a Lithuanian prompt becomes a cart line at the right price.

- **The proof is a capture of the editor's own canvas**, not a second rendering. `editor.snapshot()`
  returns what the customer has been looking at — artwork clipped per piece, cut lines, their
  text where they dragged it. Compositing it again server-side would mean two renderers that must
  agree, the browser↔GD parity problem D-033 deleted; the print path composites the stored layer
  and `order-check.php` reconciles the two on a real file.
- **The AI fee is derived server-side and the field is not posted at all.** `CartIntegration`
  writes the Fields Factory answer from whether the design really has a generated image — a
  provider name *and* a master, both — and WCFF prices it as it would any field.

Bought in a real browser: 24 cupcakes with „Emilija", cart line reading **Formatas: Keksiukams
⌀4,5 cm — 24 vnt.** · **Piešinys** · **Lakšto tipas** · **AI paveikslėlis: taip** · 4,50 €.

> **`WC_Cart::add_to_cart()` never applies `woocommerce_add_to_cart_validation`.** Only the form
> handler, AJAX, the Store API and the cart-session restore do. So the fee is derived on
> `woocommerce_add_cart_item_data` **at priority 5** — before WCFF's persister at 10, an ordering
> that used to hold only by registration accident. Deriving it on the validation hook would leave
> every other route into the cart charging nothing for AI.
>
> It also means a check that calls `add_to_cart()` and asserts "the cart is empty" asserts
> *nothing* — it passes for a plugin with no validation at all. `wcff-check` calls the filter
> directly, the way the form handler does.

**1b is done — the print path composites the layer.** `FulfilPipeline::composite_layer()` lays
the stored PNG over the imposed canvas, **after imposition, not before**: text baked into a piece
and then imposed gives every cupcake the same name, which is the whole reason the layer is sheet
sized. Never scaled — a size mismatch is refused and logged, because stretching it would put text
across a cut line while still producing a plausible file.

Two bugs found and fixed while doing it, both by reading rather than by a failing test:

- **`TextLayer` and `TextSpec` share the `text_payload` column and both carry a `text` key**, so
  `Fulfilment::text_spec()` read a layer straight into `TextSpec::from_array()` and rendered the
  *whole* string through the old server-side path with every default it never set: bottom,
  white, auto-fit. Twelve cupcakes would each have printed all twelve names across the bottom,
  on top of the composited layer, and the order would have looked successful. They are now told
  apart on `path` — only a layer has one.
- **`Fulfilment` used `PrintSpec::for_product()`, not `for_design()`.** Under D-035 the format is
  a wizard choice on the design, so a wizard order printed at whatever geometry the single AI
  product carried — and the layer would then be measured against a canvas it was never authored
  for.

`order-check.php` gained the scenario that was missing (58 assertions, was 54): a design with a
layer, ink asserted at the pixel the layer put it on piece 7 **and absent from piece 0**, which
is per-piece text proven on a real print file.

> **One of those new assertions was decoration until it was falsified.** "The print is the design
> format, not the product" originally used product 649 — whose product geometry already *is* a
> 4.5 cm cupcake sheet, so both code paths agreed and reverting the fix changed nothing. It now
> uses 646, the 15 cm topper, and reverting turns it red (2481 vs 1843) along with the ink
> assertion, because the layer then no longer matches the canvas.

**1c is done — the server draws no glyphs but the watermark (D-045).** `TextRenderer`,
`TextSpec`, the product page's text controls, the `text` parameter on `/generate` and the text
step in both pipelines are all gone.

**`FontCatalogue` and `TtfCmap` were kept**, contrary to what this file used to say. They are now
the **Lithuanian coverage gate on the font list the browser is offered**, and D-041 raised the
stakes: the layout model names a font, so the offered set is what it picks from and each entry
must be able to spell `ĄČĘĖĮŠŲŪŽ`.

Old rows still hold the retired payload shape. They read back as a layer with **no bitmap** —
nothing composites, the artwork prints alone, and the `text` they carry still tells a shop
manager what was ordered. Asserted in `order-check`.

**The cart shows the finished picture now (Ruslan, D-045).** `Pipeline/ProofPipeline.php` lays
the watermarked preview out per piece and composites the stored layer over it; `file_proof` is
**schema 4** and a `proof` file variant serves it. Not a second renderer — it composites the same
bitmap the browser made and draws no glyphs. Looked at: 24 cupcakes, „Emilija" on each.

> **A third instance of the D-043 bug, found while deleting.** `Runner` built previews with
> `PrintSpec::for_product()`, so every wizard preview used the default round 150 mm — which
> circle-masks the preview of a whole A4 sheet. Invisible for round formats, which is why it
> survived. Now `for_design()`, like the print path and the editor.

> **`rest-check.sh` can fail for a boring reason.** The per-IP daily ceiling is 30, and a day of
> browser testing uses it up — `generate` then returns 429 and three assertions go red. Confirm
> before debugging: count today's designs per `ip_hash`. Raising `ip_daily_ceiling` through
> `Settings::update()` and putting it back to 30 turns an ambiguous run into a definite one.

**3. Phase 8 — four of the five screens are cut (D-047).** Ruslan's scope, in his words: *„user
generates image using our wizard, places the order, i get order with final a4 image for printing,
thats it."*

| | What it is | Status |
|---|---|---|
| **a. Review queue** | Approve / reject before printing | **Deleted (D-047).** He does not review orders — he *is* the review, at the printer. It was a second order process beside the real one |
| **b. Print queue** | Batch download as ZIP, mark as printed | **Cut by Ruslan.** The order screen's download button is how the file is collected |
| **c. Cost dashboard** | Spend by day / provider / model | **Cut by Ruslan.** `BudgetGuard` already mails him when a ceiling is crossed |
| **d. Cleanup cron** | §12.5 retention — delete unpurchased designs after N days, never one attached to an order | **The only one left, and genuinely needed.** Storage grows with every generation, bought or not, and production is a managed host. `order_id` on the design row exists precisely so this is answerable without a query per candidate |
| **e. Emails** | Order-status mails carrying the design | **Cut by Ruslan** — out of scope, and the plugin now mails the customer nothing at all |

> **✅ Settled: the print file draws the cut line (D-048).** Ruslan asked for it after printing a
> sheet of cupcakes with no circles on it. Solid black, 0.3 mm, derived from `sheet_plan()` so
> the line and the artwork cannot drift.
>
> **Reprint any proof you checked before 2026-08-04.** Finding this turned up a second bug:
> **GD ignores `imagesetthickness()` for `imageellipse()`**, silently, so both `FulfilPipeline`
> and `ProofSheet` drew a **1 px hairline** where 0.3 mm was asked for. Every proof produced
> before the fix understates its own line weight. `GdEngine::ring()` is now the only way either
> of them draws a circle.

> **The photo-upload idea lost its safety net.** It was parked partly because the review queue
> made it viable — a photo product is arbitrary customer bitmaps and moderation layers 0–2 are
> blind to them, so §10 layer 3 was the only control. That screen is gone. The control still
> exists, because Ruslan looks at every sheet he prints; it is just no longer in the software.
> Worth saying out loud if that idea comes back.

**4. Keep buying designs through the storefront as a customer.** The first real customer order
(D-031) found a bug none of the assertions could, because they ran with privileges the real code
does not have. That is a different kind of check and it is worth repeating.

**3. Revisit abuse protection with Ruslan — a design conversation, not a task.** Raised
2026-08-03; he wants to go through it properly rather than accept the §11 defaults. What exists
today and is verified working: 5 free generations per session / 20 logged in, per-IP ceiling
30/day, minimum 3 s between requests, global concurrency cap, and the budget guard's daily and
monthly USD ceilings. §8.6's conclusion is the frame for that talk — **the dominant cost risk is
not per-call price, it is an unthrottled endpoint being hammered.** Now that generation costs
real money ($0.012 an image), the numbers deserve a decision rather than a default.

### A design direction exists but is not scheduled — D-033, D-034 and D-035

Three sessions' worth of design discussion, agreed in principle, **no code written**:

- **D-033** — the text layer moves to the browser (transparent PNG + the plain string), the
  print canvas becomes A4 with everything centred in the usable region, and the server draws a
  **solid black cut line** at trim because the customer cuts the sheet. Deletes all server-side
  text rendering. Adds one mandatory check: every non-transparent pixel in an uploaded layer must
  be close to a colour the customer declared, or the endpoint accepts arbitrary artwork and
  layers 0–2 are blind to it.
- **D-034** — a multi-stage wizard rather than one crowded product page, **with real WooCommerce
  products kept underneath it** for SEO, pricing, tax and cart. Presentation-layer change; Phase
  6 survives almost intact.

- **D-035** — **one AI product, not ten.** Format — shape, size, copies — leaves product meta
  and becomes a wizard choice recorded on the design row, from an admin-editable format
  catalogue. Adding a size becomes a table row, not a new product. **Supersedes `PLAN.md` §4.1.**
  Reworks `ProductFields`, `PrintSpec` and `CartIntegration`; Phase 7 is unaffected. *Its pricing
  half is withdrawn by D-036.*
- **D-036** — **the plugin owns no pricing.** Observed on the live cart: the shop uses a
  **simple** product plus **WC Fields Factory 4.1.9** surcharge fields, not variations. Base
  €3.50, `Cukrinio lakšto mokestis +1,50 €`, `Užrašo mokestis +1,00 €` — each already rendered
  as its own labelled row on the line. So AI generation is one more WCFF field with a price
  rule, and Ruslan edits prices exactly where he does today.

### Being built now — the D-035…D-039 model

**Step 1 done: the money path** (D-036). `WooCommerce/FieldsFactory.php` + `tools/wcff-check.php`,
18 assertions. Product `ai-paveikslelis` on the testbed charges 3.50 / 4.50 / 5.00 by sheet type,
+1.00 with AI, all of it WCFF's doing.

**Step 2 done: the geometry.**

| File | What it does |
|---|---|
| `Domain/FormatCatalogue.php` | The three format types; hardcoded size lists, **derived** arrangement |
| `Domain/PrintSpec.php` | Gains `for_design()` — design → variation → product → default |
| `Imaging/SheetLayout.php` | Usable area now **210 × 282 mm** (D-039), plus `ICING_SHORTFALL_MM` |
| `Admin/FormatsPage.php` | Every offered format drawn to scale on one page (D-038) |
| `Installer.php` | Schema **3** — `format_type`, `format_mm` on `aicake_designs` |

Verified on the testbed: the admin page renders all 16 formats, none unfit, counts matching §3.5
exactly — A4, ⌀20…15 cm yield 1, ⌀14…11 cm yield 2, ⌀10 cm yields 4, cupcakes 35 / 24 / 20 / 12.

**Printable proofs, for the physical check D-039 makes the authority.**
`Imaging/ProofSheet.php` renders any format as a **full A4 PNG at 300 DPI with the resolution
declared in the file**, so printing at 100% is correct by construction — a proof that lied about
its own size would be measured, believed, and used to sign off wrong geometry (D-027).

| | |
|---|---|
| One at a time | „Download A4 PNG" on each card, `admin_post` + nonce (D-028, not a bare link) |
| All sixteen | `tools/proof-check.php` → `Z:\ruslan\wordpress-test\aicake-files\proofs\` |

The sheet draws the trim line solid black at 0.3 mm — the line the customer cuts (D-033) — the
bleed ring in grey, and the **15 mm dead strip hatched** rather than silently subtracted, with
the caption inside it so it can never land on a product. Two of the sixteen were looked at, not
just asserted: the 24-up shows its outer bleed rings clipping at the sheet edge, which is the
`bleed_clipped` advisory made visible.

> **`order-check.php`'s sheet assertion is now derived, not typed.** It was `array( 2363, 3390 )`
> — right for the assumed 200 × 287 and wrong the moment D-039 corrected it. A frozen number
> there goes red for the right reason and then gets "fixed" by pasting in whatever the code
> produced, which asserts nothing. It now reads the usable-area constants.

**Step 3 done: wizard step 1.** `[aicake_wizard]` on
`http://100.127.55.45:8080/ai-paveikslelis-vedlys/`, verified in a real browser inside Blocksy:
choosing a format reveals the size list, the piece count is stated („Gausite: 35 vnt."), and the
price tracks the sheet type — 3,50 → 5,00 € on cukrinis.

| File | What it does |
|---|---|
| `Frontend/Wizard.php` | Shortcode, format grouping, sheet types read from WCFF, precomputed prices |
| `templates/wizard.php` | Step 1 markup, theme-overridable at `ai-cake-topper/wizard.php` |
| `assets/js/wizard.js` | Step logic, hash-addressable, live count and price |
| `Rest/GenerateEndpoint.php` | Validates the format and **derives the aspect from it** |
| `tools/wizard-check.php` | 24 assertions — also creates the page if missing |

Two things done deliberately server-side:

- **Prices are precomputed per combination, never calculated in the browser.** Whether a figure
  includes VAT depends on two shop settings, and a running total that disagrees with the cart by
  21% is worse than none. `wcff-check` proves WooCommerce charges those figures; `wizard-check`
  proves the wizard quotes the same ones.
- **The generation aspect comes from the format, not from the client.** They are not independent
  (§3.2), and a posted aspect that disagrees produces a wrongly cropped generation at our expense.

**Parked idea, Ruslan's, 2026-08-03, not scheduled: the customer uploads their own photo,**
crops a circle out of it interactively, and gets cupcakes — with the D-033 editor on top for
text. Possibly a separate product, possibly a branch inside this wizard; undecided.

Worth knowing when it comes up:

- **Most of it exists.** `SheetLayout`, `FulfilPipeline`, the order archive and the text editor
  are all source-agnostic. What is new is an upload endpoint and a crop UI, and the crop UI is
  the editor's canvas machinery again.
- **The browser must send the crop rectangle, not the cropped image.** Cropping client-side
  either throws away resolution or ships a multi-megabyte base64 blob. The server crops from the
  original.
- **Downscale on receipt and discard the original.** Peak memory is already 339 MB (D-023) and
  production's limit is still unverified. A 12 MP phone photo is ~48 MB decoded in GD before any
  canvas is allocated.
- **Nothing in the software would vet the image.** A photo product is arbitrary customer bitmaps
  by design — the exact thing `LayerInspector` exists to refuse for text — and moderation layers
  0–2 are blind to it because there is no prompt to read. This used to read "the review queue
  becomes a prerequisite"; D-047 deleted that screen. The control is Ruslan looking at every
  sheet he prints, which is real but is not code, so it holds only as long as he prints
  personally. Say that out loud before building this, rather than rebuilding the screen he
  already rejected.
- **It needs a rights confirmation at upload.** Liability moves to the customer, which is normal
  for photo toppers, but only if they are asked.
- **Pricing needs no new mechanism.** The €1 AI fee is already derived server-side from whether
  a design really has a generated image, so a photo design simply does not attract it.

Related: Ruslan also expects the **no-AI path** (text only, no generated image) to become its own
product eventually. Same mechanism, and also not scheduled.

**Parked idea, Ruslan's, not scheduled:** show a **live diagram of the sheet beside the size
choice**, so the customer sees the layout rather than reading a count. He accepted the current
view for now and wants to think about it. Worth knowing when it comes up: the machinery already
exists — `Admin/FormatsPage::diagram()` draws exactly this from `SheetLayout::plan()`, so the
work is moving it to the frontend, not inventing it. It must keep deriving from `SheetLayout`
rather than shipping fixed pictures (D-038), or the preview and the print drift apart.

**Step 4 done: generation inside the wizard.** Proven with a real fal generation from the wizard,
not a simulation: prompt → 202 → poll → preview, `remaining` counting down 18 → 17, design
`2c107f9158798417fab447fd1190ecf2` written with **`format_type=circle`, `format_mm=150.00`,
`aspect=1:1`, `status=done`, `cost 0.0121`**.

`assets/js/generation.js` now holds the engine — session, D-025's nonce rules, the §6.5 polling
contract — and `wizard.js` is a thin adapter over it. (It was extracted when `generator.js` was
the second caller; D-047 deleted that one, and the engine stays separate because the polling
contract is worth reading without a UI around it.) The product-page
generator was re-verified after the extraction; its remaining-count text is written solely by the
engine's session hook, so seeing it proves the adapter still drives the engine.

> **The generation aspect is derived from the format server-side, and it is falsified.** A client
> posting `aspect: 1:1` for a whole sheet gets `2:3` stored. Proven through the real endpoint
> using a **blocked** prompt — layer 1 refuses it before anything is queued, so the check costs
> nothing while §10 still writes the row to inspect. Disabling the derivation on the deployed copy
> turns that assertion red; restoring it turns it green.

**Step 5 done: the D-033 text editor.** Proven in a real browser on a real fal
generation, not a simulation: format → prompt → preview → step 3, three *different* names on
three of twenty-four cupcakes, saved, and the stored print-resolution layer measured.

| File | What it does |
|---|---|
| `Imaging/LayerInspector.php` | The colour + density gate on customer bitmaps |
| `Domain/TextLayer.php` | The bitmap, the plain string, the declared colours |
| `Domain/PrintSpec.php` | Gains `canvas_px()` and `editor_layout()` — one source for the print canvas |
| `Rest/TextLayerEndpoint.php` | `POST /text-layer` — ownership, moderation, size, pixels |
| `assets/js/editor.js` | The canvas editor: drag, per-piece text, safe-zone constraint, export |
| `tools/text-check.php` | **24 assertions, falsified twice** |
| `tools/layer-check.php` | Diagnostic: is a stored layer inside its safe zones? |

Measured, not assumed:

- **The inspector costs 0.44 s and 40 MB** over a full 8.3 M-pixel A4 layer at 300 DPI, accept
  and worst-case reject alike. Once per design, so constraint #2 holds.
- **The limit is a constraint, not a guide — and it is the cut line now (D-042).** Text dragged
  900 px downward comes back clamped **1.17 mm inside the trim circle**, and 3.91 mm past where
  the old 5 mm safe margin would have stopped it. Round pieces clamp radially, not per axis.
- **The layer is exactly the print canvas** — 2481 × 3331 for a 4.5 cm cupcake sheet, matching
  `PrintSpec::canvas_px()`, which is also what `FulfilPipeline` builds.
- **The plain string survives** as „Ąžuolas Eglė Rūta", so layers 0 and 1 still read what was
  typed even though it is a bitmap.

> **The colour check concedes one thing, deliberately.** Allowing blends between declared colours
> — which a stroke over a fill genuinely produces — means black plus white admits the whole grey
> ramp, so *greyscale* art satisfies the colour rule. `MAX_COVERAGE` is what closes it, and the
> falsification shows the two halves are independent: disabling the colour test leaves "a picture
> is refused" green, because density catches it alone.

Colours are a real picker and fonts a visual listbox showing the customer's own text in each
face (D-042). **The font list is still the four bundled DejaVu faces** — Ruslan's call was to
build the picker first and pick the decorative set separately. That is D-023, and it is now the
most visible open item in the wizard.

**D-041's „Pasiūlyk dizainą" button is built.** Proven against the live Gemini API: „Su
gimtadieniu Ąžuolas 5 metai" comes back as three lines with the name largest, uppercased, white
on a black outline, all inside the circle.

| File | What it does |
|---|---|
| `Pipeline/LayoutSuggester.php` | Calls `gemini-3.1-flash-lite`, then clamps everything it says |
| `Rest/LayoutEndpoint.php` | `POST /layout` — ownership, moderation, cooldown |
| `assets/js/editor.js` | `applySuggestion()` and `arrange()` |
| `tests/LayoutSuggesterTest.php` | 16 assertions over a stubbed `HttpClient`, no network |

Three things worth knowing:

- **The model returns ratios, never pixels**, and its sizes are hints. One suggestion is then
  usable on a 4 cm cupcake and a 20 cm topper alike, and a model that never sees a pixel figure
  cannot return one that disagrees with the server's geometry.
- **Every word the customer typed must survive, and no others.** Case and line splits are the
  model's to change; the words are not. A suggestion that invents or drops one is discarded
  rather than corrected — moderation ran against what was typed, and a name missing off a
  birthday cake is not worth salvaging a layout for.
- **Spacing is solved where the metrics are.** On a round piece, "move it up so it stops
  overlapping" and "make it fit across the circle" are the same problem: a line further from the
  centre has a shorter chord to fit in. Solving them separately produced text pushed off centre
  for clearance and then clamped straight back on top of the line below. `arrange()` stacks and
  shrinks together, and runs only when the customer asks for an arrangement — re-flowing on every
  change would undo dragging, and dragging is the point.

**Still to delete:** all server-side text rendering — `TextRenderer`, arc text, auto-fit,
wrapping, the cmap gate, `TextSpec`. D-033 says delete nothing until the browser side works. It
now works; deleting is a deliberate separate commit, and `FulfilPipeline` must composite the
stored layer first.

**Step 6 next:** the proof step (step 4), then the cart hand-off. The AI flag must be derived
server-side in `CartIntegration` from whether the design really has a generated image — a posted
flag about whether money was spent cannot be trusted, and hiding the field is not a control.

> **Do not polish the frontend against the testbed theme** (Ruslan, 2026-08-03). The testbed runs
> an older Blocksy child; live has many small modifications, and he does the cosmetics at ship
> time. Functional CSS only — a control that cannot be seen or clicked is worth fixing, spacing
> and colour are not.

None of D-033/034 is scheduled against Phase 8. Read all of D-033 → D-039 before starting any.

**Next concrete step for this thread: install WC Fields Factory 4.1.9 on the testbed** — the same
build as production, not the wordpress.org one, because pricing behaviour is where those differ.
The one thing to verify is whether a **programmatically set** `wccpf_*` field still fires its
price rule and still renders on the cart line and the order. The wizard cannot ask "did you use
AI?" — it is implied. If that does not work, the fallback is our own line adjustment, which
reintroduces the second pricing mechanism D-036 exists to avoid.

Worth doing soon, none blocking:

- **Tune the prompt suffix** against real output (D-019, confirmed on a real print in D-030):
  the drop shadow is still there, and the subject sits low and right of centre so a
  `PLACE_BOTTOM` greeting lands across it. Prompt work, not pipeline work.
- **Watch the spend.** Generation now costs real money — $0.012 per image. `BudgetGuard`'s
  daily/monthly ceilings have never been exercised against non-zero cost.
- **Pick the decorative fonts** (D-023). Four are bundled and verified but workmanlike; the
  coverage machinery will vet any candidate and name the exact characters a font is missing.
  **D-041 raises the stakes** — the layout model names fonts, so the offered list is what it
  picks from, and each needs Lithuanian coverage checked client-side.
- **Confirm production's `memory_limit`** before go-live. Measured peak is 339 MB (D-023).
- **Grow the blocklist from real rejections** once traffic exists — that is what the rejection
  log is for, and the admin screen now edits the list without a deploy.

**The testbed is rebuilt and ready.** Confirmed inside the
container: `AICAKE_REPLICATE_KEY` (40 chars), `AICAKE_GEMINI_KEY` (53), `AICAKE_FAL_KEY` (69),
`AICAKE_FORCE_GD` true, `AICAKE_STORAGE_DIR` set. `AICAKE_OPENAI_KEY` and `AICAKE_LLM_KEY` are
defined but empty. wp-cli 2.12.0 is baked into the image. All 23 smoke assertions pass.

> **Do not add `default-mysql-client` to the Dockerfile.** It was tried and reverted. Debian now
> ships the MariaDB **11.8** client, which requires TLS, against the pinned MariaDB **10.11**
> server, which has none — so every `wp db` command dies with `ERROR 2026 … SSL is required, but
> the server does not support it`, which reads like a plugin bug. `--skip-ssl` fixes a direct
> `mysql` call and a `[client]` entry in `/etc/mysql/conf.d` fixes that too, but neither reaches
> wp-cli: its pre-flight "get current SQL modes" query hardcodes `--no-defaults`. Use `$wpdb`
> through `wp eval` for schema work instead.

Housekeeping, not blocking:

- `PLAN.md` §8 predates the Replicate finding and still reads as though fal is the only image
  candidate. §19 lists provider files that no longer match. Reconcile when the provider decision
  is actually made, not before.
- The house style suffix must be phrased **positively** — a `flux-dev` test proved negative
  instructions are ignored: "no cake or background needed" produced exactly a cake.
**Re-verify everything in two commands** (from `C:\AI_IMAGE`, after `tools\sync.ps1`). The
`*-check.php` files must also be copied to `Z:\ruslan\wordpress-test\aicake-files\` — `sync.ps1`
deploys the plugin only, which has caught me out twice:

```bash
ssh ruslan@ruslan-server 'cd /home/ruslan/wordpress-test && docker compose exec -T wordpress php wp-content/plugins/ai-cake-topper/tests/run.php | tail -1; for f in wizard-check wcff-check order-check proof-check text-check; do echo -n "$f: "; docker compose exec -T -u www-data wordpress wp eval-file /var/lib/aicake/$f.php --path=/var/www/html | grep "passed,"; done'
```

```bash
bash tools/rest-check.sh
```

`layer-check.php` is a diagnostic, not a gate, and takes a design id (or picks the newest layer).

- **`tools/fresh-install.sh` rehearses the production install** (Ruslan's idea, 2026-08-07).
  Destroys and rebuilds a clean WordPress + WooCommerce + WCFF stack in `infra/fresh/` on
  port **8081**, installs the plugin from the git working copy, activates it once, and runs
  `tools/fresh-check.php` — **36 assertions**. The main testbed cannot answer this: its
  tables were migrated forward from an older schema and its options carry weeks of manual
  testing. Falsified by chowning `sessions/` to root, which turns 3 red — the D-003/D-031
  bug that has broken this project twice.

- **Nine suites, all committed and all green — 658 assertions** (re-verified 2026-08-07,
  after D-050/D-051; `tools/settings-check.php` is the new one, 34 assertions): `tests/run.php` 368, `tools/rest-check.sh` (12, over real HTTP, logged out *and* in),
  `tools/order-check.php` (63, a real order end to end, including a D-033 layer and the cut
  line), `tools/wcff-check.php` (30, the money path, the D-044 hand-off and the D-045 thumbnail),
  `tools/proof-check.php` (18, printable proofs — also writes them), `tools/wizard-check.php`
  (39, steps 1–2, the D-043 layout key and D-048's entry points), `tools/text-check.php` (30,
  including the D-045 proof), `tools/settings-check.php` (34, the encrypted key store and the
  counter reset). `tools/review-check.php` is deleted with the screen it tested.
  All but the first test the *deployed* copy, so sync first. Falsified rather than merely passed:
  reintroducing D-025 turns 5 of the 12 red; trusting the posted AI flag turns 3 of the 30 red
  and restoring the old product-meta gate turns 13 red; keying the wizard's layouts independently
  of `FormatCatalogue::layout_key()` turns 3 of the 35 red; serving the preview instead of the
  proof turns the thumbnail assertion red; reintroducing an `update_status()` call in
  `Fulfilment` turns 2 of the 63 red (D-047); **removing the cut line turns 1 red, and posting
  the cart form to the product permalink again turns 1 of the 39 red** (D-048).

> **`rest-check.sh` reads the printed nonce off the wizard page**, not a product page — D-047
> deleted the product-page generator that used to carry it. Getting that wrong turns 5 of the 12
> red in a way that looks exactly like having broken D-026. The page is now argument 1
> (`/ai-paveikslelis-vedlys/`) and the product id is argument 2.

> **⚠ The shop's own settings can turn committed gates red, and did (2026-08-07).**
> `text-check` (29/30) and `moderation-check` (32/34) were both failing before any migration
> work started, and stashing every change and re-running against `HEAD` reproduced them exactly
> — so not a regression. The cause was **`moderation_blocklist` and `moderation_ai` both
> switched off** in `aicake_settings`, left over from D-049's browser check, plus throttles at
> **100000**. Turning the two layers back on restored 34 and 30; `free_per_session` back to 5
> restored `rest-check`'s twelfth assertion.
>
> **Read the option before debugging either suite.** D-049 made moderation the shop's decision,
> which means the shop can switch off the thing its own tests assert:
>
> ```
> wp eval 'var_dump( get_option( "aicake_settings" ) );'
> ```

> **The testbed's limits are currently lifted for manual testing (2026-08-04, re-checked
> 2026-08-07 — `free_per_user` and `ip_daily_ceiling` are at 100000, not the 500 below):**
> `free_per_user` **500** (default 20) and `ip_daily_ceiling` **500** (default 30).
> `free_per_session` is still **5**, deliberately — raising it breaks „logged-in allowance
> exceeds anonymous" in `rest-check`, correctly. Put them back with:
>
> ```
> wp eval 'AiCake\Plugin::instance()->settings()->update(
>   array( "free_per_user" => 20, "ip_daily_ceiling" => 30 ) );'
> ```

> **A 429 in any check is the throttle, not the thing under test — and there are two of them
> behind one message.** `aicake_session_limit` is `free_per_user`/`free_per_session`;
> `aicake_ip_limit` is `ip_daily_ceiling`, default 30, per IP. „Pasiektas dienos piešinių
> limitas." is what the customer sees for *both*, which is why this cost time twice.
> `rest-check.sh` now prints the code on a 429 and says which knob to lift;
> `wizard-check.php` lifts and restores its own throttle so it re-runs from nothing on a busy
> day. Lift `ip_daily_ceiling` only — raising `free_per_session` breaks „logged-in allowance
> exceeds anonymous", correctly.

- **The plugin's logging is invisible under WP-CLI**, and so is WooCommerce's own: a
  `wc_get_logger()->warning()` from `wp eval` reaches no file, while the same call over HTTP
  lands in `wp-content/uploads/wc-logs/` as expected. Not chased — it did not block anything,
  because the fulfilment checks verify files and database state rather than log lines. Worth
  knowing before anyone debugs a fulfilment problem from the command line and concludes nothing
  ran.

## Open items, not blocking

- **Confirm GD FreeType on the live host before Phase 4** — see Production above. Not urgent,
  high confidence, three ways to check. Do not push the client to upload things to the live shop.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Under D-037/D-038 this stops mattering as a
  *product* question, but it still decides which cases are worth offering.

**The geometry is settled (D-039) and validated by printing, not by arithmetic.** Usable area is
**282 × 210 mm** — full A4 less the 15 mm of bare icing at the right, no printer margins. ⌀20 cm
is the declared maximum and fits (206 against 210). Circle list 20 → 10 cm in 1 cm steps, count
"as many as fit" and stated in the wizard. Cupcakes 35 / 24 / 20 / 12 at ⌀4.0 / 4.5 / 5.0 / 6.0.

> **Do not re-derive printer physics from specifications.** I twice argued ⌀20 cm could not fit,
> from 5 mm margins I had assumed off a spec sheet. Ruslan prints ⌀20 cm circles routinely. The
> operator has the printer — ask, or wait for the print test. He will print and check every
> format before launch, and corrections come from that.

**Settled 2026-08-03 by D-037, previously open here:** the 15 mm bare-icing strip is fixed at the
right safe margin; the usable area is **277 × 200 mm** with all four margins as admin settings;
`PLAN.md`'s A4 self-contradiction resolves in favour of the usable area; and **placement inside
the page does not matter** — the design must fit and the physical size must be exact, nothing is
required to be centred. That last one also retires the "does the bare strip land at the trailing
edge?" worry, which only mattered when content had to be offset deliberately.
- VMVT food-business registration — almost certainly already held, since the shop already sells
  edible decorations. Allergen declaration from the sheet supplier is the genuinely new item.
