#!/usr/bin/env php
<?php
/**
 * WPML Field Testing Script
 *
 * This script helps debug WPML field translation issues in DDEV.
 * Run in DDEV: ddev exec php /path/to/test-wpml-fields.php
 * Or via WP-CLI: ddev wp eval-file test-wpml-fields.php
 *
 * Usage: Place this in the plugin root and run from WordPress/DDEV context
 */

// This must be run within WordPress context
if (!defined('ABSPATH')) {
    echo "❌ Error: This script must be run within WordPress context\n";
    echo "Usage: ddev wp eval-file test-wpml-fields.php\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "WPML ACF Field Translation Test\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Check if WPML is active
if (!function_exists('icl_object_id')) {
    echo "❌ WPML is not active!\n";
    exit(1);
}

// Check if ACF is active
if (!function_exists('get_field')) {
    echo "❌ ACF is not active!\n";
    exit(1);
}

echo "✓ WPML is active\n";
echo "✓ ACF is active\n\n";

// Get current language
$current_lang = apply_filters('wpml_current_language', NULL);
echo "Current Language: {$current_lang}\n\n";

// Find a job post to test
$job_args = array(
    'post_type' => 'job',
    'posts_per_page' => 1,
    'post_status' => 'publish',
    'suppress_filters' => false, // Important for WPML
);

$jobs = get_posts($job_args);

if (empty($jobs)) {
    echo "⚠️  No job posts found. Please create a job first.\n";
    exit(0);
}

$job = $jobs[0];
echo "Testing Job: {$job->post_title} (ID: {$job->ID})\n\n";

// Test job_type field
echo "─────────────────────────────────────────────────────\n";
echo "Testing job_type (Checkbox Field):\n";
echo "─────────────────────────────────────────────────────\n";
$job_type = get_field('job_type', $job->ID);
echo "Value: ";
var_export($job_type);
echo "\nType: " . gettype($job_type) . "\n";

if (is_array($job_type)) {
    echo "✓ Correctly returns array\n";
    echo "Values:\n";
    foreach ($job_type as $type) {
        if (is_array($type) && isset($type['value'], $type['label'])) {
            echo "  - {$type['value']} ({$type['label']})\n";
        } else {
            echo "  - " . print_r($type, true) . "\n";
        }
    }
} else {
    echo "❌ ERROR: Should be array, got " . gettype($job_type) . "\n";
    echo "Raw value: " . $job_type . "\n";
}
echo "\n";

// Test job_location field
echo "─────────────────────────────────────────────────────\n";
echo "Testing job_location (Post Object Field):\n";
echo "─────────────────────────────────────────────────────\n";
$job_location = get_field('job_location', $job->ID);
echo "Value type: " . gettype($job_location) . "\n";

if (is_array($job_location)) {
    echo "✓ Correctly returns array of post objects\n";
    foreach ($job_location as $location) {
        if (is_object($location)) {
            echo "  - {$location->post_title} (ID: {$location->ID}, Type: {$location->post_type})\n";
            if ($location->post_type !== 'job_location') {
                echo "    ❌ ERROR: Wrong post type! Should be 'job_location'\n";
            }
        }
    }
} elseif (is_object($job_location)) {
    echo "Single location: {$job_location->post_title} (Type: {$job_location->post_type})\n";
    if ($job_location->post_type !== 'job_location') {
        echo "❌ ERROR: Wrong post type! Should be 'job_location', got '{$job_location->post_type}'\n";
    } else {
        echo "✓ Correct post type\n";
    }
} else {
    echo "❌ ERROR: Unexpected type: " . gettype($job_location) . "\n";
    var_export($job_location);
}
echo "\n";

// Test job_company field
echo "─────────────────────────────────────────────────────\n";
echo "Testing job_company (Post Object Field):\n";
echo "─────────────────────────────────────────────────────\n";
$job_company = get_field('job_company', $job->ID);
echo "Value type: " . gettype($job_company) . "\n";

if (is_array($job_company)) {
    echo "✓ Correctly returns array of post objects\n";
    foreach ($job_company as $company) {
        if (is_object($company)) {
            echo "  - {$company->post_title} (ID: {$company->ID}, Type: {$company->post_type})\n";
            if ($company->post_type !== 'job_company') {
                echo "    ❌ ERROR: Wrong post type! Should be 'job_company'\n";
            }
        }
    }
} elseif (is_object($job_company)) {
    echo "Single company: {$job_company->post_title} (Type: {$job_company->post_type})\n";
    if ($job_company->post_type !== 'job_company') {
        echo "❌ ERROR: Wrong post type! Should be 'job_company', got '{$job_company->post_type}'\n";
    } else {
        echo "✓ Correct post type\n";
    }
} else {
    echo "❌ ERROR: Unexpected type: " . gettype($job_company) . "\n";
    var_export($job_company);
}
echo "\n";

// Check for translations
$available_languages = apply_filters('wpml_active_languages', NULL);
if (count($available_languages) > 1) {
    echo "─────────────────────────────────────────────────────\n";
    echo "Available Translations:\n";
    echo "─────────────────────────────────────────────────────\n";

    foreach ($available_languages as $lang_code => $lang_info) {
        $translated_id = apply_filters('wpml_object_id', $job->ID, 'job', FALSE, $lang_code);
        if ($translated_id && $translated_id != $job->ID) {
            $translated_job = get_post($translated_id);
            echo "\n{$lang_info['native_name']} ({$lang_code}): {$translated_job->post_title}\n";

            // Test fields in translated version
            $trans_type = get_field('job_type', $translated_id);
            $trans_location = get_field('job_location', $translated_id);
            $trans_company = get_field('job_company', $translated_id);

            echo "  job_type: " . (is_array($trans_type) ? "✓ array" : "❌ " . gettype($trans_type)) . "\n";
            echo "  job_location: " . (is_object($trans_location) || is_array($trans_location) ? "✓ object/array" : "❌ " . gettype($trans_location)) . "\n";
            echo "  job_company: " . (is_object($trans_company) || is_array($trans_company) ? "✓ object/array" : "❌ " . gettype($trans_company)) . "\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "Test Complete\n";
echo "═══════════════════════════════════════════════════════════\n";
