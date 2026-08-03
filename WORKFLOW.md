# How we work on this project

Read this first after a session reset. Then read `STATE.md` for where we actually are.

---

## 1. The three environments

| | Where | Role |
|---|---|---|
| **Source** | `C:\AI_IMAGE\` (Windows, git) | Single source of truth. All editing happens here. |
| **Testbed** | `http://100.127.55.45:8080` — Docker on `ruslan-server` | Where it runs. Mirrors live: WP 7.0.2, WooCommerce 10.9.4, Blocksy + `valgomos`. |
| **Production** | `valgomosdekoracijos.lt` | Plain WordPress, no extra services. Ship only at the end. |

The testbed's compose directory is reachable from Windows as an SMB share:

```
Z:\ruslan\wordpress-test\        =  ~/wordpress-test on ruslan-server
├── docker-compose.yaml
├── themes\                      →  wp-content/themes      (existing, theme work)
├── plugins\ai-cake-topper\      →  wp-content/plugins/…   (new, ours)
├── aicake-files\                →  /var/lib/aicake        (new, generated images)
├── php\  mu-plugins\  .env      (new, config)
└── Dockerfile
```

That share is why the loop is fast: **write on Windows, refresh the browser.** No git push,
no rsync, no SSH, no container restart. PHP is read per-request.

## 2. Source of truth vs. deployed copy

