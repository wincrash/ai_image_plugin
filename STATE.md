# Project state

**Updated:** 2026-08-02
**Phase:** 0 — API evaluation (planned, blocked on API keys)

> Read `WORKFLOW.md` for how we work, `PLAN.md` for the design, `DECISIONS.md` for why.

---

## Where we are

Planning is done. No plugin code exists yet. The next concrete step is Phase 0: evaluate the
image APIs on real Lithuanian prompts before building anything that depends on the choice.

| Phase | Status |
|---|---|
| 0 · API evaluation | **Planned.** Blocked on fal.ai + Google AI Studio keys. |
| 1 · Foundation | Not started |
| 2–9 | Not started |

## Blocked on

1. **API keys** — fal.ai and Google AI Studio, into `Z:\ruslan\wordpress-test\.env`.
   Nothing in Phase 0 can run without them. (`docs/api-evaluation.md` §8)
2. **Testbed compose update** — the new `docker-compose.yaml` is written but not yet applied.
   Until it is, there is no plugin mount, no storage mount, and PHP is capped at 128 MB.

## Environment — verified 2026-08-02

| | |
|---|---|
| Testbed URL | `http://100.127.55.45:8080` (also `localhost:8080` on the server) |
| Reachable from Claude Code | Yes — HTTP responds; SMB share readable **and writable** |
| SMB share | `Z:\ruslan\wordpress-test` → `~/wordpress-test` on `ruslan-server` |
| WordPress | 7.0.2 |
| WooCommerce | 10.9.4 |
| Theme | Blocksy 2.1.49 + `valgomos` child |
| Cart / checkout | **Classic shortcode**, not blocks (`/krepselis/`) |
| PHP memory limit | **128 MB — too low**, fixed by the new compose (512 MB) |
| Imagick present? | **Unverified.** Dockerfile installs it if absent. |
| Containers | `wordpress-test-wordpress-1`, `wordpress-test-db-1` |
| DB | `wp_user` / `wp_password` / `wordpress` |
| Other plugins | WooPayments, PayPal, MailPoet, Unisend, Jetpack, Pinterest, Google Listings & Ads, WooCommerce POS |
| Live site | valgomosdekoracijos.lt — ~265 products, plain WordPress, no extra services |

Note: the separate **theme** project also lives on this share (`Z:\...\themes\`) with its own
`CLAUDE.md`. This project does not touch it.

## Repository layout

```
C:\AI_IMAGE\
├── PLAN.md                  the design (23 sections)
├── WORKFLOW.md              how we work — read after a session reset
├── STATE.md                 this file
├── DECISIONS.md             append-only decision log
├── idea.md                  original brief, superseded by PLAN.md
├── docs\
│   └── api-evaluation.md    Phase 0 plan
├── infra\                   testbed Docker config (deploy with tools\sync.ps1 -InfraToo)
│   ├── docker-compose.yaml  Dockerfile  .env.example
│   ├── php\zz-aicake.ini    mu-plugins\00-dev-mail.php
├── tools\sync.ps1           C:\AI_IMAGE  ->  Z:\
└── plugin\                  (empty — created in Phase 1)
```

## Next actions

1. Apply the new compose to the testbed:
   ```powershell
   powershell -File C:\AI_IMAGE\tools\sync.ps1 -InfraToo
   ```
   then on the server: `cd ~/wordpress-test && docker compose up -d --build`
2. Verify the result: Imagick present, `memory_limit` 512M, site still up, Mailpit on :8025.
3. Get fal.ai + Google AI Studio keys into `.env`.
4. Build the Phase 0 harness (`docs/api-evaluation.md` §2) and run Suites A–C.
5. Record the outcome in `docs/api-evaluation.md` §9 and `DECISIONS.md`.

## Open items, not blocking

- **No git remote yet.** Everything lives on one Windows machine. Worth adding a private
  GitHub remote once there is real code.
- Cupcake diameter assumed 4.5 cm → 24 per A4. Confirm against what is actually sold; 5 cm
  yields 20 and the SKU name must match.
- Printer make/model unknown → usable print area defaults to 200 × 287 mm, adjustable.
- Icing sheet is slightly shorter than A4; exact dimensions to be corrected late (client's call).
- VMVT food-business registration — regulatory, gates launch not development.
