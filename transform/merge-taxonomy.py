#!/usr/bin/env python3
"""
merge-taxonomy.py - Diff and merge GeoDirectory taxonomy JSON files.

Reads:
  - gd-taxonomy-cpts.json  (user's curated file, SOURCE OF TRUTH for structure/aliases/categories)
  - gd-export-dev.json     (dev site export, SOURCE OF TRUTH for live IDs/tags/fields/new CPTs)

Outputs:
  - Detailed conflict report to stdout
  - Merged file to gd-taxonomy-new.json
"""

import json
import sys
import os
import copy
from collections import OrderedDict
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
USER_FILE = os.path.join(SCRIPT_DIR, "gd-taxonomy-cpts.json")
DEV_FILE = os.path.join(SCRIPT_DIR, "gd-export-dev.json")
OUTPUT_FILE = os.path.join(SCRIPT_DIR, "gd-taxonomy-new.json")

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def load_json(path):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
        f.write("\n")


def cpt_index_by_post_type(cpts):
    """Return dict keyed by post_type -> cpt dict."""
    return {c["post_type"]: c for c in cpts}


def field_index(fields):
    """Return dict keyed by htmlvar_name -> field dict."""
    return {f["htmlvar_name"]: f for f in fields}


def category_index_by_slug(cats):
    """Return dict keyed by slug -> category dict."""
    return {c["slug"]: c for c in cats}


def category_index_by_name(cats):
    """Return dict keyed by name -> category dict."""
    return {c["name"]: c for c in cats}


def tag_index_by_slug(tags):
    return {t["slug"]: t for t in tags}


def hr(char="=", width=80):
    return char * width


def section(title):
    return f"\n{hr()}\n  {title}\n{hr()}"


# ---------------------------------------------------------------------------
# Conflict report helpers
# ---------------------------------------------------------------------------

def report_cpt_presence(user_types, dev_types):
    lines = []
    user_only = user_types - dev_types
    dev_only = dev_types - user_types
    both = user_types & dev_types

    if user_only:
        lines.append("\n  CPTs ONLY in user file (gd-taxonomy-cpts.json):")
        for pt in sorted(user_only):
            lines.append(f"    - {pt}")
    if dev_only:
        lines.append("\n  CPTs ONLY in dev export (gd-export-dev.json):")
        for pt in sorted(dev_only):
            lines.append(f"    - {pt}")
    if both:
        lines.append(f"\n  CPTs in BOTH files ({len(both)}):")
        for pt in sorted(both):
            lines.append(f"    - {pt}")

    return lines


def report_cpt_metadata(user_cpt, dev_cpt, post_type):
    """Compare top-level CPT properties: cpt name, slug."""
    lines = []

    if user_cpt.get("cpt") != dev_cpt.get("cpt"):
        lines.append(f"    CPT display name differs:")
        lines.append(f"      user: {user_cpt.get('cpt')!r}")
        lines.append(f"      dev:  {dev_cpt.get('cpt')!r}")

    if user_cpt.get("slug") != dev_cpt.get("slug"):
        lines.append(f"    slug differs:")
        lines.append(f"      user: {user_cpt.get('slug')!r}")
        lines.append(f"      dev:  {dev_cpt.get('slug')!r}")

    return lines


def report_category_diffs(user_cats, dev_cats, post_type):
    """Compare categories between user and dev for a given CPT."""
    lines = []

    user_by_name = category_index_by_name(user_cats)
    dev_by_name = category_index_by_name(dev_cats)

    user_names = set(user_by_name.keys())
    dev_names = set(dev_by_name.keys())

    user_only = user_names - dev_names
    dev_only = dev_names - user_names
    common = user_names & dev_names

    if user_only:
        lines.append(f"    Categories ONLY in user file:")
        for n in sorted(user_only):
            c = user_by_name[n]
            lines.append(f"      - {n} (id={c.get('id')}, slug={c.get('slug')})")
    if dev_only:
        lines.append(f"    Categories ONLY in dev export:")
        for n in sorted(dev_only):
            c = dev_by_name[n]
            lines.append(f"      - {n} (id={c.get('id')}, slug={c.get('slug')})")
    if common:
        for n in sorted(common):
            uc = user_by_name[n]
            dc = dev_by_name[n]
            diffs = []
            if uc.get("id") != dc.get("id"):
                diffs.append(f"id: user={uc.get('id')} vs dev={dc.get('id')}")
            if uc.get("slug") != dc.get("slug"):
                diffs.append(f"slug: user={uc.get('slug')!r} vs dev={dc.get('slug')!r}")
            if uc.get("aliases", []) != dc.get("aliases", []):
                ua = uc.get("aliases", [])
                da = dc.get("aliases", [])
                if ua and not da:
                    diffs.append(f"aliases: user has {len(ua)} aliases, dev has none")
                elif da and not ua:
                    diffs.append(f"aliases: dev has {len(da)} aliases, user has none")
                else:
                    diffs.append(f"aliases differ: user={ua} vs dev={da}")
            if diffs:
                lines.append(f"    Category '{n}' differences:")
                for d in diffs:
                    lines.append(f"      {d}")

    return lines


