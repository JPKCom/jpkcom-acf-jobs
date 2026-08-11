# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a WordPress plugin called **JPKCom ACF Jobs** - a job application system built on Advanced Custom Fields Pro. It provides custom post types (jobs, locations, companies), custom taxonomies, and a complete template system for displaying job listings with Schema.org markup.

**Requirements:**
- WordPress 7.0+
- PHP 8.3+
- Advanced Custom Fields Pro (required dependency)
- ACF Quick Edit Fields (required dependency)
- WPML (optional, for multilingual support via wpml-config.xml)

## Architecture

### Core Plugin Structure

The plugin uses a **modular file loader pattern** with override capabilities. The main file `jpkcom-acf-jobs.php` orchestrates loading via `jpkcom_acfjobs_locate_file()` which searches for files in this priority:

1. Child theme: `/wp-content/themes/your-child-theme/jpkcom-acf-jobs/`
2. Parent theme: `/wp-content/themes/your-theme/jpkcom-acf-jobs/`
3. MU plugin overrides: `/wp-content/mu-plugins/jpkcom-acf-jobs-overrides/`
4. Plugin itself: `/wp-content/plugins/jpkcom-acf-jobs/includes/`

This override system allows developers to customize any functional file without modifying the plugin.

### Custom Post Types

Three interconnected post types registered in `includes/acf-post_types.php`:

- **job**: The main job posting (public, queryable)
- **job_location**: Work locations (nested under jobs in admin)
- **job_company**: Hiring companies (nested under jobs in admin)

### Template System

Templates in `templates/` directory with debug versions in `debug-templates/` (loaded when `WP_DEBUG` is true).

**Template loading order** via `jpkcom_acf_jobs_locate_template()` in `includes/template-loader.php`:

1. Child theme: `/wp-content/themes/your-child-theme/jpkcom-acf-jobs/`
2. Parent theme: `/wp-content/themes/your-theme/jpkcom-acf-jobs/`
3. MU plugin: `/wp-content/mu-plugins/jpkcom-acf-jobs-overrides/templates/`
4. Plugin: `/wp-content/plugins/jpkcom-acf-jobs/templates/` (or `debug-templates/` if `WP_DEBUG`)

Key templates:
- `single-job.php`, `single-job_company.php`, `single-job_location.php`
- `archive-job.php`, `archive-job_company.php`, `archive-job_location.php`
- `partials/job/*.php` - reusable job components
- `shortcodes/list.php`, `shortcodes/attributes.php` - shortcode templates

### ACF Field Configuration

All ACF field groups are registered programmatically in `includes/acf-field_groups.php` using `acf_add_local_field_group()`. This includes:

- Job details (type, location, company, salary, expiry date)
- Job location details (place, street, zip, region, country)
- Company details (URL, logo)
- Application settings (description, button, shortcode)
- Flexible content layouts for job descriptions

### Shortcodes

Registered in `includes/shortcodes.php`:

**`[jpkcom_acf_jobs_list]`** - Filtered job list with attributes:
- `type` - CSV of job types (e.g., "FULL_TIME,PART_TIME")
- `company` - CSV of company post IDs
- `location` - CSV of location post IDs
- `limit` - Number of posts (default: all)
- `sort` - "ASC" or "DSC" (default: "DSC")
- `style` - Inline CSS
- `class` - CSS classes
- `title` - Section headline

**`[jpkcom_acf_jobs_attributes]`** - Display taxonomy terms as `<details>` elements:
- `id` - CSV of term IDs (optional, shows all if omitted)
- `style`, `class`, `title` - Same as above

### Helper Functions

Key functions in `includes/helpers.php`:

- `jpkcom_render_acf_fields($post_type = '')` - Auto-renders all ACF fields with Bootstrap 5 markup and icon mapping
- `jpkcom_get_acf_field_label($field_name, $post_type = '')` - Returns human-readable field labels
- `jpkcom_human_readable_relative_date($timestamp)` - Converts timestamp to "Published X days ago"

Template loading:
- `jpkcom_acf_jobs_get_template_part($slug, $name = '')` - Load partial templates with full override support (similar to `get_template_part()`)

### Schema.org Integration

`includes/schema.php` generates JobPosting JSON-LD structured data for job posts. Function `jpkcom_acf_jobs_get_schema_job_posting($post_id)` outputs complete Schema.org markup.

### Plugin Updates

Custom GitHub-based updater in `includes/class-plugin-updater.php` (namespace: `JPKComAcfJobsGitUpdate`) provides secure, self-hosted updates:

**Security Features:**
- SHA256 checksum verification of downloaded packages (since v1.2.0)
- URL validation and sanitization using `wp_http_validate_url()`
- Race condition prevention with transient locking mechanism
- Comprehensive error logging in `WP_DEBUG` mode
- **Checksum is mandatory (fail closed):** a manifest without `checksum_sha256`, or one that cannot be fetched, aborts the update instead of installing unverified code. There is deliberately no "skip verification" fallback — that would let anyone who can alter the manifest disable the integrity check by dropping one field.
- The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed (previously the package was fetched a second time and *those* bytes were installed)
- Failed manifest fetches are negatively cached for 1 h, so an unreachable host cannot stall admin requests once per plugin

