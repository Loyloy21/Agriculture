<?php
declare(strict_types=1);

/**
 * Weather-Driven Decision Support: transparent, rule-based advice.
 *
 * This is NOT machine learning, NOT a forecast and NOT an official PAGASA warning. It compares a
 * weather-MODEL reading (see weather_point.php) with the thresholds below, then looks up
 * category-specific guidance. Admin-written advisories (weather_advisories table) are a separate feature.
 *
 * Every threshold is labelled in `weather_rule_definitions()` as either
 *   - "reference": the number appears in published PAGASA material, but AgriReach applies it to a
 *     model reading and for farm advice, which PAGASA does not; or
 *   - "project": defined by the AgriReach team with no official source.
 * Nothing here has been validated against field observations or reviewed by the Department of Agriculture,
 * BFAR, BAI or PAGASA. Change a value and bump WEATHER_RULE_SET_VERSION: the version is stored with
 * every report snapshot so past results stay explainable.
 */
const WEATHER_RULE_SET_VERSION = '2026.10-2';
const WEATHER_ADVICE_SOURCE_LABEL = 'Weather-based advice';
const WEATHER_ADVICE_METHOD_LABEL = 'Rule-based thresholds applied to a weather-model reading from Open-Meteo. Not AI and not a forecast.';
const WEATHER_ADVICE_DISCLAIMER = 'This is the current reading of a weather-model grid cell, not a forecast, a measurement at your farm or an official PAGASA warning. AgriReach thresholds are project-defined rules that have not been validated in the field or reviewed by the Department of Agriculture.';
const WEATHER_GUIDANCE_REVIEW_NOTICE = 'Suggested precautions are general good-practice wording written by the AgriReach team. They have not been reviewed or endorsed by the Department of Agriculture, BFAR, BAI or PAGASA.';

/** Hourly rain: PAGASA hourly intensity class "heavy" starts at 7.5 mm/h (PAGASA statement, 16 Jan 2019, citing its 20 Jun 2012 memorandum). */
const WEATHER_HEAVY_RAIN_MM_PER_HOUR = 7.5;
/** Project-defined: 15 mm over three hourly totals (an average of 5 mm/h, two thirds of the "heavy" class lower bound). */
const WEATHER_RAIN_3H_MM = 15.0;
const WEATHER_RAIN_3H_HOURS = 3;
/** Project-defined "prolonged rain" rule. */
const WEATHER_CONTINUOUS_RAIN_HOUR_MIN_MM = 0.5;
const WEATHER_CONTINUOUS_RAIN_MIN_RAINY_HOURS = 4;
const WEATHER_CONTINUOUS_RAIN_MIN_TOTAL_MM = 5.0;
const WEATHER_RULE_HOURLY_WINDOW = 6;
/** Sustained wind: 39 km/h is the lower bound of PAGASA Tropical Cyclone Wind Signal No. 1 and of Beaufort 6 (strong breeze). */
const WEATHER_STRONG_WIND_KMH = 39.0;
/** Project-defined: a gust of about 1.4 times the sustained threshold (gusts normally exceed the mean wind). */
const WEATHER_STRONG_GUST_KMH = 55.0;
/** PAGASA four-tier heat-index classes as published by DOST: Caution 27-32, Extreme Caution 33-41, Danger 42-51, Extreme Danger 52 and above (deg C). */
const WEATHER_HEAT_INDEX_EXTREME_CAUTION_C = 33;
const WEATHER_HEAT_INDEX_DANGER_C = 42;
const WEATHER_HEAT_INDEX_EXTREME_DANGER_C = 52;
const WEATHER_HEAT_INDEX_CAUTION_C = 27;
/** Project-defined fallback used only when humidity is missing and no heat index can be calculated. */
const WEATHER_HEAT_AIR_FALLBACK_C = 35.0;
/** WMO weather codes (as returned by Open-Meteo): 95 thunderstorm, 96 and 99 thunderstorm with hail. */
const WEATHER_THUNDERSTORM_CODES = [95, 96, 99];
/** 65 rain heavy intensity, 82 violent rain showers. */
const WEATHER_HEAVY_RAIN_CODES = [65, 82];
/** 63 rain moderate intensity, 81 moderate rain showers. */
const WEATHER_MODERATE_RAIN_CODES = [63, 81];
const WEATHER_RAIN_PRESENT_MM = 0.1;

function weather_advice_categories(): array
{
    return ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
}

