# WordPress ACF to GeoDirectory CSV Transformation Project

## Project Overview

This project transforms WordPress Advanced Custom Fields (ACF) export data into GeoDirectory-compatible CSV import format. The primary script handles complex HTML content transformation, including Beaver Builder tab extraction, image deduplication, YouTube URL extraction, and field mapping.

## Key Files

### transform.py
**Purpose**: Main transformation script that converts ACF CSV exports to GeoDirectory CSV format

**Key Features**:
- HTML tab content extraction and preservation
- Image deduplication and management
- YouTube URL extraction from embedded iframes
- DateTime formatting for WordPress compatibility
- Batch processing with file splitting
- Category and tag mapping
- Custom field transformation
- Selective entry processing (include/exclude filters)

**Dependencies**:
```bash
pip install beautifulsoup4
```

### validate_html_csv.py
**Purpose**: Standalone HTML validation utility for CSV columns

**Features**:
- HTML structure validation (tag balance)
- Security checks (scripts, inline handlers, XSS vectors)
- Content analysis (element counts)
- Detailed reporting with issue identification
- Verbose mode for detailed per-entry analysis

### stcroix_locations.py
**Purpose**: Location mapping module for St. Croix regions
- Maps location names to standardized IDs and hierarchies

### Source Data Files

- **acf-full-export.csv**: Source ACF export (795 entries)
- **gd_stay.csv**: Generated GeoDirectory import CSV
- **[1-12]-gd_stay.csv**: Batch-processed output files

## Command-Line Usage

### Basic Transformation
```bash
python3 transform.py acf-full-export.csv -o gd_stay.csv
```

### Key Arguments

**--clean-tab-contents**
- Removes extracted tab content from post_content field
- Tabs are always extracted to tab fields regardless of this flag
- Use when you want clean post content without duplicate tab data

**--entries N**
- Splits output into multiple files with N entries per file
- Output format: 1-{outfile}.csv, 2-{outfile}.csv, etc.
- Useful for avoiding import timeouts on large datasets
- Example: `--entries 100` creates batches of 100 entries

**--include "Title1" "Title2"**
- Process only entries matching specified post_title values
- Can specify multiple titles
- Ignores all other entries
- Useful for testing specific entries

**--exclude "Title1" "Title2"**
- Process all entries EXCEPT those matching specified post_title values
- Can specify multiple titles
- Useful for skipping problematic entries

**--stdout**
- Write output to stdout instead of file
- Useful for piping or inspection

**--verbose**
- Enable detailed logging during processing

### Complete Example
```bash
python3 transform.py acf-full-export.csv \
  -o gd_stay.csv \
  --entries 100 \
  --clean-tab-contents \
  --verbose
```

## Data Flow

### Input: ACF Export CSV
Contains WordPress posts with ACF custom fields:
- Post metadata (title, content, date, author)
- ACF custom fields (various naming patterns)
- Categories and tags (semicolon-separated)
- Embedded HTML content (Beaver Builder tabs, YouTube iframes)

### Processing Steps

1. **HTML Tab Extraction** (`parse_tabs()`)
   - Identifies Beaver Builder tab structure by text repetition
   - Extracts tab names and content
   - Stops at `<h2>` tags (marks end of tabs section)
   - Optionally removes tabs from post_content

2. **Image Processing** (`extract_and_deduplicate_images()`)
   - Extracts all `<img>` tags from content
   - Deduplicates by src URL
   - Preserves order of first occurrence
   - Outputs |||::-separated list

3. **YouTube URL Extraction** (`extract_youtube_urls()`)
   - Finds embedded YouTube iframes
   - Extracts video URLs
   - Deduplicates URLs
   - Returns both single URL and |||::-separated list

4. **DateTime Formatting** (`format_datetime()`)
   - Converts date-only values (YYYY-MM-DD) to WordPress format
   - Appends " 00:00:00" time component
   - Handles existing datetime values
   - Required for GeoDirectory import

5. **Field Mapping**
   - Maps ACF fields to GeoDirectory fields
   - Handles multiple ACF field naming patterns
   - Transforms categories and tags
   - Maps location data using stcroix_locations module