**Update Flow:**
1. Fetches manifest from: `https://jpkcom.github.io/jpkcom-acf-jobs/plugin_jpkcom-acf-jobs.json`
2. Caches manifest data with 24-hour TTL (transient)
3. Compares versions and displays update notice
4. Downloads plugin ZIP before installation
5. Verifies SHA256 checksum against manifest (via `verify_download_checksum()`)
6. Aborts installation with `WP_Error` if checksum fails
7. Proceeds with standard WordPress upgrade if verification passes

**Hooks Used:**
- `plugins_api` - Provides plugin info for "View Details" modal
- `site_transient_update_plugins` - Injects update availability
- `upgrader_pre_download` - Verifies checksum before installation
- `upgrader_process_complete` - Clears cache after successful update

**Manifest Generation:** Automated by `.github/workflows/release.yml` (see Release Process below)

## Development Workflow

### Making Code Changes

1. Edit PHP files directly in `includes/` or `templates/`
2. Test with `WP_DEBUG` enabled to use `debug-templates/` versions
3. ACF field changes should be made in `includes/acf-field_groups.php` (programmatic registration)

### Testing Template Changes

Enable `WP_DEBUG` in wp-config.php to load templates from `debug-templates/` instead of `templates/`:

```php
define('WP_DEBUG', true);
```

### API Documentation

`phpdoc.xml` configures phpDocumentor to document `jpkcom-acf-jobs.php` and `includes/*.php` into `docs/`. The release workflow generates it and publishes to `https://jpkcom.github.io/jpkcom-acf-jobs/docs/`. `.phpdoc/`, `docs/` and `phpDocumentor.phar` are gitignored — locally: `./phpDocumentor.phar run --config=phpdoc.xml`.

### Version Management

The version appears in five places and must be kept in sync:

1. `jpkcom-acf-jobs.php` — header `Version:`
2. `jpkcom-acf-jobs.php` — header `Stable tag:`
3. `jpkcom-acf-jobs.php` — `JPKCOM_ACFJOBS_VERSION`
4. `phpdoc.xml` — `<version number="…">`
5. `README.md` — `**Version:**`, `**Stable tag:**`, plus a new `### x.y.z` changelog block

### Release Process

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

**Releasing.** Bump the five version locations, add the changelog block, commit, then push a `v*` tag — that tag push is the only trigger. `.github/workflows/release.yml` creates the GitHub release itself; do **not** create it by hand first. Pipeline: README metadata via Pandoc → slug-named ZIP (excludes `.git`, `.github`, `CLAUDE.md`, `tests`, `tools`, `docs`, build artefacts) → SHA256 → upload ZIP + `.sha256` → `plugin_jpkcom-acf-jobs.json` manifest → PHPDoc → deploy to `gh-pages`.

The manifest's `checksum_sha256` is what `includes/class-plugin-updater.php` verifies on every update, so the ZIP and the manifest must come from the same run — which is why the manifest is only rebuilt on a tag push.

### Adding Custom Filters

The plugin provides several filter hooks for customization:

- `jpkcom_acfjobs_file_paths` - Modify functional file search paths
- `jpkcom_acf_jobs_template_paths` - Modify template search paths
- `jpkcom_acf_jobs_final_template` - Last-chance template override
- `jpkcom_acf_jobs_list_query_args` - Modify shortcode query arguments. **Shortcode only**: its second
  argument is the shortcode attributes, and it is applied at the shortcode's own call site, not inside
  `jpkcom_acf_jobs_build_job_query_args()`. The archive has never fired it and the abilities do not
  either — a filter written for a curated page must not silently widen what an API caller sees.
- `jpkcom_acf_jobs_schema_job_posting` - Modify the JobPosting JSON-LD before output
- `jpkcom_acf_jobs_ability_meta` - Per-ability `meta` array (REST and MCP exposure, annotations)
- `jpkcom_acf_jobs_ability_capability` - Per-ability capability, default `read`
- `jpkcom_acf_jobs_ability_query_args` - Contribute to the abilities' query. **Only `meta_query` and
  `tax_query` are read**; everything else the callback returns is discarded, and the clauses the
  ability built must survive unchanged. See "Abilities API", point 9, for why that is not a checklist.

### Constants (wp-config.php overridable)

