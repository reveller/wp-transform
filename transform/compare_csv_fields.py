#!/usr/bin/env python3
import argparse
import csv
import sys


def load_single_row(csv_path: str) -> dict:
    """
    Load exactly one row from a CSV file.
    Raises an error if the file has zero or more than one data row.
    """
    with open(csv_path, newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        rows = list(reader)

    if not rows:
        raise ValueError(f"{csv_path} contains no data rows")

    if len(rows) > 1:
        raise ValueError(f"{csv_path} contains more than one data row")

    return rows[0]



def compare_rows(row1: dict, row2: dict, field: str | None, context=40):
    """
    Compare fields between two CSV rows and print PASS/FAIL.
    On failure, show where the first difference occurs.
    """
    if field:
        fields = [field]
    else:
        fields = sorted(f for f in (set(row1) | set(row2)) if f is not None)

    failures = 0

    for f in fields:
        v1 = row1.get(f, "")
        v2 = row2.get(f, "")

        if v1 == v2:
            print(f"[PASS] {f}")
            continue

        failures += 1
        print(f"[FAIL] {f}")

        # Length info (very useful for GeoDirectory imports)
        print(f"       len(file1)={len(v1)} len(file2)={len(v2)}")

        # Find first differing character
        min_len = min(len(v1), len(v2))
        diff_index = None

        for i in range(min_len):
            if v1[i] != v2[i]:
                diff_index = i
                break

        if diff_index is None:
            diff_index = min_len
            print("       Difference: one value has extra trailing characters")
        else:
            print(f"       First difference at char index {diff_index}")

        # Context preview
        start = max(0, diff_index - context)
        end = diff_index + context

        def preview(s):
            return (
                s[start:end]
                .replace("\n", "\\n")
                .replace("\t", "\\t")
            )

        print(f"       file1: “…{preview(v1)}…”")
        print(f"       file2: “…{preview(v2)}…”")

    return failures




def main():
    parser = argparse.ArgumentParser(
        description="Compare fields between two single-row CSV files."
    )

    parser.add_argument("csv1", help="First CSV file (source)")
    parser.add_argument("csv2", help="Second CSV file (target)")

    parser.add_argument(
        "--field",
        help="Only compare a single field (by header name)",
        default=None,
    )

    args = parser.parse_args()

    try:
        row1 = load_single_row(args.csv1)
        row2 = load_single_row(args.csv2)
    except Exception as e:
        print(f"❌ Error: {e}", file=sys.stderr)
        sys.exit(1)

    if None in row1 or None in row2:
        print("⚠️  Warning: CSV contains empty or malformed header fields")

    failures = compare_rows(row1, row2, args.field)

    print("\nSummary:")
    if failures == 0:
        print("✅ All compared fields match")
    else:
        print(f"❌ {failures} field(s) failed comparison")
        sys.exit(2)


if __name__ == "__main__":
    main()

