# Decision log

Append-only. Newest at the bottom. Each entry: what was decided, when, why, and what was
rejected. If a decision is later reversed, add a **new** entry that supersedes it rather than
editing the old one — the reasoning that turned out to be wrong is worth keeping.

---

### D-001 · Split the pipeline at the payment boundary
**2026-08-02** · from `idea.md`, retained

Cheap watermarked preview before payment; full-resolution print file only after.

**Why:** a visitor generating twelve previews and leaving costs ~€0.05 rather than twelve
upscales; the unwatermarked print file never exists before money changes hands.
**Rejected:** generating at print resolution up front — pays full price for every abandoned
preview, and fast models degrade when asked for high resolution directly.

---

### D-002 · One design × N copies for multi-up sheets
**2026-08-02** · client decision

A cupcake sheet is one generated image tiled N times, not N different images.

**Why:** matches how cupcake toppers are actually bought, keeps one design record per order
line, avoids a sheet-builder UI.
**Rejected:** up to 24 individual designs — 24× the generation cost and a much larger UI, for a
case that is rare.

---

### D-003 · Text is a server-side layer, not part of the generated image
**2026-08-02** · client decision

The customer types the name/greeting in a separate field. It is composited with a real font at
print resolution. The AI is instructed to produce no text.

**Why:** Lithuanian diacritics (`ė ū š ž č ą ę į ų`) are rendered badly or wrongly by image
models, and a misspelled name on a printed cake cannot be fixed. Also gives exact control over
font, colour and placement, and each retry is free.
**Consequence:** text-rendering ability drops out of the model-selection criteria entirely,
which widens the field considerably.
**Watch out:** most decorative fonts lack `ė` and `ū`. Bundled fonts must be verified
glyph-by-glyph by an automated test.

---

### D-004 · No customer photo upload in v1
**2026-08-02** · client decision

**Why:** a second and harder moderation problem (real people, logos), and the client wants to
see v1 perform first. Not designed for — revisit only if v1 succeeds.

---

### D-005 · Size and count are products; material is a variation
**2026-08-02** · client decision, elaborated

Each diameter/count is its own WooCommerce product. Variations are reserved for material
(icing sheet vs wafer paper), which does not change geometry.

**Why:** the print geometry is then known at page load, which removes the worst UX problem in
the earlier design — a customer generating a square design and then switching to A4. Also gives
each size its own indexable URL.
**Rejected:** one variable product with sizes as variations — created an aspect-ratio
invalidation problem needing confirm dialogs and free regenerations.
**Cost:** more products to maintain.

---

### D-006 · Always async, never a blocking generate request
**2026-08-02**

The frontend always submits a job and polls, even when the provider is sub-second.

**Why:** production is shared WordPress hosting with a 4–8 PHP worker pool for the whole site;
a 10-second blocking request means a handful of concurrent customers take the storefront down.
Also makes queue-based (fal, Replicate) and synchronous (Google, OpenAI) providers
interchangeable, and retrofitting polling later is expensive.
**Rejected:** synchronous POST returning the finished image — simpler, but unsafe on the
target hosting and would need rewriting on the first provider change.

---

### D-007 · Upscaling is conditional, not automatic
**2026-08-02** · corrects `idea.md`

Compute the required pixel size from the product's print spec; upscale only if the native
generation is short.

**Why:** a 4.5 cm cupcake circle needs 603 px at 300 DPI. A native 1024 px generation already
exceeds it by 70%. `idea.md` would have paid for an upscale on every order including the
highest-volume SKU.

---

### D-008 · Images are not WordPress attachments
**2026-08-02** · corrects `idea.md`

Files live in our own directory tree, tracked by our own table.

**Why:** every abandoned preview would otherwise become a `wp_posts` row plus metadata plus
generated thumbnails, making the Media Library unusable within weeks — and attachments are
public URLs by design, the opposite of the requirement.

---

### D-009 · Print files are PNG only
**2026-08-02** · client decision

**Why:** the client prints from PNG. Dropping PDF removes the last hard Imagick dependency
from the output path and makes the GD fallback genuinely complete.

---

### D-010 · Order files are permanent, human-readable, and outside the webroot
**2026-08-02** · client decision

