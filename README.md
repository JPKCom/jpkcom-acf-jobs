# JPKCom ACF Jobs

**Plugin Name:** JPKCom ACF Jobs  
**Plugin URI:** https://github.com/JPKCom/jpkcom-acf-jobs  
**Description:** Job application plugin for ACF  
**Version:** 1.5.5  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com/  
**Contributors:** JPKCom  
**Tags:** ACF, Fields, CPT, CTT, Taxonomy, Forms  
**Requires Plugins:** advanced-custom-fields-pro, acf-quickedit-fields  
**Requires at least:** 7.0  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Network:** true  
**Stable tag:** 1.5.5  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Text Domain:** jpkcom-acf-jobs  
**Domain Path:** /languages

A plugin to provide a job application tool for ACF Pro.


## Description

**JPKCom ACF Jobs** is a job listing and application management system built on Advanced Custom Fields Pro. This plugin provides a complete solution for creating, managing, and displaying job postings on your WordPress website with powerful features for recruitment teams, HR departments, and job boards.

### Key Features

- **Three Custom Post Types**: Jobs, Locations, and Companies with hierarchical organization
- **Flexible Job Listings**: Full-time, part-time, contract, temporary, and internship positions
- **Advanced Filtering**: Filter jobs by type, location, company, and custom attributes
- **Archive Control**: Disable or redirect the job archive page to any custom URL
- **Schema.org Integration**: Built-in JobPosting structured data for improved SEO and visibility in Google for Jobs
- **Multilingual Ready**: Full WPML support with translation-aware field configuration
- **Template Override System**: Customize any template via child theme, parent theme, or mu-plugins
- **Developer-Friendly**: Helper functions, filters, and shortcodes for easy customization
- **Bootstrap 5 Ready**: Pre-styled templates with modern responsive markup
- **Application Management**: Custom application buttons, forms, and contact information per job
- **Automatic Updates**: Secure GitHub-based plugin updates with SHA256 checksum verification

### Requirements

The following plugins are **required** for this plugin to work:

