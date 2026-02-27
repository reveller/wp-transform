<?php
/**
 * Import Yoast SEO Metadata
 *
 * Reads a JSON file exported by export-yoast-meta.php and applies
 * Yoast SEO meta to matching posts on this site (matched by slug
 * and post type).
 *
 * Usage:
 *   wp eval-file import-yoast-meta.php
 *
 * Options (set as environment variables):
 *   INPUT_FILE=path     Input JSON file (default: yoast-meta-export.json)
 *   DRY_RUN=1           Preview without modifying (default: 0)
 *   POST_TYPE=type      Limit to a single post type (default: all in file)
 *   POST_SLUG=slug      Import a single post by slug
 *   OVERWRITE=1         Overwrite existing Yoast meta (default: 0 — skip if exists)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file import-yoast-meta.php
 *   INPUT_FILE=yoast-staging.json DRY_RUN=1 wp eval-file import-yoast-meta.php
 *   DRY_RUN=1 POST_TYPE=post wp eval-file import-yoast-meta.php
 *   wp eval-file import-yoast-meta.php
 */

$input_file  = getenv('INPUT_FILE') ?: 'yoast-meta-export.json';
$dry_run     = getenv('DRY_RUN') === '1';
$filter_type = getenv('POST_TYPE') ?: '';
$filter_slug = getenv('POST_SLUG') ?: '';
$overwrite   = getenv('OVERWRITE') === '1';

echo "=== Import Yoast SEO Metadata ===\n";
echo "Input file: $input_file\n";
echo "Dry run:    " . ($dry_run ? 'YES' : 'NO') . "\n";
echo "Overwrite:  " . ($overwrite ? 'YES (replace existing)' : 'NO (skip if exists)') . "\n";
if ($filter_type) {
    echo "Post type:  $filter_type\n";
}
if ($filter_slug) {
    echo "Post slug:  $filter_slug\n";
}
echo "\n";

// ============================================================
// Read JSON
// ============================================================
if (!file_exists($input_file)) {
    die("Error: Input file not found: $input_file\n");
}

$data = json_decode(file_get_contents($input_file), true);
if (!is_array($data)) {
    die("Error: Could not parse JSON from $input_file\n");
}

echo "Entries in file: " . count($data) . "\n\n";

// ============================================================
// Build slug → post ID lookup for the dev site
// ============================================================
// Match by slug across ALL post types on the dev site, since
// staging "post" type may now be a GD CPT (gd_place, etc.)
echo "Building slug lookup...\n";

$dev_post_types = get_post_types(['public' => true]);
unset($dev_post_types['attachment']);
if ($filter_type) {
    $dev_post_types = [$filter_type];
}

$slug_lookup = []; // "slug" => [post_id, post_type]

foreach ($dev_post_types as $pt) {
    $posts = get_posts([
        'post_type'   => $pt,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => -1,
    ]);

    foreach ($posts as $post) {
        // If slug collision, first match wins (shouldn't happen in practice)
        if (!isset($slug_lookup[$post->post_name])) {
            $slug_lookup[$post->post_name] = [
                'id'   => $post->ID,
                'type' => $pt,
            ];
        }
    }
}

echo "Cached " . count($slug_lookup) . " posts on this site\n\n";

// Meta keys to skip (values are site-specific and won't transfer correctly)
$skip_meta_keys = [
    '_yoast_wpseo_primary_category',       // category IDs differ between sites
    '_yoast_wpseo_opengraph-image-id',     // attachment IDs differ
    '_yoast_wpseo_twitter-image-id',       // attachment IDs differ
];

// ============================================================
// Import
// ============================================================
$stats = [
    'processed'   => 0,
    'matched'     => 0,
    'not_found'   => 0,
    'updated'     => 0,
    'skipped'     => 0,
    'meta_written' => 0,
    'meta_skipped' => 0,
    'by_type'     => [],
];

foreach ($data as $entry) {
    $slug      = $entry['slug'];
    $post_type = $entry['post_type'];
    $title     = $entry['title'];
    $meta      = $entry['meta'];

    // Apply filters
    if ($filter_slug && $slug !== $filter_slug) {
        continue;
    }

    $stats['processed']++;

    // Find matching post on dev site by slug (any post type)
    if (!isset($slug_lookup[$slug])) {
        $stats['not_found']++;
        continue;
    }

    $dev_post_id   = $slug_lookup[$slug]['id'];
    $dev_post_type = $slug_lookup[$slug]['type'];

    // Apply post type filter against the DEV site post type
    if ($filter_type && $dev_post_type !== $filter_type) {
        continue;
    }

    $stats['matched']++;

    // Log if post type changed between sites
    if ($post_type !== $dev_post_type) {
        $type_note = " ($post_type → $dev_post_type)";
    } else {
        $type_note = '';
    }

    $meta_written = 0;
    $meta_skipped = 0;

    foreach ($meta as $meta_key => $meta_value) {
        // Skip site-specific meta keys (IDs won't match between sites)
        if (in_array($meta_key, $skip_meta_keys)) {
            $meta_skipped++;
            $stats['meta_skipped']++;
            continue;
        }

        // Check if meta already exists on dev site
        $existing = get_post_meta($dev_post_id, $meta_key, true);

        if ($existing !== '' && $existing !== false && !$overwrite) {
            $meta_skipped++;
            $stats['meta_skipped']++;
            continue;
        }

        if (!$dry_run) {
            update_post_meta($dev_post_id, $meta_key, $meta_value);
        }

        $meta_written++;
        $stats['meta_written']++;
    }

    if ($meta_written > 0) {
        $stats['updated']++;

        if (!isset($stats['by_type'][$post_type])) {
            $stats['by_type'][$post_type] = 0;
        }
        $stats['by_type'][$post_type]++;

        $action = $dry_run ? '[dry-run]' : 'Updated';
        echo "$action $dev_post_type: $slug$type_note ($meta_written meta" .
             ($meta_skipped > 0 ? ", $meta_skipped skipped" : '') . ")\n";
    } elseif ($meta_skipped > 0) {
        $stats['skipped']++;
    }
}

// ============================================================
// Report
// ============================================================
echo "\n=== Summary ===\n";
echo "Entries processed: {$stats['processed']}\n";
echo "Matched on site:   {$stats['matched']}\n";
echo "Not found:         {$stats['not_found']}\n";
echo "Posts updated:     {$stats['updated']}\n";
echo "Posts skipped:     {$stats['skipped']} (all meta already exists)\n";
echo "Meta values written: {$stats['meta_written']}\n";
echo "Meta values skipped: {$stats['meta_skipped']} (already exists, no overwrite)\n";

if (!empty($stats['by_type'])) {
    echo "\nBy post type:\n";
    foreach ($stats['by_type'] as $type => $count) {
        echo "  $type: $count\n";
    }
}

if ($stats['not_found'] > 0) {
    echo "\n--- Posts not found on this site (staging slug has no match) ---\n";
    foreach ($data as $entry) {
        if ($filter_slug && $entry['slug'] !== $filter_slug) continue;

        if (!isset($slug_lookup[$entry['slug']])) {
            echo "  {$entry['post_type']}: {$entry['slug']}\n";
        }
    }
}
