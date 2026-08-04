# Design: Abilities API integration for JPKCom ACF Jobs

**Date:** 2026-08-04
**Status:** Approved for planning
**Target version:** 1.4.0 (current: 1.3.11)
**Scope:** `jpkcom-acf-jobs` only. Inherits the pattern established by `jpkcom-post-filter` 1.3.0; `jpkcom-acf-references` inherits from here.

> Spec location note: the Superpowers default is `docs/superpowers/specs/`, but `docs/` is listed in
> `.gitignore:68` because it is the phpDocumentor output directory the release workflow deploys to
> `gh-pages`. Specs therefore live in `.claude/specs/`, which is tracked by git and excluded from the
> release ZIP (`.github/workflows/release.yml:107`).

---

## 1. Goal

Expose the plugin's job data as three read-only WordPress Abilities, so that MCP clients, REST
automation and the WordPress AI client can discover which job types, companies, locations and
attributes a site offers, run filtered queries, and fetch a single job record — without scraping HTML
and without guessing field values.

### Non-goals

- **No write abilities.** Nothing in this plugin has a safe writable surface at `read` level.
- **No new REST routes of our own.** Core's `wp-abilities/v1` surface is the transport.
- **No changes to rendering.** Templates, partials and the two shortcodes keep their current output.
- **No re-export of Schema.org JSON-LD.** `jpkcom_acf_jobs_get_schema_job_posting()` returns a string
  and stays as it is; the abilities return structured data instead.
- **No fixing of the pre-existing defects found during exploration** beyond what the abilities
  themselves must avoid. They are listed in §11 so they are not lost.

---

## 2. Decisions taken

| Question | Decision |
|---|---|
| Consumers | MCP clients, REST automation, WordPress AI/editor client |
| Write scope | Read-only |
| Granularity | Three abilities: `list-filters`, `query-jobs`, `get-job` |
| String language | `__()` with English source text and the `jpkcom-acf-jobs` text domain |
| Category | `jpkcom-content`, shared with `jpkcom-post-filter`, registered defensively |
| Exposure | On by default (REST + MCP), disableable via constant and filters |
| Extraction depth | Shared reader **and** shared query builder; all three query call sites converted |
| Jobs hidden by the visibility rule | Mirrored exactly, and the shortfall is reported explicitly |
| `job-attribute` filtering | Yes, via `tax_query` |
| Detail fields | Emitted only for jobs whose detail page would render for an anonymous visitor (§5.3) |

---

## 3. Architecture

### 3.1 Two new files

**`includes/jobs-data.php`** — jobs as data. Loaded through `jpkcom_acfjobs_locate_file()` like every
other include, positioned **before** `includes/shortcodes.php` and before `includes/archive.php`,
both of which become consumers.

**`includes/abilities.php`** — registration and callbacks. Loaded after `jobs-data.php`.

Both files follow `includes/schema.php` as their template: file docblock ending in
`@package JPKCom_ACF_Jobs` and `@since 1.4.0`, `declare(strict_types=1);`,
`if ( ! defined( constant_name: 'ABSPATH' ) ) { exit; }`, `@since` plus `@param`/`@return` on every
function. phpDocumentor publishes `includes/*.php` to
`https://jpkcom.github.io/jpkcom-acf-jobs/docs/`, so these docblocks are public API documentation.

**Naming, which is asymmetric in this repo and must stay that way:**

- New **functions** and **filters** use `jpkcom_acf_jobs_` (12 of 12 prefixed definitions in
  `includes/` use it).
- New **constants** use `JPKCOM_ACFJOBS_` — no underscore between `acf` and `jobs` — matching the four
  existing constants in `jpkcom-acf-jobs.php:34-48`. A `JPKCOM_ACF_JOBS_…` constant would be the first
  of its kind and would break the greppability the release checklist depends on.
- `jpkcom_acfjobs_` is frozen for `jpkcom_acfjobs_locate_file()` / `_textdomain()` /
  `jpkcom_acfjobs_file_paths` and must not be extended.

**Indentation is per file in this repo, not per directory.** `includes/` is 11 files on 4 spaces
against 3 on tabs, and both files the code is lifted out of (`shortcodes.php`, `schema.php`) are
4-space files. Both new `includes/` files therefore use **4 spaces**; a new `tests/test-abilities.php`
uses **tabs**, matching `tests/test-conventions.php`. The extraction commit must be a pure move — no
reindentation, no reformatting — so a reviewer can see that the rule did not change.

### 3.2 The shared query builder

```php
jpkcom_acf_jobs_build_job_query_args( array $args ): array
```

Returns `WP_Query` arguments. It encodes the visibility rule and nothing else about presentation.

**Callers, all three converted in this release:**

1. `includes/shortcodes.php:118-238` — the `[jpkcom_acf_jobs_list]` shortcode.
2. `includes/archive.php:39-77` — the `pre_get_posts` handler for `/jobs/`.
3. `includes/abilities.php` — `query-jobs`, and `get-job` as a precondition test.

`archive.php:43-68` carries a **byte-identical copy** of the shortcode's `meta_query` plus the same
`meta_key`/`orderby`. It differs only in what surrounds it: it sets no `post_status` (a front-end main
query defers to core visibility) and hardcodes `date => 'DESC'`. So `post_status` and the date
direction are caller-supplied parameters of the builder, and the rule itself is shared.

