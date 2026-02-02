<?php
/**
 * Media Cleaner - WordPress Unattached Image Scanner
 *
 * Identifies unattached media library images and reports where they are referenced.
 * Run via: OUT_FILE=/tmp/report.txt wp eval-file media-cleaner.php
 *
 * Environment Variables:
 *   OUT_FILE      - (Required) Path to output report file
 *   LIMIT         - (Optional) Process only first N unattached images
 *   DELETE        - (Optional) Set to 1 to delete unreferenced files
 *   VERBOSE       - (Optional) Set to 1 for progress output
 *   SKIP_POSTMETA - (Optional) Set to 1 to skip slow postmeta LIKE search
 *   SKIP_LIST     - (Optional) Comma-separated filename patterns to skip (supports * and ? wildcards)
 *                   Example: SKIP_LIST="logo*,icon-*,banner*.png"
 */

// Force immediate output
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
if ( function_exists( 'ob_implicit_flush' ) ) {
    ob_implicit_flush( true );
}

fwrite( STDERR, "DEBUG: Script starting...\n" );

// Ensure we're running in WP-CLI context
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Error: This script must be run via wp-cli eval-file\n" );
    exit( 1 );
}

fwrite( STDERR, "DEBUG: WP-CLI context confirmed\n" );

fwrite( STDERR, "DEBUG: Reading environment variables...\n" );

// Read environment variables
$out_file      = getenv( 'OUT_FILE' );
$limit         = getenv( 'LIMIT' ) ? intval( getenv( 'LIMIT' ) ) : 0;
$delete        = getenv( 'DELETE' ) === '1';
$verbose       = getenv( 'VERBOSE' ) === '1';
$skip_postmeta = getenv( 'SKIP_POSTMETA' ) === '1';
$skip_list_raw = getenv( 'SKIP_LIST' );
$GLOBALS['skip_patterns'] = array();

fwrite( STDERR, "DEBUG: SKIP_LIST raw value: " . var_export( $skip_list_raw, true ) . "\n" );

if ( ! empty( $skip_list_raw ) ) {
    $GLOBALS['skip_patterns'] = array_map( 'trim', explode( ',', $skip_list_raw ) );
    $GLOBALS['skip_patterns'] = array_filter( $GLOBALS['skip_patterns'] ); // Remove empty entries
}

fwrite( STDERR, "DEBUG: OUT_FILE={$out_file}, LIMIT={$limit}, VERBOSE=" . ($verbose ? '1' : '0') . ", SKIP_POSTMETA=" . ($skip_postmeta ? '1' : '0') . "\n" );
fwrite( STDERR, "DEBUG: SKIP_LIST patterns (" . count($GLOBALS['skip_patterns']) . "): " . implode( ', ', $GLOBALS['skip_patterns'] ) . "\n" );

// Validate required environment variable
if ( empty( $out_file ) ) {
    WP_CLI::error( 'OUT_FILE environment variable is required. Usage: OUT_FILE=/tmp/report.txt wp eval-file media-cleaner.php' );
}

fwrite( STDERR, "DEBUG: Environment validated, starting main execution...\n" );

/**
 * Output verbose message if VERBOSE=1
 */
function verbose_log( $message ) {
    global $verbose;
    if ( $verbose ) {
        WP_CLI::log( $message );
        // Flush output immediately
        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }
}

/**
 * Check if a filename matches any of the skip patterns
 * Supports glob-style wildcards: * (any characters) and ? (single character)
 */
function matches_skip_pattern( $filename ) {
    $skip_patterns = $GLOBALS['skip_patterns'] ?? array();

    fwrite( STDERR, "DEBUG: matches_skip_pattern('{$filename}'), patterns: " . count($skip_patterns) . "\n" );

    if ( empty( $skip_patterns ) ) {
        return false;
    }

    foreach ( $skip_patterns as $pattern ) {
        if ( fnmatch( $pattern, $filename, FNM_CASEFOLD ) ) {
            fwrite( STDERR, "DEBUG: MATCH! pattern '{$pattern}' matches '{$filename}'\n" );
            return $pattern;
        }
    }

    return false;
}