Two storage zones: `sessions/` (hashed, auto-cleaned after 30 days) and `orders/` (organised by
order number, never auto-deleted). Root is a configurable constant, ideally outside the webroot.

**Why:** the client needs to open an order's image directly on the server, and to reprint weeks
later when a customer reorders. Putting the root outside the webroot is what makes readable
filenames safe.
**Consequence:** reorder and reprint become first-class v1 features. A `.json` sidecar per
order item records prompt, seed, model and print spec, so a reprint is reproducible even
without the database.

---

### D-011 · Testbed compose gains plugin mount, storage mount, and 512 MB
**2026-08-02**

**Why:** the original compose mounts only `./themes`, so plugin development is impossible; PHP
is capped at 128 MB, which fatals on A4 images at 300 DPI (and already made
`php -r 'require "wp-load.php"'` unusable); and there was nowhere for generated files to land
that the client could open from Windows.
**Also added:** version pinning (7.0.2-php8.3-apache), a Dockerfile guaranteeing Imagick,
WP-CLI, Mailpit, debug logging, and `.env`-based API keys.

---

### D-012 · Provider adapters are written to their final shape during Phase 0
**2026-08-02**

The API evaluation harness uses the real `ImageProvider` / `UpscaleProvider` / `TextProvider`
interfaces at their final paths, with no WordPress dependency.

**Why:** the evaluation has to write this code anyway. Writing it as throwaway means writing it
twice, and the second version would not be the one that was actually tested.

---

### D-013 · GD is the target image engine; Imagick is an optional enhancement
**2026-08-02** · client constraint

The client cannot install PHP extensions on the production host, so Imagick cannot be assumed.
Every feature must be complete and good-looking on GD. `AICAKE_FORCE_GD` defaults **on** in the
testbed, so development happens on the production path even though the testbed has Imagick.

**Why the inversion matters:** treating GD as a "fallback" leads to building against Imagick and
discovering the gap at go-live. Treating it as the platform means the gap never exists.

**What survives on GD:** everything except true ICC soft-proofing. Arc text is achievable by
placing characters individually along the arc with per-character rotation, rather than warping
a rendered strip. Circle masking is fast if done as per-row span fills plus an anti-aliased
annulus, instead of a 5.9 M-iteration per-pixel loop. PNG DPI metadata needs a hand-written
`pHYs` chunk.

**What is actually lost:** ICC CMYK soft-proofing (was already v1.5 and off by default —
replaced by a calibrated LUT approximation), and Lanczos resampling.

**Knock-on:** losing Lanczos weakens the *free* local upscaler, which raises the value of paid
Real-ESRGAN for the large SKUs. Phase 0 Suite B must therefore compare against **GD bicubic**,
not Imagick Lanczos, or it measures a fallback we will not have.

**Still to confirm:** whether the live host already ships Imagick. Most WordPress hosts do. If
it does, it is a free quality upgrade — but nothing may depend on it.

---

### D-014 · GitHub remote, pushed via the server
**2026-08-02**

Remote is `github.com/wincrash/ai_image_plugin` (private). The client's Linux server has `gh`
authenticated; the Windows machine does not, and its SSH key is not registered with GitHub.

The initial push therefore went Windows → `git bundle` → server → GitHub, merging the repo's
stub `init` commit with `--allow-unrelated-histories` rather than force-overwriting it, then
returning the merge commit to Windows via a reverse bundle so all three stay in sync.

**This is a bootstrap, not the workflow.** Adding the Windows public key
(`~/.ssh/id_rsa.pub`, `rpace@ruslan-pc`) to the GitHub account makes `git push` work directly
and retires the bundle dance.

---

### D-015 · No Imagick on production — confirmed, and no external render server needed
**2026-08-02** · confirms D-013

Live-host Site Health reports `WP_Image_Editor_GD`, ImageMagick `none`, Imagick `none`, GD
`bundled (2.1.0 compatible)`, formats `GIF, JPEG, PNG, WebP, BMP`, Ghostscript not detected.

The host is a **managed platform, not a Linux machine**: the client can add PHP libraries and
WordPress plugins, but no system packages. D-013 is therefore settled fact rather than caution.

