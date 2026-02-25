<?php
/**
 * Repair GeoDirectory Attachment Metadata
 *
 * Fixes GD attachments where the `file` column was updated (by dedup)
 * but the `metadata` column still references the old revision filename.
 * GD uses metadata to construct resolution variant URLs, so stale metadata
 * causes broken image galleries.
 *
 * Usage:
 *   wp eval-file repair-gd-metadata.php
 *
 * Options (set as environment variables):
 *   CPT_NAME=name       CPT display name or post_type slug — optional, repairs all CPTs if omitted
 *   DRY_RUN=1           Preview changes without executing (default: 0)
 *   POST_TITLE=title    Filter to a single post title
 *   OUTPUT_FILE=path    Write report to file instead of stdout
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file repair-gd-metadata.php
 *   CPT_NAME="Places to Stay" DRY_RUN=1 wp eval-file repair-gd-metadata.php
 *   CPT_NAME="Places to Stay" wp eval-file repair-gd-metadata.php
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

require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// ============================================================
// Parse environment variables
// ============================================================
$cpt_filter = getenv('CPT_NAME') ?: '';
$dry_run = !empty(getenv('DRY_RUN'));
$post_title_filter = getenv('POST_TITLE') ?: null;
$output_file = getenv('OUTPUT_FILE') ?: null;

global $wpdb;
$attachments_table = $wpdb->prefix . 'geodir_attachments';
$upload_dir = wp_get_upload_dir();
$uploads_basedir = $upload_dir['basedir'];

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
echo "GeoDirectory Metadata Repair\n";
echo "=============================\n";
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
// Build query: find GD image attachments with mismatched metadata
// ============================================================
$where_parts = ["a.mime_type LIKE 'image/%%'"];
$where_values = [];

if ($cpt_filter) {
    // Resolve CPT
    $gd_post_types = get_option('geodir_post_types', []);
    if (empty($gd_post_types)) {
        $all_taxonomies = get_taxonomies([], 'objects');
        foreach ($all_taxonomies as $tax_name => $tax_obj) {
            if (preg_match('/^(gd_\w+)category$/', $tax_name, $matches)) {
                $gd_post_types[$matches[1]] = ['detected' => true];
            }
        }
    }

    $resolved_type = null;
    $filter_lower = strtolower($cpt_filter);
    foreach ($gd_post_types as $post_type => $settings) {
        if (strtolower($post_type) === $filter_lower) {
            $resolved_type = $post_type;
            break;
        }
        $pt_obj = get_post_type_object($post_type);
        if ($pt_obj && strtolower($pt_obj->labels->name) === $filter_lower) {
            $resolved_type = $post_type;
            break;
        }
    }

    if (!$resolved_type) {
        die("Error: CPT '$cpt_filter' not found.\n");
    }

    $where_parts[] = "p.post_type = %s";
    $where_values[] = $resolved_type;
    echo "Resolved CPT: $resolved_type\n\n";
}

if ($post_title_filter) {
    $where_parts[] = "p.post_title = %s";
    $where_values[] = $post_title_filter;
}

$where_parts[] = "p.post_status = 'publish'";

$where_sql = implode(' AND ', $where_parts);
$query = "SELECT a.*, p.post_title, p.post_type
          FROM $attachments_table a
          JOIN {$wpdb->posts} p ON a.post_id = p.ID
          WHERE $where_sql
          ORDER BY p.post_title, a.menu_order";

if (!empty($where_values)) {
    $query = $wpdb->prepare($query, ...$where_values);
}

$rows = $wpdb->get_results($query);
echo "Scanning " . count($rows) . " GD image attachment(s)...\n\n";

// ============================================================
// Check each attachment for metadata mismatch
// ============================================================
$stats = [
    'scanned' => 0,
    'mismatched' => 0,
    'repaired' => 0,
    'file_missing' => 0,
    'already_ok' => 0,
];

$current_post = '';
foreach ($rows as $row) {
    $stats['scanned']++;
    $gd_row_id = $row->$gd_id_col;
    $file_col = $row->file;
    $relative = ltrim($file_col, '/');
    $abs_path = $uploads_basedir . '/' . $relative;

    // Parse metadata
    $metadata = !empty($row->metadata) ? maybe_unserialize($row->metadata) : [];
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $meta_file = $metadata['file'] ?? '';

    // Compare: the file column (without leading /) should match metadata.file
    if ($meta_file === $relative) {
        $stats['already_ok']++;
        continue;
    }

    // Mismatch found
    if (empty($meta_file)) {
        // No metadata at all — also needs rebuild
    }

    $stats['mismatched']++;

    // Show post header on first issue
    $post_label = "{$row->post_title} (ID: {$row->post_id}, {$row->post_type})";
    if ($current_post !== $post_label) {
        $current_post = $post_label;
        echo str_repeat('-', 70) . "\n";
        echo "Post: $post_label\n";
        echo str_repeat('-', 70) . "\n";
    }

    echo "  GD #{$gd_row_id}: " . basename($file_col) . "\n";
    echo "    file column:    $relative\n";
    echo "    metadata.file:  " . ($meta_file ?: '(empty)') . "\n";

    if (!file_exists($abs_path)) {
        echo "    [SKIP] File missing from disk: $abs_path\n";
        $stats['file_missing']++;
        continue;
    }

    if ($dry_run) {
        echo "    [WOULD REPAIR] Regenerate metadata from $relative\n";
    } else {
        $new_metadata = wp_generate_attachment_metadata(0, $abs_path);
        if (!empty($new_metadata)) {
            $wpdb->update(
                $attachments_table,
                ['metadata' => maybe_serialize($new_metadata)],
                [$gd_id_col => $gd_row_id],
                ['%s'],
                ['%d']
            );
            echo "    [REPAIRED] Metadata rebuilt\n";
            $stats['repaired']++;
        } else {
            echo "    [FAILED] wp_generate_attachment_metadata returned empty\n";
        }
    }
}

// ============================================================
// Summary
// ============================================================
echo "\n";
echo str_repeat('=', 70) . "\n";
echo "Metadata Repair Summary\n";
echo str_repeat('=', 70) . "\n";
echo "  Attachments scanned:   {$stats['scanned']}\n";
echo "  Already OK:            {$stats['already_ok']}\n";
echo "  Mismatched metadata:   {$stats['mismatched']}\n";
echo "  Files missing on disk: {$stats['file_missing']}\n";
if ($dry_run) {
    echo "  Would repair:          {$stats['mismatched']}\n";
    echo "\n*** DRY RUN COMPLETE — no changes were made ***\n";
    echo "Run without DRY_RUN=1 to apply repairs.\n";
} else {
    echo "  Repaired:              {$stats['repaired']}\n";
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