/**
 * Get all unattached images from the database
 */
function get_unattached_images( $limit = 0 ) {
    global $wpdb;

    $sql = "SELECT ID, guid, post_title, post_date
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
              AND post_mime_type LIKE 'image/%'
              AND post_parent = 0
            ORDER BY ID ASC";

    if ( $limit > 0 ) {
        $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
    }

    return $wpdb->get_results( $sql );
}

/**
 * Get all filenames associated with an attachment (original + all sizes)
 */
function get_image_filenames( $attachment_id ) {
    $filenames = array();

    // Get the original file path
    $file = get_attached_file( $attachment_id );
    if ( ! $file ) {
        return $filenames;
    }

    $filenames['original'] = basename( $file );
    $filenames['path']     = $file;

    // Get attachment metadata for generated sizes
    $metadata = wp_get_attachment_metadata( $attachment_id );
    if ( $metadata && ! empty( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            if ( ! empty( $size_data['file'] ) ) {
                $filenames['sizes'][ $size_name ] = $size_data['file'];
            }
        }
    }

    return $filenames;
}

/**
 * Search for references to a filename in post_content and postmeta
 */
function search_references( $attachment_id, $filenames ) {
    global $wpdb, $skip_postmeta;

    $references  = array();
    $posts_found = array();
    $meta_found  = array();

    // Build list of all filenames to search for
    $search_files = array();
    if ( ! empty( $filenames['original'] ) ) {
        $search_files[] = $filenames['original'];
    }
    if ( ! empty( $filenames['sizes'] ) ) {
        $search_files = array_merge( $search_files, array_values( $filenames['sizes'] ) );
    }

    if ( empty( $search_files ) ) {
        // Still check thumbnail_id even without filenames
        goto check_thumbnail;
    }

    // Build a single LIKE query with OR conditions for all filenames
    $like_conditions = array();
    foreach ( $search_files as $filename ) {
        $like_conditions[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $filename ) . '%' );
    }

    // Search post_content with batched OR conditions
    fwrite( STDERR, "DEBUG: Searching post_content for " . count($search_files) . " filenames...\n" );
    verbose_log( '    Searching post_content...' );
    $posts_found = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_status, post_content
         FROM {$wpdb->posts}
         WHERE (" . implode( ' OR ', $like_conditions ) . ")
         AND post_type != 'attachment'"
    );

    foreach ( $posts_found as $post ) {
        // Determine which filename matched
        $matched_file = 'unknown';
        foreach ( $search_files as $filename ) {
            if ( stripos( $post->post_content, $filename ) !== false ) {
                $matched_file = $filename;
                break;
            }
        }

        $key = 'post_content:' . $post->ID;
        if ( ! isset( $references[ $key ] ) ) {
            $references[ $key ] = array(
                'type'        => 'post_content',
                'post_id'     => $post->ID,
                'post_title'  => $post->post_title,
                'post_type'   => $post->post_type,
                'post_status' => $post->post_status,
                'filename'    => $matched_file,
            );
        }
    }

    fwrite( STDERR, "DEBUG: post_content search done, found " . count($posts_found) . " matches\n" );

    // Search postmeta (can be skipped with SKIP_POSTMETA=1 for performance)
    if ( $skip_postmeta ) {
        fwrite( STDERR, "DEBUG: Skipping postmeta search (SKIP_POSTMETA=1)\n" );
        verbose_log( '    Skipping postmeta search...' );
    } else {
        // Build LIKE conditions for postmeta
        $meta_like_conditions = array();
        foreach ( $search_files as $filename ) {
            $meta_like_conditions[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $filename ) . '%' );
        }

        // Search postmeta with batched OR conditions, join with posts to get title
        fwrite( STDERR, "DEBUG: Searching postmeta...\n" );
        verbose_log( '    Searching postmeta...' );
        $meta_found = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE " . implode( ' OR ', $meta_like_conditions )
        );

        foreach ( $meta_found as $meta ) {
            // Determine which filename matched
            $matched_file = 'unknown';
            foreach ( $search_files as $filename ) {
                if ( stripos( $meta->meta_value, $filename ) !== false ) {
                    $matched_file = $filename;
                    break;
                }
            }

            $key = 'postmeta:' . $meta->post_id . ':' . $meta->meta_key;
            if ( ! isset( $references[ $key ] ) ) {
                $references[ $key ] = array(
                    'type'        => 'postmeta',
                    'post_id'     => $meta->post_id,
                    'post_title'  => $meta->post_title,
                    'post_type'   => $meta->post_type,
                    'post_status' => $meta->post_status,
                    'meta_key'    => $meta->meta_key,
                    'filename'    => $matched_file,
                );
            }
        }

        fwrite( STDERR, "DEBUG: postmeta search done, found " . count($meta_found) . " matches\n" );
    }

    check_thumbnail:
    // Note: $meta_found may not exist if we jumped here via goto
    // Check for direct attachment ID references in _thumbnail_id (fast indexed query)
    fwrite( STDERR, "DEBUG: Checking thumbnail_id...\n" );
    verbose_log( '    Checking thumbnail_id...' );
    $thumbnail_refs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, p.post_title, p.post_type, p.post_status
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_thumbnail_id'
               AND pm.meta_value = %s",
            $attachment_id
        )
    );

    foreach ( $thumbnail_refs as $ref ) {
        $key = 'thumbnail:' . $ref->post_id;
        if ( ! isset( $references[ $key ] ) ) {
            $references[ $key ] = array(
                'type'        => 'thumbnail_id',
                'post_id'     => $ref->post_id,
                'post_title'  => $ref->post_title,
                'post_type'   => $ref->post_type,
                'post_status' => $ref->post_status,
                'meta_key'    => '_thumbnail_id',
            );
        }
    }

    return $references;
}

