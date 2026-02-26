<?php
/**
 * Import Featured Images for Blog Posts
 *
 * Reads a transformed CSV, matches rows to dev-site posts by slug,
 * downloads the Image Featured URL, creates a WP attachment, and
 * sets _thumbnail_id.
 *
 * Usage:
 *   wp eval-file import-featured-images.php
 *
 * Options (set as environment variables):
 *   CSV_FILE=path       Path to transformed CSV (default: Post-First-Five.csv)
 *   DRY_RUN=1           Preview without downloading/attaching (default: 0)
 *   POST_SLUG=slug      Process a single post by slug
 *   SKIP_EXISTING=1     Skip posts that already have a featured image (default: 1)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file import-featured-images.php
 *   CSV_FILE=Posts-Staging-20260225-transformed.csv wp eval-file import-featured-images.php
 *   POST_SLUG=day-tripping-to-buck-island DRY_RUN=1 wp eval-file import-featured-images.php
 */

// ============================================================
// WordPress bootstrap
// ============================================================
if (!defined('ABSPATH')) {
    $wp_load_paths = [
        __DIR__ . '/wp-load.php',
        __DIR__ . '/../wp-load.php',
        __DIR__ . '/../../wp-load.php',
    ];

    $loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }

    if (!$loaded) {
        die("Error: Could not find wp-load.php\n");
    }
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ============================================================
// Configuration
// ============================================================
$csv_file      = getenv('CSV_FILE') ?: 'Post-First-Five.csv';
$dry_run       = getenv('DRY_RUN') === '1';
$filter_slug   = getenv('POST_SLUG') ?: '';
$skip_existing = getenv('SKIP_EXISTING') !== '0'; // default true
$post_type     = getenv('POST_TYPE') ?: 'post';

echo "=== Import Featured Images ===\n";
echo "CSV file:      $csv_file\n";
echo "Post type:     $post_type\n";
echo "Dry run:       " . ($dry_run ? 'YES' : 'NO') . "\n";
echo "Skip existing: " . ($skip_existing ? 'YES' : 'NO') . "\n";
if ($filter_slug) {
    echo "Filter slug:   $filter_slug\n";
}
echo "\n";

// ============================================================
// Read CSV
// ============================================================
if (!file_exists($csv_file)) {
    die("Error: CSV file not found: $csv_file\n");
}

$handle = fopen($csv_file, 'r');
if (!$handle) {
    die("Error: Could not open CSV file: $csv_file\n");
}

// Read header — handle BOM
$header = fgetcsv($handle);
if ($header === false) {
    die("Error: Could not read CSV header\n");
}
$header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);

$rows = [];
while (($data = fgetcsv($handle)) !== false) {
    if (count($data) === count($header)) {
        $rows[] = array_combine($header, $data);
    }
}
fclose($handle);

echo "CSV rows loaded: " . count($rows) . "\n\n";

// ============================================================
// Process rows
// ============================================================
$stats = [
    'processed'    => 0,
    'skipped_no_image' => 0,
    'skipped_no_post'  => 0,
    'skipped_existing' => 0,
    'downloaded'   => 0,
    'errors'       => 0,
];

foreach ($rows as $row) {
    $slug           = trim($row['Slug'] ?? '');
    $image_url      = trim($row['Image Featured'] ?? '');
    $title          = trim($row['Title'] ?? '');

    // Filter by slug if specified
    if ($filter_slug && $slug !== $filter_slug) {
        continue;
    }

    $stats['processed']++;

    // Skip rows without a featured image URL
    if (empty($image_url)) {
        echo "  SKIP (no image): $title\n";
        $stats['skipped_no_image']++;
        continue;
    }

    // Find the post on the dev site by slug
    $posts = get_posts([
        'name'        => $slug,
        'post_type'   => $post_type,
        'post_status' => 'any',
        'numberposts' => 1,
    ]);

    if (empty($posts)) {
        echo "  SKIP (post not found): $title [slug: $slug]\n";
        $stats['skipped_no_post']++;
        continue;
    }

    $post = $posts[0];

    // Check if post already has a featured image
    if ($skip_existing && has_post_thumbnail($post->ID)) {
        $existing_id = get_post_thumbnail_id($post->ID);
        echo "  SKIP (has thumbnail #$existing_id): $title\n";
        $stats['skipped_existing']++;
        continue;
    }

    echo "  POST #{$post->ID}: $title\n";
    echo "    Image URL: $image_url\n";

    if ($dry_run) {
        echo "    [dry-run] Would download and attach\n";
        $stats['downloaded']++;
        continue;
    }

    // Download image and create attachment
    $tmp = download_url($image_url, 30);
    if (is_wp_error($tmp)) {
        echo "    ERROR downloading: " . $tmp->get_error_message() . "\n";
        $stats['errors']++;
        continue;
    }

    $file_array = [
        'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    // Sideload into media library, attached to the post
    $attachment_id = media_handle_sideload($file_array, $post->ID);

    if (is_wp_error($attachment_id)) {
        echo "    ERROR sideloading: " . $attachment_id->get_error_message() . "\n";
        @unlink($tmp);
        $stats['errors']++;
        continue;
    }

    // Set as featured image
    set_post_thumbnail($post->ID, $attachment_id);

    echo "    Attached #$attachment_id, set as featured image\n";
    $stats['downloaded']++;
}

// ============================================================
// Report
// ============================================================
echo "\n=== Summary ===\n";
echo "Rows processed:      {$stats['processed']}\n";
echo "Downloaded/attached: {$stats['downloaded']}\n";
echo "Skipped (no image):  {$stats['skipped_no_image']}\n";
echo "Skipped (no post):   {$stats['skipped_no_post']}\n";
echo "Skipped (existing):  {$stats['skipped_existing']}\n";
echo "Errors:              {$stats['errors']}\n";