**The `meta_query` is reproduced verbatim and carries a DO-NOT-TOUCH note.** Two plausible cleanups
each silently shrink the result set with no error:

- Deleting the `NOT EXISTS` clause as "redundant next to `value => ''`" — `WP_Meta_Query` rewrites
  every `INNER JOIN` to `LEFT JOIN` as soon as one `NOT EXISTS` clause is present. Without it every
  join reverts to `INNER` and every job that has no `job_expiry_date` row at all disappears.
- Rewriting the date comparison in PHP. The SQL side compares the **raw** meta through
  `type => 'DATE'`, and ACF stores dates as `Ymd`. Measured on the reference install (WP 7.0.2):
  `CAST('20251130' AS DATE)` yields `'2025-11-30'`, so the comparison against `current_time( 'Y-m-d' )`
  is correct as written. A PHP-side string comparison against the raw value would not be.

**`current_time( 'Y-m-d' )` is the only permitted source of "today"** anywhere in this feature —
copy `includes/shortcodes.php:143` verbatim. WordPress pins the PHP timezone to UTC in
`wp-settings.php`, so `date( 'Y-m-d' )` **and** `gmdate( 'Y-m-d' )` return the UTC day and drop jobs
expiring today for the length of the offset after local midnight. That is the exact 1.3.7 regression.

**The builder never defaults to `-1`.** `shortcodes.php:121` does (`$limit > 0 ? $limit : -1`), and
lifting that verbatim would give any subscriber an unbounded query across three unindexable `LIKE`
scans. The builder **requires** an explicit positive `posts_per_page` from its caller; the shortcode
passes its own `-1` explicitly at its call site so its behaviour is unchanged.

### 3.3 Filters: the existing one stays where it is

`jpkcom_acf_jobs_list_query_args` is documented in `CLAUDE.md:176` as a shortcode extension point and
receives the **shortcode attributes** as its second argument. `archive.php` never fires it either.

- `jpkcom_acf_jobs_build_job_query_args()` returns **unfiltered** args.
- `includes/shortcodes.php` keeps applying `jpkcom_acf_jobs_list_query_args` at its own call site,
  with an unchanged signature, after calling the builder.
- The abilities apply their own `jpkcom_acf_jobs_ability_query_args( array $args, string $ability )`
  and then **re-assert** `post_type === 'job'` and `post_status === 'publish'` on the result.

Reason: an existing site filter written for a curated shortcode page may legitimately set
`post_status => 'any'` or replace `meta_query` wholesale. Carried into the builder, that silently
widens what any logged-in subscriber sees over MCP.

### 3.4 The reader

```php
jpkcom_acf_jobs_get_job_data( int $post_id, bool $full = false ): array   // [] when not readable
```

**Its first act is the gate,** because no field reader in this plugin has one today. `schema.php:48-52`
checks the post type; nothing anywhere checks `post_status`. The existing readers are safe only
because `shortcodes.php:120` hands them posts a `post_type => 'job'` + `post_status => 'publish'`
query already filtered. A function taking a bare int inherits none of that: called with a draft or
private ID, ACF returns the meta regardless of status; called with any other post type, it still
returns `get_the_title()` — a title oracle over every private post and page, callable by any
subscriber.

The gate is: `get_post()` resolves, `post_type === 'job'`, `post_status === 'publish'`,
`post_password === ''`. Anything else returns `[]`. Password-protected jobs are excluded because
`get_the_title()` prepends "Protected:" while ACF hands out the full salary and address — a
self-contradicting record. `has_password => false` is set in the builder for the same reason.

**Four rules for every value it reads:**

1. **Read long-form fields unformatted.** See §10 — this is the load-bearing rule of the design.
2. **Pass `$post_id` to every `get_field()` call.** The single-job partials all read the global
   `$post`, which does not exist in an ability callback. `templates/shortcodes/list.php:37-117` is the
   only existing field reader that passes an ID, which makes it — not the partials — the reference for
   the compact record's semantics.
3. **Never emit a `WP_Post`, never call `get_fields()`.** See §10.
4. **Guard every dereference.** `$x instanceof WP_Post` before `->ID`, `$term instanceof WP_Term &&
   $term->taxonomy === 'job-attribute'` before `->name`, `is_array()` before any index. Nothing may
   throw: on the WP 6.9 floor a `Throwable` escaping an ability callback is an **uncaught fatal**.

### 3.5 Field contract