6. **Batch Output** (if --entries specified)
   - Splits large datasets into manageable files
   - Each file gets numbered prefix (1-, 2-, 3-, etc.)
   - Prevents import timeouts
   - Allows incremental processing

### Output: GeoDirectory CSV
Contains transformed data ready for GeoDirectory import:
- WordPress post fields (post_title, post_content, post_date, etc.)
- GeoDirectory listing fields
- Tab fields (tab1_name, tab1_html, tab2_name, tab2_html, etc.)
- Image and video URLs
- Mapped categories, tags, and locations

## Field Mappings

### Tab Fields (HTML Custom Fields)
- **tab1_html** through **tab5_html**: Tab content with HTML preserved
- **tab1_name** through **tab5_name**: Tab display names

Note: These MUST be HTML custom fields in GeoDirectory, not text or textarea fields, to preserve HTML tags.

### Special Fields
- **post_images**: Deduplicated |||::-separated image URLs
- **youtube_url**: Single YouTube URL (first found)
- **youtube_urls**: All |||::-separated YouTube URLs
- **post_date**: YYYY-MM-DD HH:MM:SS format
- **post_modified**: YYYY-MM-DD HH:MM:SS format

### ACF Field Name Patterns
The script handles multiple ACF naming conventions:
- Standard: `field_name`
- Prefixed: `acf_field_name`
- Alternate case variations

## Important Implementation Details

### Tab Extraction Algorithm

The `parse_tabs()` function uses a sophisticated heuristic to identify Beaver Builder tab structures:

1. Scans HTML for text that appears 2+ times (tab titles are duplicated)
2. Finds the last occurrence of each repeated text
3. Extracts content between last occurrence and next tab title
4. Stops extraction at `<h2>` tags (non-tab content marker)
5. Conditionally removes tabs from post_content based on `--clean-tab-contents` flag

**Key behavior**: Tabs are ALWAYS extracted to tab fields. The `--clean-tab-contents` flag only controls whether they're also removed from post_content.

### HTML Validation

The project includes comprehensive HTML validation via `validate_html_csv.py`:

**Validation checks**:
- Tag balance (opening/closing pairs)
- Major HTML tags (div, p, h1-h6, ul, ol, li, a, table, etc.)
- Custom WordPress tags (broadstreet-zone)
- Security issues (script tags, inline event handlers, data URIs)
- Control characters and null bytes
- Attribute length validation

**Usage**:
```bash
# Basic validation
python3 validate_html_csv.py gd_stay.csv post_content

# Detailed analysis
python3 validate_html_csv.py gd_stay.csv post_content --verbose
```

**Exit codes**:
- 0: All entries valid
- 1: Issues found

### DateTime Handling

WordPress requires full datetime format: `YYYY-MM-DD HH:MM:SS`

ACF exports often contain date-only values: `YYYY-MM-DD`

The `format_datetime()` function ensures compatibility:
- Detects date-only values (no space or T separator)
- Appends " 00:00:00" time component
- Preserves existing datetime values
- Handles ISO format (replaces T with space)

### Image Deduplication

Images are deduplicated by URL to prevent duplicate imports:
- Extracts all `<img src="...">` from post_content
- Maintains first occurrence order
- Outputs as |||::-separated list
- Preserves relative and absolute URLs

## Known Issues and Limitations

### GeoDirectory Export CSV Issues
- GeoDirectory's export can corrupt CSVs when post_images contains multiple values
- The |||:: separator may be split into separate columns (111 columns instead of 53)
- Workaround: Use freshly exported CSVs; don't rely on previously exported data for comparison

### Import Timeouts
- Large CSV imports (795+ entries) may timeout
- Solution: Use `--entries` parameter to split into batches of 50-100 entries
- Import files sequentially; can skip/retry problematic files

### Field Type Requirements
- Tab content fields MUST be HTML custom fields in GeoDirectory
- Text fields: Too small (character limit)
- Textarea fields: Strip HTML tags (convert to plain text)
- HTML fields: Preserve tags and formatting correctly