def report_tag_diffs(user_tags, dev_tags, label="tags"):
    """Compare tag arrays."""
    lines = []

    user_by_slug = tag_index_by_slug(user_tags)
    dev_by_slug = tag_index_by_slug(dev_tags)

    user_slugs = set(user_by_slug.keys())
    dev_slugs = set(dev_by_slug.keys())

    user_only = user_slugs - dev_slugs
    dev_only = dev_slugs - user_slugs
    common = user_slugs & dev_slugs

    if user_only:
        lines.append(f"    {label} ONLY in user file ({len(user_only)}):")
        for s in sorted(user_only):
            t = user_by_slug[s]
            lines.append(f"      - {t.get('name', '???')} (slug={s}, id={t.get('id')})")
    if dev_only:
        lines.append(f"    {label} ONLY in dev export ({len(dev_only)}):")
        for s in sorted(dev_only):
            t = dev_by_slug[s]
            lines.append(f"      - {t.get('name', '???')} (slug={s}, id={t.get('id')})")
    if common:
        for s in sorted(common):
            ut = user_by_slug[s]
            dt = dev_by_slug[s]
            diffs = []
            if ut.get("id") != dt.get("id"):
                diffs.append(f"id: user={ut.get('id')} vs dev={dt.get('id')}")
            if ut.get("name") != dt.get("name"):
                diffs.append(f"name: user={ut.get('name')!r} vs dev={dt.get('name')!r}")
            if diffs:
                lines.append(f"    {label} '{s}' differences:")
                for d in diffs:
                    lines.append(f"      {d}")

    return lines


def report_field_diffs(user_fields, dev_fields, label="fields"):
    """Compare field arrays by htmlvar_name."""
    lines = []

    user_by_hv = field_index(user_fields)
    dev_by_hv = field_index(dev_fields)

    user_hvs = set(user_by_hv.keys())
    dev_hvs = set(dev_by_hv.keys())

    user_only = user_hvs - dev_hvs
    dev_only = dev_hvs - user_hvs
    common = user_hvs & dev_hvs

    if user_only:
        lines.append(f"    {label} ONLY in user file ({len(user_only)}):")
        for h in sorted(user_only):
            f = user_by_hv[h]
            lines.append(f"      - {h} ({f.get('label', '')} / {f.get('field_type', '')})")
    if dev_only:
        lines.append(f"    {label} ONLY in dev export ({len(dev_only)}):")
        for h in sorted(dev_only):
            f = dev_by_hv[h]
            lines.append(f"      - {h} ({f.get('label', '')} / {f.get('field_type', '')})")
    if common:
        for h in sorted(common):
            uf = user_by_hv[h]
            df = dev_by_hv[h]
            all_keys = set(uf.keys()) | set(df.keys())
            diffs = []
            for k in sorted(all_keys):
                if k.startswith("_"):
                    continue  # skip annotation keys like _group
                uv = uf.get(k)
                dv = df.get(k)
                if uv != dv:
                    if k not in uf:
                        diffs.append(f"{k}: missing in user, dev={dv!r}")
                    elif k not in df:
                        diffs.append(f"{k}: user={uv!r}, missing in dev")
                    else:
                        diffs.append(f"{k}: user={uv!r} vs dev={dv!r}")
            if diffs:
                lines.append(f"    {label} '{h}' differences:")
                for d in diffs:
                    lines.append(f"      {d}")

    return lines


# ---------------------------------------------------------------------------
# Merge logic
# ---------------------------------------------------------------------------

