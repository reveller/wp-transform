#!/usr/bin/env python3
"""
ACF to GeoDirectory CSV Transformation Script
Go To St. Croix - Data Migration Tool

Transforms ACF Pro export CSV to GeoDirectory import format
"""

import csv
import re
import sys
import json
import time
from pathlib import Path
from stcroix_locations import get_coordinates, get_default_coordinates

# Social media platform URL patterns
SOCIAL_MEDIA_URLS = {
    'facebook': 'https://www.facebook.com/',
    'twitter': 'https://twitter.com/',
    'instagram': 'https://www.instagram.com/',
    'pinterest': 'https://www.pinterest.com/',
    'youtube': 'https://www.youtube.com/@',
    'linkedin': 'https://www.linkedin.com/',
    'trip_advisor': 'https://www.tripadvisor.com/',
    'yelp': 'https://www.yelp.com/biz/'
}

# Field name mapping: ACF/CSV field names -> GeoDirectory field names
# Supports dual naming in override files (both ACF and GD names accepted)
ACF_TO_GD_FIELD_MAP = {
    # ACF custom fields
    'acf_location': 'location',
    'acf_phone': 'phone',
    'acf_website': 'website',
    'acf_email': 'email_',
    'acf_fixed_image': 'fixed_image',
    'acf_spotlight_link': 'spotlight_link',
    'acf_template_layout': 'layout',
    'acf_facebook': 'facebook',
    'acf_twitter': 'twitter',
    'acf_instagram': 'instagram',
    'acf_pinterest': 'pinterest',
    'acf_you_tube': 'youtube',
    'acf_linked_in': 'linkedin',
    'acf_trip_advisor': 'trip_advisor',
    'acf_yelp': 'yelp',
    'acf_other_social_label': 'other_social_label',
    'acf_other_social_url': 'other_social_url',
    'acf_other_social_icon': 'other_social_icon',
    # CSV export fields
    'Title': 'post_title',
    'Content': 'post_content',
    'Status': 'post_status',
    'Author ID': 'post_author',
    'Date': 'post_date',
    'Post Modified Date': 'post_modified',
    'Categories': 'post_category',
    'Tags': 'post_tags',
    'Image URL': 'featured_image',
    'Attachment URL': 'featured_image',
    'images': 'post_images',
    'slider': 'post_images',
    'website_url': 'website',
    'image_alignment': 'featured_image_alignment',
}

# Build reverse mapping (GD -> ACF/CSV) for override files using GD names
GD_TO_ACF_FIELD_MAP = {}
for acf_name, gd_name in ACF_TO_GD_FIELD_MAP.items():
    # Handle multiple ACF fields mapping to same GD field
    # Keep first mapping (arbitrary choice for reverse lookup)
    if gd_name not in GD_TO_ACF_FIELD_MAP:
        GD_TO_ACF_FIELD_MAP[gd_name] = acf_name

import json
from pathlib import Path
from typing import Dict, Any, Callable

# ============================================================================
# GENERIC MAPPING SYSTEM
# ============================================================================
# Module-level cache for all mappings
_MAPPINGS: Dict[str, Dict[str, Dict[str, Any]]] = {}

# Mapping configurations - defines how to parse each JSON file
MAPPING_CONFIGS = {
    'taxonomy': {
        'file': 'gd-taxonomy-map.json',
        'categories': {
            'filter': lambda item: item.get('type') in ('category', 'subcategory'),
            'key': 'name',
            'value': 'id'
        },
        'tags': {
            'filter': lambda item: item.get('type') == 'tag',
            'key': 'name',
            'value': 'id'
        }
    },
    'neighborhoods': {
        'file': 'neighborhoods.json',
        'locations': {
            'filter': lambda item: item.get('type') == 'neighborhood',
            'key': 'acf_location',
            'value': 'neighborhood'
        }
    }
}


def load_mapping(mapping_name: str, json_path: str | Path = None) -> None:
    """
    Generic mapping loader for any mapping type.

    Loads a JSON array file and converts it to lookup dictionaries based on
    the configuration defined in MAPPING_CONFIGS.

    Args:
        mapping_name: Name of mapping type (e.g., 'taxonomy', 'neighborhoods')
        json_path: Optional path to JSON file (defaults to config file path)

    Raises:
        FileNotFoundError: If JSON file doesn't exist
        KeyError: If mapping_name not in MAPPING_CONFIGS

    Example:
        load_mapping('taxonomy')  # Uses gd-taxonomy-map.json from config
        load_mapping('neighborhoods', 'custom-neighborhoods.json')
    """
    global _MAPPINGS

    if mapping_name in _MAPPINGS:
        return  # Already loaded

    if mapping_name not in MAPPING_CONFIGS:
        raise KeyError(f"Unknown mapping type: '{mapping_name}'")

    config = MAPPING_CONFIGS[mapping_name]

    # Use default file path if not provided
    if json_path is None:
        json_path = Path(config['file'])
    else:
        json_path = Path(json_path)

    if not json_path.exists():
        raise FileNotFoundError(f"Mapping file not found: {json_path}")

    with json_path.open("r", encoding="utf-8") as f:
        data_array = json.load(f)

    # Initialize mapping structure for this mapping type
    _MAPPINGS[mapping_name] = {}

    # Process each map type within this mapping
    # (e.g., 'categories' and 'tags' within 'taxonomy')
    for map_type, type_config in config.items():
        if map_type == 'file':
            continue  # Skip the file path entry

        _MAPPINGS[mapping_name][map_type] = {}

        filter_func = type_config.get('filter')
        key_field = type_config['key']
        value_field = type_config['value']

        for item in data_array:
            # Apply filter if specified
            if filter_func and not filter_func(item):
                continue

            key = item.get(key_field)
            value = item.get(value_field)

            # Only add if both key and value exist
            if key is not None and value is not None:
                _MAPPINGS[mapping_name][map_type][key] = value


def get_mapping_value(mapping_name: str, map_type: str, key: str, default: Any = None) -> Any:
    """
    Get a value from a loaded mapping.

    Args:
        mapping_name: Name of mapping (e.g., 'taxonomy', 'neighborhoods')
        map_type: Type within mapping (e.g., 'categories', 'tags', 'locations')
        key: Key to lookup
        default: Default value if not found (default: None)

    Returns:
        Mapped value or default if not found

    Raises:
        RuntimeError: If mapping not loaded
        KeyError: If map_type not found in mapping

    Example:
        get_mapping_value('taxonomy', 'categories', 'Hotels')  # Returns ID
        get_mapping_value('neighborhoods', 'locations', 'Cane Bay', default='')
    """
    if mapping_name not in _MAPPINGS:
        raise RuntimeError(
            f"Mapping '{mapping_name}' not loaded. Call load_mapping('{mapping_name}') first."
        )

    if map_type not in _MAPPINGS[mapping_name]:
        raise KeyError(f"Map type '{map_type}' not found in '{mapping_name}' mapping")

    return _MAPPINGS[mapping_name][map_type].get(key, default)


def has_mapping_key(mapping_name: str, map_type: str, key: str) -> bool:
    """
    Check if a key exists in a mapping without raising exceptions.

    Args:
        mapping_name: Name of mapping
        map_type: Type within mapping
        key: Key to check

    Returns:
        True if key exists, False otherwise
    """
    if mapping_name not in _MAPPINGS:
        return False

    if map_type not in _MAPPINGS[mapping_name]:
        return False

    return key in _MAPPINGS[mapping_name][map_type]


# ============================================================================
# BACKWARD COMPATIBILITY WRAPPERS
# ============================================================================
# These maintain the original API for existing code

# Keep reference to taxonomy mapping for backward compatibility
_NAME_ID_MAP: Dict[str, Any] | None = None

# Keep reference to neighborhood mapping for backward compatibility
_LOCATION_HOOD_MAP: Dict[str, Any] | None = None


def load_name_id_map(json_path: str | Path) -> None:
    """
    Load taxonomy (categories/tags) mapping file.

    DEPRECATED: This is a backward compatibility wrapper.
    New code should use: load_mapping('taxonomy')

    Args:
        json_path: Path to gd-taxonomy-map.json file
    """
    global _NAME_ID_MAP
    load_mapping('taxonomy', json_path)
    _NAME_ID_MAP = _MAPPINGS.get('taxonomy')


def load_location_hood_map(json_path: str | Path) -> None:
    """
    Load neighborhoods mapping file.

    DEPRECATED: This is a backward compatibility wrapper.
    New code should use: load_mapping('neighborhoods')

    Args:
        json_path: Path to neighborhoods.json file
    """
    global _LOCATION_HOOD_MAP
    load_mapping('neighborhoods', json_path)
    _LOCATION_HOOD_MAP = _MAPPINGS.get('neighborhoods')


def get_id_by_name(name: str, map_type: str = "categories") -> int:
    """
    Get taxonomy ID for a category or tag name.

    DEPRECATED: This is a backward compatibility wrapper.
    New code should use: get_mapping_value('taxonomy', map_type, name)

    Args:
        name: Category or tag name
        map_type: 'categories' or 'tags'

    Returns:
        ID value

    Raises:
        RuntimeError: If taxonomy not loaded
        KeyError: If name not found
    """
    if 'taxonomy' not in _MAPPINGS:
        raise RuntimeError(
            "Name-ID map not loaded. Call load_name_id_map() first."
        )

    if map_type not in _MAPPINGS['taxonomy']:
        raise KeyError(f"Map type '{map_type}' not found")

    try:
        return _MAPPINGS['taxonomy'][map_type][name]
    except KeyError:
        raise KeyError(
            f"Name '{name}' not found in '{map_type}' mapping"
        )


def get_neighborhood_by_location(location: str, default: str = '') -> str:
    """
    Get neighborhood slug for an ACF location value.

    Args:
        location: ACF location value (e.g., 'Cane Bay', 'Christiansted')
        default: Default value if not found (default: empty string)

    Returns:
        Neighborhood slug or default if not found

    Example:
        get_neighborhood_by_location('Cane Bay')  # Returns 'cane-bay'
        get_neighborhood_by_location('Unknown')   # Returns ''
    """
    return get_mapping_value('neighborhoods', 'locations', location, default=default)


# ============================================================================
# OVERRIDE FILE SYSTEM
# ============================================================================

