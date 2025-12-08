# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a WordPress plugin called **JPKCom ACF Jobs** - a job application system built on Advanced Custom Fields Pro. It provides custom post types (jobs, locations, companies), custom taxonomies, and a complete template system for displaying job listings with Schema.org markup.

**Requirements:**
- WordPress 6.8+
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
- Backward compatibility with manifests without checksums

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

### Version Management

Version number appears in THREE locations and must be kept in sync:
1. `jpkcom-acf-jobs.php` - Plugin header (line 6)
2. `jpkcom-acf-jobs.php` - Updater initialization (line 40)
3. `README.md` - Multiple locations in header metadata

### Release Process

Releases are automated via GitHub Actions (`.github/workflows/release.yml`):

1. Create a new Git tag: `git tag v1.x.x && git push --tags`
2. Create GitHub release from the tag on GitHub
3. Workflow automatically (triggered by `release: [published]` event):
   - **Extracts metadata** from `README.md` using Pandoc and bash
   - **Builds plugin ZIP** excluding git files, CLAUDE.md, and workflow files
   - **Generates SHA256 checksum** of the ZIP file (via `sha256sum`)
   - **Creates `.sha256` file** for manual verification
   - **Uploads both ZIP and `.sha256`** to the GitHub release
   - **Generates manifest JSON** (Python script) with:
     - Plugin metadata extracted from README.md
     - `download_url` pointing to GitHub release ZIP
     - `checksum_sha256` field containing the SHA256 hash
     - HTML sections (description, installation, changelog, FAQ) converted from Markdown
   - **Deploys to gh-pages** branch (manifest, HTML docs, assets)

**Key Workflow Steps:**
- Step 6: `Create plugin ZIP` (line 91-93)
- Step 6.1: `Generate SHA256 checksum` (line 95-102) - Creates hash for security verification
- Step 7: `Upload ZIP and checksum` (line 104-112) - Attaches files to release
- Step 8: `Generate plugin manifest JSON` (line 114-189) - Python script builds manifest with checksum
- Step 10: `Deploy to gh-pages` (line 201-208) - Publishes manifest and docs

**Important:** The SHA256 checksum in the manifest is automatically verified during plugin updates via `includes/class-plugin-updater.php`

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
