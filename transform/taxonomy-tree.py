#!/usr/bin/env python3
"""
Display GeoDirectory CPT-based taxonomy mapping as a tree structure.

Reads the gd-taxonomy-cpts.json file and outputs a visual tree showing
CPTs, categories, and aliases, with IDs for reference.

Usage:
    ./taxonomy-tree.py                      # Show CPTs and categories
    ./taxonomy-tree.py --tags               # Include tags section
    ./taxonomy-tree.py --file other.json    # Use different input file
"""

import argparse
import json
import sys
from pathlib import Path


def load_taxonomy(filepath: str) -> dict:
    """Load CPT taxonomy data from JSON file."""
    path = Path(filepath)
    if not path.exists():
        print(f"Error: File not found: {filepath}", file=sys.stderr)
        sys.exit(1)

    with open(path, 'r', encoding='utf-8') as f:
        return json.load(f)


def print_tree(data: dict, show_tags: bool = False):
    """Print the CPT-based taxonomy tree structure."""

    cpts = data.get('cpts', [])
    global_tags = data.get('global_tags', [])

    print("=" * 70)
    print("GEODIRECTORY CPT-BASED TAXONOMY TREE")
    print("=" * 70)
    print()

    for cpt_idx, cpt in enumerate(cpts):
        cpt_name = cpt.get('cpt', 'Unknown')
        post_type = cpt.get('post_type', '')
        slug = cpt.get('slug', '')
        categories = cpt.get('categories', [])

        # Print CPT header
        print(f"{cpt_name}")
        print(f"  post_type: {post_type}")
        print(f"  slug: {slug}")

        # Print categories
        for cat_idx, category in enumerate(categories):
            cat_name = category.get('name', 'Unknown')
            cat_id = category.get('id', 0)
            cat_slug = category.get('slug', '')
            aliases = category.get('aliases', [])

            is_last_cat = cat_idx == len(categories) - 1
            prefix = "└── " if is_last_cat else "├── "

            print(f"  {prefix}{cat_name} ({cat_id})")

            # Print aliases
            if aliases:
                for alias_idx, alias in enumerate(aliases):
                    is_last_alias = alias_idx == len(aliases) - 1

                    if is_last_cat:
                        cont = "    "
                    else:
                        cont = "│   "

                    alias_prefix = "└── " if is_last_alias else "├── "
                    print(f"  {cont}{alias_prefix}{alias} ({cat_id}) [alias]")

        print()  # Blank line between CPTs

    # Print global tags if requested
    if show_tags and global_tags:
        print("=" * 70)
        print("GLOBAL TAGS")
        print("=" * 70)
        print()

        for tag in sorted(global_tags, key=lambda x: x.get('name', '')):
            tag_name = tag.get('name', 'Unknown')
            tag_id = tag.get('id', 0)
            print(f"  {tag_name} ({tag_id})")

        print()


def print_summary(data: dict):
    """Print summary statistics."""
    cpts = data.get('cpts', [])
    global_tags = data.get('global_tags', [])

    print("=" * 70)
    print("SUMMARY")
    print("=" * 70)

    total_categories = 0
    total_aliases = 0

    for cpt in cpts:
        categories = cpt.get('categories', [])
        total_categories += len(categories)
        for cat in categories:
            total_aliases += len(cat.get('aliases', []))

    print(f"  CPTs:              {len(cpts)}")
    print(f"  Categories:        {total_categories}")
    print(f"  Aliases:           {total_aliases}")
    print(f"  Global Tags:       {len(global_tags)}")
    print(f"  Total mappings:    {total_categories + total_aliases}")
    print()


def main():
    parser = argparse.ArgumentParser(
        description='Display GeoDirectory CPT-based taxonomy mapping as a tree structure.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s                        Show CPT/category tree only
  %(prog)s --tags                 Include global tags section
  %(prog)s --file other.json      Use different input file
  %(prog)s --summary              Include summary statistics
  %(prog)s > taxonomy.txt         Save output to file
        """
    )

    parser.add_argument(
        '--file', '-f',
        default='gd-taxonomy-cpts.json',
        help='Input JSON file (default: gd-taxonomy-cpts.json)'
    )

    parser.add_argument(
        '--tags', '-t',
        action='store_true',
        help='Include global tags in the output'
    )

    parser.add_argument(
        '--summary', '-s',
        action='store_true',
        help='Show summary statistics'
    )

    args = parser.parse_args()

    # Load data
    data = load_taxonomy(args.file)

    # Print tree
    print_tree(data, show_tags=args.tags)

    # Print summary if requested
    if args.summary:
        print_summary(data)


if __name__ == '__main__':
    main()