def load_override_files(override_files: list[str]) -> dict[tuple[str, str], dict]:
    """
    Load and merge override JSON files.

    Override files contain JSON arrays with Title + Categories + field overrides.
    Each entry must have both 'Title' and 'Categories' fields.
    Uses composite key (Title, Categories) to support same business in multiple categories.

    Args:
        override_files: List of paths to JSON override files

    Returns:
        Dictionary keyed by (Title, Categories) tuple with override data

    Raises:
        ValueError: If entry missing Title/Categories, duplicates found, or invalid format
        FileNotFoundError: If override file doesn't exist
        json.JSONDecodeError: If file contains invalid JSON

    Example:
        overrides = load_override_files(['jennie-stay.json'])
        # Returns: {('The Comanche Hotel', 'Hotels'): {...},
        #           ('The Comanche Hotel', 'Historic Properties'): {...}}
    """
    overrides = {}
    key_sources = {}  # Track which file each (Title, Categories) came from

    for filepath in override_files:
        path = Path(filepath)
        if not path.exists():
            raise FileNotFoundError(f"Override file not found: {filepath}")

        with path.open('r', encoding='utf-8') as f:
            data = json.load(f)

        if not isinstance(data, list):
            raise ValueError(f"Override file must contain a JSON array: {filepath}")

        for entry_idx, entry in enumerate(data, 1):
            if not isinstance(entry, dict):
                continue  # Skip non-dictionary entries

            title = entry.get('Title')
            categories = entry.get('Categories')

            # Validate required fields
            if not title or not title.strip():
                continue  # Skip entries without Title

            if not categories or not categories.strip():
                raise ValueError(
                    f"Missing Categories field in {filepath} entry #{entry_idx}:\n"
                    f"  Title: '{title}'\n"
                    f"All override entries must have both Title and Categories fields."
                )

            title = title.strip()
            categories = categories.strip()

            # Create composite key
            key = (title, categories)

            # Check for conflicts (duplicates within same file or across files)
            if key in overrides:
                if key_sources[key] == filepath:
                    raise ValueError(
                        f"Duplicate entry detected in {filepath}:\n"
                        f"  Title: '{title}'\n"
                        f"  Categories: '{categories}'\n"
                        f"This exact Title + Categories combination appears multiple times.\n"
                        f"Please remove duplicate entries and try again."
                    )
                else:
                    raise ValueError(
                        f"Conflict detected: Title '{title}' with Categories '{categories}' appears in both:\n"
                        f"  - {key_sources[key]}\n"
                        f"  - {filepath}\n"
                        f"Please resolve this conflict and try again."
                    )

            overrides[key] = entry
            key_sources[key] = filepath

    return overrides


def find_matching_override(title: str, csv_categories: str, overrides: dict[tuple[str, str], dict]) -> dict | None:
    """
    Find matching override entry for a CSV row using Title + Categories matching.

    Uses "contains" matching: override category must be present in CSV categories.
    Example: Override with "Hotels" matches CSV with "Hotels,Resorts" or "Resorts,Hotels"

    Args:
        title: Title from CSV row
        csv_categories: Categories string from CSV (may be comma-separated)
        overrides: Dictionary of overrides keyed by (Title, Categories)

    Returns:
        Matching override dict, or None if no match found

    Example:
        overrides = {('Hotel X', 'Hotels'): {...}, ('Hotel X', 'Resorts'): {...}}
        find_matching_override('Hotel X', 'Hotels,Resorts', overrides)
        # Returns the 'Hotels' override (first match found)
    """
    if not title or not csv_categories:
        return None

    # Parse CSV categories into set for matching
    csv_cats = {cat.strip().lower() for cat in csv_categories.split(',') if cat.strip()}

    # Look for matching override entries
    # Check all override keys with matching title
    for (override_title, override_cats), override_data in overrides.items():
        if override_title != title:
            continue

        # Parse override categories
        override_cats_list = {cat.strip().lower() for cat in override_cats.split(',') if cat.strip()}

        # Check if any override category is in CSV categories (contains match)
        if override_cats_list & csv_cats:  # Set intersection
            return override_data

    return None


def apply_overrides(row: dict, override_data: dict) -> dict:
    """
    Apply override data to a CSV row.

    Supports both ACF field names (acf_phone) and GD field names (phone).
    If a field exists in override (even if null), it replaces the CSV value.

    Args:
        row: CSV row dictionary (will not be modified)
        override_data: Override data from JSON file

    Returns:
        New row dictionary with overrides applied

    Example:
        row = {'Title': 'Hotel X', 'acf_phone': '123-4567', ...}
        override = {'Title': 'Hotel X', 'acf_phone': '999-8888'}
        result = apply_overrides(row, override)
        # result['acf_phone'] == '999-8888'
    """
    # Create a copy to avoid modifying original
    row = row.copy()

    for field, value in override_data.items():
        if field == 'Title':
            continue  # Don't override the key field

        # Convert value to string to match CSV data types
        # CSV files always have strings, but JSON can have numbers, booleans, null, etc.
        if value is None:
            str_value = ''
        elif isinstance(value, bool):
            # Handle booleans explicitly (before numeric check)
            str_value = '1' if value else '0'
        elif isinstance(value, (int, float)):
            # Convert numbers to strings
            # Handle floats that are really integers (32427.0 -> "32427")
            if isinstance(value, float) and value.is_integer():
                str_value = str(int(value))
            else:
                str_value = str(value)
        else:
            # Already a string or other type, convert to string
            str_value = str(value) if value != '' else ''

        # Strategy:
        # 1. If field name exists in CSV row, use it directly (ACF names)
        # 2. If not, check if it's a GD name that maps to a CSV/ACF field
        # 3. Otherwise, add as new field (future-proofing)

        if field in row:
            # Direct match (ACF field name like 'acf_phone')
            row[field] = str_value
        elif field in GD_TO_ACF_FIELD_MAP:
            # GD name that maps to ACF name (e.g., 'phone' -> 'acf_phone')
            csv_field = GD_TO_ACF_FIELD_MAP[field]
            if csv_field in row:
                row[csv_field] = str_value
            else:
                # CSV field doesn't exist, add GD field directly
                row[field] = str_value
        else:
            # Unknown field, add it anyway (might be custom field)
            row[field] = str_value

    return row


def apply_output_overrides(output_row: dict, title: str, categories: str,
                           overrides: dict[tuple[str, str], dict]) -> dict:
    """
    Apply second-pass GeoDirectory field overrides to the output row.

    This happens AFTER transformation, directly modifying the final output fields.
    Allows Jennie to override transformed values like street addresses, coordinates, etc.

    Args:
        output_row: GeoDirectory output row dictionary
        title: Post title for matching
        categories: Categories string for matching
        overrides: Override dictionary with composite keys

    Returns:
        Modified output_row dictionary

    Example:
        # Override has: {"street": "123 Main St", "zip": "00820"}
        # These directly replace the output_row values after transformation
    """
    # Find matching override using same composite key logic
    override_data = find_matching_override(title, categories, overrides)
    if not override_data:
        return output_row

    # Apply any fields from override that match output_row keys
    for field, value in override_data.items():
        # Skip the key fields
        if field in ('Title', 'Categories', 'Tags'):
            continue

        # Skip ACF fields - they were handled in first pass
        if field.startswith('acf_'):
            continue

        # If this field exists in output_row, override it
        if field in output_row:
            # Convert value to string (same logic as apply_overrides)
            if value is None:
                str_value = ''
            elif isinstance(value, bool):
                str_value = '1' if value else '0'
            elif isinstance(value, (int, float)):
                if isinstance(value, float) and value.is_integer():
                    str_value = str(int(value))
                else:
                    str_value = str(value)
            else:
                str_value = str(value) if value != '' else ''

            output_row[field] = str_value

    return output_row


# ============================================================================
# ID MAPPING SYSTEM
# ============================================================================

def load_ids_file(ids_file: str) -> dict[tuple[str, str], int]:
    """
    Load listing IDs from JSON file.

    IDs file contains JSON array with id, Title, and Categories fields.
    Uses composite key (Title, Categories) to support same business in multiple categories.

    Args:
        ids_file: Path to listing IDs JSON file

    Returns:
        Dictionary keyed by (Title, Categories) tuple with ID values

    Raises:
        ValueError: If entry missing required fields or duplicates found
        FileNotFoundError: If IDs file doesn't exist
        json.JSONDecodeError: If file contains invalid JSON

    Example:
        ids = load_ids_file('listing-ids.json')
        # Returns: {('The Comanche Hotel', 'Hotels'): 117137,
        #           ('The Comanche Hotel', 'Historic Properties'): 117188}
    """
    ids_map = {}

    path = Path(ids_file)
    if not path.exists():
        raise FileNotFoundError(f"IDs file not found: {ids_file}")

    with path.open('r', encoding='utf-8') as f:
        data = json.load(f)

    if not isinstance(data, list):
        raise ValueError(f"IDs file must contain a JSON array: {ids_file}")

    for entry_idx, entry in enumerate(data, 1):
        if not isinstance(entry, dict):
            continue  # Skip non-dictionary entries

        title = entry.get('Title')
        categories = entry.get('Categories')
        listing_id = entry.get('id')

        # Validate required fields
        if not title or not title.strip():
            continue  # Skip entries without Title

        if not categories or not categories.strip():
            raise ValueError(
                f"Missing Categories field in {ids_file} entry #{entry_idx}:\n"
                f"  Title: '{title}'\n"
                f"All ID entries must have Title, Categories, and id fields."
            )

        if listing_id is None:
            raise ValueError(
                f"Missing id field in {ids_file} entry #{entry_idx}:\n"
                f"  Title: '{title}'\n"
                f"  Categories: '{categories}'\n"
                f"All ID entries must have Title, Categories, and id fields."
            )

        title = title.strip()
        categories = categories.strip()

        # Create composite key
        key = (title, categories)

        # Check for duplicates
        if key in ids_map:
            raise ValueError(
                f"Duplicate entry detected in {ids_file}:\n"
                f"  Title: '{title}'\n"
                f"  Categories: '{categories}'\n"
                f"This exact Title + Categories combination appears multiple times."
            )

        # Convert ID to int if it's not already
        try:
            ids_map[key] = int(listing_id)
        except (TypeError, ValueError):
            raise ValueError(
                f"Invalid id value in {ids_file} entry #{entry_idx}:\n"
                f"  Title: '{title}'\n"
                f"  id: '{listing_id}'\n"
                f"ID must be a valid integer."
            )

    return ids_map


def find_matching_id(title: str, csv_categories: str, ids_map: dict[tuple[str, str], int]) -> str:
    """
    Find matching ID for a CSV row using Title + Categories matching.

    Handles mismatch between ACF category names (e.g., "Hotels") and
    GeoDirectory category paths (e.g., "Places to Stay>Resorts + Hotels").

    Strategy:
    1. Match by Title first
    2. Try to find ACF category name within GeoDirectory category path
    3. If multiple matches, use first one found
    4. If no category match but Title matches, use first ID for that Title

    Args:
        title: Title from CSV row
        csv_categories: Categories string from CSV (ACF format, comma-separated)
        ids_map: Dictionary of IDs keyed by (Title, GeoDirectory categories)

    Returns:
        ID as string, or empty string if no match found

    Example:
        # ACF: "Hotels"  →  GeoDirectory: "Places to Stay>Resorts + Hotels"
        ids = {('Hotel X', 'Places to Stay>Resorts + Hotels'): 12345}
        find_matching_id('Hotel X', 'Hotels', ids)
        # Returns '12345' because "Hotels" found in path
    """
    if not title:
        return ''

    # Parse CSV categories (ACF format)
    csv_cats = [cat.strip() for cat in csv_categories.split(',') if cat.strip()]

    # Collect all IDs for matching Title
    matching_ids = []
    for (id_title, id_cat_path), listing_id in ids_map.items():
        if id_title == title:
            matching_ids.append((id_cat_path, listing_id))

    if not matching_ids:
        return ''  # No Title match

    # If only one ID for this Title, use it
    if len(matching_ids) == 1:
        return str(matching_ids[0][1])

    # Multiple IDs for this Title - try category matching
    # Check if any ACF category name appears in the GeoDirectory category path
    for csv_cat in csv_cats:
        csv_cat_lower = csv_cat.lower()
        for gd_cat_path, listing_id in matching_ids:
            # Check if ACF category appears anywhere in GeoDirectory path
            # e.g., "Hotels" in "Places to Stay>Resorts + Hotels"
            if csv_cat_lower in gd_cat_path.lower():
                return str(listing_id)

    # No category match found, return first ID for this Title
    return str(matching_ids[0][1])


