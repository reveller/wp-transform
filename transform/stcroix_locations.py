"""
St. Croix Location Coordinates
Mapping of area names to latitude/longitude coordinates
"""

# St. Croix location coordinates
# Based on major towns, neighborhoods, and points of interest
LOCATION_COORDS = {
    # Main Towns
    'christiansted': ('17.7475', '-64.7011'),
    'frederiksted': ('17.7128', '-64.8844'),

    # Directional Areas
    'east end': ('17.7644', '-64.5850'),
    'west end': ('17.7100', '-64.8900'),
    'north shore': ('17.7750', '-64.7500'),
    'mid island': ('17.7300', '-64.7500'),

    # Neighborhoods/Bays
    'gallows bay': ('17.7400', '-64.6900'),
    'cane bay': ('17.7717', '-64.8078'),
    'salt river': ('17.7800', '-64.7600'),
    'sandy point': ('17.6800', '-64.9000'),
    '5 corners': ('17.7500', '-64.7100'),

    # Points of Interest
    'buck island': ('17.7889', '-64.6222'),
    'airport': ('17.7019', '-64.7986'),
    'frederiksted pier': ('17.7115', '-64.8845'),
    'the buccaneer': ('17.7569', '-64.6247'),

    # Island-wide services (use center)
    'island wide': ('17.7478', '-64.7059'),
    'island-wide': ('17.7478', '-64.7059'),

    # Special cases
    'accessible by boat only': ('17.7478', '-64.7059'),  # Center point
    'us virgin islands and stateside': ('17.7478', '-64.7059'),  # Center point
}

def get_coordinates(location_str):
    """
    Get coordinates for a location string.

    Args:
        location_str: Location string from acf_location field

    Returns:
        Tuple of (latitude, longitude) as strings, or (None, None) if not found
    """
    if not location_str:
        return (None, None)

    # Normalize the location string
    location_lower = location_str.lower().strip()

    # Direct match
    if location_lower in LOCATION_COORDS:
        return LOCATION_COORDS[location_lower]

    # Try to match the first recognizable location in compound names
    # e.g., "Christiansted, Frederiksted" -> use Christiansted
    # "Office Location: Christiansted" -> use Christiansted
    for known_location, coords in LOCATION_COORDS.items():
        if known_location in location_lower:
            return coords

    # No match found
    return (None, None)

def get_default_coordinates():
    """Get default St. Croix center point coordinates"""
    return ('17.7478', '-64.7059')
