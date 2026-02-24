#!/usr/bin/env python3
"""
GeoDirectory Taxonomy Field Audit

Cross-references all fields in gd-taxonomy-new.json, showing which fields
appear in common_fields vs each CPT's explicit fields array.

Cell values:
  C   = field comes from common_fields (inherited by all CPTs)
  X   = field is explicitly defined in this CPT's fields
  C+X = field exists in both common_fields AND the CPT's fields
  .   = not present for this CPT
"""

import json
import os
import re
import sys
from collections import defaultdict

TAXONOMY_FILE = os.path.join(os.path.dirname(__file__) or ".", "gd-taxonomy-new.json")

# Abbreviated column headers keyed by CPT name
CPT_ABBREVS = {
    "Places to Stay": "Stay",
    "Things to Do": "ToDo",
    "Food and Drink": "Food",
    "Island Life": "Island",
    "Getting Around": "Around",
    "Events": "Events",
    "Guides": "Guides",
    "Island Living": "Living",
    "Special Offers": "Offers",
}

# Display order: business listings first, then other listings
BUSINESS_CPTS = [
    "Places to Stay", "Things to Do", "Food and Drink",
    "Island Life", "Island Living", "Getting Around",
]
OTHER_CPTS = ["Events", "Guides", "Special Offers"]
DISPLAY_ORDER = BUSINESS_CPTS + OTHER_CPTS


def load_taxonomy(path):
    with open(path) as f:
        return json.load(f)


def collect_fields(data):
    """Return (common_set, cpt_order, cpt_fields, field_info, sort_orders).

    common_set:   set of htmlvar_names in common_fields
    cpt_order:    list of CPT names in display order
    cpt_fields:   {cpt_name: {htmlvar_name: count}} — count tracks duplicates
    field_info:   {htmlvar_name: [(label, field_type, source), ...]}
                  source is 'common' or the CPT name
    sort_orders:  {htmlvar_name: {source: int|None}}
                  source is 'common' or a CPT name; None means not specified
    """
    common_set = set()
    field_info = defaultdict(list)
    sort_orders = defaultdict(dict)

    for f in data.get("common_fields", []):
        name = f["htmlvar_name"]
        common_set.add(name)
        field_info[name].append((f.get("label", ""), f.get("field_type", ""), "common"))
        sort_orders[name]["common"] = f.get("sort_order")

    cpt_fields = {}
    for cpt in data.get("cpts", []):
        cpt_name = cpt["cpt"]
        counts = defaultdict(int)
        for f in cpt.get("fields", []):
            name = f["htmlvar_name"]
            counts[name] += 1
            field_info[name].append((f.get("label", ""), f.get("field_type", ""), cpt_name))
            sort_orders[name][cpt_name] = f.get("sort_order")
        cpt_fields[cpt_name] = dict(counts)

    # Use explicit display order; append any CPTs from file not in DISPLAY_ORDER
    cpt_order = [c for c in DISPLAY_ORDER if c in cpt_fields]
    for cpt in data.get("cpts", []):
        if cpt["cpt"] not in cpt_order:
            cpt_order.append(cpt["cpt"])

    return common_set, cpt_order, cpt_fields, field_info, sort_orders


def primary_info(field_info, name):
    """Pick the first (label, field_type) recorded for a field."""
    entries = field_info.get(name, [])
    if entries:
        return entries[0][0], entries[0][1]
    return "", ""


    # Explicitly named field groups
SOCIAL_FIELDS = {
    "facebook", "instagram", "twitter", "youtube", "linkedin", "pinterest",
    "trip_advisor", "yelp", "google_business", "wedding_wire",
}
STAY_FIELDS = {"airbnb", "vrbo", "bedrooms"}


