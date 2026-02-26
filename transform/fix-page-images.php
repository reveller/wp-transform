<?php
/**
 * Fix Page Content Images
 *
 * Rewrites image src URLs in page content:
 *   1. gotostcroix-dev.wordkeeper.net URLs → relative paths (already on disk)
 *   2. www.gotostcroix.com/wp-content/uploads URLs → download + relative path
 *
 * Usage:
 *   wp eval-file fix-page-images.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1           Preview without modifying (default: 0)
 *   POST_SLUG=slug      Process a single page by slug
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file fix-page-images.php
 *   POST_SLUG=about-us DRY_RUN=1 wp eval-file fix-page-images.php
 *   wp eval-file fix-page-images.php
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
$dry_run     = getenv('DRY_RUN') === '1';
$filter_slug = getenv('POST_SLUG') ?: '';

$dev_host  = 'gotostcroix-dev.wordkeeper.net';
$live_host = 'www.gotostcroix.com';

echo "=== Fix Page Content Images ===\n";
echo "Dry run:       " . ($dry_run ? 'YES' : 'NO') . "\n";
if ($filter_slug) {
    echo "Filter slug:   $filter_slug\n";
}
echo "\n";

// ============================================================
// Get all published pages
// ============================================================
$args = [
    'post_type'   => 'page',
    'post_status' => 'publish',
    'numberposts' => -1,
];

if ($filter_slug) {
    $args['name'] = $filter_slug;
}

$pages = get_posts($args);
echo "Pages to scan: " . count($pages) . "\n\n";

// ============================================================
// Process
// ============================================================
$stats = [
    'pages_scanned'    => 0,
    'pages_updated'    => 0,
    'dev_rewritten'    => 0,
    'live_downloaded'  => 0,
    'live_rewritten'   => 0,
    'already_relative' => 0,
    'errors'           => 0,
];

// Cache: live URL => local relative path (avoid re-downloading)
$download_cache = [];

$upload_dir = wp_get_upload_dir();

foreach ($pages as $page) {
    $content = $page->post_content;
    $stats['pages_scanned']++;

    // Find all image src attributes
    $dev_pattern  = '#https?://' . preg_quote($dev_host, '#') . '(/wp-content/uploads/[^\s"\'<>);]+)#i';
    $live_pattern = '#https?://' . preg_quote($live_host, '#') . '(/wp-content/uploads/[^\s"\'<>);]+)#i';

    $dev_matches = [];
    $live_matches = [];
    preg_match_all($dev_pattern, $content, $dev_matches);
    preg_match_all($live_pattern, $content, $live_matches);

    $dev_urls  = array_unique($dev_matches[0] ?? []);
    $dev_paths = array_unique($dev_matches[1] ?? []);
    $live_urls = array_unique($live_matches[0] ?? []);

    if (empty($dev_urls) && empty($live_urls)) {
        continue;
    }

    echo "PAGE #{$page->ID}: {$page->post_title}\n";
    $replacements = 0;

    // --- Dev host: just strip the hostname, images already on disk ---
    foreach ($dev_urls as $i => $full_url) {
        $relative = $dev_paths[$i];

        // Verify file exists on disk
        $disk_path = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $relative);
        if (!file_exists($disk_path)) {
            echo "  WARNING (dev, file missing): " . basename($relative) . "\n";
        }

        $content = str_replace($full_url, $relative, $content);
        $replacements++;
        $stats['dev_rewritten']++;

        if ($dry_run) {
            echo "  DEV → relative: " . basename($relative) . "\n";
        } else {
            echo "  DEV → relative: " . basename($relative) . "\n";
        }
    }

    // --- Live host: download original, then rewrite ---
    foreach ($live_urls as $full_url) {
        // Extract path portion
        preg_match($live_pattern, $full_url, $m);
        $remote_path = $m[1];

        // Strip size suffix to get original URL for downloading
        $original_path = preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $remote_path);
        $original_url  = 'https://' . $live_host . $original_path;

        if (isset($download_cache[$original_url])) {
            $local_relative = $download_cache[$original_url];
            echo "  CACHED: " . basename($original_path) . "\n";
        } else {
            echo "  DOWNLOAD: " . basename($original_path) . "\n";

            if ($dry_run) {
                echo "    [dry-run] Would download from $live_host\n";
                $download_cache[$original_url] = '/wp-content/uploads/DRYRUN/' . basename($original_path);
                $local_relative = $download_cache[$original_url];
            } else {
                $tmp = download_url($original_url, 30);
                if (is_wp_error($tmp)) {
                    echo "    ERROR downloading: " . $tmp->get_error_message() . "\n";
                    $stats['errors']++;
                    continue;
                }

                $file_array = [
                    'name'     => basename(parse_url($original_url, PHP_URL_PATH)),
                    'tmp_name' => $tmp,
                ];

                $attachment_id = media_handle_sideload($file_array, $page->ID);
                if (is_wp_error($attachment_id)) {
                    echo "    ERROR sideloading: " . $attachment_id->get_error_message() . "\n";
                    @unlink($tmp);
                    $stats['errors']++;
                    continue;
                }

                $local_url = wp_get_attachment_url($attachment_id);
                $local_relative = str_replace($upload_dir['baseurl'], '/wp-content/uploads', $local_url);
                $download_cache[$original_url] = $local_relative;
                $stats['live_downloaded']++;

                echo "    Attached #$attachment_id\n";
            }
        }

        // Build the replacement URL (handle sized variants)
        if (preg_match('/-(\d+x\d+)\.[a-z]{3,4}$/i', $full_url, $sm)) {
            // It's a sized variant — build sized relative path
            $local_base = preg_replace('/(\.[a-z]{3,4})$/i', '', $local_relative);
            $local_ext  = pathinfo($local_relative, PATHINFO_EXTENSION);
            $sized_relative = "{$local_base}-{$sm[1]}.{$local_ext}";

            if (!$dry_run) {
                $sized_disk = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $sized_relative);
                if (file_exists($sized_disk)) {
                    $new_url = $sized_relative;
                } else {
                    $new_url = $local_relative; // fall back to full-size
                }
            } else {
                $new_url = $sized_relative;
            }
        } else {
            $new_url = $local_relative;
        }

        $content = str_replace($full_url, $new_url, $content);
        $replacements++;
        $stats['live_rewritten']++;
        echo "    Rewrite: " . basename($full_url) . " => " . basename($new_url) . "\n";
    }

    if ($replacements > 0 && !$dry_run) {
        wp_update_post([
            'ID'           => $page->ID,
            'post_content' => $content,
        ]);
        $stats['pages_updated']++;
        echo "  Content updated ($replacements URL(s) rewritten)\n";
    } elseif ($replacements > 0 && $dry_run) {
        echo "  [dry-run] Would update content ($replacements URL(s))\n";
    }

    echo "\n";
}

// ============================================================
// Report
// ============================================================
echo "=== Summary ===\n";
echo "Pages scanned:       {$stats['pages_scanned']}\n";
echo "Pages updated:       {$stats['pages_updated']}\n";
echo "Dev URLs → relative: {$stats['dev_rewritten']}\n";
echo "Live images downloaded: {$stats['live_downloaded']}\n";
echo "Live URLs rewritten: {$stats['live_rewritten']}\n";
echo "Errors:              {$stats['errors']}\n";
