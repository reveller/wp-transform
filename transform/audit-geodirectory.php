<?php
/**
 * GeoDirectory Configuration Audit Script
 *
 * Compares the live GeoDirectory site config against a JSON spec file.
 * Reports differences in custom fields, categories, and tags per CPT.
 *
 * Usage:
 *   1. Upload this file and the JSON spec to WordPress root
 *   2. Run via WP-CLI: wp eval-file audit-geodirectory.php
 *
 * Options (set as environment variables):
 *   AUDIT_FILE=path       JSON spec file (default: gd-taxonomy-new.json)
 *   OUTPUT_FILE=path      Write report to file instead of stdout
 *   CPT_NAME=name         Audit a single CPT by display name or post_type slug (default: ALL)
 *
 * Examples:
 *   wp eval-file audit-geodirectory.php
 *   CPT_NAME="Places to Stay" wp eval-file audit-geodirectory.php
 *   AUDIT_FILE=gd-taxonomy-new.json CPT_NAME=ALL OUTPUT_FILE=audit-report.txt wp eval-file audit-geodirectory.php
 */

// Load WordPress if not already loaded
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
$audit_filename = getenv('AUDIT_FILE') ?: 'gd-taxonomy-new.json';
$output_file = getenv('OUTPUT_FILE') ?: null;
$cpt_filter = getenv('CPT_NAME') ?: 'ALL';

// Resolve audit file path (absolute or relative to script dir)
if ($audit_filename[0] === '/') {
    $audit_file = $audit_filename;
} else {
    $audit_file = __DIR__ . '/' . $audit_filename;
}

if (!file_exists($audit_file)) {
    die("Error: Cannot find spec file: $audit_file\n");
}

$data = json_decode(file_get_contents($audit_file), true);
if (!$data) {
    die("Error: Failed to parse JSON spec file.\n");
}

// ============================================================
// Helper: format a DB field row to normalized array (from export-geodirectory.php)
// ============================================================
function format_field($row) {
    $field = [
        'htmlvar_name' => $row->htmlvar_name,
        'label' => $row->admin_title ?: $row->frontend_title,
        'field_type' => $row->field_type,
    ];

    if (!empty($row->field_desc)) {
        $field['description'] = $row->field_desc;
    }
    if (!empty($row->placeholder_value)) {
        $field['placeholder'] = $row->placeholder_value;
    }
    if (!empty($row->option_values)) {
        $field['options'] = $row->option_values;
    }
    if ($row->is_required == '1') {
        $field['is_required'] = true;
    } else {
        $field['is_required'] = false;
    }
    if (!empty($row->show_in)) {
        $field['show_in'] = array_values(array_filter(explode(',', $row->show_in)));
    } else {
        $field['show_in'] = [];
    }
    if (!empty($row->field_icon)) {
        $field['field_icon'] = $row->field_icon;
    }
    if (isset($row->sort_order)) {
        $field['sort_order'] = intval($row->sort_order);
    }

    return $field;
}

// ============================================================
// Helper: resolve CPT_NAME to matching CPT entries in the spec
// ============================================================
function resolve_cpts($data, $cpt_filter) {
    if (strtoupper($cpt_filter) === 'ALL') {
        return $data['cpts'];
    }

    $filter_lower = strtolower($cpt_filter);
    $matches = [];
    foreach ($data['cpts'] as $cpt) {
        if (strtolower($cpt['cpt']) === $filter_lower ||
            strtolower($cpt['post_type']) === $filter_lower) {
            $matches[] = $cpt;
        }
    }

    if (empty($matches)) {
        $available = array_map(function($c) {
            return "  - {$c['cpt']} ({$c['post_type']})";
        }, $data['cpts']);
        die("Error: CPT '$cpt_filter' not found in spec.\nAvailable CPTs:\n" . implode("\n", $available) . "\n");
    }

    return $matches;
}

// ============================================================
// Helper: merge common_fields + CPT fields (from setup-geodirectory.php)
// CPT fields override common fields with same htmlvar_name
// ============================================================
function merge_fields($common_fields, $cpt_fields) {
    $cpt_field_names = array_column($cpt_fields, 'htmlvar_name');

    $merged = [];
    foreach ($common_fields as $field) {
        $name = $field['htmlvar_name'] ?? '';
        if (!in_array($name, $cpt_field_names)) {
            $merged[$name] = $field;
        }
    }
    foreach ($cpt_fields as $field) {
        $name = $field['htmlvar_name'] ?? '';
        $merged[$name] = $field;
    }

    return $merged;
}

