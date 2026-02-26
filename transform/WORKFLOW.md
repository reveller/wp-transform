# GoToStCroix Data Import & Repair Workflow

Reference guide for importing GeoDirectory business listings and blog posts
to the WordPress dev/production site, including image deduplication,
metadata repair, and content cleaning.

All PHP scripts run via WP-CLI:
```bash
wp --path=/home/staging_gotodev/www eval-file <script.php>
```

All Python scripts run from the `transform/` directory.

Every script supports `DRY_RUN=1` (PHP) or `--dry-run` (Python).
**Always dry-run first.**

---

## Table of Contents

1. [GeoDirectory Listings Workflow](#geodirectory-listings-workflow)
2. [Blog Posts Workflow](#blog-posts-workflow)
3. [Script Reference](#script-reference)
4. [Content Cleaning Reference](#content-cleaning-reference)
5. [Key Files & Locations](#key-files--locations)

---

## GeoDirectory Listings Workflow

**CPTs:** gd_place (Places to Stay), gd_foodanddrink (Food and Drink),
gd_gettingaround (Getting Around), gd_islandliving (Island Living),
gd_thingstodo (Things to Do), gd_event (Events), gd_guides (Guides),
gd_specialoffers (Special Offers)

### Step 1: Import CSV via GeoDirectory

Use the GeoDirectory CSV import UI in wp-admin.

### Step 2: Audit listings

Verify imports landed correctly and check for duplicates.

```bash
CSV_FILE="done/*.csv" DRY_RUN=1 wp eval-file audit-listings.php
```

### Step 3: Deduplicate images

GD imports can create revision copies of images (e.g., `image-1.jpg`,
`image-3.jpg`). This script consolidates them back to the original.

```bash
# Dry run first
CPT_NAME="Places to Stay" DRY_RUN=1 wp eval-file dedup-images.php

# Then run for real
CPT_NAME="Places to Stay" wp eval-file dedup-images.php
```

### Step 4: Repair GD metadata

After dedup, the attachment metadata may reference old filenames. This
rebuilds it so image galleries and thumbnails resolve correctly.

```bash
CPT_NAME="Places to Stay" DRY_RUN=1 wp eval-file repair-gd-metadata.php
CPT_NAME="Places to Stay" wp eval-file repair-gd-metadata.php
```

### Step 5: Repair featured images

Fixes three systems at once: the `featured=1` flag in geodir_attachments,
the `featured_image` column in the GD detail table, and `_thumbnail_id`
in wp_postmeta.

```bash
CPT_NAME="Places to Stay" DRY_RUN=1 wp eval-file repair-featured-images.php
CPT_NAME="Places to Stay" wp eval-file repair-featured-images.php
```

### Run all CPTs at once

Omit `CPT_NAME` to process every GeoDirectory CPT in a single pass:

```bash
DRY_RUN=1 wp eval-file repair-gd-metadata.php
DRY_RUN=1 wp eval-file repair-featured-images.php
```

---

## Blog Posts Workflow

### Step 1: Export CSV from staging site

Use WP All Export on the staging site to export posts. The CSV should have
these 30 columns: ID, Title, Content, Excerpt, Date, Post Type, Permalink,
Image URL, Image Title, Image Caption, Image Description, Image Alt Text,
Image Featured, Attachment URL, Categories, Tags, Status, Author ID,
Author Username, Author Email, Author First Name, Author Last Name, Slug,
Format, Template, Parent, Parent Slug, Order, Comment Status, Ping Status,
Post Modified Date.

### Step 2: Transform the CSV

Filters to published Blog posts, strips Beaver Builder / page-builder
tags from content, and remaps author fields using `authors.json`.

```bash
# Dry run to check counts
python transform-posts.py Posts-Staging-20260225.csv --strip-id --dry-run

# Test with first 5 rows
python transform-posts.py Posts-Staging-20260225.csv --strip-id --test

# Full run
python transform-posts.py Posts-Staging-20260225.csv --strip-id

# After importing the test set, process the rest
python transform-posts.py Posts-Staging-20260225.csv --strip-id --not-test
```

**Output:** `Posts-Staging-20260225-transformed.csv`

### Step 3: Import via WP All Import

Use the WP All Import plugin (free version) in wp-admin. Import the
transformed CSV. WP All Import handles downloading inline content images
and rewriting their URLs to relative paths automatically.

### Step 4: Attach featured images

WP All Import free doesn't handle featured images from URLs. This script
downloads them from the staging site and sets `_thumbnail_id`.

```bash
# Dry run
CSV_FILE=Posts-Staging-20260225-transformed.csv DRY_RUN=1 \
  wp eval-file import-featured-images.php

# Run for real
CSV_FILE=Posts-Staging-20260225-transformed.csv \
  wp eval-file import-featured-images.php
```

### Step 5: Import content images (if needed)

If WP All Import didn't handle inline images (check post content for
staging URLs), this script downloads them and rewrites URLs to relative
paths (`/wp-content/uploads/...`) so they work on any domain.

```bash
CSV_FILE=Posts-Staging-20260225-transformed.csv DRY_RUN=1 \
  wp eval-file import-content-images.php

CSV_FILE=Posts-Staging-20260225-transformed.csv \
  wp eval-file import-content-images.php
```

**Note:** In our February 2026 import, WP All Import handled content
images automatically — this script found nothing to do. It exists as a
safety net.

### For Pages (future)

Same workflow, different flags:

```bash
python transform-posts.py Pages-Export.csv --post-type page --category "" --strip-id
```

---

## Script Reference

### audit-listings.php

Compares GeoDirectory CSV imports against live site listings.

| Variable | Default | Description |
|---|---|---|
| `CSV_FILE` | *(required)* | File glob pattern: `gd_Stay.csv` or `done/*.csv` |
| `OUTPUT_FILE` | stdout | Write report to file |

### dedup-images.php

Removes revision duplicate images from GeoDirectory attachments.

| Variable | Default | Description |
|---|---|---|
| `CPT_NAME` | *(required)* | CPT display name or slug |
| `DRY_RUN` | 0 | Preview without executing |
| `POST_TITLE` | | Filter to single post |
| `OUTPUT_FILE` | stdout | Write report to file |
| `REGISTER_MEDIA` | 0 | Register GD images into WP Media Library |
| `AUDIT_IMAGES` | 0 | Audit post_images field (verify files exist) |

### repair-gd-metadata.php

Fixes GD attachment metadata after dedup so resolution variants resolve.

| Variable | Default | Description |
|---|---|---|
| `CPT_NAME` | all CPTs | CPT display name or slug |
| `DRY_RUN` | 0 | Preview without executing |
| `POST_TITLE` | | Filter to single post |
| `OUTPUT_FILE` | stdout | Write report to file |

### repair-featured-images.php

Repairs featured image references across GD attachments, detail table,
and wp_postmeta.

| Variable | Default | Description |
|---|---|---|
| `CPT_NAME` | all CPTs | CPT display name or slug |
| `DRY_RUN` | 0 | Preview without executing |
| `POST_TITLE` | | Filter to single post |
| `OUTPUT_FILE` | stdout | Write report to file |

### import-featured-images.php

Downloads featured image URLs from CSV and attaches as post thumbnails.

| Variable | Default | Description |
|---|---|---|
| `CSV_FILE` | Post-First-Five.csv | Path to transformed CSV |
| `DRY_RUN` | 0 | Preview without downloading |
| `POST_SLUG` | | Process single post by slug |
| `SKIP_EXISTING` | 1 | Skip posts that already have a featured image |

### import-content-images.php

Downloads staging-site images from post content, rewrites URLs to
relative paths.

| Variable | Default | Description |
|---|---|---|
| `CSV_FILE` | Post-First-Five.csv | Path to transformed CSV (for slug list) |
| `DRY_RUN` | 0 | Preview without downloading |
| `POST_SLUG` | | Process single post by slug |
| `STAGING_HOST` | staging-gotostcroix.wordkeeper.net | Hostname to match |

### transform-posts.py

Filters and cleans blog posts/pages CSV for WP All Import.

| Flag | Default | Description |
|---|---|---|
| `input_csv` | *(required)* | Input CSV file path |
| `--output, -o` | `<input>-transformed.csv` | Output CSV filename |
| `--authors-file` | `authors.json` | Author mapping file |
| `--category` | `Blog` | Category filter (comma-separated). `""` = no filter |
| `--status` | `publish` | Post status filter |
| `--post-type` | `post` | Post type filter. Use `page` for pages |
| `--strip-id` | off | Clear ID field (creates new posts on import) |
| `--test` | off | Process first 5 matching rows only |
| `--not-test` | off | Skip first 5, process the rest |
| `--dry-run` | off | Report counts without writing files |

### authors.json

Maps staging-site authors to dev-site authors. Keyed by email address.
Edit this file to control how author fields are remapped during transform.

```json
[
  {
    "author_id": "2",
    "username": "jennie",
    "email": "jennie@gotostcroix.com",
    "first_name": "Jennie",
    "last_name": "Odgen"
  },
  {
    "author_id": "1",
    "username": "gotodev",
    "email": "wendy@gotostcroix.com",
    "first_name": "Wendy",
    "last_name": "Solomon"
  }
]
```

---

## Content Cleaning Reference

`filter_beaver_builder_tags()` in `transform.py` strips these from
blog post content:

| Pattern | Example |
|---|---|
| WP block BB comments | `<!-- wp:fl-builder/layout -->` |
| Legacy BB comments | `<!-- fl-builder-abc123 -->` |
| Unicode-escaped BB comments | `\u003c!\u002d\u002d wp:fl-builder... \u002d\u002d\u003e` |
| wpbb shortcodes | `[wpbb post:title]` |
| display-posts shortcodes | `[display-posts taxonomy="category" ...]` |
| fl_builder shortcodes | `[fl_builder_insert ...]` |
| associated-posts shortcodes | `[associated-posts]` |
| Explore-meta blocks | `<div class="explore-meta">Read More</div>` |
| Explore-meta (Do More) | `<div class="explore-meta">Do More</div>` |
| Explore-meta (Learn More) | `<div class="explore-meta">Learn More</div>` |
| Empty explore-meta divs | `<div class="explore-meta"></div>` |
| Empty WP shortcode blocks | `<!-- wp:shortcode --><p></p><!-- /wp:shortcode -->` |
| Empty WP html blocks | `<!-- wp:html --><!-- /wp:html -->` |

Standard WordPress block comments (`<!-- wp:paragraph -->`, etc.) are
preserved.

---

## Key Files & Locations

| Item | Path |
|---|---|
| Transform scripts | `/home/sfeltner/Projects/gotostcroix/transform/` |
| WordPress root (dev) | `/home/staging_gotodev/www` |
| WP-CLI command prefix | `wp --path=/home/staging_gotodev/www` |
| CPT/taxonomy definitions | `transform/gd-taxonomy-cpts.json` |
| Author mapping | `transform/authors.json` |
| Staging CSV exports | `transform/Posts-Staging-20260225.csv` |
| Transformed output | `transform/Posts-Staging-20260225-transformed.csv` |
| WP uploads (dev) | `/home/staging_gotodev/www/wp-content/uploads/` |