| Field | `get_field()` returns | Emitted as |
|---|---|---|
| `job_type` | `[ {value,label}, … ]` | `[ {value,label} ]`; **only `value` is accepted as filter input** |
| `job_work_type` | one `{value,label}` | `{value,label}` or `null` |
| `job_company` / `job_location` | array of `WP_Post`, **status `any`** | `[ {id,title} ]`, non-published dropped, `instanceof` checked |
| `job_location_*` address | strings; `job_location_zip` is a **number** field | strings, unchanged — never cast, `01067` must not become `1067` |
| `job_base_salary_group` | group array; currency/period are `{value,label}` | `{amount: float, currency: "EUR", period: "MONTH"}` |
| `job_attribute` | `wp_get_object_terms()`, **not the meta** | `[ {term_id,slug,name} ]`, `slug` is the stable key |
| `job_expiry_date` | `Y-m-d` string (raw storage is `Ymd`) | `Y-m-d` or `null`; never through `date( …, strtotime( … ) )` |
| `job_featured` / `job_closed` | `'0'`/`'1'` | booleans |
| `job_short_description` | textarea with `new_lines => 'br'`, i.e. **HTML** | plain text (`<br />` → newline, then `wp_strip_all_tags`) |
| `job_company_logo` and images | ~30-key attachment array or `false` | one URL string from `wp_get_attachment_image_src( $id, 'jpkcom-acf-job-logo' )`, else `null` |
| `job_application_button` | `{title,url,target}` | `{title,url}`, only when `job_application_show_button` is on |
| `job_application_shortcode` | raw string naming internal form IDs | **never emitted** |
| `job_layout_content` | unbounded flexible content, layout-dependent keys | `get-job` only, `[ {layout, text, image} ]` driven by `acf_fc_layout`, at most **20** rows with `layout_rows_truncated: true` beyond |
| `job_url` | `{title,url,target}` or `''` | see §5.3 |

**Labels are locale-dependent and are not filter input.** The source strings are German
(`Vollzeit`, …) and `languages/` carries de_DE, de_DE_formal, fr_FR, it_IT, es_ES, hu_HU, pl_PL. A
client that reads a label off `get-job` and feeds it back to `query-jobs` produces
`meta LIKE '"Vollzeit"'` and zero results. `OTHER` is worth calling out separately: on this site it
means *Ausbildung* (apprenticeship), not "other".

**Conditional fields keep their meta after the toggle is switched off.** `job_application_description`,
`job_application_button` and `job_application_shortcode` are gated by their controlling `true_false`
field in the templates. The reader applies the same gate — it is a disclosure control, not cosmetics:
the stored value is typically an internal ATS link or a recruiting mailto.

**Both shapes must be tolerated.** The file-override system lets a theme replace
`acf-field_groups.php` wholesale; for an unregistered field name ACF falls back to the raw meta, so
`job_type` would arrive as `['FULL_TIME']` and `job_company` as `['22']`. The normalisation the
templates already do is the contract, not defensive noise. Every property in the output schema is
therefore optional.

---

## 4. Registration

```php
add_action( 'wp_abilities_api_categories_init', 'jpkcom_acf_jobs_register_ability_category' );
add_action( 'wp_abilities_api_init',            'jpkcom_acf_jobs_register_abilities' );
```

Both return early unless **all three** hold:

1. `function_exists( 'wp_register_ability' )` — defence in depth on the 6.9 floor.
2. `JPKCOM_ACFJOBS_ABILITIES` — the kill switch.
3. `function_exists( 'get_field' )` — ACF Pro present.

The third is not theoretical. `Requires Plugins` only blocks *activation*: core does not block
deactivating a dependency that has active dependents — `$has_dependents` only disables the Delete link
and the bulk checkbox, and `wp-admin/plugins.php` runs no dependency check on `action=deactivate`. So
a one-click Deactivate is enough. Without ACF the first `get_field()` is
`Error: Call to undefined function` — an uncaught fatal in a REST request on the floor.

**A raw-meta fallback is forbidden.** `acf_add_local_field_group()` never ran either, so `list-filters`
would have no choices and every value would arrive in its serialized shape — a silent output-schema
break instead of an error. Every callback re-checks `function_exists( 'get_field' )` at call time and
returns `WP_Error` if it is gone.

Category registration is defensive, because `jpkcom-post-filter` registers the same slug and
categories are global and first-wins with a silent `null` for the loser:

```php
if ( ! wp_has_ability_category( 'jpkcom-content' ) ) { wp_register_ability_category( 'jpkcom-content', [ … ] ); }
```

This is testable on a real install: `jpkcom-post-filter` 1.3.0 is active on `/home/jpk/ddev/jobs`.

`jpkcom_acf_jobs_get_ability_definitions(): array` is pure — no registry access, no WordPress state
beyond `__()` and the `jpkcom_acf_jobs_ability_meta` filter. That is what makes §8.1 possible.
`wp_register_ability()` returns `null` on **every** failure path and reports only through
`_doing_it_wrong()`, silent in production; the return value is checked anyway.

---

## 5. The three abilities

All three: category `jpkcom-content`, `permission_callback` = `current_user_can( 'read' )` (filterable),
annotations `readonly: true, destructive: false, idempotent: true` set explicitly — they default to
`null` and the REST run controller derives the HTTP verb from them, so an ability without annotations
is POST-only.

All three pin `'post_status' => 'publish'` **explicitly and unconditionally**. The archive's main query
deliberately does not, because core adds `private` for callers holding `read_private_posts`. The
abilities' results are deliberately independent of the caller's capabilities, so two callers never see
different "public" job lists and the answer stays cacheable.

### 5.1 `jpkcom-acf-jobs/list-filters`

Tells the caller which values `query-jobs` accepts.

```
{ job_types:  [ {value, label} ],          // from the registered choices, not from stored data
  companies:  [ {id, name, count?} ],
  locations:  [ {id, name, place, count?} ],
  attributes: [ {term_id, slug, name, count?} ],
  counts_omitted: bool,
  vocabulary_truncated: bool,
  language:   string,
  visibility: { published_total, listed_total, hidden_missing_featured, hidden_expired } }
```

