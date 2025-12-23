#!/usr/bin/env python3
"""
Test suite for transform.py social media URL transformations

Run with: ./test_transform.py
Or: python3 test_transform.py
"""

import sys
from transform import transform_social_url

def test_transform_social_url():
    """Test the transform_social_url function with various inputs"""

    tests = [
        # (platform, input, expected_output, description)
        ('facebook', 'VacationSTX', 'https://www.facebook.com/VacationSTX', 'Simple username'),
        ('facebook', '@username', 'https://www.facebook.com/username', 'Username with @ symbol'),
        ('facebook', 'username/', 'https://www.facebook.com/username', 'Username with trailing slash'),
        ('facebook', 'http://facebook.com/page', 'https://facebook.com/page', 'Existing HTTP URL (upgrade to HTTPS)'),
        ('facebook', 'https://facebook.com/page', 'https://facebook.com/page', 'Existing HTTPS URL (preserve)'),
        ('facebook', '', '', 'Empty value'),
        ('facebook', '   ', '', 'Whitespace only'),

        ('twitter', 'BigBeardsAdventureTours', 'https://twitter.com/BigBeardsAdventureTours', 'Twitter username'),
        ('twitter', '@handle', 'https://twitter.com/handle', 'Twitter handle with @'),

        ('instagram', 'bigbeardsadventuretours/', 'https://www.instagram.com/bigbeardsadventuretours', 'Instagram with trailing slash'),
        ('instagram', 'username', 'https://www.instagram.com/username', 'Instagram username'),

        ('pinterest', 'username', 'https://www.pinterest.com/username', 'Pinterest username'),

        ('youtube', 'channelname', 'https://www.youtube.com/@channelname', 'YouTube username (adds @)'),
        ('youtube', '@channelname', 'https://www.youtube.com/@channelname', 'YouTube with @ already'),

        ('linkedin', 'in/john-doe', 'https://www.linkedin.com/in/john-doe', 'LinkedIn personal profile'),
        ('linkedin', 'company/acme', 'https://www.linkedin.com/company/acme', 'LinkedIn company page'),
        ('linkedin', 'john-doe', 'https://www.linkedin.com/in/john-doe', 'LinkedIn username (defaults to /in/)'),

        ('trip_advisor', 'location', 'https://www.tripadvisor.com/location', 'TripAdvisor'),
        ('yelp', 'business-name', 'https://www.yelp.com/biz/business-name', 'Yelp business'),
    ]

    print('Testing transform_social_url()...')
    print('=' * 100)

    passed = 0
    failed = 0
    failures = []

    for platform, input_val, expected, description in tests:
        result = transform_social_url(platform, input_val)

        if result == expected:
            status = 'PASS'
            passed += 1
            symbol = '✓'
        else:
            status = 'FAIL'
            failed += 1
            symbol = '✗'
            failures.append((platform, input_val, expected, result, description))

        print(f'{symbol} {status:4} | {platform:12} | "{input_val:30}" → {description}')

    print('=' * 100)
    print(f'\nResults: {passed} passed, {failed} failed out of {len(tests)} tests')

    if failures:
        print('\n' + '=' * 100)
        print('FAILURES:')
        print('=' * 100)
        for platform, input_val, expected, result, description in failures:
            print(f'\nTest: {description}')
            print(f'  Platform: {platform}')
            print(f'  Input:    "{input_val}"')
            print(f'  Expected: "{expected}"')
            print(f'  Got:      "{result}"')

    return failed == 0

def test_real_data_samples():
    """Test with actual samples from the ACF export"""

    print('\n\nTesting with real ACF data samples...')
    print('=' * 100)

    real_samples = [
        ('facebook', 'VacationSTX', 'https://www.facebook.com/VacationSTX'),
        ('facebook', 'reef.golf.stx/', 'https://www.facebook.com/reef.golf.stx'),
        ('facebook', 'Artthursday/', 'https://www.facebook.com/Artthursday'),
        ('facebook', 'BigBeardsAdventureTours', 'https://www.facebook.com/BigBeardsAdventureTours'),
        ('facebook', 'teroro.buckisland', 'https://www.facebook.com/teroro.buckisland'),
        ('instagram', 'bigbeardsadventuretours/', 'https://www.instagram.com/bigbeardsadventuretours'),
        ('instagram', 'buckislandcharters/', 'https://www.instagram.com/buckislandcharters'),
        ('facebook', 'Equus-Rides-652310078218913', 'https://www.facebook.com/Equus-Rides-652310078218913'),
        ('instagram', 'equusrides', 'https://www.instagram.com/equusrides'),
        ('facebook', 'geckosislandadventures', 'https://www.facebook.com/geckosislandadventures'),
        ('facebook', 'westendwatersports', 'https://www.facebook.com/westendwatersports'),
        ('youtube', 'westendwatersports', 'https://www.youtube.com/@westendwatersports'),
    ]

    passed = 0
    failed = 0

    for platform, input_val, expected in real_samples:
        result = transform_social_url(platform, input_val)

        if result == expected:
            status = 'PASS'
            passed += 1
            symbol = '✓'
        else:
            status = 'FAIL'
            failed += 1
            symbol = '✗'

        print(f'{symbol} {status:4} | {platform:12} | "{input_val:35}" → "{result}"')
        if result != expected:
            print(f'       Expected: "{expected}"')

    print('=' * 100)
    print(f'\nResults: {passed} passed, {failed} failed out of {len(real_samples)} tests')

    return failed == 0

def main():
    """Run all tests"""
    print('\n' + '=' * 100)
    print('TRANSFORM.PY TEST SUITE')
    print('Social Media URL Transformation Tests')
    print('=' * 100 + '\n')

    all_passed = True

    # Run unit tests
    if not test_transform_social_url():
        all_passed = False

    # Run real data tests
    if not test_real_data_samples():
        all_passed = False

    # Final summary
    print('\n' + '=' * 100)
    if all_passed:
        print('✓ ALL TESTS PASSED')
        print('=' * 100)
        return 0
    else:
        print('✗ SOME TESTS FAILED')
        print('=' * 100)
        return 1

if __name__ == '__main__':
    sys.exit(main())
