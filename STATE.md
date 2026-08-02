# Project state

**Updated:** 2026-08-02
**Phase:** 0 — API evaluation (planned, blocked on API keys)

> Read `WORKFLOW.md` for how we work, `PLAN.md` for the design, `DECISIONS.md` for why.

---

## Where we are

Planning is done and the testbed is ready. No plugin code exists yet. The next concrete step is
Phase 0: evaluate the image APIs on real Lithuanian prompts before building anything that
depends on the choice.

| Phase | Status |
|---|---|
| 0 · API evaluation | **Planned.** Blocked on fal.ai + Google AI Studio keys. |
| 1 · Foundation | Not started |
| 2–9 | Not started |

## Blocked on

**API keys** — fal.ai and Google AI Studio, into `Z:\ruslan\wordpress-test\.env`.
Nothing in Phase 0 can run without them. (`docs/api-evaluation.md` §8)

That is the only blocker. The testbed is up and the repository is on GitHub.

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

**Unverified and critical: GD FreeType support.** Site Health does not report it and the whole
text layer depends on it. Run `tools/host-check.php` on the live host to confirm.

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

1. Get fal.ai + Google AI Studio keys into `Z:\ruslan\wordpress-test\.env`.
2. Build the Phase 0 harness (`docs/api-evaluation.md` §2) — adapters written at their final
   paths against their final interfaces, so Phase 2 reuses them.
3. Run Suites A (generation), B (upscaling, **against GD bicubic**), C (translate + moderate).
4. Record the outcome in `docs/api-evaluation.md` §9 and `DECISIONS.md`.

## Open items, not blocking

- **Run `tools/host-check.php` on the live host.** Confirms GD FreeType (critical — the text
  layer needs it), whether a 4096×4096 canvas can be allocated, whether a directory outside the
  webroot is writable, whether loopback requests work, and whether fal/Google are reachable
  outbound. Change the token at the top, upload, open with `?token=…`, read, **delete**.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Confirm against what is actually sold; 5 cm
  yields 20 and the SKU name must match.
- Printer make/model unknown → usable print area defaults to 200 × 287 mm.
- Icing sheet is slightly shorter than A4; exact dimensions to be corrected late.
- VMVT food-business registration — almost certainly already held, since the shop already sells
  edible decorations. Allergen declaration from the sheet supplier is the genuinely new item.
