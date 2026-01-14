#!/usr/bin/env python3
"""
Display GeoDirectory taxonomy mapping as a tree structure.

Reads the gd-taxonomy-map.json file and outputs a visual tree showing
parent categories and their subcategories, with IDs for reference.

Usage:
    ./taxonomy-tree.py                      # Show categories only
    ./taxonomy-tree.py --tags               # Include tags section
    ./taxonomy-tree.py --file other.json    # Use different input file
"""

import argparse
import json
import sys
from collections import defaultdict
from pathlib import Path


def load_taxonomy(filepath: str) -> list[dict]:
    """Load taxonomy data from JSON file."""
    path = Path(filepath)
    if not path.exists():
        print(f"Error: File not found: {filepath}", file=sys.stderr)
        sys.exit(1)

    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def build_category_tree(data: list[dict]) -> dict:
    """
    Build a tree structure from taxonomy data.

    Returns:
        {
            parent_id: {
                'info': {...},  # Parent category info
                'children': {
                    child_id: [entries...]  # List to handle duplicate IDs
                }
            }
        }
    """
    # Separate categories and subcategories
    categories = {}
    subcategories = defaultdict(lambda: defaultdict(list))

    for entry in data:
        entry_type = entry.get('type', '')

        if entry_type == 'category':
            cat_id = entry['id']
            if cat_id not in categories:
                categories[cat_id] = {
                    'info': entry,
                    'children': defaultdict(list)
                }
            else:
                # Duplicate category ID - add as alias
                categories[cat_id]['aliases'] = categories[cat_id].get('aliases', [])
                categories[cat_id]['aliases'].append(entry)

        elif entry_type == 'subcategory':
            parent_id = entry.get('parent_id', 0)
            sub_id = entry['id']
            subcategories[parent_id][sub_id].append(entry)

    # Attach subcategories to their parents
    for parent_id, children in subcategories.items():
        if parent_id in categories:
            categories[parent_id]['children'] = children
        else:
            # Orphan subcategories (parent not found)
            if 0 not in categories:
                categories[0] = {
                    'info': {'name': 'Uncategorized', 'id': 0},
                    'children': defaultdict(list)
                }
            for sub_id, entries in children.items():
                categories[0]['children'][sub_id].extend(entries)

    return categories


def get_tags(data: list[dict]) -> list[dict]:
    """Extract and sort tags from taxonomy data."""
    tags = [entry for entry in data if entry.get('type') == 'tag']
    return sorted(tags, key=lambda x: x.get('id', 0))


def print_tree(categories: dict, show_tags: bool = False, tags: list[dict] = None):
    """Print the category tree structure."""

    # Sort categories by ID
    sorted_cat_ids = sorted(categories.keys())

    print("=" * 70)
    print("GEODIRECTORY CATEGORY TREE")
    print("=" * 70)
    print()

    for cat_idx, cat_id in enumerate(sorted_cat_ids):
        if cat_id == 0:
            continue  # Skip the placeholder for orphans

        cat_data = categories[cat_id]
        cat_info = cat_data['info']
        cat_name = cat_info.get('name', 'Unknown')

        # Print parent category
        print(f"{cat_name} ({cat_id})")

        # Print any aliases for this category (duplicate IDs)
        aliases = cat_data.get('aliases', [])
        children = cat_data.get('children', {})

        # Determine if we have content to show below this category
        has_aliases = len(aliases) > 0
        has_children = len(children) > 0

        # Print aliases first
        for alias_idx, alias in enumerate(aliases):
            alias_name = alias.get('name', 'Unknown')
            is_last_alias = (alias_idx == len(aliases) - 1) and not has_children
            prefix = "└── " if is_last_alias else "├── "
            print(f"{prefix}{alias_name} ({cat_id}) [alias]")

        # Sort children by subcategory ID
        sorted_child_ids = sorted(children.keys())

        for child_idx, child_id in enumerate(sorted_child_ids):
            entries = children[child_id]
            is_last_child = child_idx == len(sorted_child_ids) - 1

            # First entry is the primary
            primary = entries[0]
            primary_name = primary.get('name', 'Unknown')
            prefix = "└── " if is_last_child else "├── "
            print(f"{prefix}{primary_name} ({child_id})")

            # Additional entries with same ID are aliases
            if len(entries) > 1:
                for alias_idx, alias in enumerate(entries[1:]):
                    alias_name = alias.get('name', 'Unknown')
                    is_last_alias = alias_idx == len(entries) - 2

                    # Determine continuation character
                    if is_last_child:
                        cont = "    "
                    else:
                        cont = "│   "

                    alias_prefix = "└── " if is_last_alias else "├── "
                    print(f"{cont}{alias_prefix}{alias_name} ({child_id}) [alias]")

        print()  # Blank line between parent categories

    # Print tags if requested
    if show_tags and tags:
        print("=" * 70)
        print("TAGS")
        print("=" * 70)
        print()

        # Group tags by ID to show aliases
        tags_by_id = defaultdict(list)
        for tag in tags:
            tags_by_id[tag['id']].append(tag)

        sorted_tag_ids = sorted(tags_by_id.keys())

        for tag_id in sorted_tag_ids:
            tag_entries = tags_by_id[tag_id]
            primary = tag_entries[0]
            primary_name = primary.get('name', 'Unknown')

            if len(tag_entries) == 1:
                print(f"  {primary_name} ({tag_id})")
            else:
                print(f"  {primary_name} ({tag_id})")
                for alias in tag_entries[1:]:
                    alias_name = alias.get('name', 'Unknown')
                    print(f"      └── {alias_name} ({tag_id}) [alias]")

        print()


def print_summary(data: list[dict], categories: dict, tags: list[dict]):
    """Print summary statistics."""
    print("=" * 70)
    print("SUMMARY")
    print("=" * 70)

    cat_count = len([c for c in categories.keys() if c != 0])
    subcat_count = sum(
        len(children)
        for cat_id, cat_data in categories.items()
        if cat_id != 0
        for children in [cat_data.get('children', {})]
    )
    tag_count = len(set(t['id'] for t in tags))

    # Count aliases
    total_entries = len(data)
    unique_ids = len(set(e['id'] for e in data))
    alias_count = total_entries - unique_ids

    print(f"  Parent categories: {cat_count}")
    print(f"  Subcategories:     {subcat_count}")
    print(f"  Tags:              {tag_count}")
    print(f"  Total entries:     {total_entries}")
    print(f"  Unique IDs:        {unique_ids}")
    print(f"  Aliases (ACF→GD):  {alias_count}")
    print()


def main():
    parser = argparse.ArgumentParser(
        description='Display GeoDirectory taxonomy mapping as a tree structure.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s                        Show category tree only
  %(prog)s --tags                 Include tags section
  %(prog)s --file other.json      Use different input file
  %(prog)s > taxonomy.txt         Save output to file
        """
    )

    parser.add_argument(
        '--file', '-f',
        default='gd-taxonomy-map.json',
        help='Input JSON file (default: gd-taxonomy-map.json)'
    )

    parser.add_argument(
        '--tags', '-t',
        action='store_true',
        help='Include tags in the output'
    )

    parser.add_argument(
        '--summary', '-s',
        action='store_true',
        help='Show summary statistics'
    )

    args = parser.parse_args()

    # Load data
    data = load_taxonomy(args.file)

    # Build structures
    categories = build_category_tree(data)
    tags = get_tags(data) if args.tags or args.summary else []

    # Print tree
    print_tree(categories, show_tags=args.tags, tags=tags)

    # Print summary if requested
    if args.summary:
        print_summary(data, categories, tags)


if __name__ == '__main__':
    main()
