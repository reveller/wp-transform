#!/usr/bin/env python3
"""
transform-posts.py — Clean and filter blog posts (or pages) for WP All Import.

Reads a WP All Export CSV, filters by category/status/post-type, strips
Beaver Builder tags from Content, extracts unique authors, and writes a
clean CSV ready for WP All Import (free version).

Usage:
    python transform-posts.py Posts-Staging-20260225.csv
    python transform-posts.py Posts-Staging-20260225.csv --dry-run
    python transform-posts.py Posts-Staging-20260225.csv --test
    python transform-posts.py Posts-Staging-20260225.csv --post-type page --category ""
"""

import argparse
import csv
import json
import os
import sys

csv.field_size_limit(sys.maxsize)

from transform import filter_beaver_builder_tags


def parse_args():
    parser = argparse.ArgumentParser(
        description="Filter and clean posts/pages CSV for WP All Import"
    )
    parser.add_argument("input_csv", help="Input CSV file (WP All Export format)")
    parser.add_argument(
        "--output", "-o",
        help="Output CSV filename (default: <input>-transformed.csv)",
    )
    parser.add_argument(
        "--authors-file",
        default="authors.json",
        help="Authors JSON mapping file (default: authors.json). "
             "Maps staging author emails to dev-site author fields.",
    )
    parser.add_argument(
        "--category",
        default="Blog",
        help='Comma-separated category filter (default: "Blog"). '
             'Use empty string "" to skip category filtering (for pages).',
    )
    parser.add_argument(
        "--status",
        default="publish",
        help='Post status filter (default: "publish")',
    )
    parser.add_argument(
        "--post-type",
        default="post",
        help='Post type filter (default: "post"). Use "page" for pages.',
    )
    parser.add_argument(
        "--slugs-file",
        help="Text file of URLs or slugs to include (one per line). "
             "Only rows whose Slug matches will be processed.",
    )
    parser.add_argument(
        "--strip-id",
        action="store_true",
        help="Clear the ID field so WP All Import creates new posts",
    )
    parser.add_argument(
        "--test",
        action="store_true",
        help="Process first 5 matching rows only",
    )
    parser.add_argument(
        "--not-test",
        action="store_true",
        help="Skip the first 5 matching rows (process everything after the test set)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Report counts without writing output files",
    )
    return parser.parse_args()


def load_author_map(authors_file):
    """Load authors.json and build a lookup keyed by email (case-insensitive)."""
    if not os.path.isfile(authors_file):
        return {}
    with open(authors_file, "r", encoding="utf-8") as f:
        authors = json.load(f)
    return {a["email"].strip().lower(): a for a in authors}


def matches_category(row_categories, filter_categories):
    """Check if any of the row's categories match the filter (case-insensitive)."""
    row_cats = {c.strip().lower() for c in row_categories.split(",") if c.strip()}
    return bool(row_cats & filter_categories)


def main():
    args = parse_args()

    if not os.path.isfile(args.input_csv):
        print(f"Error: Input file not found: {args.input_csv}")
        sys.exit(1)

    # Build default output filename
    if args.output:
        output_csv = args.output
    else:
        base, ext = os.path.splitext(args.input_csv)
        output_csv = f"{base}-transformed{ext}"

    # Parse category filter
    filter_cats = set()
    if args.category:
        filter_cats = {c.strip().lower() for c in args.category.split(",") if c.strip()}
    skip_category_filter = len(filter_cats) == 0

    # Load slugs filter
    allowed_slugs = None
    if args.slugs_file:
        with open(args.slugs_file, "r") as f:
            allowed_slugs = set()
            for line in f:
                line = line.strip().rstrip("/")
                if not line:
                    continue
                # Extract slug from URL or use as-is
                slug = line.rsplit("/", 1)[-1]
                if slug:
                    allowed_slugs.add(slug.lower())
        print(f"Loaded {len(allowed_slugs)} slug(s) from {args.slugs_file}")

    # Load author mapping
    author_map = load_author_map(args.authors_file)
    if author_map:
        print(f"Loaded {len(author_map)} author(s) from {args.authors_file}")
    else:
        print(f"Warning: No author mapping loaded from {args.authors_file}")

    # Stats
    total_rows = 0
    matched_rows = 0
    bb_tags_cleaned = 0
    authors_remapped = 0
    output_rows = []
    header = None

    # Handle BOM in UTF-8 CSV files
    with open(args.input_csv, "r", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        header = reader.fieldnames

        for row in reader:
            total_rows += 1

            # Filter: post type
            if row.get("Post Type", "").strip().lower() != args.post_type.lower():
                continue

            # Filter: status
            if row.get("Status", "").strip().lower() != args.status.lower():
                continue

            # Filter: category (skip if no filter specified)
            if not skip_category_filter:
                if not matches_category(row.get("Categories", ""), filter_cats):
                    continue

            # Filter: slugs file
            if allowed_slugs is not None:
                row_slug = row.get("Slug", "").strip().lower()
                if row_slug not in allowed_slugs:
                    continue

            matched_rows += 1

            # --not-test: skip the first 5 matching rows
            if args.not_test and matched_rows <= 5:
                continue

            # Clean content
            original_content = row.get("Content", "")
            cleaned_content = filter_beaver_builder_tags(original_content)
            if cleaned_content != original_content:
                bb_tags_cleaned += 1
            row["Content"] = cleaned_content

            # Remap author fields using authors.json
            row_email = row.get("Author Email", "").strip().lower()
            if row_email and row_email in author_map:
                mapped = author_map[row_email]
                row["Author ID"] = mapped["author_id"]
                row["Author Username"] = mapped["username"]
                row["Author Email"] = mapped["email"]
                row["Author First Name"] = mapped["first_name"]
                row["Author Last Name"] = mapped["last_name"]
                authors_remapped += 1

            # Strip ID so WP All Import creates new posts
            if args.strip_id:
                row["ID"] = ""

            output_rows.append(row)

            # --test: stop after 5 matching rows
            if args.test and matched_rows >= 5:
                break

    # Report
    print(f"Total rows read:      {total_rows}")
    print(f"Rows matching filter: {matched_rows}")
    print(f"BB tags cleaned:      {bb_tags_cleaned}")
    print(f"Authors remapped:     {authors_remapped}")

    if args.dry_run:
        print("\n[dry-run] No files written.")
        return

    # Write output CSV
    with open(output_csv, "w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=header)
        writer.writeheader()
        writer.writerows(output_rows)

    print(f"Rows written:         {len(output_rows)}")
    print(f"Output CSV:           {output_csv}")



if __name__ == "__main__":
    main()
