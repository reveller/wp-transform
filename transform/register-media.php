<?php
/**
 * Register Unregistered Media Files
 *
 * Scans wp-content/uploads/ for image files that are not registered
 * in the WordPress Media Library and creates attachment posts for them.
 *
 * Only processes original files (skips WP-generated size variants like
 * image-300x200.jpg). WordPress will regenerate thumbnails on registration.
 *
 * Usage:
 *   wp eval-file register-media.php
 *
 * Options (set as environment variables):
 *   DRY_RUN=1           Preview without registering (default: 0)
 *   SUBDIR=path         Only scan a specific subdirectory (e.g., "2024/03")
 *   LIMIT=N             Process at most N files (for testing)
 *
 * Examples:
 *   DRY_RUN=1 wp eval-file register-media.php
 *   DRY_RUN=1 SUBDIR=2024/03 wp eval-file register-media.php
 *   LIMIT=10 wp eval-file register-media.php
 *   wp eval-file register-media.php
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

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// ============================================================
// Configuration
// ============================================================
$dry_run = getenv('DRY_RUN') === '1';
$subdir  = getenv('SUBDIR') ?: '';
$limit   = (int)(getenv('LIMIT') ?: 0);

$upload_dir = wp_get_upload_dir();
$base_dir   = $upload_dir['basedir'];
$base_url   = $upload_dir['baseurl'];

$scan_dir = $subdir ? $base_dir . '/' . trim($subdir, '/') : $base_dir;

echo "=== Register Unregistered Media ===\n";
echo "Uploads dir:   $base_dir\n";
echo "Scanning:      $scan_dir\n";
echo "Dry run:       " . ($dry_run ? 'YES' : 'NO') . "\n";
if ($limit) {
    echo "Limit:         $limit files\n";
}
echo "\n";

if (!is_dir($scan_dir)) {
    die("Error: Directory not found: $scan_dir\n");
}

// ============================================================
// Build index of already-registered files
// ============================================================
echo "Building index of registered media...\n";

global $wpdb;
$registered = [];

// Query all _wp_attached_file meta values
$results = $wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
    ARRAY_A
);

foreach ($results as $row) {
    // meta_value is relative to uploads dir, e.g. "2024/03/image.jpg"
    $registered[$row['meta_value']] = (int)$row['post_id'];
}

echo "Registered attachments: " . count($registered) . "\n\n";

// ============================================================
// Scan filesystem for originals
// ============================================================
$extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
$size_pattern = '/-\d+x\d+\.[a-z]{3,4}$/i';

$stats = [
    'files_scanned'   => 0,
    'already_registered' => 0,
    'size_variants'   => 0,
    'registered'      => 0,
    'errors'          => 0,
];

// Directories to skip (caches, auto-generated content)
$skip_dirs = ['elementor', 'elementor/thumbs', 'wc-logs', 'cache'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scan_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$to_register = [];

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    // Skip files in excluded directories
    $rel_dir = ltrim(str_replace($base_dir, '', $file->getPath()), '/');
    $skip = false;
    foreach ($skip_dirs as $sd) {
        if (strpos($rel_dir, $sd) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $extensions)) {
        continue;
    }

    $stats['files_scanned']++;

    $abs_path = $file->getPathname();
    $basename = $file->getBasename();

    // Skip WP-generated size variants
    if (preg_match($size_pattern, $basename)) {
        $stats['size_variants']++;
        continue;
    }

    // Get path relative to uploads dir
    $relative = ltrim(str_replace($base_dir, '', $abs_path), '/');

    // Check if already registered
    if (isset($registered[$relative])) {
        $stats['already_registered']++;
        continue;
    }

    $to_register[] = [
        'abs_path' => $abs_path,
        'relative' => $relative,
        'basename' => $basename,
    ];
}

echo "Files scanned:       {$stats['files_scanned']}\n";
echo "Size variants:       {$stats['size_variants']}\n";
echo "Already registered:  {$stats['already_registered']}\n";
echo "To register:         " . count($to_register) . "\n\n";

if (empty($to_register)) {
    echo "Nothing to register.\n";
    exit;
}

// ============================================================
// Register files
// ============================================================
$processed = 0;

foreach ($to_register as $item) {
    if ($limit && $processed >= $limit) {
        echo "Limit of $limit reached, stopping.\n";
        break;
    }

    $abs_path = $item['abs_path'];
    $relative = $item['relative'];
    $basename = $item['basename'];

    $processed++;

    if ($dry_run) {
        echo "  [dry-run] Would register: $relative\n";
        $stats['registered']++;
        continue;
    }

    // Determine MIME type
    $mime = wp_check_filetype($basename);
    if (empty($mime['type'])) {
        echo "  SKIP (unknown type): $relative\n";
        continue;
    }

    // Create attachment post
    $attachment = [
        'post_mime_type' => $mime['type'],
        'post_title'     => pathinfo($basename, PATHINFO_FILENAME),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $abs_path);

    if (is_wp_error($attach_id) || !$attach_id) {
        echo "  ERROR registering: $relative\n";
        $stats['errors']++;
        continue;
    }

    // Generate metadata (thumbnails, sizes, etc.)
    $metadata = wp_generate_attachment_metadata($attach_id, $abs_path);
    wp_update_attachment_metadata($attach_id, $metadata);

    $stats['registered']++;

    if ($processed % 100 === 0) {
        echo "  Registered $processed / " . count($to_register) . "...\n";
    }
}

// ============================================================
// Report
// ============================================================
echo "\n=== Summary ===\n";
echo "Files scanned:       {$stats['files_scanned']}\n";
echo "Size variants:       {$stats['size_variants']}\n";
echo "Already registered:  {$stats['already_registered']}\n";
echo "Newly registered:    {$stats['registered']}\n";
echo "Errors:              {$stats['errors']}\n";
