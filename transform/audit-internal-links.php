<?php
/**
 * Audit Internal Links
 *
 * Scans post_content across all post types for internal href links
 * and checks whether each target URL resolves to an existing post/page
 * on the site. Reports any broken (404) links.
 *
 * Usage:
 *   wp eval-file audit-internal-links.php
 *
 * Options (set as environment variables):
 *   POST_TYPE=type      Limit to a single post type (default: all)
 *   POST_SLUG=slug      Process a single post by slug
 *   VERBOSE=1           Show all links, not just broken ones (default: 0)
 *
 * Examples:
 *   wp eval-file audit-internal-links.php
 *   POST_TYPE=post wp eval-file audit-internal-links.php
 *   VERBOSE=1 wp eval-file audit-internal-links.php
 */

$filter_type = getenv('POST_TYPE') ?: '';
$filter_slug = getenv('POST_SLUG') ?: '';
$verbose     = getenv('VERBOSE') === '1';

$post_types = [
    'post', 'page',
    'gd_place', 'gd_foodanddrink', 'gd_gettingaround',
    'gd_islandliving', 'gd_thingstodo', 'gd_event',
    'gd_guides', 'gd_specialoffers',
];

if ($filter_type) {
    if (!in_array($filter_type, $post_types)) {
        die("Error: Unknown post type '$filter_type'\n");
    }
    $post_types = [$filter_type];
}

echo "=== Audit Internal Links ===\n";
echo "Post types: " . implode(', ', $post_types) . "\n";
if ($filter_slug) {
    echo "Filter slug: $filter_slug\n";
}
echo "Verbose:    " . ($verbose ? 'YES' : 'NO') . "\n";
echo "\n";

// ============================================================
// Build a lookup cache of known URLs → post ID
// ============================================================
echo "Building URL lookup cache...\n";
$url_cache = [];

// Get all public posts across all types
$all_types = get_post_types(['public' => true]);
$all_posts = get_posts([
    'post_type'   => array_values($all_types),
    'post_status' => ['publish', 'draft', 'pending', 'private'],
    'numberposts' => -1,
]);

foreach ($all_posts as $p) {
    // Get the permalink path (relative)
    $permalink = get_permalink($p->ID);
    if ($permalink) {
        $path = parse_url($permalink, PHP_URL_PATH);
        if ($path) {
            $url_cache[rtrim($path, '/')] = $p->ID;
        }
    }

    // Also index by simple slug for fallback matching
    if ($p->post_name) {
        $url_cache['slug:' . $p->post_name] = $p->ID;
    }
}

// Add known taxonomy/archive paths (these won't be posts but are valid)
// We'll mark these as "archive" type
$taxonomies = get_taxonomies(['public' => true], 'objects');
foreach ($taxonomies as $tax) {
    $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            $term_link = get_term_link($term);
            if (!is_wp_error($term_link)) {
                $path = parse_url($term_link, PHP_URL_PATH);
                if ($path) {
                    $url_cache[rtrim($path, '/')] = 'term:' . $term->term_id;
                }
            }
        }
    }
}

echo "Cached " . count($url_cache) . " known URLs\n\n";

// ============================================================
// Scan content for internal links
// ============================================================
// Match href="..." with relative paths (starting with /)
$link_pattern = '#href=["\'](/[^"\']*)["\']#i';

// Skip these paths (assets, anchors, uploads, feeds, etc.)
$skip_prefixes = [
    '/wp-content/',
    '/wp-admin/',
    '/wp-includes/',
    '/wp-json/',
    '/feed/',
    '/xmlrpc',
    '/#',
    '/tag/',
];

$stats = [
    'scanned'     => 0,
    'with_links'  => 0,
    'total_links'  => 0,
    'ok'          => 0,
    'broken'      => 0,
    'skipped'     => 0,
];

$broken_links = []; // path => [post IDs]

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
        if (!preg_match_all($link_pattern, $content, $matches)) {
            continue;
        }

        $stats['with_links']++;
        $post_broken = [];
        $post_ok     = [];

        $seen = [];
        foreach ($matches[1] as $href) {
            // Strip query string and fragment
            $clean = preg_replace('/[?#].*$/', '', $href);
            $clean = rtrim($clean, '/');

            if (empty($clean) || $clean === '') {
                continue;
            }

            // Skip if already checked in this post
            if (isset($seen[$clean])) continue;
            $seen[$clean] = true;

            $stats['total_links']++;

            // Skip non-content paths
            $skip = false;
            foreach ($skip_prefixes as $prefix) {
                if (strpos($clean, $prefix) === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                $stats['skipped']++;
                continue;
            }

            // Look up in cache
            if (isset($url_cache[$clean])) {
                $stats['ok']++;
                if ($verbose) {
                    $post_ok[] = $clean;
                }
            } else {
                // Try with trailing content stripped (e.g. /page/2)
                $base = preg_replace('#/page/\d+$#', '', $clean);
                if ($base !== $clean && isset($url_cache[$base])) {
                    $stats['ok']++;
                    if ($verbose) {
                        $post_ok[] = $clean;
                    }
                } else {
                    $stats['broken']++;
                    $post_broken[] = $clean;

                    if (!isset($broken_links[$clean])) {
                        $broken_links[$clean] = [];
                    }
                    $broken_links[$clean][] = $post->ID;
                }
            }
        }

        if (!empty($post_broken) || ($verbose && !empty($post_ok))) {
            echo "$pt #{$post->ID}: {$post->post_name}\n";

            if ($verbose) {
                foreach ($post_ok as $link) {
                    echo "  OK:     $link\n";
                }
            }
            foreach ($post_broken as $link) {
                echo "  BROKEN: $link\n";
            }
            echo "\n";
        }
    }
}

// ============================================================
// Report
// ============================================================
echo "=== Summary ===\n";
echo "Posts scanned:    {$stats['scanned']}\n";
echo "Posts with links: {$stats['with_links']}\n";
echo "Total links:      {$stats['total_links']}\n";
echo "  OK:             {$stats['ok']}\n";
echo "  Broken:         {$stats['broken']}\n";
echo "  Skipped:        {$stats['skipped']}\n";
echo "\n";

if (!empty($broken_links)) {
    echo "=== Broken Links Detail ===\n";
    // Sort by number of references (most common first)
    uasort($broken_links, function ($a, $b) {
        return count($b) - count($a);
    });

    foreach ($broken_links as $path => $post_ids) {
        $count = count($post_ids);
        echo "  $path ($count ref" . ($count > 1 ? 's' : '') . ")\n";
    }
}
