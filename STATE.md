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
| 1 · Foundation | **In progress** |
| 2–9 | Not started |

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

**Phase 1 — foundation** (`PLAN.md` §21). Nothing here is provider-specific.

1. Plugin skeleton + hand-rolled SPL autoloader, `plugin/ai-cake-topper/`.
2. Tables (`PLAN.md` §4.4), settings with constant-first keys, capability detection.
3. Site Health panel — reports GD, **FreeType**, memory, storage root. This is also how the
   open FreeType question gets answered on the live host without uploading a diagnostic.
4. Logger, rate limiter, **budget guard**. Per §21, nothing spends money until the guard exists.

Then Phase 2 wires the free stack above behind the §8.5 interfaces, adding a `ReplicateProvider`
alongside the planned fal and Gemini adapters.

Housekeeping, not blocking:

- Add `AICAKE_REPLICATE_KEY` to `infra/.env.example`.
- `PLAN.md` §8 predates the Replicate finding and still reads as though fal is the only image
  candidate. Reconcile when the provider decision is actually made, not before.
- The house style suffix must be phrased **positively** — a `flux-dev` test proved negative
  instructions are ignored: "no cake or background needed" produced exactly a cake.

## Open items, not blocking

- **Confirm GD FreeType on the live host before Phase 4** — see Production above. Not urgent,
  high confidence, three ways to check. Do not push the client to upload things to the live shop.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Confirm against what is actually sold; 5 cm
  yields 20 and the SKU name must match.
- Printer make/model unknown → usable print area defaults to 200 × 287 mm.
- Icing sheet is slightly shorter than A4; exact dimensions to be corrected late.
- VMVT food-business registration — almost certainly already held, since the shop already sells
  edible decorations. Allergen declaration from the sheet supplier is the genuinely new item.