from bs4 import BeautifulSoup, NavigableString, Tag
from collections import Counter

def parse_tabs(html_content: str, clean_out: bool) -> tuple[list[dict], str]:
    """
    Parse Beaver Builder tabbed content blocks from a post body and extract tab data.

    This function scans the supplied HTML content for Beaver Builder "Tabs" modules,
    automatically detects each tab's title and associated content, and returns them
    as structured data. All tab-related markup is removed from the original content,
    and the cleaned version is returned alongside the parsed tab data.

    IMPORTANT: This function ONLY parses tab data from the post content HTML.
    It does NOT use ACF custom fields (acf_tab_1_name, etc.). Posts must have
    flattened Beaver Builder tab markup in their Content field to be detected.

    A tab entry is returned as a dictionary with the following structure:

        {
            "name": "<tab title text>",
            "content": "<HTML content belonging to this tab>"
        }

    The function supports posts that contain:
        • zero or more tab modules
        • an arbitrary number of tabs per module
        • tab titles that differ between posts

    Detection Algorithm:
        1. Finds text that appears exactly twice in the content (tab labels)
        2. Filters out Beaver Builder wrapper text and short strings
        3. Identifies the first contiguous run of repeating text as tab titles
        4. Requires at least 2 distinct tab titles to proceed
        5. Extracts content between each tab title occurrence

    Parameters
    ----------
    html_content : str
        The raw HTML content blob of a WordPress post. This may include one or more
        Beaver Builder tab modules mixed together with normal post content.
    clean_out : bool
        Boolean value to indicate whether the tab name and content should be
        cleaned out of the original content. If true, will return the content
        with the tab name and contents removed. If false, will return the original,
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
    - Skips the first 512 bytes of content to avoid early wrapper elements.
    - Filters out known Beaver Builder wrapper text like "Overlay Wrapper".
    - Requires tab titles to be at least 3 characters long.

    Examples
    --------
    >>> tabs, cleaned = parse_tabs(html)
    >>> tabs[0]["name"]
    'Menu'
    >>> tabs[0]["content"][:20]
    '<p>Today we are s...'
    """

    soup = BeautifulSoup(html_content, "html.parser")

    # Known Beaver Builder wrapper text to filter out
    BB_WRAPPERS = {
        'Overlay Wrapper',
        'Overlay Wrapper Closed',
        'Previous slide',
        'Next slide',
        '.',
        '..',
        '...'
    }

    # ---- 1️⃣ Collect all leaf text nodes, splitting multi-line nodes ----
    texts = []
    char_count = 0
    skip_threshold = 512

    for node in soup.descendants:
        if isinstance(node, NavigableString):
            node_text = str(node)
            char_count += len(node_text)

            # Only collect text nodes after skip threshold
            if char_count > skip_threshold:
                # Split multi-line text nodes into individual lines
                lines = node_text.split('\n')
                for line in lines:
                    s = line.strip()
                    if s:
                        texts.append((node, s))

    labels = [s for _, s in texts]

    # ---- 2️⃣ Find repeating titles (the tab labels) ----
    counts = Counter(labels)
    # Filter: must appear 2+ times, be 3+ chars, and not be BB wrapper text
    repeating = [
        t for t, c in counts.items()
        if c >= 2 and len(t) >= 3 and t not in BB_WRAPPERS
    ]

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

    # ---- 3️⃣ Walk content and slice into tabs ----
    tabs = []
    nodes_to_remove = set()

    # iterator over all nodes
    all_nodes = list(soup.descendants)
    i = 0
    while i < len(all_nodes):
        node = all_nodes[i]

        if isinstance(node, NavigableString):
            # Check if this node contains any of our tab titles
            # Handle both single-line and multi-line text nodes
            node_text = str(node)
            lines = node_text.split('\n')

            # Check each line for a title match
            matched_title = None
            for line in lines:
                stripped_line = line.strip()
                if stripped_line in ordered_titles:
                    matched_title = stripped_line
                    break

            # Also check the full stripped node (for single-line matches)
            full_stripped = node.strip()
            if not matched_title and full_stripped in ordered_titles:
                matched_title = full_stripped

            if matched_title:
                # start new tab
                section_nodes = []
                contents = []

                j = i + 1
                while j < len(all_nodes):
                    nxt = all_nodes[j]

                    # Check if we've hit an <h2> tag - this marks end of tabs section
                    if isinstance(nxt, Tag) and nxt.name == 'h2':
                        break

                    # Check if next node contains a tab title (same logic)
                    is_next_title = False
                    if isinstance(nxt, NavigableString):
                        nxt_text = str(nxt)
                        nxt_lines = nxt_text.split('\n')
                        for nxt_line in nxt_lines:
                            if nxt_line.strip() in ordered_titles:
                                is_next_title = True
                                break
                        if not is_next_title and nxt.strip() in ordered_titles:
                            is_next_title = True

                    if is_next_title:
                        break

                    # capture html for top-level DOM objects inside the tab
                    if isinstance(nxt, (NavigableString, Tag)):
                        contents.append(str(nxt))
                    section_nodes.append(nxt)
                    j += 1

                tabs.append({
                    "name": matched_title,
                    "contents": "".join(contents).strip()
                })

                # mark all nodes to remove including label
                nodes_to_remove.add(node)
                nodes_to_remove.update(section_nodes)

                i = j
                continue

        i += 1

    # ---- 4️⃣ Conditionally remove tab content + headers from original ----
    if clean_out:
        # Remove tab nodes if clean_out flag is True
        for n in nodes_to_remove:
            try:
                n.extract()
            except Exception:
                pass
        cleaned_html = str(soup)
    else:
        # Return original content unchanged if clean_out is False
        cleaned_html = html_content

    return tabs, cleaned_html


class ImageCopyScriptGenerator:
    """Generates a shell script to copy media library files."""

    def __init__(self, source_path, dest_path, script_filename):
        self.source_path = source_path.rstrip('/')  # Remove trailing slash
        self.dest_path = dest_path.rstrip('/')
        self.script_filename = script_filename
        self.tracked_images = set()  # For deduplication
        self.script_lines = []

        # Initialize script with header
        self._init_script()

    def _init_script(self):
        """Initialize script with bash header and setup."""
        self.script_lines = [
            '#!/bin/bash',
            '# Generated by transform.py',
            f'# Source: {self.source_path}',
            f'# Destination: {self.dest_path}',
            '',
            'set -e  # Exit on error',
            '',
            'ERRORS=0',
            'COPIED=0',
            'SKIPPED=0',
            '',
            'echo "Starting media file copy..."',
            'echo ""',
            ''
        ]

    def add_image(self, relative_path):
        """
        Add an image to the copy script.

        Args:
            relative_path: Image path like /2022/12/image.jpg

        Returns:
            bool: True if added, False if skipped (duplicate or invalid)
        """
        # Skip if empty
        if not relative_path or not relative_path.strip():
            return False

        # Skip external URLs (anything not starting with /)
        if not relative_path.startswith('/'):
            return False

        # Remove leading slash for file path
        file_path = relative_path.lstrip('/')

        # Skip duplicates
        if file_path in self.tracked_images:
            return False

        # Add to tracked set
        self.tracked_images.add(file_path)

        # Build source and destination paths
        source_file = f"{self.source_path}/{file_path}"
        dest_file = f"{self.dest_path}/{file_path}"

        # Get directory for mkdir -p
        dest_dir = '/'.join(dest_file.split('/')[:-1])

        # Add commands to script
        self.script_lines.append(f'# Copy {file_path}')
        self.script_lines.append(f'if [ -f "{source_file}" ]; then')
        self.script_lines.append(f'    mkdir -p "{dest_dir}"')
        self.script_lines.append(f'    cp "{source_file}" "{dest_file}"')
        self.script_lines.append(f'    COPIED=$((COPIED + 1))')
        self.script_lines.append(f'    echo "✓ Copied: {file_path}"')
        self.script_lines.append(f'else')
        self.script_lines.append(f'    ERRORS=$((ERRORS + 1))')
        self.script_lines.append(f'    echo "✗ ERROR: Source file not found: {source_file}"')
        self.script_lines.append(f'fi')
        self.script_lines.append('')

        return True

    def write_script(self):
        """Write the complete script to file."""
        # Add summary footer
        self.script_lines.extend([
            'echo ""',
            'echo "================================"',
            'echo "Copy Summary:"',
            'echo "  Copied: $COPIED files"',
            'echo "  Errors: $ERRORS files"',
            'echo "  Skipped duplicates: $SKIPPED files"',
            'echo "================================"',
            '',
            'if [ $ERRORS -gt 0 ]; then',
            '    echo ""',
            '    echo "WARNING: $ERRORS files were not found"',
            '    exit 1',
            'fi'
        ])

        # Write to file
        with open(self.script_filename, 'w') as f:
            f.write('\n'.join(self.script_lines))

        # Make executable
        import os
        os.chmod(self.script_filename, 0o755)

        print(f"\nGenerated copy script: {self.script_filename}", file=sys.stderr)
        print(f"  Total images to copy: {len(self.tracked_images)}", file=sys.stderr)

def transform_names_to_ids(text, map_type='categories', separators='|,', fallback_id=2184, unmapped_tracker=None):
    """
    Transform category/tag names to GeoDirectory ID format.

    Args:
        text: Text containing category/tag names (pipe or comma separated)
        map_type: 'categories' or 'tags' for lookup
        separators: String of valid separator characters
        fallback_id: ID to use for unmapped names (default: 2184 Uncategorized)
        unmapped_tracker: Optional set to track unmapped names

    Returns:
        Formatted ID string: ",2041,2042," or "" if no valid IDs

    Example:
        >>> transform_names_to_ids("Play,Eat", "categories")
        ",2041,2043,"
        >>> transform_names_to_ids("Unknown", "categories")
        ",2184,"
        >>> transform_names_to_ids("", "categories")
        ""
    """
    if not text or not text.strip():
        return ''

    # Split on any separator
    names = re.split(f'[{re.escape(separators)}]', text)

    # Look up IDs (deduplicated, preserving order)
    ids = []
    seen_ids = set()

    for name in names:
        name = name.strip()
        if not name:
            continue

        # Case-insensitive lookup - try exact match first, then title case
        id_val = None
        try:
            id_val = get_id_by_name(name, map_type)
        except KeyError:
            # Try title case
            try:
                id_val = get_id_by_name(name.title(), map_type)
            except KeyError:
                # Use fallback and track
                id_val = fallback_id
                if unmapped_tracker is not None:
                    unmapped_tracker.add(name)

        # Deduplicate
        if id_val not in seen_ids:
            ids.append(id_val)
            seen_ids.add(id_val)

    if not ids:
        return ''

    # Format as ",ID1,ID2,"
    return ',' + ','.join(str(id_val) for id_val in ids) + ','