/** Evaluation order is also priority order (most urgent first). */
function weather_rule_definitions(): array
{
    return [
        'thunderstorm' => [
            'label' => 'Thunderstorm indicated',
            'rule' => 'The weather model\'s condition code for this reading is a thunderstorm (WMO code 95, 96 or 99). This uses the model\'s code, not lightning detection.',
            'threshold' => 'Weather code 95, 96 or 99',
            'basisType' => 'project',
            'basisLabel' => 'Uses the model\'s thunderstorm code. Not a PAGASA thunderstorm advisory.',
            'basis' => 'Project-defined. Uses the WMO weather code supplied by Open-Meteo. It is not lightning detection and not a PAGASA thunderstorm advisory.',
        ],
        'heavy_rain' => [
            'label' => 'Heavy rain',
            'rule' => 'Open-Meteo hourly precipitation total (the rain that fell in the hour before the reading) of at least ' . WEATHER_HEAVY_RAIN_MM_PER_HOUR . ' mm. The 15-minute value is not used.',
            'threshold' => '≥ ' . WEATHER_HEAVY_RAIN_MM_PER_HOUR . ' mm in one hour',
            'basisType' => 'reference',
            'basisLabel' => 'PAGASA\'s hourly "heavy" rain class starts at 7.5 mm/h. Applied here to a model hourly total; this is not a PAGASA rainfall warning.',
            'basis' => 'Reference value. PAGASA\'s hourly rainfall intensity class "heavy" is 7.5 to 15 mm per hour (PAGASA statement, 16 Jan 2019, citing its 20 Jun 2012 memorandum). PAGASA issues its own colour-coded rainfall warnings (news reports describe them as also expecting the rain to continue); this rule has no such forecast, so a match is NOT a PAGASA rainfall warning.',
        ],
        'strong_wind' => [
            'label' => 'Strong wind',
            'rule' => 'Sustained 10 m wind of at least ' . WEATHER_STRONG_WIND_KMH . ' km/h, or a gust (highest in the preceding hour) of at least ' . WEATHER_STRONG_GUST_KMH . ' km/h.',
            'threshold' => '≥ ' . WEATHER_STRONG_WIND_KMH . ' km/h sustained or ≥ ' . WEATHER_STRONG_GUST_KMH . ' km/h gust',
            'basisType' => 'reference',
            'basisLabel' => 'Sustained 39 km/h is the PAGASA Wind Signal No. 1 lower bound (also Beaufort 6). The 55 km/h gust value is project-defined. Neither is a PAGASA wind warning.',
            'basis' => 'Sustained 39 km/h: lower bound of PAGASA Tropical Cyclone Wind Signal No. 1 (39 to 61 km/h) and of Beaufort force 6. That signal is for tropical cyclones, so using it for everyday wind is a project choice. Gust 55 km/h: project-defined, about 1.4 times the sustained value; PAGASA does not state a gust threshold.',
        ],
        'rain_accumulation' => [
            'label' => 'Rain building up',
            'rule' => 'Sum of the last ' . WEATHER_RAIN_3H_HOURS . ' hourly precipitation totals of at least ' . WEATHER_RAIN_3H_MM . ' mm.',
            'threshold' => '≥ ' . WEATHER_RAIN_3H_MM . ' mm in 3 h',
            'basisType' => 'project',
            'basisLabel' => 'No official source; chosen as an average of 5 mm/h over three hours.',
            'basis' => 'Project-defined, with no official source. It exists so that steady heavy rain split across several hours (for example 7.4 mm three hours in a row) is not treated as "nothing to report". PAGASA\'s own multi-hour rainfall figures were not found in an official PAGASA document and are not used.',
        ],
        'continuous_rain' => [
            'label' => 'Prolonged rain',
            'rule' => 'At least ' . WEATHER_CONTINUOUS_RAIN_MIN_RAINY_HOURS . ' of the last ' . WEATHER_RULE_HOURLY_WINDOW . ' hourly totals each at least ' . WEATHER_CONTINUOUS_RAIN_HOUR_MIN_MM . ' mm, and at least ' . WEATHER_CONTINUOUS_RAIN_MIN_TOTAL_MM . ' mm in total.',
            'threshold' => '≥ ' . WEATHER_CONTINUOUS_RAIN_MIN_RAINY_HOURS . ' rainy hours (≥ ' . WEATHER_CONTINUOUS_RAIN_HOUR_MIN_MM . ' mm each) and ≥ ' . WEATHER_CONTINUOUS_RAIN_MIN_TOTAL_MM . ' mm in 6 h',
            'basisType' => 'project',
            'basisLabel' => 'No official source and no field validation.',
            'basis' => 'Project-defined. No national standard for "continuous rain" was found. The values are cautious guesses to flag steady rain; they have not been validated.',
        ],
        'high_temperature' => [
            'label' => 'High heat',
            'rule' => 'Estimated heat index (calculated by AgriReach from the model\'s air temperature and relative humidity with the US National Weather Service regression) of at least ' . WEATHER_HEAT_INDEX_EXTREME_CAUTION_C . ' °C. If humidity is missing, air temperature of at least ' . WEATHER_HEAT_AIR_FALLBACK_C . ' °C is used instead.',
            'threshold' => 'Estimated heat index ≥ ' . WEATHER_HEAT_INDEX_EXTREME_CAUTION_C . ' °C (PAGASA "Extreme Caution" starts at 33)',
            'basisType' => 'reference',
            'basisLabel' => 'Class ranges are PAGASA\'s published heat-index tiers (33-41 Extreme Caution, 42-51 Danger, 52+ Extreme Danger). The value is AgriReach\'s own estimate, not PAGASA\'s heat index; Open-Meteo "apparent temperature" is not used.',
            'basis' => 'PAGASA uses a four-tier, effect-based heat-index classification (PAGASA statement, 12 Apr 2024). DOST publishes the ranges as Caution 27-32, Extreme Caution 33-41, Danger 42-51 and Extreme Danger 52 °C and above. An older PAGASA web page used different cut-offs, so confirm against PAGASA before relying on them. PAGASA\'s exact formula was not found, so AgriReach estimates the index with the NWS Rothfusz regression (shade, light wind); results can differ from PAGASA\'s by a degree or two. Animals can suffer heat stress below these levels; no category-specific temperature limit was found, so none is applied.',
        ],
    ];
}

