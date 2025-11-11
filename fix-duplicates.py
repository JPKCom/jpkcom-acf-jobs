#!/usr/bin/env python3
"""
Script to remove duplicate wpml_cf_preferences entries
"""

import re

# Read file
with open('includes/acf-field_groups.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Remove consecutive duplicate wpml_cf_preferences lines
cleaned_lines = []
prev_line = None

for line in lines:
    # Check if this line and previous line are both wpml_cf_preferences
    is_wpml_pref = "'wpml_cf_preferences'" in line
    prev_was_wpml_pref = prev_line and "'wpml_cf_preferences'" in prev_line

    # Skip if both current and previous are wpml_cf_preferences
    if is_wpml_pref and prev_was_wpml_pref:
        print(f"Removing duplicate: {line.strip()}")
        continue

    cleaned_lines.append(line)
    prev_line = line

# Write back
with open('includes/acf-field_groups.php', 'w', encoding='utf-8') as f:
    f.writelines(cleaned_lines)

print(f"\n✅ Removed duplicate wpml_cf_preferences entries!")