/**
 * Generate the report content
 */
function generate_report( $results, $delete_mode, $deleted_ids ) {
    $lines = array();

    $lines[] = '================================================================================';
    $lines[] = '                          MEDIA CLEANER REPORT';
    $lines[] = '================================================================================';
    $lines[] = '';
    $lines[] = 'Generated: ' . date( 'Y-m-d H:i:s' );
    $lines[] = 'Mode: ' . ( $delete_mode ? 'DELETE ENABLED' : 'Dry-run (report only)' );
    $lines[] = '';

    $total        = count( $results );
    $referenced   = 0;
    $unreferenced = 0;
    $skipped      = 0;

    foreach ( $results as $result ) {
        if ( ! empty( $result['skipped'] ) ) {
            $skipped++;
        } elseif ( ! empty( $result['references'] ) ) {
            $referenced++;
        } else {
            $unreferenced++;
        }
    }

    $lines[] = 'Summary:';
    $lines[] = '  Total unattached images:         ' . $total;
    $lines[] = '  Skipped (matched SKIP_LIST):     ' . $skipped;
    $lines[] = '  Images with references found:    ' . $referenced;
    $lines[] = '  Images with NO references:       ' . $unreferenced;

    if ( $delete_mode && ! empty( $deleted_ids ) ) {
        $lines[] = '  Images deleted:                  ' . count( $deleted_ids );
    }

    $lines[] = '';
    // Skipped files section
    if ( $skipped > 0 ) {
        $lines[] = '================================================================================';
        $lines[] = '                       SKIPPED FILES (SKIP_LIST)';
        $lines[] = '================================================================================';
        $lines[] = '';

        foreach ( $results as $result ) {
            if ( empty( $result['skipped'] ) ) {
                continue;
            }

            $lines[] = sprintf(
                '[%s] (ID: %d) - matched pattern: %s',
                $result['filenames']['original'] ?? 'unknown',
                $result['attachment_id'],
                $result['skip_pattern'] ?? 'unknown'
            );
        }

        $lines[] = '';
    }

    $lines[] = '================================================================================';
    $lines[] = '                       IMAGES WITH REFERENCES';
    $lines[] = '================================================================================';
    $lines[] = '';

    $has_referenced = false;
    foreach ( $results as $result ) {
        if ( ! empty( $result['skipped'] ) || empty( $result['references'] ) ) {
            continue;
        }
        $has_referenced = true;

        $lines[] = sprintf(
            '[%s] (ID: %d)',
            $result['filenames']['original'] ?? 'unknown',
            $result['attachment_id']
        );
        $lines[] = '  Referenced in:';

        foreach ( $result['references'] as $ref ) {
            switch ( $ref['type'] ) {
                case 'post_content':
                    $lines[] = sprintf(
                        '    - Post "%s" (ID: %d, type: %s, status: %s) in post_content',
                        $ref['post_title'],
                        $ref['post_id'],
                        $ref['post_type'],
                        $ref['post_status']
                    );
                    break;

                case 'postmeta':
                    $lines[] = sprintf(
                        '    - Postmeta "%s" (ID: %d, type: %s, status: %s) meta_key: %s',
                        $ref['post_title'] ?? '(no title)',
                        $ref['post_id'],
                        $ref['post_type'] ?? 'unknown',
                        $ref['post_status'] ?? 'unknown',
                        $ref['meta_key']
                    );
                    break;

                case 'thumbnail_id':
                    $lines[] = sprintf(
                        '    - Featured image for "%s" (ID: %d, type: %s, status: %s)',
                        $ref['post_title'] ?? '(no title)',
                        $ref['post_id'],
                        $ref['post_type'] ?? 'unknown',
                        $ref['post_status'] ?? 'unknown'
                    );
                    break;
            }
        }

        $lines[] = '';
    }

    if ( ! $has_referenced ) {
        $lines[] = '  (No images with references found)';
        $lines[] = '';
    }

    $lines[] = '================================================================================';
    $lines[] = '                    UNREFERENCED IMAGES (safe to delete)';
    $lines[] = '================================================================================';
    $lines[] = '';

    $has_unreferenced = false;
    foreach ( $results as $result ) {
        if ( ! empty( $result['skipped'] ) || ! empty( $result['references'] ) ) {
            continue;
        }
        $has_unreferenced = true;

        $deleted_marker = '';
        if ( $delete_mode && in_array( $result['attachment_id'], $deleted_ids, true ) ) {
            $deleted_marker = ' [DELETED]';
        }

        $lines[] = sprintf(
            '[%s] (ID: %d)%s',
            $result['filenames']['original'] ?? 'unknown',
            $result['attachment_id'],
            $deleted_marker
        );

        if ( ! empty( $result['filenames']['path'] ) ) {
            $lines[] = '  File path: ' . $result['filenames']['path'];
        }

        if ( ! empty( $result['filenames']['sizes'] ) ) {
            $lines[] = '  Sizes: ' . implode( ', ', $result['filenames']['sizes'] );
        }

        $lines[] = '';
    }

    if ( ! $has_unreferenced ) {
        $lines[] = '  (No unreferenced images found)';
        $lines[] = '';
    }

    $lines[] = '================================================================================';
    $lines[] = '                              END OF REPORT';
    $lines[] = '================================================================================';

    return implode( "\n", $lines );
}