def get_category_with_parent(name, map_type='categories'):
    """
    Get category/subcategory ID and parent ID if it exists.

    Returns:
        tuple: (category_id, parent_id or None)
    """
    import json
    from pathlib import Path

    # Load the full taxonomy to check for parent relationships
    taxonomy_file = Path('gd-taxonomy-map.json')
    if not taxonomy_file.exists():
        # Fallback if file doesn't exist
        try:
            cat_id = get_id_by_name(name, map_type)
            return (cat_id, None)
        except KeyError:
            return (None, None)

    with taxonomy_file.open('r') as f:
        taxonomy = json.load(f)

    # Find the matching entry
    for item in taxonomy:
        if item.get('name') == name and item.get('type') in ('category', 'subcategory'):
            cat_id = item.get('id')
            parent_id = item.get('parent_id', 0)
            # Return parent_id only if it's not 0 (0 means top-level category)
            return (cat_id, parent_id if parent_id != 0 else None)

    # Not found
    return (None, None)


def transform_categories_to_ids(categories_text, unmapped_tracker=None):
    """
    Transform category names to GeoDirectory category IDs.

    For subcategories, includes both parent and subcategory IDs.
    Example: "Hotels" → ",2093,2094," (Places to Stay + Resorts + Hotels)

    Fallback logic:
    1. Try to find category in categories map
    2. If not found, try to find matching tag name in tags map
    3. If still not found, use Uncategorized (ID 2184)

    Args:
        categories_text: Category names (comma or pipe-separated)
        unmapped_tracker: Optional set to collect unmapped category names

    Returns:
        Formatted ID string like ",2093,2094," (parent,child)
    """
    if not categories_text or not categories_text.strip():
        return ''

    # Split on pipe or comma
    names = re.split(r'[|,]', categories_text)

    # Look up IDs with fallback logic
    ids = []
    seen_ids = set()

    for name in names:
        name = name.strip()
        if not name:
            continue

        # Try to find in categories (case-insensitive)
        cat_id, parent_id = get_category_with_parent(name, 'categories')

        if cat_id is None:
            # Try title case
            cat_id, parent_id = get_category_with_parent(name.title(), 'categories')

        if cat_id is None:
            # Category not found - try to find matching tag
            cat_id, parent_id = get_category_with_parent(name, 'tags')
            if cat_id is None:
                cat_id, parent_id = get_category_with_parent(name.title(), 'tags')

        if cat_id is None:
            # Neither category nor tag found - use Uncategorized
            cat_id = 2184
            parent_id = None
            if unmapped_tracker is not None:
                unmapped_tracker.add(name)

        # Add parent first, then category (if parent exists)
        if parent_id and parent_id not in seen_ids:
            ids.append(parent_id)
            seen_ids.add(parent_id)

        # Add the category itself
        if cat_id not in seen_ids:
            ids.append(cat_id)
            seen_ids.add(cat_id)

    if not ids:
        return ''

    # Format as ",ID1,ID2,"
    return ',' + ','.join(str(id_val) for id_val in ids) + ','


def transform_tags_to_ids(tags_text, unmapped_tracker=None):
    """
    Transform tag names to GeoDirectory tag format.

    Tags are NOT mapped to IDs - they simply use their tag names.

    Args:
        tags_text: Tag names (pipe-separated)
        unmapped_tracker: Optional set to collect unmapped tag names (unused for tags)

    Returns:
        Quoted comma-separated tag names: "Beachfront","Pool","Restaurant"
        Empty string if no tags provided
    """
    if not tags_text or not tags_text.strip():
        return ''

    # Split on pipe
    names = re.split(r'\|', tags_text)

    # Collect tag names (deduplicated)
    results = []
    seen = set()

    for name in names:
        name = name.strip()
        if not name:
            continue

        # Deduplicate (case-insensitive)
        check_key = name.lower()
        if check_key not in seen:
            results.append(name)
            seen.add(check_key)

    if not results:
        return ''

    # Return comma-separated tag names (CSV writer will handle quoting if needed)
    return ','.join(results)


def get_first_category_id(post_category_ids):
    """
    Extract first category ID from formatted ID string.

    Args:
        post_category_ids: Formatted ID string like ",2041,2042," or ""

    Returns:
        First category ID as string, or "2184" if empty/invalid

    Example:
        >>> get_first_category_id(",2041,2043,")
        "2041"
        >>> get_first_category_id("")
        "2184"
    """
    if not post_category_ids or post_category_ids.strip() == '':
        return '2184'  # Uncategorized fallback

    # Extract IDs from ",2041,2042," format
    # Strip leading/trailing commas and split
    ids = post_category_ids.strip(',').split(',')

    # Get first valid ID
    for id_str in ids:
        id_str = id_str.strip()
        if id_str and id_str.isdigit():
            return id_str

    # Fallback if no valid IDs found
    return '2184'


def format_phone(phone):
    """Format phone number as 340-555-1234"""
    if not phone:
        return ''

    # Remove all non-numeric characters
    digits = re.sub(r'\D', '', phone)

    # Format as 340-555-1234
    if len(digits) == 10:
        return f"{digits[0:3]}-{digits[3:6]}-{digits[6:10]}"
    elif len(digits) == 7:
        # Assume local St. Croix number, add 340
        return f"340-{digits[0:3]}-{digits[3:7]}"

    # Return original if can't format
    return phone


def transform_image_url(url):
    """
    Transform image URL by removing domain and path up to and including 'uploads'.

    Example:
        https://staging-gotostcroix.wordkeeper.net/wp-content/uploads/2019/11/Flying-GTSC.jpg
        → /2019/11/Flying-GTSC.jpg

    Args:
        url: Full image URL

    Returns:
        Relative path starting with / after uploads directory
    """
    if not url or not url.strip():
        return url

    # Find 'uploads/' in the URL
    uploads_idx = url.find('/uploads/')

    if uploads_idx != -1:
        # Return everything after 'uploads/' with a leading slash
        return url[uploads_idx + len('/uploads'):]

    # If 'uploads/' not found, return original URL
    return url


def format_images_gallery(images_field):
    """
    Convert image gallery to GeoDirectory format
    Handles: comma-separated, array format, or already pipe-separated

    Returns:
        GeoDirectory formatted string: "URL1|||::URL2|||::URL3|||"
        Format: URL|ID|TITLE|DESCRIPTION (ID, TITLE, DESCRIPTION left empty for imports)
    """
    if not images_field:
        return ''

    urls = []

    # Already pipe-separated (single pipe from old format)
    if '|' in images_field and '::' not in images_field:
        urls = [u.strip() for u in images_field.split('|') if u.strip()]
    # Comma-separated URLs
    elif ',' in images_field and 'http' in images_field:
        urls = [u.strip() for u in images_field.split(',') if u.strip()]
    # Array format like ["url1","url2"]
    elif images_field.startswith('['):
        urls = re.findall(r'https?://[^\s",\]]+', images_field)
    # Already in GeoDirectory format (contains ::)
    elif '::' in images_field:
        return images_field
    # Single image URL (but skip numeric-only IDs)
    elif images_field.strip():
        value = images_field.strip()
        # Only add if it looks like a URL (not just a numeric ID)
        if not value.isdigit():
            urls = [value]

    if not urls:
        return ''

    # Filter out any remaining non-URL values and transform to relative paths
    transformed_urls = []
    for url in urls:
        # Skip numeric-only values (image IDs)
        if url.isdigit():
            continue
        # Skip empty values
        if not url.strip():
            continue
        # Transform and add
        transformed_url = transform_image_url(url)
        # Only keep if it looks like a URL (starts with / or http)
        if transformed_url.startswith('/') or transformed_url.startswith('http'):
            transformed_urls.append(transformed_url)

    if not transformed_urls:
        return ''

    # Format as GeoDirectory expects: URL|ID|TITLE|DESCRIPTION
    # Leave ID, TITLE, DESCRIPTION empty for new imports
    formatted_images = ['|'.join([url, '', '', '']) for url in transformed_urls]

    # Return images separated by ::
    return '::'.join(formatted_images)

def get_first_category(categories):
    """Extract first category for default_category field"""
    if not categories:
        return ''
    
    # Comma-separated
    if ',' in categories:
        return categories.split(',')[0].strip()
    
    # Pipe-separated
    if '|' in categories:
        return categories.split('|')[0].strip()
    
    return categories.strip()

def choose_best_value(value1, value2):
    """Choose first non-empty value"""
    return value1 if value1 else value2

def clean_url(url):
    """Ensure URL has protocol"""
    if not url:
        return ''

    url = url.strip()

    # Already has protocol
    if url.startswith('http://') or url.startswith('https://'):
        return url

    # Add https if missing
    if url and not url.startswith('http'):
        return f"https://{url}"

    return url

def format_datetime(date_value):
    """
    Format date string to include time component for WordPress datetime fields.

    WordPress expects datetime in format: YYYY-MM-DD HH:MM:SS
    If the input only has a date (YYYY-MM-DD), append default time 00:00:00

    Args:
        date_value: Date string (YYYY-MM-DD) or datetime string (YYYY-MM-DD HH:MM:SS)

    Returns:
        Datetime string in format YYYY-MM-DD HH:MM:SS
    """
    if not date_value:
        return ''

    date_value = date_value.strip()

    # If it already has a time component (contains space or T separator), return as-is
    if ' ' in date_value or 'T' in date_value:
        # If using T separator (ISO format), replace with space
        return date_value.replace('T', ' ')

    # Date-only value, append default time
    return f"{date_value} 00:00:00"

def transform_social_url(platform, value):
    """
    Transform social media username/handle to full URL

    Args:
        platform: Platform name (facebook, twitter, instagram, etc.)
        value: Username, handle, or existing URL

    Returns:
        Full URL for the social media profile
    """
    if not value:
        return ''

    # Strip whitespace
    value = value.strip()

    if not value:
        return ''

    # If already a URL, upgrade http to https and return
    if value.startswith('http://'):
        return value.replace('http://', 'https://', 1)

    if value.startswith('https://'):
        return value

    # Clean the username
    # Strip trailing slashes
    username = value.rstrip('/')

    # Strip leading @ symbols
    username = username.lstrip('@')

    # Strip any remaining whitespace
    username = username.strip()

    if not username:
        return ''

    # Special handling for LinkedIn
    if platform == 'linkedin':
        # Check if username already has in/ or company/ prefix
        if username.startswith('in/') or username.startswith('company/'):
            return SOCIAL_MEDIA_URLS[platform] + username
        else:
            # Default to personal profile format
            return SOCIAL_MEDIA_URLS[platform] + 'in/' + username

    # For all other platforms, use the base URL + username
    if platform in SOCIAL_MEDIA_URLS:
        return SOCIAL_MEDIA_URLS[platform] + username

    # Fallback: just clean the URL
    return clean_url(value)