- **Job types come from the field definition** (`acf_get_field( 'field_68de7a25cd78d' )['choices']`),
  never from scanning stored values, so the enum is complete even when a type is currently unused.
- **Attributes come from `get_terms( [ 'taxonomy' => 'job-attribute', 'hide_empty' => false ] )`** —
  the same source and the same `hide_empty` as `[jpkcom_acf_jobs_attributes]`, so the ability's menu is
  not shorter than the page's. The result is checked with `is_wp_error()` before iteration: `get_terms()`
  returns `WP_Error` for an unregistered taxonomy, and iterating that is a fatal on the floor.
- **The taxonomy slug is `job-attribute` with a hyphen.** The ACF field is `job_attribute` with an
  underscore. This confusion already shipped once (fixed in 1.3.8) and the existing guard cannot see
  the `get_terms( [ 'taxonomy' => … ] )` shape — see §8.1.
- **Counts and the visibility block come from one pass**, not from one query per value: fetch the
  visible job IDs (`fields => 'ids'`), prime the meta and term caches, tally in PHP. Above **500**
  visible jobs the counts are omitted and `counts_omitted` is `true` — no silent truncation.
- **The lists themselves are never derived from that pass.** `companies` and `locations` come from all
  published `job_company` / `job_location` posts, and `attributes` from `get_terms( hide_empty => false )`
  — the full vocabulary in all three cases, the same semantics the `[jpkcom_acf_jobs_attributes]`
  shortcode already has. Only the `count` values depend on the pass. Deriving the lists from the pass
  would make their contents depend on the corpus size, so a client would see a different menu on a large
  site than on a small one. Those vocabulary queries are themselves bounded, and they fetch one record
  past the cap so the truncation is **detectable**: `vocabulary_truncated` reports it. A bounded query
  that cannot tell whether it truncated is the silent cap this design rejects everywhere else.
- `hidden_missing_featured` needs its own query, because **two independent causes** exclude a job with
  no `job_featured` row: the `EXISTS` clause *and* `meta_key => 'job_featured'` for the ordering, whose
  `postmeta.meta_key = 'job_featured'` condition lands in the `WHERE` clause. Verified in the generated
  SQL on WP 7.0.2. Dropping one of the two changes nothing.

### 5.2 `jpkcom-acf-jobs/query-jobs`

**Input** — every parameter optional, plus a **top-level** `default` as a sibling of `type` and
`properties`, run through the same empty-map-to-object helper as the outputs:

```
{ job_type:  [enum of the 8 registered values],   // max 8 values
  company:   [integer], location: [integer],      // max 20 values each
  attribute: [string slug],                       // max 20 values
  search:    string,
  include_closed: boolean = true,
  page:      integer = 1,  minimum 1,
  per_page:  integer = 10, minimum 1, maximum 50,
  order:     "ASC" | "DESC" = "DESC" }
```

`include_closed` defaults to **true** because that is what the site does — the archive lists filled
positions and marks them. The ability description says so in words, so a model does not have to infer
it from a boolean.

**Output**

```
{ filters:  object,   // echoed back normalised
  unknown:  object,   // per axis: well-formed values that matched nothing
  total, page, per_page, total_pages,
  archive_url: string,           // withheld when the archive is disabled
  visibility: { hidden_missing_featured, hidden_expired },
  language: string,              // resolved language code
  jobs: [ compact record ] }
```

**Compact record:** `{ id, title, url, redirects_externally, date, is_featured, is_closed, is_expired,
summary, job_types: [{value,label}], work_type, companies: [{id,name}],
locations: [{id,name,place}], attributes: [{slug,name}], expiry_date }`.

Deliberately **not** in the compact record: `job_layout_content` (unbounded flexible content, one full
wysiwyg plus one attachment array per row), images, the full postal address, salary, and the
application block. Those are `get-job`'s job.

**`is_closed` is required, not optional.** `job_closed` ("Position vergeben?") is read by no query in
the plugin — not the shortcode's `meta_query`, not the archive's `pre_get_posts`, not any redirect —
and the shortcode template does not render it at all. A record without it lets an AI client tell a user
to apply for a filled position, from the single input `{"per_page": 20}`, with no way to detect it.

**The load-bearing guard: a filter that normalises to nothing is an error.** The shortcode builds each
clause as

```php
$ids = array_filter( array_map( 'absint', explode( ',', $company_csv ) ) );
if ( ! empty( $ids ) ) { $meta_query[] = $company_clauses; }
```

so `company: ["acme"]` → `absint` → `0` → filtered out → **the clause is never added** → the response
contains every job. That is the same class that returned 19 of 19 posts as a "filtered" answer in
`jpkcom-post-filter`. A requested filter that survives normalisation empty is a `WP_Error` with
`status: 400` naming the valid form. A well-formed value that matches nothing (`company: [999]`) is
**not** an error — it lands in `unknown` and the empty result is honest.