/**
 * Delete unreferenced attachments
 */
function delete_unreferenced( $results ) {
    $deleted_ids = array();

    foreach ( $results as $result ) {
        // Skip files that were skipped or have references
        if ( ! empty( $result['skipped'] ) || ! empty( $result['references'] ) ) {
            continue;
        }

        $attachment_id = $result['attachment_id'];
        verbose_log( "Deleting attachment ID: {$attachment_id}" );

        $deleted = wp_delete_attachment( $attachment_id, true );

        if ( $deleted ) {
            $deleted_ids[] = $attachment_id;
            verbose_log( "  Successfully deleted." );
        } else {
            verbose_log( "  Failed to delete." );
        }
    }

    return $deleted_ids;
}

// =============================================================================
// Main execution
// =============================================================================

$start_time = microtime( true );

verbose_log( 'Media Cleaner starting...' );
verbose_log( 'Output file: ' . $out_file );
if ( $limit > 0 ) {
    verbose_log( 'Limit: ' . $limit . ' images' );
}
if ( $delete ) {
    verbose_log( 'DELETE MODE ENABLED - unreferenced files will be removed!' );
}

// Get unattached images
fwrite( STDERR, "DEBUG: About to query for unattached images...\n" );
verbose_log( 'Querying for unattached images...' );
$unattached = get_unattached_images( $limit );
$total      = count( $unattached );
fwrite( STDERR, "DEBUG: Query complete, found {$total} images\n" );
verbose_log( "Found {$total} unattached images." );