def filter_beaver_builder_tags(content):
    """
    Remove Beaver Builder tags from content

    Args:
        content: HTML/text content that may contain Beaver Builder tags

    Returns:
        Cleaned content with Beaver Builder tags removed
    """
    if not content:
        return ''

    # Remove Beaver Builder specific tags (both formats):
    # - Legacy format: <!-- fl-builder... -->
    # - WordPress block format: <!-- wp:fl-builder/... --> and <!-- /wp:fl-builder/... -->
    content = re.sub(r'<!--\s*/?wp:fl-builder[^>]*-->', '', content)
    content = re.sub(r'<!--\s*fl-builder[^>]*-->', '', content)

    # Also handle Unicode-escaped versions (e.g., in wp:divi blocks):
    # \u003c!\u002d\u002d wp:fl-builder... \u002d\u002d\u003e
    content = re.sub(r'\\u003c!\\u002d\\u002d\s*/?wp:fl-builder[^\\]*\\u002d\\u002d\\u003e', '', content)
    content = re.sub(r'\\u003c!\\u002d\\u002d\s*fl-builder[^\\]*\\u002d\\u002d\\u003e', '', content)

    # Clean up any excessive whitespace left behind
    content = re.sub(r'\n\s*\n\s*\n', '\n\n', content)

    return content.strip()


def extract_jpg_urls_from_content(content):
    """
    Extract JPG image URLs from HTML content and format for GeoDirectory.
    Filters out duplicate images with different resolutions, keeping only master images.

    Args:
        content: HTML/text content that may contain image links

    Returns:
        GeoDirectory formatted string: "URL1|||::URL2|||::URL3|||"
        Format: URL|ID|TITLE|DESCRIPTION (ID, TITLE, DESCRIPTION left empty for imports)
    """
    if not content:
        return ''

    # Find all URLs ending in .jpg or .jpeg (case-insensitive)
    # Matches both src="..." and href="..." attributes, plus standalone URLs
    jpg_pattern = r'(?:src=|href=)?["\']?(https?://[^\s"\'<>]+\.jpe?g)["\']?'
    matches = re.findall(jpg_pattern, content, re.IGNORECASE)

    if not matches:
        return ''

    # Group URLs by base filename (without resolution suffix like -1024x768)
    # Pattern: filename-WIDTHxHEIGHT.jpg
    resolution_pattern = r'-(\d+)x(\d+)(\.jpe?g)$'

    url_groups = {}  # base_url -> list of (url, width or None)

    for url in matches:
        # Check if URL has resolution suffix
        match = re.search(resolution_pattern, url, re.IGNORECASE)
        if match:
            width = int(match.group(1))
            # Remove the resolution suffix to get base URL
            base_url = re.sub(resolution_pattern, r'\3', url, flags=re.IGNORECASE)
            if base_url not in url_groups:
                url_groups[base_url] = []
            url_groups[base_url].append((url, width))
        else:
            # No resolution suffix - this is likely the master
            base_url = url
            if base_url not in url_groups:
                url_groups[base_url] = []
            url_groups[base_url].append((url, None))

    # For each group, select the master image
    master_urls = []
    for base_url, variants in url_groups.items():
        # Prefer URL without resolution suffix (width = None)
        master_variant = None
        for url, width in variants:
            if width is None:
                master_variant = url
                break

        # If no master without suffix, prefer 1440 width or largest
        if master_variant is None:
            # Sort by width descending, preferring 1440 if present
            variants_sorted = sorted(variants, key=lambda x: (x[1] != 1440, -(x[1] or 0)))
            master_variant = variants_sorted[0][0]

        master_urls.append(master_variant)

    # Remove duplicates while preserving order
    seen = set()
    unique_urls = []
    for url in master_urls:
        url_lower = url.lower()
        if url_lower not in seen:
            seen.add(url_lower)
            unique_urls.append(url)

    # Transform URLs to relative paths (remove domain and path up to uploads)
    transformed_urls = [transform_image_url(url) for url in unique_urls]

    # Format as GeoDirectory expects: URL|ID|TITLE|DESCRIPTION
    # Leave ID, TITLE, DESCRIPTION empty for new imports
    formatted_images = ['|'.join([url, '', '', '']) for url in transformed_urls]

    # Return images separated by ::
    return '::'.join(formatted_images)


def extract_youtube_urls_from_content(content):
    """
    Extract YouTube embed URLs from HTML content and format for GeoDirectory.

    Args:
        content: HTML/text content that may contain YouTube embeds

    Returns:
        GeoDirectory formatted string: "URL1|||::URL2|||::URL3|||"
        Format: URL|ID|TITLE|DESCRIPTION (ID, TITLE, DESCRIPTION left empty for imports)
    """
    if not content:
        return ''

    # Find YouTube URLs in various formats:
    # - youtube.com/embed/VIDEO_ID
    # - youtube.com/watch?v=VIDEO_ID
    # - youtu.be/VIDEO_ID
    youtube_patterns = [
        r'(?:https?:)?//(?:www\.)?youtube\.com/embed/([a-zA-Z0-9_-]+)',
        r'(?:https?:)?//(?:www\.)?youtube\.com/watch\?v=([a-zA-Z0-9_-]+)',
        r'(?:https?:)?//youtu\.be/([a-zA-Z0-9_-]+)',
    ]

    video_ids = set()
    for pattern in youtube_patterns:
        matches = re.findall(pattern, content, re.IGNORECASE)
        video_ids.update(matches)

    if not video_ids:
        return ''

    # Convert video IDs to embed URLs
    embed_urls = [f'https://www.youtube.com/embed/{vid}' for vid in sorted(video_ids)]

    # Format as GeoDirectory expects: URL|ID|TITLE|DESCRIPTION
    # Leave ID, TITLE, DESCRIPTION empty for new imports
    formatted_videos = ['|'.join([url, '', '', '']) for url in embed_urls]

    # Return videos separated by ::
    return '::'.join(formatted_videos)


def extract_urls_from_geodir_format(geodir_string):
    """
    Extract image URLs from GeoDirectory formatted string.

    Format: URL|ID|TITLE|DESC:::URL|ID|TITLE|DESC
    Also handles :: as separator for compatibility.

    Returns:
        list: List of URL strings
    """
    if not geodir_string or not geodir_string.strip():
        return []

    urls = []

    # First split by ::: or :: to get individual image entries
    # Use regex to split by either ::: or ::
    import re
    # Split by triple colon first, then double colon
    entries = re.split(r':::', geodir_string)

    # Further split each entry by :: if it contains multiple images
    all_entries = []
    for entry in entries:
        all_entries.extend(entry.split('::'))

    # Now extract URL from each entry (URL is the first element before |)
    for entry in all_entries:
        if not entry.strip():
            continue
        # Split by | to get URL (first element)
        parts = entry.split('|')
        if parts and parts[0].strip():
            url = parts[0].strip()
            # Only add if it looks like a valid URL (starts with / or http)
            if url.startswith('/') or url.startswith('http'):
                urls.append(url)

    return urls


def load_address_cache(cache_file='address_cache.json'):
    """Load address cache from JSON file"""
    if not Path(cache_file).exists():
        return {}

    try:
        with open(cache_file, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"⚠️  Warning: Could not load address cache: {e}", file=sys.stderr)
        return {}

