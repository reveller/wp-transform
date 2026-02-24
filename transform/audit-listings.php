<?php
/**
 * GeoDirectory Listing Audit Script
 *
 * Compares a GeoDirectory import CSV against live listings on the site.
 * Keys off post_title to identify which entries exist and which are missing.
 *
 * Usage:
 *   1. Upload this file and the CSV to WordPress root (or transform dir)
 *   2. Run via WP-CLI: wp eval-file audit-listings.php
 *
 * Options (set as environment variables):
 *   CSV_FILE=path         GeoDirectory import CSV file (required)
 *   OUTPUT_FILE=path      Write report to file instead of stdout
 *
 * Examples:
 *   CSV_FILE=gd_Stay.csv wp eval-file audit-listings.php
 *   CSV_FILE=gd_Stay.csv OUTPUT_FILE=listing-audit.txt wp eval-file audit-listings.php
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
$csv_filename = getenv('CSV_FILE') ?: '';
$output_file = getenv('OUTPUT_FILE') ?: null;

if (empty($csv_filename)) {
    die("Error: CSV_FILE is required.\n\nUsage:\n  CSV_FILE=gd_Stay.csv wp eval-file audit-listings.php\n");
}

// Resolve CSV file path (absolute or relative to script dir)
if ($csv_filename[0] === '/') {
    $csv_file = $csv_filename;
} else {
    $csv_file = __DIR__ . '/' . $csv_filename;
}

if (!file_exists($csv_file)) {
    die("Error: Cannot find CSV file: $csv_file\n");
}

// ============================================================
// Parse CSV file
// ============================================================
$handle = fopen($csv_file, 'r');
if (!$handle) {
    die("Error: Cannot open CSV file: $csv_file\n");
}

// Read header row
$headers = fgetcsv($handle);
if (!$headers) {
    fclose($handle);
    die("Error: CSV file is empty or has no header row.\n");
}

// Find required column indices
$title_idx = array_search('post_title', $headers);
$type_idx = array_search('post_type', $headers);
$status_idx = array_search('post_status', $headers);
$category_idx = array_search('post_category', $headers);

if ($title_idx === false) {
    fclose($handle);
    die("Error: CSV file missing required 'post_title' column.\n");
}
if ($type_idx === false) {
    fclose($handle);
    die("Error: CSV file missing required 'post_type' column.\n");
}

// Read all CSV entries
$csv_entries = [];
$csv_post_types = [];
$row_num = 1; // header was row 1

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
        'post_title' => $title,
        'post_type' => $post_type,
        'post_status' => $status,
        'post_category' => $category,
    ];

    $csv_post_types[$post_type] = true;
}

fclose($handle);

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
echo "CSV:  $csv_filename\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "CSV entries: $csv_count\n";
echo "Post types:  " . implode(', ', $post_types) . "\n";
echo "\n";

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
        echo sprintf("    %-5s %-45s %s\n", 'Row', 'Post Title', 'Category');
        echo sprintf("    %-5s %-45s %s\n", '---', str_repeat('-', 45), str_repeat('-', 15));

        foreach ($found as $entry) {
            echo sprintf("    %-5d %-45s %s\n",
                $entry['row'],
                mb_substr($entry['post_title'], 0, 45),
                mb_substr($entry['post_category'], 0, 15)
            );
        }
    }
    echo "\n";

    // -- Missing --
    echo "  MISSING FROM SITE (" . count($missing) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($missing)) {
        echo "    (none)\n";
    } else {
        echo sprintf("    %-5s %-45s %s\n", 'Row', 'Post Title', 'Category');
        echo sprintf("    %-5s %-45s %s\n", '---', str_repeat('-', 45), str_repeat('-', 15));

        foreach ($missing as $entry) {
            echo sprintf("    %-5d %-45s %s\n",
                $entry['row'],
                mb_substr($entry['post_title'], 0, 45),
                mb_substr($entry['post_category'], 0, 15)
            );
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

    // Track stats
    $cpt_stats = [
        'csv' => $cpt_csv_count,
        'site' => $site_count,
        'found' => count($found),
        'missing' => count($missing),
        'extra' => count($extra),
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
    echo sprintf("  %-25s %d in CSV, %d found, %d missing, %d extra\n",
        $post_type . ':',
        $stats['csv'], $stats['found'], $stats['missing'], $stats['extra']
    );
}

if (count($summary) > 1) {
    echo sprintf("\n  %-25s %d in CSV, %d found, %d missing, %d extra\n",
        'TOTAL:',
        $totals['csv'], $totals['found'], $totals['missing'], $totals['extra']
    );
}

echo "\n";
if ($totals['missing'] === 0 && $totals['extra'] === 0) {
    echo "All clear — CSV and site match.\n";
} else {
    $parts = [];
    if ($totals['missing'] > 0) {
        $parts[] = "{$totals['missing']} listing(s) missing from site";
    }
    if ($totals['extra'] > 0) {
        $parts[] = "{$totals['extra']} extra listing(s) on site not in CSV";
    }
    echo implode('. ', $parts) . ".\n";
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
