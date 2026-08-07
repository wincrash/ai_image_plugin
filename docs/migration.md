# Migration to production

**valgomosdekoracijos.lt — the real shop.** Written 2026-08-07 from a full Site Health dump
Ruslan pasted from the live admin. Every fact in "Production, verified" below is read off that
dump, not assumed.

`STATE.md` says where the project is. This file says how it gets onto the live server and in
what order, what has to be built first, and what to do when a step goes wrong.

> **The decision that frames everything: we go straight to live.** No staging copy — the site is
> 17.8 GB, so cloning it is not realistic. Ruslan's words: *"we go live, but the link to real
> wizard will be hidden/unknown to users, so it should be ok, and dont disturb users."*
>
> That makes **"the shop behaves exactly as it did yesterday" the acceptance criterion for every
> step up to M5**, and it is why the plugin is installed inert and configured afterwards rather
> than the other way round.

---

## 1. Production, verified 2026-08-07

| | |
|---|---|
| Path | `/home/vaijos/domains/valgomosdekoracijos.lt/public_html` — DirectAdmin layout |
| PHP | **8.4.14**, 64-bit, `cgi-fcgi`, Apache |
| `memory_limit` | **256M** — see §2.1, this is a blocker |
| `max_execution_time` | 300 s |
| `post_max_size` / upload | 64M / 64M |
| WordPress | **7.0.2**, `lt_LT`, https, `environment_type: production` |
| WooCommerce | **10.9.4** |
| WC Fields Factory | **4.1.9** |
| Theme | `valgomos` **2.7.38**, child of Blocksy **2.1.51** |
| Database | MariaDB 10.6.17, **742 MB** |
| Disk | uploads **15.87 GB**, total **17.81 GB** |
| Users | **11 133** registered |
| Image editor | `WP_Image_Editor_GD` — **no Imagick**, GD bundled 2.1.0, WebP yes |
| OPcache | on, **full** (33 MB used of 33 MB, interned strings 100%, hit rate 19.5%) |
| `WP_DEBUG` | false, `WP_DEBUG_LOG` false |
| Filesystem | `wp-content`, `plugins`, `uploads`, `mu-plugins` all writable |
| Backup tooling | Installatron present (`installatron_hide_status_test.php` mu-plugin) |

**The testbed matches production on everything that decides behaviour.** WP, WooCommerce and
WC Fields Factory are the same builds; GD-without-Imagick is the path we have been developing on
since D-013. The theme differs by two patch versions of Blocksy and a much-modified child.

### The preflight — run 2026-08-07, and it answered everything

`tools/host-check.php` against the live shop. **Both §2 blockers are retired.**

| Question | Answer |
|---|---|
| `ini_set( 'memory_limit', '512M' )` | **Sticks.** §2.1 is solvable by configuration |
| `open_basedir` | `/home/vaijos/:/tmp:/usr/share/pear` — restricted but **covers the target** |
| Storage outside the webroot | `/home/vaijos/domains/valgomosdekoracijos.lt/aicake-files` — **writable**, confirmed by a real probe write |
| GD **FreeType** | **Yes**, and `ĄČĘĖĮŠŲŪŽ` rendered — 668 dark samples |
| Loopback | **Works** — the host reaches itself |
| Anonymous `/wp-json/` | **HTTP 200.** Really Simple Security is not blocking the REST API |
| Outbound to fal.run and Google | **Reachable** |
| A4 300 DPI and 4096² canvases | **Both allocate**, 120 MB peak in the probe |
| `opcache.validate_timestamps` | **On**, `revalidate_freq` 2 — a file replaced over FTP is picked up |

**One finding nobody was looking for: production has no sodium.** PHP 8.4 normally bundles it.
So the plugin encrypts API keys with **openssl/aes-256-gcm** on the live shop, which was written
as a fallback and had never been executed. That is D-052, and the testbed now runs the same
branch on purpose.

Two items remain amber rather than red:

- **OPcache is full** (33 MB of 33 MB, hit rate 19.5%) — a pre-existing site-wide condition, not
  ours. Our files will largely not be cached. Slower, not broken.
- The canonical host is **`www.valgomosdekoracijos.lt`**; the bare domain 301s to it. Worth
  remembering wherever a URL is constructed.

---

## 2. The four things that can actually stop us

### 2.1 `memory_limit` is 256M and our measured peak is 339M

