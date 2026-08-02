# CLAUDE.md

Guidance for Claude Code working in this repository.

## Read these first, in this order

| File | What it gives you |
|---|---|
| `STATE.md` | **Where the project actually is.** Current phase, blockers, next actions, verified environment facts. |
| `WORKFLOW.md` | How we work — environments, the sync loop, conventions, commands. |
| `PLAN.md` | The design. 23 sections, authoritative. |
| `DECISIONS.md` | Why things are the way they are. Append-only. |
| `docs/api-evaluation.md` | Phase 0 plan. |

`idea.md` is the original brief. It is **superseded** — `PLAN.md` §23 lists where it is wrong.
Do not follow it.

## What this project is

A WooCommerce plugin for valgomosdekoracijos.lt, a Lithuanian shop selling edible cake
decorations. Customers describe an image in Lithuanian, an AI generates it, they buy it, and it
is printed on edible icing sheets. Customer-facing UI and prompts are Lithuanian.

## The two constraints that decide most arguments

1. **Production is plain WordPress with no additional services.** No Redis, no Node worker, no
   external render service, no message broker, no Composer at runtime. PHP + MySQL + what
   WooCommerce already ships (Action Scheduler). If a design needs more than that, it is the
   wrong design.
2. **The PHP worker pool is the scarce resource.** Shared hosting runs 4–8 workers for the whole
   site. Nothing customer-facing may block a worker for seconds at a time.

## Where things live

- **Source of truth:** `C:\AI_IMAGE\` (git). All editing happens here.
- **Testbed:** `Z:\ruslan\wordpress-test\` — SMB share of the Docker host. Deployment target
  only; never edit the plugin there, the next sync overwrites it.
- Deploy: `powershell -File C:\AI_IMAGE\tools\sync.ps1`

`Z:\ruslan\wordpress-test\themes\` is a **different project** (the site's theme, with its own
`CLAUDE.md`). This project does not touch it.

## Saving state — required

Sessions get reset and this project is long. Nothing important may exist only in chat.

- A decision made → append to `DECISIONS.md`.
- A design change → update `PLAN.md`.
- Progress, or a new environment fact → update `STATE.md`.
- Then commit. Small and often, messages that say *why*.

Never rewrite git history.

## Conventions

- WordPress Coding Standards. Tabs. Namespaced classes under `AiCake\`, hand-rolled SPL
  autoloader, no Composer at runtime.
- Prefix everything: `aicake_`, `AICAKE_`, `AiCake\`.
- Every user-facing string in `__()` with the `ai-cake-topper` text domain, from the first line
  written.
- Escape on output, sanitise on input, `$wpdb->prepare()` always.
- API keys come from constants, never `wp_options`.
- Comments explain *why*. The code already says what.
