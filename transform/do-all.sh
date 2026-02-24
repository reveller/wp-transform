#!/bin/bash
set -euo pipefail


# x Stay
# x Eat
# x Plan
# x Live

# Land Activities
./jennie-csv.sh "Land Activities"

# Water Activities
./jennie-csv.sh "Water Activities"

# Everything Else
csvgrep -c "Categories" -r "Blog|Events" -i acf-full-export.csv | csvgrep -c "Categories" -r "Golf and Tennis|Beauty|Yoga and Fitness|Massage and Spa" | csvcut -c Title,acf_location,acf_phone,acf_website,acf_email,acf_facebook,acf_twitter,acf_instagram,acf_pinterest,acf_you_tube,acf_google_plus,acf_linked_in,acf_trip_advisor,acf_yelp,acf_other_social_label,acf_other_social_url,acf_description,Categories,Tags | csvsql -d "," --snifflimit 0 --tables temp_table --query "SELECT *, NULL AS street, NULL AS street2, 'St. Croix' AS city, 'United States Virgin Islands' as region, 'United States' as country, NULL AS zip FROM temp_table" | csvjson -d "," --snifflimit 0 -i 4 > "jennie-Everything Else.json"

# Beaches
csvgrep -c "Categories" -r "Blog" -i acf-full-export.csv | csvgrep -c "Categories" -r "St. Croix Beaches" | csvcut -c Title,acf_location,acf_phone,acf_website,acf_email,acf_facebook,acf_twitter,acf_instagram,acf_pinterest,acf_you_tube,acf_google_plus,acf_linked_in,acf_trip_advisor,acf_yelp,acf_other_social_label,acf_other_social_url,acf_description,Categories,Tags | csvsql -d "," --snifflimit 0 --tables temp_table --query "SELECT *, NULL AS street, NULL AS street2, 'St. Croix' AS city, 'United States Virgin Islands' as region, 'United States' as country, NULL AS zip FROM temp_table" | csvjson -d "," --snifflimit 0 -i 4 > "jennie-Beaches.json"

# Hiking and Diving
csvgrep -c "Categories" -r "Blog|Events" -i acf-full-export.csv | csvgrep -c "Categories" -r "Hiking Trails|St. Croix Dive Sites" | csvcut -c Title,acf_location,acf_phone,acf_website,acf_email,acf_facebook,acf_twitter,acf_instagram,acf_pinterest,acf_you_tube,acf_google_plus,acf_linked_in,acf_trip_advisor,acf_yelp,acf_other_social_label,acf_other_social_url,acf_description,Categories,Tags | csvsql -d "," --snifflimit 0 --tables temp_table --query "SELECT *, NULL AS street, NULL AS street2, 'St. Croix' AS city, 'United States Virgin Islands' as region, 'United States' as country, NULL AS zip FROM temp_table" | csvjson -d "," --snifflimit 0 -i 4 > "jennie-Hiking and Diving.json"

# Shop
./jennie-csv.sh Shop

# Events
csvgrep -c "Categories" -r "Blog" -i acf-full-export.csv | csvgrep -c "Categories" -r "Events" | csvcut -c Title,acf_location,acf_phone,acf_website,acf_email,acf_facebook,acf_twitter,acf_instagram,acf_pinterest,acf_you_tube,acf_google_plus,acf_linked_in,acf_trip_advisor,acf_yelp,acf_other_social_label,acf_other_social_url,acf_description,Categories,Tags | csvsql -d "," --snifflimit 0 --tables temp_table --query "SELECT *, NULL AS street, NULL AS street2, 'St. Croix' AS city, 'United States Virgin Islands' as region, 'United States' as country, NULL AS zip FROM temp_table" | csvjson -d "," --snifflimit 0 -i 4 > "jennie-Events.json"

# Blog - not yet
