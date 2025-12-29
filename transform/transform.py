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

import json
from pathlib import Path
from typing import Dict, Any

# Module-level cache (loaded once)
_NAME_ID_MAP: Dict[str, Any] | None = None


def load_name_id_map(json_path: str | Path) -> None:
    """
    Loads the JSON mapping file into memory.
    Call this once at startup.
    """
    global _NAME_ID_MAP

    if _NAME_ID_MAP is not None:
        return  # Already loaded

    json_path = Path(json_path)

    if not json_path.exists():
        raise FileNotFoundError(f"Mapping file not found: {json_path}")

    with json_path.open("r", encoding="utf-8") as f:
        _NAME_ID_MAP = json.load(f)


def get_id_by_name(name: str, map_type: str = "categories") -> int:
    """
    Returns the ID for a given name from the specified map type.
    Requires load_name_id_map() to have been called once.
    """
    if _NAME_ID_MAP is None:
        raise RuntimeError(
            "Name-ID map not loaded. Call load_name_id_map() first."
        )

    if map_type not in _NAME_ID_MAP:
        raise KeyError(f"Map type '{map_type}' not found")

    try:
        return _NAME_ID_MAP[map_type][name]
    except KeyError:
        raise KeyError(
            f"Name '{name}' not found in '{map_type}' mapping"
        )