**Also decided: we do not build an external render service.** The client raised it as a fallback.
It is not needed — GD plus pure PHP plus the AI APIs we already call cover the whole pipeline.
The only two Imagick features that mattered are displaced: Lanczos by the paid Real-ESRGAN
upscaler (an external service we were always using), and ICC soft-proofing by a calibrated LUT.

**Rejected:** running a small external server with an image API. It would add recurring cost,
ops burden, a second attack surface, and a single point of failure between the storefront and a
paying customer — to replace two features we no longer need.

**One unknown remains:** GD's **FreeType** support, which Site Health does not report and which
the entire text layer depends on. `tools/host-check.php` verifies it, and renders
`ĄČĘĖĮŠŲŪŽ ąčęėįšųūž` to prove diacritics actually come out. If FreeType were missing — unlikely
on a managed WordPress host — that alone would justify revisiting the architecture.

---

### D-016 · Windows pushes to GitHub directly
**2026-08-02** · supersedes the bootstrap in D-014

The Windows SSH key is registered with GitHub; `git push origin main` works from `C:\AI_IMAGE`.
The bundle-through-the-server route from D-014 is retired and should not be used again.

---

### D-017 · Replicate serves some models with no credit — development only
**2026-08-02**

Probed every provider directly rather than trusting published free-tier claims. Results:

| Provider | Verdict |
|---|---|
| fal.ai | `403 User is locked. Reason: Exhausted balance.` No trial credit. |
| Google — text | **Free and working.** `gemini-3.1-flash-lite` translated a Lithuanian test prompt correctly. |
| Google — image | `429 … free_tier_requests, limit: 0`. Image generation is explicitly zero on the free tier. |
| Replicate | **Some models run with no credit at all.** |

Replicate's free set, mapped by sending empty input and reading the status code
(`402` = billing-blocked, `422` = allowed but invalid input):

| Free | Needs credit |
|---|---|
| `black-forest-labs/flux-dev` | `flux-schnell`, `flux-2-dev`, `flux-1.1-pro-ultra` |
| `black-forest-labs/flux-1.1-pro` | `google/imagen-4-fast`, `nano-banana`, `nano-banana-2` |
| `black-forest-labs/flux-2-pro` | `nightmareai/real-esrgan`, `recraft-ai/recraft-v3` |

`flux-dev` was confirmed by an actual generation, not just a status code — 1024×1024 in 1.4 s.

**The split follows no pattern we can rely on.** The cheapest model (`flux-schnell`) is blocked
while the top tier (`flux-2-pro`) is free. This is undocumented behaviour that can change without
notice, and the rate limit is explicitly *reduced* for accounts without credit (~6 predictions
per minute observed).

**Therefore: free Replicate access is a development convenience, never a production dependency.**
Production runs on a funded account. Anything built against it must survive the free access
disappearing mid-request, which the provider registry's fallback chain (§8.5) already covers.

**Also noted:** there is **no free upscaler** — `real-esrgan` is blocked. Development therefore
uses GD bicubic, which is the production fallback anyway (D-013/D-015), so the free path and the
worst-case production path happen to be the same code. That is a lucky alignment, not a plan.

---

### D-018 · Build the plugin first; the provider decision is deferred
**2026-08-02** · re-sequences `docs/api-evaluation.md`

Phase 0 was designed to run before any code, so nothing would be built on the wrong assumptions.
Ruslan's call is to invert that: **build the real plugin against free models, then swap models,
tune prompts and judge quality later.** Quality is explicitly not the current goal.

This is sound because the provider abstraction (§8.5) already exists to make a swap a settings
change, and because Phase 1 — skeleton, autoloader, tables, settings, capability detection, Site
Health, logger, rate limiter, budget guard — contains nothing provider-specific. §22 already
records that nothing blocks Phase 1.

The free stack that makes it possible, all three confirmed working today at zero cost:

| Layer | Development provider |
|---|---|
| Image generation | Replicate `black-forest-labs/flux-dev` |
| Translate + moderate | Google `gemini-3.1-flash-lite` |
| Upscale | GD bicubic in PHP — no external call |

**The one thing not to defer indefinitely:** §3.1 makes the pre/post-payment split depend on a
1024 px generation surviving an upscale to 300 DPI. If it does not, the pipeline shape changes,
and pipeline shape is the expensive thing to change — not the model. Since development uses GD
bicubic, we will find this out on the *worst* available upscaler, which is the honest test. If GD
bicubic holds, the paid upscaler is an upgrade rather than a dependency.