- [Advanced Custom Fields Pro](https://www.advancedcustomfields.com/) (v6.0+)
- [ACF Quick Edit Fields](https://wordpress.org/plugins/acf-quickedit-fields/) (for inline editing)

**Optional:**
- [WPML](https://wpml.org/) for multilingual job postings

### What's Included

- **Custom Fields** (`includes/acf-field_groups.php`) - Programmatically registered ACF field groups for jobs, locations, and companies
- **Custom Post Types** (`includes/acf-post_types.php`) - Job, Location, and Company post types with proper admin organization
- **Custom Taxonomies** (`includes/acf-taxonomies.php`) - Job attributes taxonomy for benefits, perks, and requirements
- **Template System** (`templates/`) - Complete set of single and archive templates with override support
- **Schema.org** (`includes/schema.php`) - Automatic JobPosting JSON-LD structured data generation
- **Shortcodes** (`includes/shortcodes.php`) - Display filtered job lists and attribute taxonomies anywhere
- **Helper Functions** (`includes/helpers.php`) - Utility functions for rendering fields and formatting dates

### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-acf-jobs/docs/](https://jpkcom.github.io/jpkcom-acf-jobs/docs/)

The documentation includes detailed information about all functions, classes, hooks, and filters available in the plugin.

### Get Template Parts

```php
// Native WordPress:
get_template_part( 'jpkcom-acf-jobs/partials/job/company' );
// Plugin:
jpkcom_acf_jobs_get_template_part( 'partials/job/company' );
```

### Shortcodes

All shortcode attributes are optional.

#### Job list with filter functions:
```
[jpkcom_acf_jobs_list type="FULL_TIME" company="6,8" location="1,3,7,11" limit="10" sort="DSC" style="background:transparent;" class="mb-5" title="Attributes Headline"]
```

#### List of attributes displayd as `<details>` tags with filter functions:
```
[jpkcom_acf_jobs_attributes id="3,7,21" style="background:transparent;" class="mb-5" title="Attributes Headline"]
```

### Helper functions

#### Renders all ACF fields of a post with Bootstrap 5 markup.

`@param string $post_type` Optional post type for field group query. If empty, 'current_post_type' is used.

```php
jpkcom_render_acf_fields();
```

### Abilities API (since 1.4.0)

The plugin registers three **read-only** WordPress Abilities, so AI assistants, MCP clients and REST
automation can query your job listings as structured data instead of scraping the page:

- `jpkcom-acf-jobs/list-filters` — which job types, companies, locations and job attributes exist on
  this site, so a caller can filter with real values instead of guessing
- `jpkcom-acf-jobs/query-jobs` — a filtered, paginated list of jobs
- `jpkcom-acf-jobs/get-job` — one job in full

Who can use them, and what they see:

- Access requires a **logged-in user** with the `read` capability — that includes subscribers. Anonymous
  requests are rejected.
- Only **published** jobs are ever returned, and only those your site's own listing shows. The abilities
  apply exactly the same visibility rule as the `/jobs/` archive and the `[jpkcom_acf_jobs_list]`
  shortcode.
- Detailed fields — salary, postal address, application details, the full job description — are returned
  **only for jobs whose detail page a visitor could actually open**. A job that redirects to an external
  application URL and an expired job have no public detail page, so those fields are withheld and the
  reason is stated in the response.
- A **password-protected** job is a different case and is not covered by the point above: it is not
  trimmed, it is not returned at all. `query-jobs` omits it from the results, `list-filters` does not
  count it when building the filter vocabulary, and `get-job` answers for it with the same message and
  the same 404 it gives an ID that names nothing — deliberately identical, so that the abilities cannot
  be used to work out which post IDs the site holds. Note that the `/jobs/` archive and the
  `[jpkcom_acf_jobs_list]` shortcode *do* list such a job, in WordPress' usual protected form, so this
  is the one place where the abilities are narrower than the site's own listing.
- Note that WordPress lists the abilities themselves — their names, descriptions and parameter
  definitions — to any logged-in user. That is core behaviour, not a setting of this plugin.

To switch the feature off entirely, add this to `wp-config.php`:

```php
define( 'JPKCOM_ACFJOBS_ABILITIES', false );
```

To keep the abilities but restrict who may run them, raise the required capability:

```php
add_filter( 'jpkcom_acf_jobs_ability_capability', static function ( $capability ) {
    return 'edit_posts';
} );
```

## FAQ

### Why do I need Advanced Custom Fields Pro?

This plugin relies on ACF Pro's powerful field group system to provide flexible job data management. ACF Pro offers advanced field types (repeaters, flexible content, groups) that are essential for complex job listings with salary information, multiple locations, and rich content layouts.

### How do I create my first job posting?

1. After activation, go to **Jobs → Add New** in your WordPress admin
2. Enter the job title and description
3. Fill in the ACF fields: job type, location, company, salary, etc.
4. Add job attributes (benefits, requirements) using the taxonomy on the right
5. Set an expiry date if the position is time-limited
6. Publish the job

The job will automatically appear in your job archive and be indexed by search engines with Schema.org markup.

### How do I display jobs on my website?

**Option 1: Use the shortcode**
```
[jpkcom_acf_jobs_list limit="10" sort="DSC"]
```

**Option 2: Navigate to the archive**
Visit `/jobs/` on your site to see all published jobs.

**Option 3: Create a custom template**
Use `WP_Query` with `post_type => 'job'` to build custom job displays.

### How does the Schema.org integration work?

The plugin automatically generates JobPosting structured data (JSON-LD) for each job post. This markup is recognized by Google for Jobs and other search engines, improving visibility and displaying rich snippets in search results. No configuration needed - it works out of the box!

### Is this plugin compatible with WPML?

Yes! The plugin includes full WPML support via `wpml-config.xml`. Jobs, locations, companies, and taxonomies can all be translated. Fields are configured with appropriate translation strategies (translate, copy, or copy-once) for optimal multilingual workflow.

### How do I customize the job templates?

You have three options:

**Option 1: Child Theme Override** (Recommended)
Copy templates from `plugins/jpkcom-acf-jobs/templates/` to `your-child-theme/jpkcom-acf-jobs/` and customize them.

**Option 2: Parent Theme Override**
Copy templates to `your-theme/jpkcom-acf-jobs/` (works if no child theme is active).

**Option 3: MU-Plugin Override**
Copy templates to `mu-plugins/jpkcom-acf-jobs-overrides/templates/` for site-wide customization.

### How to overwrite functional libraries?

```php
/**
 * Add new path for overwrites of functional libraries
 */
add_filter( 'jpkcom_acfjobs_file_paths', function( $paths, $filename ) {
    array_unshift( $paths, WP_CONTENT_DIR . '/custom-overrides/' . $filename );
    return $paths;
}, 10, 2 );
```

### How to overwrite template paths programmatically?

```php
/**
 * Add a new path, for example from the child theme or custom directory
 */
add_filter( 'jpkcom_acf_jobs_template_paths', function( $paths, $template_name ) {
    array_unshift( $paths, WP_CONTENT_DIR . '/custom-templates/jpkcom-acf-jobs/' . $template_name );
    return $paths;
}, 10, 2 );
```

```php
/**
 * Last chance to dynamically overwrite template path
 */
add_filter( 'jpkcom_acf_jobs_final_template', function( $template ) {
    if ( is_singular( 'job' ) ) {
        return WP_CONTENT_DIR . '/special/single-job-custom.php';
    }
    return $template;
});
```

### How do plugin updates work?

The plugin uses a secure GitHub-based update system. When a new version is released:

1. WordPress checks `https://jpkcom.github.io/jpkcom-acf-jobs/plugin_jpkcom-acf-jobs.json` for updates
2. Update notifications appear in your WordPress admin (Plugins page and Updates page)
3. When you click "Update Now", WordPress downloads the plugin ZIP from GitHub
4. The download is verified using SHA256 checksum for security
5. If the checksum matches, the update proceeds automatically

You can also download releases manually from the [GitHub repository](https://github.com/JPKCom/jpkcom-acf-jobs/releases).

### Can I filter jobs by location or company?

Yes! Use the shortcode attributes:

```
[jpkcom_acf_jobs_list location="1,3,7" company="6,8" type="FULL_TIME"]
```

Location and company values are post IDs. You can find them in the admin when editing locations or companies (look at the URL: `post=123`).

### What are job attributes?

Job attributes are custom taxonomy terms (like tags) that you can assign to jobs. Use them for:
- Benefits: "Health Insurance", "Remote Work", "Flexible Hours"
- Requirements: "Driver's License", "Security Clearance"
- Perks: "Company Car", "Free Lunch", "Gym Membership"

Display them with the shortcode:
```
[jpkcom_acf_jobs_attributes]
```

### How do I disable the job archive page?

If you want to prevent visitors from accessing the job archive page (`/jobs/`), you can disable it:

1. Go to **Jobs → Options** in your WordPress admin
2. Check the box **"Disable Job Archive"**
3. Optionally, specify a custom redirect URL (e.g., `/careers/` or `/contact/`)
4. Click **Save Changes**

When enabled:
- Visitors accessing `/jobs/` will be redirected to your specified URL (or homepage if empty)
- Individual job pages (`/jobs/job-title/`) remain fully accessible
- The redirect uses HTTP 307 (Temporary Redirect) status

This is useful when you want to use shortcodes to display jobs on custom pages instead of the default archive.

## Installation

### Prerequisites

Before installing this plugin, ensure you have:
- WordPress 6.9 or higher
- PHP 8.3 or higher
- [Advanced Custom Fields Pro](https://www.advancedcustomfields.com/) installed and activated
- [ACF Quick Edit Fields](https://wordpress.org/plugins/acf-quickedit-fields/) installed and activated

### Method 1: Upload via WordPress Admin (Recommended)

1. Download the latest release ZIP file from the [GitHub Releases page](https://github.com/JPKCom/jpkcom-acf-jobs/releases)
2. In your WordPress admin panel, navigate to **Plugins → Add New**
3. Click the **Upload Plugin** button at the top of the page
4. Click **Choose File** and select the downloaded `jpkcom-acf-jobs.zip` file
5. Click **Install Now** and wait for the upload to complete
6. Click **Activate Plugin** to enable the plugin immediately

### Method 2: Manual Installation via FTP/SFTP

1. Download the latest release ZIP file from the [GitHub Releases page](https://github.com/JPKCom/jpkcom-acf-jobs/releases)
2. Extract the ZIP file on your local computer
3. Using an FTP/SFTP client, upload the extracted `jpkcom-acf-jobs` folder to `/wp-content/plugins/`
4. In your WordPress admin panel, navigate to **Plugins**
5. Find "JPKCom ACF Jobs" in the list and click **Activate**

### Method 3: GitHub Clone (For Developers)

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/JPKCom/jpkcom-acf-jobs.git
```

Then activate the plugin in the WordPress admin panel.

### Post-Installation Steps

1. **Verify Dependencies**: Go to **Plugins** and ensure ACF Pro and ACF Quick Edit Fields are active
2. **Check Custom Post Types**: You should now see **Jobs**, **Locations**, and **Companies** in your admin menu
3. **Review Settings**: Visit **Jobs → Options** to configure archive settings and other options
4. **Create Test Content**:
   - Create a location: **Locations → Add New**
   - Create a company: **Companies → Add New**
   - Create a job: **Jobs → Add New** (assign the location and company)
5. **View Frontend**: Visit `/jobs/` on your site to see the job archive
6. **Add to Navigation** (Optional): Add the job archive to your site menu via **Appearance → Menus**

### Automatic Updates

Once installed, the plugin will automatically check for updates from GitHub. Update notifications will appear in:
- **Dashboard → Updates**
- **Plugins** page (update notice below plugin name)

Simply click **Update Now** to install the latest version securely with SHA256 checksum verification.

### Multisite Installation

This plugin is **network-compatible**. To install on a multisite network:

1. Follow Method 1 or 2 above
2. Go to **Network Admin → Plugins**
3. Click **Network Activate** to enable on all sites, or activate individually per site

### Troubleshooting Installation

**Issue: Plugin fails to activate**
- Ensure PHP 8.3+ and WordPress 6.9+ requirements are met
- Check that ACF Pro is installed and activated first

**Issue: No Jobs menu in admin**
- Verify the plugin is activated (not just installed)
- Check for PHP errors in **Tools → Site Health → Info → Server**

**Issue: Templates not displaying correctly**
- Ensure your theme supports Bootstrap 5 markup, or customize the templates
- Enable `WP_DEBUG` to load debug templates for troubleshooting


## Changelog

### 1.5.5

* Fixed: the 1.4.0 release note and the Abilities API section both listed a password-protected job alongside a redirecting one and an expired one, as a job whose detailed fields are withheld. That understated what actually happens. A redirecting or expired job **is** returned, as a summary, with the reason stated. A password-protected job is not returned at all: `query-jobs` omits it, `list-filters` does not count it, and `get-job` answers with the same message and the same 404 it gives an id that names nothing — deliberately identical, so the abilities cannot be used to work out which post IDs the site holds. Both places have been corrected, and the documentation now also records that the `/jobs/` archive and the `[jpkcom_acf_jobs_list]` shortcode *do* list such a job, which makes this the one point where the abilities are narrower than the site's own listing. No code is affected — the behaviour was always this; only its description was wrong

### 1.5.4
* Added: the updater's four security messages are now translated in all seven languages — German, Spanish, French, Hungarian, Italian and Polish. These are the messages a site owner sees when an update is refused because its checksum does not match or cannot be checked at all, so they are exactly the ones that should not appear in a foreign language
* Changed: the five translations without a `.po` — Spanish, French, Hungarian, Italian, Polish — were extended directly in their PHP translation file, the format WordPress loads first and the one they were written in. Their existing entries are untouched, verified entry by entry
* Hardened: the build check added in 1.5.2 reported entries carrying a context as missing from the compiled catalogue although they were present. It now reads the context the way WordPress stores it

### 1.5.3
* Fixed: the note published with 1.5.1 about the Spanish, French, Hungarian, Italian and Polish translations was wrong. It said they existed "only in their compiled form", could not be updated and were frozen. None of that is true. Those five are authored directly as PHP translation files — the format WordPress has loaded first since 6.5, and a supported way to ship a translation, not a by-product of something else. They are maintainable in exactly the way they were written. What is true is narrower: they cover the texts of the earlier releases and have not caught up with the newer ones, which is an ordinary backlog. The note has been corrected and the same wrong assumption removed from the developer documentation
* Changed: the build check introduced in 1.5.2 no longer treats a PHP translation file containing more than its `.po` as a failure. That is a legitimate state here — for five of the seven locales the PHP file is the only source. It is now reported as a note, with the warning that regenerating from the `.po` would delete the difference, which is the mistake 1.5.1 actually made. A translation present in the `.po` and missing from the PHP file still fails the build, because it means the translation is not being served

### 1.5.2
* Fixed: version 1.5.1 removed 27 German translations. They existed only in the compiled translation file and not in the source file it is generated from, so regenerating the source overwrote them — and because WordPress reads the compiled file first, those were the translations actually being shown. Affected were parts of the settings screens and the employment type labels, among others. All 27 are restored and are now in the source file as well, so they can be maintained for the first time. Nothing else changed: no existing translation was altered
* Hardened: the build now also compares the compiled translation file against its source, in both directions. A translation in the source but not in the compiled file means the build step was skipped; one in the compiled file but not in the source means the source is not the source, and the next regeneration destroys work — which is precisely what happened in 1.5.1. Neither can pass unnoticed again

### 1.5.1
* Fixed: the translation catalogue was last generated in October 2025 and had fallen far behind. It listed 64 texts while the abilities file alone contains 129, so every message the abilities return appeared in English on a translated site, and nothing indicated that. The catalogue now covers the whole plugin: 64 entries became 210. The existing German translations are unchanged and one obsolete entry was dropped; the newly listed texts are not translated yet and still appear in English
* Fixed: an explanatory comment had been placed between the note for translators and the text it describes, which silently detached the two — the note would never have reached a translator. Found by the first run of the regeneration step this release adds
* Hardened: the build now fails when the catalogue falls behind the code. Regenerating it was neither automated nor on the release checklist, which is how ten months went by without anyone noticing. It is now both
* Note: the Spanish, French, Hungarian, Italian and Polish translations are maintained directly as PHP translation files, the format WordPress loads first, and have no `.po` alongside them. They currently cover the texts of the previous releases and are behind the rest. **The wording originally published here — that they could not be updated and were frozen — was wrong; see 1.5.3**

### 1.5.0
* Fixed: `jpkcom-acf-jobs/get-job` accepted input it does not understand. Sending a parameter the ability never declared — a mistyped name, or a filter that only exists on `query-jobs` — was answered with a normal, successful response in which that parameter had simply been ignored. The other two abilities refused the same input with a clear error naming what they accept, so a caller that had learned the rule from them had every reason to trust the one ability that did not follow it. `get-job` now refuses it the same way. **This can change what an existing caller sees:** a request that sent extra parameters and got an answer will now get an error instead, naming the parameter it rejected and the ones it accepts
* Changed: the message all three abilities return for an unrecognised parameter is now worded for all of them. It previously explained the danger only in terms of filtering, which does not describe `get-job`, where the answer is determined by the job ID alone
* Changed: WordPress 7.0 is now the minimum. Up to 6.9 an unexpected error inside an ability callback ended the whole request with a blank page instead of a readable message, and the plugin carried its own guards against that. From 7.0 WordPress catches it itself. The guards stay in place, but the plugin is no longer tested against 6.9 and no longer claims to run there
* Hardened: two build checks were added, because the defect above was invisible to a green test suite — every check written for the parameter guard happened to target an ability that had it. One check now asserts that every ability calls the guard at all; the other compares the parameters each ability guards against the parameters it publishes in its schema, so the two cannot drift apart unnoticed
* Changed: the "no input given" default of `list-filters` and `query-jobs` is now written directly as an empty object instead of being produced by a helper. What clients receive is unchanged

### 1.4.0
* Added: three read-only WordPress Abilities — `jpkcom-acf-jobs/list-filters`, `jpkcom-acf-jobs/query-jobs` and `jpkcom-acf-jobs/get-job` — so AI assistants, MCP clients and REST automation can read your job listings as structured data instead of scraping the page. They are on by default for logged-in users with the `read` capability and can be switched off with `define( 'JPKCOM_ACFJOBS_ABILITIES', false )`; see the Abilities API section above
* Added: `jpkcom_acf_jobs_ability_meta`, `jpkcom_acf_jobs_ability_capability` and `jpkcom_acf_jobs_ability_query_args` filters, so a site can change which abilities are exposed, who may run them, and what their query contains
* Added: the abilities return only jobs your site's own listing shows, and withhold salary, address and application details for jobs that have no public detail page — one that redirects to an external application URL, or one that has expired. **This entry as originally published also named password-protected jobs here. That was wrong: such a job is not trimmed, it is not returned at all, by any of the three abilities; see 1.5.5**
* Added: `includes/jobs-data.php`, which now holds the job visibility rule that previously existed as three separate copies — in the `[jpkcom_acf_jobs_list]` shortcode, in the job archive query, and about to become a fourth. The shortcode and the archive return exactly what they returned before; this was verified by comparing the generated SQL, the returned post IDs and the rendered HTML before and after the change
* Hardened: a single job whose stored data is corrupt — the usual causes are an import, a migration or a translation copy — no longer takes the whole listing down. It used to make the query answer with a blank server error for every caller, on whichever page that job fell, until someone repaired the data. The job is now left out of the list, the rest of the page answers normally, and the response says how many were left out
* Hardened: asking for one job whose *detail* data is unreadable now returns that job's summary with a stated reason instead of claiming the job does not exist — which it did while the listing was showing that same job
* Fixed: the visibility figures beside the filter list were wrong on any site whose jobs have no expiry date, which is the ordinary case once the date field has been saved and cleared. Every such job was counted as expired while the same response listed it, so the numbers could add up to more jobs than the site has. They are now derived from the listing rule itself and add up exactly
* Changed: the job query no longer returns a site-wide visibility block beside a filtered result. The figures never changed with the filters, so reading them next to a filtered total suggested jobs that do not exist. They remain on the filter-list ability, where they describe the site
* Fixed: `include_closed` — the only switch that changes which jobs come back — could not be sent over the REST route at all, because that route accepts only GET and the parameter demanded a strict true/false. It now accepts the same spellings every other WordPress REST endpoint does
* Fixed: the filter-list ability published an input description that is not valid JSON Schema, which made strict AI clients reject the tool — and with it the other two, since a client that refuses one entry can refuse the whole list. A later attempt at that fix briefly made any request with an unrecognised parameter answer with a server error, without credentials; both are closed
* Fixed: a parameter the abilities do not accept — a typo, or a filter sent at the wrong nesting level — used to be ignored silently, so the answer was every job on the site presented as a filtered result. All three abilities now refuse it and name what they accept
* Fixed: the search description promised to cover job content. It searches titles only, because this plugin stores job text in ACF fields; a search that found nothing was being read as proof that no such job exists. The description now says what it does not reach and points at the filters that do
* Hardened: if another plugin alters the job query after this one has built it, the abilities now detect that the query which ran is not the query they built and refuse to answer, rather than returning an unfiltered list labelled as a filtered one. What they cannot detect — a plugin that changes only paging, ordering or the search term — is documented rather than implied
* Added: `tools/seed-jobs.php` and `tools/unseed-jobs.php` for creating and removing the edge-case job fixtures a test installation needs
* Fixed: the source-level guards in `tests/test-conventions.php` matched only one spelling of the date rule, so `gmdate( 'Y-m-d' )` and `date( 'Y-m-d', $timestamp )` both slipped past although each carries the timezone bug the rule exists to prevent. They also scanned `includes/` alone, and the taxonomy guard could not see the `'taxonomy' => '…'` array form. All three gaps are closed
* Note for developers: reading an ACF wysiwyg field with the default two-argument `get_field()` runs WordPress's shortcode and oEmbed pipeline, which for a job description containing a bare URL performs an outbound HTTP request and creates a database row. Every long-form field in this plugin is now read unformatted, and a test fails the build if that changes. `CLAUDE.md` documents this and eleven further traps in detail

### 1.3.11
* Fixed: the debug schema template printed an untranslated German sentence after the translated parse-error message; it now prints the translated message alone, escaped with `esc_html__()`
* Changed: the update manifest generator now defaults a missing `Network:` header to false instead of true, matching WordPress' own default. No change for this plugin, which declares `Network: true` explicitly
* CI: the lint and guard workflow now also runs on pushes to `main`. It only covered pull requests, so a direct push with bypass rights skipped every check
* Changed: comments, workflow step names and CI output across the repository are now English throughout, and the developer notes in `CLAUDE.md` were translated and trimmed. No effect on the shipped plugin

### 1.3.10
* Changed: `Tested up to` raised to WordPress 7.1
* Changed: the bundled updater's runtime floor now matches the plugin's own minimum. It bailed out below WordPress 6.8 while the plugin header has required 6.9 for several releases, so the check could never fire on a supported installation
* Docs: the remaining "WordPress 6.8" requirement statements now say 6.9, matching the plugin header
* CI: the release manifest's fallback values for `requires` and `tested` now say 6.9 and 7.1. They only apply when the README metadata cannot be read, but a stale fallback would have published a minimum the plugin no longer supports

### 1.3.9
* Changed: the plugin banners (`assets/banner-1544x500.avif`, `assets/banner-772x250.avif`) are now a plain `#3c4955` surface with no lettering

### 1.3.8
* Fixed: the attributes partial looked terms up under `job_attribute`, the field name, instead of `job-attribute`, the registered taxonomy. `get_term_by()` returned false for every string value and the attribute was dropped from the output with no error anywhere. Not reached with the shipped `return_format => 'id'`, but immediate the moment anything hands that partial strings
* Fixed: `tools/check-term-sync.php` could not run at all — a `declare(strict_types=1)` halfway down the file made the documented `wp eval-file` invocation a fatal error
* Fixed: the same script reported every translated post as drifted, because it compared raw meta against `wp_get_object_terms()`, which WPML rewrites to the current language
* Added: `tests/test-conventions.php` compares every literal taxonomy argument against the slugs actually passed to `register_taxonomy()`
* Docs: corrected the section on `tax_query`. None of the three fields the list shortcode filters is taxonomy-backed, so the switch made in `jpkcom-acf-references` does not apply here

### 1.3.7
* **Fixed:** job expiry was compared against the UTC date. WordPress sets the PHP timezone to UTC, so `date( 'Y-m-d' )` returns the UTC day and expired listings stayed visible for the length of the site's UTC offset after local midnight (1–2 hours for Europe/Berlin). `shortcodes.php`, `archive.php` and `redirects.php` now use `current_time( 'Y-m-d' )`. The `date()` call in `schema.php` is deliberately unchanged — it round-trips a stored date string and never refers to "now"
* **Fixed:** the single-job redirect checked `current_user_can( 'administrator' )`, passing a role name where a capability belongs. That works only because the role is a key in the capability array, bypassing `map_meta_cap` and missing differently named roles with the same rights. Now checks `manage_options`
* **Added:** `tools/check-term-sync.php` — a read-only checker reporting whether the serialised `job_attribute` meta values and the real `job-attribute` term assignments agree. Groundwork for moving the shortcode filters from unindexed `meta_query` + `LIKE` to indexed `tax_query`
* **Added:** `tests/test-conventions.php` — regression guards for both fixes above, precise enough to leave the legitimate `schema.php` date call alone. Run in CI on every pull request
* **Docs:** `CLAUDE.md` gained the Security & Correctness section it was missing — the only JPKCom plugin without one

### 1.3.6
* Security: update packages are now verified *before* installation — the verified file is handed to WordPress instead of being downloaded a second time, so the bytes that were checked are the bytes that get installed
* Security: a missing or unfetchable SHA-256 checksum now aborts the update instead of installing unverified code (previously it silently skipped verification)
* Security: pinned every GitHub Action to a full commit SHA and added Dependabot with a 7-day cooldown, so a moved tag can no longer change the release build
* Security: tightened which download the updater claims, so sibling plugins cannot match each other's package
* Fixed: `sprintf()` calls in the updater bound named arguments to a variadic parameter, which raises `ArgumentCountError` on PHP 8.3
* Fixed: the "View Details" modal could fail with a `TypeError` when the manifest omitted `requires_plugins`
* Performance: a failed manifest fetch is now cached for an hour instead of being retried on every admin request
* Added: CI workflow on every pull request (PHP lint, named-argument check, YAML validation, action-pinning guard)

### 1.3.5
* Fixed broken `<main>` element in the single job template: it was closed immediately after opening, leaving the entire job content outside of it and producing an unmatched closing tag at the end of the template
* Fixed a leftover duplicate logo call in the job company partial that passed the ACF field array where an attachment ID is expected, which could render an unrelated image before every company logo

### 1.3.4
* Raised the minimum WordPress version to 6.9 and "Tested up to" to WordPress 7.0
* Switched license metadata to the SPDX identifier `GPL-2.0-or-later` with the HTTPS license URI

### 1.3.3
* Security: prevent JSON-LD script-tag breakout (stored XSS) in JobPosting schema output (`JSON_HEX_TAG | JSON_HEX_AMP`, plus output-point hardening in `single-job.php`)
* Security: updater prefers exact match against manifest `download_url` over the slug heuristic, so a tampered manifest can no longer bypass the checksum gate
* Security: updater checksum comparison is now timing-safe (`hash_equals()`) with an `is_string()` guard against `hash_file()` failures
* Security: manifest fetch uses `wp_safe_remote_get()` (SSRF defense-in-depth)
* Fixed PHP warning + missing contributor names in the plugin detail popup (`display_name` now provided)
* Fixed PHP warning/deprecation on `wp plugin list` by completing the `no_update` transient entry (`new_version`, `package`, `tested`, `requires_php`)

### 1.3.2
* Fixed missing output escaping across all templates and debug templates (`esc_html__()`, `esc_html()`, `esc_url()`, `esc_attr()`)
* Fixed pagination template: added `flex-wrap` with `row-gap-2` for responsive wrapping on small screens
* Fixed pagination template: empty `<li>` elements no longer rendered when no previous/next post exists
* Fixed pagination template: previous/next links now use proper `page-link` class for consistent Bootstrap styling
* Fixed pagination template: archive URL now escaped with `esc_url()`

### 1.3.1
* Fixed updater checksum verification failing on manual ZIP uploads (local file path instead of URL)
* Fixed release ZIP missing top-level directory, causing WordPress to not recognize the update

### 1.3.0
* Added archiv redirect options

### 1.2.5
* Added translation for "job_type"

### 1.2.4
* Added translations for ES, FR, HU, IT and PL

### 1.2.3
* Added check for "SitePress" class

### 1.2.2
* Fix for incorrect database content caused by WPML

### 1.2.1
* Fix for incorrect database content caused by WPML

### 1.2.0
* Security enhancement
* AI support

### 1.1.12
* Added WPML support

### 1.1.11
* Improvements to jpkcom_acfjobs_textdomain()

### 1.1.10
* Updater bugfix

### 1.1.9
* "display_name" for update-core.php

### 1.1.8
* "display_name" for plugin-install.php

### 1.1.7
* New namespace for updater

### 1.1.6
* Updater bugfix

### 1.1.5
* Plugin icon support

### 1.1.4
* Plugin details

### 1.1.3
* Updater bugfix

### 1.1.2
* Improvements for GitHub workflow

### 1.1.1
* Improvements for GitHub workflow

### 1.1.0
* Improvements for GitHub workflow

### 1.0.9
* Removed plugin dependency

### 1.0.8
* Improvements for GitHub workflow
* Improvements to plugin JSON
* Updater improvements

### 1.0.7
* Improvements for GitHub workflow
* Bugfix plugin JSON
* Updater improvements

### 1.0.6
* Bugfix for GitHub workflow

### 1.0.5
* Bugfix plugin JSON

### 1.0.4
* Bugfix for GitHub workflow

### 1.0.3
* Updater improvements

### 1.0.2
* Bugfix for GitHub workflow

### 1.0.1
* GitHub workflow

### 1.0.0
* Initial Release
