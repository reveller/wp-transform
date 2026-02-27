<?php
/**
 * Scrub Staging Links
 *
 * Scans post_content across all post types for references to staging/dev/live
 * hostnames and rewrites them to domain-independent relative paths.
 *
 * For image URLs (/wp-content/uploads/...):
 *   - staging-gotostcroix.wordkeeper.net  → download + register in Media Library
 *   - www.gotostcroix.com / gotostcroix.com → download + register in Media Library
 *   - gotostcroix-dev.wordkeeper.net      → already on disk, just strip hostname
 *
 * For non-image URLs (page links, etc.):
 *   - All hostnames → strip hostname, keep path as relative URL
 *
 * Usage:
 *   wp eval-file scrub-staging-links.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1           Preview without modifying (default: 0)
 *   POST_TYPE=type      Limit to a single post type (default: all)
 *   POST_SLUG=slug      Process a single post by slug
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file scrub-staging-links.php
 *   DRY_RUN=1 POST_TYPE=post wp eval-file scrub-staging-links.php
 *   POST_SLUG=about-us DRY_RUN=1 wp eval-file scrub-staging-links.php
 *   wp eval-file scrub-staging-links.php
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ============================================================
// Configuration
// ============================================================
$dry_run     = getenv('DRY_RUN') === '1';
$filter_type = getenv('POST_TYPE') ?: '';
$filter_slug = getenv('POST_SLUG') ?: '';

// Hostnames that need downloading (images not on dev disk)
$download_hosts = [
    'staging-gotostcroix.wordkeeper.net',
    'www.gotostcroix.com',
    'gotostcroix.com',
];

// Hostnames where images are already on dev disk
$local_hosts = [
    'gotostcroix-dev.wordkeeper.net',
];

$all_hostnames = array_merge($download_hosts, $local_hosts);

// All post types to scan
$post_types = [
    'post',
    'page',
    'gd_place',
    'gd_foodanddrink',
    'gd_gettingaround',
    'gd_islandliving',
    'gd_thingstodo',
    'gd_event',
    'gd_guides',
    'gd_specialoffers',
];

if ($filter_type) {
    if (!in_array($filter_type, $post_types)) {
        die("Error: Unknown post type '$filter_type'\n");
    }
    $post_types = [$filter_type];
}

echo "=== Scrub Staging Links ===\n";
echo "Dry run:    " . ($dry_run ? 'YES' : 'NO') . "\n";
echo "Post types: " . implode(', ', $post_types) . "\n";
if ($filter_slug) {
    echo "Filter slug: $filter_slug\n";
}
echo "Hostnames:  " . implode(', ', $all_hostnames) . "\n";
echo "\n";

// ============================================================
// Build combined regex pattern
// ============================================================
$host_alts = implode('|', array_map(function ($h) {
    return preg_quote($h, '#');
}, $all_hostnames));

// Match the full URL: protocol + hostname + optional path
// Exclude trailing ), ;, whitespace, quotes, angle brackets
$pattern = '#https?://(' . $host_alts . ')(/[^\s"\'<>);]*)?#i';

// ============================================================
// Helpers
// ============================================================
$upload_dir     = wp_get_upload_dir();
$download_cache = []; // URL => local relative path

/**
 * Check if a URL path points to an uploads image file.
 */
function is_upload_path($path) {
    return (bool) preg_match('#^/wp-content/uploads/.+\.(jpe?g|png|gif|webp|svg|bmp|ico)$#i', $path);
}

/**
 * Strip WP size suffix: image-300x200.jpg => image.jpg
 */
function strip_size_suffix($path) {
    return preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $path);
}

/**
 * Download an image, register in Media Library, return relative path.
 * Returns false on failure.
 */
function download_and_register($url, $post_id, $dry_run) {
    global $upload_dir, $download_cache;

    // Check cache first (keyed by original URL, not sized variant)
    if (isset($download_cache[$url])) {
        return $download_cache[$url];
    }

    if ($dry_run) {
        $relative = '/wp-content/uploads/DRYRUN/' . basename(parse_url($url, PHP_URL_PATH));
        $download_cache[$url] = $relative;
        return $relative;
    }

    $tmp = download_url($url, 30);
    if (is_wp_error($tmp)) {
        echo "    ERROR downloading: " . $tmp->get_error_message() . "\n";
        return false;
    }

    $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        echo "    ERROR sideloading: " . $attachment_id->get_error_message() . "\n";
        @unlink($tmp);
        return false;
    }

    // Use _wp_attached_file meta directly — more reliable than wp_get_attachment_url in wp-cli
    $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    $relative = '/wp-content/uploads/' . $attached_file;
    $download_cache[$url] = $relative;

    echo "    Registered media #$attachment_id\n";
    return $relative;
}