/**
 * Category-specific guidance. General good-practice wording written by the AgriReach team:
 * it states what to check or prepare, never a prediction. See WEATHER_GUIDANCE_REVIEW_NOTICE.
 */
function weather_rule_guidance(): array
{
    return [
        'thunderstorm' => [
            'Crops' => ['Stop field work and take shelter; stay away from open fields, tall trees and metal tools while there is lightning.', 'Postpone spraying and fertilizer application.', 'Check drainage and low-lying plots after the storm has passed.'],
            'Livestock' => ['Bring animals under shelter and keep them away from isolated trees and metal fences.', 'Secure roofing, doors and loose objects before strong gusts arrive.'],
            'Poultry' => ['Keep birds inside and close openings facing the wind.', 'Secure the roof and loose equipment, and check any backup for fans or lights.'],
            'Fishpond' => ['Stay off the water and away from pond banks while there is lightning.', 'Secure nets, cages, boats and loose equipment.', 'Check dikes and gates after the storm.'],
        ],
        'heavy_rain' => [
            'Crops' => ['Open drainage canals and clear blocked outlets before water builds up.', 'Postpone fertilizer and pesticide application; heavy rain can wash it away.', 'Check low-lying plots for flooding, and harvest mature produce only if it is safe to do so.'],
            'Livestock' => ['Move animals to higher, dry, sheltered ground.', 'Keep feed and drinking water covered and clean.', 'Check pens and walkways for flooding and deep mud.'],
            'Poultry' => ['Keep houses dry: check the roof, gutters and drainage around the house.', 'Raise feed and bedding off wet floors and replace wet litter.', 'Watch the flock for signs of illness after dampness.'],
            'Fishpond' => ['Check dikes, gates and spillways and be ready to release excess water if it is safe.', 'Watch for pond overflow so fish are not lost.', 'Consider reducing feeding while the water is muddy or churned; oxygen can drop after heavy rain.'],
        ],
        'strong_wind' => [
            'Crops' => ['Stake or support tall crops and secure covers, nets and light structures.', 'Avoid spraying: wind carries chemicals away from the target.', 'Harvest mature produce that could be lost, only if it is safe to do so.'],
            'Livestock' => ['Secure shelters, roofing and fences.', 'Keep animals away from trees, loose sheets and power lines.'],
            'Poultry' => ['Secure the poultry house roof and walls and close openings facing the wind.', 'Tie down or store loose equipment and feeders.'],
            'Fishpond' => ['Secure nets, cages, boats and loose equipment.', 'Check dikes and pond banks for damage after the wind.'],
        ],
        'rain_accumulation' => [
            'Crops' => ['Check drainage canals and low-lying plots for waterlogging.', 'Consider delaying planting, fertilizer and pesticide application.'],
            'Livestock' => ['Check that animals have dry bedding and resting areas.', 'Watch low-lying pens and walkways for flooding.'],
            'Poultry' => ['Check the roof, gutters and drainage around the house and keep litter dry.'],
            'Fishpond' => ['Check the water level and be ready to release excess water if it is safe.', 'Watch fish for stress such as gasping at the surface.'],
        ],
        'continuous_rain' => [
            'Crops' => ['Avoid planting or transplanting in low-lying fields.', 'Keep drainage open and watch for waterlogging and leaf disease.'],
            'Livestock' => ['Keep bedding and resting areas dry.', 'Check hooves and skin for problems caused by constant wetness.'],
            'Poultry' => ['Manage wet litter and keep the house ventilated but dry.', 'Keep feed dry to avoid spoilage.'],
            'Fishpond' => ['Monitor the water level and overflow risk.', 'Watch fish for stress such as gasping at the surface.'],
        ],
        'high_temperature' => [
            'Crops' => ['Irrigate early morning or late afternoon rather than midday.', 'Limit heavy field work during the hottest hours, rest in shade and drink water often.', 'Watch crops for wilting.'],
            'Livestock' => ['Provide shade and plenty of clean drinking water.', 'Avoid moving or working animals in the midday heat.', 'Animals can be stressed at lower heat levels than people, so watch them even when the level is modest.'],
            'Poultry' => ['Improve ventilation and provide cool, clean water.', 'Avoid overcrowding and watch for panting or listless birds.', 'Birds can be stressed at lower heat levels than people, so watch the flock even when the level is modest.'],
            'Fishpond' => ['Watch fish for gasping at the surface and avoid overfeeding in the heat.', 'Keep the pond water level up and aerate if you have the means.'],
        ],
        'no_threshold_exceeded' => [
            'Crops' => ['None of the listed weather rules was triggered by this reading. Follow your usual field routine.', 'Check admin advisories and official PAGASA forecasts and warnings for what is coming.'],
            'Livestock' => ['None of the listed weather rules was triggered by this reading. Follow your usual animal care routine.', 'Check admin advisories and official PAGASA forecasts and warnings for what is coming.'],
            'Poultry' => ['None of the listed weather rules was triggered by this reading. Follow your usual house routine.', 'Check admin advisories and official PAGASA forecasts and warnings for what is coming.'],
            'Fishpond' => ['None of the listed weather rules was triggered by this reading. Follow your usual pond checks.', 'Check admin advisories and official PAGASA forecasts and warnings for what is coming.'],
        ],
        'limited_check' => [
            'Crops' => ['This check is incomplete, so it cannot show that conditions are normal or safe for your crops.', 'Judge the weather at your farm yourself, and check admin advisories and official PAGASA forecasts and warnings.'],
            'Livestock' => ['This check is incomplete, so it cannot show that conditions are normal or safe for your animals.', 'Judge the weather at your farm yourself, and check admin advisories and official PAGASA forecasts and warnings.'],
            'Poultry' => ['This check is incomplete, so it cannot show that conditions are normal or safe for your flock.', 'Judge the weather at your farm yourself, and check admin advisories and official PAGASA forecasts and warnings.'],
            'Fishpond' => ['This check is incomplete, so it cannot show that conditions are normal or safe for your pond.', 'Judge the weather at your farm yourself, and check admin advisories and official PAGASA forecasts and warnings.'],
        ],
        'data_unclear' => [
            'Crops' => ['The weather model\'s own indicators disagree, so this reading cannot be relied on.', 'Look at the actual sky and ground at your farm, check admin advisories and official PAGASA warnings, and refresh in a few minutes.'],
            'Livestock' => ['The weather model\'s own indicators disagree, so this reading cannot be relied on.', 'Look at the actual sky and ground at your farm, check admin advisories and official PAGASA warnings, and refresh in a few minutes.'],
            'Poultry' => ['The weather model\'s own indicators disagree, so this reading cannot be relied on.', 'Look at the actual sky and ground at your farm, check admin advisories and official PAGASA warnings, and refresh in a few minutes.'],
            'Fishpond' => ['The weather model\'s own indicators disagree, so this reading cannot be relied on.', 'Look at the actual sky and ground at your farm, check admin advisories and official PAGASA warnings, and refresh in a few minutes.'],
        ],
    ];
}

