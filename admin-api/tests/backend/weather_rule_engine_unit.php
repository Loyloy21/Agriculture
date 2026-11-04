<?php
declare(strict_types=1);

/**
 * Weather rule engine: pure-function boundary, missing-data, contradiction and category tests.
 * No database, no network, no HTTP server. Run: php tests/weather_rule_engine_unit.php
 *
 * Passing these tests shows the rules behave as DOCUMENTED. It does not show the thresholds are right for
 * Cabadbaran farms: that needs field observations and expert review.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/lib/weather_decision.php';

$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $passed++;
    echo "PASS: {$message}\n";
}
$hours = static fn(array $values): array => array_map(static fn($v) => ['time' => '2026-10-12T10:00:00+08:00', 'precipitationMm' => (float) $v], $values);
$complete = [
    'provider' => 'Open-Meteo', 'observationType' => 'Current model conditions', 'observedAt' => '2026-10-12T10:00:00+08:00',
    'temperature' => 29.0, 'apparentTemperature' => 33.0, 'humidity' => 60.0, 'precipitation' => 0.0, 'precipitationIntervalSeconds' => 900,
    'windSpeed' => 8.0, 'windGusts' => 15.0, 'weatherCode' => 3, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 0]),
];
$with = static fn(array $changes): array => array_merge($complete, $changes);
$codes = static fn(array $observation, array $context = []): array => array_column(weather_evaluate_conditions($observation, $context)['matched'], 'code');
$assess = static fn(array $observation, array $context = []): string => weather_evaluate_conditions($observation, $context)['assessment'];

// ---------- Complete data, nothing triggered ----------
$advice = weather_decision_advice($complete, 'Crops');
check($advice['assessment'] === 'no_threshold_exceeded' && $advice['normal'] === true && $advice['matched'] === [] && $advice['limitations'] === [], 'complete current data with no rule triggered is "no threshold exceeded"');
check($advice['headline'] === 'No listed threshold exceeded' && $advice['recommendations'][0]['conditionCode'] === 'no_threshold_exceeded', 'headline is "No listed threshold exceeded", never "Normal"');
check(str_contains($advice['statement'], 'not a forecast') && str_contains($advice['disclaimer'], 'not a forecast, a measurement at your farm or an official PAGASA warning'), 'the all-clear explains it is a model reading, not a forecast, farm observation or PAGASA warning');
$allText = json_encode(weather_rule_guidance());
check(!str_contains($allText, 'Continue regular') && !str_contains($allText, 'Normal conditions') && !str_contains($allText, 'normal limits'), 'no guidance text says "Continue regular ..." or "Normal conditions"');

// ---------- Heavy rain: hourly totals only, boundaries ----------
check($codes($with(['hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 7.4])])) === [], 'hourly rain 7.4 mm is below the heavy-rain value');
check($codes($with(['hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 7.5])])) === ['heavy_rain'], 'hourly rain 7.5 mm reaches the heavy-rain value');
check($codes($with(['precipitation' => 2.0, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 0])])) === [], 'a 15-minute value of 2.0 mm is NOT multiplied by four into a heavy-rain trigger');
check($codes($with(['precipitation' => 1.9, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 7.4])])) === [], 'audit false positive removed: true 7.4 mm/h with a rounded 15-minute 1.9 mm no longer triggers heavy rain');
$heavy = weather_evaluate_conditions($with(['hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 8.2])]))['matched'][0];
check($heavy['basisType'] === 'reference' && str_contains($heavy['measured'], '8.2 mm') && str_contains($heavy['basisLabel'], 'not a PAGASA rainfall warning'), 'heavy rain is labelled a reference value and explicitly not a PAGASA rainfall warning');

// ---------- Three-hour accumulation (project-defined) ----------
check($codes($with(['hourlyPrecipitation' => $hours([0, 0, 0, 5, 5, 5])])) === ['rain_accumulation'], '15 mm over three hourly totals triggers "Rain building up"');
check($codes($with(['hourlyPrecipitation' => $hours([0, 0, 0, 5, 5, 4.9])])) === [], '14.9 mm over three hours does not');
check($codes($with(['hourlyPrecipitation' => $hours([0, 0, 0, 7.4, 7.4, 7.4])])) === ['rain_accumulation'], 'audit gap closed: 7.4 + 7.4 + 7.4 mm in three hours is no longer "nothing to report"');
check(weather_rule_definitions()['rain_accumulation']['basisType'] === 'project', 'the three-hour rule is labelled project-defined');

// ---------- Prolonged rain (project-defined) ----------
check($codes($with(['hourlyPrecipitation' => $hours([0.6, 0.6, 0.6, 0.6, 0, 0])])) === [], 'four rainy hours but only 2.4 mm total is not prolonged rain');
check($codes($with(['hourlyPrecipitation' => $hours([0.6, 1.0, 1.0, 1.0, 1.0, 0.4])])) === ['continuous_rain'], 'four rainy hours and at least 5 mm total is prolonged rain');
check($codes($with(['hourlyPrecipitation' => $hours([0.4, 0.4, 0.4, 0.4, 0.4, 0.4])])) === [], 'six drizzle hours under 0.5 mm each are not prolonged rain');
check(weather_rule_definitions()['continuous_rain']['basisType'] === 'project', 'prolonged rain is labelled project-defined');

// ---------- Wind ----------
check($codes($with(['windSpeed' => 38.9])) === [] && $codes($with(['windSpeed' => 39.0])) === ['strong_wind'], 'sustained wind threshold is 39 km/h');
check($codes($with(['windGusts' => 54.9])) === [] && $codes($with(['windGusts' => 55.0])) === ['strong_wind'], 'gust threshold is 55 km/h (audit: 62 was too lenient)');
$gustOnly = weather_evaluate_conditions($with(['windSpeed' => 30.0, 'windGusts' => 55.0]))['matched'][0];
check($gustOnly['basisType'] === 'project' && str_contains($gustOnly['basisLabel'], 'no official source'), 'a gust-only match is labelled project-defined, not a wind warning');
$sustained = weather_evaluate_conditions($with(['windSpeed' => 41.0]))['matched'][0];
check($sustained['basisType'] === 'reference', 'a sustained-wind match is labelled a reference value');

// ---------- Heat index (air temperature + humidity), PAGASA tiers ----------
check(approx_between(weather_heat_index_c(32.2, 70.0), 40.0, 41.5) && approx_between(weather_heat_index_c(26.7, 40.0), 25.5, 27.5) && approx_between(weather_heat_index_c(35.6, 50.0), 41.0, 43.0), 'heat index matches NWS reference values (90°F/70%≈105°F, 80°F/40%≈80°F, 96°F/50%≈108°F)');
function approx_between(float $value, float $low, float $high): bool { return $value >= $low && $value <= $high; }
foreach ([[32.4, 'caution'], [32.5, 'extreme_caution'], [41.4, 'extreme_caution'], [41.5, 'danger'], [51.4, 'danger'], [51.5, 'extreme_danger'], [26.4, 'below_caution'], [26.5, 'caution']] as [$value, $expected]) {
    check(weather_heat_index_category($value)['code'] === $expected, "heat index {$value} °C is PAGASA tier {$expected}");
}
foreach ([[33.5, 75.0, 'danger'], [34.9, 70.0, 'danger'], [32.6, 70.0, 'danger'], [31.0, 80.0, 'extreme_caution'], [30.0, 85.0, 'extreme_caution'], [29.0, 60.0, null]] as [$air, $humidity, $severity]) {
    $matches = weather_evaluate_conditions($with(['temperature' => $air, 'humidity' => $humidity, 'apparentTemperature' => 30.0]))['matched'];
    $found = $matches === [] ? null : $matches[0]['severity'];
    check($found === $severity, "audit heat case {$air} °C at {$humidity}% humidity (heat index " . weather_heat_index_c($air, $humidity) . ' °C) is ' . ($severity ?? 'not flagged'));
}
check($codes($with(['temperature' => 27.0, 'humidity' => 50.0, 'apparentTemperature' => 45.0])) === [], 'Open-Meteo apparent temperature (45 °C) is NOT used as a heat trigger or labelled a heat index');
$fallback = weather_evaluate_conditions($with(['humidity' => null, 'temperature' => 35.0]));
check(array_column($fallback['matched'], 'code') === ['high_temperature'] && $fallback['matched'][0]['basisType'] === 'project', 'air 35 °C with humidity missing uses the labelled project-defined fallback');
check($assess($with(['humidity' => null, 'temperature' => 30.0])) === 'limited', 'humidity missing at 30 °C: heat cannot be assessed, so the result is "limited", not normal');
$danger = weather_decision_advice($with(['temperature' => 33.5, 'humidity' => 75.0]), 'Crops');
check(str_contains($danger['recommendations'][0]['actions'][0], 'PAGASA\'s "Danger" class') && str_contains($danger['matched'][0]['measured'], 'Estimated heat index 47.5'), 'a Danger-class heat index shows the estimate (47.5 °C) and a Danger-specific first action');
check($danger['calculations']['heatIndexC'] === 47.5 && $danger['calculations']['heatIndexCategory'] === 'danger', 'the heat index calculation is returned for display');

// ---------- Thunderstorm codes ----------
foreach ([95, 96, 99] as $weatherCode) {
    $storm = weather_decision_advice($with(['weatherCode' => $weatherCode]), 'Crops');
    check($storm['assessment'] === 'thresholds_exceeded' && $storm['normal'] === false && array_column($storm['matched'], 'code') === ['thunderstorm'] && str_contains($storm['headline'], 'Thunderstorm'), "weather code {$weatherCode} is a thunderstorm, never described as normal (even with no rain values)");
}
check($codes($with(['weatherCode' => 80])) === [] && $codes($with(['weatherCode' => 61])) === [], 'ordinary rain-shower codes (61, 80) are not thunderstorms');
check($codes($with(['weatherCode' => 95, 'windSpeed' => 45.0, 'temperature' => 36.0, 'humidity' => 70.0, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 9.0])])) === ['thunderstorm', 'heavy_rain', 'strong_wind', 'high_temperature'], 'simultaneous conditions are returned in priority order, thunderstorm first');

// ---------- Contradictory rain data ----------
$conflict = weather_decision_advice($with(['weatherCode' => 65]), 'Crops');
check($conflict['assessment'] === 'unclear' && $conflict['normal'] === false && $conflict['headline'] === 'Weather data unclear' && $conflict['conflicts'][0]['code'] === 'rain_code_mismatch' && $conflict['recommendations'][0]['conditionCode'] === 'data_unclear', 'heavy-rain code 65 with zero rain values is "Weather data unclear", not normal');
check($assess($with(['weatherCode' => 82])) === 'unclear', 'violent-shower code 82 with zero rain values is unclear');
check($assess($with(['weatherCode' => 65, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 6.0])])) === 'unclear', 'heavy-rain code with only 6 mm in the hour is unclear (numbers disagree with the code)');
check($codes($with(['weatherCode' => 65, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 7.5])])) === ['heavy_rain'] && $assess($with(['weatherCode' => 65, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 7.5])])) === 'thresholds_exceeded', 'heavy-rain code agreeing with a heavy hourly total is heavy rain, not a conflict');
check($assess($with(['weatherCode' => 63])) === 'unclear' && $assess($with(['weatherCode' => 81])) === 'unclear', 'moderate-rain codes 63 and 81 with no rain values are unclear');
check($assess($with(['weatherCode' => 63, 'hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 0.3])])) === 'no_threshold_exceeded' && $assess($with(['weatherCode' => 63, 'precipitation' => 0.2])) === 'no_threshold_exceeded' && $assess($with(['weatherCode' => 61])) === 'no_threshold_exceeded', 'moderate-rain code with some rain recorded, and light-rain code 61, are not conflicts');

// ---------- Missing data never becomes an all-clear ----------
$onlyTemperature = weather_decision_advice(['provider' => 'Open-Meteo', 'observationType' => 'Current model conditions', 'observedAt' => '2026-10-12T10:00:00+08:00', 'temperature' => 29.0], 'Crops');
check($onlyTemperature['assessment'] === 'limited' && $onlyTemperature['normal'] === false && str_starts_with($onlyTemperature['headline'], 'Limited weather check: could not check '), 'a reading with only temperature is a "Limited weather check" that names what was skipped');
foreach (['thunderstorm', 'heavy_rain', 'strong_wind', 'rain_accumulation', 'continuous_rain', 'high_temperature'] as $ruleCode) {
    check(in_array($ruleCode, array_column($onlyTemperature['notEvaluated'], 'code'), true), "limited check identifies {$ruleCode} as not checked when its data is absent");
}
check($onlyTemperature['recommendations'][0]['conditionCode'] === 'limited_check' && !str_contains(json_encode($onlyTemperature), 'No listed threshold exceeded'), 'limited check never shows the "no threshold exceeded" text');
foreach ([['hourlyPrecipitation' => [], 'heavy_rain'], ['windSpeed' => null, 'strong_wind'], ['windGusts' => null, 'strong_wind'], ['temperature' => null, 'high_temperature'], ['weatherCode' => null, 'thunderstorm']] as $row) {
    $missingKey = array_key_first($row);
    $observation = $with([$missingKey => $row[$missingKey]]);
    $result = weather_evaluate_conditions($observation);
    check($result['assessment'] === 'limited' && in_array($row[0] ?? end($row), array_column($result['notEvaluated'], 'code'), true), "missing {$missingKey}: result is limited and {$row[array_key_last($row)]} is listed as not checked");
}
$partial = weather_evaluate_conditions($with(['hourlyPrecipitation' => $hours([0, 0, 0, 0])]));
check($partial['assessment'] === 'limited' && in_array('continuous_rain', array_column($partial['notEvaluated'], 'code'), true), 'only four of six hourly totals: prolonged rain cannot be ruled out, so the result is limited');
check($codes($with(['hourlyPrecipitation' => $hours([1.5, 1.5, 1.5, 1.5])])) === ['continuous_rain'], 'four hourly totals that already satisfy prolonged rain still trigger it');
$matchedAndMissing = weather_decision_advice($with(['weatherCode' => 95, 'windSpeed' => null, 'windGusts' => null]), 'Crops');
check($matchedAndMissing['assessment'] === 'thresholds_exceeded' && $matchedAndMissing['limitations'] !== [] && str_contains($matchedAndMissing['statement'], 'Some checks could not be completed'), 'a triggered rule is still reported when other checks were skipped, and the skipped checks are listed');

// ---------- Stale readings ----------
$stale = weather_decision_advice($complete, 'Crops', ['stale' => true, 'ageMinutes' => 95]);
check($stale['assessment'] === 'limited' && $stale['basedOnStaleReading'] === true && $stale['normal'] === false && str_contains($stale['headline'], 'reading is not current') && str_contains(json_encode($stale['limitations']), '95 minutes old'), 'a stale reading with nothing triggered is "Limited weather check", with its age, never an all-clear');
$staleStorm = weather_decision_advice($with(['weatherCode' => 95]), 'Crops', ['stale' => true, 'ageMinutes' => 80]);
check($staleStorm['assessment'] === 'thresholds_exceeded' && $staleStorm['basedOnStaleReading'] === true, 'a triggered rule on a stale reading is still shown, flagged as based on a stale reading');

// ---------- Category-specific guidance ----------
$guidance = weather_rule_guidance();
foreach (array_keys(weather_rule_definitions()) as $ruleCode) {
    $perCategory = [];
    foreach (weather_advice_categories() as $category) {
        check(!empty($guidance[$ruleCode][$category]), "guidance exists for {$ruleCode} / {$category}");
        $perCategory[$category] = json_encode($guidance[$ruleCode][$category]);
    }
    check(count(array_unique($perCategory)) === 4, "{$ruleCode}: Crops, Livestock, Poultry and Fishpond each receive different actions");
}
foreach (['no_threshold_exceeded', 'limited_check', 'data_unclear'] as $stateCode) {
    foreach (weather_advice_categories() as $category) check(!empty($guidance[$stateCode][$category]), "state text exists for {$stateCode} / {$category}");
}
foreach (weather_advice_categories() as $category) {
    $heat = weather_decision_advice($with(['temperature' => 34.9, 'humidity' => 70.0]), $category);
    check($heat['category'] === $category && $heat['recommendations'][0]['category'] === $category && str_contains($heat['recommendations'][0]['actions'][0], '"Danger" class'), "{$category}: Danger-class heat advice carries the category and the Danger action");
    foreach (['thunderstorm' => ['weatherCode' => 95], 'heavy_rain' => ['hourlyPrecipitation' => $hours([0, 0, 0, 0, 0, 9])], 'strong_wind' => ['windSpeed' => 45.0]] as $ruleCode => $changes) {
        $result = weather_decision_advice($with($changes), $category);
        check($result['recommendations'][0]['actions'] === $guidance[$ruleCode][$category], "{$category}: {$ruleCode} returns the {$category}-specific actions");
    }
}
check(str_contains(json_encode($guidance['high_temperature']['Poultry']), 'lower heat levels than people') && str_contains(json_encode($guidance['high_temperature']['Livestock']), 'lower heat levels than people'), 'poultry and livestock heat advice warns that animals can be stressed below the human heat-index tiers');
try { weather_decision_advice($complete, 'Aquaculture'); check(false, 'an unsupported category is rejected'); } catch (InvalidArgumentException) { check(true, 'an unsupported category is rejected'); }

// ---------- Honesty of labels ----------
foreach (weather_rule_definitions() as $ruleCode => $definition) {
    check(in_array($definition['basisType'], ['reference', 'project'], true) && $definition['basisLabel'] !== '', "{$ruleCode} is labelled as a reference value or project-defined");
    check((bool) preg_match('/not a PAGASA|neither is a PAGASA|not PAGASA|project-defined|no official source/i', $definition['basisLabel']), "{$ruleCode}: basis label says it is project-defined or not a PAGASA warning");
}
$table = weather_rule_table();
check(str_contains($table['guidanceReview'], 'not been reviewed or endorsed by the Department of Agriculture, BFAR, BAI or PAGASA'), 'the rule table states the guidance has not been reviewed or endorsed by DA, BFAR, BAI or PAGASA');
check(!str_contains(strtolower(json_encode(weather_rule_definitions())), 'yellow rainfall'), 'the unsupported "yellow rainfall-warning band" wording is gone');
check($table['ruleSetVersion'] === '2026.10-2', 'the rule set version was bumped so old snapshots stay attributable to rule set 2026.10-1');

echo "\nALL {$passed} CHECKS PASSED\n";
