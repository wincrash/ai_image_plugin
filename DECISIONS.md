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

<!-- Next: D-015 -->