def classify_fields(common_set, cpt_order, cpt_fields, field_info):
    """Assign every field to a display group.

    Returns a list of (group_label, [field_names]) in display order.
    """
    all_names = set(field_info.keys())
    assigned = set()

    groups = []

    # 1. Common fields
    common_names = sorted(n for n in all_names if n in common_set)
    groups.append(("Common", common_names))
    assigned |= set(common_names)

    # 2. Socials
    social_names = sorted(n for n in all_names if n in SOCIAL_FIELDS and n not in assigned)
    if social_names:
        groups.append(("Socials", social_names))
        assigned |= set(social_names)

    # 3. Stay
    stay_names = sorted(n for n in all_names if n in STAY_FIELDS and n not in assigned)
    if stay_names:
        groups.append(("Stay", stay_names))
        assigned |= set(stay_names)

    # 4. CPT-exclusive fields: only appear in one CPT's fields array, not in common
    exclusive = defaultdict(list)  # cpt_name -> [field_names]
    for name in sorted(all_names - assigned):
        cpts_with = [c for c in cpt_order if name in cpt_fields[c]]
        if len(cpts_with) == 1:
            exclusive[cpts_with[0]].append(name)
    for cpt_name in cpt_order:
        if cpt_name in exclusive:
            abbrev = CPT_ABBREVS.get(cpt_name, cpt_name[:6])
            groups.append((abbrev + " only", sorted(exclusive[cpt_name])))
            assigned |= set(exclusive[cpt_name])

    # 5. Remaining shared fields
    remaining = sorted(all_names - assigned)
    if remaining:
        groups.append(("Shared", remaining))

    return groups


def print_table(common_set, cpt_order, cpt_fields, field_info):
    all_names = sorted(field_info.keys())

    # Build abbreviations list and locate the group boundary
    abbrevs = [CPT_ABBREVS.get(c, c[:6]) for c in cpt_order]
    biz_set = set(BUSINESS_CPTS)
    sep_idx = None
    for i, c in enumerate(cpt_order):
        if c not in biz_set:
            sep_idx = i
            break

    # Column widths
    name_w = max(len(n) for n in all_names)
    name_w = max(name_w, len("htmlvar_name"))
    label_w = max(
        (len(primary_info(field_info, n)[0]) for n in all_names),
        default=5,
    )
    label_w = min(max(label_w, len("label")), 28)  # cap label column
    type_w = max(
        (len(primary_info(field_info, n)[1]) for n in all_names),
        default=5,
    )
    type_w = max(type_w, len("type"))
    col_w = max(max(len(a) for a in abbrevs), len("C+X"))

    prefix_w = name_w + 2 + label_w + 2 + type_w

    # Group labels above the column headers
    if sep_idx is not None:
        biz_span = sep_idx * (col_w + 2)
        other_span = (len(cpt_order) - sep_idx) * (col_w + 2)
        group_line = " " * prefix_w
        biz_label = "Business Listings"
        other_label = "Other"
        group_line += f"  {biz_label:^{biz_span}}{other_label:^{other_span}}"
        print(group_line)

        ul_line = " " * prefix_w
        ul_line += f"  {'-' * len(biz_label):^{biz_span}}{'-' * len(other_label):^{other_span}}"
        print(ul_line)

    # Column header
    header = (
        f"{'htmlvar_name':<{name_w}}  {'label':<{label_w}}  {'type':<{type_w}}"
    )
    for i, a in enumerate(abbrevs):
        if i == sep_idx:
            header += f" |{a:^{col_w}} "
        else:
            header += f"  {a:^{col_w}}"
    print(header)

    row_line = "-" * len(header)
    print(row_line)

    def format_row(name):
        label, ftype = primary_info(field_info, name)
        if len(label) > label_w:
            label = label[: label_w - 1] + "\u2026"
        row = f"{name:<{name_w}}  {label:<{label_w}}  {ftype:<{type_w}}"
        in_common = name in common_set
        for i, cpt_name in enumerate(cpt_order):
            in_cpt = name in cpt_fields[cpt_name]
            if in_common and in_cpt:
                cell = "C+X"
            elif in_common:
                cell = "C"
            elif in_cpt:
                cell = "X"
            else:
                cell = "."
            if i == sep_idx:
                row += f" |{cell:^{col_w}} "
            else:
                row += f"  {cell:^{col_w}}"
        return row

    # Print grouped rows
    field_groups = classify_fields(common_set, cpt_order, cpt_fields, field_info)
    for group_label, names in field_groups:
        # Section header
        print(f"  [{group_label}]")
        for name in names:
            print(format_row(name))

    print()
    print(f"Total unique fields: {len(all_names)}")
    print(f"Common fields: {len(common_set)}")
    print(f"  Business Listings:")
    for cpt_name, abbrev in zip(cpt_order, abbrevs):
        if cpt_name in biz_set:
            print(f"    {abbrev}: {len(cpt_fields[cpt_name])} explicit fields")
    print(f"  Other:")
    for cpt_name, abbrev in zip(cpt_order, abbrevs):
        if cpt_name not in biz_set:
            print(f"    {abbrev}: {len(cpt_fields[cpt_name])} explicit fields")


