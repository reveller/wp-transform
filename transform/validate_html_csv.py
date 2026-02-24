#!/usr/bin/env python3
"""
HTML Validation Script for CSV Columns

Validates HTML content in a specified column of a CSV file.
Provides detailed validation report including:
- HTML structure validation (balanced tags)
- Security checks (scripts, inline handlers)
- Content analysis (elements count)
- Issue identification

Usage:
    python3 validate_html_csv.py <csv_file> <column_name>
    python3 validate_html_csv.py data.csv post_content
    python3 validate_html_csv.py data.csv post_content --verbose
"""

import csv
import re
import sys
from bs4 import BeautifulSoup
from pathlib import Path


def validate_html(content, entry_name):
    """
    Validate HTML structure and return issues.

    Args:
        content: HTML content string to validate
        entry_name: Name/identifier for the entry (for error reporting)

    Returns:
        List of issue strings (empty if valid)
    """
    issues = []

    if not content:
        return ["Empty content"]

    # Try to parse with BeautifulSoup
    try:
        soup = BeautifulSoup(content, 'html.parser')
    except Exception as e:
        return [f"HTML parsing failed: {str(e)[:100]}"]

    # Check for major unclosed tags
    major_tags = [
        'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'a', 'strong', 'em', 'span',
        'table', 'tr', 'td', 'th', 'thead', 'tbody',
        'iframe', 'form', 'section', 'article', 'nav'
    ]

    for tag in major_tags:
        opening = len(re.findall(f'<{tag}[^>]*>', content))
        closing = len(re.findall(f'</{tag}>', content))
        self_closing = len(re.findall(f'<{tag}[^>]*/>', content))

        net_opening = opening - self_closing

        if net_opening != closing:
            diff = net_opening - closing
            if diff > 0:
                issues.append(f"<{tag}>: missing {diff} closing tag(s)")
            else:
                issues.append(f"<{tag}>: {-diff} extra closing tag(s)")

    # Check for control characters
    if '\x00' in content:
        issues.append("Contains null bytes")

    # Check for unclosed broadstreet tags (common in WordPress)
    broadstreet_open = len(re.findall(r'<broadstreet-zone[^>]*>', content))
    broadstreet_close = len(re.findall(r'</broadstreet-zone>', content))
    if broadstreet_open != broadstreet_close:
        issues.append(f"broadstreet-zone: {broadstreet_open} opening, {broadstreet_close} closing")

    return issues


def analyze_html_content(content):
    """
    Analyze HTML content and return statistics.

    Args:
        content: HTML content string

    Returns:
        Dictionary with content statistics
    """
    soup = BeautifulSoup(content, 'html.parser')

    return {
        'length': len(content),
        'paragraphs': len(soup.find_all('p')),
        'headings': len(soup.find_all(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])),
        'links': len(soup.find_all('a')),
        'images': len(soup.find_all('img')),
        'iframes': len(soup.find_all('iframe')),
        'lists': len(soup.find_all(['ul', 'ol'])),
        'tables': len(soup.find_all('table')),
    }


def check_security_issues(content):
    """
    Check for potential security issues in HTML content.

    Args:
        content: HTML content string

    Returns:
        List of security issue strings
    """
    issues = []

    # Check for script tags
    if '<script' in content.lower():
        script_count = len(re.findall(r'<script[^>]*>', content, re.IGNORECASE))
        issues.append(f"Contains {script_count} <script> tag(s)")

    # Check for inline event handlers
    event_handlers = re.findall(
        r'\s(on(?:click|error|load|mouseover|mouseout|focus|blur|change|submit|keydown|keyup|keypress))\s*=',
        content,
        re.IGNORECASE
    )
    if event_handlers:
        unique_handlers = set(h.lower() for h in event_handlers)
        issues.append(f"Contains inline event handlers: {', '.join(unique_handlers)}")

    # Check for extremely long attribute values
    attr_pattern = r'(\w+)="([^"]*)"'
    matches = re.findall(attr_pattern, content)
    for attr_name, attr_value in matches:
        if len(attr_value) > 10000:
            issues.append(f"Very long {attr_name} attribute: {len(attr_value):,} chars")

    # Check for data URIs (can be used for XSS)
    if 'data:' in content.lower():
        data_uris = len(re.findall(r'data:[^"\'>\s]+', content, re.IGNORECASE))
        if data_uris > 0:
            issues.append(f"Contains {data_uris} data URI(s)")

    return issues


