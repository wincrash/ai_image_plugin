# AI Edible Cake Topper — WooCommerce plugin

A WooCommerce plugin for **valgomosdekoracijos.lt**, a Lithuanian shop selling edible cake
decorations. A customer describes an image in Lithuanian, an AI generates it, they buy it, and
it is printed on an edible icing sheet.

**Status:** planning complete, no plugin code yet. Phase 0 (AI provider evaluation) is next.

## Start here

| File | What it is |
|---|---|
| [STATE.md](STATE.md) | **Where the project actually is** — phase, blockers, next actions |
| [WORKFLOW.md](WORKFLOW.md) | Environments, the deploy loop, conventions, commands |
| [PLAN.md](PLAN.md) | The design. Authoritative. |
| [DECISIONS.md](DECISIONS.md) | Why things are the way they are. Append-only. |
| [docs/api-evaluation.md](docs/api-evaluation.md) | Phase 0 plan |
| [infra/README.md](infra/README.md) | Testbed Docker setup |

`idea.md` is the original brief and is **superseded** — `PLAN.md` §23 lists where it is wrong.

## How it works

```
Customer types a prompt in Lithuanian
   |  translate + moderate in one LLM call
   |  generate ~1 MP preview, watermarked, in the product's real shape
   |  optional name/greeting composited server-side with a real font
Add to cart -> pay
   |  (background, post-payment only)
   |  upscale only if the SKU needs it
   |  text at print resolution, shape + bleed, N-up imposition
   |  300 DPI PNG -> manual approval -> print
```

The split at the payment boundary is the central design decision: it keeps abandoned previews
cheap (~€0.01 each) and means the unwatermarked print file only exists after payment.

## Constraints that shape everything

1. **Production is plain WordPress, no additional services.** No Redis, no Node worker, no
   external render service, no Composer at runtime. PHP + MySQL + Action Scheduler.
2. **Shared hosting runs 4–8 PHP workers for the whole site**, so nothing customer-facing may
   block a worker for seconds. Generation is always async with polling.
3. **No PHP extensions can be installed on the live host.** GD is the target image engine;
   Imagick is an optional enhancement where present.

## Layout

```
PLAN.md  WORKFLOW.md  STATE.md  DECISIONS.md  CLAUDE.md
docs/        design deep-dives
infra/       testbed Docker config
tools/       sync.ps1 - deploy to the testbed
plugin/      the plugin itself (Phase 1)
```
