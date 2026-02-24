<?php
/**
 * GeoDirectory Listing Audit Script
 *
 * Compares GeoDirectory import CSV(s) against live listings on the site.
 * Keys off post_title to identify which entries exist and which are missing.
 * When multiple CSV files are provided, also reports duplicate post_titles
 * across files.
 *
 * Usage:
 *   1. Upload this file and the CSV(s) to WordPress root (or transform dir)
 *   2. Run via WP-CLI: wp eval-file audit-listings.php
 *
 * Options (set as environment variables):
 *   CSV_FILE=pattern      GeoDirectory import CSV file or glob pattern (required)
 *   OUTPUT_FILE=path      Write report to file instead of stdout
 *
 * Examples:
 *   CSV_FILE=gd_Stay.csv wp eval-file audit-listings.php
 *   CSV_FILE="done/*.csv" wp eval-file audit-listings.php
 *   CSV_FILE="done/*.csv" OUTPUT_FILE=listing-audit.txt wp eval-file audit-listings.php
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
// Load taxonomy for category ID -> name lookup
// ============================================================
$taxonomy_file = __DIR__ . '/gd-taxonomy-cpts.json';
$cat_id_to_name = [];
if (file_exists($taxonomy_file)) {
    $taxonomy = json_decode(file_get_contents($taxonomy_file), true);
    if ($taxonomy && isset($taxonomy['cpts'])) {
        foreach ($taxonomy['cpts'] as $cpt) {
            foreach ($cpt['categories'] ?? [] as $cat) {
                $id = $cat['id'] ?? null;
                $name = $cat['name'] ?? '';
                if ($id !== null && $name) {
                    $cat_id_to_name[$id] = $name;
                }
            }
        }
    }
}

/**
 * Resolve a CSV category value (e.g. ",119,") to a human-readable name.
 */
function resolve_category_name($cat_value, $cat_id_to_name) {
    $cat_value = trim($cat_value);
    if (empty($cat_value)) return '(no category)';

    // Extract numeric ID from ",119," format
    $id = trim($cat_value, ', ');
    if (is_numeric($id) && isset($cat_id_to_name[(int)$id])) {
        return $cat_id_to_name[(int)$id];
    }

    return $cat_value;
}

// ============================================================
// Parse environment variables
// ============================================================
$csv_pattern = getenv('CSV_FILE') ?: '';
$output_file = getenv('OUTPUT_FILE') ?: null;

if (empty($csv_pattern)) {
    die("Error: CSV_FILE is required.\n\nUsage:\n  CSV_FILE=gd_Stay.csv wp eval-file audit-listings.php\n  CSV_FILE=\"done/*.csv\" wp eval-file audit-listings.php\n");
}

// Resolve glob pattern (absolute or relative to script dir)
if ($csv_pattern[0] !== '/') {
    $csv_pattern = __DIR__ . '/' . $csv_pattern;
}

$csv_files = glob($csv_pattern);
if (empty($csv_files)) {
    die("Error: No files match pattern: $csv_pattern\n");
}
sort($csv_files);

// ============================================================
// Parse CSV files
// ============================================================
$csv_entries = [];
$csv_post_types = [];

foreach ($csv_files as $csv_file) {
    $handle = fopen($csv_file, 'r');
    if (!$handle) {
        fwrite(STDERR, "Warning: Cannot open CSV file: $csv_file — skipping\n");
        continue;
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        fwrite(STDERR, "Warning: CSV file is empty or has no header row: $csv_file — skipping\n");
        continue;
    }

    $title_idx = array_search('post_title', $headers);
    $type_idx = array_search('post_type', $headers);
    $status_idx = array_search('post_status', $headers);
    $category_idx = array_search('post_category', $headers);

    if ($title_idx === false || $type_idx === false) {
        fclose($handle);
        fwrite(STDERR, "Warning: CSV file missing post_title or post_type column: $csv_file — skipping\n");
        continue;
    }

    $source = basename($csv_file);
    $row_num = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $row_num++;

        $title = trim($row[$title_idx] ?? '');
        $post_type = trim($row[$type_idx] ?? '');
        $status = $status_idx !== false ? trim($row[$status_idx] ?? '') : '';
        $category = $category_idx !== false ? trim($row[$category_idx] ?? '') : '';

        if (empty($title) || empty($post_type)) {
            continue;
        }

        $csv_entries[] = [
            'row' => $row_num,
            'source' => $source,
            'post_title' => $title,
            'post_type' => $post_type,
            'post_status' => $status,
            'post_category' => $category,
        ];

        $csv_post_types[$post_type] = true;
    }

    fclose($handle);
}

if (empty($csv_entries)) {
    die("Error: No valid entries found in any CSV file.\n");
}

$csv_count = count($csv_entries);
$post_types = array_keys($csv_post_types);

// ============================================================
// Query live GeoDirectory listings
// ============================================================
// Build a lookup of live listings keyed by post_type -> title (lowercase)
$live_listings = []; // post_type -> [lowercase_title -> post object]

