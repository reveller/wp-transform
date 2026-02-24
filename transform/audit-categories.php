<?php
/**
 * GeoDirectory Category Audit Script
 *
 * Compares category data in gd-taxonomy-cpts.json against the live
 * GeoDirectory site. Reports matches, mismatches, missing, and extra
 * categories per CPT taxonomy.
 *
 * Usage:
 *   1. Upload this file and the JSON spec to WordPress root (or transform dir)
 *   2. Run via WP-CLI: wp eval-file audit-categories.php
 *
 * Options (set as environment variables):
 *   AUDIT_FILE=path       JSON spec file (default: gd-taxonomy-cpts.json)
 *   OUTPUT_FILE=path      Write report to file instead of stdout
 *   CPT_NAME=name         Audit a single CPT by display name or post_type slug (default: ALL)
 *
 * Examples:
 *   wp eval-file audit-categories.php
 *   CPT_NAME="Food and Drink" wp eval-file audit-categories.php
 *   AUDIT_FILE=gd-taxonomy-cpts.json OUTPUT_FILE=category-audit.txt wp eval-file audit-categories.php
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
$audit_filename = getenv('AUDIT_FILE') ?: 'gd-taxonomy-cpts.json';
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
// Resolve CPT filter
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

$cpts_to_audit = resolve_cpts($data, $cpt_filter);

// ============================================================
// Start output buffering if OUTPUT_FILE set
// ============================================================
if ($output_file) {
    ob_start();
}

// ============================================================
// Report header
// ============================================================
echo "GeoDirectory Category Audit\n";
echo "===========================\n";
echo "Spec: $audit_filename\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "CPTs: " . (strtoupper($cpt_filter) === 'ALL' ? 'ALL' : $cpt_filter) . "\n";
echo "\n";

// ============================================================
// Audit each CPT
// ============================================================
$summary = [];

foreach ($cpts_to_audit as $cpt) {
    $cpt_name = $cpt['cpt'];
    $post_type = $cpt['post_type'];
    $spec_categories = $cpt['categories'] ?? [];

    // Get the GeoDirectory taxonomy name for this CPT
    $taxonomy = $post_type . 'category';

    echo str_repeat('=', 70) . "\n";
    echo "CPT: $cpt_name ($post_type)\n";
    echo "Taxonomy: $taxonomy\n";
    echo str_repeat('=', 70) . "\n\n";

    // Fetch live categories from WordPress
    $live_terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (is_wp_error($live_terms)) {
        echo "  ERROR: Could not fetch terms for taxonomy '$taxonomy': " . $live_terms->get_error_message() . "\n\n";
        $summary[$cpt_name] = ['spec' => count($spec_categories), 'site' => 0, 'match' => 0, 'mismatch' => 0, 'missing' => 0, 'extra' => 0, 'error' => true];
        continue;
    }

    // Build lookup of live terms by slug
    $live_by_slug = [];
    $live_by_id = [];
    foreach ($live_terms as $term) {
        $live_by_slug[$term->slug] = $term;
        $live_by_id[$term->term_id] = $term;
    }

    $matched = [];
    $mismatched = [];
    $missing = [];
    $matched_slugs = [];

    // Compare spec categories against live site
    foreach ($spec_categories as $spec_cat) {
        $spec_name = $spec_cat['name'] ?? '';
        $spec_slug = $spec_cat['slug'] ?? '';
        $spec_id = $spec_cat['id'] ?? null;

        // Try to find by slug first, then by ID
        $live_term = null;
        if (isset($live_by_slug[$spec_slug])) {
            $live_term = $live_by_slug[$spec_slug];
        } elseif ($spec_id && isset($live_by_id[$spec_id])) {
            $live_term = $live_by_id[$spec_id];
        }

        if ($live_term) {
            $matched_slugs[$live_term->slug] = true;
            $issues = [];

            // Check name match
            if ($live_term->name !== $spec_name) {
                $issues[] = "name: site='{$live_term->name}' spec='{$spec_name}'";
            }

            // Check slug match
            if ($live_term->slug !== $spec_slug) {
                $issues[] = "slug: site='{$live_term->slug}' spec='{$spec_slug}'";
            }

            // Check ID match
            if ($spec_id !== null && $live_term->term_id !== $spec_id) {
                $issues[] = "id: site={$live_term->term_id} spec={$spec_id}";
            }

            if (empty($issues)) {
                $matched[] = [
                    'spec' => $spec_cat,
                    'live' => $live_term,
                ];
            } else {
                $mismatched[] = [
                    'spec' => $spec_cat,
                    'live' => $live_term,
                    'issues' => $issues,
                ];
            }
        } else {
            $missing[] = $spec_cat;
        }
    }

    // Find extra categories on site not in spec
    $extra = [];
    foreach ($live_terms as $term) {
        if (!isset($matched_slugs[$term->slug])) {
            $extra[] = $term;
        }
    }

    // -- Matched --
    echo "  MATCHED (" . count($matched) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($matched)) {
        echo "    (none)\n";
    } else {
        echo sprintf("    %-6s %-25s %s\n", 'ID', 'Slug', 'Name');
        echo sprintf("    %-6s %-25s %s\n", '---', str_repeat('-', 25), str_repeat('-', 30));

        foreach ($matched as $m) {
            echo sprintf("    %-6d %-25s %s\n",
                $m['live']->term_id,
                mb_substr($m['live']->slug, 0, 25),
                mb_substr($m['live']->name, 0, 30)
            );
        }
    }
    echo "\n";

    // -- Mismatched --
    echo "  MISMATCHED (" . count($mismatched) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($mismatched)) {
        echo "    (none)\n";
    } else {
        foreach ($mismatched as $mm) {
            $spec = $mm['spec'];
            echo sprintf("    %s (spec id=%s, slug=%s)\n",
                $spec['name'],
                $spec['id'] ?? 'null',
                $spec['slug']
            );
            foreach ($mm['issues'] as $issue) {
                echo "      -> $issue\n";
            }
        }
    }
    echo "\n";

    // -- Missing from site --
    echo "  MISSING FROM SITE (" . count($missing) . ")\n";
    echo "  " . str_repeat('-', 68) . "\n";

    if (empty($missing)) {
        echo "    (none)\n";
    } else {
        echo sprintf("    %-6s %-25s %s\n", 'ID', 'Slug', 'Name');
        echo sprintf("    %-6s %-25s %s\n", '---', str_repeat('-', 25), str_repeat('-', 30));

        foreach ($missing as $spec_cat) {
            echo sprintf("    %-6s %-25s %s\n",
                $spec_cat['id'] ?? 'null',
                mb_substr($spec_cat['slug'], 0, 25),
                mb_substr($spec_cat['name'], 0, 30)
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
        echo sprintf("    %-6s %-25s %-30s %s\n", 'ID', 'Slug', 'Name', 'Count');
        echo sprintf("    %-6s %-25s %-30s %s\n", '---', str_repeat('-', 25), str_repeat('-', 30), '-----');

        foreach ($extra as $term) {
            echo sprintf("    %-6d %-25s %-30s %d\n",
                $term->term_id,
                mb_substr($term->slug, 0, 25),
                mb_substr($term->name, 0, 30),
                $term->count
            );
        }
    }
    echo "\n";

    // Track stats
    $summary[$cpt_name] = [
        'spec' => count($spec_categories),
        'site' => count($live_terms),
        'match' => count($matched),
        'mismatch' => count($mismatched),
        'missing' => count($missing),
        'extra' => count($extra),
    ];
}

// ============================================================
// Summary
// ============================================================
echo str_repeat('=', 70) . "\n";
echo "Summary\n";
echo str_repeat('=', 70) . "\n";

$totals = ['spec' => 0, 'site' => 0, 'match' => 0, 'mismatch' => 0, 'missing' => 0, 'extra' => 0];

foreach ($summary as $cpt_name => $stats) {
    if (!empty($stats['error'])) {
        echo sprintf("  %-25s ERROR fetching taxonomy\n", $cpt_name . ':');
        continue;
    }

    echo sprintf("  %-25s %d in spec, %d on site | %d matched, %d mismatched, %d missing, %d extra\n",
        $cpt_name . ':',
        $stats['spec'], $stats['site'],
        $stats['match'], $stats['mismatch'], $stats['missing'], $stats['extra']
    );

    foreach ($totals as $key => &$val) {
        $val += $stats[$key];
    }
    unset($val);
}

if (count($summary) > 1) {
    echo sprintf("\n  %-25s %d in spec, %d on site | %d matched, %d mismatched, %d missing, %d extra\n",
        'TOTAL:',
        $totals['spec'], $totals['site'],
        $totals['match'], $totals['mismatch'], $totals['missing'], $totals['extra']
    );
}

echo "\n";
if ($totals['mismatch'] === 0 && $totals['missing'] === 0 && $totals['extra'] === 0) {
    echo "All clear — spec and site categories match.\n";
} else {
    $parts = [];
    if ($totals['mismatch'] > 0) {
        $parts[] = "{$totals['mismatch']} category(ies) with mismatched data";
    }
    if ($totals['missing'] > 0) {
        $parts[] = "{$totals['missing']} category(ies) missing from site";
    }
    if ($totals['extra'] > 0) {
        $parts[] = "{$totals['extra']} extra category(ies) on site not in spec";
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
    echo "Category audit report written to: $output_file\n";
}