def transform_names_to_ids(text, map_type='categories', separators='|,', fallback_id=2040, unmapped_tracker=None):
    """
    Transform category/tag names to GeoDirectory ID format.

    Args:
        text: Text containing category/tag names (pipe or comma separated)
        map_type: 'categories' or 'tags' for lookup
        separators: String of valid separator characters
        fallback_id: ID to use for unmapped names (default: 2040 Uncategorized)
        unmapped_tracker: Optional set to track unmapped names

    Returns:
        Formatted ID string: ",2041,2042," or "" if no valid IDs

    Example:
        >>> transform_names_to_ids("Play,Eat", "categories")
        ",2041,2043,"
        >>> transform_names_to_ids("Unknown", "categories")
        ",2040,"
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


def transform_categories_to_ids(categories_text, unmapped_tracker=None):
    """
    Transform category names to GeoDirectory category IDs.

    Args:
        categories_text: Category names (comma or pipe-separated)
        unmapped_tracker: Optional set to collect unmapped category names

    Returns:
        Formatted ID string like ",2041,2043,"
    """
    return transform_names_to_ids(categories_text, 'categories', '|,', 2040, unmapped_tracker)


def transform_tags_to_ids(tags_text, unmapped_tracker=None):
    """
    Transform tag names to GeoDirectory tag IDs.

    Args:
        tags_text: Tag names (pipe-separated)
        unmapped_tracker: Optional set to collect unmapped tag names

    Returns:
        Formatted ID string like ",2050,2051," or empty string if no tag mappings exist
    """
    # Check if any tag mappings exist
    if not _NAME_ID_MAP or 'tags' not in _NAME_ID_MAP or not _NAME_ID_MAP['tags']:
        # No tag mappings defined - return empty string
        return ''

    return transform_names_to_ids(tags_text, 'tags', '|', 2040, unmapped_tracker)


def get_first_category_id(post_category_ids):
    """
    Extract first category ID from formatted ID string.

    Args:
        post_category_ids: Formatted ID string like ",2041,2042," or ""

    Returns:
        First category ID as string, or "2040" if empty/invalid

    Example:
        >>> get_first_category_id(",2041,2043,")
        "2041"
        >>> get_first_category_id("")
        "2040"
    """
    if not post_category_ids or post_category_ids.strip() == '':
        return '2040'  # Uncategorized fallback

    # Extract IDs from ",2041,2042," format
    # Strip leading/trailing commas and split
    ids = post_category_ids.strip(',').split(',')

    # Get first valid ID
    for id_str in ids:
        id_str = id_str.strip()
        if id_str and id_str.isdigit():
            return id_str

    # Fallback if no valid IDs found
    return '2040'


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
    # Single image URL
    elif images_field.strip():
        urls = [images_field.strip()]

    if not urls:
        return ''

    # Format as GeoDirectory expects: URL|ID|TITLE|DESCRIPTION
    # Leave ID, TITLE, DESCRIPTION empty for new imports
    formatted_images = ['|'.join([url, '', '', '']) for url in urls]

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

    # Format as GeoDirectory expects: URL|ID|TITLE|DESCRIPTION
    # Leave ID, TITLE, DESCRIPTION empty for new imports
    formatted_images = ['|'.join([url, '', '', '']) for url in unique_urls]

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
                  layouts_filter=None, default_lat=None, default_lng=None, skip_geocoding=False,
                  use_address_cache=False, filter_bb=False, enable_default_address=False):
    """
    Transform ACF CSV to GeoDirectory format

    Args:
        input_file: Path to ACF export CSV
        output_file: Path for GeoDirectory import CSV
        test_mode: If True, only process first 5 rows
        category_filter: Comma-separated category names to filter by
        tags_filter: Comma-separated tags to filter by
        layouts_filter: Comma-separated layout names to filter by
        default_lat: Default latitude for all records (prevents geocoding)
        default_lng: Default longitude for all records (prevents geocoding)
        skip_geocoding: If True, uses St. Croix center coordinates
        use_address_cache: If True, loads addresses from address_cache.json
        filter_bb: If True, filters out Beaver Builder tags from content
        enable_default_address: If True, uses default address when street is empty
    """
    
    # Check input file exists
    if not Path(input_file).exists():
        print(f"❌ Error: Input file not found: {input_file}")
        sys.exit(1)

    # Parse filters
    category_list = []
    tags_list = []
    layouts_list = []

    if category_filter:
        category_list = [c.strip() for c in category_filter.split(',')]

    if tags_filter:
        tags_list = [t.strip() for t in tags_filter.split(',')]

    if layouts_filter:
        layouts_list = [l.strip() for l in layouts_filter.split(',')]

    # Load address cache if requested
    address_cache = {}
    if use_address_cache:
        address_cache = load_address_cache()
        if address_cache:
            print(f"📍 Loaded {len(address_cache)} addresses from cache", file=sys.stderr)
        else:
            print(f"⚠️  Address cache not found or empty", file=sys.stderr)

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
    if category_list:
        print(f"   Filter: Categories = {', '.join(category_list)}", file=sys.stderr)
    if tags_list:
        print(f"   Filter: Tags = {', '.join(tags_list)}", file=sys.stderr)
    if layouts_list:
        print(f"   Filter: Layouts = {', '.join(layouts_list)}", file=sys.stderr)
    if use_fixed_coords:
        print(f"   Coords: {fixed_lat}, {fixed_lng} (all records)", file=sys.stderr)
    else:
        print(f"   Coords: Location-based lookup (prevents geocoding)", file=sys.stderr)
    if filter_bb:
        print(f"   Content: Beaver Builder tags will be filtered", file=sys.stderr)
    if enable_default_address:
        print(f"   Address: Default '123 King Street' for empty addresses", file=sys.stderr)
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
            'enable_post_tabs', 'tab_1_description', 'youtube_url', 'youtube_urls', 'post_images'
        ]
        
        # Open output file or use stdout
        if use_stdout:
            outfile = sys.stdout
            writer = csv.DictWriter(outfile, fieldnames=fieldnames)
            writer.writeheader()
        else:
            outfile = open(output_file, 'w', encoding='utf-8', newline='')
            writer = csv.DictWriter(outfile, fieldnames=fieldnames)
            writer.writeheader()

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

                # Choose best website value
                website = choose_best_value(
                    row.get('acf_website', ''),
                    row.get('website_url', '')
                )
                website = clean_url(website)
                
                # Choose best featured image
                featured_image = choose_best_value(
                    row.get('Image URL', ''),
                    row.get('Attachment URL', '')
                )
                
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

                # Get coordinates for this location
                if use_fixed_coords:
                    # Use fixed coordinates for all records
                    lat = fixed_lat
                    lng = fixed_lng
                else:
                    # Look up coordinates based on acf_location
                    location_name = row.get('acf_location', '').strip()
                    lat, lng = get_coordinates(location_name)

                    # If location not found or empty, use default St. Croix center
                    if not lat or not lng:
                        lat, lng = get_default_coordinates()

                # Get street address from cache if available (keyed by business name)
                business_name = row.get('Title', '').strip()
                street_address = address_cache.get(business_name, '')

                # Apply default address if enabled and address is empty
                if enable_default_address and not street_address:
                    street_address = '123 King Street'

                # Get content and filter Beaver Builder tags if enabled
                content = row.get('Content', '')
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

                # Build output row
                output_row = {
                    'ID': row.get('id', ''),
                    'post_title': row.get('Title', ''),
                    'post_content': content,
                    'post_status': row.get('Status', 'publish'),
                    'post_author': row.get('Author ID', '1'),
                    'post_type': 'gd_listing_new',  # Change if using different GD post type
                    'post_date': row.get('Date', ''),
                    'post_modified': row.get('Post Modified Date', ''),
                    'post_tags': post_tags_ids,
                    'post_category': post_category_ids,
                    'default_category': default_category_id,
                    'featured': '0',  # Change to '1' for featured listings
                    
                    # Location fields (geographic)
                    'street': street_address,
                    'street2': '',
                    'city': 'St. Croix',
                    'region': 'VI',
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
                    'fixed_image': clean_url(row.get('acf_fixed_image', '')),
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
                    'other_social_icon': row.get('acf_other_social_icon', ''),
                    
                    # Tabs
                    'enable_post_tabs': '1' if row.get('acf_tabs_filter') else '0',
                    'tab_1_description': row.get('acf_tab_1_name', ''),

                    # YouTube URLs (extracted from content)
                    'youtube_url': '',  # Single URL field (empty for now, can be populated if needed)
                    'youtube_urls': youtube_urls_from_content,

                    # Image gallery (combined from gallery fields + JPG URLs in content)
                    'post_images': combined_images,
                }

                writer.writerow(output_row)
                processed_count += 1
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
        print(f"   Output saved:   {output_file}", file=sys.stderr)
        print(file=sys.stderr)
        print("📋 Next steps:", file=sys.stderr)
        print(f"   1. Review {output_file}", file=sys.stderr)
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
    print(f"{'(hardcoded)':<30} {'post_type':<25} {'gd_listing_new':<25}")
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
    print(f"{'(hardcoded)':<30} {'region':<25} {'VI':<25}")
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

    # Tab configuration
    print(f"\n{'TAB CONFIGURATION':<80}")
    print("-"*80)
    print(f"{'acf_tabs_filter':<30} {'enable_post_tabs':<25} {'1 if set, else 0':<25}")
    print(f"{'acf_tab_1_name':<30} {'tab_1_description':<25} {'':<25}")

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
    print(f"Mapping file: categories_and_tags.json")
    print(f"Fallback ID for unmapped items: 2040 (Uncategorized)")
    print(f"Output format: Quoted with leading/trailing commas (e.g., ',2041,2042,')\n")

    print("Available Category Mappings:")
    if _NAME_ID_MAP and 'categories' in _NAME_ID_MAP:
        for name, id_val in sorted(_NAME_ID_MAP['categories'].items()):
            print(f"  {name:<30} → {id_val}")
    else:
        print("  (No category mappings loaded)")

    print("\nAvailable Tag Mappings:")
    if _NAME_ID_MAP and 'tags' in _NAME_ID_MAP and _NAME_ID_MAP['tags']:
        for name, id_val in sorted(_NAME_ID_MAP['tags'].items()):
            print(f"  {name:<30} → {id_val}")
    else:
        print("  (No tag mappings - all tags will use Uncategorized ID 2040)")

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
        '--enable-default-address',
        action='store_true',
        help='Use default address "123 King Street" when street address is empty'
    )

    args = parser.parse_args()

    # Load the Categories and Tags mapping first (needed for --mapping display)
    load_name_id_map("categories_and_tags.json")

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

    # Perform transformation with optional filtering
    try:
        transform_csv(
            args.acf,
            args.out,
            test_mode=args.test,
            category_filter=args.category,
            tags_filter=args.tags,
            layouts_filter=args.layouts,
            default_lat=args.lat,
            default_lng=args.lng,
            skip_geocoding=args.skip_geocoding,
            use_address_cache=args.use_address_cache,
            filter_bb=args.filter_bb,
            enable_default_address=args.enable_default_address
        )
    except Exception as e:
        print(f"❌ Error during transformation: {e}")
        sys.exit(1)

if __name__ == '__main__':
    main()