> **Retired 2026-08-07 — `ini_set( 'memory_limit', '512M' )` sticks on this host.** The plugin
> raises the limit for the render and the ceiling stops being a launch blocker. Response 3 below
> is still worth doing, because a host setting we do not control should not be the only thing
> between a paid order and a failed render — but it is now hardening, not a blocker.

This was the headline risk and it is not close. D-023 measured **339 MB peak** rendering the A4
4× path. Production allows **256M**. A print render that hits the ceiling dies *after the
customer has paid*, which is the worst possible place for it.

Three responses, and we do all three because the first two are not ours to guarantee:

1. **Ask the host / the DirectAdmin panel to raise `memory_limit` to 512M.** Cheap, usually
   granted, but it is somebody else's setting and it can be reverted by a panel update.
2. **Probe whether `ini_set()` can raise it from PHP.** On `cgi-fcgi` with a per-user php.ini
   this usually works. If it does, the plugin raises the limit for the render only.
3. **Cut the peak below ~200 MB and prove it under a forced 256M limit** — the only one of the
   three that is ours. `tools/memory-check.php` will run the worst format (A4, 4× upscale, a full
   sheet-sized text layer) with `memory_limit` pinned to 256M on the testbed. If it passes there
   it passes on production, because the geometry is identical.

   Where the memory goes: an A4 canvas at 300 DPI is 2552 × 3579 ≈ 9.1 Mpx ≈ 36 MB in GD, and the
   pipeline holds several at once — master, upscaled master, shaped piece, sheet, the customer's
   text layer, the composite. Eager `imagedestroy()`, writing PNG straight to a file rather than
   to a string, and never holding a source after its destination exists should get us to roughly
   150 MB. This is work, not a code review comment.

> Note `WP_MEMORY_LIMIT: 40M` in the dump. That is WordPress's own default, not a host setting,
> and it never *lowers* PHP's limit — the effective limit is PHP's 256M. `wp_raise_memory_limit()`
> raises to `WP_MAX_MEMORY_LIMIT`, which is also 256M, so it buys nothing here.

### 2.2 Really Simple Security may block the REST API for logged-out visitors

> **Retired 2026-08-07 — anonymous `/wp-json/` returns HTTP 200 with a namespace list.** The
> REST API is reachable logged out. Still worth a glance at its Hardening screen before install,
> because the setting exists and could be switched on later by an update or by a well-meaning
> click.

The wizard's entire audience is anonymous. It calls `/wp-json/aicake/v1/session`, `/generate` and
`/jobs/{id}` before anyone logs in. Really Simple Security has a hardening option that disables
the REST API for logged-out users, and if it is on, the wizard is dead for exactly the people it
exists for — and it will look like our bug.

**Check before install**, in Really Simple Security → Hardening: whether the REST API is
restricted, and whether user enumeration blocking touches `/wp-json/`. If it is on, the fix is a
namespace allowance for `aicake/v1`, not switching the hardening off site-wide.

Same screen is worth a glance for anything that rewrites headers on `admin-post.php`, which is
what the loopback dispatcher and the print-file download both use.

### 2.3 Storage outside the webroot

> **Settled 2026-08-07.** `open_basedir` is `/home/vaijos/:/tmp:/usr/share/pear` and the
> preflight wrote a probe file into the target successfully. The fallback below is not needed.

Target: `/home/vaijos/domains/valgomosdekoracijos.lt/aicake-files/` — a sibling of `public_html`,
created over FTP, so nothing generated is ever reachable by URL.

**Fallback if it cannot:** `wp-content/uploads/aicake/` with a `deny from all` `.htaccess` and
unguessable filenames (`PLAN.md` §12.4). It works, but it leans entirely on the web server
honouring the `.htaccess`, and uploads is already 15.87 GB — see §2.4.

Either way the directory must be created by, or chowned to, the user PHP runs as. D-003 and D-031
are both this bug: the zone root looks fine and every write fails. Creating it over FTP creates it
as the FTP user, which on DirectAdmin *is* the PHP user — so this should be clean, and Site Health
will confirm with a real probe write.

### 2.4 Disk, and why the retention job stops being optional

Total site is 17.81 GB with uploads at 15.87 GB. Every generation writes a master plus a preview
whether or not anyone buys it, and at 5–20 free generations per visitor that grows quickly.