| Constant | Default | Purpose |
|----------|---------|---------|
| `JPKCOM_ACFJOBS_VERSION` | matches the header `Version:` | Plugin version |
| `JPKCOM_ACFJOBS_BASENAME` | `plugin_basename(__FILE__)` | Plugin basename |
| `JPKCOM_ACFJOBS_PLUGIN_PATH` | `plugin_dir_path(__FILE__)` | Absolute path |
| `JPKCOM_ACFJOBS_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL |
| `JPKCOM_ACFJOBS_ABILITIES` | `true` | Abilities API registration master switch |

New constants use the `JPKCOM_ACFJOBS_` prefix — no underscore between `acf` and `jobs` — matching the
four that predate them. New **functions** and **filters** use `jpkcom_acf_jobs_`. That asymmetry is
real and deliberate: `jpkcom_acfjobs_` is frozen for `jpkcom_acfjobs_locate_file()`, `_textdomain()`
and the `jpkcom_acfjobs_file_paths` filter, and must not be extended.

### WPML/Translation Support

Multilingual configuration in `wpml-config.xml` provides comprehensive WPML integration:

**Post Types & Taxonomies:**
- `job` - Marked for translation (`translate="1"`)
- `job_location` - Translate + display as translated (`translate="1" display-as-translated="1"`)
- `job_company` - Translate + display as translated
- `job-attribute` - Custom taxonomy marked for translation

**ACF Field Translation Strategy:**

**CRITICAL:** The `wpml_cf_preferences` values in ACF field definitions MUST match the actions in `wpml-config.xml`:

- `wpml_cf_preferences => 0` = `action="ignore"` (ACF internal fields only)
- `wpml_cf_preferences => 1` = `action="copy-once"` (copied once, then independent)
- `wpml_cf_preferences => 2` = `action="translate"` (content differs per language)
- `wpml_cf_preferences => 3` = `action="copy"` (kept in sync across translations - RARELY USED)

Three action types control how fields are handled across languages:

1. **`action="translate"`** (`wpml_cf_preferences => 2`) - Content differs per language:
   - `job_short_description`
   - `job_application_description`
   - `job_application_button`
   - All flexible content text fields (e.g., `job_layout_content_%_text_left`)

2. **`action="copy-once"`** (`wpml_cf_preferences => 1`) - Copied once, then independent:
   - **IMPORTANT:** This is the default for most fields!
   - `job_type`, `job_work_type` (with `encoding="base64"` for serialized arrays)
   - `job_url`
   - `job_location`, `job_company` (with `translate_link_target="1"` for auto Post-ID translation)
   - `job_layout_content` (flexible content structure)
   - All salary fields (`job_base_salary_group_*`)
   - All metadata (`job_closed`, `job_featured`, `job_expiry_date`)
   - All location/company detail fields
   - All image fields in flexible content (`img_left`, `img_right`)

3. **`action="copy"`** (`wpml_cf_preferences => 3`) - Kept in sync across translations:
   - **NOT USED** in this plugin (causes issues with arrays and objects)

**ACF Internal Fields (Prefixed with `_`):**

WPML requires special handling of ACF's internal meta fields:

- **Standard fields:** `action="ignore"` - These store field keys, not content (e.g., `_job_type`)
- **Flexible Content system fields:** `action="copy"` - EXCEPTION for PHP-registered flexible content (lines 94-102 in wpml-config.xml)

The `_job_layout_content` fields MUST be copied for WPML to work correctly with programmatically registered flexible content fields. This is a known ACF + WPML compatibility requirement.

**Wildcard Patterns:**

Flexible content uses `%` wildcard to match dynamic row indices:
```xml
<custom-field action="translate">job_layout_content_%_text_left</custom-field>
```
Matches: `job_layout_content_0_text_left`, `job_layout_content_1_text_left`, etc.

**Translation Files:**
- Located in `languages/` directory
- Format: `.l10n.php` (WordPress 6.8+ format)
- Text domain: `jpkcom-acf-jobs`
- Available languages:
  - German (`de_DE`) - German (Standard)
  - German Formal (`de_DE_formal`) - German (Formal)
  - French (`fr_FR`) - Français
  - Italian (`it_IT`) - Italiano
  - Spanish (`es_ES`) - Español
  - Hungarian (`hu_HU`) - Magyar
  - Polish (`pl_PL`) - Polski

**Important Notes:**

**Bidirectional Post Object Fields:**
- Fields like `job_location` and `job_company` use `wpml_cf_preferences => 1` (copy-once) with `translate_link_target="1"` in wpml-config.xml
- The `translate_link_target="1"` attribute is **CRITICAL** - it tells WPML to automatically translate Post IDs to their translated versions
- Without this, the field would show the wrong post (e.g., showing the Job title instead of Location title)

**Checkbox & Array Fields:**
- Fields like `job_type` and `job_work_type` are stored as serialized PHP arrays
- Use `wpml_cf_preferences => 1` (copy-once) with `encoding="base64"` in wpml-config.xml
- The `encoding="base64"` prevents WPML from corrupting the serialized array data
- Labels are translated via WordPress translation files (`languages/*.l10n.php`), not WPML field translation

**Flexible Content:**
- Main field `job_layout_content` uses `wpml_cf_preferences => 1` (copy-once)
- This copies the layout structure once, then allows independent editing per language
- Sub-fields use `translate` (for text) or `copy-once` (for images)
- Internal ACF fields (`_job_layout_content*`) also use copy-once

**Python Scripts:**
- `add-wpml-preferences.py` - Auto-applies wpml_cf_preferences based on wpml-config.xml
- `update-json-from-wpml.py` - Syncs ACF JSON export with wpml-config.xml
- Both use the corrected mapping: ignore=0, copy-once=1, translate=2, copy=3

## Security & Correctness

This section was missing until 1.3.7 — it was the only JPKCom plugin without one.

### Rules with a guard behind them

Both of these are enforced by `tests/test-conventions.php`, which CI runs on every pull request:

- **Dates:** use `current_time( 'Y-m-d' )`, never a bare `date( 'Y-m-d' )`. WordPress sets the PHP timezone to UTC in `wp-settings.php`, so `date()` returns the *UTC* date and expiry checks lag the site timezone by its offset — expired job listings stayed visible for 1–2 hours after local midnight in Europe/Berlin.
  **Exception, deliberately not flagged:** `schema.php` formats a *stored* date with `date( 'Y-m-d', strtotime( $expiry ) )`. That is a pure round-trip of a date-only string with no reference to "now", so it is correct. The guard's pattern only matches the single-argument form.
- **Capabilities:** never pass a role name to `current_user_can()`. It works only because the role is a key in the capability array, which bypasses `map_meta_cap` and misses differently named roles holding the same rights. Check a capability (`manage_options`, `edit_post`).

### Verified as sound (do not "fix")

- **JSON-LD output** (`schema.php:296`) uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. That combination is deliberate: `JSON_HEX_TAG` escapes `<` to `<` so a `</script>` in any field cannot break out, while unescaped slashes keep schema URLs readable. `single-job.php:123` adds a redundant `str_replace( '</', '<\/' )` on top — harmless, and a no-op given the hex escaping.
- **External redirects** in `redirects.php` use `wp_redirect()` rather than `wp_safe_redirect()` on purpose: a job may point at an external application URL, and the archive-disabled redirect target is an admin-set option sanitised with `esc_url_raw()`.
- Template partials that `echo` a `*_url_HTML_*` variable are safe — the URL itself went through `esc_url()` before concatenation and the surrounding fragments are literals.

### By design, worth knowing

Anyone who can edit a job can point `job_url` at any external target, and the single-job redirect 307s there. That is the feature, but it does mean a non-admin editor can use the site's own domain as a redirector.

### Filtering: why tax_query does *not* apply here

An earlier note in this file implied the list shortcode's three `LIKE '%"5"%'`
clauses could move to `tax_query`, the way `jpkcom-acf-references` did. **They
cannot** — none of the three filtered fields is taxonomy-backed:

| Shortcode attribute | Meta field | ACF field type | Can it use `tax_query`? |
|---|---|---|---|
| `type` | `job_type` | `checkbox` with string choices (`FULL_TIME`, …) | No — there is no taxonomy behind it |
| `company` | `job_company` | post object | No — post relation, not a term relation |
| `location` | `job_location` | post object | No — same |

The only taxonomy-backed field in this plugin is `job_attribute`
(`type => 'taxonomy'`, `taxonomy => 'job-attribute'`, `save_terms => 1`,
`includes/acf-field_groups.php`), and **the list shortcode does not filter by it
at all**. So the three unindexable meta scans stay for now; removing them would
mean giving `job_type` a real taxonomy, which is a data migration, not a query
rewrite.

`tools/check-term-sync.php` is still correct and worth keeping: it maps
`job_attribute => job-attribute` and would guard the switch if a filter on that
attribute is ever added.

### The `job_attribute` slug fix, measured (2026-07-28)

Verified on a DDEV instance with 6 seeded jobs and three `job-attribute` terms.

`templates/partials/job/job_attribute.php` branches on the value type: numeric →
`get_term()`, `WP_Term` → used directly, string → `get_term_by( 'name', … )`.
That last branch passed **`job_attribute`**, the field name, where the
**`job-attribute`** taxonomy slug belongs.

| Probe | Result |
|---|---|
| `get_term_by( 'name', 'Firmenwagen', 'job_attribute' )` | `false` — for all three terms |
| `get_term_by( 'name', 'Firmenwagen', 'job-attribute' )` | term ID |
| `get_taxonomies()` | registers `job-attribute` only |

**Which branch actually runs:** with the shipped configuration
(`return_format => 'id'`) `get_field()` returns integers, so the numeric branch
handles everything and the bug never shows. Rendering a job page confirms it —
attributes appear, no PHP diagnostics.

**What the fix buys:** forcing the string branch (an `acf/format_value/type=taxonomy`
filter returning term names, after resetting ACF's value store) makes the
difference visible immediately — the corrected slug renders
`Parkplatz | Firmenwagen`, the old one renders **nothing at all**, with no error
and no log entry. So the fix is preventive under the current configuration and
load-bearing the moment anything hands that partial strings: a changed
`return_format`, a `format_value` filter, or legacy data. `tests/test-conventions.php`
now compares every literal taxonomy argument against the slugs actually passed
to `register_taxonomy()`.

```bash
wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/check-term-sync.php
```

Exit 0 means meta and term assignments agree; exit 1 lists the diverging posts
with edit links.

---

## Abilities API (since 1.4.0)

`includes/abilities.php` registers three **read-only** WordPress Abilities so MCP clients, REST
automation and the WordPress AI client can query this plugin without scraping HTML.

| Ability | Returns |
|---|---|
| `jpkcom-acf-jobs/list-filters` | The job types, companies, locations and `job-attribute` terms a caller may filter by, with counts |
| `jpkcom-acf-jobs/query-jobs` | A filtered, paginated list of compact job records |
| `jpkcom-acf-jobs/get-job` | One job's full record, with a `detail` block |

They share the category `jpkcom-content` with `jpkcom-post-filter`. Categories are global and
**first-wins**, so registration goes through `wp_has_ability_category()` first. Measured: without that
guard all three abilities still register (the category exists either way) but `_doing_it_wrong()`
fires. Which plugin wins depends on load order — on this stack `jpkcom-acf-jobs` loads first.

### The shared foundation

`includes/jobs-data.php` holds what the plugin previously did in several places at once:

```php
jpkcom_acf_jobs_build_job_query_args( array $args = [] ): array  // the visibility rule, one home
jpkcom_acf_jobs_get_job_data( int $post_id, bool $full = false ): array  // a job as data, [] when unreadable
jpkcom_acf_jobs_normalise_choices() / _choice() / _related() / _date() / plain_text()
```

The visibility rule had **three** byte-identical copies — the `[jpkcom_acf_jobs_list]` shortcode,
`includes/archive.php`, and it was about to gain a fourth. All three now call the builder. Verified at
the time of extraction that the generated SQL, the returned post IDs and the rendered shortcode HTML
were byte-identical before and after.

### Twelve things that will bite

**1. `get_field()` is not a read operation on the four wysiwyg fields.** ACF Pro 6.8.6 pipes them
through `acf_the_content` (`includes/fields/class-acf-field-wysiwyg.php:410`), and that chain carries
`do_shortcode` at priority **11** and `$GLOBALS['wp_embed']->autoembed` at **8** (`:71-84`). In an
ability callback there is no post context, so `WP_Embed::shortcode()` takes the `else` branch at
`wp-includes/class-wp-embed.php:316-360`: it fetches the remote URL and calls `wp_insert_post()` with
`post_type => 'oembed_cache'`. **A declared read-only ability that writes rows and makes outbound
requests, triggerable by any logged-in subscriber.** A bare URL alone on one line of a job description
is enough.

Measured, not argued: with the unformatted read, `oembed_cache` was 0 before and 0 after a `get-job`
call on such a job and the shortcode came back as literal text. Flipping one `false` to the
two-argument form: 0 → 1, an `oembed_cache` post created with status `publish`, and the shortcode
executed. Every long-form field is therefore read as `get_field( $name, $post_id, false )`, and
`tests/test-abilities.php` fails the build if a named long-form read does not end in `, false` — the
guard also rejects an explicit `true`, because the first version of it only matched the two-argument
form and a `false → true` "tidy-up" stayed green.

`$escape_html = true` does **not** help: it only adds `acf_esc_html` at priority 1, `do_shortcode`
still runs at 11, and `[foo]` is not HTML. Tellingly, ACF declares no `format_value_for_rest` for
wysiwyg — it does not run this chain for its own API responses either.

**2. The reader gates, because nothing else does.** `schema.php:48-52` checks the post type; nothing
anywhere checked `post_status`. The existing readers are safe only because `shortcodes.php:120` hands
them posts a `post_type`/`post_status` query already filtered. `jpkcom_acf_jobs_get_job_data()` takes
a bare int and inherits none of that, and the abilities above it answer to any subscriber — so it
rejects a non-`job` type, any status but `publish`, a non-empty `post_password`, and a non-positive
id, all returning `[]`. **"Does not exist" and "not readable" return the same thing**, so `get-job`
cannot be used to probe which IDs exist.

**3. `get_post( 0 )` returns the global post.** `absint()` maps `false`, `''`, `null` and `'abc'` all
to 0, and ACF returns `false` for an unassigned `post_object` — both `job_company` and `job_location`
are `allow_null`. Measured live: `get_post( absint( false ) )` returned `WP_Post #184`. Before the
guard, a job with no company projected **itself as its own employer**. Every path that turns an
external value into an id rejects a non-positive one before the lookup.

**4. Never emit a `WP_Post`, never call `get_fields()`.** ACF resolves `post_object` fields through
`acf_get_posts()` with `post_status => 'any'`, so drafts and private companies genuinely arrive.
`WP_Post` implements no `JsonSerializable` and exposes `post_password`, `post_content` and
`post_status` as public properties — a job linked to a password-protected company would have handed a
subscriber that password in plaintext. And `job_company_jobs` / `job_location_jobs` are bidirectional
back-references to every linked job regardless of status, so a generic read of a related record
bypasses the entire visibility rule.

**5. Never write `date( 'Y-m-d', strtotime( $x ) )`.** That is `schema.php:71`, and under
`strict_types` a `false` from `strtotime()` makes `date()` throw a `TypeError` — an uncaught fatal on
the pre-7.0 floor this plugin used to declare, and a 500 on the 7.0 floor it declares now.
`jpkcom_acf_jobs_normalise_date()` uses `DateTimeImmutable::createFromFormat()`
with a round-trip check — and that check is load-bearing: without it `'20251340'` becomes 2026-02-09
and `'20259999'` becomes 2033-06-07. It is wrapped in `try/catch` because a NUL byte in the stored
value makes `createFromFormat()` throw `ValueError`, and MySQL `longtext` stores NUL.

**6. The detail-page rule is what makes `read` defensible.** `detail` is emitted only for a job whose
detail page would actually render for an anonymous visitor. Three states suppress it, each mirroring a
redirect in `redirects.php`: `job_url` set (`:32-86`), expired (`:132-173`), password-protected
(excluded by the gate). For those jobs the salary, postal address, attributes, layout content and
application data have **no public render path at all**, so emitting them would publish what the site
has deliberately never shown. The compact record stays, with the reason.

The expiry half of that rule failed open once: an unparseable stored date made `normalise_date()`
return `null` and the predicate answer "renders". A non-empty raw expiry that does not normalise now
withholds.

**7. `listed` is answered BY the visibility query, not by a paraphrase of it.** `get-job` runs
`jpkcom_acf_jobs_build_job_query_args()` restricted to that one post ID and lets the presence of the
row be the answer. This is not fastidiousness: a PHP re-derivation disagreed with the SQL for a stored
expiry of `'2025-11-30 00:00:00'`, because `CAST(… AS DATE)` parses it and `DateTimeImmutable` does
not — `query-jobs` excluded the job while `get-job` reported `listed: true`. The `ability_query_args`
filter is deliberately **not** applied to that query: a callback must not be able to make a verdict
*about* the rule disagree with the rule. The guarantee is agreement with the site's **base** rule, not
with a filtered `query-jobs` response.

Note `listed` is an EXISTS test on the `job_featured` meta **row**, not its value. A stored `0` is
listed; a missing row is not; `get_field()` cannot tell those apart.

**8. Two independent causes exclude a job with no `job_featured` row.** The `EXISTS` clause *and*
`meta_key => 'job_featured'` for the ordering, whose `postmeta.meta_key = 'job_featured'` condition
lands in the `WHERE` clause. Removing one of them changes nothing, which is why the count of jobs that
carry the row cannot be read off the listing query and needs one of its own.

**8a. Both visibility counts are DIFFERENCES, and reintroducing a comparison is the bug.** Trap 7 says
the rule answers `listed`. The same applies one field along: `hidden_expired` used to be its own
`meta_query` — `job_featured EXISTS AND job_expiry_date < today` — carrying a comment claiming it was
"the mirror image of the visibility rule's". It was not. The rule's expiry clause is an **OR group**
(at or after today, OR no row, OR the empty string), and negating only its first branch is not its
complement. MariaDB casts `''` to `'0000-00-00'`, which is less than any real date, so **every job
whose expiry date had been saved and cleared was counted as expired in the same response that listed
it** — and `''` is the ordinary case, because ACF writes it rather than deleting the row. Measured on
the floor: 2 of 2 jobs, and 25 listed + 13 expired against 37 published, against a schema that calls
the partition exact.

Among jobs carrying the `job_featured` row, expiry is the only remaining exclusion, so:

```
hidden_expired          = ( published + featured row ) − listed
hidden_missing_featured = published − ( published + featured row )
```

Both derived from the rule, no second statement of what "expired" means, and the partition holds by
construction. `tests/test-abilities.php` fails the build if any query issued for the counts mentions
`job_expiry_date` at all — no stub can reproduce the original defect, because it lives in MariaDB's
cast, so the guard is the structural property that makes it impossible.

**8b. `query-jobs` carries no `visibility` block.** It did, and the numbers were site-wide while the
schema called them "excluded from **this answer**". Measured: `total` moved 12 → 6 → 2 → 0 across
filters while `visibility` never moved at all, so a reader adding the two reported jobs that do not
exist, with an error that grows with the rest of the site. No wording fixes that — the numbers are
about something else. The block belongs to `list-filters`, whose whole answer is site-level and whose
wording already said so, and which a caller has to call anyway to learn the filter vocabulary.

**9. The site filter contributes `meta_query` and `tax_query`, and nothing else is read.** This is the
important one, and it took five attempts to get right. `jpkcom_acf_jobs_ability_query_args` runs, but
`$args` is never overwritten with its return value — the two structures are lifted out and everything
else is discarded. Then the executed clauses are compared against the clauses the ability built, by
**identity of canonical content**, not by a checklist of properties.

Four earlier versions checked a property list — presence, then operator, then relation, then value —
and each was broken one layer deeper, because the class is "anything that changed what the clause
means" and that list is not bounded by what anyone thought of. The same applied to the query vars: a
pin list for `posts_per_page` was defeated by `posts_per_archive_page`, then by `showposts`, and
reading `WP_Query::get_posts()` shows 34 vars that assign over another. Deleting the list beat
extending it. **Do not reintroduce an enumeration here.**

`pre_get_posts` is the one route non-carriage cannot close — it fires inside `get_posts()` and holds
the query by reference. The clause guarantee is therefore checked **twice with the same comparison**:
once against the arguments, and once against `$query->query_vars` after the run, which is what the
query actually asked. Asking the second time is not a second mechanism; the first version asked the
right question of the wrong array, and a callback replacing `meta_query` — `WP_Query::set()` replaces,
it does not merge — deleted every clause while the argument array still held them. Measured: HTTP 200,
`filters.job_type` still `["FULL_TIME"]`, no job_type LIKE in the executed statement.

**What this does NOT close, and why nothing here will.** A callback that rewrites `paged`, `offset`,
`orderby`, the page size **or the search term `s`** produces a response whose numbers are internally plausible and whose window
is not the one reported: page 1 returning page 3's rows, a shifted offset making rows unreachable
through any page number, `orderby => rand` making three pages yield seven distinct jobs out of nine
while every page insists the set is complete. `s` is on that list for a different reason than the rest: it IS checkable in principle, but WP_Query
rewrites it in place (`class-wp-query.php:1429`, `stripslashes`, same line on 6.9.4 and 7.0.3, plus a
conditional urldecode and a CR/LF strip), so comparing it after the run refused legitimate terms —
any backslash — with a 500 blaming a site callback that did not exist. Reimplementing those three
transformations would hold until the next release changed one, silently. None of those are clauses, and the two "read what came
back" checks — more rows than the page can hold, or a total smaller than the jobs beside it — are
blind to all of them by construction.

Three checks were considered and rejected, on purpose. Comparing every query var after the run fails
because `WP_Query` legitimately rewrites its own (`showposts` → `posts_per_page`, `paged`
normalisation, `orderby` defaults), which is what broke the earlier attempts. Arithmetic
self-consistency (`total_pages === ceil( total / per_page )`) catches exactly one of the five measured
cases. And a check per case is the pattern this file's own history names as the most expensive mistake
of the release. **So `filters` in the response describes the REQUEST, not the executed query, and a
site whose callbacks rewrite paging or ordering gets an answer whose window this ability cannot
vouch for.** That is the honest statement, and it belongs in the schema rather than in a fourth guard.

**10. A caller mistake is 400; a site misconfiguration is 500.** The REST run controller returns the
`WP_Error` verbatim and `rest_ensure_response()` defaults to **500** without `data['status']`. That
matters more than a status usually does: these messages exist so an agent corrects itself in one turn,
and a 5xx tells it "transient fault, retry unchanged". A dropped clause is a 500 on purpose — the
cause is the site's own callback, and the caller cannot fix it.

**11. Core silently blanks a `search` term over 1600 BYTES** (`class-wp-query.php:867`, an anti-DoS
guard, byte-identical on 6.9.4 and 7.0.2). It does so *inside* `WP_Query`, after any check on the
arguments has passed, so the ability returned every job while `filters.search` echoed the term as
applied. Refused up front now. The unit is bytes, not characters: 801 `ü` is 1602 bytes.

**12. An ability whose parameters are all optional still needs a top-level input `default`, and the
callback must accept the value core substitutes.** `normalize_input()` puts that value verbatim into
the callback when the input is exactly `null`. The default must be an **object**, because the MCP
adapter hands `get_input_schema()` to clients raw and an array violates `type: object` — core's REST
list controller rewrites it, the adapter does not. So the callback receives a `stdClass` and must read
it. It did not, and `query-jobs` answered **400 to the most obvious call it has**. This exact defect
shipped once before in `jpkcom-post-filter`. The guard executes each ability with `null`, with `[]`,
and with the value read out of its own registration array — a guard built from a hand-rolled
`stdClass` or from `null` alone would have stayed green.

**13. The declaration and the reachable surface have to be the same thing.** Four defects in 1.4.0
were one class: something the schema said that the surface would not do.

- **`include_closed` could not be sent at all.** `readonly => true` makes the run route GET-only, GET
  carries strings, and the callback demanded a strict `bool` — so the schema's own declared default was
  a 400 whose message named a form the caller had no way to produce. An agent retries `true`, `"true"`,
  `1`, `on` and exhausts its budget; the correction loop cannot terminate. It now accepts core's
  `rest_sanitize_boolean()` spellings and nothing wider, so a value with no boolean reading is still a
  400. **Any future boolean input has this problem on day one.**
- **`"properties": []` is not valid JSON Schema.** `jpkcom_acf_jobs_ability_json_object()` had been
  applied to `default` — the one key core's REST list controller already repairs — and not to
  `properties`, the one key only the plugin can. The MCP adapter publishes `get_input_schema()`
  verbatim, so a client validating a tool's inputSchema drops `list-filters`, and one that rejects the
  whole `tools/list` on a single bad entry loses the other two with it. The guard now checks **every**
  object-typed key of every published schema, not the one that was reported.
- **`search` described a corpus that does not exist.** It is `WP_Query`'s `s` — `post_title`,
  `post_excerpt`, `post_content` — and this plugin holds every piece of job text in ACF meta with
  `post_content` empty. "Free-text search across job titles and job content" made a model report that
  no job mentions a company car when four do. The description now says what it does not reach and names
  the axes that are indexed.
- **An undeclared axis was swallowed.** Same 200, same total as an unfiltered call, `unknown: {}` — and
  the *output* schema instructed the model to send a `work_type` filter that has never been an input.
  `jpkcom_acf_jobs_ability_validate_input_keys()` refuses any key the ability does not declare, with a
  400 naming it.

When adding an input: send it over the GET route before believing the schema, and check that no output
description advertises an axis the input schema does not have.

**14. The guard reached two abilities of three, and the suite could not see it** (fixed in 1.5.0).
`get-job` answered **HTTP 200** to any undeclared input key while `query-jobs` and `list-filters`
answered **400** to the same key — measured over the REST route on WP 7.0.3. The comment at the
`list-filters` call site already said what was wrong: *"Every ability, not one of three: a guard on a
subset is a trap, because a caller that learned the refusal on query-jobs assumes it everywhere."*
The code stood in the trap its own comment described, for a whole release.

Why nothing caught it: **every assertion written for the guard was written against an ability that
had it.** A per-case test cannot find the case it was not written for. The two checks added in 1.5.0
are structural instead — one asserts that *every* ability callback body calls
`jpkcom_acf_jobs_ability_validate_input_keys()`, the other compares
`JPKCOM_ACFJOBS_ABILITY_INPUT_KEYS` against the `properties` of every registered schema. Both were
proved by mutation: removing the guard reddens exactly the first, drifting the constant exactly the
second.

On `get-job` the damage was bounded — the answer is determined by `id`, so an ignored key cannot
widen a result set the way it can on `query-jobs`. The defect was the inconsistency, and that is
enough: a caller calibrates its expectations on the abilities that refuse.

**15. `additionalProperties => false` on a schema WITH `properties` is safe — and still the wrong
tool here.** Measured on 7.0.3: no fatal, anonymous included, a clean 400. But `validate_input()`
runs **before** the execute callback, so it preempts the plugin's own guard, and the reply degrades
from *"Unknown input key: work_type. This ability accepts: job_type, company, location, …"* to core's
*"work_type is not a valid property of the object"* — the accepted set gone, and localised into the
site language while these messages are not. Self-correction in one turn is the entire point of the
guard's wording, so the guard wins and the declarative form is deliberately not used on the two
schemas that carry `properties`. `list-filters` is the exception and must stay one: it has no
`properties` at all, so `additionalProperties => false` is the only thing that can refuse a key there.

(The static lint in the WordPress `wp-abilities-verify` skill asks for the declarative form on every
object schema. On this plugin that recommendation is wrong, and the measurement above is why. Do not
"fix" it in a later tidy-up.)

### Exposure

Three independent switches in `meta`: `show_in_rest`, `public` (WP 7.1; inert passthrough on 7.0),
and `mcp.public` — not a core key at all, but the MCP adapter's own gate for discovery *and*
execution. All three annotations (`readonly`, `destructive`, `idempotent`) are set explicitly, because
they default to `null` and the REST run controller derives the HTTP verb from them: `readonly` makes
the run route **GET-only**, and POST answers 405.

`JPKCOM_ACFJOBS_ABILITIES = false` in `wp-config.php` suppresses registration entirely. Per-ability
control goes through `jpkcom_acf_jobs_ability_meta` and `jpkcom_acf_jobs_ability_capability` (default
capability `read`). Registration additionally requires `function_exists( 'get_field' )` — `Requires
Plugins` only blocks *activation*, and core does not block deactivating a dependency that has active
dependents.

Listing abilities over REST is gated only by `current_user_can( 'read' )`, so **every logged-in user
can read all three abilities' labels, descriptions and full schemas**. Execution is gated by the
permission callback, and every query is hard-scoped to `post_status => 'publish'`.

### Verifying against a real installation

`wp ability` needs WP-CLI ≥ 2.13; DDEV ships 2.12, so use `ddev wp eval-file <file>.php` with the file
in the DDEV project root. `tools/seed-jobs.php` and `tools/unseed-jobs.php` create and remove the edge
cases the happy path does not cover: a job with no `job_featured` row, an expired one, a closed one,
one with several `job_type` values, one with a `job_url`, and one whose description carries a
shortcode and a bare URL.

```bash
# The run routes are GET, because readonly => true. -g or the brackets are eaten.
BASE=https://your-site.test/wp-json/wp-abilities/v1/abilities
curl -skg -u "$USER:$APP_PW" "$BASE/jpkcom-acf-jobs/list-filters/run"
curl -skg -u "$USER:$APP_PW" "$BASE/jpkcom-acf-jobs/query-jobs/run"          # no input at all must work
curl -skg -u "$USER:$APP_PW" "$BASE/jpkcom-acf-jobs/get-job/run?input[id]=184"

# The read-only claim, measured rather than asserted:
# count oembed_cache rows before and after a get-job call on a job whose
# description contains a bare URL on its own line. They must be equal.
```

The check that matters is that filtering **narrows** — compare the filtered `total` against the
unfiltered one and against the count `list-filters` reports. A suite can pass while the filters never
reach `WP_Query` at all.

Verify in-process **and** over HTTP: the empty-map-as-`[]` defect exists only at `json_encode` time,
and the two consumers read different surfaces. And note that the floor is WP **7.0** since 1.5.0: a Throwable out
of a callback is now caught by core and surfaces as an error a client can act on, rather than the
blank page it produced on 6.9. Measurements are taken on 7.0.3 only.

---

## Common Patterns

### Adding a New Template Partial

1. Create file in `templates/partials/job/`
2. Use `jpkcom_acf_jobs_get_template_part('partials/job/filename')` to load
3. Optionally create debug version in `debug-templates/partials/job/`

### Querying Jobs with Meta Filters

Jobs support complex meta queries. Key meta fields:
- `job_featured` - Sorting priority (numeric)
- `job_expiry_date` - Date field (Y-m-d format)
- `job_type` - Serialized array (use LIKE '"VALUE"')
- `job_company` - Serialized array of post IDs
- `job_location` - Serialized array of post IDs

### Overriding Plugin Files (for end users)

Developers can override files without modifying plugin code:

**Templates**: Copy to theme directory:
```
/wp-content/themes/your-theme/jpkcom-acf-jobs/single-job.php
```

**Functional libraries**: Use filter:
```php
add_filter('jpkcom_acfjobs_file_paths', function($paths, $filename) {
    array_unshift($paths, WP_CONTENT_DIR . '/custom-overrides/' . $filename);
    return $paths;
}, 10, 2);
```

## Code Style

- Uses PHP 8.3 features (named parameters, type declarations)
- WordPress Coding Standards
- Text domain: `jpkcom-acf-jobs`
- All strings must be translatable with `__()`, `esc_html__()`, etc.
- Bootstrap 5 markup in templates