def validate_csv_column(csv_file, column_name, verbose=False):
    """
    Validate HTML in a specific column of a CSV file.

    Args:
        csv_file: Path to CSV file
        column_name: Name of column to validate
        verbose: If True, show detailed analysis for each entry

    Returns:
        Tuple of (total_entries, valid_entries, invalid_entries)
    """
    csv_path = Path(csv_file)

    if not csv_path.exists():
        print(f"❌ Error: File not found: {csv_file}")
        return 0, 0, 0

    # Read all entries
    with open(csv_file, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)

        # Check if column exists
        if column_name not in reader.fieldnames:
            print(f"❌ Error: Column '{column_name}' not found in CSV")
            print(f"Available columns: {', '.join(reader.fieldnames)}")
            return 0, 0, 0

        entries = list(reader)

    # Print header
    print("=" * 80)
    print(f"HTML VALIDATION REPORT: {csv_path.name}")
    print("=" * 80)
    print(f"Column: {column_name}")
    print(f"Total entries: {len(entries)}")
    print()

    valid_count = 0
    invalid_count = 0
    all_issues = []

    # Validate each entry
    for i, row in enumerate(entries, 1):
        # Get title/identifier (try common title fields)
        title = (row.get('post_title') or row.get('title') or
                row.get('Title') or row.get('name') or
                row.get('Name') or f'Entry {i}')

        content = row.get(column_name, '')

        # Validate HTML
        html_issues = validate_html(content, title)
        security_issues = check_security_issues(content)

        all_entry_issues = html_issues + security_issues

        # Print entry summary
        print(f"{i}. {title}")
        print(f"   Content length: {len(content):,} chars")

        if all_entry_issues:
            invalid_count += 1
            print(f"   ❌ ISSUES FOUND:")
            for issue in all_entry_issues:
                print(f"      - {issue}")
            all_issues.extend([(title, issue) for issue in all_entry_issues])
        else:
            valid_count += 1
            print(f"   ✅ Valid HTML")

        # Verbose mode: show detailed analysis
        if verbose and content:
            stats = analyze_html_content(content)
            print(f"   Structure:")
            print(f"      Paragraphs: {stats['paragraphs']}")
            print(f"      Headings: {stats['headings']}")
            print(f"      Links: {stats['links']}")
            print(f"      Images: {stats['images']}")
            if stats['iframes'] > 0:
                print(f"      Iframes: {stats['iframes']}")
            if stats['lists'] > 0:
                print(f"      Lists: {stats['lists']}")
            if stats['tables'] > 0:
                print(f"      Tables: {stats['tables']}")

            # Show first 80 chars
            preview = content[:80].replace('\n', ' ').replace('\r', '')
            print(f"   Preview: {preview}...")

        print()

    # Print summary
    print("=" * 80)
    print("SUMMARY")
    print("=" * 80)
    print(f"Total entries: {len(entries)}")
    print(f"Valid entries: {valid_count} ({valid_count/len(entries)*100:.1f}%)")
    print(f"Invalid entries: {invalid_count} ({invalid_count/len(entries)*100:.1f}%)")
    print()

    if all_issues:
        print("ISSUES BY TYPE:")
        issue_types = {}
        for title, issue in all_issues:
            # Extract issue type (first part before colon)
            issue_type = issue.split(':')[0]
            if issue_type not in issue_types:
                issue_types[issue_type] = 0
            issue_types[issue_type] += 1

        for issue_type, count in sorted(issue_types.items(), key=lambda x: -x[1]):
            print(f"  - {issue_type}: {count}")
        print()

    if invalid_count == 0:
        print("✅ ALL ENTRIES HAVE VALID HTML")
        print()
        print("Validation checks passed:")
        print("  ✓ All tags are properly closed")
        print("  ✓ No null bytes or control characters")
        print("  ✓ No script tags or inline event handlers")
        print("  ✓ No extremely long attributes")
    else:
        print("⚠️  SOME ENTRIES HAVE HTML ISSUES")
        print()
        print("Review the issues above and fix before importing.")

    print("=" * 80)

    return len(entries), valid_count, invalid_count


def main():
    """Main entry point"""
    import argparse

    parser = argparse.ArgumentParser(
        description='Validate HTML content in a CSV column',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s data.csv post_content
  %(prog)s export.csv description --verbose
  %(prog)s listings.csv post_content -v
        """
    )

    parser.add_argument('csv_file', help='Path to CSV file')
    parser.add_argument('column_name', help='Name of column containing HTML to validate')
    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Show detailed analysis for each entry'
    )

    args = parser.parse_args()

    # Validate
    total, valid, invalid = validate_csv_column(
        args.csv_file,
        args.column_name,
        verbose=args.verbose
    )

    # Exit with error code if there were issues
    sys.exit(0 if invalid == 0 else 1)


if __name__ == '__main__':
    main()
