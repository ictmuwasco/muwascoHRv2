<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * GeoLocation Helper
 *
 * Provides geographic distance calculation utilities using the
 * Haversine formula. All distances are returned in METERS.
 */
class GeoLocation
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Calculate the great-circle distance between two coordinates
     * using the Haversine formula.
     *
     * @param float $lat1 Employee latitude in degrees
     * @param float $lon1 Employee longitude in degrees
     * @param float $lat2 Office latitude in degrees
     * @param float $lon2 Office longitude in degrees
     * @return float Distance in meters
     */
    public static function haversineDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;

        // Clamp to avoid floating-point errors outside [0, 1]
        $a = min(1.0, max(0.0, $a));

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Validate that a coordinate is within a valid range.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public static function isValidCoordinate(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    /**
     * Human-friendly distance display: "80 m" or "1.2 km".
     */
    public static function formatDistanceMeters(float $meters): string
    {
        if ($meters >= 1000) {
            return $meters >= 10000
                ? round($meters / 1000) . ' km'
                : round($meters / 1000, 1) . ' km';
        }
        return (string) round($meters) . ' m';
    }

    /**
     * Check if a location is within the allowed radius of an office.
     *
     * @param float $empLat Employee latitude
     * @param float $empLon Employee longitude
     * @param float $officeLat Office latitude
     * @param float $officeLon Office longitude
     * @param float $allowedRadius Allowed radius in meters
     * @param float $gpsAccuracy GPS accuracy in meters (adds tolerance)
     * @return array{within: bool, distance: float, effective_distance: float}
     */
    public static function isWithinRadius(
        float $empLat,
        float $empLon,
        float $officeLat,
        float $officeLon,
        float $allowedRadius,
        float $gpsAccuracy = 0.0
    ): array {
        $distance = self::haversineDistance($empLat, $empLon, $officeLat, $officeLon);

        // Add GPS accuracy as tolerance - if the GPS says we're within
        // accuracy meters of the office, we may actually be closer.
        $effectiveDistance = max(0.0, $distance - $gpsAccuracy);

        return [
            'within' => $effectiveDistance <= $allowedRadius,
            'distance' => round($distance, 2),
            'effective_distance' => round($effectiveDistance, 2),
        ];
    }
}