#!/usr/bin/env python3
"""
Address Lookup Helper Script
Helps lookup business addresses for St. Croix listings

This script extracts business names from the ACF export and helps you
find addresses. Addresses are saved to a cache file for use during transformation.
"""

import csv
import json
import sys
from pathlib import Path

def extract_businesses(acf_file, output_file='address_lookup_needed.txt'):
    """Extract business names that need address lookup"""

    print(f"📋 Extracting business names from {acf_file}...")

    businesses = []

    with open(acf_file, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for row in reader:
            business_id = row.get('id', '')
            business_name = row.get('Title', '').strip()
            location = row.get('acf_location', '').strip()
            phone = row.get('acf_phone', '').strip()
            website = row.get('acf_website', '') or row.get('website_url', '')

            if business_name:
                businesses.append({
                    'id': business_id,
                    'name': business_name,
                    'location': location,
                    'phone': phone,
                    'website': website.strip()
                })

    # Write to file for manual lookup
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write("# Business Address Lookup List\n")
        f.write("# Format: Business Name|Location|Phone|Website\n")
        f.write("#\n")
        f.write("# To add addresses, create 'address_cache.json' with format:\n")
        f.write('# {"Business Name": "street address", ...}\n')
        f.write("#\n")
        f.write("# Use exact business name from first column as the key\n")
        f.write("#\n\n")

        for b in businesses:
            f.write(f"{b['name']}|{b['location']}|{b['phone']}|{b['website']}\n")

    print(f"✅ Extracted {len(businesses)} businesses to {output_file}")
    print(f"\nℹ️  To add addresses:")
    print(f"   1. Review {output_file}")
    print(f"   2. Search for addresses (Google Maps, business websites, etc.)")
    print(f"   3. Create 'address_cache.json' file with:")
    print(f'      {{"business_id": "123 Main St", ...}}')
    print(f"   4. Run transform.py with --use-address-cache flag")

    return len(businesses)

def create_sample_cache():
    """Create a sample address cache file"""
    sample = {
        "1": "123 Main Street",
        "2": "456 Ocean View Drive",
        # Add more as you find them
    }

    with open('address_cache_sample.json', 'w', encoding='utf-8') as f:
        json.dump(sample, f, indent=2)

    print("✅ Created address_cache_sample.json as a template")

def main():
    import argparse

    parser = argparse.ArgumentParser(
        description='Extract business names for address lookup'
    )
    parser.add_argument(
        '--acf',
        default='acf-full-export.csv',
        help='Input ACF export CSV file'
    )
    parser.add_argument(
        '--output',
        default='address_lookup_needed.txt',
        help='Output file for lookup list'
    )
    parser.add_argument(
        '--sample',
        action='store_true',
        help='Create a sample address cache file'
    )

    args = parser.parse_args()

    if args.sample:
        create_sample_cache()
        return

    extract_businesses(args.acf, args.output)

if __name__ == '__main__':
    main()