def merge_common_fields(user_fields, dev_fields):
    """
    Start with user's common_fields.
    Add any dev fields not already present (by htmlvar_name).
    For fields in both, keep user's version but add missing properties from dev.
    """
    merged = []
    user_by_hv = field_index(user_fields)
    dev_by_hv = field_index(dev_fields)
    seen = set()

    # Keep user fields in order, augmenting with dev properties
    for uf in user_fields:
        hv = uf["htmlvar_name"]
        seen.add(hv)
        merged_field = copy.deepcopy(uf)
        if hv in dev_by_hv:
            df = dev_by_hv[hv]
            for k, v in df.items():
                if k not in merged_field:
                    merged_field[k] = v
        merged.append(merged_field)

    # Add dev-only fields at end
    for df in dev_fields:
        hv = df["htmlvar_name"]
        if hv not in seen:
            merged_field = copy.deepcopy(df)
            merged_field["_source"] = "dev_export"
            merged.append(merged_field)

    return merged


def merge_categories(user_cats, dev_cats):
    """
    Keep user's category structure (with aliases).
    For matching categories (by name): keep user's id/slug, add dev_id/dev_slug.
    For categories only in dev: add them at the end with a note.
    """
    merged = []
    dev_by_name = category_index_by_name(dev_cats)
    seen_names = set()

    for uc in user_cats:
        name = uc["name"]
        seen_names.add(name)
        mc = copy.deepcopy(uc)
        if name in dev_by_name:
            dc = dev_by_name[name]
            if dc.get("id") != mc.get("id"):
                mc["dev_id"] = dc["id"]
            if dc.get("slug") != mc.get("slug"):
                mc["dev_slug"] = dc["slug"]
        merged.append(mc)

    # Add dev-only categories
    for dc in dev_cats:
        if dc["name"] not in seen_names:
            mc = copy.deepcopy(dc)
            mc["_source"] = "dev_export"
            merged.append(mc)

    return merged


def merge_per_cpt_fields(user_fields, dev_fields):
    """
    If user has empty fields[], fill from dev.
    If user has fields, keep user's; add dev-only fields by htmlvar_name.
    """
    if not user_fields and dev_fields:
        return copy.deepcopy(dev_fields)

    if not user_fields:
        return []

    merged = []
    user_by_hv = field_index(user_fields)
    dev_by_hv = field_index(dev_fields)
    seen = set()

    for uf in user_fields:
        hv = uf["htmlvar_name"]
        seen.add(hv)
        mf = copy.deepcopy(uf)
        if hv in dev_by_hv:
            df = dev_by_hv[hv]
            for k, v in df.items():
                if k not in mf:
                    mf[k] = v
        merged.append(mf)

    for df in dev_fields:
        if df["htmlvar_name"] not in seen:
            mf = copy.deepcopy(df)
            mf["_source"] = "dev_export"
            merged.append(mf)

    return merged


def merge_per_cpt_tags(user_tags, dev_tags):
    """
    If user has empty tags[], fill from dev.
    If user has tags, keep user's; add dev-only tags by slug.
    """
    if not user_tags and dev_tags:
        return copy.deepcopy(dev_tags)

    if not user_tags:
        return []

    merged = []
    user_by_slug = tag_index_by_slug(user_tags)
    dev_by_slug = tag_index_by_slug(dev_tags)
    seen = set()

    for ut in user_tags:
        slug = ut["slug"]
        seen.add(slug)
        mt = copy.deepcopy(ut)
        if slug in dev_by_slug:
            dt = dev_by_slug[slug]
            if dt.get("id") != mt.get("id"):
                mt["dev_id"] = dt["id"]
            if dt.get("name") != mt.get("name"):
                mt["dev_name"] = dt["name"]
        merged.append(mt)

    for dt in dev_tags:
        if dt["slug"] not in seen:
            mt = copy.deepcopy(dt)
            mt["_source"] = "dev_export"
            merged.append(mt)

    return merged