// ============================================================
// Process each post type
// ============================================================
$stats = [
    'scanned'    => 0,
    'updated'    => 0,
    'links'      => 0,
    'images'     => 0,
    'downloaded' => 0,
    'local'      => 0,
    'errors'     => 0,
    'by_host'    => array_fill_keys($all_hostnames, 0),
    'by_type'    => [],
];

foreach ($post_types as $pt) {
    $args = [
        'post_type'   => $pt,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => -1,
    ];

    if ($filter_slug) {
        $args['name'] = $filter_slug;
    }

    $posts = get_posts($args);
    if (empty($posts)) {
        continue;
    }

    $type_count = 0;

    foreach ($posts as $post) {
        $content = $post->post_content;
        $stats['scanned']++;

        $matches = [];
        $found = preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        if (!$found) {
            continue;
        }

        echo "$pt #{$post->ID}: {$post->post_name}\n";
        $replacements = 0;

        // Deduplicate matches (same full URL may appear multiple times)
        $seen = [];

        foreach ($matches as $m) {
            $full_url = $m[0];
            $hostname = $m[1];
            $path     = $m[2] ?? '/';

            if (empty($path)) {
                $path = '/';
            }

            // Skip if already processed this exact URL in this post
            if (isset($seen[$full_url])) {
                continue;
            }
            $seen[$full_url] = true;

            $stats['by_host'][$hostname]++;

            // Determine if this is an image upload URL
            if (is_upload_path($path)) {
                $stats['images']++;

                if (in_array($hostname, $local_hosts)) {
                    // Dev host — file already on disk, just strip hostname
                    $disk_path = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $path);
                    if (!file_exists($disk_path)) {
                        echo "  WARNING (file missing on disk): $path\n";
                    }
                    $new_url = $path;
                    $stats['local']++;
                    echo "  IMG (local): $full_url => $new_url\n";
                } else {
                    // Staging/live host — need to download
                    $original_path = strip_size_suffix($path);
                    $original_url  = 'https://' . $hostname . $original_path;

                    echo "  IMG (download): " . basename($path) . " from $hostname\n";

                    $local_relative = download_and_register($original_url, $post->ID, $dry_run);

                    if ($local_relative === false) {
                        $stats['errors']++;
                        continue;
                    }

                    $stats['downloaded']++;

                    // If original URL was a sized variant, build the sized relative path
                    if (preg_match('/-(\d+x\d+)\.[a-z]{3,4}$/i', $path, $sm)) {
                        $local_base = preg_replace('/(\.[a-z]{3,4})$/i', '', $local_relative);
                        $local_ext  = pathinfo($local_relative, PATHINFO_EXTENSION);
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

                    echo "    Rewrite: " . basename($full_url) . " => " . basename($new_url) . "\n";
                }
            } else {
                // Non-image URL (page link, etc.) — just strip hostname
                $new_url = $path;
                $stats['links']++;
                echo "  LINK: $full_url => $new_url\n";
            }

            $content = str_replace($full_url, $new_url, $content);
            $replacements++;
            $stats['urls'] = ($stats['urls'] ?? 0) + 1;
        }

        if ($replacements > 0) {
            $type_count += $replacements;

            if (!$dry_run) {
                wp_update_post([
                    'ID'           => $post->ID,
                    'post_content' => $content,
                ]);
                $stats['updated']++;
                echo "  => Updated ($replacements rewrites)\n";
            } else {
                echo "  => [dry-run] Would update ($replacements rewrites)\n";
            }
        }

        echo "\n";
    }

    if ($type_count > 0) {
        $stats['by_type'][$pt] = $type_count;
    }
}

// ============================================================
// Report
// ============================================================
echo "=== Summary ===\n";
echo "Posts scanned:     {$stats['scanned']}\n";
echo "Posts updated:     {$stats['updated']}\n";
echo "Image URLs:        {$stats['images']}\n";
echo "  Downloaded:      {$stats['downloaded']}\n";
echo "  Already local:   {$stats['local']}\n";
echo "Link URLs:         {$stats['links']}\n";
echo "Errors:            {$stats['errors']}\n";
echo "\n";

echo "By hostname:\n";
foreach ($stats['by_host'] as $host => $count) {
    if ($count > 0) {
        echo "  $host: $count\n";
    }
}

echo "\nBy post type:\n";
foreach ($stats['by_type'] as $type => $count) {
    echo "  $type: $count\n";
}
