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

### D-025 · §7's uncached-nonce trick has a second half nobody wrote down
**2026-08-02** · corrects `PLAN.md` §7 · **fixed — see D-026**

§7 is right that a nonce must never be printed into cached HTML — a stale one 403s every
logged-out generation. The plugin does that, and it works for anonymous visitors.

**It breaks for logged-in customers**, which was found by driving the real page in a browser:

```
GET  /aicake/v1/session   → 200, logged_in: FALSE   (as an authenticated admin)
POST /aicake/v1/generate  → 403 rest_cookie_invalid_nonce
```

The cause is a chicken-and-egg in WordPress's REST cookie authentication.
`rest_cookie_check_errors()` only authenticates a cookie-carrying REST request when a **valid
nonce is already present**. `/session` deliberately sends none, so WordPress treats the caller as
user 0 and `wp_create_nonce( 'wp_rest' )` mints a nonce **for user 0**. The next request sends
that nonce *together with* the login cookie, WordPress now validates it against the real user id,
and the two do not match.

So the endpoint that exists to hand out a working nonce hands out one that is only valid for
visitors who are not logged in — and logged-in customers are precisely the ones who signed up to
get a larger free allowance (§11.3).

**The fix, for whoever picks this up:** print the nonce into the page *for logged-in users only*,
and keep the uncached endpoint for anonymous ones. Every page cache worth the name already
bypasses itself when `wordpress_logged_in_*` is set, so there is no stale-nonce risk for them —
which means §7's reasoning was always specifically about anonymous traffic, it just never said so.
The JS should prefer a printed nonce when present and fall back to `/session` otherwise.

Worth stating plainly: this is a bug the unit tests, the REST tests and the earlier end-to-end
curl run all missed, because every one of them ran logged out. It took loading the page as a real
logged-in user to see it.

---

### D-026 · The nonce rule is per-audience, and the bug was costing logged-in customers their allowance
**2026-08-02** · applies D-025 · adds `PLAN.md` §7.1

D-025's fix is in. The nonce is printed into the page **for logged-in users only**
(`Frontend/Generator.php`), the uncached `/session` endpoint still serves anonymous visitors, and
the JS prefers a printed nonce whenever there is one — including on the `/session` call itself.

That last detail turned out to matter more than the 403 did. Measured on the testbed as a real
`customer`-role user:

```
GET /session  without a nonce  → logged_in: false, allowance: 5    ← the bug
GET /session  with the printed → logged_in: true,  allowance: 20   ← the fix
```

**Logged-in customers were being served the anonymous allowance.** §11.3 offers a larger free
allowance as the reason to create an account, and the account was buying them nothing — not
because the allowance logic was wrong, but because the request that reads it never authenticated.
Nobody would have reported this as a bug; it looks exactly like the feature working.

Verified over real HTTP, logged in and logged out:

| | |
|---|---|
| `generate` + login cookie + user 0 nonce | **403** `rest_cookie_invalid_nonce` — D-025 reproduced |
| `generate` + login cookie + printed nonce | **202** `{job_id, design_id, poll_after_ms}` |
| anonymous `session` → `generate` | **202** — §7 path intact |
| anonymous `generate`, no nonce | **403** — still refused |
| anonymous product page HTML | `"nonce":""` — nothing leaked into cacheable markup |
| job polled to terminal state as its logged-in owner | `failed / quota` — the D-022 outage, reached cleanly |
| same job polled by a stranger | **404**, not 403 — no id enumeration |

Two JS branches exist because they are genuinely reachable and each has exactly one cure:

- `/session` **403s** while a printed nonce is in hand → the nonce outlived its window and the
  login cookie is still good. Nothing in the page can mint a replacement, so the customer is
  asked to reload rather than told to retry something that cannot work.
- `/session` returns **200 with `logged_in: false`** → they logged out in another tab, and the
  endpoint's anonymous nonce is now the correct one. Drop the printed one and carry on.

**The lesson is the test method, not the code.** Unit tests, REST tests and an end-to-end curl run
all passed against this bug for two phases. What found it was loading the page as a real
logged-in user. A testbed with an admin account and no customer account quietly tests one
audience twice — there is now a `testuser` / `customer` account for the other one.

---

### D-027 · Every print file the plugin has ever made declared two resolutions
**2026-08-03** · corrects `PLAN.md` §9.1 · found during Phase 7

`GdEngine::inject_phys()` inserted a `pHYs` chunk after IHDR without checking
whether one was already there. It always was: this GD build — **the same
`bundled (2.1.0 compatible)` build production reports** — writes its own `pHYs`
declaring the image's default 96 DPI.