`C:\AI_IMAGE\` is git-tracked and authoritative. `Z:\` is a deployment target — treat anything
there as disposable. Never edit the plugin directly on `Z:\`; the next sync overwrites it.

Deploy with:

```powershell
powershell -File C:\AI_IMAGE\tools\sync.ps1
```

It mirrors `plugin\ai-cake-topper\` → `Z:\ruslan\wordpress-test\plugins\ai-cake-topper\` and
copies the infra files. Takes about a second. Run it after any edit you want to see live.

**Exception:** `themes\` on `Z:\` is *not* mirrored from here. That is the separate theme
project with its own `CLAUDE.md`, and this project must not touch it.

## 3. Saving state — the rule

This is a long project and sessions get reset. Nothing important may live only in the chat.

Four files carry all durable state, and they are updated *as we go*, not at the end:

| File | Contains | Updated |
|---|---|---|
| `PLAN.md` | The design. What we are building and why. | When a design decision changes |
| `STATE.md` | Where we are right now. Done / in progress / next. Environment facts. | Every session, and at any meaningful checkpoint |
| `DECISIONS.md` | Append-only log: decision, date, reasoning, alternatives rejected. | Whenever a choice is made |
| `docs/` | Deep-dives that would bloat `PLAN.md` (API evaluation, print tests) | As produced |

**Git is the backstop.** Commit at every checkpoint, with a message that says what changed and
why. If a session is lost, `git log` plus `STATE.md` is enough to resume cold.

Rules of thumb:
- A decision explained only in chat is a decision that will be lost — write it to `DECISIONS.md`.
- Before ending a work block, update `STATE.md` and commit.
- Never `--force`, never rewrite history. Disk is cheap; context is not.

## 4. Git

Local repository, no remote yet. A remote (private GitHub) is worth adding once there is real
code — it is the only thing protecting against the Windows machine dying. Flagged in `STATE.md`
as an open item.

```
main          the only long-lived branch
```

No branching ceremony for a single-developer project. Commit small and often.

What is **not** committed (see `.gitignore`): `.env`, generated images, `vendor/`, anything
under `Z:\`.

## 5. Build phases and gates

Each phase ends with something demonstrable. We do not start the next until the current one
works on the testbed.

| Phase | Deliverable | Gate |
|---|---|---|
| **0. API evaluation** | `docs/api-evaluation.md` + a CLI test harness | We know which provider, at what cost and quality, on *real Lithuanian cake prompts* |
| **1. Foundation** | Plugin skeleton, tables, settings, rate limiter, **budget guard** | Plugin activates cleanly; nothing can spend money without a cap |
| **2. Providers** | Adapters lifted from Phase 0, registry, fallback | "Test provider" button returns images in wp-admin |
| **3. Job system** | Queue, atomic claim, loopback dispatch + fallbacks | A job survives loopback being deliberately broken |
| **4. Imaging** | Masks, bleed, watermark, text layer, imposition | A correct 24-up A4 PNG lands in `aicake-files\` |
| **5. Moderation** | Blocklist with LT stemming, LLM classifier | Adversarial prompt set (§ api-evaluation) is caught |
| **6. Storefront** | Product fields, generator UI, cart | A design can be generated and added to the cart |
| **7. Orders** | Statuses, fulfilment, print files, reorder | A test order produces a print file in `orders\` |
| **8. Operations** | Review queue, print queue, cost dashboard, cleanup | An order can be approved and printed end to end |
| **9. Hardening** | i18n, privacy hooks, uninstall, security review, load test | Ready to ship |

Phases 1–5 need no WooCommerce interaction and are testable from WP-CLI.

## 6. Conventions

- **WordPress Coding Standards.** Tabs, `snake_case` functions, `Class_Name` or namespaced
  `ClassName` — the plugin uses namespaced PSR-4-style classes under `AiCake\`, autoloaded by a
  hand-rolled SPL autoloader. No Composer at runtime.
- **Prefix everything** `aicake_` / `AICAKE_` / `AiCake\`. Options, tables, hooks, CSS classes,
  JS globals. A shared WordPress install is a shared namespace.
- **Every user-facing string** in `__()` with the `ai-cake-topper` text domain from the first
  line written, not retrofitted.
- **No `error_log()` left behind.** Use the plugin's logger, which respects a debug setting.
- **Escape on output, sanitise on input, `$wpdb->prepare()` always.**
- Comments explain *why*, not *what*. The code says what.

## 7. Useful commands

```bash
# The pure-PHP tests: Mm, SheetLayout, LtNormaliser, font coverage
docker compose exec wordpress php wp-content/plugins/ai-cake-topper/tests/run.php
```

```bash
# The REST layer over real HTTP, logged out AND logged in. Run this after
# touching anything in src/Rest/, Frontend/Generator.php or the throttle —
# it is the only check that authenticates, and D-025 is what happens without
# one. Deploy first; it tests the testbed, not the working copy.
bash tools/rest-check.sh
```

```bash
# Phase 7's gate: a real order through to print files, the sidecar, the
# failure path and reorder. Copy it where the container can see it, then run.
cp tools/order-check.php Z:/ruslan/wordpress-test/aicake-files/ && ssh ruslan@ruslan-server 'docker exec wordpress-test-wordpress-1 wp eval-file /var/lib/aicake/order-check.php --allow-root --path=/var/www/html'
```

> **WordPress-bound verification gets committed** (D-029). Phase 3 and Phase 5 were verified
> from scratch files that no longer exist, so their numbers cannot be reproduced. If a check is
> worth running once it goes in `tools/`.

```bash
# Watch PHP errors as they happen
docker compose logs -f wordpress
```

```bash
# WordPress state without booting PHP (the container's memory limit made this necessary before; it is fixed now, but SQL is still faster)
docker exec wordpress-test-db-1 mysql -u wp_user -pwp_password wordpress -N -e "select option_name from wp_options where option_name like 'aicake%';"
```

```bash
# WP-CLI, on demand
docker compose run --rm wpcli wp plugin list
```

```bash
# Confirm the image pipeline's capabilities
docker compose exec wordpress php -r "var_dump(extension_loaded('imagick'), extension_loaded('gd'), ini_get('memory_limit'));"
```

```bash
# Read WooCommerce order emails without sending anything real
echo "open http://100.127.55.45:8025"
```

## 8. Going to production

Not until Phase 9. When we do:

1. The plugin folder uploads as-is — no build step, no Composer install.
2. `wp-config.php` gains the `AICAKE_*` constants (keys, IP salt, storage dir).
3. `AICAKE_STORAGE_DIR` must point **outside** the webroot. If the host cannot provide that,
   `orders/` switches to hashed filenames — see `PLAN.md` §12.4.
4. Verify Imagick, `memory_limit` (≥256 M) and loopback on the real host before enabling
   anything. The plugin's Site Health panel reports all three.
5. Enable on one hidden test product first. Place a real order end to end. Then open it up.
