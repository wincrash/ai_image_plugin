# Project state

**Updated:** 2026-08-02
**Phase:** 1 — foundation. Phase 0 deferred to a later calibration step (D-018).

> Read `WORKFLOW.md` for how we work, `PLAN.md` for the design, `DECISIONS.md` for why.

---

## Where we are

Planning is done and the testbed is ready. No plugin code exists yet. The next concrete step is
Phase 0: evaluate the image APIs on real Lithuanian prompts before building anything that
depends on the choice.

| Phase | Status |
|---|---|
| 0 · API evaluation | **Deferred** to a calibration step after the plugin runs end to end (D-018). |
| 1 · Foundation | **Done and verified on the testbed.** |
| 2 · Providers | **Done and verified against the live APIs.** |
| 3 · Job system | **Next** |
| 4–9 | Not started |

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

> **Cost recording is deliberately conservative.** `ReplicateProvider::estimate_cost()` records
> the **list price** even while these calls are free, because the API gives no way to tell
> whether a prediction was billed. Over-recording is the safe direction for a spend guard and
> the figure becomes exactly right the moment credit is added. It does mean the Phase 8 cost
> dashboard will read high until then.

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

### Provider access — probed directly 2026-08-02

Keys for fal, Google and Replicate are in `.env` and all three authenticate. Every claim below
was tested against the live API, not read off a pricing page.

| Provider | Verdict |
|---|---|
| **Replicate** | **Some models run with no credit.** Free: `flux-dev` (confirmed by a real 1024² generation in 1.4 s), `flux-1.1-pro`, `flux-2-pro`. Blocked: `flux-schnell`, `flux-2-dev`, `flux-1.1-pro-ultra`, `imagen-4-fast`, `nano-banana`, `nano-banana-2`, `real-esrgan`, `recraft-v3`. |
| **Google — text** | **Free and working.** `gemini-3.1-flash-lite` translates Lithuanian correctly. |
| **Google — image** | `429 … free_tier_requests, limit: 0`. Explicitly zero on the free tier. |
| **fal.ai** | `403 User is locked. Reason: Exhausted balance.` Needs a top-up. |

The free stack we build against (D-018):

| Layer | Development provider | Cost |
|---|---|---|
| Image generation | Replicate `black-forest-labs/flux-dev` | free |
| Translate + moderate | Google `gemini-3.1-flash-lite` | free |
| Upscale | GD bicubic in PHP | free, and it is the production fallback anyway |

> **Free Replicate access is undocumented and must never be a production dependency** (D-017).
> The split follows no pattern — the cheapest model is blocked, the top tier is free — and the
> rate limit is reduced to ~6 predictions/min. Production runs on a funded account.

`AICAKE_OPENAI_KEY` and `AICAKE_LLM_KEY` are still empty. `AICAKE_REPLICATE_KEY` was added and is
not yet in `infra/.env.example`.

**When money is wanted later:** top up fal.ai — it is the primary candidate in `PLAN.md` §8 and
covers Suite A *and* Suite B alone. Whole-phase budget is still under $5.

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
├── DECISIONS.md             append-only decision log (14 entries)
├── idea.md                  original brief, superseded by PLAN.md
├── docs\api-evaluation.md   Phase 0 plan
├── infra\                   testbed Docker config — applied
├── tools\sync.ps1           C:\AI_IMAGE  ->  Z:\
└── plugin\                  (empty — created in Phase 1)
```

## Next actions

**Phase 3 — job system** (`PLAN.md` §21, §6). This is the phase that makes generation
customer-safe: today the only caller is an admin screen that blocks for five seconds, which is
fine for one shop owner and unacceptable for a storefront on 4–8 PHP workers.

1. Jobs table is already created; add `Domain/Job.php` + `JobRepository.php` with the **atomic
   claim** — `UPDATE … SET status='claimed' WHERE id=? AND status='queued'`, checking
   `affected_rows`. A duplicate run costs real money (§6.3).
2. `Queue/Dispatcher.php` — loopback spawn → poll fallback → Action Scheduler sweeper (§6.2).
3. `Queue/Runner.php` — claims and executes one job, calling the Phase 2 registry.
4. Concurrency cap, default 3 (§6.4).
5. REST endpoints: uncached session/nonce (§7), generate, job status.
6. **Test with loopback deliberately broken** — §21 is explicit about this.

`ReplicateProvider::start()` and `poll()` already exist for exactly this, so the async path
needs no adapter changes.

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
- No unit tests yet. `tests/` is specified in §19 for `Mm`, `SheetLayout` and `LtNormaliser`;
  none of those classes exist yet, so there is nothing to test that is not WordPress-bound.

## Open items, not blocking

- **Confirm GD FreeType on the live host before Phase 4** — see Production above. Not urgent,
  high confidence, three ways to check. Do not push the client to upload things to the live shop.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Confirm against what is actually sold; 5 cm
  yields 20 and the SKU name must match.
- Printer make/model unknown → usable print area defaults to 200 × 287 mm.
- Icing sheet is slightly shorter than A4; exact dimensions to be corrected late.
- VMVT food-business registration — almost certainly already held, since the shop already sells
  edible decorations. Allergen declaration from the sheet supplier is the genuinely new item.