### Source Data Limitations
- ACF exports only contain dates, not full datetimes
- Default time "00:00:00" is appended to all entries
- Original post times are not preserved (not available in ACF export)

## Testing Approach

### Validation Workflow
1. Generate output CSV using transform.py
2. Validate HTML content: `python3 validate_html_csv.py output.csv post_content`
3. Spot-check specific entries using `--include` filter
4. Import small batches first (10-50 entries)
5. Export from GeoDirectory to verify import success
6. Compare key fields between generated and exported CSVs

### Common Test Commands
```bash
# Test single entry
python3 transform.py acf-full-export.csv -o test.csv --include "Entry Name"

# Validate HTML
python3 validate_html_csv.py test.csv post_content --verbose

# Generate small batch
python3 transform.py acf-full-export.csv -o batch.csv --entries 10

# Test with clean tab contents
python3 transform.py acf-full-export.csv -o clean.csv --clean-tab-contents --entries 5
```

### Comparison Testing
When comparing generated vs exported entries:
1. Export specific entry from GeoDirectory after import
2. Use `--include` filter to generate same entry
3. Compare key fields manually (post_title, post_content, tab fields)
4. Check HTML preservation in tab fields
5. Verify image and YouTube URL extraction

## Statistics (acf-full-export.csv)

- **Total entries**: 795
- **Processing time**: ~5-10 seconds for full dataset
- **Average entry size**: ~15KB HTML content
- **Tabs found**: ~60% of entries contain extractable tabs
- **Images per entry**: 3-5 average, up to 20+ in some entries

## Batch Processing Recommendations

For 795 entries:
- **50 entries/file**: 16 files (good for cautious imports)
- **100 entries/file**: 8 files (recommended balance)
- **150 entries/file**: 6 files (faster processing)

Higher batch sizes risk timeout; lower batch sizes increase manual work.

## Change History

### Major Changes
1. **Tab extraction fix**: Added conditional removal based on `--clean-tab-contents` flag
2. **H2 boundary detection**: Tabs now stop at `<h2>` tags to prevent over-collection
3. **Field name evolution**: tab1_contents → old_tab1_contents → tab1_html (HTML field type)
4. **Batch processing**: Added `--entries` parameter for file splitting
5. **DateTime formatting**: Added time component to date-only values
6. **HTML validation**: Created standalone validation utility

## Future Considerations

### Potential Enhancements
- Parallel processing for large datasets
- Preview mode (show transformations without writing)
- Diff mode (compare generated vs exported CSVs)
- Field mapping configuration file (JSON/YAML)
- Progress bar for large datasets
- Dry-run mode with statistics

### Known Improvement Areas
- Tab extraction heuristic could be more robust
- Could preserve original post times if available from different export
- Could validate GeoDirectory field types before generation
- Could auto-detect optimal batch size based on entry complexity

## Support Files

### Category Mapping
- Categories from ACF export map to GeoDirectory taxonomy
- Semicolon-separated values in source
- Mapped to numeric IDs in output

### Location Mapping
- Handled by `stcroix_locations.py` module
- Maps location names to standardized hierarchy
- Supports St. Croix region-specific locations

## Success Criteria

A successful transformation includes:
1. All 795 entries processed without errors
2. HTML validation passes for all post_content
3. Tab content properly extracted and preserved
4. Images deduplicated correctly
5. DateTime fields formatted properly
6. GeoDirectory import completes without timeout
7. Exported data matches generated data in key fields
8. HTML tags preserved in tab fields

## Contact and Maintenance

This project is part of the gotostcroix.com website migration from ACF to GeoDirectory. The transformation script is designed for one-time use but can be adapted for similar ACF-to-GeoDirectory migrations.

For issues or questions, refer to the conversation history or the full codebase at:
`/home/sfeltner/Projects/gotostcroix/transform/`

---

**Last Updated**: 2026-01-09
**Script Version**: transform.py (795-entry compatible)
**Python Version**: 3.11+
**Primary Maintainer**: Documentation generated from conversation history
