<?php
/**
 * Scrub Staging Links — Retry Failed Downloads
 *
 * Finds any remaining staging-gotostcroix.wordkeeper.net image URLs in post content
 * and retries the download from www.gotostcroix.com as a fallback.
 *
 * Run this AFTER scrub-staging-links.php to mop up 404 failures.
 *
 * Usage:
 *   wp eval-file scrub-staging-retry.php
 *
 * Options:
 *   DRY_RUN=1       Preview without modifying (default: 0)
 *   POST_SLUG=slug  Process a single post by slug
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$dry_run     = getenv('DRY_RUN') === '1';
$filter_slug = getenv('POST_SLUG') ?: '';

$staging_host  = 'staging-gotostcroix.wordkeeper.net';
$fallback_host = 'www.gotostcroix.com';

echo "=== Scrub Staging Links — Retry via Live Site ===\n";
echo "Dry run:       " . ($dry_run ? 'YES' : 'NO') . "\n";
echo "Staging host:  $staging_host\n";
echo "Fallback host: $fallback_host\n";
echo "\n";

// Pattern: staging image URLs still in content
$pattern = '#https?://' . preg_quote($staging_host, '#') . '(/wp-content/uploads/[^\s"\'<>);]+)#i';

$upload_dir     = wp_get_upload_dir();
$download_cache = [];

function strip_size_suffix($path) {
    return preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $path);
}

// Scan all post types
$post_types = [
    'post', 'page',
    'gd_place', 'gd_foodanddrink', 'gd_gettingaround',
    'gd_islandliving', 'gd_thingstodo', 'gd_event',
    'gd_guides', 'gd_specialoffers',
];

$stats = ['scanned' => 0, 'found' => 0, 'downloaded' => 0, 'still_missing' => 0, 'updated' => 0];

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
    foreach ($posts as $post) {
        $content = $post->post_content;
        $stats['scanned']++;

        $matches = [];
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            continue;
        }

        echo "$pt #{$post->ID}: {$post->post_name}\n";
        $replacements = 0;
        $seen = [];

        foreach ($matches as $m) {
            $full_url = $m[0];
            $path     = $m[1];

            if (isset($seen[$full_url])) continue;
            $seen[$full_url] = true;
            $stats['found']++;

            $original_path = strip_size_suffix($path);
            $fallback_url  = 'https://' . $fallback_host . $original_path;

            echo "  RETRY: " . basename($path) . "\n";
            echo "    Trying: $fallback_url\n";

            if ($dry_run) {
                echo "    [dry-run] Would download from $fallback_host\n";
                $new_url = $path; // placeholder
                $replacements++;
                continue;
            }

            // Check cache
            if (isset($download_cache[$fallback_url])) {
                $local_relative = $download_cache[$fallback_url];
                echo "    CACHED\n";
            } else {
                $tmp = download_url($fallback_url, 30);
                if (is_wp_error($tmp)) {
                    echo "    STILL MISSING: " . $tmp->get_error_message() . "\n";
                    $stats['still_missing']++;
                    continue;
                }

                $file_array = [
                    'name'     => basename(parse_url($fallback_url, PHP_URL_PATH)),
                    'tmp_name' => $tmp,
                ];

                $attachment_id = media_handle_sideload($file_array, $post->ID);
                if (is_wp_error($attachment_id)) {
                    echo "    ERROR sideloading: " . $attachment_id->get_error_message() . "\n";
                    @unlink($tmp);
                    $stats['still_missing']++;
                    continue;
                }

                $attached_file  = get_post_meta($attachment_id, '_wp_attached_file', true);
                $local_relative = '/wp-content/uploads/' . $attached_file;
                $download_cache[$fallback_url] = $local_relative;
                $stats['downloaded']++;

                echo "    Registered media #$attachment_id\n";
            }

            // Build sized variant path if needed
            if (preg_match('/-(\d+x\d+)\.[a-z]{3,4}$/i', $path, $sm)) {
                $local_base     = preg_replace('/(\.[a-z]{3,4})$/i', '', $local_relative);
                $local_ext      = pathinfo($local_relative, PATHINFO_EXTENSION);
                $sized_relative = "{$local_base}-{$sm[1]}.{$local_ext}";

                $sized_disk = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $sized_relative);
                $new_url = file_exists($sized_disk) ? $sized_relative : $local_relative;
            } else {
                $new_url = $local_relative;
            }

            $content = str_replace($full_url, $new_url, $content);
            $replacements++;
            echo "    Rewrite: " . basename($full_url) . " => " . basename($new_url) . "\n";
        }

        if ($replacements > 0 && !$dry_run) {
            wp_update_post([
                'ID'           => $post->ID,
                'post_content' => $content,
            ]);
            $stats['updated']++;
            echo "  => Updated ($replacements rewrites)\n";
        }
        echo "\n";
    }
}

echo "=== Summary ===\n";
echo "Posts scanned:    {$stats['scanned']}\n";
echo "Staging URLs:     {$stats['found']}\n";
echo "Downloaded (live): {$stats['downloaded']}\n";
echo "Still missing:    {$stats['still_missing']}\n";
echo "Posts updated:    {$stats['updated']}\n";