// ============================================================
// Helper: compare a single field property between spec and site
// ============================================================
function compare_field_property($spec_field, $site_field, $spec_key, $site_key = null) {
    if ($site_key === null) {
        $site_key = $spec_key;
    }

    $spec_val = $spec_field[$spec_key] ?? null;
    $site_val = $site_field[$site_key] ?? null;

    // Normalize for comparison
    if ($spec_key === 'is_required') {
        $spec_val = !empty($spec_val);
        $site_val = !empty($site_val);
    }

    if ($spec_key === 'show_in' || $site_key === 'show_in') {
        // Normalize arrays: sort, filter empties
        if (!is_array($spec_val)) $spec_val = [];
        if (!is_array($site_val)) $site_val = [];
        $spec_val = array_values(array_filter($spec_val, 'strlen'));
        $site_val = array_values(array_filter($site_val, 'strlen'));
        sort($spec_val);
        sort($site_val);
    }

    if ($spec_key === 'sort_order') {
        $spec_val = isset($spec_val) ? intval($spec_val) : null;
        $site_val = isset($site_val) ? intval($site_val) : null;
    }

    // Normalize nulls/empty strings
    if ($spec_val === '' || $spec_val === null) $spec_val = null;
    if ($site_val === '' || $site_val === null) $site_val = null;

    if ($spec_val === $site_val) {
        return null; // match
    }

    return [
        'property' => $spec_key,
        'spec' => $spec_val,
        'site' => $site_val,
    ];
}

// ============================================================
// Helper: format a value for display in the report
// ============================================================
function fmt_val($val) {
    if ($val === null) return '(empty)';
    if ($val === true) return 'true';
    if ($val === false) return 'false';
    if (is_array($val)) return implode(',', $val);
    return (string)$val;
}

// ============================================================
// Start output buffering if OUTPUT_FILE set
// ============================================================
if ($output_file) {
    ob_start();
}

// ============================================================
// Report header
// ============================================================
echo "GeoDirectory Configuration Audit\n";
echo "=================================\n";
echo "Spec: $audit_filename\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "\n";

// ============================================================
// Resolve which CPTs to audit
// ============================================================
$cpts_to_audit = resolve_cpts($data, $cpt_filter);
$common_fields = $data['common_fields'] ?? [];

global $wpdb;
$fields_table = $wpdb->prefix . 'geodir_custom_fields';

// Accumulate per-CPT summary stats
$summary = [];
$totals = ['fields_differ' => 0, 'fields_missing' => 0, 'fields_extra' => 0,
           'cats_missing' => 0, 'cats_extra' => 0,
           'tags_missing' => 0, 'tags_extra' => 0];