`job_type` is additionally declared as a JSON-Schema `enum` of the eight registered values, so
`"Vollzeit"` or `"FULLTIME"` is rejected before any query runs rather than producing an unindexable
scan that answers "no full-time jobs". `company` and `location` IDs are verified with `get_post_type()`
before a clause is built. Each axis accepts at most **20** values (`job_type` at most 8, since that is
the whole vocabulary), and exceeding the cap is a `400`, not a silent truncation — each extra value is
one more unindexable `LIKE` scan.

**`attribute` is a `tax_query` on `job-attribute`, never a meta `LIKE`.** `save_terms => 1` and
`load_terms => 1` (`acf-field_groups.php:639-641`) mean ACF discards the stored meta and returns
`wp_get_object_terms()`. The postmeta copy is write-only from a reader's point of view — which is
exactly what `tools/check-term-sync.php` exists to detect. Copying the `LIKE '"VALUE"'` pattern would
filter on a store the site never reads: a job whose meta still lists a removed term would be returned
while its page shows no such attribute.

**A page past the last one.** `WP_Query::set_found_posts()` returns early when `posts` is empty, so
`found_posts` and `max_num_pages` stay 0 — a self-contradicting response a model reads as an empty
corpus. On that path only, re-run for page 1 and take the totals from there, keeping `jobs` empty and
echoing the requested page back. The guard is `$query->posts === [] && $page > 1`; dropping the first
half doubles the query count of every paginated call.

**`archive_url` is withheld** when `get_option( 'jpkcom_acf_job_disable_archive' )` is truthy. The
archive redirect target is sanitised with `esc_url_raw()` only and dispatched with `wp_redirect()`, not
`wp_safe_redirect()` — any host is allowed by design. Emitting the archive link there would hand an MCP
client a 307 to an arbitrary third-party domain, and would silently reverse the site owner's explicit
"do not publish a job list" setting.

**No company or location permalinks anywhere.** Both post types are registered `public => false`
(`acf-post_types.php:70`, `:117`) and `redirects.php:98-120` 302s anyone without `edit_post` away from
their singles. A link that always bounces is worse than no link.

### 5.3 `jpkcom-acf-jobs/get-job`

**Input:** `{ id: integer }`, required.

**Output:** the compact record plus `detail` — full postal addresses, salary, work type, attributes
with descriptions, application description and button, company logo URL, normalised
`job_layout_content` — **and** `listed: bool` with `listed_reason` when false.

**The detail-page rule.** `detail` is emitted only for a job whose detail page would actually render
for an anonymous visitor. Otherwise the record is the compact one plus the reason. Three states
suppress it, all measured in `includes/redirects.php`:

| State | Mechanism |
|---|---|
| `job_url` is set | `redirects.php:32-86` 307s every non-`manage_options` caller to that target before `single-job.php` ever runs |
| expired | `redirects.php:132-173` 307s every non-`edit_post` caller to the archive |
| password-protected | excluded by the reader's gate (§3.4) |

This is what makes `read` defensible. Address, salary, attributes and application data are public only
*as a side effect of a job's detail page rendering*; for these three states no such page exists, so
emitting them would publish data the site has deliberately never shown. The compact record stays
available so an agent can still answer "why does job 42 not appear in any list?" — which is what
`get-job` is for.

`get-job` does **not** apply the `job_featured`/expiry predicate as an existence test: a job that
`query-jobs` cannot list is still resolvable, with `listed: false` and the reason. What it does apply
is `post_type`, `post_status` and the password gate, and it returns the **same** answer for "does not
exist" and "not readable" so it cannot be used to probe which IDs exist.

`url` is the effective destination — `job_url['url']` when set, otherwise the permalink — accompanied
by `redirects_externally`. `job_url` is `''` or `false` when unset, never `null`, so `??` does not
catch it.

---

## 6. Error handling

| Condition | Behaviour |
|---|---|
| A requested filter normalises to nothing | `WP_Error`, **`status: 400`**, names the valid form |
| `job_type` outside the eight registered values | Rejected by the schema `enum` before the query runs |
| More filter values than the per-axis cap | `WP_Error`, `status: 400` — never a silent truncation |
| Well-formed value with no match | Not an error; listed in `unknown` |
| `per_page` outside 1–50 | Clamped. `-1` is never produced |
| `page` past the last one | Totals recovered from a page-1 re-run; `jobs` empty |
| `get-job` id that is not a published, unprotected `job` | `WP_Error`, `status: 404`, identical for "absent" and "not readable" |
| ACF absent at call time | `WP_Error`. Never a raw-meta fallback |
| `get_terms()` returns `WP_Error` | Empty list, logged; never iterated |
| Anything internal | `WP_Error`. **Never an exception** |

**The 400 is not cosmetic.** The REST run controller returns the `WP_Error` verbatim and
`rest_ensure_response()` defaults to **500** without `data['status']`. These messages exist to let an
agent self-correct in one turn; a 5xx tells it "transient server fault, retry unchanged" — the exact
opposite instruction.

**Per-property defaults are applied in the callbacks.** Core applies only a top-level `default`, and
only when the input is exactly `null`. Without that top-level default, `execute( null )` — calling the
ability with no arguments, the most obvious call there is — fails `validate_input()` before the callback
runs. Both mechanisms are needed and they are unrelated.

