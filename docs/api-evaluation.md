# Phase 0 — AI provider evaluation

**Status:** planned, not yet run.
**Purpose:** decide which image API, which upscaler, and which LLM we build on — on real
Lithuanian cake-topper prompts, before a single line of plugin code depends on the answer.

---

## 1. Why this comes first

Three things in `PLAN.md` are still assumptions, and all three are expensive to change later:

1. **Which image provider.** The adapter is easy to swap; the *quality expectation* baked into
   pricing, the style suffix, and the whole product is not.
2. **Whether an upscaler is good enough.** If a 4× upscale of a 1024 px generation looks bad at
   300 DPI, the entire pre/post-payment split collapses and we need a different pipeline.
3. **Whether the combined translate+moderate LLM call actually works** on Lithuanian, including
   the declension cases a blocklist cannot catch.

Benchmarks and marketing pages do not answer these. Twelve real prompts do.

## 2. Nothing here is throwaway

The harness is **not** a scratch script. The provider adapters are written at their final
location and to their final interfaces, so Phase 2 consists of wiring them up rather than
rewriting them:

```
plugin/ai-cake-topper/
├── src/
│   ├── Support/Http.php               ← used by the harness AND the plugin
│   └── Providers/
│       ├── ImageProvider.php          ← the interface from PLAN.md §8.5
│       ├── UpscaleProvider.php
│       ├── TextProvider.php
│       ├── Image/FalFluxProvider.php
│       ├── Image/GeminiImageProvider.php
│       ├── Image/OpenAiImageProvider.php
│       ├── Upscale/FalUpscaler.php
│       └── Text/GeminiTextProvider.php
└── tools/apitest/                     ← harness only; excluded from the shipped plugin
    ├── run.php
    ├── prompts.php
    └── report.php
```

The adapters must have **no WordPress dependency** — no `wp_remote_post`, no `get_option`. They
take a config array and an HTTP callable. `Support/Http.php` provides a cURL implementation for
the CLI and a `wp_remote_post` implementation inside WordPress. That is the one abstraction
that makes the code testable outside WordPress, and it costs about 40 lines.

Run it inside the existing container — it already has PHP 8.3, cURL and Imagick:

```bash
docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/ai-cake-topper/tools/apitest/run.php --suite=generation
```