**The §12.5 cleanup job is now a prerequisite for launch, not the leftover Phase 8 item.** It is
the last thing on the Phase 8 list (STATE.md) and it is the one that has to exist before the
wizard is public. Sessions older than 30 days lose their files; `orders/` is never touched.

---

## 3. What has to be built before production is touched — M0

All of this happens in `C:\AI_IMAGE\` and is verified on the testbed. Nothing here goes near the
live shop.

| # | Work | Why it blocks |
|---|---|---|
| **M0.1** | **API keys in the admin screen** (D-050) | Ruslan's first requirement. Without it, every key change is an FTP edit of `wp-config.php` |
| **M0.2** | **Settings screen** — throttle, budget, storage + diagnostics, prompt style suffix | There is no WP-CLI on production. Today these are only reachable by `wp eval`, so on live they would be unreachable entirely |
| **M0.3** | **Peak memory under 256M**, proven with the limit pinned (§2.1) | A paid order that cannot render |
| **M0.4** | **Retention cleanup job** (§12.5) | §2.4 |
| **M0.5** | **Inertness proof** — the plugin changes nothing for the other ~2500 products, ordinary orders, cart or checkout | "Don't disturb users" is the acceptance criterion |
| **M0.6** | ~~Packaging~~ | **Dropped.** Ruslan uploads the folder over FTP himself each time (2026-08-07) |
| **M0.7** | ~~Preflight~~ | **Done and run** — see §1 |
| **M0.8** | **🛑 Full code review, fresh session** (D-053) | **The gate on the first upload.** Nothing goes to the live shop before it |

Not blocking, deliberately deferred: the i18n `.pot` file (every customer-facing string is already
written in Lithuanian, so the shop reads correctly with no translation loaded), and the decorative
font set (D-023).

---

## 4. How the plugin actually gets there

**Install through wp-admin → Plugins → Add New → Upload Plugin, with a `.zip`.** Not FTP.

Three reasons this matters more than it sounds:

- **FTP file-by-file upload is not atomic.** A visitor can execute a half-written PHP file, and on
  a live shop that is a white screen for real customers. The zip is unpacked server-side in one
  step.
- **OPcache is full on this host** (33 MB of 33 MB, hit rate 19.5%). Overwriting files under a
  full, saturated cache is exactly where stale-bytecode confusion comes from. A clean
  install/replace through the uploader avoids arguing with it.
- It needs no FTP client at all, which makes the routine update path a two-minute job.

**FTP keeps three jobs**, and they are the ones it is good at:

1. Editing `wp-config.php` (one constant — §M3).
2. Creating the storage directory outside the webroot.
3. **Rescue.** If the plugin ever causes a fatal error, rename
   `wp-content/plugins/ai-cake-topper` to `ai-cake-topper.off` over FTP. WordPress deactivates
   what it cannot find and the shop comes straight back. **This is the rollback for every step
   below** — worth having FileZilla open and connected before starting any of them.

---

## 5. The migration itself

### M1 · Preflight — read-only, ten minutes

1. Upload `tools/host-check.php` to `public_html/`, together with one `.ttf` from the plugin's
   `fonts/` folder so the FreeType render test can run.
2. Open `https://valgomosdekoracijos.lt/host-check.php?token=sJE1SqqPpbsqAjX7HKOjhl-0`.
3. Paste the output back here.
4. **Delete both files.**

It writes one temporary probe file and changes nothing else. It answers all five unknowns from §1.

**Also check, in wp-admin, before going further:** Really Simple Security → Hardening (§2.2), and
take a **full backup** (Installatron, or the host panel) — files *and* database. Restoring is what
makes every following step reversible.

**Stop condition:** if FreeType is missing, the watermark cannot be drawn and that needs a
decision before anything else. If `open_basedir` confines PHP to `public_html`, we take the §2.3
fallback.

### M2 · Install, inert

1. **The code review (D-053) must be done first.** This is the gate, not a formality.
2. Upload `plugin/ai-cake-topper/` over FTP to `wp-content/plugins/`, then activate it in
   wp-admin. Upload before activating, never the other way round.
3. **Verify nothing changed.** Front page, a normal product page, add that product to the cart,
   open the cart and the checkout, open a recent existing order in wp-admin. All must be
   indistinguishable from before.
4. Tools → Site Health → the two AI Cake Topper panels. Read GD, FreeType, memory, storage,
   loopback.

