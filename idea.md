# AI Edible Cake Topper — WooCommerce Plugin Brief

Handoff document for a Claude Code implementation session. Captures product goal,
architecture decisions and their rationale, and the open questions still to be decided.

Written 2026-08-02. Prices and API details should be re-verified before implementation.

---

## 1. Product concept

A WooCommerce store where a customer:

1. Types a prompt describing the image they want (in Lithuanian)
2. Sees a generated preview on the product page
3. Adds their chosen design to the cart and pays
4. Receives a physically printed **edible image** (icing sheet / wafer paper) for a cake

Store is Lithuanian-facing. Customer-facing UI and prompts are in Lithuanian.

---

## 2. Core architecture

The pipeline is deliberately split around the payment boundary. This is the single most
important design decision — it controls cost, prevents image theft, and keeps quality high.

```
Customer prompt (LT)
   ↓
Translate LT → EN  +  moderation check          [API, cheap]
   ↓
Generate preview: 1 MP, watermarked             [API, ~$0.003]
   ↓
Add to cart (attachment ID stored on cart item)
   ↓
Payment → order created
   ↓  (async, post-payment only)
Upscale 4x → 300 DPI print file                 [API, ~$0.02]
   ↓
Manual approval queue → print
```

### Why the split

- A visitor who generates 12 previews and leaves costs ~€0.04, not 12 upscales.
- The unwatermarked, print-resolution file only ever exists after money has changed hands.
- Fast models degrade when asked to generate directly at high resolution; generate small,
  upscale separately.

**Estimated API cost per completed order: under €0.06.** Negligible against a €10–20 product.
Do not over-optimise model choice; optimise for abuse prevention instead.

---

## 3. The print resolution constraint

This drives the whole image pipeline and is easy to get wrong.

Edible printers need **~300 DPI at final physical size**:

| Product | Physical size | Required pixels |
|---|---|---|
| Round topper | 20 cm diameter | 2362 × 2362 |
| Round topper | 15 cm diameter | 1772 × 1772 |
| A4 icing sheet | 210 × 297 mm | 2480 × 3508 |

A native 1024 × 1024 generation is **8.7 cm** at 300 DPI — a quarter of a standard cake.
It is not usable as a print file.

Solution: generate at 1 MP for preview, then run a 4× upscaler (Real-ESRGAN, clarity
upscaler, or equivalent) after payment. 4096 × 4096 covers every SKU with room to spare.

Round toppers: generate square, mask to a circle, add 3–5 mm bleed.

---

## 4. Provider choices

### Image generation

Candidates evaluated (prices as of mid-2026, verify before committing):

| Option | Approx cost | Notes |
|---|---|---|
| FLUX.1 [schnell] on fal.ai | ~$0.003 / MP | Cheapest. Weak at text-in-image and complex prompts. |
| FLUX.1 [schnell] on Replicate | similar | Queue-based, some free starting credit |
| Google Imagen 4 Fast | ~$0.02 / image | Better prompt adherence, synchronous |
| OpenAI mini image model | ~$0.009–0.02 | Synchronous, simple PHP integration |

**Practical consideration for WordPress:** fal and Replicate are queue-based
(submit → poll). fal also exposes a synchronous `fal.run/...` endpoint. Google and OpenAI
return the image directly in one POST, which is the simplest possible `wp_remote_post()`
integration.

**DECISION STILL OPEN** — see section 12.

### Translation (LT → EN)

Image models are trained overwhelmingly on English captions and produce worse results from
Lithuanian prompts. Translate before generating.

- **DeepL** — best quality for Lithuanian and other European languages. Free developer tier
  available. Note DeepL restructured its API plans in mid-2026 (Developer / Growth); ignore
  older articles quoting the retired API Free / API Pro rates.
- **An LLM** (Gemini Flash, Claude Haiku, GPT mini) — cheaper per character, handles context
  better, and can be combined with the moderation call in a single request. Likely the better
  choice here since we need an LLM classifier anyway.

**Suggested:** one LLM call that does translation *and* moderation classification together,
returning JSON. Halves the latency and the call count.

### Prompt post-processing

Append a fixed style suffix to every translated prompt to compensate for edible ink
limitations (see section 8), e.g.:

> "..., on a clean white background, bright cheerful colors, simple composition, no text"

---

## 5. Content moderation — the highest-risk area

**This is the biggest practical threat to the business, ahead of any technical concern.**

Cake topper demand is dominated by licensed characters: Elsa, Spider-Man, Mickey, Bluey,
Peppa Pig, Paw Patrol. Customers will absolutely type these. The model will produce something
close enough. The result is a physical product sold for money bearing someone else's IP.
Rights holders enforce this; small print shops receive takedowns.

Three layers, all required:

1. **Prompt blocklist** — franchise and character names, checked *before* spending an API
   call. Cheap, catches the majority. Must be editable from admin without a code deploy.
