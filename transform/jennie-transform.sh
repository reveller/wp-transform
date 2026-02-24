#!/bin/bash

# This will print the message and exit the script if $1 is missing or empty
: "${1:?Error: No Tag parameter provided. Usage: $0 <Tag> [override-file]}"

CURRENT_DATE=$(TZ=UTC date +"%Y/%m")
OVERRIDE_FILE="${2:-${1}_revised.json}"

echo "Processing Tag:$1"
echo "Output file:gd_${1}.csv"
echo "Override file:${OVERRIDE_FILE}"
echo "Destination media folder will be:${CURRENT_DATE}"

./transform.py --acf acf-full-export.csv --out "gd_${1}.csv" \
    --category "${1}" --exclude-categories blog \
    --source-media . --dest-media "${CURRENT_DATE}" --copy-script copy_images.sh \
    --override-file "${OVERRIDE_FILE}" \
    --image-file image-inventory.json

#./transform.py --acf acf-full-export.csv --out "gd_${1}.csv" \
#    --tags "${1}" --exclude-categories blog \
#    --source-media . --dest-media "${CURRENT_DATE}" --copy-script copy_images.sh \
#    --override-file "${OVERRIDE_FILE}" \
#    --image-file image-inventory.json

