<?php
/**
 * Import Content Images for Blog Posts
 *
 * Scans imported blog post content for staging-site image URLs,
 * downloads them into the dev media library, and rewrites the
 * URLs in post_content to point to the local site.
 *
 * Usage:
 *   wp eval-file import-content-images.php
 *
 * Options (set as environment variables):
 *   CSV_FILE=path         Path to transformed CSV (default: Post-First-Five.csv)
 *   DRY_RUN=1             Preview without downloading/rewriting (default: 0)
 *   POST_SLUG=slug        Process a single post by slug
 *   STAGING_HOST=host     Staging hostname to match (default: staging-gotostcroix.wordkeeper.net)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file import-content-images.php
 *   CSV_FILE=Posts-Staging-20260225-transformed.csv wp eval-file import-content-images.php
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
$csv_file     = getenv('CSV_FILE') ?: 'Post-First-Five.csv';
$dry_run      = getenv('DRY_RUN') === '1';
$filter_slug  = getenv('POST_SLUG') ?: '';
$staging_host = getenv('STAGING_HOST') ?: 'staging-gotostcroix.wordkeeper.net';

echo "=== Import Content Images ===\n";
echo "CSV file:      $csv_file\n";
echo "Staging host:  $staging_host\n";
echo "Dry run:       " . ($dry_run ? 'YES' : 'NO') . "\n";
if ($filter_slug) {
    echo "Filter slug:   $filter_slug\n";
}
echo "\n";

// ============================================================
// Read CSV for slug list
// ============================================================
if (!file_exists($csv_file)) {
    die("Error: CSV file not found: $csv_file\n");
}

$handle = fopen($csv_file, 'r');
$header = fgetcsv($handle);
$header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);

$slugs = [];
while (($data = fgetcsv($handle)) !== false) {
    if (count($data) === count($header)) {
        $row = array_combine($header, $data);
        $slug = trim($row['Slug'] ?? '');
        if ($slug) {
            $slugs[] = $slug;
        }
    }
}
fclose($handle);

echo "CSV slugs loaded: " . count($slugs) . "\n\n";

// ============================================================
// Helpers
// ============================================================

/**
 * Strip WP size suffix from a URL to get the original.
 * e.g. edit_foo-1024x448.jpg => edit_foo.jpg
 */
function get_original_url($url) {
    return preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $url);
}

/**
 * Find all staging image URLs in content.
 * Returns array of unique URLs found.
 */
function find_staging_urls($content, $staging_host) {
    $pattern = '#https?://' . preg_quote($staging_host, '#') . '/wp-content/uploads/[^\s"\'<>]+#i';
    preg_match_all($pattern, $content, $matches);
    return array_unique($matches[0]);
}

/**
 * Download an image and sideload into the media library.
 * Returns the new local URL, or WP_Error on failure.
 */
function sideload_image($url, $post_id) {
    $tmp = download_url($url, 30);
    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return $attachment_id;
    }

    return [
        'attachment_id' => $attachment_id,
        'url'           => wp_get_attachment_url($attachment_id),
    ];
}

// ============================================================
// Process posts
// ============================================================
$stats = [
    'posts_processed'  => 0,
    'posts_with_images' => 0,
    'posts_updated'    => 0,
    'images_downloaded' => 0,
    'urls_rewritten'   => 0,
    'errors'           => 0,
];

// Cache: staging original URL => local attachment data
// Shared across posts so the same image isn't downloaded twice
$image_cache = [];