def transform_csv(input_file, output_file, test_mode=False, category_filter=None, tags_filter=None,
                  layouts_filter=None, exclude_categories=None, exclude_tags=None,
                  default_lat=None, default_lng=None, skip_geocoding=False,
                  use_address_cache=False, filter_bb=False, enable_default_address=False,
                  image_script=None, clean_tab_contents=False, include_filter=None, exclude_filter=None,
                  entries_per_file=None, override_files=None, pull_tabs=False):
    """
    Transform ACF CSV to GeoDirectory format

    Args:
        input_file: Path to ACF export CSV
        output_file: Path for GeoDirectory import CSV
        test_mode: If True, only process first 5 rows
        category_filter: Comma-separated category names to filter by (include only)
        tags_filter: Comma-separated tags to filter by (include only)
        layouts_filter: Comma-separated layout names to filter by (include only)
        exclude_categories: Comma-separated category names to exclude
        exclude_tags: Comma-separated tags to exclude
        default_lat: Default latitude for all records (prevents geocoding)
        default_lng: Default longitude for all records (prevents geocoding)
        skip_geocoding: If True, uses St. Croix center coordinates
        use_address_cache: If True, loads addresses from address_cache.json
        filter_bb: If True, filters out Beaver Builder tags from content
        enable_default_address: If True, uses default address when street is empty
        clean_tab_contents: If True, extracts tabs and removes them from content
        include_filter: List of exact post_title values to include (process only these)
        exclude_filter: List of exact post_title values to exclude (skip these)
        entries_per_file: If set, splits output into multiple files with this many entries each
        override_files: List of JSON files with field overrides (keyed by Title + Categories).
                       Acts as a WHITELIST - if provided, only entries with matching overrides are processed.
        pull_tabs: If True, extracts tab data from content to tab fields; if False, leaves tab fields empty
    """
    
    # Check input file exists
    if not Path(input_file).exists():
        print(f"❌ Error: Input file not found: {input_file}")
        sys.exit(1)

    # Parse filters
    category_list = []
    tags_list = []
    layouts_list = []
    exclude_category_list = []
    exclude_tags_list = []
    include_titles = []
    exclude_titles = []

    if category_filter:
        category_list = [c.strip() for c in category_filter.split(',')]

    if tags_filter:
        tags_list = [t.strip() for t in tags_filter.split(',')]

    if layouts_filter:
        layouts_list = [l.strip() for l in layouts_filter.split(',')]

    if exclude_categories:
        exclude_category_list = [c.strip() for c in exclude_categories.split(',')]

    if exclude_tags:
        exclude_tags_list = [t.strip() for t in exclude_tags.split(',')]

    if include_filter:
        include_titles = include_filter  # Already a list from nargs='+'

    if exclude_filter:
        exclude_titles = exclude_filter  # Already a list from nargs='+'

    # Load address cache if requested
    address_cache = {}
    if use_address_cache:
        address_cache = load_address_cache()
        if address_cache:
            print(f"📍 Loaded {len(address_cache)} addresses from cache", file=sys.stderr)
        else:
            print(f"⚠️  Address cache not found or empty", file=sys.stderr)

    # Load override data if provided
    overrides = {}
    if override_files:
        print(f"📝 Loading override files...", file=sys.stderr)
        try:
            overrides = load_override_files(override_files)
            print(f"   Loaded {len(overrides)} override entries from {len(override_files)} file(s)", file=sys.stderr)
        except ValueError as e:
            print(f"❌ Error loading override files: {e}", file=sys.stderr)
            sys.exit(1)
        except FileNotFoundError as e:
            print(f"❌ {e}", file=sys.stderr)
            sys.exit(1)
        except json.JSONDecodeError as e:
            print(f"❌ Error parsing JSON in override file: {e}", file=sys.stderr)
            sys.exit(1)

    # Determine geocoding mode
    # If --lat/--lng provided, use those for all records
    # If --skip-geocoding, use St. Croix center for all records
    # Otherwise, look up coordinates per location
    use_fixed_coords = default_lat or default_lng or skip_geocoding
    fixed_lat = default_lat if default_lat else ('17.7478' if skip_geocoding else '')
    fixed_lng = default_lng if default_lng else ('-64.7059' if skip_geocoding else '')

    # Determine if writing to stdout
    use_stdout = output_file is None or output_file == '-'

    print(f"🔄 Transforming ACF CSV to GeoDirectory format...", file=sys.stderr)
    print(f"   Input:  {input_file}", file=sys.stderr)
    print(f"   Output: {'stdout' if use_stdout else output_file}", file=sys.stderr)
    if test_mode:
        print(f"   Mode:   TEST (first 5 rows only)", file=sys.stderr)
    if include_titles:
        print(f"   Include: Post titles = {', '.join(include_titles)}", file=sys.stderr)
    if exclude_titles:
        print(f"   Exclude: Post titles = {', '.join(exclude_titles)}", file=sys.stderr)
    if category_list:
        print(f"   Filter: Categories = {', '.join(category_list)}", file=sys.stderr)
    if tags_list:
        print(f"   Filter: Tags = {', '.join(tags_list)}", file=sys.stderr)
    if layouts_list:
        print(f"   Filter: Layouts = {', '.join(layouts_list)}", file=sys.stderr)
    if exclude_category_list:
        print(f"   Exclude: Categories = {', '.join(exclude_category_list)}", file=sys.stderr)
    if exclude_tags_list:
        print(f"   Exclude: Tags = {', '.join(exclude_tags_list)}", file=sys.stderr)
    if use_fixed_coords:
        print(f"   Coords: {fixed_lat}, {fixed_lng} (all records)", file=sys.stderr)
    else:
        print(f"   Coords: Location-based lookup (prevents geocoding)", file=sys.stderr)
    if filter_bb:
        print(f"   Content: Beaver Builder tags will be filtered", file=sys.stderr)
    if enable_default_address:
        print(f"   Address: Default '123 King Street' for empty addresses", file=sys.stderr)
    if pull_tabs:
        if clean_tab_contents:
            print(f"   Tabs: Extracting from content (cleaned)", file=sys.stderr)
        else:
            print(f"   Tabs: Extracting from content (preserved)", file=sys.stderr)
    else:
        print(f"   Tabs: Not extracted (tab fields will be empty)", file=sys.stderr)
    if override_files:
        print(f"   Overrides: {len(overrides)} entries from {len(override_files)} file(s) (WHITELIST MODE - only these entries will be processed)", file=sys.stderr)
    print(file=sys.stderr)
    
    with open(input_file, 'r', encoding='utf-8') as infile:
        reader = csv.DictReader(infile)
        
        # GeoDirectory required column order
        fieldnames = [
            'ID', 'post_title', 'post_content', 'post_status', 'post_author',
            'post_type', 'post_date', 'post_modified', 'post_tags', 'post_category',
            'default_category', 'featured', 'street', 'street2', 'city', 'region',
            'country', 'zip', 'latitude', 'longitude', 'location', 'phone',
            'website', 'website_url', 'email_', 'fixed_image', 'spotlight_link',
            'featured_image_alignment', 'layout', 'facebook', 'twitter',
            'instagram', 'pinterest', 'youtube', 'linkedin', 'trip_advisor',
            'yelp', 'other_social_label', 'other_social_url', 'other_social_icon',
            'tab1_name', 'tab1_html', 'tab2_name', 'tab2_html',
            'tab3_name', 'tab3_html', 'tab4_name', 'tab4_html',
            'tab5_name', 'tab5_html', 'youtube_url', 'youtube_urls', 'neighbourhood',
            'post_images'
            
        ]
        
        # Track file splitting if entries_per_file is set
        current_file_num = 1
        entries_in_current_file = 0
        outfile = None
        writer = None
        output_files_created = []

        def open_output_file(file_num):
            """Open a new output file with file number prefix"""
            if use_stdout:
                return sys.stdout, csv.DictWriter(sys.stdout, fieldnames=fieldnames)
            else:
                if entries_per_file:
                    # Add file number prefix: 1-output.csv, 2-output.csv, etc.
                    filename = f"{file_num}-{output_file}"
                else:
                    filename = output_file

                f = open(filename, 'w', encoding='utf-8', newline='')
                w = csv.DictWriter(f, fieldnames=fieldnames)
                w.writeheader()
                output_files_created.append(filename)
                return f, w

        # Open first output file
        outfile, writer = open_output_file(current_file_num)

        try:

            row_count = 0
            processed_count = 0

            # Track unmapped categories/tags for reporting
            unmapped_categories = set()
            unmapped_tags = set()

            for row in reader:
                row_count += 1

                # Test mode - only process first 5 rows
                if test_mode and processed_count >= 5:
                    break

                # Apply post_title include filter
                if include_titles:
                    post_title = row.get('Title', '').strip()
                    if post_title not in include_titles:
                        continue

                # Apply post_title exclude filter
                if exclude_titles:
                    post_title = row.get('Title', '').strip()
                    if post_title in exclude_titles:
                        continue

                # Apply category filter
                if category_list:
                    categories = row.get('Categories', '')
                    match_found = False
                    for cat in category_list:
                        if cat.lower() in categories.lower():
                            match_found = True
                            break
                    if not match_found:
                        continue

                # Apply tags filter
                if tags_list:
                    tags = row.get('Tags', '')
                    match_found = False
                    for tag in tags_list:
                        if tag.lower() in tags.lower():
                            match_found = True
                            break
                    if not match_found:
                        continue

                # Apply layouts filter
                if layouts_list:
                    layout = row.get('acf_template_layout', '')
                    match_found = False
                    for l in layouts_list:
                        if l.lower() in layout.lower():
                            match_found = True
                            break
                    if not match_found:
                        continue

                # Apply category exclusion filter
                if exclude_category_list:
                    categories = row.get('Categories', '')
                    excluded = False
                    for cat in exclude_category_list:
                        if cat.lower() in categories.lower():
                            excluded = True
                            break
                    if excluded:
                        continue

                # Apply tags exclusion filter
                if exclude_tags_list:
                    tags = row.get('Tags', '')
                    excluded = False
                    for tag in exclude_tags_list:
                        if tag.lower() in tags.lower():
                            excluded = True
                            break
                    if excluded:
                        continue

                # Apply override data if available
                # This happens after all filtering but before data extraction
                # so override values are used throughout the transformation
                post_title = row.get('Title', '').strip()
                csv_categories = row.get('Categories', '').strip()

                # If override files were specified, they act as a whitelist
                # Only process entries that have a matching override
                if override_files:
                    override_data = find_matching_override(post_title, csv_categories, overrides)
                    if not override_data:
                        # No override found - skip this entry (Jennie's way of "deleting")
                        continue
                    # Apply the override data
                    row = apply_overrides(row, override_data)
                else:
                    # No override files specified - process all entries normally
                    override_data = None

                # Choose best website value
                website = choose_best_value(
                    row.get('acf_website', ''),
                    row.get('website_url', '')
                )
                website = clean_url(website)
                
                # Choose best featured image and transform to relative path
                featured_image = choose_best_value(
                    row.get('Image URL', ''),
                    row.get('Attachment URL', '')
                )
                featured_image = transform_image_url(featured_image)

                # Get fixed image (skip numeric IDs)
                fixed_image_raw = row.get('acf_fixed_image', '').strip()
                if fixed_image_raw and not fixed_image_raw.isdigit():
                    fixed_image = transform_image_url(clean_url(fixed_image_raw))
                else:
                    fixed_image = ''

                # Get gallery images
                gallery = choose_best_value(
                    row.get('images', ''),
                    row.get('slider', '')
                )
                
                # Get categories and tags
                categories = row.get('Categories', '')
                tags = row.get('Tags', '')

                # Transform categories and tags to IDs
                post_category_ids = transform_categories_to_ids(categories, unmapped_categories)
                post_tags_ids = transform_tags_to_ids(tags, unmapped_tags)
                default_category_id = get_first_category_id(post_category_ids)

                # Get location-based data (coordinates and neighborhood)
                location_name = row.get('acf_location', '').strip()

                if use_fixed_coords:
                    # Use fixed coordinates for all records
                    lat = fixed_lat
                    lng = fixed_lng
                else:
                    # Look up coordinates based on acf_location
                    lat, lng = get_coordinates(location_name)

                    # If location not found or empty, use default St. Croix center
                    if not lat or not lng:
                        lat, lng = get_default_coordinates()

                # Look up neighborhood slug from location name
                neighborhood_slug = get_neighborhood_by_location(location_name, default='')

                # Get street address from cache if available (keyed by business name)
                business_name = row.get('Title', '').strip()
                street_address = address_cache.get(business_name, '')

                # Apply default address if enabled and address is empty
                if enable_default_address and not street_address:
                    street_address = '123 King Street'

                # Get content and extract tabs
                content = row.get('Content', '')

                # Extract tabs from content (only if pull_tabs is enabled)
                tab_fields = {}
                if pull_tabs:
                    tabs, content = parse_tabs(content, clean_out=clean_tab_contents)

                    # Populate tab fields (up to 5 tabs)
                    for i in range(1, 6):  # tab1 through tab5
                        idx = i - 1
                        if idx < len(tabs):
                            tab_fields[f'tab{i}_name'] = tabs[idx]['name']
                            tab_fields[f'tab{i}_html'] = tabs[idx]['contents']
                        else:
                            tab_fields[f'tab{i}_name'] = ''
                            tab_fields[f'tab{i}_html'] = ''
                else:
                    # Leave all tab fields empty
                    for i in range(1, 6):
                        tab_fields[f'tab{i}_name'] = ''
                        tab_fields[f'tab{i}_html'] = ''

                # Then filter Beaver Builder tags if enabled
                if filter_bb:
                    content = filter_beaver_builder_tags(content)

                # Extract JPG URLs and YouTube URLs from content
                jpg_urls_from_content = extract_jpg_urls_from_content(content)
                youtube_urls_from_content = extract_youtube_urls_from_content(content)

                # Combine gallery images with JPG URLs from content
                # Both are already in GeoDirectory format: URL|ID|TITLE|DESCRIPTION::URL|ID|TITLE|DESCRIPTION
                gallery_images = format_images_gallery(gallery)
                if gallery_images and jpg_urls_from_content:
                    # Both formatted, combine with :: separator
                    combined_images = gallery_images + '::' + jpg_urls_from_content
                elif jpg_urls_from_content:
                    combined_images = jpg_urls_from_content
                else:
                    combined_images = gallery_images

                # If no images from gallery/content but featured_image exists, use it
                # Featured image should be first image in GeoDirectory
                if featured_image:
                    # Format featured_image in GeoDirectory format
                    # Handle multiple URLs separated by | (split and format each)
                    featured_urls = [url.strip() for url in featured_image.split('|') if url.strip()]
                    if featured_urls:
                        formatted_featured = '::'.join([f"{url}|||" for url in featured_urls])
                        if combined_images:
                            # Prepend featured image to existing images
                            combined_images = formatted_featured + '::' + combined_images
                        else:
                            # Use featured image as the only image
                            combined_images = formatted_featured

                # Track images for copy script
                if image_script:
                    # Track images from post_images (combined_images)
                    if combined_images:
                        for url in extract_urls_from_geodir_format(combined_images):
                            image_script.add_image(url)

                    # Track featured_image (may contain multiple URLs separated by |)
                    if featured_image:
                        # Split by | in case there are multiple URLs
                        for url in featured_image.split('|'):
                            url = url.strip()
                            if url:
                                image_script.add_image(url)

                    # Track fixed_image (may contain multiple URLs separated by |)
                    if fixed_image:
                        # Split by | in case there are multiple URLs
                        for url in fixed_image.split('|'):
                            url = url.strip()
                            if url:
                                image_script.add_image(url)

                # Build output row (ID will be set later after second-pass overrides)
                output_row = {
                    'ID': row.get('id', ''),
                    'post_title': row.get('Title', ''),
                    'post_content': content,
                    'post_status': row.get('Status', 'publish'),
                    'post_author': row.get('Author ID', '1'),
                    'post_type': 'gd_listing',  # Change if using different GD post type
                    'post_date': format_datetime(row.get('Date', '')),
                    'post_modified': format_datetime(row.get('Post Modified Date', '')),
                    'post_tags': post_tags_ids,
                    'post_category': post_category_ids,
                    'default_category': default_category_id,
                    'featured': '0',  # Change to '1' for featured listings
                    
                    # Location fields (geographic)
                    'street': street_address,
                    'street2': '',
                    'city': '',
                    'neighbourhood': neighborhood_slug,
                    'region': 'United States Virgin Islands',
                    'country': 'United States',
                    'zip': '',
                    'latitude': lat,
                    'longitude': lng,
                    'location': row.get('acf_location', ''),  # Area/neighborhood
                    
                    # Contact fields
                    'phone': format_phone(row.get('acf_phone', '')),
                    'website': website,
                    'website_url': website,
                    'email_': row.get('acf_email', ''),
                    
                    # Display/layout fields
                    'fixed_image': fixed_image,
                    'spotlight_link': clean_url(row.get('acf_spotlight_link', '')),
                    'featured_image_alignment': row.get('image_alignment', ''),
                    'layout': row.get('acf_template_layout', ''),
                    
                    # Social media fields
                    'facebook': transform_social_url('facebook', row.get('acf_facebook', '')),
                    'twitter': transform_social_url('twitter', row.get('acf_twitter', '')),
                    'instagram': transform_social_url('instagram', row.get('acf_instagram', '')),
                    'pinterest': transform_social_url('pinterest', row.get('acf_pinterest', '')),
                    'youtube': transform_social_url('youtube', row.get('acf_you_tube', '')),
                    'linkedin': transform_social_url('linkedin', row.get('acf_linked_in', '')),
                    'trip_advisor': transform_social_url('trip_advisor', row.get('acf_trip_advisor', '')),
                    'yelp': transform_social_url('yelp', row.get('acf_yelp', '')),
                    'other_social_label': row.get('acf_other_social_label', ''),
                    'other_social_url': clean_url(row.get('acf_other_social_url', '')),
                    'other_social_icon': '' if row.get('acf_other_social_icon', '').strip().isdigit() else row.get('acf_other_social_icon', ''),

                    # Tabs (extracted from content)
                    'tab1_name': tab_fields['tab1_name'],
                    'tab1_html': tab_fields['tab1_html'],
                    'tab2_name': tab_fields['tab2_name'],
                    'tab2_html': tab_fields['tab2_html'],
                    'tab3_name': tab_fields['tab3_name'],
                    'tab3_html': tab_fields['tab3_html'],
                    'tab4_name': tab_fields['tab4_name'],
                    'tab4_html': tab_fields['tab4_html'],
                    'tab5_name': tab_fields['tab5_name'],
                    'tab5_html': tab_fields['tab5_html'],

                    # YouTube URLs (extracted from content)
                    'youtube_url': '',  # Single URL field (empty for now, can be populated if needed)
                    'youtube_urls': youtube_urls_from_content,

                    # Image gallery (combined from gallery fields + JPG URLs in content)
                    'post_images': combined_images,
                }

                # Apply second-pass overrides (GeoDirectory fields)
                # This happens after transformation, allowing direct override of final output fields
                output_row = apply_output_overrides(output_row, post_title, csv_categories, overrides)

                # Check if we need to start a new file
                if entries_per_file and entries_in_current_file >= entries_per_file:
                    # Close current file and open new one
                    if not use_stdout and outfile:
                        outfile.close()

                    current_file_num += 1
                    entries_in_current_file = 0
                    outfile, writer = open_output_file(current_file_num)

                writer.writerow(output_row)
                processed_count += 1
                entries_in_current_file += 1
        finally:
            # Close file if it's not stdout
            if not use_stdout and outfile:
                outfile.close()

    print(f"✅ Transformation complete!", file=sys.stderr)
    print(f"   Rows read:      {row_count}", file=sys.stderr)
    if category_list or tags_list or layouts_list:
        print(f"   Rows matched:   {processed_count}", file=sys.stderr)
    else:
        print(f"   Rows processed: {processed_count}", file=sys.stderr)

    # Report unmapped categories and tags
    if unmapped_categories:
        print(f"   ⚠️  Unmapped categories ({len(unmapped_categories)}): {', '.join(sorted(unmapped_categories))}", file=sys.stderr)
    if unmapped_tags:
        print(f"   ⚠️  Unmapped tags ({len(unmapped_tags)}): {', '.join(sorted(unmapped_tags))}", file=sys.stderr)

    if not use_stdout:
        if entries_per_file and len(output_files_created) > 1:
            print(f"   Output files:   {len(output_files_created)} files created", file=sys.stderr)
            for i, filename in enumerate(output_files_created, 1):
                print(f"                   {i}. {filename}", file=sys.stderr)
        else:
            print(f"   Output saved:   {output_file if not entries_per_file else output_files_created[0]}", file=sys.stderr)
        print(file=sys.stderr)
        print("📋 Next steps:", file=sys.stderr)
        print(f"   1. Review output file(s)", file=sys.stderr)
        print(f"   2. Check image URLs are valid", file=sys.stderr)
        print(f"   3. Verify categories/tags look correct", file=sys.stderr)
        print(f"   4. Import to GeoDirectory", file=sys.stderr)
        print(file=sys.stderr)

    if test_mode:
        print("⚠️  TEST MODE: Only first 5 rows were processed", file=sys.stderr)
        print("   Run without --test flag to process all rows", file=sys.stderr)
    
    return True