So every print file carried two contradictory resolutions. It was malformed
PNG, libpng warned about it on every read, and a decoder that takes the last
chunk rather than the first saw **96 DPI on a file meant to be 300** — which is
precisely the four-times-too-large print that §9.1 added the chunk to prevent,
hiding inside the fix for it.

`read_dpi()` reported 300 and every Phase 4 assertion passed, because it did a
`strpos()` for `pHYs` and found ours first. Both methods now walk the chunk
list properly: `inject_phys()` strips existing chunks before inserting, and
`read_dpi()` cannot match the four type bytes occurring inside compressed pixel
data.

**What actually found it was a libpng warning on stderr** while making a
thumbnail to look at — not an assertion. The lesson is the one from D-026 in a
different key: the check that would have caught this is cheap and now exists
(`GdEngineTest`, five cases, including that there is never more than one
chunk), but nothing prompted anyone to write it, because the end-to-end result
looked right.

---

### D-028 · A cookie without a nonce is user 0, wherever it happens
**2026-08-03** · extends D-025 · found during Phase 7

The admin order screen offers "Spausdinimo failas" as a plain `<a href>` to
`/aicake/v1/file/{id}/print`, gated on `manage_woocommerce`. Measured against
the live testbed as a real `shop_manager`:

```
GET /file/{id}/print                    → 404
GET /file/{id}/print?_wpnonce=<wp_rest> → 200  image/png
```

Same mechanism as D-025: WordPress's REST cookie check only authenticates when
a valid nonce is present, so without one the shop manager is user 0 and the
capability test fails. A link and an `<img src>` cannot send an `X-WP-Nonce`
header, so the nonce goes in the query string. Both URLs on that screen now
carry one.

The download button would have 404'd every single time while looking perfectly
correct in the markup — and the markup is what an assertion would have checked.

**Generalising, because this is now twice:** any of our REST endpoints reached
by cookie alone is anonymous. That covers ordinary links, image tags, form
posts and anything a browser issues without our JavaScript. The rule is that
the caller supplies a nonce or the endpoint must not depend on who is asking.

---

### D-029 · Phase 7's verification is committed, unlike Phase 3's and Phase 5's
**2026-08-03** · corrects `WORKFLOW.md` §7

Phase 3 claimed 17 end-to-end checks and Phase 5 claimed 39 stack assertions.
Neither script is in the repository — they were run from scratch files and
deleted. Those numbers are now unrepeatable claims about code that has changed
several times since.

Phase 7's gate is `tools/order-check.php`: 54 assertions covering statuses,
storage layout, the real `woocommerce_order_status_processing` trigger,
geometry, the sidecar, idempotency, the failure path through to
`aicake-failed`, recovery via the retry, and reorder. It runs against the
deployed testbed with `wp eval-file` and exits non-zero on failure.

Together with `tools/rest-check.sh` (D-026) that is the pattern going forward:
**anything WordPress-bound that gets verified gets committed as a script.**
`tests/run.php` stays for the pure-PHP units. A verification that only ever
existed in a session transcript is not a verification.

---

### D-030 · fal is funded, and is now the primary image provider
**2026-08-03** · closes D-022 · supersedes the ordering in D-017/D-018

Ruslan added credit to fal.ai. Probed through the plugin's own `FalFluxProvider`
against the live API, not a pricing page: `fal-ai/flux/dev` returned a 992×992
PNG in 4.7 s at the recorded $0.012. No code change was needed to turn it on,
which is what the §8.5 interface was for.

`ProviderRegistry::DEFAULT_IMAGE_ORDER` moves from `replicate, fal,
gemini-image` to **`fal, replicate, gemini-image`**. Two reasons, and only the
first is about money:

1. **D-017 stands.** Free Replicate access is undocumented, follows no pattern,
   and already withdrew once mid-session (D-022). It may sit in the chain as a
   fallback; it may not be the thing production depends on.
2. **Replicate first was costing a round trip per generation.** It answers
   `402` now, and `should_fall_through()` correctly walks past it — so every
   image paid for a wasted call before reaching the provider that works.

**The success path is verified, and Phase 6's gate is met.** It had never been
seen end to end for the reason above — see D-022. Now, in one `rest-check.sh`
run: `POST /generate` → 202 → job claimed → fal → master and preview on disk →
design `done`. Produced and inspected, not merely asserted:

- **master** — 992×992 PNG, flat vector on white, single subject: the house
  style suffix doing what D-019 tuned it to do.
- **preview** — 800×800 WebP, circle-masked and watermarked, 20 KB.
- **print file** — that same real master through `FulfilPipeline` at the 15 cm
  spec: 1843×1843 at 300 DPI, `Ąž` rendering correctly from the bundled fonts.