**Nothing can spend money at this point** — no key is configured, and every provider reports
itself unavailable without one. That is the safe state, and it is why activation comes before
configuration.

**Rollback:** deactivate, or rename the folder over FTP. The two new tables (`wp_aicake_designs`,
`wp_aicake_jobs`) are new and empty; nothing existing is altered.

### M3 · Configure

1. **FTP:** create `/home/vaijos/domains/valgomosdekoracijos.lt/aicake-files/`.
2. **FTP:** in `wp-config.php`, above the `/* That's all, stop editing! */` line, add:

   ```php
   define( 'AICAKE_STORAGE_DIR', '/home/vaijos/domains/valgomosdekoracijos.lt/aicake-files' );
   ```

   That is the **only** constant production needs. Keys go in the admin screen (D-050).
3. Re-check Site Health: the storage probe must report a real write into `sessions/YYYY/MM`, not
   just a writable root.
4. **Settings screen → API keys.** Enter the fal and Gemini keys. Replicate is optional and is
   only ever a fallback (D-017).
5. **Settings screen → limits.** These are defaults chosen for a testbed, and production has
   11 133 registered users and public traffic. They need Ruslan's numbers, not mine — see §7.
6. **Test provider screen.** One real generation. Costs about **$0.012**. This proves keys,
   outbound HTTPS, storage and GD in one press.

### M4 · Commerce wiring

1. Create the AI product — simple, price per §7, **catalogue visibility "Hidden"** so it does not
   appear in the shop listing while remaining purchasable by direct link.
2. WC Fields Factory: bind the existing field group to it, and add the AI surcharge field. Field
   keys are random, so the plugin resolves them **by label** at runtime — the labels must match
   exactly what the settings screen shows.
3. Create the wizard page with an **unguessable slug**, containing only `[aicake_wizard]`. Not in
   any menu, `noindex` in All in One SEO, and excluded from search.
4. Walk the wizard as a logged-out visitor in a private window. Do not buy yet.

### M5 · One real order, by Ruslan

Buy one, properly, through the real checkout. Then on the order screen press **Atsisiųsti
spausdinimui**, print it, and measure it. D-039's rule holds on production too: **the physical
print is the authority**, not the arithmetic.

This is the step that has previously found what the assertions could not (D-031), because it runs
without admin privileges.

### M6 · Open it up

Link the page, remove the `noindex`, make the product visible. Then for the first weeks:

- WooCommerce → Status → Logs for the plugin's own logging (`WP_DEBUG_LOG` is off on this host,
  so this is where errors surface).
- The budget guard's daily and monthly ceilings, which have never been exercised against non-zero
  cost.
- Disk, against §2.4.

---

## 6. The theme, and where the visual work lives

The live theme is `valgomos` 2.7.38 — heavily modified and **a separate project with its own
`CLAUDE.md`**, which this project does not touch.

So the split is: **the plugin ships function, the theme ships appearance.** The wizard template is
already overridable at `ai-cake-topper/wizard.php` in the theme, and the plugin's CSS is
deliberately functional only (D-032 / Ruslan, 2026-08-03: don't polish against the testbed theme).

That means the "wizard will be modified a lot" work is mostly theme-side, and what this side owes
it is hooks, classes and a template that can be overridden without forking. Worth agreeing the
list of hooks before M6 rather than after.

---

## 7. Open — Ruslan's calls, not mine

| | |
|---|---|
| **The AI surcharge** | What does an AI-generated image add to the base price? Currently €1.00 on the testbed, which was a placeholder |
| **Base price and the product name** | The live product does not exist yet |
| **Limits** | Free generations per session / per logged-in user, per-IP daily ceiling, and the daily/monthly USD budget. At $0.012 an image these are the difference between a marketing cost and a bill |
| **The wizard page slug** | Needs to be unguessable but yours |
| **Who does the cosmetic pass**, and whether it lands in the theme project (§6) |

---

## 8. Rollback, at a glance

| Step | Undo |
|---|---|
| Any step | Rename `wp-content/plugins/ai-cake-topper` over FTP |
| M2 | Deactivate. Tables stay, empty, harmless |
| M3 | Remove the constant; delete the storage directory |
| M4 | Unpublish the page, trash the product |
| M6 | Unlink the page, restore `noindex` |
| Catastrophe | The M1 backup — files and database |