foreach ($post_types as $post_type) {
    $live_listings[$post_type] = [];

    $posts = get_posts([
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    foreach ($posts as $post) {
        $key = strtolower(trim($post->post_title));
        $live_listings[$post_type][$key] = $post;
    }
}

// ============================================================
// Group CSV entries by post_type
// ============================================================
$csv_by_type = [];
foreach ($csv_entries as $entry) {
    $csv_by_type[$entry['post_type']][] = $entry;
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
echo "GeoDirectory Listing Audit\n";
echo "==========================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
if (count($csv_files) === 1) {
    echo "CSV:  " . basename($csv_files[0]) . "\n";
} else {
    echo "CSV files (" . count($csv_files) . "):\n";
    foreach ($csv_files as $f) {
        echo "  - " . basename($f) . "\n";
    }
}
echo "CSV entries: $csv_count\n";
echo "Post types:  " . implode(', ', $post_types) . "\n";
echo "\n";

// ============================================================
// Detect duplicates across CSV files
// ============================================================
$multi_file = count($csv_files) > 1;
$duplicates = []; // post_type -> composite_key -> [entries...]
$title_seen = [];  // post_type -> composite_key -> first entry

foreach ($csv_entries as $entry) {
    $pt = $entry['post_type'];
    $title_key = strtolower(trim($entry['post_title']));
    $cat_key = strtolower(trim($entry['post_category']));
    $key = $title_key . '||' . $cat_key;

    if (isset($title_seen[$pt][$key])) {
        // First time seeing a dupe for this key — add the original too
        if (!isset($duplicates[$pt][$key])) {
            $duplicates[$pt][$key] = [$title_seen[$pt][$key]];
        }
        $duplicates[$pt][$key][] = $entry;
    } else {
        $title_seen[$pt][$key] = $entry;
    }
}

$total_dupe_titles = 0;
$total_dupe_rows = 0;
foreach ($duplicates as $pt => $groups) {
    $total_dupe_titles += count($groups);
    foreach ($groups as $entries) {
        $total_dupe_rows += count($entries);
    }
}

// ============================================================
// Audit each CPT
// ============================================================
$summary = [];
$totals = ['csv' => 0, 'found' => 0, 'missing' => 0, 'extra' => 0];

foreach ($post_types as $post_type) {
    $cpt_csv_entries = $csv_by_type[$post_type] ?? [];
    $cpt_csv_count = count($cpt_csv_entries);
    $site_count = count($live_listings[$post_type]);

    echo str_repeat('=', 70) . "\n";
    echo "CPT: $post_type ($cpt_csv_count in CSV, $site_count on site)\n";
    echo str_repeat('=', 70) . "\n\n";

    // Compare CSV entries against site for this CPT
    $found = [];
    $missing = [];
    $matched_keys = [];

    foreach ($cpt_csv_entries as $entry) {
        $key = strtolower(trim($entry['post_title']));

        if (isset($live_listings[$post_type][$key])) {
            $found[] = $entry;
            $matched_keys[$key] = true;
        } else {
            $missing[] = $entry;
        }
    }

    // Find extra listings on site not in CSV for this CPT
    $extra = [];
    foreach ($live_listings[$post_type] as $key => $post) {
        if (!isset($matched_keys[$key])) {
            $extra[] = [
                'post_title' => $post->post_title,
                'post_status' => $post->post_status,
                'post_id' => $post->ID,
            ];
        }
    }

    // -- Found --
    echo "  FOUND (" . count($found) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($found)) {
        echo "    (none)\n";
    } else {
        if ($multi_file) {
            echo sprintf("    %-5s %-30s %-25s %s\n", 'Row', 'Post Title', 'Source', 'Category');
            echo sprintf("    %-5s %-30s %-25s %s\n", '---', str_repeat('-', 30), str_repeat('-', 25), str_repeat('-', 20));
            foreach ($found as $entry) {
                echo sprintf("    %-5d %-30s %-25s %s\n",
                    $entry['row'],
                    mb_substr($entry['post_title'], 0, 30),
                    mb_substr($entry['source'], 0, 25),
                    mb_substr(resolve_category_name($entry['post_category'], $cat_id_to_name), 0, 20)
                );
            }
        } else {
            echo sprintf("    %-5s %-45s %s\n", 'Row', 'Post Title', 'Category');
            echo sprintf("    %-5s %-45s %s\n", '---', str_repeat('-', 45), str_repeat('-', 20));
            foreach ($found as $entry) {
                echo sprintf("    %-5d %-45s %s\n",
                    $entry['row'],
                    mb_substr($entry['post_title'], 0, 45),
                    mb_substr(resolve_category_name($entry['post_category'], $cat_id_to_name), 0, 20)
                );
            }
        }
    }
    echo "\n";

    // -- Missing --
    echo "  MISSING FROM SITE (" . count($missing) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($missing)) {
        echo "    (none)\n";
    } else {
        if ($multi_file) {
            echo sprintf("    %-5s %-30s %-25s %s\n", 'Row', 'Post Title', 'Source', 'Category');
            echo sprintf("    %-5s %-30s %-25s %s\n", '---', str_repeat('-', 30), str_repeat('-', 25), str_repeat('-', 20));
            foreach ($missing as $entry) {
                echo sprintf("    %-5d %-30s %-25s %s\n",
                    $entry['row'],
                    mb_substr($entry['post_title'], 0, 30),
                    mb_substr($entry['source'], 0, 25),
                    mb_substr(resolve_category_name($entry['post_category'], $cat_id_to_name), 0, 20)
                );
            }
        } else {
            echo sprintf("    %-5s %-45s %s\n", 'Row', 'Post Title', 'Category');
            echo sprintf("    %-5s %-45s %s\n", '---', str_repeat('-', 45), str_repeat('-', 20));
            foreach ($missing as $entry) {
                echo sprintf("    %-5d %-45s %s\n",
                    $entry['row'],
                    mb_substr($entry['post_title'], 0, 45),
                    mb_substr(resolve_category_name($entry['post_category'], $cat_id_to_name), 0, 20)
                );
            }
        }
    }
    echo "\n";

    // -- Extra on site --
    echo "  EXTRA ON SITE (" . count($extra) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($extra)) {
        echo "    (none)\n";
    } else {
        echo sprintf("    %-8s %-45s %s\n", 'ID', 'Post Title', 'Status');
        echo sprintf("    %-8s %-45s %s\n", '---', str_repeat('-', 45), str_repeat('-', 10));

        foreach ($extra as $entry) {
            echo sprintf("    %-8d %-45s %s\n",
                $entry['post_id'],
                mb_substr($entry['post_title'], 0, 45),
                $entry['post_status']
            );
        }
    }
    echo "\n";

    // -- Duplicates within this CPT --
    $cpt_dupes = $duplicates[$post_type] ?? [];
    if (!empty($cpt_dupes)) {
        $dupe_count = count($cpt_dupes);
        echo "  DUPLICATES ($dupe_count title(s) appear more than once)\n";
        echo "  " . str_repeat('-', 68) . "\n";

        foreach ($cpt_dupes as $combo_key => $entries) {
            $cat_display = resolve_category_name($entries[0]['post_category'], $cat_id_to_name);
            echo sprintf("    \"%s\" [%s] (%d occurrences)\n",
                $entries[0]['post_title'],
                $cat_display,
                count($entries)
            );
            foreach ($entries as $dup) {
                echo sprintf("      Row %-5d  %-30s  %s\n",
                    $dup['row'],
                    mb_substr($dup['source'], 0, 30),
                    resolve_category_name($dup['post_category'], $cat_id_to_name)
                );
            }
        }
        echo "\n";
    }

    // Track stats
    $cpt_stats = [
        'csv' => $cpt_csv_count,
        'site' => $site_count,
        'found' => count($found),
        'missing' => count($missing),
        'extra' => count($extra),
        'dupe_titles' => count($cpt_dupes),
    ];
    $summary[$post_type] = $cpt_stats;
    $totals['csv'] += $cpt_csv_count;
    $totals['found'] += count($found);
    $totals['missing'] += count($missing);
    $totals['extra'] += count($extra);
}

