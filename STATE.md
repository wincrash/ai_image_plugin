# Project state

**Updated:** 2026-08-03
**Phase:** 7 — orders and fulfilment. Phase 0 deferred to a later calibration step (D-018).

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

**218 committed assertions, all green:** 152 pure-PHP · 12 REST over real HTTP · 54 a real order
through to print files.

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
| `WooCommerce/OrderStatuses.php` | The five statuses, registered HPOS **and** legacy **and** in the dropdown |
| `WooCommerce/Fulfilment.php` | AS job per line item, idempotency, retries, status flips, reorder |
| `Pipeline/FulfilPipeline.php` | master → upscale → shape → text at 300 DPI → imposition → flatten → PNG |
| `Storage/OrderArchive.php` | `sessions/` → `orders/`, DB repoint, the `.json` sidecar |
| `Domain/PrintFile.php` | The rendered file and what it took to make it |
| `Admin/OrderScreen.php` | Preview, gated download, retry button |
| `tools/order-check.php` | **The gate, committed and re-runnable — 54 assertions** |

Produced and inspected, not just asserted, from a real
`woocommerce_order_status_processing` transition:

- **15 cm topper** — 1843 px square, 300 DPI, 156.0 × 156.0 mm, circle-masked with arc text.
- **24-up cupcake sheet** — 2363 × 3390 px, 200.1 × 287.0 mm, 4 × 6 evenly gutterred.
- **The order folder** — `orders/2026/08/<id>/` with `item-N-print.png`, `-master.png`,
  `-preview.webp` and `item-N.json`, browsable on the SMB share exactly as §12.2 promises.

Also verified: the order reaches `aicake-approval` only when *every* item has a file; a second
run does not re-render; a missing master retries three times then lands on `aicake-failed` with
an order note and an admin email (seen in Mailpit); the retry button recovers it and lifts the
order back out of `failed`; an ordinary sale with no design is left in `processing`; and
"Order again" carries the design across.

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
| `Frontend/Generator.php` | Enqueue + render, theme-overridable template |
| `Pipeline/PreviewPipeline.php` | master → shape → text → watermark → WebP |
| `templates/generator.php`, `assets/` | The UI, Lithuanian, mobile-first |

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
| `Moderation/Moderator.php` | Layer ordering, verdict caching, customer-facing wording |
| `Admin/BlocklistPage.php` | Edit terms and try a prompt against the free layers |

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
| Theme | Blocksy 2.1.49 + `valgomos` child |
| Cart / checkout | **Classic shortcode**, not blocks (`/krepselis/`) |
| PHP memory | **512M** (was 128M) |
| Imagick | **Present** — but see below, we do not build against it |
| GD | Present |
| Accounts | `ruslan` (administrator) · `testuser` / `TestPass123` (**customer**, D-026) · `testmanager` / `TestPass123` (**shop_manager**, D-028) |
| Mailpit | `http://100.127.55.45:8025` |
| DB | `wp_user` / `wp_password` / `wordpress` |
| Other plugins | WooPayments, PayPal, MailPoet, Unisend, Jetpack, Pinterest, Google Listings & Ads, WooCommerce POS |

### Production — capabilities confirmed 2026-08-02

`valgomosdekoracijos.lt` — ~265 products. A **managed platform, not a Linux machine**: PHP
libraries and WordPress plugins can be added, system packages cannot.

From wp-admin → Site Health → Media Handling:

| | |
|---|---|
| Active image editor | `WP_Image_Editor_GD` |
| Imagick / ImageMagick | **none** |
| GD | bundled (2.1.0 compatible) |
| GD formats | GIF, JPEG, PNG, **WebP**, BMP |
| Ghostscript | not detected (irrelevant — PNG only) |
| Max upload | 64 MB |

> **The testbed has Imagick; production does not.** GD is the target engine and
> `AICAKE_FORCE_GD` defaults on, so development happens on the production path.
> See `PLAN.md` §9.1 and D-013/D-015.

**No external render server is needed** — GD + pure PHP + the AI APIs cover everything
(`PLAN.md` §9.1.3, D-015).

**GD FreeType: assumed present, not yet verified.** Site Health does not report it and the text
layer depends on it. The client declined to upload a diagnostic to the live shop — reasonable,
and it is not needed yet.

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
├── DECISIONS.md             append-only decision log (29 entries)
├── idea.md                  original brief, superseded by PLAN.md
├── docs\api-evaluation.md   Phase 0 plan
├── infra\                   testbed Docker config — applied
├── tools\sync.ps1           C:\AI_IMAGE  ->  Z:\
├── tools\rest-check.sh      REST over real HTTP, logged out and logged in
├── tools\order-check.php    a real order through to print files (Phase 7's gate)
└── plugin\                  the plugin itself
```

## Next actions

**Nothing is blocked.** fal is funded and the success path is verified (D-030).

**1. Phase 8 — operations** (§14): review queue, print queue, cost dashboard, cleanup cron,
emails. The review queue is the screen §10 layer 3 makes non-negotiable, and `aicake-approval`
orders are already piling up in a real, filterable status waiting for it.

**2. Keep buying designs through the storefront as a customer.** The first real customer order
(D-031) found a bug none of the 218 assertions could, because the assertions ran with privileges
the real code does not have. That is a different kind of check and it is worth repeating.

Worth doing soon, none blocking:

- **Tune the prompt suffix** against real output (D-019, confirmed on a real print in D-030):
  the drop shadow is still there, and the subject sits low and right of centre so a
  `PLACE_BOTTOM` greeting lands across it. Prompt work, not pipeline work.
- **Watch the spend.** Generation now costs real money — $0.012 per image. `BudgetGuard`'s
  daily/monthly ceilings have never been exercised against non-zero cost.
- **Pick the decorative fonts** (D-023). Four are bundled and verified but workmanlike; the
  coverage machinery will vet any candidate and name the exact characters a font is missing.
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
- **Three suites, all committed and all green:** `tests/run.php` (152 pure-PHP assertions),
  `tools/rest-check.sh` (12, over real HTTP, logged out *and* in), `tools/order-check.php` (54,
  a real order end to end). The last two test the *deployed* copy, so sync first. `rest-check.sh`
  was falsified before being trusted — reintroducing D-025 turns 5 of its 12 red.

- **The plugin's logging is invisible under WP-CLI**, and so is WooCommerce's own: a
  `wc_get_logger()->warning()` from `wp eval` reaches no file, while the same call over HTTP
  lands in `wp-content/uploads/wc-logs/` as expected. Not chased — it did not block anything,
  because the fulfilment checks verify files and database state rather than log lines. Worth
  knowing before anyone debugs a fulfilment problem from the command line and concludes nothing
  ran.

## Open items, not blocking

- **Confirm GD FreeType on the live host before Phase 4** — see Production above. Not urgent,
  high confidence, three ways to check. Do not push the client to upload things to the live shop.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Confirm against what is actually sold; 5 cm
  yields 20 and the SKU name must match.
- Printer make/model unknown → usable print area defaults to 200 × 287 mm.
- Icing sheet is slightly shorter than A4; exact dimensions to be corrected late.
- VMVT food-business registration — almost certainly already held, since the shop already sells
  edible decorations. Allergen declaration from the sheet supplier is the genuinely new item.
