# Address Lookup Guide

## Overview

The transformation script now supports adding street addresses to business listings. Since addresses aren't in the ACF export, you can manually research and cache them for use during transformation.

## Quick Start

### 1. Extract Business List

```bash
./lookup_addresses.py --acf acf-full-export.csv --output businesses.txt
```

This creates a file with all businesses that need addresses:
```
Business Name|Location|Phone|Website
Cane Bay Dive Shop|Frederiksted, West End|340-718-9913|https://canebaydiveshop.com
Dive Experience|Christiansted|340-773-3307|https://divexp.com
...
```

### 2. Research Addresses

For each business, find their street address using:
- Their website (if available)
- Google Maps search: "[Business Name] St. Croix USVI"
- Phone directory
- Social media pages

### 3. Create Address Cache File

Create `address_cache.json` with your researched addresses:

```json
{
  "Cane Bay Dive Shop": "10 Cane Bay",
  "Dive Experience": "1146 Strand Street",
  "St. Croix Ultimate Bluewater Adventures (SCUBA)": "56 Queen Cross Street",
  "Sweet Bottom Dive Center": "63 Estate Cane Bay"
}
```

**Important:** Use exact business names from the first column of businesses.txt as keys.

### 4. Run Transformation with Addresses

```bash
./transform.py \
  --acf acf-full-export.csv \
  --out output.csv \
  --use-address-cache
```

The script will:
- Load addresses from `address_cache.json`
- Use them for matching businesses
- Leave street field empty for businesses without cached addresses

## Workflow Tips

### Start Small

Research addresses for one category at a time:

```bash
# 1. Filter and transform one category
./transform.py --acf acf-full-export.csv --category "Scuba Diving" --out temp.txt

# 2. Note business names that need addresses
# 3. Research those specific businesses
# 4. Add to address_cache.json
# 5. Re-run transformation with --use-address-cache
```

### Incremental Updates

You can add addresses gradually:
1. Start with businesses that have websites
2. Add more as you research them
3. Re-run transformations as the cache grows

### Verify Results

Check what addresses were loaded:

```bash
./transform.py --acf acf-full-export.csv --out output.csv --use-address-cache 2>&1 | grep "Loaded"
```

Output:
```
📍 Loaded 15 addresses from cache
```

## Address Cache Format

The `address_cache.json` file uses business names as keys:

```json
{
  "Business Name": "Street Address",
  "Another Business": "123 Main Street",
  ...
}
```

### Valid Address Formats

- `"10 Cane Bay"` - Simple street address
- `"1146 Strand Street"` - Street with number
- `"56 Queen Cross Street, Suite 200"` - With suite number
- `"Estate Cane Bay"` - Estate name (common in USVI)

### What to Avoid

- Don't include city, state, or country (these are hardcoded as "St. Croix, VI, United States")
- Don't include zip codes in the street field (there's a separate zip field that's currently empty)
- Keep addresses concise and clean

## Performance Notes

### Manual Research

Since addresses must be researched manually:
- This is a **one-time effort** per business
- Focus on high-priority businesses/categories first
- Cache persists across multiple transformations
- You can share the cache file with teammates

### Large Datasets

For 40,000+ records:
- Not all businesses need physical addresses (events, blog posts, etc.)
- Focus on business categories: Restaurants, Hotels, Shops, Services
- Events and informational content can use area coordinates without street addresses

## Example: Complete Workflow

```bash
# 1. Extract businesses
./lookup_addresses.py --acf acf-full-export.csv --output all_businesses.txt

# 2. Filter to just restaurants
grep -i "restaurant" all_businesses.txt > restaurants_to_lookup.txt

# 3. Manually research and create cache
# Edit address_cache.json with restaurant addresses

# 4. Transform restaurants with addresses
./transform.py \
  --acf acf-full-export.csv \
  --out restaurants.csv \
  --category "Restaurants,Christiansted Restaurants,Frederiksted Restaurants" \
  --use-address-cache

# 5. Verify results
head restaurants.csv | cut -d',' -f12,13,16,17
# Shows: street,street2,city,region for first few records
```

## Troubleshooting

### "Address cache not found or empty"

- Make sure `address_cache.json` exists in the same directory as `transform.py`
- Check JSON syntax is valid (use a JSON validator)

### Addresses Not Appearing

- Verify business name in cache exactly matches the `post_title` field
- Check for extra spaces or special characters
- Business names are case-sensitive

### Finding Businesses Without Addresses

Compare your cache against a filtered category:

```bash
# See which businesses don't have addresses yet
./transform.py --acf acf-full-export.csv --category "Scuba Diving" 2>/dev/null | \
  python3 -c "
import csv, sys
reader = csv.DictReader(sys.stdin)
for row in reader:
    if not row['street']:
        print(row['post_title'])
"
```

## Benefits

1. **Better Geocoding**: GeoDirectory won't need to guess addresses
2. **Accurate Maps**: Businesses appear at correct locations
3. **Professional Listings**: Complete address information
4. **Future-Proof**: Cache can be maintained and updated over time
