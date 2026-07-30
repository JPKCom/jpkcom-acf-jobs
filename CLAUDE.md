# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a WordPress plugin called **JPKCom ACF Jobs** - a job application system built on Advanced Custom Fields Pro. It provides custom post types (jobs, locations, companies), custom taxonomies, and a complete template system for displaying job listings with Schema.org markup.

**Requirements:**
- WordPress 6.9+
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
- `jpkcom_acf_jobs_list_query_args` - Modify shortcode query arguments

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