/** Extra first action(s) for heat-index tiers above "Extreme Caution". */
function weather_heat_extra_actions(string $severity): array
{
    return match ($severity) {
        'danger' => ['The estimated heat index is in PAGASA\'s "Danger" class: avoid strenuous outdoor work, rest often in shade and drink water.'],
        'extreme_danger' => ['The estimated heat index is in PAGASA\'s "Extreme Danger" class: stop strenuous outdoor work, rest in shade and drink water.'],
        default => [],
    };
}

function weather_format_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
}

/**
 * Heat index in °C from air temperature (°C) and relative humidity (%), using the US National Weather
 * Service Rothfusz regression with its documented low/high-humidity adjustments. This is AgriReach's
 * ESTIMATE of PAGASA-style heat index; PAGASA's own formula was not found, so values can differ slightly.
 */
function weather_heat_index_c(float $airC, float $relativeHumidity): float
{
    $rh = max(0.0, min(100.0, $relativeHumidity));
    $t = $airC * 9 / 5 + 32;
    $hi = 0.5 * ($t + 61.0 + (($t - 68.0) * 1.2) + ($rh * 0.094));
    if (($hi + $t) / 2 >= 80) {
        $hi = -42.379 + 2.04901523 * $t + 10.14333127 * $rh - 0.22475541 * $t * $rh - 0.00683783 * $t * $t - 0.05481717 * $rh * $rh
            + 0.00122874 * $t * $t * $rh + 0.00085282 * $t * $rh * $rh - 0.00000199 * $t * $t * $rh * $rh;
        if ($rh < 13 && $t >= 80 && $t <= 112) $hi -= ((13 - $rh) / 4) * sqrt((17 - abs($t - 95)) / 17);
        if ($rh > 85 && $t >= 80 && $t <= 87) $hi += (($rh - 85) / 10) * ((87 - $t) / 5);
    }
    return round(($hi - 32) * 5 / 9, 1);
}