2. **LLM classifier** — one call (~$0.0001) asking whether the prompt describes a copyrighted
   character, a real identifiable person, or sexual/violent content. Catches paraphrases the
   blocklist misses ("blue hedgehog running fast", "the ice princess from that movie").
3. **Manual approval before printing.** Non-negotiable. Never auto-send to the printer. A
   custom order status (`wc-awaiting-approval`) with an admin review screen. Ten seconds of
   human eyeballing per order catches everything the automated layers miss.

Also block: real people's names (likeness rights), NSFW, hate symbols.

Terms of service must state that orders violating content rules may be cancelled and
refunded. Surface this near the prompt input, not buried in a footer link.

---

## 6. Abuse and cost control

The generate endpoint is public and costs money per click. Unprotected, a loop costs real
euros.

**Use a custom DB table, not transients.** If the host has Redis or Memcached object caching,
transients can be evicted early and the limiter silently stops limiting.

```sql
CREATE TABLE wp_aicake_generations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_key   VARCHAR(64)  NOT NULL,
  ip_hash       CHAR(64)     NOT NULL,
  user_id       BIGINT UNSIGNED DEFAULT NULL,
  prompt_raw    TEXT         NOT NULL,
  prompt_en     TEXT         DEFAULT NULL,
  attachment_id BIGINT UNSIGNED DEFAULT NULL,
  status        VARCHAR(20)  NOT NULL,
  api_cost      DECIMAL(10,5) DEFAULT NULL,
  created_at    DATETIME     NOT NULL,
  INDEX (ip_hash, created_at),
  INDEX (session_key, created_at)
);
```

One table serves throttling, moderation audit, and cost analytics.

### Keying the limiter

IP alone is weak — Lithuanian mobile carriers use CGNAT (many customers behind one IP) and
IPv6 addresses rotate. Use a composite:

- hashed IP
- a plugin-set cookie (`session_key`)
- `user_id` when logged in

Count against the **loosest** of the three.

### GDPR

Store `SHA256(ip + salt)`, never the raw address. Define a retention window and add a cleanup
cron. The salt lives in `wp-config.php`.

### Limits

Suggested default: **5 free generations per session**, then require account creation. Make
this an admin setting. Also verify a nonce on every request and enforce limits server-side —
never trust the frontend.

---

## 7. Storage and file security

- Private directory: `wp-content/uploads/aicake-private/`
- Hashed, unguessable filenames
- Served through a PHP endpoint that checks entitlement (order ownership / admin capability)

**`.htaccess` does nothing on nginx**, which most decent hosts use. Do not rely on webserver
rules — rely on the hashed path plus the PHP gate.

If the print file sits at a guessable public URL, customers generate a preview, guess the URL,
and never buy.

### Watermarking

Must be **composited into the pixels server-side** with Imagick (preferred) or GD (always
available). A CSS overlay or canvas layer is removed with devtools in seconds.

Style: diagonal repeated text at ~25% opacity across the entire image. Not a corner logo.

---

## 8. Edible printing specifics

- **Icing sheets vs wafer paper** — icing sheets give noticeably better colour and detail.
  Wafer is cheaper but washes out.
- **Narrow colour gamut.** Bright saturated blues, greens and purples print dull. Convert to
  CMYK and soft-proof, or previews will over-promise what the customer receives.
- **Dark backgrounds** soak ink, look muddy, and taste bitter. Steer prompts toward light
  backgrounds via the style suffix.
- **Shelf life and shipping** — icing sheets are humidity-sensitive and fragile. Ship flat,
  sealed, with a backing sheet.

Consider showing the customer a CMYK-simulated preview rather than the raw RGB output, to
reduce "it looked brighter on screen" complaints.

---

## 9. Plugin structure

Standalone plugin — not a child theme (must survive theme changes), not a snippets plugin
(this will reach a few thousand lines).

```
ai-cake-topper/
├── ai-cake-topper.php          bootstrap, constants, activation hook
├── includes/
│   ├── class-plugin.php         singleton, hook registration
│   ├── class-rest.php           /wp-json/aicake/v1/ endpoints
│   ├── class-generator.php      API client (translate → moderate → generate)
│   ├── class-moderation.php     blocklist + LLM classifier
│   ├── class-throttle.php       rate limiting
│   ├── class-image.php          watermark, circle mask, CMYK, upscale
│   ├── class-storage.php        private file handling
│   ├── class-product.php        product data tab, meta box
│   ├── class-cart.php           cart item data, order line item
│   ├── class-order.php          post-payment upscale, approval status
│   └── admin/
│       ├── class-settings.php   API keys, limits, blocklist
│       └── class-review.php     approve/reject queue
├── assets/js/generator.js
└── languages/
```

Split by responsibility, not by hook. When the blocklist needs updating, it is obvious which
file to open.