foreach ($cpts_to_audit as $cpt) {
    $post_type = $cpt['post_type'];
    $cpt_name = $cpt['cpt'];

    echo "CPT: $cpt_name ($post_type)\n";
    echo str_repeat('-', 63) . "\n";

    $cpt_stats = ['fields_differ' => 0, 'fields_missing' => 0, 'fields_extra' => 0,
                  'cats_missing' => 0, 'cats_extra' => 0,
                  'tags_missing' => 0, 'tags_extra' => 0];

    // ==========================================================
    // 1. Custom Fields
    // ==========================================================
    $spec_fields = merge_fields($common_fields, $cpt['fields'] ?? []);
    $spec_count = count($spec_fields);

    // Query live fields
    $live_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $fields_table WHERE post_type = %s ORDER BY sort_order, id",
        $post_type
    ));

    $live_fields = [];
    if ($live_rows) {
        foreach ($live_rows as $row) {
            $formatted = format_field($row);
            $live_fields[$row->htmlvar_name] = $formatted;
        }
    }
    $site_count = count($live_fields);

    echo "  Fields ($spec_count in spec, $site_count on site):\n";

    // Compare fields in spec against site
    foreach ($spec_fields as $htmlvar => $spec_field) {
        if (!isset($live_fields[$htmlvar])) {
            echo "    [MISSING]  " . str_pad($htmlvar, 25) . "not found on site\n";
            $cpt_stats['fields_missing']++;
            continue;
        }

        $site_field = $live_fields[$htmlvar];
        $diffs = [];

        // Compare each property
        $checks = [
            ['label', 'label'],
            ['field_type', 'field_type'],
            ['sort_order', 'sort_order'],
            ['is_required', 'is_required'],
            ['show_in', 'show_in'],
            ['options', 'options'],
            ['field_icon', 'field_icon'],
            ['description', 'description'],
            ['placeholder', 'placeholder'],
        ];

        foreach ($checks as $check) {
            $diff = compare_field_property($spec_field, $site_field, $check[0], $check[1]);
            if ($diff !== null) {
                $diffs[] = $diff;
            }
        }

        if (empty($diffs)) {
            $label = $spec_field['label'] ?? $htmlvar;
            $type = $spec_field['field_type'] ?? '?';
            $sort = $spec_field['sort_order'] ?? '?';
            echo "    [MATCH]    " . str_pad($htmlvar, 25) . "$label, $type, sort:$sort\n";
        } else {
            $diff_parts = [];
            foreach ($diffs as $d) {
                $diff_parts[] = "{$d['property']}: spec=" . fmt_val($d['spec']) . ", site=" . fmt_val($d['site']);
            }
            echo "    [DIFFERS]  " . str_pad($htmlvar, 25) . implode('; ', $diff_parts) . "\n";
            $cpt_stats['fields_differ']++;
        }
    }

    // Find extra fields on site not in spec
    foreach ($live_fields as $htmlvar => $site_field) {
        if (!isset($spec_fields[$htmlvar])) {
            $sort = $site_field['sort_order'] ?? '?';
            echo "    [EXTRA]    " . str_pad($htmlvar, 25) . "on site but not in spec (sort:$sort)\n";
            $cpt_stats['fields_extra']++;
        }
    }

    echo "\n";

    // ==========================================================
    // 2. Categories
    // ==========================================================
    $spec_cats = $cpt['categories'] ?? [];
    $spec_cat_count = count($spec_cats);

    $cat_taxonomy = $post_type . 'category';
    $live_cats = [];
    if (taxonomy_exists($cat_taxonomy)) {
        $terms = get_terms([
            'taxonomy' => $cat_taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $live_cats[$term->slug] = $term->name;
            }
        }
    }
    $site_cat_count = count($live_cats);

    echo "  Categories ($spec_cat_count in spec, $site_cat_count on site):\n";

    // Index spec categories by slug
    $spec_cat_slugs = [];
    foreach ($spec_cats as $cat) {
        $spec_cat_slugs[$cat['slug']] = $cat['name'];
    }

    foreach ($spec_cat_slugs as $slug => $name) {
        if (isset($live_cats[$slug])) {
            echo "    [MATCH]    " . str_pad($slug, 25) . "$name\n";
        } else {
            echo "    [MISSING]  " . str_pad($slug, 25) . "$name\n";
            $cpt_stats['cats_missing']++;
        }
    }

    foreach ($live_cats as $slug => $name) {
        if (!isset($spec_cat_slugs[$slug])) {
            echo "    [EXTRA]    " . str_pad($slug, 25) . "$name\n";
            $cpt_stats['cats_extra']++;
        }
    }

    echo "\n";

    // ==========================================================
    // 3. Tags
    // ==========================================================
    $spec_tags = $cpt['tags'] ?? [];
    $spec_tag_count = count($spec_tags);

    $tag_taxonomy = $post_type . '_tags';
    $live_tags = [];
    if (taxonomy_exists($tag_taxonomy)) {
        $terms = get_terms([
            'taxonomy' => $tag_taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $live_tags[$term->slug] = $term->name;
            }
        }
    }
    $site_tag_count = count($live_tags);

    echo "  Tags ($spec_tag_count in spec, $site_tag_count on site):\n";

    // Index spec tags by slug
    $spec_tag_slugs = [];
    foreach ($spec_tags as $tag) {
        $spec_tag_slugs[$tag['slug']] = $tag['name'];
    }

    foreach ($spec_tag_slugs as $slug => $name) {
        if (isset($live_tags[$slug])) {
            echo "    [MATCH]    " . str_pad($slug, 25) . "$name\n";
        } else {
            echo "    [MISSING]  " . str_pad($slug, 25) . "$name\n";
            $cpt_stats['tags_missing']++;
        }
    }

    foreach ($live_tags as $slug => $name) {
        if (!isset($spec_tag_slugs[$slug])) {
            echo "    [EXTRA]    " . str_pad($slug, 25) . "$name\n";
            $cpt_stats['tags_extra']++;
        }
    }

    if (empty($spec_tags) && empty($live_tags)) {
        echo "    (none defined)\n";
    }

    echo "\n";

    $summary[$cpt_name] = $cpt_stats;
    foreach ($cpt_stats as $k => $v) {
        $totals[$k] += $v;
    }
}

// ============================================================
// Summary
// ============================================================
echo "Summary\n";
echo "=======\n";

foreach ($summary as $cpt_name => $stats) {
    $parts = [];
    $parts[] = "{$stats['fields_differ']} fields differ";
    $parts[] = "{$stats['fields_missing']} fields missing";
    $parts[] = "{$stats['fields_extra']} fields extra";
    $parts[] = "{$stats['cats_missing']} cats missing";
    $parts[] = "{$stats['cats_extra']} cats extra";
    $parts[] = "{$stats['tags_missing']} tags missing";
    $parts[] = "{$stats['tags_extra']} tags extra";
    echo "  " . str_pad($cpt_name . ':', 25) . implode(', ', $parts) . "\n";
}

echo "\n";
echo "  Total: {$totals['fields_differ']} fields differ, " .
     "{$totals['fields_missing']} fields missing, " .
     "{$totals['fields_extra']} fields extra, " .
     "{$totals['cats_missing']} cats missing, " .
     "{$totals['cats_extra']} cats extra, " .
     "{$totals['tags_missing']} tags missing, " .
     "{$totals['tags_extra']} tags extra\n";

$all_clean = array_sum($totals) === 0;
echo "\n";
if ($all_clean) {
    echo "All clear — site matches spec.\n";
} else {
    echo "Differences found — review above for details.\n";
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
    echo "Audit report written to: $output_file\n";
    echo "  " . ($all_clean ? "All clear." : "Differences found.") . "\n";
}