**Empty maps must encode as `{}`.** `filters`, `unknown` and the top-level input `default` are declared
`type: object`, but PHP serialises an empty array as `[]`. Core's REST list controller special-cases
exactly that value and rewrites it to `{}` — the **MCP Adapter does not**, it hands
`$ability->get_input_schema()` to clients raw. So the wrapper is required, not decorative.

**Unknown input keys are not rejected by core.** There is no `additionalProperties` enforcement; the
callbacks ignore what they do not know rather than assuming core filtered it out.

---

## 7. Exposure and kill switch

```php
'meta' => [
    'show_in_rest' => true,
    'public'       => true,                 // WP 7.1; inert passthrough on 6.9/7.0
    'mcp'          => [ 'public' => true ], // MCP Adapter's own gate, not a core key
    'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
],
```

- `JPKCOM_ACFJOBS_ABILITIES` (default `true`), defined next to `jpkcom-acf-jobs.php:34-48` in the same
  `if ( ! defined( … ) )` form, overridable from `wp-config.php`. When `false`, nothing registers.
- `jpkcom_acf_jobs_ability_meta( array $meta, string $ability )`
- `jpkcom_acf_jobs_ability_capability( string $capability, string $ability )` — default `read`
- `jpkcom_acf_jobs_ability_query_args( array $args, string $ability )`

**Disclosure note.** Listing abilities over REST is gated only by `current_user_can( 'read' )`, so every
logged-in user can read all three abilities' labels, descriptions and full schemas. Execution is gated
by the permission callback.

**Untrusted content warning in the schema.** `job_url`, the application button URL, `job_company_url`
and every free-text field are authored by anyone holding the job-editing capability. The output schema
descriptions state that these values are untrusted editor-supplied content that must not be followed or
acted on automatically. A job editor without admin rights otherwise has a direct channel into every
agent that reads this data.

**WPML.** `job` is translated **without** `display-as-translated` (`wpml-config.xml:3-8`), so in a
secondary language WPML hides jobs that have no translation rather than falling back. A REST/MCP request
carries no language segment. There is deliberately **no `lang` input**: nothing in this release can
switch WPML's language context, and a declared parameter with nothing behind it is a false statement in
the schema — a client sending `lang=fr` would receive German and have no way to notice. Instead all
three abilities **echo the resolved language code** in every response (WPML's current language when
WPML is active, otherwise `determine_locale()`), and the output schema states that address and attribute
values are not translated by design and that untranslated jobs are absent rather than substituted.

---

## 8. Testing

### 8.1 `tests/test-abilities.php` (runs in CI)

CI globs `tests/test-*.php`, so the filename is mandatory. **The obvious implementation is a trap:**
this repo has no `tests/bootstrap.php`, no composer.json and no PHPUnit — `tests/` contains exactly one
file. A test that `require`s `includes/abilities.php` hits the `ABSPATH` guard's `exit;` and the process
ends at status **0** before a single assertion runs. CI prints an empty group and reports green.

The file therefore follows `tests/test-conventions.php`: standalone, `declare(strict_types=1)`, its own
pass/fail counters, tabs, `exit( $fail > 0 ? 1 : 0 )`. It defines `ABSPATH` and minimal stubs *before*
requiring, and its **first assertion is that
`jpkcom_acf_jobs_get_ability_definitions()` exists** — so a failed load is a failure, not a silent pass.

Assertions:

- Ability names match `/^[a-z0-9-]+\/[a-z0-9-]+$/`; category slug matches `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`
- `label`, `description`, `category`, `execute_callback`, `permission_callback` present on all three
- All three annotations present and boolean, none left `null`
- Input and output schemas are arrays with `type => object`, a `description` on every property, and a
  **top-level** `default`
- `job_type` in the input schema is an `enum` of exactly the eight registered values
- Source guards, in `forbid()` style: no `get_field(` with only two arguments in the ability path (the
  third must be an explicit `false` for long-form fields); no `get_fields(`; no
  `wp_register_ability(` without an adjacent `permission_callback`; no `return true;` inside a
  permission callback body
- Regression: a filter value that normalises to nothing yields a `WP_Error`, not a silent pass
- Regression: `per_page` is clamped and never becomes `-1`

**Three existing guards must be widened before the feature lands**, because each has a hole this
feature would walk straight into:

1. `taxonomy_slugs_exist()` (`tests/test-conventions.php:132-136`) recognises only `get_term_by()`'s 3rd
   and `wp_get_object_terms()`'s 2nd argument. `get_terms( [ 'taxonomy' => … ] )` — the call
   `list-filters` needs — is invisible to it, and so is the plugin's own existing literal at
   `includes/shortcodes.php:421`.
2. The date guard (`:79-86`) matches one exact spelling. `gmdate( 'Y-m-d' )` and `date( 'Y-m-d', $ts )`
   both evade it and both have the bug.
3. `forbid()` scans `includes/` only. Both new files live there, which is a requirement, not a
   coincidence.

**A CI trap to design around:** the named-argument scanner (`.github/workflows/ci.yml:95-105`) cannot
distinguish a named argument from a ternary colon, because `null`, `true`, `false` and constants all
tokenise as `T_STRING`. Any ternary with a bare-identifier branch sitting directly inside an *internal*
PHP function call fails the build — and `is_scalar( $v ) ? $v : null` is the natural idiom for exactly
this kind of defensive code. Assign the ternary to a variable first, or wrap it in parentheses.