---

## 10. WooCommerce integration

### Do NOT register a custom product type

Common instinct, usually wrong. Product types exist to change **pricing and cart behaviour**
(subscriptions, bookings, grouped products). This is a normal product with an extra input
field.

Registering `product_type = ai_edible` immediately loses variable-product support — and
variations are needed, because "A4 sheet / 20 cm round / 15 cm round" are different prices.

**Instead:** variable product + a checkbox in a product data tab, stored as one meta field
`_aicake_enabled`. The plugin checks the flag and injects the prompt UI. Themes, page builders
and other Woo extensions keep working.

### Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_before_add_to_cart_button` | prompt field + preview area |
| `woocommerce_add_cart_item_data` | attach attachment ID; add a unique key so two different designs don't merge into one cart line |
| `woocommerce_get_item_data` | show thumbnail in cart / checkout |
| `woocommerce_checkout_create_order_line_item` | persist to order meta |
| `woocommerce_admin_order_item_headers` / `_values` | show print file in admin order screen |
| `woocommerce_order_status_processing` | enqueue the upscale job |

### Custom order status

Register `wc-awaiting-approval` via `register_post_status` + the `wc_order_statuses` filter,
so the print queue is a real filter in the orders list rather than a note someone has to read.

### Async processing

Generation takes 3–10 seconds. Blocking a PHP worker that long is survivable for previews at
low traffic, but the **post-payment upscale must never block checkout**.

WooCommerce already ships **Action Scheduler** — use it. Hook order status → enqueue upscale
job → run in background → flip order to `wc-awaiting-approval` when the file is ready.

---

## 11. Security and configuration

**API keys must not live in `wp_options`.** They would sit in the database and in every
backup.

Pattern: the settings page reads a constant if one is defined, and falls back to the option
only if not. Production defines them in `wp-config.php`:

```php
define('AICAKE_IMAGE_API_KEY', '...');
define('AICAKE_LLM_API_KEY', '...');
define('AICAKE_IP_SALT', '...');
```

Other requirements:

- Nonce verification on every REST request
- Capability checks on all admin endpoints
- Server-side rate limiting (frontend limits are cosmetic)
- Sanitise prompt input; cap length (e.g. 500 chars)
- Wrap all strings in `__()` with a text domain from day one, ship a `.pot`. Loco Translate
  handles the Lithuanian translation from there.

---

## 12. Open decisions

These were not settled in the planning conversation and need deciding early:

1. **Which image API.** fal (cheapest, queue-based), Replicate (similar, free credit), Google
   Imagen 4 Fast (better adherence, synchronous), OpenAI mini (synchronous, simplest PHP).
   The sync/async difference materially changes `class-generator.php`.

2. **Preview job flow.**
   - *Simple:* POST returns the finished image after ~5 seconds.
   - *Better:* POST returns a job ID immediately, JS polls a status endpoint.

   The second costs roughly a day of extra work and prevents a slow API from tying up PHP
   workers. **Retrofitting polling into a synchronous frontend is painful — decide now.** If
   more than a handful of concurrent users are expected, build polling from the start.

3. **Upscaler choice** — Real-ESRGAN vs a clarity/creative upscaler. Creative upscalers add
   detail that wasn't there, which may be better or worse for this use case. Worth testing on
   real cake-topper-style images.

4. **Translation approach** — DeepL as a separate call, or folded into the moderation LLM
   call. The combined approach is recommended but untested.

5. **Free generation limit** — 5 is a starting guess. Needs tuning against real conversion
   data.

---

## 13. Regulatory notes (Lithuania / EU)

Not legal advice — confirm directly with the relevant authority before launch.

- Selling edible products in Lithuania requires registration as a food business with **VMVT**
  (Valstybinė maisto ir veterinarijos tarnyba).
- Allergen information required on packaging. Icing sheets typically contain starch, sugar,
  and sometimes soy lecithin — get the exact declaration from the sheet supplier.
- GDPR applies to the IP hashing, retention, and the prompt log. Prompts are user-generated
  content and may contain personal data; include them in the privacy policy and in any data
  deletion routine.
- Distance selling rules: custom-made goods are generally exempt from the standard EU
  14-day withdrawal right, but this must be clearly disclosed before purchase. Verify.

---

## 14. Suggested build order

1. `class-throttle.php` + DB table — everything else depends on it
2. `class-rest.php` — the endpoint skeleton
3. `class-generator.php` — translate + generate, hardcoded settings at first
4. `class-moderation.php` — blocklist first, LLM classifier second
5. `class-image.php` — watermarking
6. `class-product.php` + `class-cart.php` — the WooCommerce half
7. `class-order.php` + Action Scheduler — post-payment upscale
8. `admin/` — settings and review queue
9. i18n pass and `.pot` generation

Steps 1–5 are testable without WooCommerce involved at all.