/** PAGASA tier for a heat index (whole degrees, as PAGASA publishes them). */
function weather_heat_index_category(float $heatIndexC): array
{
    $whole = (int) round($heatIndexC);
    return match (true) {
        $whole >= WEATHER_HEAT_INDEX_EXTREME_DANGER_C => ['code' => 'extreme_danger', 'label' => 'Extreme Danger'],
        $whole >= WEATHER_HEAT_INDEX_DANGER_C => ['code' => 'danger', 'label' => 'Danger'],
        $whole >= WEATHER_HEAT_INDEX_EXTREME_CAUTION_C => ['code' => 'extreme_caution', 'label' => 'Extreme Caution'],
        $whole >= WEATHER_HEAT_INDEX_CAUTION_C => ['code' => 'caution', 'label' => 'Caution'],
        default => ['code' => 'below_caution', 'label' => 'Below Caution'],
    };
}

function weather_observation_number(array $observation, string $key): ?float
{
    return isset($observation[$key]) && is_numeric($observation[$key]) ? (float) $observation[$key] : null;
}

function weather_hour_label(mixed $time): ?string
{
    if (!is_string($time) || $time === '') return null;
    try {
        return (new DateTimeImmutable($time))->format('g:i A');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Apply the thresholds to one observation.
 *
 * @param array $context ['stale' => bool, 'ageMinutes' => ?int]
 * @return array{assessment:string,matched:array,notEvaluated:array,conflicts:array,limitations:array,calculations:array}
 */
function weather_evaluate_conditions(array $observation, array $context = []): array
{
    $definitions = weather_rule_definitions();
    $matched = [];
    $notEvaluated = [];
    $conflicts = [];
    $calculations = ['latestHourMm' => null, 'latestHourEndsAt' => null, 'last3hMm' => null, 'last6hMm' => null, 'heatIndexC' => null, 'heatIndexCategory' => null, 'heatIndexCategoryLabel' => null];

    $add = static function (string $code, string $measured, array $extra = []) use (&$matched, $definitions): void {
        $definition = $definitions[$code];
        $matched[] = array_merge(['code' => $code, 'label' => $definition['label'], 'measured' => $measured, 'threshold' => $definition['threshold'], 'basisType' => $definition['basisType'], 'basisLabel' => $definition['basisLabel']], $extra);
    };
    $skip = static function (string $code, string $reason) use (&$notEvaluated, $definitions): void {
        $notEvaluated[] = ['code' => $code, 'label' => $definitions[$code]['label'], 'reason' => $reason];
    };

    $hourly = [];
    foreach (is_array($observation['hourlyPrecipitation'] ?? null) ? $observation['hourlyPrecipitation'] : [] as $entry) {
        if (is_array($entry) && isset($entry['precipitationMm']) && is_numeric($entry['precipitationMm'])) {
            $hourly[] = ['time' => $entry['time'] ?? null, 'mm' => (float) $entry['precipitationMm']];
        }
    }
    $latest = $hourly ? end($hourly) : null;
    $latestMm = $latest === null ? null : $latest['mm'];
    $calculations['latestHourMm'] = $latestMm;
    $calculations['latestHourEndsAt'] = $latest === null ? null : (is_string($latest['time']) ? $latest['time'] : null);
    if (count($hourly) >= WEATHER_RAIN_3H_HOURS) $calculations['last3hMm'] = round(array_sum(array_column(array_slice($hourly, -WEATHER_RAIN_3H_HOURS), 'mm')), 2);
    if (count($hourly) >= WEATHER_RULE_HOURLY_WINDOW) $calculations['last6hMm'] = round(array_sum(array_column(array_slice($hourly, -WEATHER_RULE_HOURLY_WINDOW), 'mm')), 2);

    // Thunderstorm (model weather code)
    $code = isset($observation['weatherCode']) && is_numeric($observation['weatherCode']) ? (int) $observation['weatherCode'] : null;
    if ($code === null) {
        $skip('thunderstorm', 'The provider did not return a weather condition code.');
    } elseif (in_array($code, WEATHER_THUNDERSTORM_CODES, true)) {
        $add('thunderstorm', 'Model weather code ' . $code . ' (thunderstorm)');
    }

    // Heavy rain: hourly total only. The 15-minute value is a rounded hourly/4 interpolation, so it is never used.
    if ($latestMm === null) {
        $skip('heavy_rain', 'No hourly rainfall total was returned.');
    } elseif ($latestMm >= WEATHER_HEAVY_RAIN_MM_PER_HOUR) {
        $endsAt = weather_hour_label($calculations['latestHourEndsAt']);
        $add('heavy_rain', weather_format_number($latestMm) . ' mm of rain in the hour' . ($endsAt !== null ? ' ending ' . $endsAt : ''));
    }

    // Strong wind
    $speed = weather_observation_number($observation, 'windSpeed');
    $gusts = weather_observation_number($observation, 'windGusts');
    if (($speed !== null && $speed >= WEATHER_STRONG_WIND_KMH) || ($gusts !== null && $gusts >= WEATHER_STRONG_GUST_KMH)) {
        $parts = [];
        if ($speed !== null) $parts[] = 'wind ' . weather_format_number($speed) . ' km/h';
        if ($gusts !== null) $parts[] = 'gusts ' . weather_format_number($gusts) . ' km/h';
        $sustainedHit = $speed !== null && $speed >= WEATHER_STRONG_WIND_KMH;
        $extra = $sustainedHit ? [] : ['basisType' => 'project', 'basisLabel' => 'Gust rule of about 1.4 times 39 km/h; no official source. Not a PAGASA wind warning.'];
        $add('strong_wind', ucfirst(implode(', ', $parts)), $extra);
    } elseif ($speed === null && $gusts === null) {
        $skip('strong_wind', 'The provider did not return wind for this reading.');
    } elseif ($speed === null) {
        $skip('strong_wind', 'Sustained wind speed was not returned; only gusts could be checked.');
    } elseif ($gusts === null) {
        $skip('strong_wind', 'Wind gusts were not returned; only sustained wind could be checked.');
    }

    // Rain building up: three hourly totals
    if (count($hourly) < WEATHER_RAIN_3H_HOURS) {
        $skip('rain_accumulation', 'Fewer than ' . WEATHER_RAIN_3H_HOURS . ' hourly rainfall totals were returned.');
    } elseif ($calculations['last3hMm'] >= WEATHER_RAIN_3H_MM) {
        $add('rain_accumulation', weather_format_number((float) $calculations['last3hMm']) . ' mm over the last ' . WEATHER_RAIN_3H_HOURS . ' hourly totals');
    }

    // Prolonged rain: six hourly totals. A negative answer needs the whole window.
    $values = array_column($hourly, 'mm');
    $rainyHours = count(array_filter($values, static fn(float $value): bool => $value >= WEATHER_CONTINUOUS_RAIN_HOUR_MIN_MM));
    $total = array_sum($values);
    if ($rainyHours >= WEATHER_CONTINUOUS_RAIN_MIN_RAINY_HOURS && $total >= WEATHER_CONTINUOUS_RAIN_MIN_TOTAL_MM) {
        $add('continuous_rain', $rainyHours . ' rainy hours, ' . weather_format_number($total) . ' mm in the last ' . count($values) . ' h');
    } elseif (count($hourly) < WEATHER_RULE_HOURLY_WINDOW) {
        $skip('continuous_rain', 'Only ' . count($hourly) . ' of ' . WEATHER_RULE_HOURLY_WINDOW . ' hourly rainfall totals were returned.');
    }

    // High heat: estimated heat index from air temperature and humidity.
    $temperature = weather_observation_number($observation, 'temperature');
    $humidity = weather_observation_number($observation, 'humidity');
    if ($temperature === null) {
        $skip('high_temperature', 'The provider did not return air temperature for this reading.');
    } elseif ($humidity === null) {
        if ($temperature >= WEATHER_HEAT_AIR_FALLBACK_C) {
            $add('high_temperature', 'Air temperature ' . weather_format_number($temperature) . ' °C (humidity missing, so no heat index)', ['severity' => 'extreme_caution', 'basisType' => 'project', 'basisLabel' => 'Fallback used only while humidity is missing: air temperature of 35 °C or more. No official source.']);
        } else {
            $skip('high_temperature', 'Humidity was not returned, so the heat index could not be calculated.');
        }
    } else {
        $heatIndex = weather_heat_index_c($temperature, $humidity);
        $category = weather_heat_index_category($heatIndex);
        $calculations['heatIndexC'] = $heatIndex;
        $calculations['heatIndexCategory'] = $category['code'];
        $calculations['heatIndexCategoryLabel'] = $category['label'];
        if (in_array($category['code'], ['extreme_caution', 'danger', 'extreme_danger'], true)) {
            $add('high_temperature', 'Estimated heat index ' . weather_format_number($heatIndex) . ' °C (PAGASA "' . $category['label'] . '" range) from air ' . weather_format_number($temperature) . ' °C and humidity ' . weather_format_number($humidity) . '%', ['severity' => $category['code']]);
        }
    }

    // Contradictions between the model's own weather code and its rainfall numbers.
    $currentRain = weather_observation_number($observation, 'precipitation');
    $matchedCodes = array_column($matched, 'code');
    if ($code !== null && in_array($code, WEATHER_HEAVY_RAIN_CODES, true) && !in_array('heavy_rain', $matchedCodes, true)) {
        $conflicts[] = ['code' => 'rain_code_mismatch', 'label' => 'Rain indicators disagree', 'reason' => 'The weather model reports heavy rain (code ' . $code . '), but its hourly rainfall total is ' . ($latestMm === null ? 'missing' : weather_format_number($latestMm) . ' mm') . ', below the ' . weather_format_number(WEATHER_HEAVY_RAIN_MM_PER_HOUR) . ' mm heavy-rain value.'];
    } elseif ($code !== null && in_array($code, WEATHER_MODERATE_RAIN_CODES, true) && ($latestMm ?? 0.0) < WEATHER_RAIN_PRESENT_MM && ($currentRain ?? 0.0) < WEATHER_RAIN_PRESENT_MM) {
        $conflicts[] = ['code' => 'rain_code_mismatch', 'label' => 'Rain indicators disagree', 'reason' => 'The weather model reports rain (code ' . $code . '), but its rainfall values show no rain.'];
    }

    // Keep priority order of the rule definitions.
    $order = array_flip(array_keys($definitions));
    usort($matched, static fn(array $a, array $b): int => $order[$a['code']] <=> $order[$b['code']]);

    $stale = ($context['stale'] ?? false) === true;
    $limitations = [];
    foreach ($notEvaluated as $item) $limitations[] = $item + ['kind' => 'not_checked'];
    foreach ($conflicts as $item) $limitations[] = $item + ['kind' => 'conflict'];
    if ($stale) {
        $age = isset($context['ageMinutes']) && is_numeric($context['ageMinutes']) ? (int) $context['ageMinutes'] : null;
        $limitations[] = ['code' => 'reading_not_current', 'label' => 'Reading is not current', 'reason' => 'This reading is ' . ($age === null ? 'older than expected' : $age . ' minutes old') . ' and is not the current weather.', 'kind' => 'not_current'];
    }

    $assessment = match (true) {
        $matched !== [] => 'thresholds_exceeded',
        $conflicts !== [] => 'unclear',
        $notEvaluated !== [] || $stale => 'limited',
        default => 'no_threshold_exceeded',
    };
    return ['assessment' => $assessment, 'matched' => $matched, 'notEvaluated' => $notEvaluated, 'conflicts' => $conflicts, 'limitations' => $limitations, 'calculations' => $calculations];
}

function weather_assessment_text(string $assessment, array $evaluation): array
{
    switch ($assessment) {
        case 'thresholds_exceeded':
            return [
                implode(' · ', array_column($evaluation['matched'], 'label')),
                'One or more AgriReach rules were triggered by this reading. These are AgriReach advice rules, not official PAGASA warnings.' . ($evaluation['limitations'] ? ' Some checks could not be completed; see below.' : ''),
            ];
        case 'unclear':
            return ['Weather data unclear', 'The weather model\'s own indicators disagree, so no reliable advice can be given from this reading.'];
        case 'limited':
            $missing = array_column($evaluation['notEvaluated'], 'label');
            $parts = [];
            if ($missing) $parts[] = 'could not check ' . implode(', ', array_map('strtolower', $missing));
            if (array_filter($evaluation['limitations'], static fn(array $item): bool => $item['kind'] === 'not_current')) $parts[] = 'reading is not current';
            return ['Limited weather check' . ($parts ? ': ' . implode('; ', $parts) : ''), 'Some checks could not be completed, so this does not show that conditions are normal or safe.'];
        default:
            return ['No listed threshold exceeded', 'None of the AgriReach rules was triggered by this reading. That is not a forecast and does not mean the weather is safe.'];
    }
}

/**
 * Build the farmer/admin-facing advice for one observation and one agricultural category.
 * `normal` is kept for older clients and is true ONLY for a complete, current reading with no rule triggered.
 *
 * @param array $context ['stale' => bool, 'ageMinutes' => ?int]
 */
function weather_decision_advice(array $observation, string $category, array $context = []): array
{
    if (!in_array($category, weather_advice_categories(), true)) {
        throw new InvalidArgumentException('Unsupported agricultural category.');
    }
    $evaluation = weather_evaluate_conditions($observation, $context);
    $assessment = $evaluation['assessment'];
    $guidance = weather_rule_guidance();
    $observedAt = (string) ($observation['observedAt'] ?? '');
    $dataSource = trim((string) ($observation['provider'] ?? '') . ' (' . (string) ($observation['observationType'] ?? '') . ')');

    $recommendations = [];
    foreach ($evaluation['matched'] as $match) {
        $actions = $guidance[$match['code']][$category];
        if ($match['code'] === 'high_temperature') $actions = array_merge(weather_heat_extra_actions((string) ($match['severity'] ?? '')), $actions);
        $recommendations[] = [
            'conditionCode' => $match['code'], 'conditionLabel' => $match['label'], 'category' => $category,
            'actions' => $actions, 'observedAt' => $observedAt, 'source' => $dataSource,
        ];
    }
    if ($assessment !== 'thresholds_exceeded') {
        [$code, $label] = match ($assessment) {
            'unclear' => ['data_unclear', 'Weather data unclear'],
            'limited' => ['limited_check', 'Limited weather check'],
            default => ['no_threshold_exceeded', 'No listed threshold exceeded'],
        };
        $recommendations[] = ['conditionCode' => $code, 'conditionLabel' => $label, 'category' => $category, 'actions' => $guidance[$code][$category], 'observedAt' => $observedAt, 'source' => $dataSource];
    }
    [$headline, $statement] = weather_assessment_text($assessment, $evaluation);

    return [
        'source' => WEATHER_ADVICE_SOURCE_LABEL,
        'method' => WEATHER_ADVICE_METHOD_LABEL,
        'ruleSetVersion' => WEATHER_RULE_SET_VERSION,
        'category' => $category,
        'observedAt' => $observedAt,
        'dataSource' => $dataSource,
        'assessment' => $assessment,
        'headline' => $headline,
        'statement' => $statement,
        'disclaimer' => WEATHER_ADVICE_DISCLAIMER,
        'guidanceReview' => WEATHER_GUIDANCE_REVIEW_NOTICE,
        'normal' => $assessment === 'no_threshold_exceeded',
        'basedOnStaleReading' => ($context['stale'] ?? false) === true,
        'matched' => $evaluation['matched'],
        'notEvaluated' => $evaluation['notEvaluated'],
        'conflicts' => $evaluation['conflicts'],
        'limitations' => $evaluation['limitations'],
        'calculations' => $evaluation['calculations'],
        'recommendations' => $recommendations,
    ];
}

/** "urgent" (act now: lightning, heavy rain, strong wind, heat Danger or above) or "caution" (check and prepare). */
function weather_match_urgency(array $match): string
{
    return match ($match['code']) {
        'thunderstorm', 'heavy_rain', 'strong_wind' => 'urgent',
        'high_temperature' => in_array($match['severity'] ?? '', ['danger', 'extreme_danger'], true) ? 'urgent' : 'caution',
        default => 'caution',
    };
}

/**
 * Short farmer-facing summary of weather_decision_advice() for cards: the triggered condition(s), the
 * measured value and the FIRST (most important) action, word for word from the guidance table. Every
 * urgent condition is kept; other triggered conditions are named in `also`. Nothing is reworded, so the
 * full advice stays the reference.
 * @return array{level:string,title:string,stale:bool,points:list<array{conditionCode:string,condition:string,detail:?string,action:?string,urgency:?string}>,also:list<string>}
 */
function weather_advice_summary(array $advice): array
{
    $byCode = array_column($advice['recommendations'], null, 'conditionCode');
    $points = [];
    foreach ($advice['matched'] as $match) {
        $points[] = ['conditionCode' => $match['code'], 'condition' => $match['label'], 'detail' => $match['measured'], 'action' => $byCode[$match['code']]['actions'][0] ?? null, 'urgency' => weather_match_urgency($match)];
    }
    $urgent = array_values(array_filter($points, static fn(array $point): bool => $point['urgency'] === 'urgent'));
    $shown = $urgent ?: array_slice($points, 0, 1);
    $also = array_values(array_diff(array_column($points, 'condition'), array_column($shown, 'condition')));
    if (!$points) {
        // No rule triggered: the first guidance line says what the reading shows, the second what to do.
        $recommendation = $advice['recommendations'][0];
        $shown = [['conditionCode' => $recommendation['conditionCode'], 'condition' => $recommendation['conditionLabel'], 'detail' => $recommendation['actions'][0] ?? null, 'action' => $recommendation['actions'][1] ?? null, 'urgency' => null]];
    }
    $level = match (true) {
        $urgent !== [] => 'urgent',
        $points !== [] => 'caution',
        $advice['assessment'] === 'unclear' => 'unclear',
        $advice['assessment'] === 'limited' => 'limited',
        default => 'normal',
    };
    return ['level' => $level, 'title' => $advice['headline'], 'stale' => $advice['basedOnStaleReading'], 'points' => $shown, 'also' => $also];
}

/** Rule table for the admin screen and documentation. */
function weather_rule_table(): array
{
    $rows = [];
    foreach (weather_rule_definitions() as $code => $definition) {
        $rows[] = ['code' => $code] + $definition;
    }
    return ['ruleSetVersion' => WEATHER_RULE_SET_VERSION, 'rules' => $rows, 'guidance' => weather_rule_guidance(), 'method' => WEATHER_ADVICE_METHOD_LABEL, 'disclaimer' => WEATHER_ADVICE_DISCLAIMER, 'guidanceReview' => WEATHER_GUIDANCE_REVIEW_NOTICE];
}
