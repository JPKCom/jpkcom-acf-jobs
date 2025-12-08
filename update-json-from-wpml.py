#!/usr/bin/env python3
"""
Update ACF JSON export with correct wpml_cf_preferences and acfml_field_group_mode
"""

import json
import xml.etree.ElementTree as ET

# Parse wpml-config.xml to get the correct mappings
tree = ET.parse('wpml-config.xml')
root = tree.getroot()

# Create mapping: field_name => wpml_cf_preference value
# CORRECT MAPPING: 0=ignore, 1=copy-once, 2=translate, 3=copy
mapping = {}

for field in root.findall('.//custom-field'):
    action = field.get('action')
    field_name = field.text

    if action == 'ignore':
        value = 0
    elif action == 'copy-once':
        value = 1
    elif action == 'translate':
        value = 2
    elif action == 'copy':
        value = 3
    else:
        continue

    mapping[field_name] = value

print(f"Loaded {len(mapping)} field mappings from wpml-config.xml")

# Load the ACF JSON file
with open('.ht.acf-json/acf-export.json', 'r', encoding='utf-8') as f:
    field_groups = json.load(f)

print(f"Found {len(field_groups)} field groups in JSON")

def update_field_wpml_preferences(field, field_path=''):
    """Recursively update wpml_cf_preferences in fields"""
    field_name = field.get('name', '')

    # Get the correct preference value
    preference = None

    # Check exact match first
    if field_name in mapping:
        preference = mapping[field_name]
    else:
        # Check wildcard patterns (for flexible content)
        for pattern, value in mapping.items():
            if '%' in pattern:
                # Simple wildcard matching (% matches digits)
                import re
                regex_pattern = pattern.replace('%', r'\d+')
                if re.match(f'^{regex_pattern}$', field_name):
                    preference = value
                    break

    # Update if we found a mapping
    if preference is not None:
        old_value = field.get('wpml_cf_preferences')
        if old_value != preference:
            field['wpml_cf_preferences'] = preference
            action_name = ['ignore', 'copy-once', 'translate', 'copy'][preference]
            print(f"  Updated {field_name}: {old_value} → {preference} ({action_name})")

    # Recursively process sub_fields (for groups and flexible content)
    if 'sub_fields' in field and isinstance(field['sub_fields'], list):
        for sub_field in field['sub_fields']:
            update_field_wpml_preferences(sub_field, f"{field_path}/{field_name}")

    # Process layouts in flexible content
    if 'layouts' in field and isinstance(field['layouts'], dict):
        for layout_key, layout in field['layouts'].items():
            if 'sub_fields' in layout and isinstance(layout['sub_fields'], list):
                for sub_field in layout['sub_fields']:
                    update_field_wpml_preferences(sub_field, f"{field_path}/{field_name}/{layout_key}")

# Process each field group
updates_count = 0
for group in field_groups:
    print(f"\nProcessing group: {group.get('title', 'Unknown')}")

    # Add acfml_field_group_mode if not present
    if 'acfml_field_group_mode' not in group:
        group['acfml_field_group_mode'] = 'translation'
        print(f"  Added acfml_field_group_mode => 'translation'")

    # Update all fields in this group
    if 'fields' in group and isinstance(group['fields'], list):
        for field in group['fields']:
            update_field_wpml_preferences(field)

# Write updated JSON back
with open('.ht.acf-json/acf-export.json', 'w', encoding='utf-8') as f:
    json.dump(field_groups, f, indent=4, ensure_ascii=False)

print("\n✅ ACF JSON file updated successfully!")
print("\nChanges made:")
print("  ✓ Added acfml_field_group_mode => 'translation' to all field groups")
print("  ✓ Corrected all wpml_cf_preferences values based on wpml-config.xml")
print("\nNext steps:")
print("  1. Commit these changes to your repository")
print("  2. Test in your DDEV installation")
print("  3. Sync field groups in WordPress: Custom Fields → Tools → Sync available")
