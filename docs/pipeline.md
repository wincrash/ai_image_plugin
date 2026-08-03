# The pipeline, end to end

**What runs, where it runs, and what it costs.** Written 2026-08-03, after the chain was first
verified working end to end (D-030).

This is a map of the built system, not a design document — `PLAN.md` is the design. Where the
two disagree, the code wins and `PLAN.md` needs correcting.

---

## The shape of it in one paragraph

A customer types Lithuanian on a product page. Two free moderation layers answer them
instantly, in the same request. Everything expensive — translation, the AI classifier, the
image generation — happens in a **background job**, because a shared host has 4–8 PHP workers
and none of them may block for 15 seconds. The browser polls. What comes back is a
watermarked 800 px preview; the clean master never leaves the server. When the order is paid,
a second background job re-renders that same master at 300 DPI for print. Two AI calls cost
money. Everything else is PHP and GD on the host you already pay for.

---

## Act 1 — before payment

### Stage by stage

| # | Stage | Where it runs | Blocking? | Cost |
|---|---|---|---|---|
| 1 | Page render, generator UI | PHP, cached HTML | — | free |
| 2 | `GET /session` — nonce, cookie, allowance | PHP, **uncached** | ~50 ms | free |
| 3 | **Layer 0** — sanity: length, control chars, gibberish | PHP | instant | free |
| 4 | **Layer 1** — blocklist, LT stemming | PHP | instant | free |
| 5 | Rate limit + identity | PHP + MySQL | instant | free |
| 6 | Job row + design row, `202 Accepted` | MySQL | **~209 ms total** | free |
| 7 | Dispatch: loopback socket → poll → AS sweep | PHP | non-blocking | free |
| 8 | **Layer 2** — translate + moderate, one call | **Google Gemini** | in the job | **~$0.0001** |
| 9 | Budget guard, before anything is spent | MySQL | instant | free |
| 10 | Prompt build — house style suffix | PHP | instant | free |
| 11 | **Image generation** | **fal.ai FLUX** | in the job, ~5 s | **$0.012** |
| 12 | Store master → `sessions/YYYY/MM/` | PHP | instant | free |
| 13 | Preview: cover → mask → text → **watermark** → WebP | **PHP + GD** | in the job | free |
| 14 | Browser polls `/jobs/{id}`, gets the preview URL | PHP | ~50 ms/poll | free |

Steps 8–13 all happen inside one background job. The customer sees step 6 and then polls.

### The three moderation layers, and why the order matters

§10's rule is *each layer is cheaper than the next*, and that is literally the ordering:

- **Layer 0 and 1 run synchronously in the REST handler.** They are free and instant, so the
  customer gets a real answer in the same request, and a refused prompt **queues no job and
  spends nothing**. D-024 is the payoff: the blocklist now catches, for free, every Lithuanian
  declension case the LLM was catching for $0.0001 and 790 ms.
- **Layer 2 runs in the job**, because it costs money and ~800 ms. It also does the
  Lithuanian → English translation in the same call, so the image provider never sees the
  customer's original text.
- **Layer 3 is a human**, in Phase 8's review queue, and it is the only layer that looks at the
  **image** rather than the prompt. Not built yet. Orders wait in `aicake-approval`.

A rejection is terminal and never retried — the classifier will say the same thing next time,
and re-asking costs money.

---

## Act 2 — after payment

Triggered by the real `woocommerce_order_status_processing` transition. One Action Scheduler job
**per line item**.

| # | Stage | Where | Cost |
|---|---|---|---|
| 1 | Read `_aicake_design` off the line item | MySQL | free |
| 2 | Resolve geometry: variation → product → default | PHP | free |
| 3 | Upscale, **only if the SKU needs it** | **PHP + GD bicubic** | free |
| 4 | Bleed, shape mask, text at **300 DPI** | PHP + GD | free |
| 5 | Imposition — N-up for cupcake sheets | PHP + GD | free |
| 6 | Flatten on white, PNG with a correct `pHYs` chunk | PHP + GD | free |
| 7 | Archive: `sessions/` → `orders/`, DB repoint, `.json` sidecar | PHP | free |
| 8 | Status → `aicake-approval` once **every** item has a file | PHP | free |

**Nothing in Act 2 costs money.** No AI call happens after payment. The master already exists;
this is pixels and arithmetic. That is why a retry is safe and why the reprint button is free.

Two things worth knowing:

- **Idempotency is the print file itself.** If the item already has a readable print file, the
  job returns without re-rendering. That is what makes the retry button, a duplicated status
  transition and an AS sweep all safe.
- **`on-hold` is deliberately not promoted.** An unpaid order must not walk into the print
  queue. It self-heals: on payment the order moves to `processing`, the idempotency check finds
  the existing file, nothing is re-rendered, and it lands in `aicake-approval` (D-031).

---

## What actually costs money

The complete list. Everything not on it is free.

| What | Provider | Price | When |
|---|---|---|---|
| Translate + moderate | Google `gemini-3.1-flash-lite` | ~$0.0001/call | once per generation |
| Image generation | fal.ai `fal-ai/flux/dev` | $0.012 / MP | once per generation |

That is it. Two calls, both **before** payment, both inside the background job, both behind the
budget guard.

**Per completed order** (§8.6 assumes 6 previews generated before one is bought):