def display_field_mappings():
    """Display ACF to GeoDirectory field mappings in a table format"""
    print("\n" + "="*80)
    print("ACF TO GEODIRECTORY FIELD MAPPINGS")
    print("="*80)
    print(f"{'ACF Field':<30} {'GD Field':<25} {'Transformation':<25}")
    print("-"*80)

    # Core content fields
    print(f"{'id':<30} {'ID':<25} {'':<25}")
    print(f"{'Title':<30} {'post_title':<25} {'':<25}")
    print(f"{'Content':<30} {'post_content':<25} {'':<25}")
    print(f"{'Status':<30} {'post_status':<25} {'':<25}")
    print(f"{'Author ID':<30} {'post_author':<25} {'Default: 1':<25}")
    print(f"{'(hardcoded)':<30} {'post_type':<25} {'gd_listing':<25}")
    print(f"{'Date':<30} {'post_date':<25} {'':<25}")
    print(f"{'Post Modified Date':<30} {'post_modified':<25} {'':<25}")
    print(f"{'Tags':<30} {'post_tags':<25} {'Text names → IDs':<25}")
    print(f"{'Categories':<30} {'post_category':<25} {'Text names → IDs':<25}")
    print(f"{'Categories':<30} {'default_category':<25} {'First ID from post_category':<25}")
    print(f"{'(hardcoded)':<30} {'featured':<25} {'0 (not featured)':<25}")

    # Location fields
    print(f"\n{'LOCATION FIELDS':<80}")
    print("-"*80)
    print(f"{'post_title (cache lookup)':<30} {'street':<25} {'From address_cache.json':<25}")
    print(f"{'(hardcoded)':<30} {'street2':<25} {'Empty':<25}")
    print(f"{'(hardcoded)':<30} {'city':<25} {'St. Croix':<25}")
    print(f"{'acf_location':<30} {'neighbourhood':<25} {'Location → slug lookup':<25}")
    print(f"{'(hardcoded)':<30} {'region':<25} {'United States Virgin Islands':<25}")
    print(f"{'(hardcoded)':<30} {'country':<25} {'United States':<25}")
    print(f"{'(hardcoded)':<30} {'zip':<25} {'Empty':<25}")
    print(f"{'acf_location':<30} {'latitude':<25} {'Location lookup':<25}")
    print(f"{'acf_location':<30} {'longitude':<25} {'Location lookup':<25}")
    print(f"{'acf_location':<30} {'location':<25} {'Area/neighborhood':<25}")

    # Contact fields
    print(f"\n{'CONTACT FIELDS':<80}")
    print("-"*80)
    print(f"{'acf_phone':<30} {'phone':<25} {'340-555-1234 format':<25}")
    print(f"{'acf_website | website_url':<30} {'website':<25} {'URL cleaned':<25}")
    print(f"{'acf_website | website_url':<30} {'website_url':<25} {'URL cleaned':<25}")
    print(f"{'acf_email':<30} {'email_':<25} {'':<25}")

    # Display/layout fields
    print(f"\n{'DISPLAY/LAYOUT FIELDS':<80}")
    print("-"*80)
    print(f"{'acf_fixed_image':<30} {'fixed_image':<25} {'URL cleaned':<25}")
    print(f"{'acf_spotlight_link':<30} {'spotlight_link':<25} {'URL cleaned':<25}")
    print(f"{'image_alignment':<30} {'featured_image_alignment':<25} {'':<25}")
    print(f"{'acf_template_layout':<30} {'layout':<25} {'':<25}")
    print(f"{'images | slider':<30} {'post_images':<25} {'Pipe-separated':<25}")

    # Social media fields
    print(f"\n{'SOCIAL MEDIA FIELDS':<80}")
    print("-"*80)
    print(f"{'acf_facebook':<30} {'facebook':<25} {'Username → URL':<25}")
    print(f"{'acf_twitter':<30} {'twitter':<25} {'Username → URL':<25}")
    print(f"{'acf_instagram':<30} {'instagram':<25} {'Username → URL':<25}")
    print(f"{'acf_pinterest':<30} {'pinterest':<25} {'Username → URL':<25}")
    print(f"{'acf_you_tube':<30} {'youtube':<25} {'@username → URL':<25}")
    print(f"{'acf_linked_in':<30} {'linkedin':<25} {'Smart URL':<25}")
    print(f"{'acf_trip_advisor':<30} {'trip_advisor':<25} {'Username → URL':<25}")
    print(f"{'acf_yelp':<30} {'yelp':<25} {'Username → URL':<25}")
    print(f"{'acf_other_social_label':<30} {'other_social_label':<25} {'':<25}")
    print(f"{'acf_other_social_url':<30} {'other_social_url':<25} {'URL cleaned':<25}")
    print(f"{'acf_other_social_icon':<30} {'other_social_icon':<25} {'':<25}")

    # Tab extraction (from Beaver Builder content)
    print(f"\n{'TAB EXTRACTION (BEAVER BUILDER)':<80}")
    print("-"*80)
    print(f"{'Extracted from Content':<30} {'tab1_name, tab1_html':<30} {'Up to 5 tabs':<25}")
    print(f"{'--clean-tab-contents flag':<30} {'tab2_name, tab2_html':<30} {'Controls extraction':<25}")
    print(f"{'':<30} {'tab3_name, tab3_html':<30} {'':<25}")
    print(f"{'':<30} {'tab4_name, tab4_html':<30} {'':<25}")
    print(f"{'':<30} {'tab5_name, tab5_html':<30} {'':<25}")

    print("="*80)
    print()

    # Display location coordinates table
    print("\n" + "="*80)
    print("LOCATION COORDINATE DEFAULTS")
    print("="*80)
    print(f"{'Location Name':<40} {'Latitude':<12} {'Longitude':<12}")
    print("-"*80)

    from stcroix_locations import LOCATION_COORDS

    # Sort locations alphabetically
    sorted_locations = sorted(LOCATION_COORDS.items(), key=lambda x: x[0].lower())

    for location, (lat, lng) in sorted_locations:
        print(f"{location.title():<40} {lat:<12} {lng:<12}")

    print("-"*80)
    print(f"{'Default (St. Croix Center)':<40} {'17.7478':<12} {'-64.7059':<12}")
    print("="*80)
    print("\nNote: Coordinates are looked up by acf_location field.")
    print("If location is not found or empty, default St. Croix center is used.")
    print()

    # Display category and tag ID mappings
    print("\n" + "="*80)
    print("CATEGORY AND TAG ID MAPPINGS")
    print("="*80)
    print(f"Mapping file: gd-taxonomy-map.json")
    print(f"Fallback ID for unmapped items: 2184 (Uncategorized)")
    print(f"Output format: Quoted with leading/trailing commas (e.g., ',2041,2042,')\n")

    print("Available Category Mappings:")
    if 'taxonomy' in _MAPPINGS and 'categories' in _MAPPINGS['taxonomy']:
        for name, id_val in sorted(_MAPPINGS['taxonomy']['categories'].items()):
            print(f"  {name:<30} → {id_val}")
    else:
        print("  (No category mappings loaded)")

    print("\nAvailable Tag Mappings:")
    if 'taxonomy' in _MAPPINGS and 'tags' in _MAPPINGS['taxonomy']:
        for name, id_val in sorted(_MAPPINGS['taxonomy']['tags'].items()):
            print(f"  {name:<30} → {id_val}")
    else:
        print("  (No tag mappings - all tags will use their names directly)")

    print("="*80)
    print()

    # Display neighborhood mappings
    print("\n" + "="*80)
    print("NEIGHBORHOOD MAPPINGS")
    print("="*80)
    print(f"Mapping file: neighborhoods.json")
    print(f"Maps ACF location values to GeoDirectory neighborhood slugs")
    print(f"Used to populate the 'neighbourhood' field in output\n")

    print(f"{'ACF Location':<40} {'Neighborhood Slug':<30}")
    print("-"*80)

    if 'neighborhoods' in _MAPPINGS and 'locations' in _MAPPINGS['neighborhoods']:
        locations_map = _MAPPINGS['neighborhoods']['locations']
        # Sort by location name
        for location in sorted(locations_map.keys()):
            slug = locations_map[location]
            # Show empty slugs as "(empty)" for visibility
            display_slug = slug if slug else "(empty)"
            print(f"{location:<40} {display_slug:<30}")

        # Count how many have values vs empty
        total = len(locations_map)
        with_values = sum(1 for v in locations_map.values() if v)
        empty = total - with_values

        print("-"*80)
        print(f"Total locations: {total} ({with_values} mapped, {empty} empty)")
    else:
        print("  (No neighborhood mappings loaded)")

    print("="*80)
    print()

