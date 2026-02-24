<?php
/**
 * GeoDirectory Image Deduplication Script
 *
 * Each time a GeoDirectory CSV import runs, GD creates revision copies of
 * images (e.g., image.jpg → image-1.jpg, image-2.jpg). WordPress also
 * generates resolution variants for each (-1024x448.jpg, -150x150.jpg, etc.).
 *
 * After multiple imports, the GD attachments table points to the LATEST
 * revision (e.g., -3), while earlier revisions (original, -1, -2) are
 * orphaned in the WP media library. This script:
 *   1. For each GD attachment with a -N suffix, scans the uploads directory
 *      for the original file and all revision siblings
 *   2. Updates the GD attachment row to point to the original
 *   3. Deletes all revision copies (-1, -2, -3, etc.) and their resolution
 *      variants from the filesystem (and WP media library if tracked)
 *   4. Rebuilds the post_images field in the GD detail table
 *
 * Usage:
 *   wp eval-file dedup-images.php
 *
 * Options (set as environment variables):
 *   CPT_NAME=name       CPT display name or post_type slug (e.g., "Food and Drink") — REQUIRED
 *   DRY_RUN=1           Preview changes without executing (default: 0)
 *   POST_TITLE=title    Filter to a single post title for testing
 *   OUTPUT_FILE=path    Write report to file instead of stdout
 *   REGISTER_MEDIA=1    Register GD images into the WP Media Library (default: 0)
 *   AUDIT_IMAGES=1      Audit post_images: verify files exist on disk and in media library (default: 0)
 *
 * Examples:
 *   CPT_NAME="Food and Drink" DRY_RUN=1 POST_TITLE="Zalatina Foods" wp eval-file dedup-images.php
 *   CPT_NAME="Food and Drink" DRY_RUN=1 REGISTER_MEDIA=1 wp eval-file dedup-images.php
 *   CPT_NAME="Food and Drink" REGISTER_MEDIA=1 wp eval-file dedup-images.php
 *   CPT_NAME="Food and Drink" AUDIT_IMAGES=1 wp eval-file dedup-images.php
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

// Check if GeoDirectory is active
if (!class_exists('GeoDirectory')) {
    die("Error: GeoDirectory plugin is not active.\n");
}

// ============================================================
// Parse environment variables
// ============================================================
$cpt_filter = getenv('CPT_NAME') ?: '';
$dry_run = !empty(getenv('DRY_RUN'));
$post_title_filter = getenv('POST_TITLE') ?: null;
$output_file = getenv('OUTPUT_FILE') ?: null;
$register_media = !empty(getenv('REGISTER_MEDIA'));
$audit_images = !empty(getenv('AUDIT_IMAGES'));

if (empty($cpt_filter)) {
    die("Error: CPT_NAME is required.\nUsage: CPT_NAME=\"Food and Drink\" DRY_RUN=1 wp eval-file dedup-images.php\n");
}

// ============================================================
// Helper: resolve CPT_NAME to a registered GD post_type
// ============================================================
function resolve_cpt_post_type($cpt_filter) {
    $gd_post_types = get_option('geodir_post_types', []);

    // Fallback: detect GD CPTs from registered taxonomies
    if (empty($gd_post_types)) {
        $all_taxonomies = get_taxonomies([], 'objects');
        foreach ($all_taxonomies as $tax_name => $tax_obj) {
            if (preg_match('/^(gd_\w+)category$/', $tax_name, $matches)) {
                $pt = $matches[1];
                if (!isset($gd_post_types[$pt])) {
                    $gd_post_types[$pt] = ['detected' => true];
                }
            }
        }
    }

    if (empty($gd_post_types)) {
        die("Error: No GeoDirectory post types found.\n");
    }

    $filter_lower = strtolower($cpt_filter);

    foreach ($gd_post_types as $post_type => $settings) {
        if (strtolower($post_type) === $filter_lower) {
            return $post_type;
        }
        $pt_obj = get_post_type_object($post_type);
        if ($pt_obj && strtolower($pt_obj->labels->name) === $filter_lower) {
            return $post_type;
        }
    }

    $available = [];
    foreach ($gd_post_types as $post_type => $settings) {
        $pt_obj = get_post_type_object($post_type);
        $name = $pt_obj ? $pt_obj->labels->name : $post_type;
        $available[] = "  - $name ($post_type)";
    }
    die("Error: CPT '$cpt_filter' not found.\nAvailable CPTs:\n" . implode("\n", $available) . "\n");
}

// ============================================================
// Helper: parse an attachment filename into base, ext, revision
// Resolution variants like -1024x448 are NOT revisions.
// ============================================================
function parse_attachment_filename($filename) {
    $basename = basename($filename);

    $dot_pos = strrpos($basename, '.');
    if ($dot_pos === false) {
        return ['base' => $basename, 'ext' => '', 'revision' => null, 'dir' => ''];
    }

    $name = substr($basename, 0, $dot_pos);
    $ext = substr($basename, $dot_pos); // includes the dot
    $dir = dirname($filename);
    $dir = ltrim($dir, '/');
    if ($dir === '.') $dir = '';

    // Check for trailing -N revision suffix
    // Must NOT match resolution variants like -1024x448, -150x150, etc.
    if (preg_match('/^(.+)-(\d+)$/', $name, $matches)) {
        // Verify this isn't a resolution variant (NxN pattern on the number)
        // Resolution variants have format: base-WIDTHxHEIGHT.ext
        // A revision is just: base-N.ext where N is a small integer
        return [
            'base' => $matches[1],
            'ext' => $ext,
            'revision' => intval($matches[2]),
            'dir' => $dir,
        ];
    }

    return ['base' => $name, 'ext' => $ext, 'revision' => null, 'dir' => $dir];
}

// ============================================================
// Helper: query GD attachments for a post
// ============================================================
function get_post_gd_attachments($wpdb, $post_id) {
    $table = $wpdb->prefix . 'geodir_attachments';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE post_id = %d AND mime_type LIKE 'image/%%' ORDER BY menu_order ASC",
        $post_id
    ));
}

// ============================================================
// Helper: find revision family on the FILESYSTEM
// Given a base name and directory, glob for:
//   base.ext (original), base-1.ext, base-2.ext, etc.
// Ignores resolution variants (base-NNNxNNN.ext)
// Returns array of ['file' => relative_path, 'revision' => int|null, 'abs_path' => string]
// ============================================================
function find_revision_family_on_disk($uploads_basedir, $dir, $base, $ext) {
    $abs_dir = $uploads_basedir . '/' . $dir;
    if (!is_dir($abs_dir)) {
        return [];
    }

    // Glob for base*.ext — this catches base.ext, base-1.ext, base-2.ext, etc.
    // but also catches base-1024x448.ext (resolution variants) — we filter those out
    $pattern = $abs_dir . '/' . $base . '*' . $ext;
    $files = glob($pattern);

    $family = [];
    foreach ($files as $abs_path) {
        $fname = basename($abs_path);

        // Skip resolution variants: base-NNNxNNN.ext or base-N-NNNxNNN.ext
        if (preg_match('/-\d+x\d+' . preg_quote($ext) . '$/', $fname)) {
            continue;
        }

        $parsed = parse_attachment_filename($fname);

        // Must match exact base name
        if ($parsed['base'] !== $base || $parsed['ext'] !== $ext) {
            continue;
        }

        $relative = $dir . '/' . $fname;
        $family[] = [
            'file' => $relative,
            'revision' => $parsed['revision'],
            'abs_path' => $abs_path,
        ];
    }

    return $family;
}

// ============================================================
// Helper: delete a file and all its WP resolution variants
// Returns number of files deleted
// ============================================================
function delete_image_and_variants($abs_path, $dry_run) {
    $dir = dirname($abs_path);
    $basename = basename($abs_path);
    $dot_pos = strrpos($basename, '.');
    $name = substr($basename, 0, $dot_pos);
    $ext = substr($basename, $dot_pos);

    // Find resolution variants: name-NNNxNNN.ext
    $variant_pattern = $dir . '/' . $name . '-*x*' . $ext;
    $variants = glob($variant_pattern);

    // Filter to only actual resolution variants (not other files)
    $to_delete = [$abs_path]; // the main file
    foreach ($variants as $variant) {
        $vname = basename($variant);
        if (preg_match('/' . preg_quote($name, '/') . '-\d+x\d+' . preg_quote($ext, '/') . '$/', $vname)) {
            $to_delete[] = $variant;
        }
    }

    $count = 0;
    foreach ($to_delete as $file) {
        if (file_exists($file)) {
            if (!$dry_run) {
                unlink($file);
            }
            $count++;
        }
    }
    return $count;
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
// Resolve CPT
// ============================================================
$post_type = resolve_cpt_post_type($cpt_filter);
$pt_obj = get_post_type_object($post_type);
$cpt_display = $pt_obj ? $pt_obj->labels->name : $post_type;

global $wpdb;

// Discover GD attachments table column names
$attachments_table = $wpdb->prefix . 'geodir_attachments';
$sample_row = $wpdb->get_row("SELECT * FROM $attachments_table LIMIT 1");
$gd_id_col = 'ID'; // default fallback
if ($sample_row) {
    $cols = array_keys(get_object_vars($sample_row));
    foreach ($cols as $col) {
        if (strtolower($col) === 'id') {
            $gd_id_col = $col;
            break;
        }
    }
}

// Get uploads base directory
$upload_dir = wp_get_upload_dir();
$uploads_basedir = $upload_dir['basedir'];
$uploads_baseurl = $upload_dir['baseurl'];

// ============================================================
// Start output buffering if OUTPUT_FILE set
// ============================================================
if ($output_file) {
    ob_start();
}

// ============================================================
// Report header
// ============================================================
echo "GeoDirectory Image Deduplication\n";
echo "================================\n";
echo "CPT: $cpt_display ($post_type)\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Uploads: $uploads_basedir\n";
echo "GD attachments PK: $gd_id_col\n";
if ($dry_run) {
    echo "*** DRY RUN — no changes will be made ***\n";
}
if ($register_media) {
    echo "REGISTER_MEDIA: will register GD images into WP Media Library\n";
}
if ($audit_images) {
    echo "AUDIT_IMAGES: will verify post_images against filesystem and media library\n";
}
if ($post_title_filter) {
    echo "Filter: POST_TITLE=\"$post_title_filter\"\n";
}
echo "\n";

// Load WP image functions needed for media registration
if ($register_media) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
}

// ============================================================
// Query posts
// ============================================================
$query_args = [
    'post_type' => $post_type,
    'posts_per_page' => -1,
    'post_status' => 'any',
    'orderby' => 'title',
    'order' => 'ASC',
];

$posts = get_posts($query_args);

// Filter by title if POST_TITLE is set
if ($post_title_filter) {
    $filter_lower = strtolower($post_title_filter);
    $posts = array_filter($posts, function($p) use ($filter_lower) {
        return strtolower($p->post_title) === $filter_lower;
    });

    if (empty($posts)) {
        die("Error: No post found with title \"$post_title_filter\" in CPT $cpt_display.\n");
    }
}

echo "Found " . count($posts) . " post(s) to scan.\n\n";

// ============================================================
// Statistics
// ============================================================
$stats = [
    'posts_scanned' => 0,
    'posts_with_dupes' => 0,
    'gd_attachments_updated' => 0,
    'files_deleted' => 0,
    'wp_attachments_cleaned' => 0,
    'already_clean' => 0,
    'originals_missing' => 0,
    'post_images_updated' => 0,
    'featured_images_fixed' => 0,
    'media_registered' => 0,
    'media_already_registered' => 0,
    'media_register_failed' => 0,
];

// ============================================================
// Process each post
// ============================================================
$detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';

foreach ($posts as $post) {
    $stats['posts_scanned']++;
    $post_id = $post->ID;
    $title = $post->post_title;

    // Get GD attachments for this post
    $attachments = get_post_gd_attachments($wpdb, $post_id);

    if (empty($attachments)) {
        if ($post_title_filter) {
            echo "  Post: $title (ID: $post_id) — no image attachments in geodir_attachments\n\n";
        }
        continue;
    }

    $post_has_changes = false;
    $old_wp_ids = []; // for featured image fixup: old_wp_id => winner info

    // Diagnostic header for single-post filter
    if ($post_title_filter) {
        echo str_repeat('-', 70) . "\n";
        echo "Post: $title (ID: $post_id) — " . count($attachments) . " GD attachments\n";
        echo str_repeat('-', 70) . "\n";

        $cols = array_keys(get_object_vars($attachments[0]));
        echo "  GD columns: " . implode(', ', $cols) . "\n\n";
    }

    foreach ($attachments as $att) {
        $gd_row_id = $att->$gd_id_col;
        $parsed = parse_attachment_filename($att->file);

        // For ALL attachments (with or without revision suffix), scan disk
        // for orphaned revision siblings. Even [CLEAN] originals may have
        // leftover -1, -2, -3 files from previous imports.
        $scan_base = $parsed['base'];
        $scan_ext = $parsed['ext'];
        $scan_dir = $parsed['dir'];

        $family = find_revision_family_on_disk($uploads_basedir, $scan_dir, $scan_base, $scan_ext);

        // Count how many revision siblings exist on disk (exclude the original)
        $revision_members = array_filter($family, function($fm) { return $fm['revision'] !== null; });

        // A file with a -N suffix is only a real revision if the family has 2+ members.
        // If it's alone (family size 1), the -N is just part of the natural filename
        // (e.g., cape-air-2025.png, review_oct-2022.jpg).
        if (count($family) <= 1) {
            $stats['already_clean']++;
            if ($post_title_filter) {
                echo "  [CLEAN] " . basename($att->file) . " (GD #$gd_row_id)\n";
            }
            continue;
        }

        if ($parsed['revision'] === null && empty($revision_members)) {
            // Truly clean — original with no orphaned revisions
            $stats['already_clean']++;
            if ($post_title_filter) {
                echo "  [CLEAN] " . basename($att->file) . " (GD #$gd_row_id)\n";
            }
            continue;
        }

        if ($post_title_filter) {
            $tag = $parsed['revision'] !== null ? "[REVISION]" : "[ORPHANS]";
            $rev_info = $parsed['revision'] !== null ? " rev -{$parsed['revision']}," : "";
            echo "  $tag " . basename($att->file) . " (GD #$gd_row_id,$rev_info " . count($revision_members) . " revision(s) on disk)\n";
            echo "    Filesystem family for {$scan_base}{$scan_ext} in {$scan_dir}/:\n";
            if (empty($family)) {
                echo "      (none found)\n";
            }
            foreach ($family as $fm) {
                $rev_label = $fm['revision'] !== null ? "rev -{$fm['revision']}" : "(original)";
                echo "      {$fm['file']} $rev_label\n";
            }
        }

        // Find the winner: the original (no revision suffix)
        $winner = null;
        foreach ($family as $fm) {
            if ($fm['revision'] === null) {
                $winner = $fm;
                break;
            }
        }

        // If no original on disk, winner stays as current file (lowest revision)
        if ($winner === null) {
            $lowest_rev = PHP_INT_MAX;
            foreach ($family as $fm) {
                if ($fm['revision'] !== null && $fm['revision'] < $lowest_rev) {
                    $lowest_rev = $fm['revision'];
                    $winner = $fm;
                }
            }
        }

        if ($winner === null) {
            $stats['originals_missing']++;
            echo "  WARNING: No files found on disk for " . basename($att->file) . " — skipping\n";
            continue;
        }

        if (!$post_has_changes) {
            $post_has_changes = true;
            $stats['posts_with_dupes']++;
            if (!$post_title_filter) {
                echo str_repeat('-', 70) . "\n";
                echo "Post: $title (ID: $post_id)\n";
                echo str_repeat('-', 70) . "\n";
            }
        }

        $winner_label = $winner['revision'] === null ? "(original)" : "(rev -{$winner['revision']})";
        echo "    Winner: " . basename($winner['file']) . " $winner_label\n";

        // Update GD attachment row to point to the winner (if needed)
        $old_file = $att->file;
        $new_file = '/' . ltrim($winner['file'], '/');

        if ($old_file !== $new_file) {
            $stats['gd_attachments_updated']++;
            if ($dry_run) {
                echo "    [WOULD UPDATE] GD #$gd_row_id: " . basename($old_file) . " -> " . basename($new_file) . "\n";
            } else {
                $wpdb->update(
                    $attachments_table,
                    ['file' => $new_file],
                    [$gd_id_col => $gd_row_id],
                    ['%s'],
                    ['%d']
                );
                echo "    [UPDATE] GD #$gd_row_id: " . basename($old_file) . " -> " . basename($new_file) . "\n";
            }
        }

        // Delete all losers (every family member except the winner)
        foreach ($family as $fm) {
            if ($fm['file'] === $winner['file']) {
                continue;
            }

            $rev_label = $fm['revision'] !== null ? "rev -{$fm['revision']}" : "(original)";
            $file_count = 0;

            // Try WP attachment deletion first (handles DB + files + variants)
            $wp_id = find_wp_attachment_by_file($wpdb, $fm['file']);

            if ($dry_run) {
                // Count files that would be deleted (main + resolution variants)
                $dir = dirname($fm['abs_path']);
                $bname = basename($fm['abs_path']);
                $dot = strrpos($bname, '.');
                $stem = substr($bname, 0, $dot);
                $fext = substr($bname, $dot);
                $variants = glob($dir . '/' . $stem . '-*x*' . $fext);
                $variant_count = 0;
                foreach ($variants as $v) {
                    if (preg_match('/' . preg_quote($stem, '/') . '-\d+x\d+' . preg_quote($fext, '/') . '$/', basename($v))) {
                        $variant_count++;
                    }
                }
                $file_count = 1 + $variant_count; // main + variants

                $wp_note = $wp_id ? "WP #$wp_id + " : "";
                echo "    [WOULD DELETE] " . basename($fm['file']) . " $rev_label ({$wp_note}{$file_count} files)\n";
            } else {
                if ($wp_id) {
                    // wp_delete_attachment with force_delete=true removes post + files + variants
                    wp_delete_attachment($wp_id, true);
                    $stats['wp_attachments_cleaned']++;

                    // Check if files actually got cleaned (sometimes WP misses them)
                    if (file_exists($fm['abs_path'])) {
                        $file_count = delete_image_and_variants($fm['abs_path'], false);
                    }

                    echo "    [DELETE] " . basename($fm['file']) . " $rev_label (WP #$wp_id)\n";
                } else {
                    // No WP attachment — delete files directly
                    $file_count = delete_image_and_variants($fm['abs_path'], false);
                    echo "    [DELETE] " . basename($fm['file']) . " $rev_label ($file_count files)\n";
                }
            }

            $stats['files_deleted'] += $file_count;
        }
    }

    if (!$post_has_changes) {
        continue;
    }

    // Rebuild post_images field (only if column exists in detail table)
    $has_post_images_col = $wpdb->get_var(
        "SHOW COLUMNS FROM `$detail_table` LIKE 'post_images'"
    );

    if ($has_post_images_col) {
        if (!$dry_run) {
            $remaining = get_post_gd_attachments($wpdb, $post_id);
            $image_parts = [];
            foreach ($remaining as $att) {
                $url = $uploads_baseurl . $att->file;
                $att_id = $att->$gd_id_col;
                $att_title = isset($att->title) ? ($att->title ?: '') : '';
                $att_caption = isset($att->caption) ? ($att->caption ?: '') : '';
                $image_parts[] = "$url|$att_id|$att_title|$att_caption";
            }

            $new_post_images = implode('::', $image_parts);

            $wpdb->update(
                $detail_table,
                ['post_images' => $new_post_images],
                ['post_id' => $post_id],
                ['%s'],
                ['%d']
            );

            echo "  [UPDATE] post_images rebuilt (" . count($remaining) . " images)\n";
        } else {
            echo "  [WOULD UPDATE] post_images would be rebuilt\n";
        }
        $stats['post_images_updated']++;
    } else {
        if ($post_title_filter) {
            echo "  [SKIP] post_images column not found in $detail_table\n";
        }
    }

    // Check featured image — if it points to a deleted WP attachment, find the winner
    $thumbnail_id = get_post_meta($post_id, '_thumbnail_id', true);
    if ($thumbnail_id) {
        $thumb_exists = get_post($thumbnail_id);
        if (!$thumb_exists) {
            $remaining = $remaining ?? get_post_gd_attachments($wpdb, $post_id);
            if (!empty($remaining)) {
                $first_file = ltrim($remaining[0]->file, '/');
                $new_thumb_id = find_wp_attachment_by_file($wpdb, $first_file);
                if ($new_thumb_id) {
                    if ($dry_run) {
                        echo "  [WOULD UPDATE] _thumbnail_id: $thumbnail_id -> $new_thumb_id\n";
                    } else {
                        update_post_meta($post_id, '_thumbnail_id', $new_thumb_id);
                        echo "  [UPDATE] _thumbnail_id: $thumbnail_id -> $new_thumb_id\n";
                    }
                    $stats['featured_images_fixed']++;
                }
            }
        }
    }

    echo "\n";
}

// ============================================================
// Register GD images into WP Media Library
// ============================================================
if ($register_media) {
    echo str_repeat('=', 70) . "\n";
    echo "Media Library Registration\n";
    echo str_repeat('=', 70) . "\n\n";

    foreach ($posts as $post) {
        $post_id = $post->ID;
        $title = $post->post_title;
        $gd_attachments = get_post_gd_attachments($wpdb, $post_id);

        if (empty($gd_attachments)) {
            continue;
        }

        $post_registered = 0;

        foreach ($gd_attachments as $att) {
            $gd_row_id = $att->$gd_id_col;
            $relative_file = ltrim($att->file, '/');
            $abs_file = $uploads_basedir . '/' . $relative_file;

            // Check if already registered in WP Media Library
            $existing_wp_id = find_wp_attachment_by_file($wpdb, $relative_file);
            if ($existing_wp_id) {
                $stats['media_already_registered']++;
                continue;
            }

            // Verify file exists on disk
            if (!file_exists($abs_file)) {
                echo "  [MISSING] " . basename($att->file) . " — file not found on disk\n";
                $stats['media_register_failed']++;
                continue;
            }

            // Determine MIME type
            $mime_type = $att->mime_type ?: mime_content_type($abs_file);
            $filename = basename($att->file);
            $att_title = !empty($att->title) ? $att->title : pathinfo($filename, PATHINFO_FILENAME);

            if ($dry_run) {
                if ($post_registered === 0) {
                    echo "Post: $title (ID: $post_id)\n";
                }
                echo "  [WOULD REGISTER] $filename -> WP Media Library\n";
                $post_registered++;
                $stats['media_registered']++;
                continue;
            }

            // Create the WP attachment post
            $attachment_data = [
                'post_mime_type' => $mime_type,
                'post_title'    => $att_title,
                'post_content'  => '',
                'post_status'   => 'inherit',
                'guid'          => $uploads_baseurl . '/' . $relative_file,
            ];

            $wp_attach_id = wp_insert_attachment($attachment_data, $abs_file, $post_id);

            if (is_wp_error($wp_attach_id) || !$wp_attach_id) {
                $error = is_wp_error($wp_attach_id) ? $wp_attach_id->get_error_message() : 'unknown error';
                echo "  [FAILED] $filename — $error\n";
                $stats['media_register_failed']++;
                continue;
            }

            // Generate attachment metadata (reads existing thumbnails, creates missing ones)
            $metadata = wp_generate_attachment_metadata($wp_attach_id, $abs_file);
            wp_update_attachment_metadata($wp_attach_id, $metadata);

            if ($post_registered === 0) {
                echo "Post: $title (ID: $post_id)\n";
            }
            echo "  [REGISTER] $filename -> WP #$wp_attach_id\n";
            $post_registered++;
            $stats['media_registered']++;
        }

        if ($post_registered > 0) {
            echo "\n";
        }
    }
}

// ============================================================
// Audit post_images: verify filesystem + media library
// ============================================================
if ($audit_images) {
    echo str_repeat('=', 70) . "\n";
    echo "Image Audit: post_images vs Filesystem vs Media Library\n";
    echo str_repeat('=', 70) . "\n\n";

    $audit_stats = [
        'posts_audited' => 0,
        'posts_with_issues' => 0,
        'images_total' => 0,
        'images_ok' => 0,
        'images_missing_disk' => 0,
        'images_missing_media' => 0,
        'images_missing_both' => 0,
        'gd_attachments_total' => 0,
        'gd_missing_disk' => 0,
        'gd_missing_media' => 0,
        'gd_not_in_post_images' => 0,
        'post_images_not_in_gd' => 0,
    ];

    // Check if post_images column exists
    $has_post_images_col = $wpdb->get_var(
        "SHOW COLUMNS FROM `$detail_table` LIKE 'post_images'"
    );

    if (!$has_post_images_col) {
        echo "  Note: post_images column not found in $detail_table\n";
        echo "  Auditing GD attachments against filesystem and media library only.\n\n";
    }

    foreach ($posts as $post) {
        $post_id = $post->ID;
        $title = $post->post_title;
        $audit_stats['posts_audited']++;

        // Get post_images from detail table (if column exists)
        $post_images_raw = '';
        if ($has_post_images_col) {
            $post_images_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT post_images FROM `$detail_table` WHERE post_id = %d",
                $post_id
            ));
        }

        // Get GD attachments for cross-reference
        $gd_attachments = get_post_gd_attachments($wpdb, $post_id);
        $gd_files = [];
        foreach ($gd_attachments as $att) {
            $gd_files[ltrim($att->file, '/')] = $att;
        }
        $audit_stats['gd_attachments_total'] += count($gd_attachments);

        // Parse post_images: URL|ID|TITLE|DESC::URL|ID|TITLE|DESC::...
        $post_image_entries = [];
        if (!empty($post_images_raw)) {
            $parts = explode('::', $post_images_raw);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;
                $fields = explode('|', $part);
                $url = $fields[0] ?? '';
                if (!empty($url)) {
                    $post_image_entries[] = [
                        'url' => $url,
                        'id' => $fields[1] ?? '',
                        'title' => $fields[2] ?? '',
                        'desc' => $fields[3] ?? '',
                    ];
                }
            }
        }

        $audit_stats['images_total'] += count($post_image_entries);

        $post_issues = [];

        // Build set of relative paths from post_images URLs
        $post_image_relatives = [];

        foreach ($post_image_entries as $img) {
            $url = $img['url'];

            // Extract relative path from URL
            // URL format: https://domain/wp-content/uploads/YYYY/MM/file.jpg
            $relative = '';
            if (strpos($url, $uploads_baseurl) === 0) {
                $relative = substr($url, strlen($uploads_baseurl) + 1);
            } else {
                // Try to extract from /wp-content/uploads/ portion
                if (preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
                    $relative = $m[1];
                }
            }

            if (empty($relative)) {
                $post_issues[] = [
                    'type' => 'unparseable',
                    'image' => basename($url),
                    'url' => $url,
                    'on_disk' => '?',
                    'in_media' => '?',
                ];
                continue;
            }

            $post_image_relatives[$relative] = true;

            $abs_path = $uploads_basedir . '/' . $relative;
            $on_disk = file_exists($abs_path);
            $wp_id = find_wp_attachment_by_file($wpdb, $relative);
            $in_media = !empty($wp_id);

            if ($on_disk && $in_media) {
                $audit_stats['images_ok']++;
            } elseif (!$on_disk && !$in_media) {
                $audit_stats['images_missing_both']++;
                $post_issues[] = [
                    'type' => 'missing_both',
                    'image' => basename($relative),
                    'relative' => $relative,
                    'on_disk' => false,
                    'in_media' => false,
                ];
            } elseif (!$on_disk) {
                $audit_stats['images_missing_disk']++;
                $post_issues[] = [
                    'type' => 'missing_disk',
                    'image' => basename($relative),
                    'relative' => $relative,
                    'on_disk' => false,
                    'in_media' => "WP #$wp_id",
                ];
            } else {
                $audit_stats['images_missing_media']++;
                $post_issues[] = [
                    'type' => 'missing_media',
                    'image' => basename($relative),
                    'relative' => $relative,
                    'on_disk' => true,
                    'in_media' => false,
                ];
            }
        }

        // Cross-check: GD attachments not referenced in post_images
        // (only relevant when post_images column exists)
        if ($has_post_images_col) {
            foreach ($gd_files as $rel_path => $att) {
                if (!isset($post_image_relatives[$rel_path])) {
                    $audit_stats['gd_not_in_post_images']++;
                    $post_issues[] = [
                        'type' => 'gd_not_in_post_images',
                        'image' => basename($rel_path),
                        'relative' => $rel_path,
                        'note' => "GD #" . $att->$gd_id_col . " not referenced in post_images",
                    ];
                }
            }
        }

        // Check GD attachment files on disk and in media
        foreach ($gd_files as $rel_path => $att) {
            $abs_path = $uploads_basedir . '/' . $rel_path;
            if (!file_exists($abs_path)) {
                $audit_stats['gd_missing_disk']++;
                // Only add issue if not already reported via post_images
                if (!isset($post_image_relatives[$rel_path])) {
                    $post_issues[] = [
                        'type' => 'gd_missing_disk',
                        'image' => basename($rel_path),
                        'relative' => $rel_path,
                        'note' => "GD #" . $att->$gd_id_col . " file missing from disk",
                    ];
                }
            }
            $wp_id = find_wp_attachment_by_file($wpdb, $rel_path);
            if (!$wp_id) {
                $audit_stats['gd_missing_media']++;
                // When no post_images column, report missing media directly
                if (!$has_post_images_col && !isset($post_image_relatives[$rel_path])) {
                    $on_disk = file_exists($abs_path);
                    if ($on_disk) {
                        $post_issues[] = [
                            'type' => 'missing_media',
                            'image' => basename($rel_path),
                            'relative' => $rel_path,
                            'on_disk' => true,
                            'in_media' => false,
                        ];
                    }
                }
            }
        }

        // Cross-check: post_images entries not in GD attachments
        if ($has_post_images_col) {
            foreach ($post_image_relatives as $rel_path => $_) {
                if (!isset($gd_files[$rel_path])) {
                    $audit_stats['post_images_not_in_gd']++;
                    $post_issues[] = [
                        'type' => 'post_images_not_in_gd',
                        'image' => basename($rel_path),
                        'relative' => $rel_path,
                        'note' => "In post_images but no matching GD attachment row",
                    ];
                }
            }
        }

        // Report issues for this post
        if (!empty($post_issues)) {
            $audit_stats['posts_with_issues']++;
            $header_parts = ["Post: $title (ID: $post_id)"];
            if ($has_post_images_col) {
                $header_parts[] = count($post_image_entries) . " in post_images";
            }
            $header_parts[] = count($gd_attachments) . " GD attachments";

            echo str_repeat('-', 70) . "\n";
            echo implode(' — ', $header_parts) . "\n";
            echo str_repeat('-', 70) . "\n";

            foreach ($post_issues as $issue) {
                switch ($issue['type']) {
                    case 'missing_both':
                        echo "  [MISSING]       {$issue['image']} — not on disk, not in media library\n";
                        break;
                    case 'missing_disk':
                        echo "  [NO FILE]       {$issue['image']} — not on disk (but in media: {$issue['in_media']})\n";
                        break;
                    case 'missing_media':
                        echo "  [NO MEDIA]      {$issue['image']} — on disk but not in media library\n";
                        break;
                    case 'unparseable':
                        echo "  [BAD URL]       {$issue['url']}\n";
                        break;
                    case 'gd_not_in_post_images':
                        echo "  [GD ORPHAN]     {$issue['image']} — {$issue['note']}\n";
                        break;
                    case 'gd_missing_disk':
                        echo "  [GD NO FILE]    {$issue['image']} — {$issue['note']}\n";
                        break;
                    case 'post_images_not_in_gd':
                        echo "  [STALE REF]     {$issue['image']} — {$issue['note']}\n";
                        break;
                }
            }
            echo "\n";
        }
    }

    // Audit summary
    echo str_repeat('-', 70) . "\n";
    echo "Image Audit Summary\n";
    echo str_repeat('-', 70) . "\n";
    echo "  Posts audited:                  {$audit_stats['posts_audited']}\n";
    echo "  Posts with issues:              {$audit_stats['posts_with_issues']}\n";
    if ($has_post_images_col) {
        echo "  Total images in post_images:    {$audit_stats['images_total']}\n";
        echo "  Images OK (disk + media):       {$audit_stats['images_ok']}\n";
        echo "  Missing from disk:              {$audit_stats['images_missing_disk']}\n";
        echo "  Missing from media library:     {$audit_stats['images_missing_media']}\n";
        echo "  Missing from both:              {$audit_stats['images_missing_both']}\n";
    }
    echo "  GD attachments total:           {$audit_stats['gd_attachments_total']}\n";
    echo "  GD files missing from disk:     {$audit_stats['gd_missing_disk']}\n";
    echo "  GD files missing from media:    {$audit_stats['gd_missing_media']}\n";
    if ($has_post_images_col) {
        echo "  GD rows not in post_images:     {$audit_stats['gd_not_in_post_images']}\n";
        echo "  post_images not in GD table:    {$audit_stats['post_images_not_in_gd']}\n";
    }
    echo "\n";
}

// ============================================================
// Summary
// ============================================================
echo str_repeat('=', 70) . "\n";
echo "Summary\n";
echo str_repeat('=', 70) . "\n";
echo "  Posts scanned:             {$stats['posts_scanned']}\n";
echo "  Posts with revisions:      {$stats['posts_with_dupes']}\n";
echo "  Already clean (no suffix): {$stats['already_clean']}\n";
echo "  GD rows " . ($dry_run ? "to update" : "updated") . ":          {$stats['gd_attachments_updated']}\n";
echo "  Files " . ($dry_run ? "to delete" : "deleted") . ":            {$stats['files_deleted']}\n";
echo "  WP attachments cleaned:    {$stats['wp_attachments_cleaned']}\n";
echo "  Originals missing:         {$stats['originals_missing']}\n";
echo "  post_images " . ($dry_run ? "to rebuild" : "rebuilt") . ":     {$stats['post_images_updated']}\n";
echo "  Featured images " . ($dry_run ? "to fix" : "fixed") . ":      {$stats['featured_images_fixed']}\n";
if ($register_media) {
    echo "  Media " . ($dry_run ? "to register" : "registered") . ":        {$stats['media_registered']}\n";
    echo "  Media already registered:  {$stats['media_already_registered']}\n";
    if ($stats['media_register_failed'] > 0) {
        echo "  Media register failed:     {$stats['media_register_failed']}\n";
    }
}
echo "\n";

if ($dry_run) {
    echo "*** DRY RUN COMPLETE — no changes were made ***\n";
    echo "Run without DRY_RUN=1 to apply changes.\n";
} else {
    if ($stats['gd_attachments_updated'] > 0 || $stats['files_deleted'] > 0) {
        echo "Deduplication complete. Verify with:\n";
        echo "  CPT_NAME=\"$cpt_filter\" wp eval-file audit-listings.php\n";
    } else {
        echo "No revision images found — nothing to deduplicate.\n";
    }
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
    echo "Dedup report written to: $output_file\n";
}
