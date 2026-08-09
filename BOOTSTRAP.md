# Session bootstrap

**Written 2026-08-09, from an audit of the code rather than of the other documents.**
The minimum to work on this project safely. If you read nothing else, read this.

`STATE.md` is 1600 lines of accreted session narrative and only its **top section** is a statement
of the present. This file is the part that must be true.

---

## 1. The three rules that override everything

1. **🛑 Nothing may be uploaded to the live shop until a full code review has run in a dedicated
   fresh session** (D-053, Ruslan's instruction). ~2500 products, 11 133 users, **no staging
   copy**. The acceptance criterion is his: *"don't disturb users"* — not *does the wizard work*,
   but *does activating this change anything for anyone who never touches it.* Suites passing is
   not the review. `DECISIONS.md` is not the review. The review reads the code.
2. **Production is plain WordPress with no additional services.** PHP + MySQL + what WooCommerce
   already ships. No Redis, no Node, no external render service, no broker, **no Composer at
   runtime**. A design needing more than that is the wrong design.
3. **The PHP worker pool is the scarce resource.** Shared hosting runs 4–8 workers for the whole
   site. Nothing customer-facing may block a worker for seconds.

## 2. What this is

A WooCommerce plugin for `valgomosdekoracijos.lt`, a Lithuanian shop selling edible cake
decorations. A customer designs a decoration in a wizard, buys it, and it is printed on an edible
icing sheet. **All customer-facing UI and text is Lithuanian.**

The whole system in one line: **customer designs in the wizard → orders → Ruslan moves the order
by hand exactly as he always has → he opens the order, presses „Atsisiųsti spausdinimui", and
gets an A4 file rendered on the spot in ~1 s.**

## 3. Where the project is

**Wizard v2 is built and works end to end on the testbed, for all four sources.** Phases 1–7
done; Phase 8 almost entirely cut (D-047, D-048). Migration to production is **paused on purpose**
behind D-053's review.

| Source | State |
|---|---|
| `none` — text only | works |
| `upload` — the customer's photo | works; whole photo, movable selection, live preview |
| `ai` — fal generation | works; the only source that spends money per press |
| `search` — Openverse | works, **off by default**, commercial+modification licences only |

## 4. Hard facts about the pixels — get these wrong and you ruin printed sheets

- **Every print file is a full A4 page**, 2481 × 3508 at 300 DPI, mounted at the same origin the
  proof uses. Printed at **100%**, never "fit to page" (D-070).
- **The picture fills the cut circle.** What the customer approved in the preview is exactly what
  is inside the black line (D-073).
- **There is no bleed.** `FormatCatalogue::BLEED_MM` is `0.0` — the picture stops at the line and
  the page is bare outside it (D-074, Ruslan's instruction). The mechanism is intact; that
  constant is the only number.
- **Nineteen formats**, all derived from `SheetLayout`, never tabulated: A4 whole sheet, circles
  ⌀20→10 cm, cupcakes ⌀4/4.5/5/6 cm, cake pops ⌀2,5/3/3,5 cm.
- **Two masters exist and they are not interchangeable.** A cropped upload arrives already bled;
  everything else is the picture alone. `SourceCatalogue::master_is_bled()` is the only thing that
  knows, and it is a function of the source, never a stored flag.
- **The text layer is drawn by the browser** at exact print size and is **never scaled** by the
  server. A size mismatch is refused, loudly, on purpose.

## 5. Conventions

- WordPress Coding Standards. **Tabs.** Namespaced under `AiCake\`, hand-rolled SPL autoloader.
- Prefix everything: `aicake_`, `AICAKE_`, `AiCake\`.
- Every user-facing string in `__()` with the `ai-cake-topper` text domain, **from the first line
  written**.
- Escape on output, sanitise on input, `$wpdb->prepare()` always.
- API keys resolve **constant first, then the encrypted store** (D-050). Never in `wp_options` in
  plaintext, never logged. `Settings::secret()` is the only seam.
- **Comments explain *why*.** The code already says what. Do not shorten a comment that explains
  why a control exists — several of them are the only record of a bug that reached paper.

## 6. Where things live, and how to run anything

- **Source of truth:** `C:\AI_IMAGE\` (git). All editing happens here.
- **Testbed:** `Z:\ruslan\wordpress-test\` — SMB share of the Docker host. **Deployment target
  only; never edit the plugin there**, the next sync overwrites it.
- `Z:\ruslan\wordpress-test\themes\` is a **different project** with its own `CLAUDE.md`. Not ours.

```bash
powershell -File C:\AI_IMAGE\tools\sync.ps1
```

Checks test the **deployed** copy, so sync first. Copy the tool across, then run it as
`www-data` — **never `--allow-root`** (D-031: root-owned `orders/` dirs make every later real
order fail with „Nepavyko įrašyti spausdinimo failo.", and the gate still passes).

```bash
cp tools/order-check.php Z:/ruslan/wordpress-test/aicake-files/ && ssh ruslan@ruslan-server 'docker exec -u www-data wordpress-test-wordpress-1 wp eval-file /var/lib/aicake/order-check.php --path=/var/www/html'
```

```bash
ssh ruslan@ruslan-server 'docker exec -u www-data wordpress-test-wordpress-1 php /var/www/html/wp-content/plugins/ai-cake-topper/tests/run.php'
```

## 7. The suites — all green, 2026-08-09

`tests/run.php` **446** · `order-check` 65 · `wizard-check` 65 · `wcff-check` 47 ·
`settings-check` 45 · `text-check` 37 · `moderation-check` 34 · `proof-check` 21 ·
`bleed-check` 16 · `rest-check.sh` 16 · `upload-check` 18 · `search-check` 18 ·
`retention-check` 11.

`layer-check.php` is a **report, not a gate**. `crop-check.html` is **vacuous since D-074** — with
no bleed it scores two identical mappings against each other and cannot fail. Do not count it.

## 8. The four rules this project paid to learn

1. **Test logged out, in a real browser.** *"most users are firstly not logged or as guest, they
   create account on checkout only."* Three bugs (D-063, D-066, D-068) were invisible to a
   logged-in tester **and to every committed suite**, because a logged-in page carries a printed
   nonce and the suites run server-side with their own.
2. **A cookie without a nonce is user 0, silently.** Bitten this project three times (D-025,
   D-028, D-063).
3. **Some things are only true on paper.** Twelve green suites said the print geometry was right;
   it was wrong about the size of the page it was drawn on, and only a printed sheet said so
   (D-070). D-040's standard is paper.
4. **When two things are supposed to show the same picture, assert that they do.** D-073 was not a
   print file wrong on its own terms — it was wrong *against the preview the customer had already
   approved*, and no suite compared the pair. The proof had been drawing the right answer all
   along while the print file drew another, and that pair had never been compared either.

## 9. Scope — the rule that keeps being re-learned

**`PLAN.md` describing a workflow is not evidence the shop wants that workflow** (D-047). §10 said
a human must review every image, so a review queue was built; Ruslan does not review orders,
because he already sees every image when he loads the icing sheet and presses print. What that
screen actually was: **a second order process beside the one the shop has used for years.**

**The plugin touches no order status and sends the customer nothing, ever.** Both asserted in
`order-check`, both falsifiable. If you find yourself adding a status, a customer note, or a
screen the shop must visit daily — **ask first**.

Two standing corollaries:

- **Customer-facing text and money are Ruslan's.** `PLAN.md` specifying an email or a price is not
  authorisation to build it.
- **Trust the operator on physical facts.** Do not re-derive printer physics from spec sheets. He
  owns the printer; ⌀20 cm circles that were "impossible" twice are routine for him.

## 10. Waiting on Ruslan

1. **The „Paveikslėlio tipas" field in WC Fields Factory.** D-071 built the machinery; the field is
   admin work. **Until it exists every picture type sells at the base price.**
2. **Every price.** His (D-058).
3. **🔴 A printed sheet.** D-070, D-073 and D-074 all moved what lands on paper and **none of it
   has been on paper**. Print one order file and one proof of the same format and hold them
   together; then cut one circle — with no bleed there is nothing outside the line to absorb a
   wide cut, and that is the one consequence of D-074 no assertion can show him.
4. **Attribution for search results** — most CC licences require crediting the creator; the data is
   stored on every design, the policy is his (D-067).
5. **A look at the format grid** (D-055).
6. **An iPhone.** See risks.
7. **One more error and one modification he mentioned on 2026-08-09 and never described.** Ask.

## 11. Known risks and open items

- **🔴 iOS is completely unmeasured, and it is the majority mobile platform for this shop** — iOS
  16.1% vs Android 11.1%; Mobile Safari beats Chrome Mobile roughly two to one. The wizard asks
  the browser for an 8.3 MP canvas. Android clears 35 MP on a four-year-old mid-range phone; **iOS
  Safari has a hard canvas-area ceiling and its failure mode is silent** — it returns a canvas
  that reads back transparent and `toDataURL()` yields a valid blank PNG. `D-057`'s client probe
  is built for exactly this and **has never been seen returning false on a real device.**
  `tools/phone-canvas-check.html` takes 30 seconds and needs an iPhone.
- **Memory: 80–84 MB per render on top of the request** (D-072, measured per item). Production's
  `memory_limit` is 256M but `ini_set( '512M' )` **sticks** — verified on the live host. The older
  "339 MB peak" figure came from a check rendering two formats in one pass; re-scope M0.3 against
  the real number before doing any work on it.
- **Production has no sodium**, so the openssl branch of the key store is the only one that will
  ever run there (D-052).
- **`WP_DEBUG` is off on production** — plugin errors surface in **WooCommerce → Status → Logs**
  and nowhere else.
- **The testbed's own settings can turn committed gates red.** Throttles and moderation switches
  are shop settings and have been left in odd states by browser testing. **A 429 in any check is
  the throttle, not the thing under test** — this has cost time twice. Read
  `get_option( 'aicake_settings' )` before debugging a suite.
- **Logging is invisible under WP-CLI** — `wc_get_logger()` from `wp eval` reaches no file. Do not
  conclude from silence that nothing ran.

## 12. Reading order after this file

| File | What it gives you |
|---|---|
| `STATE.md`, **top section only** | current phase, blockers, verified environment facts |
| `docs/wizard-v2.md` | the four-source wizard — the design that shipped |
| `docs/pipeline.md` | what runs where and what costs money |
| `DECISIONS.md` | **why.** D-001…D-074. Superseded entries are kept, not marked — the header table names the chains that still govern |
| `docs/migration.md` | going live: production's verified facts and the ordered steps |
| `PLAN.md` | the original design. Authoritative where nothing supersedes it — **check `DECISIONS.md` first** |
| `WORKFLOW.md` | how we work |

`idea.md` is **superseded**. `PLAN.md` §23 lists where it is wrong. Do not follow it.

## 13. Saving state is not optional

Sessions get reset and this project is long. **Nothing important may exist only in chat.**
A decision → append to `DECISIONS.md`. A design change → `PLAN.md`. Progress or a new environment
fact → `STATE.md`. Then commit, small and often, with a message that says *why*.
**Never rewrite git history.**
