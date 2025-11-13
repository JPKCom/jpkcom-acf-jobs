# JPKCom ACF Jobs

**Plugin Name:** JPKCom ACF Jobs  
**Plugin URI:** https://github.com/JPKCom/jpkcom-acf-jobs  
**Description:** Job application plugin for ACF  
**Version:** 1.2.0  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com/  
**Contributors:** JPKCom  
**Tags:** ACF, Fields, CPT, CTT, Taxonomy, Forms  
**Requires Plugins:** advanced-custom-fields-pro, acf-quickedit-fields  
**Requires at least:** 6.8  
**Tested up to:** 6.9  
**Requires PHP:** 8.3  
**Network:** true  
**Stable tag:** 1.2.0  
**License:** GPL-2.0+  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.txt  
**Text Domain:** jpkcom-acf-jobs  
**Domain Path:** /languages

A plugin to provide a job application tool for ACF Pro.


## Description

**JPKCom ACF Jobs** is a job listing and application management system built on Advanced Custom Fields Pro. This plugin provides a complete solution for creating, managing, and displaying job postings on your WordPress website with powerful features for recruitment teams, HR departments, and job boards.

### Key Features

- **Three Custom Post Types**: Jobs, Locations, and Companies with hierarchical organization
- **Flexible Job Listings**: Full-time, part-time, contract, temporary, and internship positions
- **Advanced Filtering**: Filter jobs by type, location, company, and custom attributes
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

## Installation

### Prerequisites

Before installing this plugin, ensure you have:
- WordPress 6.8 or higher
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
3. **Review Settings**: Visit **Jobs → Settings** to configure default options (if available)
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
- Ensure PHP 8.3+ and WordPress 6.8+ requirements are met
- Check that ACF Pro is installed and activated first

**Issue: No Jobs menu in admin**
- Verify the plugin is activated (not just installed)
- Check for PHP errors in **Tools → Site Health → Info → Server**

**Issue: Templates not displaying correctly**
- Ensure your theme supports Bootstrap 5 markup, or customize the templates
- Enable `WP_DEBUG` to load debug templates for troubleshooting


## Changelog

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