Phase 0 is not cancelled. It becomes a calibration step once the plugin runs end to end, at which
point it is also cheaper — the harness is the plugin, and the "Test provider" screen from §8.5
is how the comparison actually gets made.

---

### D-019 · The LLM handles Lithuanian inflection — the moderation design holds
**2026-08-02** · validates `PLAN.md` §10 Layer 2

The open risk in the moderation design was never the blocklist; it was whether a cheap model
could judge **Lithuanian**, where a franchise name declines through seven cases and the
characters have translated names. Tested `gemini-3.1-flash-lite` against the §3.2 set:

| Prompt | Wanted | Got | Why it is hard |
|---|---|---|---|
| `linksmas dinozauras su gimtadienio tortu` | allow | **allow** | False-positive check — blocking this would be worse than useless |
| `gėlių vainikas su rožėmis` | allow | **allow** | Second false-positive check |
| `Elsos suknelė` | block | **block** `copyright_character:frozen` | Genitive; a substring match on "Elsa" misses it |
| `Žmogaus voro tinklas` | block | **block** `franchise:spiderman` | Genitive *of the Lithuanian name* — two failure modes at once |
| `mėlynas ežiukas, kuris greitai bėga` | block | **block** `franchise:sonic` | Sonic described, never named — no blocklist can catch this |
| `prezidento Gitano Nausėdos karikatūra` | block | **block** `real_person:named` | Real public figure |

Six of six, ~790 ms each, **$0.000639 for all six**. Translations were clean
("Spider-Man web", "caricature of President Gitanas Nausėda").

**Decided: keep the §10 design as written.** Blocklist first for the cheap obvious cases, LLM for
everything else, three verdicts. No fallback classifier is needed for Lithuanian specifically,
which was the open question.

**Also decided: JSON validity is enforced, not measured.** `PLAN.md` §4 planned to measure how
often the model returns malformed JSON, because the plugin fails closed and a 2% malformed rate
rejects 2% of legitimate orders. Gemini's `responseSchema` makes the API enforce the shape
instead, which removes the failure mode rather than quantifying it. That measurement is dropped
from Phase 0 as no longer meaningful.

**Style suffix must be phrased positively.** Confirmed twice. The negative form
("no cake or background needed") produced exactly a cake, photorealistic and dark. The positive
form — flat vector illustration, thick clean outlines, isolated on a plain solid white
background — produced precisely the product. The working suffix now lives in
`TestProviderPage::apply_style_suffix()` and is a setting, not a constant.

Two things to tune later, neither blocking: the model adds a **soft drop shadow**, which would
print as a grey smudge on a cut-out topper, and the subject is not reliably centred.

---

### D-020 · Replicate's free set is not even stable — reinforcing D-017
**2026-08-02** · strengthens D-017

While testing the fallback chain, `black-forest-labs/flux-schnell` returned
`404 No adapter found for model` where hours earlier the same call returned
`402 Insufficient credit`. The response changed with no action on our side.

This is a second, independent reason free Replicate access can never be a production dependency:
not only is the free set arbitrary, it is not stable within a single day. The provider registry's
fallback chain is what makes this survivable, and it was exercised end to end — Replicate 404 →
fal 403 (no balance) → Gemini 429 (free-tier image quota is zero) — walking the whole chain and
returning the last failure rather than dying on the first.

---

### D-021 · `wp_remote_post( 'blocking' => false )` is not non-blocking
**2026-08-02** · changes `PLAN.md` §6.2

`PLAN.md` §6.2 specifies loopback dispatch as
`wp_remote_post( admin_url('admin-post.php'), ['blocking' => false, 'timeout' => 0.01] )`,
returning in about 100 ms. **It does not.** Measured on the testbed:

| Target | Time |
|---|---|
| Reachable address | **1002 ms** |
| Deliberately unroutable address | **1002 ms** |

WordPress passes the timeout to cURL as a whole number of seconds, so anything below one second
becomes one second, and the call blocks for it either way. The identical idiom in core's own
`spawn_cron()` has the same behaviour, which is presumably why it went unquestioned.

