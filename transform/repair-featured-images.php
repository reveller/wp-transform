<?php
/**
 * Repair GeoDirectory Featured Images
 *
 * After dedup, the featured_image column in the GD detail table and the
 * featured=1 flag in geodir_attachments may reference old (deleted) revision
 * files. This script checks and repairs:
 *
 *   1. featured_image column in the detail table — must match a valid GD attachment file
 *   2. featured=1 flag in geodir_attachments — exactly one attachment per post should have it
 *   3. _thumbnail_id in wp_postmeta — must point to a valid WP attachment
 *
 * Usage:
 *   wp eval-file repair-featured-images.php
 *
 * Options (set as environment variables):
 *   CPT_NAME=name       CPT display name or post_type slug — optional, repairs all CPTs if omitted
 *   CATEGORY=name       Filter to posts in a specific GD category (e.g., "Shopping")
 *   DRY_RUN=1           Preview changes without executing (default: 0)
 *   POST_TITLE=title    Filter to a single post title
 *   OUTPUT_FILE=path    Write report to file instead of stdout
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file repair-featured-images.php
 *   CPT_NAME="Places to Stay" DRY_RUN=1 wp eval-file repair-featured-images.php
 *   CPT_NAME="Places to Stay" wp eval-file repair-featured-images.php
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

if (!class_exists('GeoDirectory')) {
    die("Error: GeoDirectory plugin is not active.\n");
}

// ============================================================
// Parse environment variables
// ============================================================
$cpt_filter = getenv('CPT_NAME') ?: '';
$category_filter = getenv('CATEGORY') ?: null;
$dry_run = !empty(getenv('DRY_RUN'));
$post_title_filter = getenv('POST_TITLE') ?: null;
$output_file = getenv('OUTPUT_FILE') ?: null;

global $wpdb;
$attachments_table = $wpdb->prefix . 'geodir_attachments';
$upload_dir = wp_get_upload_dir();
$uploads_basedir = $upload_dir['basedir'];
$uploads_baseurl = $upload_dir['baseurl'];

// Discover PK column
$sample_row = $wpdb->get_row("SELECT * FROM $attachments_table LIMIT 1");
$gd_id_col = 'ID';
if ($sample_row) {
    foreach (array_keys(get_object_vars($sample_row)) as $col) {
        if (strtolower($col) === 'id') {
            $gd_id_col = $col;
            break;
        }
    }
}

if ($output_file) {
    ob_start();
}

// ============================================================
// Report header
// ============================================================
echo "GeoDirectory Featured Image Repair\n";
echo "====================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Uploads: $uploads_basedir\n";
if ($cpt_filter) {
    echo "CPT filter: $cpt_filter\n";
}
if ($post_title_filter) {
    echo "Post filter: $post_title_filter\n";
}
if ($dry_run) {
    echo "*** DRY RUN — no changes will be made ***\n";
}
echo "\n";

// ============================================================
// Discover GD CPTs to process
// ============================================================
$gd_post_types = get_option('geodir_post_types', []);
if (empty($gd_post_types)) {
    $all_taxonomies = get_taxonomies([], 'objects');
    foreach ($all_taxonomies as $tax_name => $tax_obj) {
        if (preg_match('/^(gd_\w+)category$/', $tax_name, $matches)) {
            $gd_post_types[$matches[1]] = ['detected' => true];
        }
    }
}

$types_to_process = [];

if ($cpt_filter) {
    $filter_lower = strtolower($cpt_filter);
    $resolved = null;
    foreach ($gd_post_types as $post_type => $settings) {
        if (strtolower($post_type) === $filter_lower) {
            $resolved = $post_type;
            break;
        }
        $pt_obj = get_post_type_object($post_type);
        if ($pt_obj && strtolower($pt_obj->labels->name) === $filter_lower) {
            $resolved = $post_type;
            break;
        }
    }
    if (!$resolved) {
        die("Error: CPT '$cpt_filter' not found.\n");
    }
    $types_to_process[] = $resolved;
    echo "Resolved CPT: $resolved\n\n";
} else {
    $types_to_process = array_keys($gd_post_types);
    echo "Processing all GD CPTs: " . implode(', ', $types_to_process) . "\n\n";
}

// ============================================================
// Helper: find WP attachment ID by _wp_attached_file
// ============================================================
function find_wp_attachment_by_file($wpdb, $relative_path) {
    $path = ltrim($relative_path, '/');
    return $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $path
    ));
}

// ============================================================
// Process each CPT
// ============================================================
$stats = [
    'posts_scanned' => 0,
    'posts_with_issues' => 0,
    'featured_image_col_fixed' => 0,
    'featured_flag_fixed' => 0,
    'thumbnail_id_fixed' => 0,
    'no_attachments' => 0,
];

foreach ($types_to_process as $post_type) {
    $pt_obj = get_post_type_object($post_type);
    $cpt_display = $pt_obj ? $pt_obj->labels->name : $post_type;
    $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';

    echo str_repeat('=', 70) . "\n";
    echo "CPT: $cpt_display ($post_type)\n";
    echo str_repeat('=', 70) . "\n\n";

    // Check if featured_image column exists in detail table
    $has_featured_image_col = $wpdb->get_var(
        "SHOW COLUMNS FROM `$detail_table` LIKE 'featured_image'"
    );

    if (!$has_featured_image_col) {
        echo "  Note: No featured_image column in $detail_table — skipping detail table checks\n\n";
    }

    // Query posts
    $query_args = [
        'post_type' => $post_type,
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ];

    // Filter by category if CATEGORY is set
    if ($category_filter) {
        $taxonomy = $post_type . 'category';
        $term = get_term_by('name', $category_filter, $taxonomy)
             ?: get_term_by('slug', $category_filter, $taxonomy);
        if (!$term) {
            echo "  Note: Category \"$category_filter\" not found in taxonomy $taxonomy — skipping CPT\n\n";
            continue;
        }
        $query_args['tax_query'] = [[
            'taxonomy' => $taxonomy,
            'field' => 'term_id',
            'terms' => $term->term_id,
        ]];
        echo "  Category filter: {$term->name} (ID {$term->term_id})\n";
    }

    $posts = get_posts($query_args);

    if ($post_title_filter) {
        $filter_lower = strtolower($post_title_filter);
        $posts = array_filter($posts, function($p) use ($filter_lower) {
            return strtolower($p->post_title) === $filter_lower;
        });
    }

    echo "  Scanning " . count($posts) . " post(s)...\n\n";

    foreach ($posts as $post) {
        $stats['posts_scanned']++;
        $post_id = $post->ID;
        $title = $post->post_title;

        // Get GD attachments
        $gd_attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $attachments_table WHERE post_id = %d AND mime_type LIKE 'image/%%' ORDER BY menu_order ASC",
            $post_id
        ));

        if (empty($gd_attachments)) {
            $stats['no_attachments']++;
            continue;
        }

        $issues = [];

        // ----------------------------------------------------------
        // Check 1: featured=1 flag in geodir_attachments
        // ----------------------------------------------------------
        $featured_rows = array_filter($gd_attachments, function($a) {
            return !empty($a->featured) && $a->featured == 1;
        });

        $first_att = $gd_attachments[0]; // menu_order ASC, so first is the expected featured
        $first_att_file = ltrim($first_att->file, '/');
        $first_att_id = $first_att->$gd_id_col;

        if (count($featured_rows) === 0) {
            // No featured flag set — set it on the first attachment
            $issues[] = "No featured=1 flag set on any GD attachment";

            if (!$dry_run) {
                $wpdb->update(
                    $attachments_table,
                    ['featured' => 1],
                    [$gd_id_col => $first_att_id],
                    ['%d'],
                    ['%d']
                );
            }
            $stats['featured_flag_fixed']++;
        } elseif (count($featured_rows) > 1) {
            // Multiple featured flags — clear all and set on first
            $issues[] = count($featured_rows) . " attachments have featured=1 (should be 1)";

            if (!$dry_run) {
                // Clear all featured flags for this post
                $wpdb->query($wpdb->prepare(
                    "UPDATE $attachments_table SET featured = 0 WHERE post_id = %d",
                    $post_id
                ));
                // Set on first
                $wpdb->update(
                    $attachments_table,
                    ['featured' => 1],
                    [$gd_id_col => $first_att_id],
                    ['%d'],
                    ['%d']
                );
            }
            $stats['featured_flag_fixed']++;
        }
        // If exactly 1 featured row exists, check it's valid (file exists)
        if (count($featured_rows) === 1) {
            $feat_row = array_values($featured_rows)[0];
            $feat_file = ltrim($feat_row->file, '/');
            $feat_abs = $uploads_basedir . '/' . $feat_file;
            if (!file_exists($feat_abs)) {
                $issues[] = "featured=1 on GD #" . $feat_row->$gd_id_col . " but file missing: " . basename($feat_file);
                // Move featured flag to first attachment
                if (!$dry_run) {
                    $wpdb->update(
                        $attachments_table,
                        ['featured' => 0],
                        [$gd_id_col => $feat_row->$gd_id_col],
                        ['%d'],
                        ['%d']
                    );
                    $wpdb->update(
                        $attachments_table,
                        ['featured' => 1],
                        [$gd_id_col => $first_att_id],
                        ['%d'],
                        ['%d']
                    );
                }
                $stats['featured_flag_fixed']++;
            }
        }

        // ----------------------------------------------------------
        // Check 2: featured_image column in detail table
        // ----------------------------------------------------------
        if ($has_featured_image_col) {
            $current_featured = $wpdb->get_var($wpdb->prepare(
                "SELECT featured_image FROM `$detail_table` WHERE post_id = %d",
                $post_id
            ));

            // The featured_image should match the file path of the featured attachment
            // Find which attachment currently has featured=1 (after our fix above)
            $expected_file = $first_att->file;

            // Re-query to get the actual featured row after fixes
            if (!$dry_run) {
                $current_featured_att = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $attachments_table WHERE post_id = %d AND featured = 1 LIMIT 1",
                    $post_id
                ));
                if ($current_featured_att) {
                    $expected_file = $current_featured_att->file;
                }
            }

            $expected_url = $uploads_baseurl . $expected_file;

            if (empty($current_featured)) {
                $issues[] = "featured_image column is empty";
                if (!$dry_run) {
                    $wpdb->update(
                        $detail_table,
                        ['featured_image' => $expected_url],
                        ['post_id' => $post_id],
                        ['%s'],
                        ['%d']
                    );
                }
                $stats['featured_image_col_fixed']++;
            } elseif ($current_featured !== $expected_url) {
                // Check if the current featured_image URL points to a valid file
                $current_relative = '';
                if (strpos($current_featured, $uploads_baseurl) === 0) {
                    $current_relative = substr($current_featured, strlen($uploads_baseurl) + 1);
                } elseif (preg_match('#/wp-content/uploads/(.+)$#', $current_featured, $m)) {
                    $current_relative = $m[1];
                }

                $current_abs = !empty($current_relative) ? $uploads_basedir . '/' . $current_relative : '';
                $current_file_exists = !empty($current_abs) && file_exists($current_abs);

                if (!$current_file_exists) {
                    $issues[] = "featured_image references missing file: " . basename($current_featured);
                    if (!$dry_run) {
                        $wpdb->update(
                            $detail_table,
                            ['featured_image' => $expected_url],
                            ['post_id' => $post_id],
                            ['%s'],
                            ['%d']
                        );
                    }
                    $stats['featured_image_col_fixed']++;
                }
            }
        }

        // ----------------------------------------------------------
        // Check 3: _thumbnail_id in wp_postmeta
        // ----------------------------------------------------------
        $thumbnail_id = get_post_meta($post_id, '_thumbnail_id', true);

        if (!empty($thumbnail_id)) {
            $thumb_post = get_post($thumbnail_id);
            if (!$thumb_post) {
                $issues[] = "_thumbnail_id=$thumbnail_id points to deleted WP attachment";

                // Find WP attachment for the first GD attachment
                $new_thumb_id = find_wp_attachment_by_file($wpdb, $first_att_file);
                if ($new_thumb_id) {
                    if (!$dry_run) {
                        update_post_meta($post_id, '_thumbnail_id', $new_thumb_id);
                    }
                    $issues[count($issues) - 1] .= " -> fixed to WP #$new_thumb_id";
                    $stats['thumbnail_id_fixed']++;
                } else {
                    $issues[count($issues) - 1] .= " (no WP attachment found for replacement)";
                }
            }
        } else {
            // No _thumbnail_id set at all — try to set one
            $new_thumb_id = find_wp_attachment_by_file($wpdb, $first_att_file);
            if ($new_thumb_id) {
                $issues[] = "_thumbnail_id not set — setting to WP #$new_thumb_id";
                if (!$dry_run) {
                    update_post_meta($post_id, '_thumbnail_id', $new_thumb_id);
                }
                $stats['thumbnail_id_fixed']++;
            }
        }

        // ----------------------------------------------------------
        // Report
        // ----------------------------------------------------------
        if (!empty($issues)) {
            $stats['posts_with_issues']++;
            $action = $dry_run ? 'WOULD FIX' : 'FIXED';
            echo str_repeat('-', 60) . "\n";
            echo "Post: $title (ID: $post_id) — " . count($gd_attachments) . " GD attachments\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($issues as $issue) {
                echo "  [$action] $issue\n";
            }
            echo "\n";
        }
    }
}

// ============================================================
// Summary
// ============================================================
echo str_repeat('=', 70) . "\n";
echo "Featured Image Repair Summary\n";
echo str_repeat('=', 70) . "\n";
echo "  Posts scanned:             {$stats['posts_scanned']}\n";
echo "  Posts with issues:         {$stats['posts_with_issues']}\n";
echo "  Posts with no attachments: {$stats['no_attachments']}\n";
if ($dry_run) {
    echo "  featured=1 flags to fix:   {$stats['featured_flag_fixed']}\n";
    echo "  featured_image cols to fix:{$stats['featured_image_col_fixed']}\n";
    echo "  _thumbnail_id to fix:      {$stats['thumbnail_id_fixed']}\n";
    echo "\n*** DRY RUN COMPLETE — no changes were made ***\n";
    echo "Run without DRY_RUN=1 to apply repairs.\n";
} else {
    echo "  featured=1 flags fixed:    {$stats['featured_flag_fixed']}\n";
    echo "  featured_image cols fixed: {$stats['featured_image_col_fixed']}\n";
    echo "  _thumbnail_id fixed:       {$stats['thumbnail_id_fixed']}\n";
}

// ============================================================
// Write to file if OUTPUT_FILE set
// ============================================================
if ($output_file) {
    $report = ob_get_clean();
    $result = file_put_contents($output_file, $report);
    if ($result === false) {
        fwrite(STDERR, "Error: Failed to write to $output_file\n");
        exit(1);
    }
    echo "Report written to: $output_file\n";
}
