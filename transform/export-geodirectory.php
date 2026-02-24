<?php
/**
 * GeoDirectory Taxonomy & Fields Export Script
 *
 * Exports the current GeoDirectory CPTs, Categories, Tags, and Custom Fields
 * to a JSON file format matching gd-taxonomy-cpts.json structure.
 *
 * Usage:
 *   1. Upload this file to WordPress root
 *   2. Run via WP-CLI: wp eval-file export-geodirectory.php
 *   Or:
 *   2. Access via browser to download JSON
 *
 * Options (set as environment variables):
 *   OUTPUT_FILE=path    Write JSON to file instead of stdout
 *   VERBOSE=1           Show progress info to stderr
 *   INCLUDE_EMPTY=1     Include CPTs with no categories (default: exclude)
 *   SKIP_FIELDS=1       Skip exporting custom fields
 *
 * Examples:
 *   wp eval-file export-geodirectory.php
 *   OUTPUT_FILE=exported.json wp eval-file export-geodirectory.php
 *   VERBOSE=1 wp eval-file export-geodirectory.php
 *
 * This is useful for:
 *   - Getting actual term IDs after creating categories
 *   - Syncing your local JSON file with the live WordPress database
 *   - Backing up your taxonomy structure and custom fields
 *   - Creating a config file for transform.py and setup-geodirectory.php
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

// Parse options from environment variables
$output_file = getenv('OUTPUT_FILE') ?: null;
$verbose = !empty(getenv('VERBOSE'));
$include_empty = !empty(getenv('INCLUDE_EMPTY'));
$skip_fields = !empty(getenv('SKIP_FIELDS'));

// Check if running from CLI or browser
$is_cli = php_sapi_name() === 'cli';

// Helper function for verbose output
function verbose_log($message) {
    global $verbose, $is_cli;
    if ($verbose && $is_cli) {
        fwrite(STDERR, $message . "\n");
    }
}

// Helper function to convert GeoDirectory field to our JSON format
function format_field($row) {
    $field = [
        'htmlvar_name' => $row->htmlvar_name,
        'label' => $row->admin_title ?: $row->frontend_title,
        'field_type' => $row->field_type,
    ];

    // Add optional properties if they have values
    if (!empty($row->field_desc)) {
        $field['description'] = $row->field_desc;
    }
    if (!empty($row->placeholder_value)) {
        $field['placeholder'] = $row->placeholder_value;
    }
    if (!empty($row->default_value)) {
        $field['default_value'] = $row->default_value;
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
        $field['show_in'] = array_filter(explode(',', $row->show_in));
    } else {
        $field['show_in'] = [];
    }
    if (!empty($row->field_icon)) {
        $field['field_icon'] = $row->field_icon;
    }
    if (!empty($row->css_class)) {
        $field['css_class'] = $row->css_class;
    }
    if (isset($row->sort_order)) {
        $field['sort_order'] = intval($row->sort_order);
    }

    return $field;
}

verbose_log("GeoDirectory Taxonomy & Fields Export");
verbose_log("=====================================");

// Known GeoDirectory CPTs
$gd_post_types = get_option('geodir_post_types', []);
verbose_log("Found " . count($gd_post_types) . " CPT(s) in geodir_post_types option");

// If no registered CPTs found, try to detect from taxonomies
if (empty($gd_post_types)) {
    verbose_log("No CPTs in option, detecting from taxonomies...");
    $all_taxonomies = get_taxonomies([], 'objects');
    foreach ($all_taxonomies as $tax_name => $tax_obj) {
        if (preg_match('/^(gd_\w+)category$/', $tax_name, $matches)) {
            $post_type = $matches[1];
            if (!isset($gd_post_types[$post_type])) {
                $gd_post_types[$post_type] = ['detected' => true];
                verbose_log("  Detected: $post_type (from $tax_name)");
            }
        }
    }
}

// Initialize output structure
$output = [
    '_comment' => 'Exported from GeoDirectory. Compatible with transform.py and setup-geodirectory.php.',
    '_field_types' => 'text, textarea, html, select, multiselect, radio, checkbox, url, phone, email, file, files, datepicker, time',
    'common_fields' => [],
    'cpts' => [],
    'global_tags' => [],
];

// ============================================================
// Export Custom Fields
// ============================================================
$all_cpt_fields = []; // Store fields by CPT for later analysis

if (!$skip_fields) {
    global $wpdb;
    $fields_table = $wpdb->prefix . 'geodir_custom_fields';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$fields_table'") === $fields_table;

    if ($table_exists) {
        verbose_log("Exporting custom fields from $fields_table");

        // Get all custom fields grouped by post_type
        $all_fields = $wpdb->get_results(
            "SELECT * FROM $fields_table ORDER BY post_type, sort_order, id"
        );

        if ($all_fields) {
            // Group fields by post_type
            foreach ($all_fields as $row) {
                $pt = $row->post_type;
                if (!isset($all_cpt_fields[$pt])) {
                    $all_cpt_fields[$pt] = [];
                }
                $all_cpt_fields[$pt][] = $row;
            }

            verbose_log("  Found " . count($all_fields) . " total custom fields across " . count($all_cpt_fields) . " CPT(s)");

            // Identify common fields (same htmlvar_name across all CPTs with same settings)
            // Use gd_place (Places to Stay) as the reference CPT for labels and sort_order
            if (count($all_cpt_fields) > 1) {
                $post_types = array_keys($all_cpt_fields);
                $first_pt = isset($all_cpt_fields['gd_place']) ? 'gd_place' : $post_types[0];
                $first_pt_fields = $all_cpt_fields[$first_pt];

                foreach ($first_pt_fields as $field) {
                    $htmlvar = $field->htmlvar_name;
                    $is_common = true;

                    // Check if this field exists in all other CPTs with same type
                    foreach ($post_types as $pt) {
                        if ($pt === $first_pt) continue;

                        $found = false;
                        foreach ($all_cpt_fields[$pt] as $other_field) {
                            if ($other_field->htmlvar_name === $htmlvar &&
                                $other_field->field_type === $field->field_type) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $is_common = false;
                            break;
                        }
                    }

                    if ($is_common) {
                        $output['common_fields'][] = format_field($field);
                    }
                }

                verbose_log("  Identified " . count($output['common_fields']) . " common fields");
            }
        }
    } else {
        verbose_log("  Custom fields table not found");
    }
}

// Get list of common field names for filtering CPT-specific fields
$common_field_names = array_column($output['common_fields'], 'htmlvar_name');

// ============================================================
// Build CPT data
// ============================================================
foreach ($gd_post_types as $post_type => $settings) {
    $pt_obj = get_post_type_object($post_type);

    verbose_log("Processing CPT: $post_type");

    $cpt_data = [
        'cpt' => $pt_obj ? $pt_obj->labels->name : ucwords(str_replace(['gd_', '_'], ['', ' '], $post_type)),
        'post_type' => $post_type,
        'slug' => $pt_obj ? ($pt_obj->rewrite['slug'] ?? $post_type) : $post_type,
        'categories' => [],
        'tags' => [],
        'fields' => [],
    ];

    // Get categories for this CPT
    $cat_taxonomy = $post_type . 'category';
    if (taxonomy_exists($cat_taxonomy)) {
        $terms = get_terms([
            'taxonomy' => $cat_taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (!is_wp_error($terms)) {
            verbose_log("  Found " . count($terms) . " categories in $cat_taxonomy");
            foreach ($terms as $term) {
                $cpt_data['categories'][] = [
                    'name' => $term->name,
                    'id' => $term->term_id,
                    'slug' => $term->slug,
                    'aliases' => [], // Aliases are not stored in WP, kept for compatibility
                ];
            }
        }
    } else {
        verbose_log("  Category taxonomy $cat_taxonomy does not exist");
    }

    // Get tags for this CPT
    $tag_taxonomy = $post_type . '_tags';
    if (taxonomy_exists($tag_taxonomy)) {
        $terms = get_terms([
            'taxonomy' => $tag_taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (!is_wp_error($terms)) {
            verbose_log("  Found " . count($terms) . " tags in $tag_taxonomy");
            foreach ($terms as $term) {
                $cpt_data['tags'][] = [
                    'name' => $term->name,
                    'id' => $term->term_id,
                    'slug' => $term->slug,
                ];
            }
        }
    } else {
        verbose_log("  Tag taxonomy $tag_taxonomy does not exist");
    }

    // Add CPT-specific fields (not in common_fields)
    if (!$skip_fields && isset($all_cpt_fields[$post_type])) {
        foreach ($all_cpt_fields[$post_type] as $field_row) {
            // Skip if this field is in common_fields
            if (!in_array($field_row->htmlvar_name, $common_field_names)) {
                $cpt_data['fields'][] = format_field($field_row);
            }
        }
        verbose_log("  Found " . count($cpt_data['fields']) . " CPT-specific fields");
    }

    // Only include CPTs with categories unless INCLUDE_EMPTY is set
    if ($include_empty || !empty($cpt_data['categories'])) {
        $output['cpts'][] = $cpt_data;
    } else {
        verbose_log("  Skipping (no categories, use INCLUDE_EMPTY=1 to include)");
    }
}

// ============================================================
// Check for global/unified tag taxonomy
// ============================================================
$global_tag_taxonomies = ['gd_place_tags', 'gd_placetag'];
foreach ($global_tag_taxonomies as $tag_tax) {
    if (taxonomy_exists($tag_tax)) {
        verbose_log("Found global tag taxonomy: $tag_tax");
        $terms = get_terms([
            'taxonomy' => $tag_tax,
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (!is_wp_error($terms)) {
            verbose_log("  Found " . count($terms) . " global tags");
            foreach ($terms as $term) {
                $output['global_tags'][] = [
                    'name' => $term->name,
                    'id' => $term->term_id,
                    'slug' => $term->slug,
                ];
            }
        }
        break; // Only use first found
    }
}

// ============================================================
// Generate JSON output
// ============================================================
$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Output results
if ($output_file) {
    // Write to specified file
    $result = file_put_contents($output_file, $json . "\n");
    if ($result === false) {
        fwrite(STDERR, "Error: Failed to write to $output_file\n");
        exit(1);
    }
    verbose_log("Wrote " . strlen($json) . " bytes to $output_file");
    if ($is_cli) {
        echo "Exported to: $output_file\n";
        echo "  CPTs: " . count($output['cpts']) . "\n";
        $total_cats = array_sum(array_map(fn($c) => count($c['categories']), $output['cpts']));
        echo "  Categories: $total_cats\n";
        echo "  Global Tags: " . count($output['global_tags']) . "\n";
        echo "  Common Fields: " . count($output['common_fields']) . "\n";
        $total_cpt_fields = array_sum(array_map(fn($c) => count($c['fields']), $output['cpts']));
        echo "  CPT-specific Fields: $total_cpt_fields\n";
    }
} elseif ($is_cli) {
    // CLI output to stdout
    echo $json . "\n";
} else {
    // Browser output - offer as download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="gd-taxonomy-export.json"');
    echo $json;
}