One second of a PHP worker on every generation request is not acceptable when the whole site
runs on four to eight of them — that is the second of this project's two governing constraints,
so this is a correctness problem, not an optimisation.

**Replaced with a raw socket write:** open the connection, write the HTTP request, close without
reading. The runner already calls `ignore_user_abort()`, so it finishes the work after the caller
hangs up.

| | Before | After |
|---|---|---|
| `Dispatcher::dispatch()` | 1002 ms | **0.4 ms** |
| `POST /generate` end to end | 1219 ms | **209 ms** |

Bootstrap on this testbed is ~200 ms by itself, so the endpoint's own work is now ~10 ms.

**Also decided: no HTTP fallback when the socket write fails**, and no dispatch attempt at all
when the self-test has already established that loopback is blocked. Both would cost a full
second to duplicate a mechanism that layers 2 and 3 are about to run anyway.

**And the self-test now tests the spawn path**, not merely reachability. A blocking request
proves the endpoint answers; it does not prove that a socket written and immediately closed still
gets processed. Those are different questions and only the second one describes production. The
probe spawns a request and watches for the transient it leaves behind.

---

### D-022 · Replicate's free access ended mid-session
**2026-08-02** · supersedes the practical assumption in D-017, confirms D-020

`black-forest-labs/flux-dev` generated designs #10, #11 and #12 successfully, then began
answering `402 Insufficient credit` roughly eight images into the session. Nothing changed on our
side. The free window is closed.

D-017 already said this must never be a production dependency; it turns out it is not a reliable
*development* dependency either. Combined with D-020 — the same model set answering 402 one hour
and 404 the next — the honest summary is that Replicate's behaviour for an unfunded account is
not predictable enough to plan around at all.

**This is not a blocker for the code.** It was, in effect, an unannounced provider outage in the
middle of an end-to-end test, and the system behaved exactly as designed: the registry walked
Replicate → fal → Gemini, the job retried and then failed terminally, the customer-facing message
stayed generic, and the cost was recorded. That is better evidence for the fallback design than
any simulation would have been.

**It does block further image generation.** Continuing requires funding an account. fal.ai remains
the recommendation — it is the primary candidate in `PLAN.md` §8 and covers both Suite A and
Suite B, and the whole Phase 0 budget is still under $5.

Phases 4 and 5 need no image provider: shaping, text rendering, imposition and the blocklist all
operate on images we already have, and there are three stored masters on the testbed to work with.

---

### D-023 · Imaging works on GD, and three things the plan had slightly wrong
**2026-08-02** · corrects `PLAN.md` §3, §9.2, §19

The whole imaging path is built and verified on real output: circle mask, bleed, upscale
decision, straight and arc text with full Lithuanian diacritics, watermark, 24-up imposition, and
PNG `pHYs` so the file actually declares 300 DPI. §9.1's claim that everything the product needs
is achievable on GD holds.

**FreeType is present on the testbed**, and all four bundled fonts pass a cmap-level check for
`ĄČĘĖĮŠŲŪŽ ąčęėįšųūž`. Production is still unverified — but the Site Health panel now reports it,
which was always the plan for finding out (§9.1.2).

Three corrections:

**1. §3's pixel table disagrees with §3's own formula.** The formula is stated as
`ceil( mm × dpi / 25.4 )`, but two cells were rounded instead: A4 width is 2552 not 2551, and the
20 cm round is 2434 not 2433. `ceil` is both what §3 says and the safe direction — a pixel short
is a white sliver at the cut, a pixel over is invisible. The code follows the formula and the
tests encode the corrected figures. The difference is 0.085 mm.

**2. §9.2's memory estimate is optimistic.** It predicts the fulfilment path "needs 256 MB and
will be uncomfortable at 128 MB". Measured peak for a 15 cm round plus a 24-up A4 sheet in one
pass: **339 MB**. The mitigations §9.2 lists are already applied — the per-circle image is
downscaled once and reused 24 times rather than compositing 24 full-size copies. Production has
not been checked for its limit, and this needs confirming before go-live; the Site Health panel
already warns below 256 MB and that threshold should probably become 384 MB.