if ( $total === 0 ) {
    WP_CLI::success( 'No unattached images found.' );
    exit( 0 );
}

// Process each image
$results = array();
$current = 0;

foreach ( $unattached as $attachment ) {
    $current++;
    $img_start = microtime( true );
    $filename_display = '';

    $filenames = get_image_filenames( $attachment->ID );
    if ( ! empty( $filenames['original'] ) ) {
        $filename_display = " ({$filenames['original']})";
    }

    fwrite( STDERR, "DEBUG: Processing {$current}/{$total}: ID {$attachment->ID}{$filename_display}\n" );
    verbose_log( "Processing {$current}/{$total}: ID {$attachment->ID}{$filename_display}" );

    // Check if this file matches a skip pattern
    $skip_match = false;
    if ( ! empty( $filenames['original'] ) ) {
        $skip_match = matches_skip_pattern( $filenames['original'] );
    }

    if ( $skip_match !== false ) {
        fwrite( STDERR, "DEBUG: Skipping - matches pattern: {$skip_match}\n" );
        verbose_log( "    Skipped (matches pattern: {$skip_match})" );

        $results[] = array(
            'attachment_id' => $attachment->ID,
            'guid'          => $attachment->guid,
            'post_title'    => $attachment->post_title,
            'filenames'     => $filenames,
            'references'    => array(),
            'skipped'       => true,
            'skip_pattern'  => $skip_match,
        );
        continue;
    }

    $references = search_references( $attachment->ID, $filenames );

    $results[] = array(
        'attachment_id' => $attachment->ID,
        'guid'          => $attachment->guid,
        'post_title'    => $attachment->post_title,
        'filenames'     => $filenames,
        'references'    => $references,
        'skipped'       => false,
    );

    $img_elapsed = round( microtime( true ) - $img_start, 2 );
    if ( ! empty( $references ) ) {
        verbose_log( "    Found " . count( $references ) . " reference(s) [{$img_elapsed}s]" );
    } else {
        verbose_log( "    No references found [{$img_elapsed}s]" );
    }
}

// Delete unreferenced if DELETE=1
$deleted_ids = array();
if ( $delete ) {
    verbose_log( 'Deleting unreferenced images...' );
    $deleted_ids = delete_unreferenced( $results );
    verbose_log( 'Deleted ' . count( $deleted_ids ) . ' images.' );
}

// Generate and write report
verbose_log( 'Generating report...' );
$report = generate_report( $results, $delete, $deleted_ids );

$written = file_put_contents( $out_file, $report );
if ( $written === false ) {
    WP_CLI::error( "Failed to write report to: {$out_file}" );
}

// Summary
$unreferenced_count = 0;
$skipped_count      = 0;
foreach ( $results as $r ) {
    if ( ! empty( $r['skipped'] ) ) {
        $skipped_count++;
    } elseif ( empty( $r['references'] ) ) {
        $unreferenced_count++;
    }
}

$total_elapsed = round( microtime( true ) - $start_time, 2 );

WP_CLI::success( "Report written to: {$out_file}" );
WP_CLI::log( "  Total scanned: {$total}" );
WP_CLI::log( "  Skipped: {$skipped_count}" );
WP_CLI::log( "  With references: " . ( $total - $unreferenced_count - $skipped_count ) );
WP_CLI::log( "  Unreferenced: {$unreferenced_count}" );
WP_CLI::log( "  Elapsed time: {$total_elapsed}s" );

if ( $delete ) {
    WP_CLI::log( "  Deleted: " . count( $deleted_ids ) );
}