| Line | Cost |
|---|---|
| 6 × translate + moderate | ~$0.0006 |
| 6 × image generation @ ~1 MP | ~$0.072 |
| Upscale | **$0.00** — GD bicubic, not Real-ESRGAN |
| **Total** | **~$0.073 ≈ €0.067** |

Against a €10–20 product that is well under 1%. §8.6's own conclusion still stands: **do not
optimise model choice for cost, optimise for abuse prevention and output quality.** The
dominant risk is not per-call price, it is an unthrottled endpoint being hammered.

### What protects the money

Four independent things, in order of when they fire:

1. **Layers 0 and 1** — a refused prompt never reaches a paid call.
2. **Rate limiter** — 5 free generations per session (20 logged in), per-IP ceiling 30/day,
   minimum 3 s between requests, global concurrency cap.
3. **Budget guard** — daily and monthly USD ceilings, checked *before* every paid call by
   summing `cost_usd`. On breach: generation disabled site-wide, clear customer message, admin
   emailed.
4. **Cost recording is list price**, deliberately. Neither API says whether a call was billed,
   and over-recording is the safe direction for a spend guard.

---

## What is local PHP

Everything below runs on the WordPress host. No external service, no Node, no Redis, no
Composer at runtime. This is constraint #1 from `CLAUDE.md`, and it is why the plugin can ship
to a managed host.

| Concern | Where |
|---|---|
| Lithuanian normalisation + stemming | `Moderation/LtNormaliser.php` |
| Blocklist matching | `Moderation/Blocklist.php` |
| Job queue, atomic claim, state machine | `Domain/Job*.php`, `Queue/` |
| Dispatch (loopback socket / poll / AS sweep) | `Queue/Dispatcher.php`, `Scheduler.php` |
| Print geometry, mm ↔ px | `Support/Mm.php` |
| Imposition (N-up, derived not typed) | `Imaging/SheetLayout.php` |
| Masking, cover/crop, flatten, PNG/WebP, DPI | `Imaging/GdEngine.php` |
| **Upscaling** | `Providers/Upscale/GdUpscaler.php` — bicubic, free |
| Text: straight, outlined, auto-fit, wrapped, arc | `Imaging/TextRenderer.php` |
| TrueType cmap reading, LT coverage gate | `Imaging/TtfCmap.php`, `FontCatalogue.php` |
| Watermarking | `Imaging/Watermarker.php` |
| Storage, two zones, containment checks | `Storage/PrivateStorage.php`, `OrderArchive.php` |
| Rate limiting, identity, budget | `Throttle/` |

**The upscaler is worth calling out.** `PLAN.md` §8.6 budgeted $0.005 per order for
Real-ESRGAN. We use GD bicubic instead: free, adequate at these sizes, and it was always the
production fallback anyway (D-015). One less external dependency and one less thing to fail
after the customer has paid.

---

## The two storage zones

Opposite lifecycles, and the cleanup cron must never confuse them (§12.2, §12.5).

| | `sessions/` | `orders/` |
|---|---|---|
| Holds | every generation, bought or not | what a customer paid for |
| Files | delete after 30 days | **never auto-deleted** |
| DB row | keep prompt + verdict, null the paths | kept indefinitely |
| Hard delete | 12 months, or GDPR erasure | GDPR erasure or manual admin action only |

Both live **outside the webroot** (`AICAKE_STORAGE_DIR`). Nothing is in the Media Library.
The master and the print file are **never servable** under any URL — only the watermarked
preview is, and only to its owner.

> **Ownership matters more than it looks.** The dated directories are created by whoever writes
> first. Create them as root and PHP-as-web-user cannot write into them — the zone root looks
> fine and every write fails. This has now bitten both zones (D-003, D-031). Site Health probes
> both with a real write; `find $AICAKE_STORAGE_DIR -uid 0` should return nothing.

---

## Why it is all asynchronous

Constraint #2 from `CLAUDE.md`: **the PHP worker pool is the scarce resource.** Shared hosting
runs 4–8 workers for the whole site. A generation takes 5–15 seconds. Six customers clicking
Generate on a synchronous endpoint would take down the shop — not slow it, take it down,
because the checkout and the front page need those same workers.

So `POST /generate` returns **202 in ~209 ms** and the work happens elsewhere. "Elsewhere" has
three routes, because plain WordPress has no worker:

1. **Loopback socket spawn** — fire-and-forget request to ourselves. Fastest when it works.
2. **Poll-triggered execution** — a polling request that finds the job still queued runs it
   itself. This is the fallback when loopback is blocked, which is common on cheap hosts.
3. **Action Scheduler sweep** — catches anything both of the above missed. Ignores the
   concurrency cap, so a stuck queue can always recover.

All three can arrive at the same job, which is exactly why the claim is atomic and why only one
may execute it. Both dispatch layers are verified working, including with loopback deliberately
blocked.

---

## Not built yet

**Phase 8 — operations.** Review queue (layer 3, non-negotiable), print queue, cost dashboard,
cleanup cron, emails. See `PLAN.md` §14 and §12.5.

**Phase 9 — hardening and ship.** i18n pass and `.pot`, privacy hooks, uninstall, security
review, a load test with loopback disabled and a low memory limit, then production deploy.
