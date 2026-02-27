<?php
/**
 * Export Yoast SEO Metadata
 *
 * Exports all Yoast SEO post meta from the site, keyed by post slug,
 * to a JSON file for import into another site.
 *
 * Usage:
 *   wp eval-file export-yoast-meta.php
 *
 * Options (set as environment variables):
 *   OUTPUT_FILE=path    Output JSON file (default: yoast-meta-export.json)
 *   POST_TYPE=type      Limit to a single post type (default: all public types)
 *   POST_SLUG=slug      Export a single post by slug
 *
 * Examples:
 *   wp eval-file export-yoast-meta.php
 *   OUTPUT_FILE=yoast-staging.json wp eval-file export-yoast-meta.php
 *   POST_TYPE=post wp eval-file export-yoast-meta.php
 */

$output_file = getenv('OUTPUT_FILE') ?: 'yoast-meta-export.json';
$filter_type = getenv('POST_TYPE') ?: '';
$filter_slug = getenv('POST_SLUG') ?: '';

// Yoast meta keys to export
$yoast_keys = [
    '_yoast_wpseo_title',
    '_yoast_wpseo_metadesc',
    '_yoast_wpseo_focuskw',
    '_yoast_wpseo_focuskw_text_input',
    '_yoast_wpseo_canonical',
    '_yoast_wpseo_meta-robots-noindex',
    '_yoast_wpseo_meta-robots-nofollow',
    '_yoast_wpseo_meta-robots-adv',
    '_yoast_wpseo_opengraph-title',
    '_yoast_wpseo_opengraph-description',
    '_yoast_wpseo_opengraph-image',
    '_yoast_wpseo_opengraph-image-id',
    '_yoast_wpseo_twitter-title',
    '_yoast_wpseo_twitter-description',
    '_yoast_wpseo_twitter-image',
    '_yoast_wpseo_twitter-image-id',
    '_yoast_wpseo_schema_page_type',
    '_yoast_wpseo_schema_article_type',
    '_yoast_wpseo_primary_category',
    '_yoast_wpseo_estimated-reading-time-minutes',
    '_yoast_wpseo_wordproof_timestamp',
    '_yoast_wpseo_redirect',
];

echo "=== Export Yoast SEO Metadata ===\n";
echo "Output file: $output_file\n";

// Get post types to scan
if ($filter_type) {
    $post_types = [$filter_type];
} else {
    $post_types = get_post_types(['public' => true]);
    // Remove 'attachment' — not useful for Yoast meta
    unset($post_types['attachment']);
    $post_types = array_values($post_types);
}

echo "Post types:  " . implode(', ', $post_types) . "\n";
if ($filter_slug) {
    echo "Filter slug: $filter_slug\n";
}
echo "\n";

$export = [];
$stats = [
    'scanned'    => 0,
    'with_meta'  => 0,
    'meta_values' => 0,
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

    foreach ($posts as $post) {
        $stats['scanned']++;

        $meta = [];
        foreach ($yoast_keys as $key) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value !== '' && $value !== false) {
                $meta[$key] = $value;
            }
        }

        if (empty($meta)) {
            continue;
        }

        $stats['with_meta']++;
        $stats['meta_values'] += count($meta);

        if (!isset($stats['by_type'][$pt])) {
            $stats['by_type'][$pt] = 0;
        }
        $stats['by_type'][$pt]++;

        $export[] = [
            'slug'      => $post->post_name,
            'post_type' => $pt,
            'title'     => $post->post_title,
            'meta'      => $meta,
        ];
    }
}

// Write JSON
$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($output_file, $json);

echo "=== Summary ===\n";
echo "Posts scanned:    {$stats['scanned']}\n";
echo "Posts with Yoast: {$stats['with_meta']}\n";
echo "Meta values:      {$stats['meta_values']}\n";
echo "Output file:      $output_file\n";
echo "\nBy post type:\n";
foreach ($stats['by_type'] as $type => $count) {
    echo "  $type: $count\n";
}
