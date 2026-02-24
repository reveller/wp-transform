#!/usr/bin/env python3
import argparse
import csv
import sys
from textwrap import shorten

from bs4 import BeautifulSoup, NavigableString, Tag
from collections import Counter

import ipdb;

def parse_tabs(html_content: str, clean_out: bool) -> tuple[list[dict], str]:
    """
    Parse Beaver Builder tabbed content blocks from a post body and extract tab data.

    This function scans the supplied HTML content for Beaver Builder "Tabs" modules,
    automatically detects each tab's title and associated content, and returns them
    as structured data. All tab-related markup is removed from the original content,
    and the cleaned version is returned alongside the parsed tab data.

    A tab entry is returned as a dictionary with the following structure:

        {
            "name": "<tab title text>",
            "content": "<HTML content belonging to this tab>"
        }

    The function supports posts that contain:
        • zero or more tab modules
        • an arbitrary number of tabs per module
        • tab titles that differ between posts

    Parameters
    ----------
    html_content : str
        The raw HTML content blob of a WordPress post. This may include one or more
        Beaver Builder tab modules mixed together with normal post content.
    clean_out : bool
        Boolean value too indicate whether the tab name and content should be
        cleaned out of the original content. If true, will return the content
        with the tab name and contents removed. If fals, will return the origina,
        untouched content.

    Returns
    -------
    tuple[list[dict], str]
        A 2-tuple consisting of:
        
        tabs : list[dict]
            A list of dictionaries, one per detected tab. If no tabs are found, an empty list is returned.
        
        cleaned_content : str
            If clean_out flag is true, the original HTML content with all tab-module markup removed, preserving the
            rest of the post content.
            If clean_out is false, the original, untouched content.

    Notes
    -----
    - Tab titles are auto-detected directly from the markup.
    - Tab content HTML is preserved as-is and not sanitized.
    - This function does not modify markup outside Beaver Builder tab modules.

    Examples
    --------
    >>> tabs, cleaned = parse_tabs(html)
    >>> tabs[0]["name"]
    'Menu'
    >>> tabs[0]["content"][:20]
    '<p>Today we are s...'
    """

    soup = BeautifulSoup(html_content, "html.parser")

    ipdb.set_trace()
    # ---- 1️⃣ Collect all leaf text nodes ----
    texts = []
    for node in soup.descendants:
        if isinstance(node, NavigableString):
            s = node.strip()
            if s:
                texts.append((node, s))

    labels = [s for _, s in texts]

    ipdb.set_trace()
    # ---- 2️⃣ Find repeating titles (the tab labels) ----
    counts = Counter(labels)
    repeating = [t for t, c in counts.items() if c >= 2]

    if not repeating:
        # nothing to do
        return [], str(soup)

    # We assume the *first contiguous run* of repeating strings is the tab menu
    tab_titles = []
    for _, s in texts:
        if s in repeating:
            if not tab_titles or s != tab_titles[-1]:
                tab_titles.append(s)
        elif tab_titles:
            break

    # de-dupe while preserving order
    seen = set()
    ordered_titles = []
    for t in tab_titles:
        if t not in seen:
            seen.add(t)
            ordered_titles.append(t)

    # If too small, bail out
    if len(ordered_titles) < 2:
        return [], str(soup)

    ipdb.set_trace()
    # ---- 3️⃣ Walk content and slice into tabs ----
    tabs = []
    nodes_to_remove = set()

    # iterator over all nodes
    all_nodes = list(soup.descendants)
    i = 0
    while i < len(all_nodes):
        node = all_nodes[i]

        if isinstance(node, NavigableString):
            title = node.strip()

            if title in ordered_titles:
                # start new tab
                section_nodes = []
                contents = []

                j = i + 1
                while j < len(all_nodes):
                    nxt = all_nodes[j]

                    if isinstance(nxt, NavigableString) and nxt.strip() in ordered_titles:
                        break

                    # capture html for top-level DOM objects inside the tab
                    if isinstance(nxt, (NavigableString, Tag)):
                        contents.append(str(nxt))
                    section_nodes.append(nxt)
                    j += 1

                tabs.append({
                    "name": title,
                    "contents": "".join(contents).strip()
                })

                # mark all nodes to remove including label
                nodes_to_remove.add(node)
                nodes_to_remove.update(section_nodes)

                i = j
                continue

        i += 1

    ipdb.set_trace()
    # ---- 4️⃣ Remove tab content + headers from original ----
    for n in nodes_to_remove:
        try:
            n.extract()
        except Exception:
            pass

    cleaned_html = str(soup)

    ipdb.set_trace()
    return tabs, cleaned_html



def print_tabs_table(tabs):
    if not tabs:
        print("\nNo tabs were detected.\n")
        return

    # Determine column sizes
    name_width = max(len("Tab Name"), max(len(t["name"]) for t in tabs))
    preview_width = 60  # fixed so tables don’t explode

    # Header
    print()
    print(f"{'Tab Name'.ljust(name_width)} | Content Preview")
    print("-" * name_width + "-+-" + "-" * preview_width)

    # Rows
    for tab in tabs:
        preview = shorten(tab["content"].strip(), width=preview_width, placeholder="…")
        print(f"{tab['name'].ljust(name_width)} | {preview}")

    print()


def load_row_by_id(csv_file, id_value, id_field="id"):
    """
    Reads a CSV and returns the first row where id_field == id_value.
    """
    with open(csv_file, newline='', encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)

        for row in reader:
            read_id = row.get(id_field)
            if read_id:
                read_id = read_id.strip()
            print(f"Read id:{read_id} in field:{id_field}")
            if read_id == id_value.strip():
                return row

    return None


def main():
    parser = argparse.ArgumentParser(
        description="Extract Beaver Builder tabbed content from a CSV post record."
    )

    parser.add_argument(
        "csvfile",
        help="Path to the input CSV file (first row must contain headers)"
    )

    parser.add_argument(
        "--id",
        required=True,
        help="ID value of the row to extract"
    )

    parser.add_argument(
        "--id-field",
        default="id",
        help="CSV column name containing the ID (default: id)"
    )

    parser.add_argument(
        "--content-field",
        default="Content",
        help='CSV column name containing the post HTML (default: "Content")'
    )

    args = parser.parse_args()

    # Load row
    print(f"Reading from: {args.csvfile} id:{args.id} id_field:{args.id_field}")
    row = load_row_by_id(args.csvfile, args.id, args.id_field)

    if not row:
        print(f"❌ No row found where {args.id_field} == {args.id}", file=sys.stderr)
        sys.exit(1)

    if args.content_field not in row:
        print(f"❌ Field '{args.content_field}' not found in CSV headers.", file=sys.stderr)
        sys.exit(1)

    html = row[args.content_field]

    # Parse tabs
    print(f"Original content:{html}")
    tabs, cleaned = parse_tabs(html, False)

    # Display results
    print(f"\nMatched row ID: {args.id}")
    print_tabs_table(tabs)


if __name__ == "__main__":
    main()