**3. §19's `ImageEngine` interface is not built.** It specifies GD and Imagick implementations
behind an interface, but that predates D-013/D-015 settling that production has GD only and no
way to add system packages. There is no second implementation coming, and an interface with one
implementor hides code without abstracting anything. `GdEngine` is concrete; extracting an
interface later is mechanical if a real second engine ever appears.

**Two bugs that only looking at the output would have caught**, both now fixed:

- **Text at the bottom of a round topper ran off the edge.** The safe inset is a rectangle
  measurement; a circle at 80% of its height is far narrower than at the centre. Straight text now
  fits against the circle's *chord* at the height the block actually occupies, converging over
  three passes. It also cannot be placed at the extreme edge, where the chord is zero — the outer
  edge of a text block is capped at 0.82 of the radius, where the chord is still 57% of the
  diameter.
- **Arc text had no fit rule at all.** A long string does not overflow a box, it keeps going round
  the circle and eventually collides with itself. It now shrinks until the run occupies at most
  200°.

**Fonts: four bundled, all verified — but they are placeholders for the decorative set.**
DejaVu Sans and Serif, regular and bold, chosen because their licence is permissive and their
Lithuanian coverage is complete. They are competent, not festive. §9.4 asks for a curated set of
6–8, and *which* display fonts suit a cake is Ruslan's judgement rather than an engineering
question — the machinery to verify any candidate is in place, and a font that fails coverage is
listed with its missing characters rather than silently dropped.

---

### D-024 · The blocklist now catches for free what D-019 paid an LLM to catch
**2026-08-02** · completes `PLAN.md` §10

All three automatable moderation layers are built and verified. The interesting result is that
Layer 1 now handles, at zero cost and zero latency, every case D-019 measured the LLM catching
for $0.0001 and 790 ms each:

| Prompt | Layer 1 | Why it is hard |
|---|---|---|
| `Elsos suknelė` | **block** | Genitive; a substring match on "Elsa" misses it |
| `Žmogaus voro tinklas` | **block** | Genitive of the *Lithuanian* name for Spider-Man |
| `noriu torto su Šunyčiais patruliais` | **block** | Instrumental plural, inside a sentence |
| `ZMOGUS VORAS` | **block** | Diacritics stripped, upper case |
| `linksmas dinozauras su tortu` | allow | False-positive check |
| `princesė rožinėje suknelėje` | allow | A generic princess is not a franchise |

The LLM is not redundant — it still catches what no word list can, and did so again during
verification: `mėlynas ežiukas, kuris greitai bėga` came back `block / franchise:sonic` with no
proper noun anywhere in the prompt. The layers are doing exactly what §10 intends, cheapest first.

**Layers 0 and 1 run synchronously in `POST /generate`; Layer 2 runs in the job.** That split is
forced by cost, not preference: the free layers give the customer an answer immediately, while an
800 ms LLM call in the request path would hold a customer-facing worker for no benefit (§6.1).

**Decided: `MIN_STEM` is 3, not 4.** Four is the safer-looking number, but `Elsa` folds to `elsa`
and stripping `-a` leaves `els` — a four-character floor would refuse that strip and miss the
single most likely blocked prompt in the shop. The cost of three is bluntness, contained by
matching whole tokens only: `els` matches a word that stems to exactly `els`, never a substring.

**Decided: some real franchise names are deliberately absent from the starter list.** `Ratai`
(Cars) means "wheels", `Lokys` (Masha and the Bear) means "bear", `Kiaulytė` (Peppa Pig) means
"piglet". Including them would refuse ordinary cake decorations. §10 is explicit that an
over-eager filter is worse than useless, so the multi-word forms are listed instead — those are
safe because matching requires the whole phrase contiguously and in order.

**Decided: a rejection is logged but does not consume the free allowance.** §10 requires every
rejection stored with its prompt and layer, since that is the data the blocklist grows from — so a
blocked prompt still writes a design row, with no job queued and nothing spent. Taking one of five
free generations for it would be indefensible when the customer's next attempt is usually a
legitimate rewording. The per-IP daily ceiling *does* count rejections, so the blocklist cannot be
probed indefinitely.

**Verdict caching does what §10 asks for.** Measured: 943 ms and $0.000106 on the first call,
0 ms and $0 on the second. Only successful analyses are cached — caching a transport failure would
turn one bad minute into a bad day, because the plugin fails closed.

---

<!-- Next: D-025 -->
