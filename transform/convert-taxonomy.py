#!/usr/bin/env python3
"""
Convert flat gd-taxonomy-map.json to nested gd-taxonomy-cpts.json structure.

This script transforms the legacy flat taxonomy structure into the new
CPT-based nested structure for easier maintenance.

Usage:
    ./convert-taxonomy.py
    ./convert-taxonomy.py --input old.json --output new.json
"""

import argparse
import json
import re
from collections import defaultdict
from pathlib import Path


def slugify(name: str) -> str:
    """Convert name to slug format."""
    # Convert to lowercase
    slug = name.lower()
    # Replace + with and
    slug = slug.replace('+', 'and')
    # Replace spaces and special chars with hyphens
    slug = re.sub(r'[^a-z0-9]+', '-', slug)
    # Remove leading/trailing hyphens
    slug = slug.strip('-')
    return slug


def generate_post_type(name: str) -> str:
    """Generate GeoDirectory post_type from CPT name."""
    # Remove spaces and special characters, prefix with gd_
    clean = re.sub(r'[^a-zA-Z0-9]', '', name.lower())
    return f"gd_{clean}"


def convert_taxonomy(input_file: str, output_file: str):
    """Convert flat taxonomy to nested CPT structure."""

    # Load existing data
    with open(input_file, 'r', encoding='utf-8') as f:
        data = json.load(f)

    # Separate by type
    parent_categories = {}  # id -> entry (these become CPTs)
    subcategories = defaultdict(list)  # parent_id -> [entries]
    tags = []

    for entry in data:
        entry_type = entry.get('type', '')

        if entry_type == 'category' and entry.get('parent_id', 0) == 0:
            # Top-level category -> becomes CPT
            cat_id = entry['id']
            if cat_id not in parent_categories:
                parent_categories[cat_id] = entry
        elif entry_type == 'subcategory':
            # Subcategory -> becomes category under CPT
            parent_id = entry.get('parent_id', 0)
            subcategories[parent_id].append(entry)
        elif entry_type == 'tag':
            tags.append(entry)

    # Build new structure
    cpts = []

    # Sort parent categories by ID
    for parent_id in sorted(parent_categories.keys()):
        parent = parent_categories[parent_id]

        cpt_entry = {
            "cpt": parent['name'],
            "post_type": generate_post_type(parent['name']),
            "slug": parent.get('slug', slugify(parent['name'])),
            "categories": [],
            "tags": []  # Will need manual assignment
        }

        # Group subcategories by ID to identify primary vs aliases
        subcats_by_id = defaultdict(list)
        for subcat in subcategories.get(parent_id, []):
            subcats_by_id[subcat['id']].append(subcat)

        # Process each unique subcategory ID
        for sub_id in sorted(subcats_by_id.keys()):
            entries = subcats_by_id[sub_id]

            # Find the primary entry (one with a slug, or first one)
            primary = None
            aliases = []

            for entry in entries:
                if entry.get('slug') and entry['slug'].strip():
                    if primary is None:
                        primary = entry
                    else:
                        aliases.append(entry['name'])
                else:
                    aliases.append(entry['name'])

            # If no entry has a slug, use first as primary
            if primary is None:
                primary = entries[0]
                aliases = [e['name'] for e in entries[1:]]
            else:
                # Remove primary name from aliases if it ended up there
                aliases = [a for a in aliases if a != primary['name']]

            category_entry = {
                "name": primary['name'],
                "id": sub_id,
                "slug": primary.get('slug', slugify(primary['name'])),
                "aliases": sorted(set(aliases))  # Remove duplicates, sort
            }

            cpt_entry["categories"].append(category_entry)

        cpts.append(cpt_entry)

    # Handle Uncategorized specially (it's a category with parent_id=0 but id=2184)
    # It should probably be available in all CPTs or handled separately

    # Add tags section (for now, all tags go in a global section)
    # User will need to manually assign to CPTs
    global_tags = []
    for tag in sorted(tags, key=lambda x: x.get('id', 0)):
        global_tags.append({
            "name": tag['name'],
            "id": tag['id'],
            "slug": tag.get('slug', slugify(tag['name']))
        })

    # Create output structure
    output = {
        "_comment": "CPT-based taxonomy structure. Tags need manual assignment to CPTs.",
        "cpts": cpts,
        "global_tags": global_tags
    }

    # Write output
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    # Print summary
    print(f"Conversion complete!")
    print(f"  CPTs created: {len(cpts)}")
    for cpt in cpts:
        cat_count = len(cpt['categories'])
        alias_count = sum(len(c['aliases']) for c in cpt['categories'])
        print(f"    - {cpt['cpt']}: {cat_count} categories, {alias_count} aliases")
    print(f"  Global tags: {len(global_tags)}")
    print(f"\nOutput written to: {output_file}")
    print(f"\nNOTE: Tags are in 'global_tags' section. You'll need to manually")
    print(f"      move them to appropriate CPT 'tags' arrays.")


def main():
    parser = argparse.ArgumentParser(
        description='Convert flat taxonomy JSON to nested CPT structure'
    )
    parser.add_argument(
        '--input', '-i',
        default='gd-taxonomy-map.json',
        help='Input file (default: gd-taxonomy-map.json)'
    )
    parser.add_argument(
        '--output', '-o',
        default='gd-taxonomy-cpts.json',
        help='Output file (default: gd-taxonomy-cpts.json)'
    )

    args = parser.parse_args()

    if not Path(args.input).exists():
        print(f"Error: Input file not found: {args.input}")
        return 1

    convert_taxonomy(args.input, args.output)
    return 0


if __name__ == '__main__':
    exit(main())
