<?php
declare(strict_types=1);

const CABADBARAN_CITY_CODE = '1600203000';
const CABADBARAN_CITY_NAME = 'Cabadbaran City';
const CABADBARAN_PROVINCE_NAME = 'Agusan del Norte';
const CABADBARAN_BOUNDARY_SOURCE = 'Locally stored approximate NAMRIA-derived City-Municipal Boundary, DPWH rectification (March 2022)';
// Public city geometry from the existing ArcGIS FeatureServer, MUN_CODE=160203000,
// queried as GeoJSON with outSR=4326. No farmer coordinates are sent to ArcGIS.
const CABADBARAN_BOUNDARY_FILE = __DIR__ . '/../data/cabadbaran-city-boundary.geojson';
const CABADBARAN_BOUNDARY_MUNICIPAL_CODE = '160203000';

/** Philippine Statistics Authority PSGC barangays for the City of Cabadbaran. */
function cabadbaran_barangays(): array
{
    return [
        '1600203002' => 'Antonio Luna',
        '1600203005' => 'Bay-ang',
        '1600203006' => 'Bayabas',
        '1600203007' => 'Caasinan',
        '1600203009' => 'Cabinet',
        '1600203010' => 'Calamba',
        '1600203011' => 'Calibunan',
        '1600203012' => 'Comagascas',
        '1600203013' => 'Concepcion',
        '1600203014' => 'Del Pilar',
        '1600203016' => 'Katugasan',
        '1600203017' => 'Kauswagan',
        '1600203018' => 'La Union',
        '1600203019' => 'Mabini',
        '1600203021' => 'Poblacion 1',
        '1600203022' => 'Poblacion 10',
        '1600203023' => 'Poblacion 11',
        '1600203024' => 'Poblacion 12',
        '1600203025' => 'Poblacion 2',
        '1600203026' => 'Poblacion 3',
        '1600203027' => 'Poblacion 4',
        '1600203028' => 'Poblacion 5',
        '1600203029' => 'Poblacion 6',
        '1600203030' => 'Poblacion 7',
        '1600203031' => 'Poblacion 8',
        '1600203032' => 'Poblacion 9',
        '1600203033' => 'Puting Bato',
        '1600203037' => 'Sanghan',
        '1600203038' => 'Soriano',
        '1600203040' => 'Tolosa',
        '1600203041' => 'Mahaba',
    ];
}

function cabadbaran_barangay_name(string $code): ?string
{
    $barangays = cabadbaran_barangays();
    return $barangays[$code] ?? null;
}

function cabadbaran_coverage_label(string $scope, array $barangayNames = []): string
{
    if ($scope === 'Barangays' && $barangayNames) {
        return implode(', ', $barangayNames) . ' — ' . CABADBARAN_CITY_NAME . ', ' . CABADBARAN_PROVINCE_NAME;
    }
    return 'Entire ' . CABADBARAN_CITY_NAME . ', ' . CABADBARAN_PROVINCE_NAME;
}

function cabadbaran_farm_address(string $purokSitio, string $barangayName, string $landmark = ''): string
{
    $parts = [$purokSitio, $barangayName, CABADBARAN_CITY_NAME, CABADBARAN_PROVINCE_NAME];
    if ($landmark !== '') $parts[] = $landmark;
    return implode(', ', $parts);
}

function cabadbaran_residential_address(string $purokSitio, string $barangayName, string $landmark = ''): string
{
    $parts = [];
    if ($landmark !== '') $parts[] = $landmark;
    $parts[] = $purokSitio;
    $parts[] = $barangayName;
    $parts[] = CABADBARAN_CITY_NAME;
    $parts[] = CABADBARAN_PROVINCE_NAME;
    return implode(', ', $parts);
}

/** @return array{status:string,inside:?bool,source:string,message:string} */
function cabadbaran_boundary_check(float $latitude, float $longitude): array
{
    $source = CABADBARAN_BOUNDARY_SOURCE;
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return ['status' => 'invalid', 'inside' => false, 'source' => $source, 'message' => 'The selected coordinates are invalid.'];
    }
    static $rings = null;
    if ($rings === null) {
        $file = @file_get_contents(CABADBARAN_BOUNDARY_FILE);
        $geometry = is_string($file) ? json_decode($file, true) : null;
        $feature = $geometry['features'][0] ?? null;
        if (($feature['properties']['MUN_CODE'] ?? '') !== CABADBARAN_BOUNDARY_MUNICIPAL_CODE
            || ($feature['geometry']['type'] ?? '') !== 'Polygon'
            || !is_array($feature['geometry']['coordinates'] ?? null)) {
            return ['status' => 'unavailable', 'inside' => null, 'source' => $source, 'message' => 'Mapped city-boundary validation is temporarily unavailable.'];
        }
        $rings = $feature['geometry']['coordinates'];
    }

    $inside = false;
    foreach ($rings as $ringIndex => $ring) {
        if (!is_array($ring) || count($ring) < 4) {
            return ['status' => 'unavailable', 'inside' => null, 'source' => $source, 'message' => 'Mapped city-boundary validation is temporarily unavailable.'];
        }
        $inRing = false;
        $previous = $ring[count($ring) - 1];
        foreach ($ring as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                return ['status' => 'unavailable', 'inside' => null, 'source' => $source, 'message' => 'Mapped city-boundary validation is temporarily unavailable.'];
            }
            $x1 = (float) $previous[0];
            $y1 = (float) $previous[1];
            $x2 = (float) $point[0];
            $y2 = (float) $point[1];
            $cross = ($longitude - $x1) * ($y2 - $y1) - ($latitude - $y1) * ($x2 - $x1);
            if (abs($cross) < 1.0e-11 && $longitude >= min($x1, $x2) && $longitude <= max($x1, $x2)
                && $latitude >= min($y1, $y2) && $latitude <= max($y1, $y2)) {
                $inRing = true;
                break;
            }
            if (($y1 > $latitude) !== ($y2 > $latitude)
                && $longitude < ($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1) + $x1) {
                $inRing = !$inRing;
            }
            $previous = $point;
        }
        $inside = $ringIndex === 0 ? $inRing : ($inside && !$inRing);
    }
    return [
        'status' => $inside ? 'inside' : 'outside',
        'inside' => $inside,
        'source' => $source,
        'message' => $inside
            ? 'The pin is inside the mapped Cabadbaran City boundary.'
            : 'The pin is outside the mapped Cabadbaran City boundary.',
    ];
}