### 8.2 Runtime verification (manual, not in CI)

`wp ability` needs WP-CLI ≥ 2.13; DDEV ships 2.12, so use `ddev wp eval-file <file>.php` with the file
placed inside the DDEV project root. All three instances hold real directories, not symlinks — every
change needs a copy step.

| Instance | WP | ACF Pro | Rank Math (MCP) | Role |
|---|---|---|---|---|
| `/home/jpk/ddev/posts` | 7.0.2 | 6.8.6 | 1.0.275 | Main run: REST **and** MCP surface, 6 published jobs |
| `/home/jpk/ddev/jobs` | 7.0.2 | 6.8.6 | – | Category collision (post-filter 1.3.0 active); visibility rule |
| `/home/jpk/ddev/test2` | **6.9.4** | 6.7.1 | – | The declared floor: registers and executes without a fatal |

**Test data to seed** (the existing six jobs cover only the happy path — same company, same location,
no expiry, none closed): a job with **no** `job_featured` row, an expired job, a job with
`job_closed = 1`, a job with several `job_type` values, a second company and a second location, a job
with a `job_url`, and a job whose description contains a shortcode **and** a bare URL on its own line
— that last one is the only way to prove §10 empirically.

**Checks that matter:**

1. Filtering **narrows**. Compare the filtered `total` against the unfiltered one and against the count
   `list-filters` reports. A suite can pass while the filters never reach `WP_Query` at all.
2. `query-jobs` returns exactly what `/jobs/` and the shortcode return, for the same visibility rule.
3. Over HTTP with an application password, not only in-process — the empty-map-as-`[]` defect exists
   only at `json_encode` time.
4. Through the **MCP adapter** as well, because it reads schemas raw where core rewrites them.
5. The floor instance registers all three and executes each one without a fatal.
6. **No `oembed_cache` post exists before the run and none exists after it.** `SELECT COUNT(*) FROM
   wp_posts WHERE post_type = 'oembed_cache'` before and after a `query-jobs` call over the seeded job
   with a bare URL. This is the direct measurement of §10.

### 8.3 Acceptance gate — not skippable

Repeating what `jpkcom-post-filter` 1.3.0 taught, where 178 green checks were followed by nine defects:

1. **Abuse round.** After the core function works, a dedicated pass whose brief is to *misuse* the
   interface, not to confirm it: no arguments at all, a page past the end, a value that normalises to
   nothing, a `job_location` ID in the `company` field, an attribute slug on no job, a `job_type` label
   instead of its value, a filter list longer than the cap, `id` of a draft / of a page / of `0` / of a
   revision.
2. **Refutation.** Every confirmed finding goes to a second agent whose brief is to **disprove** it. In
   doubt it falls. Of 25 claims in the previous round, 5 survived.
3. **Each fix round gets its own attack.** Four of five fixes were reopened last time, three with new
   production holes, always because the fix addressed the *reported input* rather than the *class of
   inputs*. A re-review asking "was the finding addressed?" does not catch that.

---

## 9. Release

Version `1.4.0`. The version lives in **4 files and 7 lines** — `CLAUDE.md:149-155` understates it as
five:

1. `jpkcom-acf-jobs.php:6` — header `Version:`
2. `jpkcom-acf-jobs.php:16` — `Stable tag:`
3. `jpkcom-acf-jobs.php:35` — `JPKCOM_ACFJOBS_VERSION`
4. `phpdoc.xml:12` — `<version number="…">`
5. `README.md:6` — `**Version:**`
6. `README.md:16` — `**Stable tag:**`
7. `README.md:319` — a new `### 1.4.0` block directly under `## Changelog`, which `release.yml:72`
   slices into the manifest with `awk '/^## Changelog/{flag=1;next}/^## /{flag=0}flag'`

Documentation in the same commit: `CLAUDE.md` gets an "Abilities API" section and the new constant;
`CLAUDE.md:169-176` gets the three new filters (and the missing `jpkcom_acf_jobs_schema_job_posting`
backfilled while there); `README.md` gets a user-facing section explaining what the abilities are and
how to switch them off — the site owner deciding whether to disable the feature must be able to decide
from the README alone.

**A README formatting trap:** `release.yml:76-91` harvests `**Key:** value` lines that start at column
0, and step 8 publishes the ZIP *before* step 9 builds the manifest. A new documentation line in that
form whose value contains a quote or backslash kills the job **after** the release is already public: no
manifest is regenerated, no `gh-pages` deploy happens, and every installed site keeps seeing the old
version while the new ZIP is downloadable. New prose uses headings, list items or fenced code — the
`**Key:** value` form is reserved for the header block at `README.md:3-20`.

**CI must be green on the tagged commit before the tag is pushed.** `release.yml` is not gated on
`ci.yml`; pushing commit and tag together starts two independent runs and the release publishes
regardless of what the guards say. See §11.

Release is triggered by pushing a `v1.4.0` tag. No `Co-Authored-By` trailer in any commit.

---

## 10. The finding that shaped this design

**`get_field()` is not a read operation on the four wysiwyg fields.** Verified against the ACF Pro
6.8.6 source on disk, not against documentation:

- `includes/api/api-template.php:26` — `get_field( $selector, $post_id = false, $format_value = true, … )`.
  Formatting is the default.
- `includes/fields/class-acf-field-wysiwyg.php:410` — `format_value()` applies
  `apply_filters( 'acf_the_content', $value )`.
- `:71-84`, attached unconditionally from `initialize()` — `do_shortcode` at priority **11**,
  `$GLOBALS['wp_embed']->autoembed` at priority **8**, `wp_filter_content_tags`.

Two consequences, both reachable by ordinary editorial content:

1. **Arbitrary shortcodes execute inside the ability callback**, under the identity of any logged-in
   subscriber, up to `per_page` times per call. If one throws, that is an uncaught fatal on the 6.9
   floor. If one echoes — very common — the bytes land **before** the JSON body:
   `WP_REST_Server::serve_request()` contains no `ob_start()`, so nothing catches them and no MCP client
   can parse the response.
2. **A declared read-only ability writes to the database and makes outbound HTTP requests.** With no
   post context — which is exactly an ability callback — `WP_Embed::shortcode()` takes the `else` branch
   at `wp-includes/class-wp-embed.php:316-360`: `wp_oembed_get()` fetches the remote URL, then
   `wp_insert_post()` creates an `oembed_cache` post with `post_status => 'publish'`. A URL alone on one
   line of a job description is enough.

**The obvious mitigation does not work.** `$escape_html = true` only adds `acf_esc_html` at priority 1
(`:407`); `do_shortcode` still runs at 11, and `[foo]` is not HTML so escaping does not remove it.

**The rule:** every wysiwyg and every flexible-content sub-field is read with
`get_field( $name, $post_id, false )` and normalised by this plugin. Tellingly, ACF itself declares no
`format_value_for_rest` for wysiwyg — it deliberately does not run this chain for API responses either.

Three companions to it, from the same round:

- **Never emit a `WP_Post`.** ACF resolves `post_object` fields through `acf_get_posts()` with
  `post_status => 'any'`, so drafts and private companies come back. `WP_Post` implements no
  `JsonSerializable` and exposes `post_password`, `post_content` and `post_status` as public
  properties — a job linked to a password-protected company would have handed a subscriber that
  password in plaintext.
- **Never call `get_fields()`.** `job_company_jobs` and `job_location_jobs` are bidirectional
  back-references written without a status check; reading a related company generically returns every
  job attached to it — drafts, expired, filled — bypassing the entire visibility rule.
- **Never reuse `schema.php:71`.** `date( 'Y-m-d', strtotime( $expiry ) )` throws `TypeError` under
  `strict_types` when `strtotime()` returns `false`, which happens for an unparseable stored value
  whenever the `_job_expiry_date` reference row is absent. On the floor that is an uncaught fatal.

---

## 11. Risks, and defects found but deliberately not fixed here

**Risks**

- **The output schema is a promise the plugin cannot fully keep.** A theme may replace
  `acf-field_groups.php` through the override system. Mitigated by tolerating both value shapes and
  marking every property optional.
- **`read` may be too permissive for some sites.** Mitigated by
  `jpkcom_acf_jobs_ability_capability` and called out in the README.
- **The `job-attribute` meta/term divergence is real, not hypothetical.** On `/home/jpk/ddev/posts`,
  term 22 (`parkplatz`) reports a count of 4 while job 184's `job_attribute` meta lists only 20 and 21.
  The abilities read the terms — the authoritative store — so they are on the correct side of it, but
  `tools/check-term-sync.php` should be run against any install before its numbers are trusted.
- **WP 7.1 GA is 2026-08-19.** Nothing here depends on a 7.1-only API.

**Pre-existing defects found during exploration, left alone**

- `.github/workflows/release.yml` is not gated on `ci.yml`. A `v*` tag publishes the ZIP **and** the
  SHA256 the auto-updater trusts at step 8, before any PHP is parsed — so a commit failing every guard,
  including the "actions must be pinned to a SHA" check that `CLAUDE.md:159` describes as what secures
  the build, still ships. This is the most consequential finding of the round and belongs in its own
  change.
- `includes/shortcodes.php:34-57` duplicates `jpkcom_acf_jobs_locate_template()` and is unreachable
  dead code; with `WP_DEBUG` on it makes the shortcode silently fall through to the inline fallback at
  `:276-377` instead of `templates/shortcodes/list.php`, and the two differ in their zero-results output.
- `includes/shortcodes.php:233-238` — the `count( $meta_query ) > 1` guard is unreachable: the array
  already holds more than one element before it.
- `includes/schema.php:112` keeps only the first company while every other renderer lists all of them;
  `schema.php:267-270` calls `get_term()` without a taxonomy, so a stale ID resolves a term from any
  taxonomy.
- `includes/wpml-acf-field-keys-fix.php` only covers the Translation Management flow, so a translation
  made through WPML's "+" editor can leave `_job_company` / `_job_location` missing, after which
  `get_field()` returns raw IDs and four renderers dereference `->ID` on an int.
- Site search and `/wp-json/wp/v2/jobs` do **not** apply the visibility rule — only the job archive main
  query and the shortcode do. So "what a visitor sees" is not one thing, and the abilities are
  deliberately narrower than site search. Stated here so the divergence is not read as a bug in the
  abilities.