def merge_cpt(user_cpt, dev_cpt):
    """Merge a single CPT that exists in both files."""
    merged = copy.deepcopy(user_cpt)

    # Slug: keep user's, record dev_slug if different
    if user_cpt.get("slug") != dev_cpt.get("slug"):
        merged["dev_slug"] = dev_cpt["slug"]

    # CPT display name: keep user's, record dev if different
    if user_cpt.get("cpt") != dev_cpt.get("cpt"):
        merged["dev_cpt_name"] = dev_cpt["cpt"]

    # Categories: user is authority, augment with dev IDs
    merged["categories"] = merge_categories(
        user_cpt.get("categories", []),
        dev_cpt.get("categories", [])
    )

    # Tags: fill from dev if user is empty
    merged["tags"] = merge_per_cpt_tags(
        user_cpt.get("tags", []),
        dev_cpt.get("tags", [])
    )

    # Fields: fill from dev if user is empty
    merged["fields"] = merge_per_cpt_fields(
        user_cpt.get("fields", []),
        dev_cpt.get("fields", [])
    )

    return merged


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    user_data = load_json(USER_FILE)
    dev_data = load_json(DEV_FILE)

    report = []
    report.append(section("TAXONOMY MERGE - CONFLICT REPORT"))
    report.append(f"  Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    report.append(f"  User file:  {USER_FILE}")
    report.append(f"  Dev file:   {DEV_FILE}")
    report.append(f"  Output:     {OUTPUT_FILE}")

    # ------------------------------------------------------------------
    # 1. Top-level structural diff
    # ------------------------------------------------------------------
    report.append(section("1. TOP-LEVEL KEYS"))

    user_keys = set(user_data.keys())
    dev_keys = set(dev_data.keys())
    if user_keys != dev_keys:
        only_user = user_keys - dev_keys
        only_dev = dev_keys - user_keys
        if only_user:
            report.append(f"  Keys only in user file: {sorted(only_user)}")
        if only_dev:
            report.append(f"  Keys only in dev file:  {sorted(only_dev)}")
    else:
        report.append("  Both files have the same top-level keys.")

    # ------------------------------------------------------------------
    # 2. CPT presence / absence
    # ------------------------------------------------------------------
    report.append(section("2. CPT PRESENCE"))

    user_cpts = cpt_index_by_post_type(user_data.get("cpts", []))
    dev_cpts = cpt_index_by_post_type(dev_data.get("cpts", []))

    user_types = set(user_cpts.keys())
    dev_types = set(dev_cpts.keys())

    report.extend(report_cpt_presence(user_types, dev_types))

    # ------------------------------------------------------------------
    # 3. Per-CPT diffs for matching CPTs
    # ------------------------------------------------------------------
    both_types = sorted(user_types & dev_types)
    for pt in both_types:
        uc = user_cpts[pt]
        dc = dev_cpts[pt]
        cpt_lines = []

        # Metadata (name, slug)
        cpt_lines.extend(report_cpt_metadata(uc, dc, pt))

        # Categories
        cat_lines = report_category_diffs(
            uc.get("categories", []),
            dc.get("categories", []),
            pt
        )
        if cat_lines:
            cpt_lines.append("    --- Categories ---")
            cpt_lines.extend(cat_lines)

        # Tags
        user_tags = uc.get("tags", [])
        dev_tags = dc.get("tags", [])
        if not user_tags and dev_tags:
            cpt_lines.append(f"    --- Tags ---")
            cpt_lines.append(f"    User has EMPTY tags[]; dev has {len(dev_tags)} tags (will be filled from dev)")
        elif user_tags or dev_tags:
            tag_lines = report_tag_diffs(user_tags, dev_tags, label="tags")
            if tag_lines:
                cpt_lines.append("    --- Tags ---")
                cpt_lines.extend(tag_lines)

        # Fields
        user_fields = uc.get("fields", [])
        dev_fields = dc.get("fields", [])
        if not user_fields and dev_fields:
            cpt_lines.append(f"    --- Per-CPT Fields ---")
            cpt_lines.append(f"    User has EMPTY fields[]; dev has {len(dev_fields)} fields (will be filled from dev)")
        elif user_fields or dev_fields:
            fld_lines = report_field_diffs(user_fields, dev_fields, label="per-CPT fields")
            if fld_lines:
                cpt_lines.append("    --- Per-CPT Fields ---")
                cpt_lines.extend(fld_lines)

        report.append(section(f"3. CPT: {pt} ({uc.get('cpt', '???')})"))
        if cpt_lines:
            report.extend(cpt_lines)
        else:
            report.append("    No differences found.")

    # ------------------------------------------------------------------
    # 4. Common fields diff
    # ------------------------------------------------------------------
    report.append(section("4. COMMON FIELDS"))

    user_cf = user_data.get("common_fields", [])
    dev_cf = dev_data.get("common_fields", [])
    cf_lines = report_field_diffs(user_cf, dev_cf, label="common_fields")
    if cf_lines:
        report.extend(cf_lines)
    else:
        report.append("    No differences found.")

    # ------------------------------------------------------------------
    # 5. Global tags diff
    # ------------------------------------------------------------------
    report.append(section("5. GLOBAL TAGS"))

    user_gt = user_data.get("global_tags", [])
    dev_gt = dev_data.get("global_tags", [])
    gt_lines = report_tag_diffs(user_gt, dev_gt, label="global_tags")
    if gt_lines:
        report.extend(gt_lines)
    else:
        report.append("    No differences found.")

    # ------------------------------------------------------------------
    # 6. Dev-only CPT summaries
    # ------------------------------------------------------------------
    dev_only_types = sorted(dev_types - user_types)
    if dev_only_types:
        report.append(section("6. DEV-ONLY CPTs (will be added as-is)"))
        for pt in dev_only_types:
            dc = dev_cpts[pt]
            report.append(f"\n  {pt} ({dc.get('cpt', '???')}):")
            report.append(f"    slug: {dc.get('slug')}")
            cats = dc.get("categories", [])
            report.append(f"    categories: {len(cats)}")
            for c in cats:
                report.append(f"      - {c['name']} (id={c.get('id')}, slug={c.get('slug')})")
            tags = dc.get("tags", [])
            report.append(f"    tags: {len(tags)}")
            fields = dc.get("fields", [])
            report.append(f"    fields: {len(fields)}")

    # ------------------------------------------------------------------
    # 7. User-only CPT summaries
    # ------------------------------------------------------------------
    user_only_types = sorted(user_types - dev_types)
    if user_only_types:
        report.append(section("7. USER-ONLY CPTs (kept as-is, may need manual attention)"))
        for pt in user_only_types:
            uc = user_cpts[pt]
            report.append(f"\n  {pt} ({uc.get('cpt', '???')}):")
            report.append(f"    slug: {uc.get('slug')}")
            cats = uc.get("categories", [])
            report.append(f"    categories: {len(cats)}")
            for c in cats:
                report.append(f"      - {c['name']} (id={c.get('id')}, slug={c.get('slug')})")

    # ------------------------------------------------------------------
    # Print report
    # ------------------------------------------------------------------
    print("\n".join(report))
    print(f"\n{hr()}")

    # ==================================================================
    # BUILD MERGED OUTPUT
    # ==================================================================
    merged = {}

    # Copy metadata comments
    if "_comment" in user_data:
        merged["_comment"] = user_data["_comment"]
    if "_field_types" in user_data:
        merged["_field_types"] = user_data["_field_types"]

    merged["_merge_info"] = {
        "generated": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "user_file": os.path.basename(USER_FILE),
        "dev_file": os.path.basename(DEV_FILE),
        "strategy": "user=structure/aliases/categories, dev=IDs/tags/fields/new CPTs"
    }

    # Merge common_fields
    merged["common_fields"] = merge_common_fields(user_cf, dev_cf)

    # Merge CPTs
    merged_cpts = []

    # 1. CPTs in both files (user order first)
    user_order = [c["post_type"] for c in user_data.get("cpts", [])]
    for pt in user_order:
        if pt in dev_cpts:
            merged_cpts.append(merge_cpt(user_cpts[pt], dev_cpts[pt]))
        else:
            # User-only CPT: keep as-is
            mc = copy.deepcopy(user_cpts[pt])
            mc["_note"] = "User-only CPT; not found in dev export"
            merged_cpts.append(mc)

    # 2. Dev-only CPTs appended
    for pt in dev_only_types:
        mc = copy.deepcopy(dev_cpts[pt])
        mc["_source"] = "dev_export"
        merged_cpts.append(mc)

    merged["cpts"] = merged_cpts

    # Global tags: keep user's, add dev's separately
    merged["global_tags"] = copy.deepcopy(user_gt)
    merged["dev_global_tags"] = copy.deepcopy(dev_gt)

    # Write merged output
    save_json(OUTPUT_FILE, merged)

    # Summary
    print(f"\n  MERGE COMPLETE")
    print(f"  Output written to: {OUTPUT_FILE}")
    print(f"  CPTs in merged file: {len(merged_cpts)}")
    print(f"  Common fields in merged: {len(merged['common_fields'])}")
    print(f"  User global_tags: {len(merged['global_tags'])}")
    print(f"  Dev global_tags (reference): {len(merged['dev_global_tags'])}")
    print()


if __name__ == "__main__":
    main()