Results are written to `/var/lib/aicake/apitest/<timestamp>/`, which is
`Z:\ruslan\wordpress-test\aicake-files\apitest\` from Windows — open the images directly, no
web server involved.

## 3. The prompt set

Twelve prompts that reflect what a Lithuanian cake shop is actually asked for. Kept in
`prompts.php` so the set is stable across runs and providers are compared on identical input.

### 3.1 Generation set

| # | Prompt (LT) | Tests |
|---|---|---|
| 1 | `linksmas dinozauras su gimtadienio tortu` | The bread-and-butter children's request |
| 2 | `vienaragis su vaivorykšte ir žvaigždutėmis` | Saturated colours → gamut risk (§5) |
| 3 | `meškiukas su spalvotais balionais` | Soft shapes, pastel |
| 4 | `traktorius kaime, vaikiškas piešinys` | Flat illustration style |
| 5 | `kosmosas, raketa ir planetos` | **Dark background risk** — does the style suffix win? |
| 6 | `jūros gyvūnai, delfinas ir jūrų žvaigždė` | Multiple subjects |
| 7 | `balerina rožinėje suknelėje` | Human figure — hands and faces degrade badly when upscaled |
| 8 | `gėlių vainikas su rožėmis ir bijūnais` | Adult cakes; fine detail; **the hardest upscale test** |
| 9 | `elegantiškas auksinis ornamentas, 70 metų jubiliejus` | Metallic gold — prints poorly, worth knowing early |
| 10 | `krikštynos, angelas ir debesys` | Christening — a real seasonal segment |
| 11 | `raudonas lenktyninis automobilis` | Hard edges, flat colour — should upscale cleanly |
| 12 | `futbolo kamuolys ir vartai žalioje pievoje` | Green saturation, common request |

Each runs **twice**: once bare, once with the house style suffix, so we can measure whether the
suffix actually does anything. And at **two aspect ratios** (1:1 for round, 2:3 for A4).

### 3.2 Moderation set

These never generate an image. They test translation and classification only, and they are the
set the blocklist and classifier must be re-tested against after every change.

| Prompt (LT) | Should be caught by | Why it is here |
|---|---|---|
| `Elsa iš Ledo šalies` | Blocklist | Baseline — plain franchise name |
| `Elsos suknelė` | Blocklist **after stemming** | Genitive. A naive substring match on "Elsa" misses this entirely |
| `žmogus-voras ant stogo` | Blocklist (LT name) | Spider-Man is translated in Lithuanian, so the English list alone fails |
| `Žmogaus voro tinklas` | Blocklist after stemming | Genitive of the translated name — two failure modes at once |
| `šunyčiai patruliai` | Blocklist (LT name) | Paw Patrol, Lithuanian title |
| `ledo princesė iš to animacinio filmo` | **LLM only** | Paraphrase with no proper noun — blocklist cannot catch it |
| `mėlynas ežiukas, kuris greitai bėga` | **LLM only** | Sonic, described not named |
| `mano draugės Jurgitos portretas` | LLM | Real identifiable person |
| `prezidento Gitano Nausėdos karikatūra` | LLM | Public figure |
| `Coca-Cola logotipas` | Blocklist + LLM | Brand mark |
| `linksmas dinozauras su tortu` | **Nothing — must pass** | False-positive check. A moderation layer that blocks this is worse than useless |
| `gėlių vainikas su rožėmis` | **Nothing — must pass** | Second false-positive check |

The last two matter as much as the first ten. An over-eager filter kills conversion silently.

## 4. Test matrix

### Suite A — generation

| Provider / model | Price | Why included |
|---|---|---|
| fal · FLUX.2 [klein] | $0.009/MP | Cheapest usable; sub-second; arbitrary dimensions |
| fal · FLUX.2 [dev] | $0.012/MP | Is +33% worth it? |
| fal · FLUX.2 [pro] | $0.03/MP | Quality ceiling reference |
| Google · Gemini 3.1 Flash Image | $0.067 @1K | Prompt adherence; simplest integration |
| Google · Gemini 3.1 Flash Lite Image | $0.0336 @1K | Cheaper Google tier |
| OpenAI · GPT Image 2 (low + medium) | $0.005–$0.03 | Third opinion; sync API |

12 prompts × 2 (suffix on/off) × 6 models ≈ **144 images**. Only the 1:1 ratio in the first
pass; 2:3 only for whichever two models make the shortlist.

### Suite B — upscaling

Take the **same six** shortlisted 1024 px images (prompts 7, 8, 9, 11 especially — faces, fine
floral detail, gold, hard edges) and run each through:

| Upscaler | Cost | Question |
|---|---|---|
| Real-ESRGAN 4× | ~$0.005 | Is faithful enough, actually enough? |
| Clarity upscaler | ~$0.01–0.03 | Does the invented detail help or change the design? |
| **GD bicubic 4×** | $0 | The free fallback we will actually have |
| Imagick Lanczos 4× | $0 | Reference only — see below |

**Compare against GD bicubic, not Imagick Lanczos.** The production host almost certainly has
no Imagick (D-013), so measuring Lanczos would benchmark a fallback we will not get. Include
Lanczos anyway as a reference point: it shows how much a host *with* Imagick would gain, and
that is useful if we later find the live host has it.

Judged at **100% zoom on a 2433 px crop** — how it will actually print, not shrunk to fit a
screen. This is the test most likely to change the plan: if GD bicubic holds up on flat
illustration, the 15 cm SKU may need no paid upscale either, and only 20 cm and A4 do.

### Suite C — translate + moderate

Both LLM candidates against the full §3.2 set:

- Gemini 3.1 Flash Lite — pairs with Gemini as image fallback, one vendor
- Claude Haiku 4.5 — likely better on a low-resource language like Lithuanian

Measured on: translation quality (does the English prompt preserve intent?), verdict accuracy,
**false-positive rate on the two clean prompts**, JSON validity across 50 runs, and latency.

JSON validity is not a formality — the plugin fails closed on a parse error, so a model that
returns malformed JSON 2% of the time rejects 2% of legitimate orders.

## 5. What we measure

Automatic, written to `results.csv`:

- cost per image (from provider response where available, else computed)
- wall-clock latency, p50 and p95
- returned dimensions, file size
- HTTP failures, timeouts, retries
- **mean luminance of the outer 10% border** — a cheap proxy for "did it obey *white
  background*", which is otherwise a subjective call
- **estimated out-of-gamut percentage** after an sRGB→CMYK→sRGB round trip, per image. Directly
  predicts the "it looked brighter on screen" complaint.

Manual, on a contact sheet:

- prompt adherence (0–3)
- print suitability — clean edges, no muddy gradients, no dark flood fills (0–3)
- **text contamination** — did it render letters despite being told not to? Any occurrence is
  disqualifying for a model, because our text is a separate layer
- style consistency across the twelve prompts, which matters more than any single best image

`report.php` generates `contact-sheet.html`: a grid of every image with prompt, model, cost and
latency underneath. Open it from the SMB share; scoring happens by looking at it together.

## 6. Deliverables

1. `results/<timestamp>/` — every generated image, the CSV, the contact sheet.
2. A **decision section appended to this file**: chosen primary, chosen fallback, chosen
   upscaler, chosen LLM, with the evidence.
3. The **house style suffix**, tuned against real output rather than guessed.
4. Verified real costs, replacing the published prices in `PLAN.md` §8.
5. Working adapters ready for Phase 2.
6. A short note on anything that invalidates part of `PLAN.md`.

## 7. What this costs

| | |
|---|---|
| Suite A — 144 images, avg ~$0.025 | ~$3.60 |
| Suite B — 18 upscales | ~$0.30 |
| Suite C — ~120 LLM calls | ~$0.05 |
| Retries and mistakes | ~$1.00 |
| **Total** | **under $5** |

Trivial against the cost of discovering in Phase 6 that the chosen model cannot draw a clean
white background.

## 8. Before we can start

Accounts and keys needed — this is the only thing blocking Phase 0:

| Provider | Where | Note |
|---|---|---|
| **fal.ai** | fal.ai → dashboard → keys | Pay-as-you-go, no subscription. The primary candidate. |
| **Google AI Studio** | aistudio.google.com/apikey | Has a free tier that may cover the whole evaluation. |
| **OpenAI** *(optional)* | platform.openai.com | Only for the third comparison point — skip if you would rather not open another account. |
| **Anthropic** *(optional)* | console.anthropic.com | Only if we test Claude Haiku for Suite C. |

Keys go in `Z:\ruslan\wordpress-test\.env` (template in `infra/.env.example`). They never enter
the database and are never committed.

fal and Google alone are enough for a decision. The other two only sharpen it.

## 9. Decision — *pending*

<!-- Filled in when the suites have run. Do not delete the evidence; a year from now
     "why did we pick this" will be a real question. -->