Phase 7 was verified against a synthetic master (D-029). It is now verified
against a real one, so nothing in the chain is fixture-only any more.

**D-019's two tuning items are both confirmed on real output, neither
blocking.** The drop shadow is still there, and the subject sits low and right
of centre, which at `PLACE_BOTTOM` puts the greeting across the subject's legs
rather than under it. Both are prompt-suffix work, not pipeline work.

> **Cost recording stops being conservative here.** The note in `STATE.md`
> about `ReplicateProvider::estimate_cost()` over-recording list price on free
> calls no longer applies to the primary provider: fal bills, and $0.012 is
> what it billed.

---

### D-031 · The Phase 7 gate, run as documented, broke the shop
**2026-08-03** · corrects `tools/order-check.php` and `Capabilities.php`

A real order — `Keksiukų dekoracijos ⌀6 cm, 12 vnt` — failed in the admin with
**„Nepavyko įrašyti spausdinimo failo."** The image had been generated and paid
for. `FulfilPipeline` rendered fine; `OrderArchive::archive()` returned `''`.

`/var/lib/aicake/orders/2026/08` was owned by **root**, mode 775, group 1000.
PHP runs as www-data (uid 33), not in that group, so it could not create the
order's own folder inside it.

**It was `order-check.php` that made it root-owned.** The header said to run the
gate with `--allow-root`, so the dated parent was created by root the first time
the gate ran. Every subsequent real order then failed. The gate went green and
the storefront broke — *because* the gate had run.

This is D-003's failure mode in the second zone. `Capabilities::can_actually_write()`
already existed for exactly this, and its docblock describes this bug
precisely — but it only ever probed `sessions/YYYY/MM`. The orders zone was
never checked, and it is the worse of the two: the customer has already paid
and the image already exists.

Three changes:

1. **The gate runs as the web user.** `-u www-data`, never `--allow-root`. A
   verification that runs with privileges the real code does not have is not
   verifying the real code. After a full run, `find /var/lib/aicake -uid 0`
   returns nothing.
2. **The Site Health probe covers both zones**, and probes the *dated*
   directory, because that is the parent whose ownership decides whether the
   per-order `mkdir` succeeds.
3. The critical-status wording now names both consequences.

**The probe was falsified before being trusted**, as D-026 requires: `chown
root:root` on `orders/2026/08` turns `storage_writable` to `no`, and restoring
it turns it back. Without step 2 that same fault reported a healthy site.

The order recovered with no re-render once ownership was fixed — the retry
produced a correct 2363 × 3390 sheet, 3 × 4 = 12 up at ⌀60 mm.

**Not a bug, checked while here:** the order sat at `on-hold`, which
`finish_if_complete()` deliberately excludes from promotion. That is right —
an unpaid order must not walk into the print queue — and it is self-healing:
on payment the order moves to `processing`, the idempotency check finds the
existing print file, nothing is re-rendered and no money is spent, and the
order lands in `aicake-approval`. Verified.

---

### D-032 · The watermark was white on white, and so barely a watermark
**2026-08-03** · corrects `PLAN.md` §9.3 · Ruslan, on seeing a real preview

§9.3 specified "~25% opacity" and the implementation drew a white mark with a
faint dark shadow behind it. That number was written before any image existed
to test it against.

The house style suffix deliberately produces **flat vector art on a white
background**. So the primary pass — white at 25% — was landing on white and
disappearing. What little you could see was the shadow, drawn *fainter* still.
The preview was close to unprotected, which matters: §9.3's threat model is
not a determined attacker, it is a customer who realises they could just save
the picture.

Three changes:

1. **Dark ink with a light halo, not white with a dark shadow.** The dark pass
   now carries the mark, so it reads on the artwork we actually generate; the
   halo keeps it legible if a customer gets a dark subject.
2. **Opacity 0.25 → 0.42**, exposed as `watermark_opacity` and clamped to
   0.1–0.75. A mark nobody can see through is a preview nobody can use, so the
   ceiling matters as much as the floor.
3. **Denser and larger** — type at 1/14 of the short edge rather than 1/18,
   tiles at 1.35/1.5 rather than 1.6/1.8. The halo offset is now proportional
   to type size rather than a fixed 2 px, which had been vanishing at preview
   scale.

Judged on real output, not on the constant: regenerated from an existing fal
master, the mark is unmistakably present and the artwork is still judgeable.

**The general lesson is the one from D-019 and D-027.** A number in `PLAN.md`
that was never checked against a rendered image is a guess. This one survived
Phase 4's 83 assertions and Phase 6's verification because nothing asserts
"can a human see this" — it took looking at a picture.

---

