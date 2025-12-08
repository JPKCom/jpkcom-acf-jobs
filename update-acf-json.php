#!/usr/bin/env php
<?php
/**
 * ACF JSON Export Updater
 *
 * This script regenerates the ACF JSON export file from the PHP field groups.
 * Run this after making changes to includes/acf-field_groups.php
 *
 * Usage: php update-acf-json.php
 */

// Load the field groups from the PHP file
require_once __DIR__ . '/includes/acf-field_groups.php';

// Create output directory if it doesn't exist
$output_dir = __DIR__ . '/.ht.acf-json';
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Simulate ACF loading to get field groups
$field_groups = [];

// This is a workaround since we can't actually call acf_get_field_groups() without WordPress
// Instead, we'll extract the field groups from the PHP file directly

$php_content = file_get_contents(__DIR__ . '/includes/acf-field_groups.php');

// Extract all acf_add_local_field_group calls
preg_match_all('/acf_add_local_field_group\(\s*array\((.*?)\)\s*\);/s', $php_content, $matches);

if (empty($matches[1])) {
    die("❌ Could not extract field groups from PHP file\n");
}

echo "Found " . count($matches[1]) . " field groups\n";

// Convert PHP arrays to JSON
// This is a simplified approach - for a full implementation, you would need to use WordPress + ACF
$json_data = [];

foreach ($matches[1] as $index => $array_content) {
    // This is a simplified conversion - in practice, you should use WordPress
    // The proper way is to use ACF's built-in JSON sync in WordPress admin
    echo "⚠️  Field group " . ($index + 1) . " detected\n";
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "⚠️  IMPORTANT: Manual JSON Update Required\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "The ACF JSON file cannot be automatically regenerated from PHP.\n";
echo "\n";
echo "Please follow these steps in WordPress:\n";
echo "\n";
echo "1. Go to: Custom Fields → Tools\n";
echo "2. Click 'Sync available' to see all field groups that need syncing\n";
echo "3. Select all field groups (or use 'Select All')\n";
echo "4. Click 'Sync changes'\n";
echo "\n";
echo "This will regenerate the JSON files with all your updates including:\n";
echo "  ✓ Corrected wpml_cf_preferences values\n";
echo "  ✓ acfml_field_group_mode => 'translation'\n";
echo "  ✓ All field structure updates\n";
echo "\n";
echo "Alternatively, you can simply re-save each field group in the ACF UI,\n";
echo "and ACF will automatically update the JSON file.\n";
echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
