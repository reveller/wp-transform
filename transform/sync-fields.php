<?php
/**
 * GeoDirectory Field Sync Script
 *
 * Reads JSON spec, compares against live geodir_custom_fields table,
 * and syncs admin_title, frontend_title, sort_order, and htmlvar_name
 * to match spec. No DELETEs — only UPDATEs and INSERTs.
 *
 * When a spec field isn't found by htmlvar_name, the script checks for a
 * "_rename_from" property listing legacy htmlvar_names. If a live field
 * matches one of those old names, it is flagged as a [RENAME]. Renames
 * update the geodir_custom_fields row and the column in the CPT detail table.
 *
 * If no match is found (neither by name nor _rename_from), the field is
 * created via geodir_custom_field_save() and flagged as [ADD].
 *
 * Usage:
 *   wp eval-file sync-fields.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1         Show what would change without making changes (default: 0)
 *   JSON_FILE=path    Path to spec file (default: gd-taxonomy-new.json)
 *   CPT_NAME=name     Filter to a single CPT by display name or post_type slug (default: ALL)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file sync-fields.php
 *   DRY_RUN=1 CPT_NAME="Guides" wp eval-file sync-fields.php
 *   wp eval-file sync-fields.php
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
$dry_run = !empty(getenv('DRY_RUN'));
$json_filename = getenv('JSON_FILE') ?: 'gd-taxonomy-new.json';
$cpt_filter = getenv('CPT_NAME') ?: 'ALL';

// Resolve JSON file path (absolute or relative to script dir)
if ($json_filename[0] === '/') {
    $json_file = $json_filename;
} else {
    $json_file = __DIR__ . '/' . $json_filename;
}

if (!file_exists($json_file)) {
    die("Error: Cannot find spec file: $json_file\n");
}

$data = json_decode(file_get_contents($json_file), true);
if (!$data) {
    die("Error: Failed to parse JSON spec file.\n");
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
// Helper: merge common_fields + CPT fields
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
// Helper: rename a column in the CPT detail table
// Returns true on success, error string on failure
// ============================================================
function rename_detail_column($wpdb, $post_type, $old_name, $new_name) {
    $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';

    // Verify detail table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$detail_table'") !== $detail_table) {
        return "detail table $detail_table not found";
    }

    // Get the current column definition
    $col_info = $wpdb->get_row("SHOW COLUMNS FROM `$detail_table` WHERE Field = '$old_name'");
    if (!$col_info) {
        // Column doesn't exist in detail table — not an error, some fields
        // (like core GD fields) may not have a detail column
        return true;
    }

    // Check new column doesn't already exist
    $new_col = $wpdb->get_row("SHOW COLUMNS FROM `$detail_table` WHERE Field = '$new_name'");
    if ($new_col) {
        return "column '$new_name' already exists in $detail_table";
    }

    // Build column definition from SHOW COLUMNS output
    $type = $col_info->Type;
    $null = ($col_info->Null === 'YES') ? 'NULL' : 'NOT NULL';
    $default = '';
    if ($col_info->Default !== null) {
        $default = "DEFAULT '" . esc_sql($col_info->Default) . "'";
    } elseif ($col_info->Null === 'YES') {
        $default = 'DEFAULT NULL';
    }

    $sql = "ALTER TABLE `$detail_table` CHANGE COLUMN `$old_name` `$new_name` $type $null $default";
    $result = $wpdb->query($sql);

    if ($result === false) {
        return "ALTER TABLE failed: " . $wpdb->last_error;
    }

    return true;
}

// ============================================================
// Helper: drop a column from the CPT detail table
// Returns true on success, error string on failure
// ============================================================
function drop_detail_column($wpdb, $post_type, $col_name) {
    $detail_table = $wpdb->prefix . 'geodir_' . $post_type . '_detail';

    // Verify detail table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$detail_table'") !== $detail_table) {
        return "detail table $detail_table not found";
    }

    // Check column exists
    $col_info = $wpdb->get_row("SHOW COLUMNS FROM `$detail_table` WHERE Field = '$col_name'");
    if (!$col_info) {
        // No column to drop — not an error
        return true;
    }

    $sql = "ALTER TABLE `$detail_table` DROP COLUMN `$col_name`";
    $result = $wpdb->query($sql);

    if ($result === false) {
        return "ALTER TABLE failed: " . $wpdb->last_error;
    }

    return true;
}

// Core GD fields that should never be deleted (managed by GeoDirectory itself)
$protected_fields = [
    'post_title', 'post_category', 'post_tags', 'post_content', 'post_images',
    'address', 'phone', 'website', 'logo', 'recurring', 'event_dates',
];

// ============================================================
// Report header
// ============================================================
echo "GeoDirectory Field Sync\n";
echo "=======================\n";
echo "Spec: $json_filename\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
if ($dry_run) {
    echo "*** DRY RUN — no changes will be made ***\n";
}
echo "\n";
echo "REMINDER: Export a backup before syncing:\n";
echo "  wp eval-file export-geodirectory.php OUTPUT_FILE=gd-export-before-sync.json\n";
echo "\n";

// ============================================================
// Resolve which CPTs to sync
// ============================================================
$cpts_to_sync = resolve_cpts($data, $cpt_filter);
$common_fields = $data['common_fields'] ?? [];

global $wpdb;
$fields_table = $wpdb->prefix . 'geodir_custom_fields';

// Check for geodir_custom_field_save (needed for adding fields)
$can_add_fields = function_exists('geodir_custom_field_save');
if (!$can_add_fields) {
    echo "WARNING: geodir_custom_field_save() not found — missing fields cannot be added.\n\n";
}

// Per-CPT and overall counters
$total_match = 0;
$total_update = 0;
$total_rename = 0;
$total_add = 0;
$total_delete = 0;
$total_skip = 0;

foreach ($cpts_to_sync as $cpt) {
    $post_type = $cpt['post_type'];
    $cpt_name = $cpt['cpt'];

    echo "CPT: $cpt_name ($post_type)\n";
    echo str_repeat('-', 63) . "\n";

    $cpt_match = 0;
    $cpt_update = 0;
    $cpt_rename = 0;
    $cpt_add = 0;
    $cpt_delete = 0;
    $cpt_skip = 0;

    // Merge common_fields + CPT fields
    $spec_fields = merge_fields($common_fields, $cpt['fields'] ?? []);

    // Query live fields
    $live_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, htmlvar_name, admin_title, frontend_title, sort_order, field_type FROM $fields_table WHERE post_type = %s",
        $post_type
    ));

    // Index live fields by htmlvar_name
    $live_by_name = [];
    if ($live_rows) {
        foreach ($live_rows as $row) {
            $live_by_name[$row->htmlvar_name] = $row;
        }
    }

    // Compare and sync each spec field
    foreach ($spec_fields as $spec_name => $spec_field) {
        $label = $spec_field['label'] ?? $spec_name;
        $spec_sort = isset($spec_field['sort_order']) ? intval($spec_field['sort_order']) : null;

        // --- Phase 1: exact htmlvar_name match ---
        if (isset($live_by_name[$spec_name])) {
            $live = $live_by_name[$spec_name];
            $live_label = $live->admin_title ?: $live->frontend_title;
            $live_sort = intval($live->sort_order);

            $changes = [];
            if ($label !== $live_label) {
                $changes['label'] = "$live_label -> $label";
            }
            if ($spec_sort !== null && $spec_sort !== $live_sort) {
                $changes['sort_order'] = "$live_sort -> $spec_sort";
            }

            if (empty($changes)) {
                echo "  [MATCH]   " . str_pad($spec_name, 25) . "$label, sort:$live_sort\n";
                $cpt_match++;
            } else {
                $desc = implode('; ', array_map(function($k, $v) { return "$k: $v"; }, array_keys($changes), $changes));
                echo "  [UPDATE]  " . str_pad($spec_name, 25) . $desc . "\n";
                $cpt_update++;

                if (!$dry_run) {
                    $update_data = [
                        'admin_title' => $label,
                        'frontend_title' => $label,
                    ];
                    if ($spec_sort !== null) {
                        $update_data['sort_order'] = $spec_sort;
                    }

                    $result = $wpdb->update(
                        $fields_table,
                        $update_data,
                        ['id' => $live->id],
                        array_map(function($v) { return is_int($v) ? '%d' : '%s'; }, $update_data),
                        ['%d']
                    );

                    if ($result === false) {
                        echo "            ** DB ERROR: " . $wpdb->last_error . "\n";
                    }
                }
            }
            continue;
        }

        // --- Phase 2: _rename_from match ---
        $rename_from = $spec_field['_rename_from'] ?? [];
        if (is_string($rename_from)) {
            $rename_from = [$rename_from];
        }

        $rename_candidate = null;
        foreach ($rename_from as $old_candidate) {
            if (isset($live_by_name[$old_candidate])) {
                $rename_candidate = $live_by_name[$old_candidate];
                break;
            }
        }

        if ($rename_candidate) {
            $live = $rename_candidate;
            $old_name = $live->htmlvar_name;
            $live_label = $live->admin_title ?: $live->frontend_title;
            $live_sort = intval($live->sort_order);

            // Build change description
            $changes = ["htmlvar_name: $old_name -> $spec_name"];
            if ($label !== $live_label) {
                $changes[] = "label: $live_label -> $label";
            }
            if ($spec_sort !== null && $spec_sort !== $live_sort) {
                $changes[] = "sort_order: $live_sort -> $spec_sort";
            }

            echo "  [RENAME]  " . str_pad($spec_name, 25) . implode('; ', $changes) . "\n";
            $cpt_rename++;

            if (!$dry_run) {
                // 1. Rename column in detail table
                $col_result = rename_detail_column($wpdb, $post_type, $old_name, $spec_name);
                if ($col_result !== true) {
                    echo "            ** COLUMN RENAME WARNING: $col_result\n";
                    echo "            ** Skipping this field to avoid inconsistency.\n";
                    continue;
                }

                // 2. Update the custom_fields row
                $update_data = [
                    'htmlvar_name' => $spec_name,
                    'admin_title' => $label,
                    'frontend_title' => $label,
                ];
                if ($spec_sort !== null) {
                    $update_data['sort_order'] = $spec_sort;
                }

                $result = $wpdb->update(
                    $fields_table,
                    $update_data,
                    ['id' => $live->id],
                    array_map(function($v) { return is_int($v) ? '%d' : '%s'; }, $update_data),
                    ['%d']
                );

                if ($result === false) {
                    echo "            ** DB ERROR: " . $wpdb->last_error . "\n";
                }
            }
            continue;
        }

        // --- Phase 3: not found — add the field ---
        if (!$can_add_fields) {
            echo "  [SKIP]    " . str_pad($spec_name, 25) . "not found on site (cannot add — function missing)\n";
            $cpt_skip++;
            continue;
        }

        $field_type = $spec_field['field_type'] ?? 'text';
        echo "  [ADD]     " . str_pad($spec_name, 25) . "$label (type: $field_type, sort: $spec_sort)\n";
        $cpt_add++;

        if (!$dry_run) {
            $field_data = [
                'post_type'        => $post_type,
                'field_type'       => $field_type,
                'admin_title'      => $label,
                'frontend_title'   => $label,
                'htmlvar_name'     => $spec_name,
                'field_icon'       => $spec_field['field_icon'] ?? '',
                'default_value'    => $spec_field['default_value'] ?? '',
                'placeholder_value'=> $spec_field['placeholder'] ?? '',
                'desc'             => $spec_field['description'] ?? '',
                'is_active'        => '1',
                'is_required'      => ($spec_field['is_required'] ?? false) ? '1' : '0',
                'option_values'    => $spec_field['options'] ?? '',
                'show_in'          => implode(',', $spec_field['show_in'] ?? []),
                'sort_order'       => $spec_sort ?? 99,
                'for_admin_use'    => '0',
                'css_class'        => $spec_field['css_class'] ?? '',
            ];

            $result = geodir_custom_field_save($field_data);

            if ($result && !is_wp_error($result)) {
                echo "            -> created successfully\n";
            } else {
                $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                echo "            ** ADD ERROR: $error_msg\n";
            }
        }
    }

    // --- Phase 4: delete fields on site but not in spec ---
    // Build set of all spec field names (including _rename_from aliases that were matched)
    $spec_names = array_keys($spec_fields);
    // Also collect any _rename_from names so we don't delete a field mid-rename
    $rename_aliases = [];
    foreach ($spec_fields as $sf) {
        $rf = $sf['_rename_from'] ?? [];
        if (is_string($rf)) $rf = [$rf];
        $rename_aliases = array_merge($rename_aliases, $rf);
    }
    $known_names = array_merge($spec_names, $rename_aliases, $protected_fields);

    foreach ($live_by_name as $live_name => $live_row) {
        if (in_array($live_name, $known_names)) {
            continue;
        }

        $live_label = $live_row->admin_title ?: $live_row->frontend_title ?: $live_name;
        echo "  [DELETE]  " . str_pad($live_name, 25) . "$live_label (not in spec)\n";
        $cpt_delete++;

        if (!$dry_run) {
            // 1. Drop column from detail table
            $col_result = drop_detail_column($wpdb, $post_type, $live_name);
            if ($col_result !== true) {
                echo "            ** COLUMN DROP WARNING: $col_result\n";
            }

            // 2. Delete the custom_fields row
            $result = $wpdb->delete(
                $fields_table,
                ['id' => $live_row->id],
                ['%d']
            );

            if ($result === false) {
                echo "            ** DB ERROR: " . $wpdb->last_error . "\n";
            } else {
                echo "            -> deleted successfully\n";
            }
        }
    }

    echo "  --- $cpt_name: $cpt_match match, $cpt_update update, $cpt_rename rename, $cpt_add add, $cpt_delete delete, $cpt_skip skip\n\n";

    $total_match += $cpt_match;
    $total_update += $cpt_update;
    $total_rename += $cpt_rename;
    $total_add += $cpt_add;
    $total_delete += $cpt_delete;
    $total_skip += $cpt_skip;
}

// ============================================================
// Summary
// ============================================================
echo "Summary\n";
echo "=======\n";
echo "  $total_match fields matched\n";
echo "  $total_update fields " . ($dry_run ? "would be updated" : "updated") . "\n";
echo "  $total_rename fields " . ($dry_run ? "would be renamed" : "renamed") . "\n";
echo "  $total_add fields " . ($dry_run ? "would be added" : "added") . "\n";
echo "  $total_delete fields " . ($dry_run ? "would be deleted" : "deleted") . "\n";
echo "  $total_skip fields skipped\n";
echo "\n";

if ($dry_run) {
    echo "*** DRY RUN COMPLETE — no changes were made ***\n";
    echo "Run without DRY_RUN=1 to apply changes.\n";
} else {
    if ($total_update > 0 || $total_rename > 0 || $total_add > 0 || $total_delete > 0) {
        echo "Sync complete. Verify with:\n";
        echo "  AUDIT_FILE=$json_filename wp eval-file audit-geodirectory.php\n";
    } else {
        echo "Nothing to sync — all fields already match spec.\n";
    }
}