def print_issues(common_set, cpt_order, cpt_fields, field_info):
    print()
    print("=" * 72)
    print("ISSUES")
    print("=" * 72)

    # 1. Fields in common AND redefined in CPT
    print()
    print("--- Redundant: field in common_fields AND redefined in CPT fields ---")
    found = False
    for cpt_name in cpt_order:
        overlap = common_set & set(cpt_fields[cpt_name].keys())
        if overlap:
            abbrev = CPT_ABBREVS.get(cpt_name, cpt_name[:6])
            for name in sorted(overlap):
                print(f"  {name:<30} common + {abbrev}")
                found = True
    if not found:
        print("  (none)")

    # 2. Duplicate htmlvar_name within a single CPT
    print()
    print("--- Duplicate htmlvar_name within a single CPT ---")
    found = False
    for cpt_name in cpt_order:
        abbrev = CPT_ABBREVS.get(cpt_name, cpt_name[:6])
        for name, count in sorted(cpt_fields[cpt_name].items()):
            if count > 1:
                print(f"  {name:<30} appears {count}x in {abbrev}")
                found = True
    if not found:
        print("  (none)")

    # 3. Label / name mismatches (name suggests one thing, label says another)
    #    Only flag truly suspicious cases — ignore minor formatting like
    #    trip_advisor vs "TripAdvisor" by normalizing and doing substring checks.
    print()
    print("--- Label/name mismatches (suspicious) ---")
    found = False
    seen = set()  # deduplicate across CPTs
    for name, entries in sorted(field_info.items()):
        for label, ftype, source in entries:
            key = (name, label)
            if key in seen:
                continue
            # Normalize: lowercase, strip punctuation, collapse
            norm_name = re.sub(r"[^a-z0-9]", "", name.lower())
            norm_label = re.sub(r"[^a-z0-9]", "", label.lower())
            # Skip if one is a substring of the other (tripadvisor ⊂ trip_advisor)
            if norm_name in norm_label or norm_label in norm_name:
                continue
            # Split into significant words
            name_words = set(name.lower().replace("_", " ").split())
            label_words = set(label.lower().replace("/", " ").replace("-", " ").split())
            stop = {"", "a", "the", "of", "and", "or", "for", "to", "in", "url"}
            name_sig = name_words - stop
            label_sig = label_words - stop
            if label_sig and name_sig and not (label_sig & name_sig):
                seen.add(key)
                src = source if source != "common" else "common_fields"
                print(f"  {name:<30} label={label!r:<22} in {src}")
                found = True
    if not found:
        print("  (none)")

    # 4. Duplicate htmlvar_name in common_fields itself
    print()
    print("--- Duplicate htmlvar_name in common_fields ---")
    found = False
    for entry in field_info.items():
        name, entries = entry
        common_count = sum(1 for _, _, src in entries if src == "common")
        if common_count > 1:
            print(f"  {name:<30} appears {common_count}x in common_fields")
            found = True
    if not found:
        print("  (none)")

    # 5. Similar / "like" names — fields that likely should be consolidated
    #    Detects leading/trailing underscores, shared roots, near-duplicates
    print()
    print("--- Similar names (likely should be consolidated) ---")
    all_names = sorted(field_info.keys())

    def stem(name):
        """Strip leading/trailing underscores to get the core name."""
        return name.strip("_")

    # Group by stripped stem — catches _foo vs foo vs foo_
    stem_groups = defaultdict(set)
    for name in all_names:
        stem_groups[stem(name)].add(name)

    # Merge groups using prefix matching and first-word matching.
    # Prefix: stem(A) + "_" is a prefix of stem(B)  (email -> email_address)
    # First-word: stems share the same leading word   (google_biz <-> google_business)
    # Ignore generic first words that produce false positives.
    generic_roots = {"post", "event", "business", "book", "meta", "extended"}

    stems = sorted(stem_groups.keys())
    # Union-find for merging
    parent = {s: s for s in stems}

    def find(x):
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x

    def union(a, b):
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[rb] = ra

    def contains_at_word_boundary(haystack, needle):
        """Check if needle appears in haystack at _ word boundaries."""
        # Match needle as a whole word segment in the _ -delimited name
        return (
            haystack == needle
            or haystack.startswith(needle + "_")
            or haystack.endswith("_" + needle)
            or ("_" + needle + "_") in haystack
        )

    for i, s1 in enumerate(stems):
        for j in range(i + 1, len(stems)):
            s2 = stems[j]
            short, long = (s1, s2) if len(s1) <= len(s2) else (s2, s1)
            # Prefix match: short + "_" is a prefix of long
            if long.startswith(short + "_"):
                union(s1, s2)
                continue
            # First-word match: same leading word, not generic
            w1 = s1.split("_")[0]
            w2 = s2.split("_")[0]
            if w1 == w2 and w1 not in generic_roots and len(w1) >= 3:
                union(s1, s2)
                continue
            # Word-boundary substring: short name appears as a whole
            # word segment inside long name (e.g., "email" in "enter_email_address")
            # Only for multi-word short names to avoid false positives
            # (e.g., "address" matching "email_address")
            if "_" in short and len(short) >= 4 and short not in generic_roots:
                if contains_at_word_boundary(long, short):
                    union(s1, s2)

    # Collect merged groups
    groups = defaultdict(set)
    for s in stems:
        root = find(s)
        groups[root] |= stem_groups[s]

    found = False
    for canonical in sorted(groups):
        group = groups[canonical]
        if len(group) < 2:
            continue
        names_sorted = sorted(group)
        locations = []
        for n in names_sorted:
            sources = set()
            for _, _, src in field_info[n]:
                if src == "common":
                    sources.add("common")
                else:
                    sources.add(CPT_ABBREVS.get(src, src[:6]))
            locations.append(f"{n} ({', '.join(sorted(sources))})")
        print(f"  -> {' | '.join(locations)}")
        found = True
    if not found:
        print("  (none)")