// ============================================================
// Summary
// ============================================================
echo str_repeat('=', 70) . "\n";
echo "Summary\n";
echo str_repeat('=', 70) . "\n";

foreach ($summary as $post_type => $stats) {
    $line = sprintf("  %-25s %d in CSV, %d found, %d missing, %d extra",
        $post_type . ':',
        $stats['csv'], $stats['found'], $stats['missing'], $stats['extra']
    );
    if ($stats['dupe_titles'] > 0) {
        $line .= sprintf(", %d duplicate title(s)", $stats['dupe_titles']);
    }
    echo $line . "\n";
}

if (count($summary) > 1) {
    $line = sprintf("\n  %-25s %d in CSV, %d found, %d missing, %d extra",
        'TOTAL:',
        $totals['csv'], $totals['found'], $totals['missing'], $totals['extra']
    );
    if ($total_dupe_titles > 0) {
        $line .= sprintf(", %d duplicate title(s) (%d rows)", $total_dupe_titles, $total_dupe_rows);
    }
    echo $line . "\n";
}

echo "\n";
$status_parts = [];
if ($totals['missing'] === 0 && $totals['extra'] === 0 && $total_dupe_titles === 0) {
    echo "All clear — CSV and site match, no duplicates.\n";
} else {
    if ($totals['missing'] > 0) {
        $status_parts[] = "{$totals['missing']} listing(s) missing from site";
    }
    if ($totals['extra'] > 0) {
        $status_parts[] = "{$totals['extra']} extra listing(s) on site not in CSV";
    }
    if ($total_dupe_titles > 0) {
        $status_parts[] = "{$total_dupe_titles} duplicate title(s) across CSV files ({$total_dupe_rows} total rows)";
    }
    echo implode('. ', $status_parts) . ".\n";
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
}
