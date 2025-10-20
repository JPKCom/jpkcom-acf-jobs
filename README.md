# JPKCom ACF Jobs

**Plugin Name:** JPKCom ACF Jobs  
**Plugin URI:** https://github.com/JPKCom/jpkcom-acf-jobs  
**Description:** Job application plugin for ACF  
**Version:** 1.0.5  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com/  
**Contributors:** JPKCom  
**Tags:** ACF, Fields, CPT, CTT, Taxonomy, Forms  
**Requires Plugins:** advanced-custom-fields-pro, acf-quickedit-fields  
**Requires at least:** 6.8  
**Tested up to:** 6.9  
**Requires PHP:** 8.3  
**Network:** true  
**Stable tag:** 1.0.5  
**License:** GPL-2.0+  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.txt  
**Text Domain:** jpkcom-acf-jobs  
**Domain Path:** /languages

A plugin to provide a job application tool for ACF Pro.


## Description

A plugin to provide a job application tool for ACF Pro.

The following plugins are required:

- [Advanced Custom Fields Pro](https://www.advancedcustomfields.com/)
- [ACF Quick Edit Fields](https://wordpress.org/plugins/acf-quickedit-fields/)

The following is included:

- Custom fields (`includes/acf-field_groups.php`)
- Custom content types (`includes/acf-post_types.php`)
- Custom taxonomies (`includes/acf-taxonomies.php`)

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

### How to overwrite template paths?

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

## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Make sure the 'Advanced Custom Fields' and the 'ACF QuickEdit Fields' plugins are activated.
4. Click 'Activate' to use the plugin right away.


## Changelog

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
