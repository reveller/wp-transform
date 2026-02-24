# Plan: Restructure Taxonomy Mapping for CPT-Based Architecture

## Decisions Made

- **CPT Structure**: Each CPT has `post_type` (for output) and `slug` (for reference)
- **Tags**: Specific to each CPT (nested under CPT section)
- **Aliases**: Simplified to string array `["Hotels", "Small Inns"]`
- **post_category**: Single category ID only (no parent)
- **default_category**: Reflects the single post_category
- **Approach**: Option A - New dedicated loader function

## Understanding the Change

### Current Architecture (Flat JSON)
```
Listings (single CPT: gd_listing)
└── Places to Stay (category, id: 2093)
    └── Resorts + Hotels (subcategory, id: 2094)
        └── Hotels (alias → id: 2094)
```

- Single post_type: `gd_listing`
- post_category output: `,2093,2094,` (parent + child)
- 178 flat entries with type/parent_id fields
- Aliases are duplicate entries with same ID

### New Architecture (Nested JSON by CPT)
```
Places to Stay (CPT: gd_placestostay)
└── Resorts + Hotels (category, id: 2094)
    └── Hotels (alias → id: 2094)
```

- Multiple post_types based on CPT
- post_category output: `,2094,` (just category, no parent)
- Nested structure: CPT → categories → aliases
- Much cleaner, easier to maintain

## Proposed New JSON Structure

```json
[
  {
    "cpt": "Places to Stay",
    "cpt_slug": "gd_placestostay",    // <-- Need to clarify format
    "categories": [
      {
        "name": "Resorts + Hotels",
        "id": 2094,
        "slug": "resorts-hotels",
        "aliases": ["Hotels", "Hotels and Inns", "Resorts", "Small Inns"]
      },
      {
        "name": "Vacation Rentals",
        "id": 2095,
        "slug": "vacation-rentals",
        "aliases": ["Vacation Rentals and Villas", "1-2 Bedroom Rentals"]
      }
    ],
    "tags": [
      // Tags specific to this CPT? Or global tags section?
    ]
  },
  {
    "cpt": "Things to Do",
    "cpt_slug": "gd_thingstodo",
    "categories": [...]
  }
]
```

## Questions for Clarification

### 1. CPT Slug Format
What is the GeoDirectory post_type slug for each CPT?
- `gd_placestostay` (no spaces)?
- `gd_places_to_stay` (underscores)?
- Something else from GeoDirectory admin?

### 2. Tags Handling
Are tags:
- **A)** Global across all CPTs (separate section)?
- **B)** Specific to each CPT (nested under CPT)?
- **C)** Both (some global, some CPT-specific)?

### 3. Output Format Changes
With categories now being top-level (no parent), the post_category output changes:
- **Before:** `,2093,2094,` (parent + subcategory)
- **After:** `,2094,` (just category)

Is this correct?

### 4. Alias Structure Simplification
Current sample has aliases as objects:
```json
"aliases": [{"type": "subcategory", "name": "Hotels"}]
```

Can we simplify to just strings (since type is always the same)?
```json
"aliases": ["Hotels", "Hotels and Inns"]
```

## Implementation Approach

### Option A: New Loader Function (Recommended)
Create a new loader specifically for the nested CPT structure that:
1. Parses nested JSON
2. Builds flat lookup tables for fast access:
   - `name_to_category`: {"Hotels" → {id: 2094, cpt_slug: "gd_placestostay"}}
   - `id_to_cpt`: {2094 → "gd_placestostay"}
3. Keeps existing generic mapping system for neighborhoods.json

**Pros:** Clean separation, optimized for new structure
**Cons:** New code path to maintain

### Option B: Extend Generic Mapping System
Modify MAPPING_CONFIGS to support nested structures with a new parser type.

**Pros:** Single system for all mappings
**Cons:** More complex generic code, may be over-engineered

### Option C: Flatten at Load Time
Load nested JSON, immediately flatten to current structure format, use existing code.

**Pros:** Minimal code changes
**Cons:** Defeats purpose of cleaner JSON structure

## Recommended Implementation (Option A)

### Step 1: Define new JSON structure
Finalize the schema with CPT slugs and simplified aliases.

### Step 2: Create CPT taxonomy loader
```python
def load_cpt_taxonomy(json_path: str) -> None:
    """Load nested CPT-based taxonomy structure."""
    # Parse JSON
    # Build lookup tables:
    #   _CPT_CATEGORIES[name] = {id, cpt_slug}
    #   _CPT_CATEGORIES[alias] = {id, cpt_slug}  # aliases map same way
    #   _CPT_BY_ID[id] = cpt_slug
```

### Step 3: Create lookup functions
```python
def get_category_info(name: str) -> dict | None:
    """Get category ID and CPT slug for a name (or alias)."""
    return _CPT_CATEGORIES.get(name)

def get_cpt_for_category_id(cat_id: int) -> str | None:
    """Get CPT slug for a category ID."""
    return _CPT_BY_ID.get(cat_id)
```

### Step 4: Update transform_categories_to_ids()
- Remove parent_id logic (no more parent categories)
- Just return category IDs

### Step 5: Update post_type assignment
- Look up CPT based on first/primary category
- Use CPT slug instead of hardcoded `gd_listing`

### Step 6: Update taxonomy-tree.py
- Parse new nested structure
- Display CPT → Category → Aliases hierarchy

## Files to Modify

1. **gd-taxonomy-cpts.json** - Finalize new structure
2. **transform.py** - New loader, updated transform functions
3. **taxonomy-tree.py** - Updated tree display
4. **gd-taxonomy-map.json** - Eventually retire (keep as backup)

## Migration Strategy

1. Create new gd-taxonomy-cpts.json with complete data
2. Add new loader alongside existing (feature flag or filename switch)
3. Test thoroughly with sample data
4. Switch to new structure
5. Keep old file as backup until confirmed working