def list_unique_values(input_file, field_name):
    """
    List all unique values from a specific field in the ACF CSV

    Args:
        input_file: Path to ACF export CSV
        field_name: Name of the column to extract values from
    """
    if not Path(input_file).exists():
        print(f"❌ Error: Input file not found: {input_file}")
        sys.exit(1)

    print(f"\n🔍 Extracting unique values from '{field_name}' field...")
    print(f"   Reading from: {input_file}\n")

    unique_values = set()

    with open(input_file, 'r', encoding='utf-8') as infile:
        reader = csv.DictReader(infile)

        if field_name not in reader.fieldnames:
            print(f"❌ Error: Field '{field_name}' not found in CSV")
            print(f"   Available fields: {', '.join(reader.fieldnames)}")
            sys.exit(1)

        for row in reader:
            value = row.get(field_name, '').strip()
            if not value:
                continue

            # Handle comma-separated values
            if ',' in value:
                parts = [v.strip() for v in value.split(',')]
                unique_values.update(parts)
            # Handle pipe-separated values
            elif '|' in value:
                parts = [v.strip() for v in value.split('|')]
                unique_values.update(parts)
            else:
                unique_values.add(value)

    # Remove empty strings
    unique_values.discard('')

    # Sort and display
    sorted_values = sorted(unique_values, key=str.lower)

    print(f"{'='*80}")
    print(f"UNIQUE VALUES IN '{field_name.upper()}' FIELD")
    print(f"{'='*80}")
    print(f"Total unique values: {len(sorted_values)}\n")

    for i, value in enumerate(sorted_values, 1):
        print(f"{i:3}. {value}")

    print(f"\n{'='*80}")
    print()

def main():
    """Main entry point"""
    import argparse

    parser = argparse.ArgumentParser(
        description='Transform ACF export CSV to GeoDirectory import format'
    )
    parser.add_argument(
        '--acf',
        default='acf_export.csv',
        help='Input ACF export CSV file (default: acf_export.csv)'
    )
    parser.add_argument(
        '--out',
        default=None,
        help='Output GeoDirectory import CSV file (default: stdout)'
    )
    parser.add_argument(
        '--test',
        action='store_true',
        help='Test mode: only process first 5 rows'
    )
    parser.add_argument(
        '--mapping',
        action='store_true',
        help='Display field mappings and exit'
    )
    parser.add_argument(
        '--list-categories',
        action='store_true',
        help='List all unique categories from ACF input and exit'
    )
    parser.add_argument(
        '--list-tags',
        action='store_true',
        help='List all unique tags from ACF input and exit'
    )
    parser.add_argument(
        '--list-layouts',
        action='store_true',
        help='List all unique layouts from ACF input and exit'
    )
    parser.add_argument(
        '--list-locations',
        action='store_true',
        help='List all unique locations from ACF input and exit'
    )
    parser.add_argument(
        '--category',
        type=str,
        help='Filter transformation by category/categories (comma-separated)'
    )
    parser.add_argument(
        '--tags',
        type=str,
        help='Filter transformation by tag(s) (comma-separated)'
    )
    parser.add_argument(
        '--layouts',
        type=str,
        help='Filter transformation by layout(s) (comma-separated)'
    )
    parser.add_argument(
        '--exclude-categories',
        type=str,
        help='Exclude listings with these category/categories (comma-separated)'
    )
    parser.add_argument(
        '--exclude-tags',
        type=str,
        help='Exclude listings with these tag(s) (comma-separated)'
    )
    parser.add_argument(
        '--skip-geocoding',
        action='store_true',
        help='Use St. Croix center coordinates (prevents OpenStreetMap geocoding errors)'
    )
    parser.add_argument(
        '--lat',
        type=str,
        help='Default latitude for all records (overrides --skip-geocoding)'
    )
    parser.add_argument(
        '--lng',
        type=str,
        help='Default longitude for all records (overrides --skip-geocoding)'
    )
    parser.add_argument(
        '--use-address-cache',
        action='store_true',
        help='Load street addresses from address_cache.json file'
    )
    parser.add_argument(
        '--filter-bb',
        action='store_true',
        help='Filter out Beaver Builder tags from post content'
    )
    parser.add_argument(
        '--clean-tab-contents',
        action='store_true',
        help='Extract tab names and contents from post content (removes tabs from content when set)'
    )
    parser.add_argument(
        '--enable-default-address',
        action='store_true',
        help='Use default address "123 King Street" when street address is empty'
    )
    parser.add_argument(
        '--source-media',
        type=str,
        default='',
        help='Source media library path (e.g., /var/www/uploads/)'
    )
    parser.add_argument(
        '--dest-media',
        type=str,
        default='',
        help='Destination media folder (e.g., 2026/01/)'
    )
    parser.add_argument(
        '--copy-script',
        type=str,
        default='copy_images.sh',
        help='Output shell script filename (default: copy_images.sh)'
    )
    parser.add_argument(
        '--include',
        nargs='+',
        type=str,
        help='Process only entries with these exact post_title values (can specify multiple)'
    )
    parser.add_argument(
        '--exclude',
        nargs='+',
        type=str,
        help='Exclude entries with these exact post_title values (can specify multiple)'
    )
    parser.add_argument(
        '--entries',
        type=int,
        help='Number of entries per output file (splits output into multiple files: 1-{outfile}.csv, 2-{outfile}.csv, etc.)'
    )
    parser.add_argument(
        '--override-file',
        nargs='+',
        type=str,
        dest='override_files',
        help='JSON override file(s) with edited ACF data (can specify multiple). Files are keyed by Title and Categories. Acts as a WHITELIST - only entries present in the override file will be processed. Supports both ACF field names (acf_phone) and GeoDirectory field names (phone).'
    )
    parser.add_argument(
        '--pull-tabs',
        action='store_true',
        help='Extract tab data from content and populate tab fields (tab1_name, tab1_html, etc.). If not specified, tab fields will be empty.'
    )

    args = parser.parse_args()

    # Validate mutually exclusive options
    if args.include and args.exclude:
        parser.error("--include and --exclude cannot be used together")

    # Load the Categories and Tags mapping first (needed for --mapping display)
    load_name_id_map("gd-taxonomy-map.json")

    # Load the location to neighborhood mapping first (needed for --mapping display)
    load_location_hood_map("neighborhoods.json")

    # Handle info/list flags (mutually exclusive with transformation)
    if args.mapping:
        display_field_mappings()
        sys.exit(0)

    if args.list_categories:
        list_unique_values(args.acf, 'Categories')
        sys.exit(0)

    if args.list_tags:
        list_unique_values(args.acf, 'Tags')
        sys.exit(0)

    if args.list_layouts:
        list_unique_values(args.acf, 'acf_template_layout')
        sys.exit(0)

    if args.list_locations:
        list_unique_values(args.acf, 'acf_location')
        sys.exit(0)

    # Initialize image copy script generator if paths provided
    image_script = None
    if args.source_media and args.dest_media:
        image_script = ImageCopyScriptGenerator(
            args.source_media,
            args.dest_media,
            args.copy_script
        )

    # Perform transformation with optional filtering
    try:
        transform_csv(
            args.acf,
            args.out,
            test_mode=args.test,
            category_filter=args.category,
            tags_filter=args.tags,
            layouts_filter=args.layouts,
            exclude_categories=args.exclude_categories,
            exclude_tags=args.exclude_tags,
            default_lat=args.lat,
            default_lng=args.lng,
            skip_geocoding=args.skip_geocoding,
            use_address_cache=args.use_address_cache,
            filter_bb=args.filter_bb,
            enable_default_address=args.enable_default_address,
            image_script=image_script,
            clean_tab_contents=args.clean_tab_contents,
            include_filter=args.include,
            exclude_filter=args.exclude,
            entries_per_file=args.entries,
            override_files=args.override_files,
            pull_tabs=args.pull_tabs
        )

        # Write copy script if initialized
        if image_script:
            image_script.write_script()
    except Exception as e:
        print(f"❌ Error during transformation: {e}")
        sys.exit(1)

if __name__ == '__main__':
    main()