def print_sort_order(common_set, cpt_order, cpt_fields, field_info, sort_orders):
    """Print a sort_order cross-reference table.

    Shows the effective sort_order for each field in each CPT.
    Places to Stay is listed first as the gold-standard reference.
    """
    print()
    print("=" * 72)
    print("SORT ORDER")
    print("=" * 72)
    print()

    all_names = sorted(field_info.keys())
    field_groups = classify_fields(common_set, cpt_order, cpt_fields, field_info)

    # Column order: Stay first (gold standard), then the rest in display order
    gold = "Places to Stay"
    so_cpt_order = [gold] + [c for c in cpt_order if c != gold]
    so_abbrevs = [CPT_ABBREVS.get(c, c[:6]) for c in so_cpt_order]

    biz_set = set(BUSINESS_CPTS)
    sep_idx = None
    for i, c in enumerate(so_cpt_order):
        if c not in biz_set:
            sep_idx = i
            break

    # Column widths
    name_w = max(len(n) for n in all_names)
    name_w = max(name_w, len("htmlvar_name"))
    label_w = max(
        (len(primary_info(field_info, n)[0]) for n in all_names),
        default=5,
    )
    label_w = min(max(label_w, len("label")), 28)
    col_w = max(max(len(a) for a in so_abbrevs), 3)  # at least 3 for "C:5"

    prefix_w = name_w + 2 + label_w

    # Group labels
    if sep_idx is not None:
        biz_span = sep_idx * (col_w + 2)
        other_span = (len(so_cpt_order) - sep_idx) * (col_w + 2)
        group_line = " " * prefix_w
        biz_label = "Business Listings"
        other_label = "Other"
        group_line += f"  {biz_label:^{biz_span}}{other_label:^{other_span}}"
        print(group_line)
        ul_line = " " * prefix_w
        ul_line += f"  {'-' * len(biz_label):^{biz_span}}{'-' * len(other_label):^{other_span}}"
        print(ul_line)

    # Column header
    header = f"{'htmlvar_name':<{name_w}}  {'label':<{label_w}}"
    for i, a in enumerate(so_abbrevs):
        if i == sep_idx:
            header += f" |{a:^{col_w}} "
        else:
            header += f"  {a:^{col_w}}"
    print(header)
    print("-" * len(header))

    def effective_sort(name, cpt_name):
        """Return the effective sort_order for a field in a CPT.

        Returns (cell_str, sort_val):
          cell_str: display string
          sort_val: numeric value for comparison (None if absent)
        """
        in_common = name in common_set
        in_cpt = name in cpt_fields.get(cpt_name, {})

        if not in_common and not in_cpt:
            return ".", None

        so = sort_orders.get(name, {})

        # CPT-level sort_order takes precedence over common
        if in_cpt and cpt_name in so:
            val = so[cpt_name]
            if val is not None:
                return str(val), val
            return "-", None

        # Fall back to common sort_order
        if in_common and "common" in so:
            val = so["common"]
            if val is not None:
                return str(val), val
            return "-", None

        return "-", None

    # ANSI escape for reverse video
    REV = "\033[7m"
    RST = "\033[0m"

    def fmt_cell(cell, col_w, highlight=False):
        """Center cell text in col_w chars, optionally in reverse video."""
        padded = f"{cell:^{col_w}}"
        if highlight:
            return f"{REV}{padded}{RST}"
        return padded

    # Print grouped rows
    for group_label, names in field_groups:
        print(f"  [{group_label}]")
        for name in names:
            label, _ = primary_info(field_info, name)
            if len(label) > label_w:
                label = label[: label_w - 1] + "\u2026"
            row = f"{name:<{name_w}}  {label:<{label_w}}"
            _, stay_val = effective_sort(name, gold)
            for i, cpt_name in enumerate(so_cpt_order):
                cell, val = effective_sort(name, cpt_name)
                # Highlight if this field exists in both Stay and this CPT
                # but the sort_order differs
                diff = (
                    cpt_name != gold
                    and stay_val is not None
                    and val is not None
                    and val != stay_val
                )
                rendered = fmt_cell(cell, col_w, highlight=diff)
                if i == sep_idx:
                    row += f" |{rendered} "
                else:
                    row += f"  {rendered}"
            print(row)

    # Summary: fields where sort_order differs from Stay (business listings only)
    print()
    print("--- Sort order differences vs Stay (business listings only) ---")
    found = False
    biz_others = [c for c in so_cpt_order[1:] if c in biz_set]
    for _, names in field_groups:
        for name in names:
            stay_cell, stay_val = effective_sort(name, gold)
            if stay_cell == ".":
                continue  # not in Stay, skip
            for cpt_name in biz_others:
                other_cell, other_val = effective_sort(name, cpt_name)
                if other_cell == ".":
                    continue  # not in this CPT
                if stay_val != other_val:
                    abbrev = CPT_ABBREVS.get(cpt_name, cpt_name[:6])
                    print(f"  {name:<22} Stay={stay_cell:<4} {abbrev}={other_cell}")
                    found = True
    if not found:
        print("  (none)")


def main():
    if not os.path.exists(TAXONOMY_FILE):
        print(f"Error: {TAXONOMY_FILE} not found", file=sys.stderr)
        sys.exit(1)

    data = load_taxonomy(TAXONOMY_FILE)
    common_set, cpt_order, cpt_fields, field_info, sort_orders = collect_fields(data)

    print("GeoDirectory Taxonomy Field Audit")
    print(f"Source: {os.path.basename(TAXONOMY_FILE)}")
    print(f"CPTs: {len(cpt_order)}  |  Common fields: {len(common_set)}")
    print()

    print_table(common_set, cpt_order, cpt_fields, field_info)
    print_issues(common_set, cpt_order, cpt_fields, field_info)
    print_sort_order(common_set, cpt_order, cpt_fields, field_info, sort_orders)


if __name__ == "__main__":
    main()
