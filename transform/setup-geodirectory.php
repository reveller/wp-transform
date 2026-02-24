<?php
/**
 * GeoDirectory Taxonomy Setup Script
 *
 * Creates Categories, Tags, and Custom Fields in GeoDirectory
 * based on the gd-taxonomy-cpts.json configuration file.
 *
 * IMPORTANT: CPTs must be created manually via GeoDirectory admin UI:
 *   GeoDirectory > Settings > Post Types > Add New
 *
 * Usage:
 *   1. Create your CPTs in GeoDirectory admin first
 *   2. Upload this file and gd-taxonomy-cpts.json to WordPress root
 *   3. Run via WP-CLI: wp eval-file setup-geodirectory.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1      Show what would be created without making changes
 *   SKIP_CATS=1    Skip category creation
 *   SKIP_TAGS=1    Skip tag creation
 *   SKIP_FIELDS=1  Skip custom field creation
 *   JSON_FILE=path Use a different JSON config file
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file setup-geodirectory.php
 *   DRY_RUN=1 SKIP_TAGS=1 wp eval-file setup-geodirectory.php
 *   SKIP_FIELDS=1 wp eval-file setup-geodirectory.php
 *   JSON_FILE=gd-taxonomy-cpts-test.json wp eval-file setup-geodirectory.php
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    // Find wp-load.php
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
        die("Error: Could not find wp-load.php. Run this from WordPress root directory.\n");
    }
}

// Check if GeoDirectory is active
if (!class_exists('GeoDirectory')) {
    die("Error: GeoDirectory plugin is not active.\n");
}

// Parse options from environment variables (for WP-CLI compatibility)
// Usage: DRY_RUN=1 wp eval-file setup-geodirectory.php
$dry_run = !empty(getenv('DRY_RUN'));
$skip_cats = !empty(getenv('SKIP_CATS'));
$skip_tags = !empty(getenv('SKIP_TAGS'));
$skip_fields = !empty(getenv('SKIP_FIELDS'));
$json_filename = getenv('JSON_FILE') ?: 'gd-taxonomy-cpts.json';

// Configuration
$json_file = __DIR__ . '/' . $json_filename;

if (!file_exists($json_file)) {
    die("Error: Cannot find $json_filename at: $json_file\n");
}

$data = json_decode(file_get_contents($json_file), true);
if (!$data) {
    die("Error: Failed to parse JSON file.\n");
}

echo "=======================================================\n";
echo "GeoDirectory Setup Script\n";
echo "=======================================================\n";
echo "Config file: $json_filename\n";
if ($dry_run) {
    echo "*** DRY RUN MODE - No changes will be made ***\n";
}
echo "\n";

$stats = [
    'cpts_ok' => 0,
    'cpts_missing' => 0,
    'cats_created' => 0,
    'cats_existed' => 0,
    'tags_created' => 0,
    'tags_existed' => 0,
    'fields_created' => 0,
    'fields_existed' => 0,
];

// ============================================================
// STEP 1: Verify Custom Post Types
// ============================================================
// NOTE: GeoDirectory CPTs must be created via the admin UI at:
//       GeoDirectory > Settings > Post Types > Add New
// This step only verifies they exist and lists any missing CPTs.
echo "STEP 1: Custom Post Types (Verification Only)\n";
echo "---------------------------------------------------------\n";
echo "  NOTE: CPTs must be created via GeoDirectory admin UI.\n";
echo "  This step verifies existing CPTs and lists missing ones.\n\n";

$missing_cpts = [];
foreach ($data['cpts'] as $cpt) {
    $post_type = $cpt['post_type'];
    $cpt_name = $cpt['cpt'];
    $slug = $cpt['slug'];

    // Check if CPT exists AND has its detail table (fully initialized)
    global $wpdb;
    $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$detail_table'") === $detail_table;

    if (post_type_exists($post_type) && $table_exists) {
        echo "  [OK] $cpt_name ($post_type)\n";
        $stats['cpts_ok']++;
    } else {
        echo "  [MISSING] $cpt_name ($post_type, slug: $slug)\n";
        $missing_cpts[] = $cpt;
        $stats['cpts_missing']++;
    }
}

if (!empty($missing_cpts)) {
    echo "\n  ** ACTION REQUIRED **\n";
    echo "  Create the missing CPT(s) in GeoDirectory admin:\n";
    echo "  GeoDirectory > Settings > Post Types > Add New\n\n";
    echo "  Then re-run this script to create categories, tags, and fields.\n";
}
echo "\n";

// ============================================================
// STEP 2: Create Categories for each CPT
// ============================================================
if (!$skip_cats) {
    echo "STEP 2: Categories\n";
    echo "---------------------------------------------------------\n";

    foreach ($data['cpts'] as $cpt) {
        $post_type = $cpt['post_type'];
        $cpt_name = $cpt['cpt'];
        $taxonomy = $post_type . 'category'; // e.g., gd_placestostaycategory

        echo "  CPT: $cpt_name ($taxonomy)\n";

        // Ensure taxonomy exists
        if (!taxonomy_exists($taxonomy)) {
            echo "    [WARNING] Taxonomy $taxonomy does not exist yet.\n";
            echo "    You may need to create the CPT first via GeoDirectory admin.\n";
            continue;
        }

        foreach ($cpt['categories'] as $category) {
            $cat_name = $category['name'];
            $cat_slug = $category['slug'];
            $cat_id = $category['id'];

            // Check if term exists
            $existing = get_term_by('slug', $cat_slug, $taxonomy);

            if ($existing) {
                echo "    [EXISTS] $cat_name (ID: {$existing->term_id}, slug: $cat_slug)\n";
                $stats['cats_existed']++;

                // Verify ID matches expected
                if ($existing->term_id != $cat_id) {
                    echo "      [WARNING] ID mismatch! Expected $cat_id, got {$existing->term_id}\n";
                }
                continue;
            }

            if ($dry_run) {
                echo "    [WOULD CREATE] $cat_name (slug: $cat_slug)\n";
                $stats['cats_created']++;
                continue;
            }

            // Create the term
            $result = wp_insert_term($cat_name, $taxonomy, [
                'slug' => $cat_slug,
                'description' => "Category for $cpt_name listings",
            ]);

            if (is_wp_error($result)) {
                echo "    [ERROR] Failed to create $cat_name: " . $result->get_error_message() . "\n";
            } else {
                echo "    [CREATED] $cat_name (ID: {$result['term_id']}, slug: $cat_slug)\n";
                $stats['cats_created']++;

                // Note: The ID will be auto-assigned by WordPress
                // It may not match the ID in the JSON file
                if ($result['term_id'] != $cat_id) {
                    echo "      [INFO] New ID {$result['term_id']} differs from JSON ID $cat_id\n";
                    echo "      You may need to update gd-taxonomy-cpts.json\n";
                }
            }
        }
        echo "\n";
    }
} else {
    echo "STEP 2: Skipping category creation (SKIP_CATS=1)\n\n";
}

// ============================================================
// STEP 3: Create Global Tags
// ============================================================
if (!$skip_tags) {
    echo "STEP 3: Global Tags\n";
    echo "---------------------------------------------------------\n";

    $global_tags = $data['global_tags'] ?? [];

    if (empty($global_tags)) {
        echo "  No global tags defined in JSON file.\n\n";
    } else {
        // GeoDirectory uses a unified tag taxonomy or per-CPT tags
        // Check for gd_place_tags or similar
        $tag_taxonomies = [];
        foreach ($data['cpts'] as $cpt) {
            $tag_tax = $cpt['post_type'] . '_tags';
            if (taxonomy_exists($tag_tax)) {
                $tag_taxonomies[] = $tag_tax;
            }
        }

        // Also check for unified gd_place_tags
        if (taxonomy_exists('gd_place_tags')) {
            $tag_taxonomies[] = 'gd_place_tags';
        }

        if (empty($tag_taxonomies)) {
            echo "  [WARNING] No GeoDirectory tag taxonomies found.\n";
            echo "  Tags may need to be created after CPTs are set up.\n\n";
        } else {
            echo "  Found tag taxonomies: " . implode(', ', $tag_taxonomies) . "\n";

            // Create tags in the first available tag taxonomy
            // Or create in all CPT-specific tag taxonomies
            $primary_tag_tax = $tag_taxonomies[0];

            foreach ($global_tags as $tag) {
                $tag_name = $tag['name'];
                $tag_slug = $tag['slug'];
                $tag_id = $tag['id'];

                // Check if tag exists
                $existing = get_term_by('slug', $tag_slug, $primary_tag_tax);

                if ($existing) {
                    echo "  [EXISTS] $tag_name (ID: {$existing->term_id})\n";
                    $stats['tags_existed']++;
                    continue;
                }

                if ($dry_run) {
                    echo "  [WOULD CREATE] $tag_name (slug: $tag_slug)\n";
                    $stats['tags_created']++;
                    continue;
                }

                $result = wp_insert_term($tag_name, $primary_tag_tax, [
                    'slug' => $tag_slug,
                ]);

                if (is_wp_error($result)) {
                    echo "  [ERROR] Failed to create $tag_name: " . $result->get_error_message() . "\n";
                } else {
                    echo "  [CREATED] $tag_name (ID: {$result['term_id']})\n";
                    $stats['tags_created']++;
                }
            }
        }
    }
    echo "\n";
} else {
    echo "STEP 3: Skipping tag creation (SKIP_TAGS=1)\n\n";
}

// ============================================================
// STEP 4: Create Custom Fields
// ============================================================
if (!$skip_fields) {
    echo "STEP 4: Custom Fields\n";
    echo "---------------------------------------------------------\n";

    $common_fields = $data['common_fields'] ?? [];

    if (empty($common_fields)) {
        echo "  No common fields defined in JSON file.\n\n";
    } else {
        // Check if GeoDirectory custom fields function exists
        if (!function_exists('geodir_custom_field_save')) {
            echo "  [WARNING] geodir_custom_field_save() not found.\n";
            echo "  Custom fields cannot be created programmatically.\n";
            echo "  You may need to create them via GeoDirectory admin.\n\n";
        } else {
            // Create fields for each CPT
            foreach ($data['cpts'] as $cpt) {
                $post_type = $cpt['post_type'];
                $cpt_name = $cpt['cpt'];

                echo "  CPT: $cpt_name ($post_type)\n";

                // Get CPT-specific fields (override/additions)
                $cpt_fields = $cpt['fields'] ?? [];
                $cpt_field_names = array_column($cpt_fields, 'htmlvar_name');

                // Merge common fields with CPT-specific fields
                // CPT-specific fields override common fields with same name
                $all_fields = [];
                foreach ($common_fields as $field) {
                    $field_name = $field['htmlvar_name'] ?? '';
                    if (!in_array($field_name, $cpt_field_names)) {
                        $all_fields[] = $field;
                    }
                }
                $all_fields = array_merge($all_fields, $cpt_fields);

                // Get the current maximum sort_order for this CPT to place new fields after existing ones
                global $wpdb;
                $table = $wpdb->prefix . 'geodir_custom_fields';
                $max_sort = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(sort_order) FROM $table WHERE post_type = %s",
                    $post_type
                ));
                $sort_order = ($max_sort !== null) ? intval($max_sort) + 1 : 100;
                echo "    Starting sort_order: $sort_order (after existing max: " . ($max_sort ?? 'none') . ")\n";

                foreach ($all_fields as $field) {
                    $htmlvar_name = $field['htmlvar_name'] ?? '';
                    $label = $field['label'] ?? $htmlvar_name;
                    $field_type = $field['field_type'] ?? 'text';

                    if (empty($htmlvar_name)) {
                        continue; // Skip fields without a name
                    }

                    // Skip internal comment fields
                    if (strpos($htmlvar_name, '_') === 0) {
                        continue;
                    }

                    // Check if field already exists for this post type
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table WHERE post_type = %s AND htmlvar_name = %s",
                        $post_type,
                        $htmlvar_name
                    ));

                    if ($existing) {
                        echo "    [EXISTS] $label ($htmlvar_name)\n";
                        $stats['fields_existed']++;
                        continue;
                    }

                    if ($dry_run) {
                        echo "    [WOULD CREATE] $label ($htmlvar_name, type: $field_type, sort: $sort_order)\n";
                        $stats['fields_created']++;
                        if (!isset($field['sort_order'])) {
                            $sort_order++;
                        }
                        continue;
                    }

                    // Use explicit sort_order from JSON if provided, otherwise auto-increment
                    $field_sort_order = isset($field['sort_order']) ? intval($field['sort_order']) : $sort_order++;

                    // Prepare field data for GeoDirectory
                    $field_data = [
                        'post_type' => $post_type,
                        'field_type' => $field_type,
                        'admin_title' => $label,
                        'frontend_title' => $label,
                        'htmlvar_name' => $htmlvar_name,
                        'field_icon' => $field['field_icon'] ?? '',
                        'default_value' => $field['default_value'] ?? '',
                        'placeholder_value' => $field['placeholder'] ?? '',
                        'desc' => $field['description'] ?? '',
                        'is_active' => '1',
                        'is_required' => ($field['is_required'] ?? false) ? '1' : '0',
                        'option_values' => $field['options'] ?? '',
                        'show_in' => implode(',', $field['show_in'] ?? []),
                        'sort_order' => $field_sort_order,
                        'for_admin_use' => '0',
                        'css_class' => $field['css_class'] ?? '',
                    ];

                    // Map our field types to GeoDirectory field types
                    $field_type_map = [
                        'text' => 'text',
                        'textarea' => 'textarea',
                        'html' => 'html',
                        'select' => 'select',
                        'multiselect' => 'multiselect',
                        'radio' => 'radio',
                        'checkbox' => 'checkbox',
                        'url' => 'url',
                        'phone' => 'phone',
                        'email' => 'email',
                        'file' => 'file',
                        'files' => 'files',
                        'datepicker' => 'datepicker',
                        'time' => 'time',
                    ];

                    if (isset($field_type_map[$field_type])) {
                        $field_data['field_type'] = $field_type_map[$field_type];
                    }

                    // Save the field
                    $result = geodir_custom_field_save($field_data);

                    if ($result && !is_wp_error($result)) {
                        echo "    [CREATED] $label ($htmlvar_name)\n";
                        $stats['fields_created']++;
                    } else {
                        $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                        echo "    [ERROR] Failed to create $label: $error_msg\n";
                    }

                    $sort_order++;
                }
                echo "\n";
            }
        }
    }
} else {
    echo "STEP 4: Skipping custom field creation (SKIP_FIELDS=1)\n\n";
}

// ============================================================
// Summary
// ============================================================
echo "=======================================================\n";
echo "SUMMARY\n";
echo "=======================================================\n";
echo "  CPTs:       {$stats['cpts_ok']} ok, {$stats['cpts_missing']} missing\n";
echo "  Categories: {$stats['cats_created']} created, {$stats['cats_existed']} existed\n";
echo "  Tags:       {$stats['tags_created']} created, {$stats['tags_existed']} existed\n";
echo "  Fields:     {$stats['fields_created']} created, {$stats['fields_existed']} existed\n";
echo "\n";

if ($dry_run) {
    echo "*** DRY RUN COMPLETE - No changes were made ***\n";
    echo "Run without DRY_RUN=1 to apply changes.\n";
} else {
    if ($stats['cpts_missing'] > 0) {
        echo "** INCOMPLETE: {$stats['cpts_missing']} CPT(s) missing **\n";
        echo "Create them in GeoDirectory admin, then re-run this script.\n";
    } else {
        echo "Setup complete!\n";
    }
    echo "\n";
    echo "IMPORTANT: After running this script:\n";
    echo "  1. Flush permalinks: Settings > Permalinks > Save Changes\n";
    echo "  2. Verify categories in GeoDirectory > [CPT] > Categories\n";
    echo "  3. Verify custom fields in GeoDirectory > Settings > Custom Fields\n";
    echo "  4. Delete this script from your server for security\n";
}

echo "\n";
