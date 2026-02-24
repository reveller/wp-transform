#!/bin/bash

# 1. Validate Input - Use quotes around $1
: "${1:?Error: No Tag parameter provided. Usage: $0 [Tag]}"

# 2. Capture Expected Count
# Quotes around "$1" are critical for the match filter
EXPECTED_COUNT=$(csvgrep -c "Categories" -r "Blog|Events" -i acf-full-export.csv | \
                 csvgrep -c "Tags" -r "${1}" | csvstat --count)

echo "Processing Tag: ${1}"
echo "Expected Count: $EXPECTED_COUNT"

# 3. Combined Processing Pipeline
csvgrep -c "Categories" -r "Blog|Events" -i acf-full-export.csv | \
csvgrep -c "Tags" -m "${1}" | \
csvcut -c Title,acf_location,acf_phone,acf_website,acf_email,acf_facebook,acf_twitter,acf_instagram,acf_pinterest,acf_you_tube,acf_google_plus,acf_linked_in,acf_trip_advisor,acf_yelp,acf_other_social_label,acf_other_social_url,acf_description,Categories,Tags | \
csvsql --no-inference --delimiter "," --query "SELECT *, NULL AS street, NULL AS street2, 'St. Croix' AS city, 'United States Virgin Islands' as region, 'United States' as country, NULL AS zip FROM stdin" | \
csvjson -d "," --snifflimit 0 -i 4 > "jennie-${1}.json"

# 4. Capture Realized Count
# Ensure file path is quoted to handle the space in the filename
REALIZED_COUNT=$(jq 'length' "jennie-${1}.json")
echo "Realized JSON count: $REALIZED_COUNT in jennie-${1}.json"

# 5. Equality Comparison
if [ "$EXPECTED_COUNT" -ne "$REALIZED_COUNT" ]; then
    echo "ERROR: Count mismatch for Tag '${1}'!" >&2
    echo "Expected: $EXPECTED_COUNT, but got: $REALIZED_COUNT" >&2
    exit 1
else
    echo "SUCCESS: Counts match ($REALIZED_COUNT entries processed)."
fi

