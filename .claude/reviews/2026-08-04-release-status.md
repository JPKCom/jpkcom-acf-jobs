# Release status: 1.4.0 is prepared but NOT released

**Date:** 2026-08-04
**Branch:** `abilities-api`, 30 commits ahead of `main`, nothing pushed
**Suites:** 533 assertions green (`test-conventions` 3, `test-jobs-data` 77, `test-abilities` 453)
**Verdict: do not tag.** The acceptance gate found defects that make the release wrong to ship.

---

## What is done

The feature is complete and the version is bumped in all six places with a written changelog.
`CLAUDE.md` carries the "Abilities API" section with twelve documented traps; `README.md` carries the
user-facing section a site owner needs to decide whether to switch the feature off.

Everything the plan asked for was built and verified:

- `includes/jobs-data.php` — the job visibility rule, previously three byte-identical copies, now one.
  The shortcode, the archive and the abilities all call it, and the extraction was proven behaviour-
  neutral by comparing generated SQL, returned post IDs and rendered HTML before and after.
- `includes/abilities.php` — three read-only abilities, registered defensively into the shared
  `jpkcom-content` category.
- The central claim of the design is **measured, not asserted**: with the unformatted read,
  `oembed_cache` stays at 0 across a `get-job` call on a job whose description carries a bare URL;
  flipping one `false` to the two-argument form takes it to 1 and executes the shortcode.
- WordPress 6.9.4 floor: 385 measurements, zero fatals, on core that genuinely has no `try/catch`.

## Why it is not released

The acceptance gate ran 38 agents across five adversarial lenses and handed every finding to a second
agent tasked with disproving it. 25 survived, 8 were refuted. Eleven are Important. Full detail in
`2026-08-04-acceptance-gate-findings.md`.

Four groups block the release. They are grouped by cause rather than by lens, because fixing them one
finding at a time is what made this feature expensive — see "What this cost" below.

### 1. Corrupt stored meta is an uncaught fatal on the declared floor

Three findings, and the most serious. A single `postmeta` row whose serialized value contains a
non-scalar element — what a WPML base64 copy, an importer, a migration or one `UPDATE` produces —
makes `query-jobs` fatal for **every caller with no input at all**, permanently, as soon as that job
is on the requested page. Measured: page 1 answering 200 and page 3 answering 500 on a mixed corpus.
`have_rows()` on corrupt flexible-content meta fatals the same way, and the file's "read unformatted"
discipline gives no protection there, because that rule is about `format_value` while this is the
**load** path — `have_rows()` has no formatted/unformatted argument at all.

This is a direct breach of the hardest rule in the design. On 6.9 there is no `Throwable → WP_Error`
wrapper, so it is a blank 500 with no body rather than an error a client can act on.

### 2. The `visibility` block reports numbers that cannot be true

Two findings. `hidden_expired` counts every job whose `job_expiry_date` is an **empty string** — which
is what ACF stores once the date field has been saved and cleared, i.e. the ordinary case — because
MariaDB casts `''` to a zero date and `'0000-00-00' < today` is true. The same response lists those
jobs. On the floor instance that is 2 of 2: the entire corpus reported as expired and as listed at
once, and `listed_total + hidden_expired` exceeding `published_total`.

Separately, `query-jobs`' `visibility` block is site-wide while its schema describes it as "excluded
from **this answer**". A model adds the numbers it was handed and reports a job count that does not
exist, and the error grows with the rest of the site.

### 3. The REST surface contradicts its own schema

Four findings. `include_closed` — the only switch that changes which jobs come back — **cannot be sent
over REST at all**: the route is GET-only because `readonly` is true, GET cannot carry a JSON boolean,
and the callback demands a strict bool. An agent reading the schema and sending the declared default
gets a 400 whose message names a form it cannot produce, so its correction loop cannot terminate.