foreach ($slugs as $slug) {
    if ($filter_slug && $slug !== $filter_slug) {
        continue;
    }

    $posts = get_posts([
        'name'        => $slug,
        'post_type'   => 'post',
        'post_status' => 'any',
        'numberposts' => 1,
    ]);

    if (empty($posts)) {
        continue;
    }

    $post = $posts[0];
    $stats['posts_processed']++;

    // Find staging URLs in content
    $staging_urls = find_staging_urls($post->post_content, $staging_host);
    if (empty($staging_urls)) {
        continue;
    }

    $stats['posts_with_images']++;
    echo "POST #{$post->ID}: {$post->post_title}\n";
    echo "  Found " . count($staging_urls) . " staging URL(s)\n";

    $content = $post->post_content;
    $replacements = 0;

    // Group URLs by their original (un-sized) version
    // so we download each original once
    $url_groups = [];
    foreach ($staging_urls as $url) {
        $original = get_original_url($url);
        $url_groups[$original][] = $url;
    }

    foreach ($url_groups as $original_url => $variant_urls) {
        // Check cache first
        if (isset($image_cache[$original_url])) {
            $local = $image_cache[$original_url];
            echo "  CACHED: " . basename($original_url) . " => attachment #{$local['attachment_id']}\n";
        } else {
            echo "  DOWNLOAD: " . basename($original_url) . "\n";

            if ($dry_run) {
                // In dry-run, just report what we'd do
                foreach ($variant_urls as $vurl) {
                    echo "    Would rewrite: " . basename($vurl) . "\n";
                    $replacements++;
                }
                $stats['images_downloaded']++;
                continue;
            }

            $result = sideload_image($original_url, $post->ID);
            if (is_wp_error($result)) {
                echo "    ERROR: " . $result->get_error_message() . "\n";
                $stats['errors']++;
                continue;
            }

            $local = $result;
            $image_cache[$original_url] = $local;
            $stats['images_downloaded']++;
            echo "    Attached #{$local['attachment_id']}\n";
        }

        if ($dry_run) {
            foreach ($variant_urls as $vurl) {
                echo "    Would rewrite: " . basename($vurl) . "\n";
                $replacements++;
            }
            continue;
        }

        // Build relative URL paths (domain-independent)
        // e.g. /wp-content/uploads/2026/02/edit_foo.jpg
        $local_url  = $local['url'];
        $upload_dir = wp_get_upload_dir();
        $relative_url  = str_replace($upload_dir['baseurl'], '/wp-content/uploads', $local_url);
        $relative_base = preg_replace('/(\.[a-z]{3,4})$/i', '', $relative_url);
        $local_ext     = pathinfo($local_url, PATHINFO_EXTENSION);

        foreach ($variant_urls as $staging_variant) {
            // Check if this is a sized variant
            if (preg_match('/-(\d+x\d+)\.[a-z]{3,4}$/i', $staging_variant, $m)) {
                $sized_relative = "{$relative_base}-{$m[1]}.{$local_ext}";
                // Verify the sized file exists on disk
                $abs_path = $upload_dir['basedir'] . str_replace('/wp-content/uploads', '', $sized_relative);

                if (file_exists($abs_path)) {
                    $new_url = $sized_relative;
                } else {
                    // Sized variant doesn't exist locally — use the full-size image
                    $new_url = $relative_url;
                }
            } else {
                $new_url = $relative_url;
            }

            $content = str_replace($staging_variant, $new_url, $content);
            $replacements++;
            echo "    Rewrite: " . basename($staging_variant) . " => " . basename($new_url) . "\n";
        }
    }

    $stats['urls_rewritten'] += $replacements;

    if (!$dry_run && $replacements > 0) {
        wp_update_post([
            'ID'           => $post->ID,
            'post_content' => $content,
        ]);
        $stats['posts_updated']++;
        echo "  Content updated ($replacements URL(s) rewritten)\n";
    } elseif ($dry_run && $replacements > 0) {
        echo "  [dry-run] Would update content ($replacements URL(s))\n";
    }

    echo "\n";
}

// ============================================================
// Report
// ============================================================
echo "=== Summary ===\n";
echo "Posts processed:     {$stats['posts_processed']}\n";
echo "Posts with images:   {$stats['posts_with_images']}\n";
echo "Posts updated:       {$stats['posts_updated']}\n";
echo "Images downloaded:   {$stats['images_downloaded']}\n";
echo "URLs rewritten:      {$stats['urls_rewritten']}\n";
echo "Errors:              {$stats['errors']}\n";
