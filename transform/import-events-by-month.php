<?php
/**
 * Import Events by Month as Pages (All-in-One)
 *
 * Reads Event_By_Month_FINAL.json and creates WordPress pages for each month.
 * In a single pass:
 *   1. Cleans content (shortcodes, stray tags)
 *   2. Downloads any images from staging/live sites, registers in Media Library
 *   3. Rewrites all staging/dev/live URLs to relative paths
 *   4. Creates the page
 *   5. Imports Yoast SEO metadata (if export file is available)
 *
 * Usage:
 *   wp eval-file import-events-by-month.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1           Preview without creating pages (default: 0)
 *   INPUT_FILE=path     Input JSON file (default: Event_By_Month_FINAL.json)
 *   YOAST_FILE=path     Yoast export JSON (default: yoast-meta-export.json)
 *   PARENT_SLUG=slug    Set a parent page by slug (default: none)
 *   STATUS=publish      Post status (default: publish)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file import-events-by-month.php
 *   PARENT_SLUG=events-by-month wp eval-file import-events-by-month.php
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ============================================================
// Configuration
// ============================================================
$dry_run     = getenv('DRY_RUN') === '1';
$input_file  = getenv('INPUT_FILE') ?: 'Event_By_Month_FINAL.json';
$yoast_file  = getenv('YOAST_FILE') ?: 'yoast-meta-export.json';
$parent_slug = getenv('PARENT_SLUG') ?: '';
$status      = getenv('STATUS') ?: 'publish';

// Hostnames to scrub
$scrub_hosts = [
    'staging-gotostcroix.wordkeeper.net',
    'gotostcroix-dev.wordkeeper.net',
    'www.gotostcroix.com',
    'gotostcroix.com',
];

// Hosts that need image downloads (not on dev disk)
$download_hosts = [
    'staging-gotostcroix.wordkeeper.net',
    'www.gotostcroix.com',
    'gotostcroix.com',
];

// Hosts where images are already on dev disk
$local_hosts = [
    'gotostcroix-dev.wordkeeper.net',
];

// Yoast meta keys to skip (site-specific IDs)
$skip_yoast_keys = [
    '_yoast_wpseo_primary_category',
    '_yoast_wpseo_opengraph-image-id',
    '_yoast_wpseo_twitter-image-id',
];

echo "=== Import Events by Month as Pages ===\n";
echo "Input file:  $input_file\n";
echo "Yoast file:  $yoast_file\n";
echo "Dry run:     " . ($dry_run ? 'YES' : 'NO') . "\n";
echo "Post status: $status\n";
if ($parent_slug) {
    echo "Parent slug: $parent_slug\n";
}
echo "\n";

// ============================================================
// Read input JSON
// ============================================================
if (!file_exists($input_file)) {
    die("Error: Input file not found: $input_file\n");
}

$data = json_decode(file_get_contents($input_file), true);
if (!is_array($data)) {
    die("Error: Could not parse JSON from $input_file\n");
}

echo "Entries in file: " . count($data) . "\n";

// ============================================================
// Load Yoast export (optional)
// ============================================================
$yoast_lookup = []; // slug => [meta_key => value]

if (file_exists($yoast_file)) {
    $yoast_data = json_decode(file_get_contents($yoast_file), true);
    if (is_array($yoast_data)) {
        foreach ($yoast_data as $entry) {
            $yoast_lookup[$entry['slug']] = $entry['meta'] ?? [];
        }
        echo "Yoast entries loaded: " . count($yoast_lookup) . "\n";
    }
} else {
    echo "Yoast file not found — skipping Yoast import\n";
}

echo "\n";

// ============================================================
// Resolve parent page
// ============================================================
$parent_id = 0;
if ($parent_slug) {
    $parent = get_page_by_path($parent_slug);
    if ($parent) {
        $parent_id = $parent->ID;
        echo "Parent page: #{$parent_id} ({$parent->post_title})\n\n";
    } else {
        echo "WARNING: Parent slug '$parent_slug' not found. Pages will have no parent.\n\n";
    }
}

// ============================================================
// Helpers
// ============================================================
$upload_dir     = wp_get_upload_dir();
$download_cache = [];

function clean_content($text) {
    // Strip shortcodes: [associated-posts], [display-posts ...], etc.
    $text = preg_replace('/\[\/?\w+[^\]]*\]/', '', $text);

    // Strip stray closing </p> tags at the end
    $text = preg_replace('#</p>\s*$#', '', $text);

    // Trim whitespace
    $text = trim($text);

    return $text;
}

function strip_size_suffix($path) {
    return preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $path);
}

function scrub_and_download_images($content, $post_id, $dry_run) {
    global $upload_dir, $download_cache;

    $scrub_hosts = [
        'staging-gotostcroix.wordkeeper.net',
        'gotostcroix-dev.wordkeeper.net',
        'www.gotostcroix.com',
        'gotostcroix.com',
    ];

    $local_hosts = [
        'gotostcroix-dev.wordkeeper.net',
    ];

    $host_alts = implode('|', array_map(function ($h) {
        return preg_quote($h, '#');
    }, $scrub_hosts));

    $pattern = '#https?://(' . $host_alts . ')(/[^\s"\'<>);]*)?#i';

    $matches = [];
    if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        return ['content' => $content, 'images' => 0, 'links' => 0, 'downloaded' => 0, 'errors' => 0];
    }

    $stats = ['images' => 0, 'links' => 0, 'downloaded' => 0, 'errors' => 0];
    $seen = [];

    foreach ($matches as $m) {
        $full_url = $m[0];
        $hostname = $m[1];
        $path     = $m[2] ?? '/';

        if (empty($path)) $path = '/';
        if (isset($seen[$full_url])) continue;
        $seen[$full_url] = true;

        // Is this an image upload URL?
        $is_image = (bool) preg_match('#^/wp-content/uploads/.+\.(jpe?g|png|gif|webp|svg|bmp|ico)$#i', $path);

        if ($is_image) {
            $stats['images']++;

            if (in_array($hostname, $local_hosts)) {
                // Already on disk — just strip hostname
                $new_url = $path;
                echo "    IMG (local): " . basename($path) . "\n";
            } else {
                // Need to download
                $original_path = strip_size_suffix($path);
                $original_url  = 'https://' . $hostname . $original_path;

                echo "    IMG (download): " . basename($path) . " from $hostname\n";

                if (isset($download_cache[$original_url])) {
                    $local_relative = $download_cache[$original_url];
                    echo "      CACHED\n";
                } elseif ($dry_run) {
                    $local_relative = '/wp-content/uploads/DRYRUN/' . basename($original_path);
                    $download_cache[$original_url] = $local_relative;
                } else {
                    $tmp = download_url($original_url, 30);
                    if (is_wp_error($tmp)) {
                        // Try fallback host
                        $fallback_url = 'https://www.gotostcroix.com' . $original_path;
                        $tmp = download_url($fallback_url, 30);
                        if (is_wp_error($tmp)) {
                            echo "      ERROR: " . $tmp->get_error_message() . "\n";
                            $stats['errors']++;
                            continue;
                        }
                    }

                    $file_array = [
                        'name'     => basename(parse_url($original_url, PHP_URL_PATH)),
                        'tmp_name' => $tmp,
                    ];

                    $attachment_id = media_handle_sideload($file_array, $post_id);
                    if (is_wp_error($attachment_id)) {
                        echo "      ERROR sideloading: " . $attachment_id->get_error_message() . "\n";
                        @unlink($tmp);
                        $stats['errors']++;
                        continue;
                    }

                    $attached_file  = get_post_meta($attachment_id, '_wp_attached_file', true);
                    $local_relative = '/wp-content/uploads/' . $attached_file;
                    $download_cache[$original_url] = $local_relative;
                    $stats['downloaded']++;

                    echo "      Registered media #$attachment_id\n";
                }

                // Build sized variant path if needed
                if (preg_match('/-(\d+x\d+)\.[a-z]{3,4}$/i', $path, $sm)) {
                    $local_base     = preg_replace('/(\.[a-z]{3,4})$/i', '', $local_relative);
                    $local_ext      = pathinfo($local_relative, PATHINFO_EXTENSION);
                    $sized_relative = "{$local_base}-{$sm[1]}.{$local_ext}";

                    if (!$dry_run) {
                        $sized_disk = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $sized_relative);
                        $new_url = file_exists($sized_disk) ? $sized_relative : $local_relative;
                    } else {
                        $new_url = $sized_relative;
                    }
                } else {
                    $new_url = $local_relative;
                }
            }
        } else {
            // Non-image URL — just strip hostname
            $new_url = $path;
            $stats['links']++;
            echo "    LINK: $full_url => $new_url\n";
        }

        $content = str_replace($full_url, $new_url, $content);
    }

    return array_merge(['content' => $content], $stats);
}

// ============================================================
// Create pages
// ============================================================
$totals = [
    'created'    => 0,
    'skipped'    => 0,
    'errors'     => 0,
    'images'     => 0,
    'downloaded' => 0,
    'links'      => 0,
    'yoast'      => 0,
];

foreach ($data as $entry) {
    $title   = trim($entry['Title']);
    $content = clean_content($entry['acf_description'] ?? '');
    $tags    = $entry['Tags'] ?? '';

    // Generate slug: "january", "february", etc.
    $slug = sanitize_title($title);

    echo "$title (slug: $slug)\n";

    if (empty($content)) {
        echo "  SKIP: No content\n\n";
        $totals['skipped']++;
        continue;
    }

    // Check if page already exists
    $existing = get_page_by_path($slug);
    if (!$existing && $parent_slug) {
        $existing = get_page_by_path($parent_slug . '/' . $slug);
    }

    if ($existing) {
        echo "  SKIP: Page already exists (#{$existing->ID})\n\n";
        $totals['skipped']++;
        continue;
    }

    // Parse tags
    $tag_list = array_filter(array_map('trim', explode('|', $tags)));

    // --- Step 1: Scrub URLs and download images ---
    $scrub_result = scrub_and_download_images($content, 0, $dry_run);
    $content = $scrub_result['content'];
    $totals['images']     += $scrub_result['images'];
    $totals['downloaded'] += $scrub_result['downloaded'];
    $totals['links']      += $scrub_result['links'];

    echo "  Content: " . strlen($content) . " chars\n";
    if (!empty($tag_list)) {
        echo "  Tags: " . implode(', ', $tag_list) . "\n";
    }

    if ($dry_run) {
        // Check Yoast
        if (isset($yoast_lookup[$slug]) && !empty($yoast_lookup[$slug])) {
            $yoast_count = count(array_diff_key($yoast_lookup[$slug], array_flip($skip_yoast_keys)));
            echo "  Yoast: $yoast_count meta values to import\n";
            $totals['yoast'] += $yoast_count;
        }
        echo "  [dry-run] Would create page\n\n";
        $totals['created']++;
        continue;
    }

    // --- Step 2: Create the page ---
    $post_data = [
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => $status,
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
        'post_author'  => 1,
    ];

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
        echo "  ERROR: " . $post_id->get_error_message() . "\n\n";
        $totals['errors']++;
        continue;
    }

    echo "  Created page #{$post_id}\n";

    // Store tags as post meta (pages don't have native tags)
    if (!empty($tag_list)) {
        update_post_meta($post_id, '_events_by_month_tags', implode(', ', $tag_list));
    }

    // --- Step 3: Import Yoast metadata ---
    if (isset($yoast_lookup[$slug]) && !empty($yoast_lookup[$slug])) {
        $yoast_count = 0;
        foreach ($yoast_lookup[$slug] as $meta_key => $meta_value) {
            if (in_array($meta_key, $skip_yoast_keys)) continue;
            update_post_meta($post_id, $meta_key, $meta_value);
            $yoast_count++;
        }
        $totals['yoast'] += $yoast_count;
        echo "  Yoast: $yoast_count meta values imported\n";
    } else {
        echo "  Yoast: no data found for this slug\n";
    }

    echo "\n";
    $totals['created']++;
}

// ============================================================
// Report
// ============================================================
echo "=== Summary ===\n";
echo "Pages created:     {$totals['created']}\n";
echo "Pages skipped:     {$totals['skipped']}\n";
echo "Errors:            {$totals['errors']}\n";
echo "Images found:      {$totals['images']}\n";
echo "Images downloaded: {$totals['downloaded']}\n";
echo "Links rewritten:   {$totals['links']}\n";
echo "Yoast meta values: {$totals['yoast']}\n";
