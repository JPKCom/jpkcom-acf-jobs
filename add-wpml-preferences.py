#!/usr/bin/env python3
"""
Script to add wpml_cf_preferences to ACF field groups based on wpml-config.xml
"""

import re
import xml.etree.ElementTree as ET

# Parse wpml-config.xml
tree = ET.parse('wpml-config.xml')
root = tree.getroot()

# Create mapping: field_name => wpml_cf_preference value
# 0 = ignore, 1 = copy, 2 = translate, 3 = copy-once
mapping = {}

for field in root.findall('.//custom-field'):
    action = field.get('action')
    field_name = field.text

    # Map action to numeric value
    if action == 'ignore':
        value = 0
    elif action == 'copy':
        value = 1
    elif action == 'translate':
        value = 2
    elif action == 'copy-once':
        value = 3
    else:
        continue

    # Handle wildcards - for flexible content fields
    if '%' in field_name:
        # Store pattern for later matching
        mapping[field_name] = value
    else:
        mapping[field_name] = value

print("=== WPML Field Mapping ===")
for field, value in sorted(mapping.items()):
    action_name = ['ignore', 'copy', 'translate', 'copy-once'][value]
    print(f"{field}: {value} ({action_name})")

print(f"\nTotal fields mapped: {len(mapping)}")

# Now read acf-field_groups.php
with open('includes/acf-field_groups.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Function to match field name against patterns (including wildcards)
def get_wpml_preference(field_name):
    # Exact match first
    if field_name in mapping:
        return mapping[field_name]

    # Try wildcard patterns
    for pattern, value in mapping.items():
        if '%' in pattern:
            # Convert pattern to regex (% matches any digits)
            regex_pattern = pattern.replace('%', r'\d+')
            if re.match(f'^{regex_pattern}$', field_name):
                return value

    return None

# Find all field definitions and add wpml_cf_preferences
# Pattern: 'name' => 'field_name',
modified_count = 0
def add_wpml_preference(match):
    global modified_count
    full_match = match.group(0)
    field_name = match.group(1)

    # Skip if it's an internal ACF field (starts with _)
    if field_name.startswith('_'):
        return full_match

    # Get wpml preference for this field
    preference = get_wpml_preference(field_name)

    if preference is None:
        # No mapping found, skip
        return full_match

    # Check if wpml_cf_preferences already exists in the surrounding context
    # Look ahead to see if it's already there
    if 'wpml_cf_preferences' in full_match:
        return full_match

    # Add wpml_cf_preferences right after the name line
    indent = '\t\t\t'
    new_line = f"\n{indent}'wpml_cf_preferences' => {preference},"
    result = full_match + new_line
    modified_count += 1

    return result

# Pattern to match: 'name' => 'field_name',
# We need to capture the field name and add wpml_cf_preferences after
pattern = r"'name'\s*=>\s*'([^']+)',"

content_modified = re.sub(pattern, add_wpml_preference, content)

print(f"\n=== Modification Summary ===")
print(f"Fields modified: {modified_count}")

# Write back
with open('includes/acf-field_groups.php', 'w', encoding='utf-8') as f:
    f.write(content_modified)

print("\n✅ File updated successfully!")
print("Please review the changes and test in WPML.")