`list-filters` ships `"properties": []` — invalid JSON Schema — to both consumers raw. The plugin's own
`jpkcom_acf_jobs_ability_json_object()` helper exists for exactly this hazard and was applied to
`default`, the one key core already repairs, and not to `properties`, the one key only the plugin can.

`search` is documented as covering "job content", but it is `WP_Query`'s `s`, which reads
`post_title` / `post_excerpt` / `post_content` — and every field this plugin holds job text in lives in
ACF meta, with `post_content` empty on its own fixtures. And an unrecognised filter axis is swallowed
with no signal: same 200, same total, `unknown: {}`.

### 4. `pre_get_posts` defeats the clause guarantee

One finding. A site callback doing `$q->set( 'meta_query', [ … ] )` without an `is_main_query()` guard
removes every clause the ability built, because `WP_Query::set()` replaces rather than merges. The
response is HTTP 200 with `filters.job_type` still claiming the filter — verbatim the defect the
feature exists to prevent. Both "read what came back" checks pass by construction.

The refuter corrected the reachability argument: the plugin's own `archive.php` handler **is** guarded,
so a stock install is unaffected. But the additive idiom in any third-party callback produces identical
corruption, so the precondition is a benign, common site callback rather than a misbehaving one.

## What this cost, and what to do differently

Most findings this session are defects in code written **today**, not pre-existing plugin bugs. The
pre-existing ones were nearly all captured by the initial audit and written into the spec.

The expensive part was orchestration, not implementation. The query post-condition was strengthened
**five times** — presence, then operator, then relation, then value, then identity — because each fix
brief said "fix this finding" instead of "which class of inputs leads here?". The fifth attempt, which
asked the class question first, deleted the enumeration instead of extending it and closed the class.
Reading `WP_Query::get_posts()` for every var that assigns over another turned up a third alias within
a minute, which is the proof the enumeration would never have finished.

Second: scope grew in flight. The spec asked for two things after the site filter — re-assert
`post_type` and `post_status`. What exists is a clause-identity mechanism guarding against callbacks
the site owner wrote. Nobody decided that; it accreted one review at a time.

**For the remaining plugins in this rollout:**

1. Keep the defensive surface to what the spec names. If a guard is needed, its shape belongs in the
   spec, not in the third fix round.
2. Ask the class question in the **first** fix brief, always.
3. Fewer, larger review passes — each round costs a dispatch plus a review.
4. `jpkcom-acf-references`, `jpkcom-gutenberg-img-alt`, `jpkcom-simple-lang` and `jpkcom-allow-blocks`
   are separate repositories with no shared files. They can run genuinely in parallel; this plugin's
   tasks could not, because every one edited the same two files.
5. When several agents share a DDEV instance, say who owns which. Two of them knocked
   `/home/jpk/ddev/test2` off the 6.9.4 floor mid-run, and one lens spent its budget measuring 7.0.2
   while believing it was on the floor.

## Environment state, left clean

- `/home/jpk/ddev/posts` — WP 7.0.2, jobs 184-189 only, no `oembed_cache` rows, job 184 with empty
  `post_content` and `post_modified` `2026-07-28 14:05:49`. No application passwords left behind.
- `/home/jpk/ddev/test2` — restored to **WP 6.9.4** with `WP_AUTO_UPDATE_CORE false` and
  `AUTOMATIC_UPDATER_DISABLED true`. A DDEV snapshot `pre-69-downgrade` exists. It was knocked to 7.0.2
  twice during this session and restored twice; check its version before trusting any floor measurement.
- `/home/jpk/ddev/jobs` — untouched apart from plugin syncs.

## Resuming

The full finding list with reproductions is `2026-08-04-acceptance-gate-findings.md` beside this file.
The SDD ledger, briefs and per-task reports are under `.superpowers/sdd/` — **gitignored**, so they do
not survive `git clean`; the two files in `.claude/reviews/` are the durable record.

Fix the four groups above as **four** consolidated rounds, one per cause, not as twenty-five. Then
re-run the acceptance gate before tagging.