### D-033 · The text layer moves to the browser, and the print canvas becomes A4
**2026-08-03** · Ruslan · **agreed direction, not scheduled** · affects `PLAN.md` §3, §9.4, §14, §15

A design conversation, recorded because it is expensive to reconstruct and it
changes what Phase 8's review queue has to be. **Nothing here is built.**

The trigger: the current text layer offers a font, a colour, a size and five
fixed placements. On real output that produces text sitting across the artwork
(D-030, D-032), and it cannot do the thing a cake shop actually wants — twelve
cupcakes with twelve different names.

#### What was decided

1. **The customer composes text in the browser, over the watermarked preview.**
   Zero PHP workers touched while editing.
2. **What crosses the wire is a PNG-32 with a transparent background**, plus
   the plain text string. The string is not used for rendering — it is there so
   moderation layers 0 and 1 can still read what was typed, which a bitmap
   otherwise hides, and so the order record is readable without opening an
   image.
3. **The final print canvas is always A4**, every product centred inside it.
4. **The text layer is exactly the dimensions of the final print file** — the
   whole sheet, not one piece.
5. **The server draws a solid black cut line** at the trim diameter.
6. **The editor prevents text outside the safe zone.** Not a guide — a
   constraint.

#### Why A4 for everything

Not disk space, and not uniformity for its own sake: **a file that is not page
sized has to be placed by whoever prints it.** One "fit to page" in a print
dialog and a 150 mm topper comes out 143 mm. An A4 file printed at 100% is
correct by construction. That is D-027's failure mode — a file that is right
and prints at the wrong physical size — reached by a different route.

It also deletes the sheet-versus-single-piece branch from the pipeline.

#### Why the text layer is sheet sized

Per-piece text is impossible if the text is baked into the piece before
imposition. Sheet-sized layer means the order becomes `impose → composite text`
rather than `composite text → impose`, and twelve different names on twelve
cupcakes works from day one. Retrofitting it later would mean re-cutting the
pipeline.

The client must never compute piece positions itself. `SheetLayout` derives
them server-side and the editor consumes them, or text lands across a gutter —
and it would look right in the editor and wrong on the print.

#### Why the customer cuts, and what follows

Ruslan ships the printed A4; **the customer cuts it.** So:

- The cut line is part of the product, not a convenience. Solid black, ~0.3 mm,
  drawn at trim with artwork continuing 3 mm past it into the bleed — which is
  what makes a kitchen-scissors cut forgiving in both directions.
- It is drawn **server-side**. In the customer's layer they could move it,
  resize it or omit it.
- It must appear in the preview. A line on the printed sheet that was not in
  the proof reads as a printing fault.
- **The 5 mm safe zone stops being a formality.** A hand cut is far less
  accurate than a trimmed one, so a name 2 mm inside the trim gets clipped.
  Enforcing it in the editor prevents more complaints than any amount of
  review-queue attention.

#### What this deletes

Server-side text rendering entirely: arc text, auto-fit, wrapping, faked
outline strokes, and the Lithuanian cmap coverage gate. With it go the font
picker, font bundling and licensing for product use, and the browser↔GD parity
problem. Fonts remain only for the watermark, which draws our own domain name.

Fonts still need Lithuanian coverage **client-side** — `Ąžuolas` rendering as
tofu boxes is worse baked into a bitmap, because nothing downstream can catch
it. Curated self-hosted list, not the open Google catalogue (also the GDPR
answer: an EU shop must not hotlink Google's CDN).

#### The load-bearing new check

**Every non-transparent pixel in the uploaded layer must be close to a colour
the customer declared.** Antialiasing passes; a photograph or a franchise
character does not. Without it the endpoint accepts arbitrary artwork and
layers 0–2 are blind to all of it — which is the entire risk §10 exists to
manage. This is not optional.

#### Explicitly given up

Reorders and post-order modifications. Ruslan's call: he is the operator, he
sees every final image, and an occasional reprint at a different size is ten
minutes in GIMP a few times a year. Building generality for it costs more than
it saves. This also closes §14's v1.5 idea of ganging single toppers from
different orders onto one sheet.

#### Unresolved, and now load-bearing

**`PLAN.md` contradicts itself about A4.** The §3 table gives the A4 SKU as
216 × 303 mm (paper + bleed, 2552 × 3579 px); §3.4 says usable area is
200 × 287 mm and that *"imposition maths uses the usable area, never the paper
size."* Both cannot be right — a printer that cannot reach the sheet edge
cannot produce a full-bleed 210 × 297.

The usable area wins, because it is the one that physically prints. Which makes
**the printer's real usable area a number every product now depends on**, not
just sheets. It is still a placeholder. Measure it before any of this is built.

---

<!-- Next: D-034 -->
