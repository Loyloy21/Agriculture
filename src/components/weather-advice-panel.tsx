import { router } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { Action, Badge, categoryIcon, formatDate, Icon, palette, ui } from '@/components/farm-ui';
import { API_FAILURE_TITLES, type ApiFailureCode } from '@/services/apiFailure';
import { FarmerDataApiError, getWeatherAdvice, type WeatherAdviceCardSummary, type WeatherAdviceResult } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

type AdviceState = { loading: boolean; data: WeatherAdviceResult | null; error: string; errorCode: ApiFailureCode | null; errorStatus: number | null };

/** Loads rule-based weather advice for one of the farmer's farms (primary farm when farmId is null). */
export function useWeatherAdvice(farmId: number | null, enabled = true) {
  const { refreshSession } = useFarmerAuth();
  const [state, setState] = useState<AdviceState>({ loading: enabled, data: null, error: '', errorCode: null, errorStatus: null });
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    setState((current) => ({ loading: true, data: refresh ? current.data : null, error: '', errorCode: null, errorStatus: null }));
    try {
      const data = await getWeatherAdvice(farmId, refresh);
      if (request === revision.current) setState({ loading: false, data, error: '', errorCode: null, errorStatus: null });
    } catch (error) {
      if (error instanceof FarmerDataApiError && error.status === 401) void refreshSession();
      if (request === revision.current) setState((current) => ({ loading: false, data: current.data, error: error instanceof Error ? error.message : 'Weather-based advice could not be loaded.', errorCode: error instanceof FarmerDataApiError ? error.code ?? null : null, errorStatus: error instanceof FarmerDataApiError ? error.status ?? null : null }));
    }
  }, [farmId, refreshSession]);
  useEffect(() => {
    if (enabled) void load();
    return () => { revision.current += 1; };
  }, [enabled, load]);
  return { ...state, reload: load };
}

/** Why weather could not be shown, when the server DID answer (status "unavailable"). */
function unavailableTitle(reasonCode: string | null) {
  switch (reasonCode) {
    case 'location_outside_city': case 'location_invalid': case 'boundary_unavailable': return 'Farm location needs review';
    case 'no_active_farm': return 'No active farm';
    case 'provider_timeout': case 'provider_unreachable': case 'provider_rate_limited': case 'provider_http_error': case 'provider_invalid_response': return 'Weather service unavailable';
    default: return 'Weather unavailable';
  }
}
function CodeNote({ code, status }: { code: string | null; status?: number | null }) {
  return code ? <Text style={ui.small} selectable>Reason code: {code}{status ? ` · HTTP ${status}` : ''}</Text> : null;
}

function number(value: number) { return Number.isInteger(value) ? String(value) : value.toFixed(1); }
function ageText(minutes: number | null) {
  if (minutes === null) return 'age unknown';
  if (minutes < 1) return 'less than a minute old';
  return minutes < 90 ? `${minutes} min old` : `${Math.round(minutes / 60)} h old`;
}

function SourceBadges({ stale }: { stale: boolean }) {
  return <View style={styles.badgeRow}>
    <Badge label="Weather-based advice" tone="blue" />
    <Badge label="Rule-based · not AI" tone="neutral" />
    {stale && <Badge label="Not current" tone="amber" />}
  </View>;
}

const LEVEL_BADGES: Record<WeatherAdviceCardSummary['level'], { label: string; tone: 'red' | 'amber' } | null> = {
  urgent: { label: 'Urgent', tone: 'red' }, caution: { label: 'Caution', tone: 'amber' },
  limited: { label: 'Limited check', tone: 'amber' }, unclear: { label: 'Data unclear', tone: 'amber' }, normal: null,
};

/** The short card version: each shown condition with its measured value and most important action, word for word from the full advice. */
function SummaryPoints({ summary }: { summary: WeatherAdviceCardSummary }) {
  return <View style={styles.points}>
    {summary.points.map((point) => point.urgency
      ? <View key={point.conditionCode} style={styles.point}>
        <Text style={[styles.pointCondition, point.urgency === 'urgent' && styles.pointUrgent]}>{point.condition}{point.detail ? ': ' + point.detail : ''}</Text>
        {!!point.action && <View style={styles.actionRow}><Text style={styles.bullet}>•</Text><Text style={[styles.pointAction, styles.flex]}>{point.action}</Text></View>}
      </View>
      : <View key={point.conditionCode} style={styles.point}>
        {!!point.detail && <Text style={ui.body}>{point.detail}</Text>}
        {!!point.action && <Text style={ui.body}>{point.action}</Text>}
      </View>)}
    {summary.also.length > 0 && <Text style={ui.small}>Also triggered: {summary.also.join(', ')}. See the full advice.</Text>}
  </View>;
}

function levelCardStyle(level: WeatherAdviceCardSummary['level']) {
  return level === 'urgent' ? styles.urgentCard : level === 'normal' ? null : styles.cautionCard;
}

/** Full panel for the Weather Advice screen: the summary first, the complete advice behind "View full advice". */
export function WeatherAdvicePanel({ data, loading, error, errorCode = null, errorStatus = null, onRetry, initiallyFull = false }: { data: WeatherAdviceResult | null; loading: boolean; error: string; errorCode?: ApiFailureCode | null; errorStatus?: number | null; onRetry: () => void; initiallyFull?: boolean }) {
  const [full, setFull] = useState(initiallyFull);
  if (loading && !data) {
    return <View style={[ui.card, styles.centered]} accessibilityLiveRegion="polite">
      <ActivityIndicator color={palette.forest} accessibilityLabel="Loading weather-based advice" />
      <Text style={ui.cardTitle}>Loading weather-based advice</Text>
      <Text style={[ui.small, styles.center]}>Fetching a real weather reading for your farm location…</Text>
    </View>;
  }
  if (!data) {
    // The AgriReach API (or the path to it) failed: this is NOT a weather problem. Say which failure it is.
    return <View style={[ui.card, styles.centered]}>
      <Icon name="weather" size={30} color={palette.amber} />
      <Text style={ui.cardTitle}>{errorCode ? API_FAILURE_TITLES[errorCode] : 'Weather-based advice could not be loaded'}</Text>
      <Text style={[ui.body, styles.center]}>{error || 'Weather-based advice could not be loaded.'}</Text>
      <CodeNote code={errorCode} status={errorStatus} />
      {errorCode !== 'auth' && <Action title="Try again" secondary onPress={onRetry} />}
    </View>;
  }
  if (data.status === 'unavailable' || !data.advice || !data.weather) {
    const needsPin = data.reasonCode === 'location_outside_city' || data.reasonCode === 'location_invalid';
    return <View style={[ui.card, styles.unavailable]}>
      <SourceBadges stale={false} />
      <View style={ui.row}><Icon name="weather" size={24} color={palette.amber} /><Text style={[ui.cardTitle, styles.flex]}>{unavailableTitle(data.reasonCode)}</Text></View>
      {data.farm && <Text style={ui.small}>For {data.farm.name} · {data.farm.category}</Text>}
      <Text style={ui.body}>{data.reason || data.statusMessage || 'Weather data is not available right now.'}</Text>
      <Text style={ui.small}>No advice is shown because no real weather reading was obtained. Nothing has been estimated.</Text>
      <CodeNote code={data.reasonCode} />
      {needsPin ? <Action title="Review farm location" secondary onPress={() => router.push('/my-farms')} /> : <Action title="Try again" secondary onPress={onRetry} />}
    </View>;
  }

  const { weather, advice, farm, location, summary } = data;
  const stale = data.status === 'stale';
  const cautious = advice.assessment !== 'no_threshold_exceeded';
  const calc = advice.calculations;
  const grid = gridText(location, weather);
  // An older server sends no summary: show the full advice as before.
  const showFull = full || !summary;
  const level = summary ? LEVEL_BADGES[summary.level] : null;
  return <View style={styles.stack}>
    {stale && <View style={styles.staleBanner} accessibilityRole="alert">
      <Text style={styles.staleTitle}>Not current: this reading is {ageText(weather.observationAgeMinutes)}</Text>
      <Text style={styles.staleText}>{data.statusMessage || 'The weather service could not be reached, so the last saved reading is shown.'}</Text>
    </View>}
    {summary && <View style={[ui.card, levelCardStyle(summary.level)]} accessibilityRole={summary.level === 'urgent' ? 'alert' : undefined}>
      <View style={styles.badgeRow}><Badge label="Weather-based advice" tone="blue" /><Badge label="Rule-based · not AI" tone="neutral" />{level && <Badge label={level.label} tone={level.tone} />}{stale && <Badge label="Not current" tone="amber" />}</View>
      <View style={ui.row}><Icon name={categoryIcon(advice.category)} size={22} /><Text style={[ui.small, styles.flex]}>{farm?.name ?? 'Your farm'} · {advice.category}</Text></View>
      <Text style={styles.heading}>{summary.title}</Text>
      <SummaryPoints summary={summary} />
      <Text style={ui.small}>{stale ? 'Not current · ' : ''}Reading time {formatDate(weather.observedAt, true)} · weather-model value, not a forecast or PAGASA warning</Text>
      <Pressable accessibilityRole="button" accessibilityState={{ expanded: full }} accessibilityLabel={full ? 'Hide full weather advice' : 'View full weather advice'} onPress={() => setFull((value) => !value)} style={styles.linkRow}>
        <Icon name="advice" size={18} /><Text style={styles.linkText}>{full ? 'Hide full advice' : 'View full advice'}</Text><Icon name="chevron" size={16} />
      </Pressable>
    </View>}
    {showFull && <>
    <View style={ui.card}>
      <SourceBadges stale={stale} />
      <View style={ui.row}>
        <Icon name={categoryIcon(advice.category)} size={22} />
        <View style={styles.flex}>
          <Text style={ui.cardTitle}>{farm?.name ?? 'Your farm'}</Text>
          <Text style={ui.small}>{advice.category}{farm?.barangayName ? ' · ' + farm.barangayName : ''}</Text>
        </View>
      </View>
      <View style={ui.row}><Icon name="pin" size={16} /><Text style={[ui.small, styles.flex]}>{location?.label}</Text></View>
      <View style={styles.weatherRow}>
        <View style={styles.temperatureCopy}>
          <Text style={styles.temperature}>{weather.temperature === null ? '—' : number(weather.temperature) + weather.units.temperature}</Text>
          <Text style={ui.small}>Air temperature (model)</Text>
          <Text style={ui.body}>{weather.condition}</Text>
        </View>
        <View style={styles.metrics}>
          {weather.humidity !== null && <Metric icon="water" label="Humidity" value={number(weather.humidity) + weather.units.humidity} />}
          {weather.windSpeed !== null && <Metric icon="wind" label="Wind" value={number(weather.windSpeed) + ' ' + weather.units.windSpeed} />}
          {weather.windGusts !== null && <Metric icon="wind" label="Gusts (highest, past hour)" value={number(weather.windGusts) + ' ' + weather.units.windSpeed} />}
          {calc.latestHourMm !== null && <Metric icon="weather" label="Rain, latest hour" value={number(calc.latestHourMm) + ' ' + weather.units.precipitation} />}
          {calc.last3hMm !== null && <Metric icon="weather" label="Rain, last 3 h" value={number(calc.last3hMm) + ' ' + weather.units.precipitation} />}
          {calc.last6hMm !== null && <Metric icon="weather" label="Rain, last 6 h" value={number(calc.last6hMm) + ' ' + weather.units.precipitation} />}
          {calc.heatIndexC !== null && <Metric icon="weather" label="Heat index (estimate)" value={number(calc.heatIndexC) + weather.units.temperature} />}
        </View>
      </View>
      <View style={styles.sourceBox}>
        <Text style={ui.small}>Data source: {advice.dataSource}</Text>
        <Text style={ui.small}>Weather reading time: {formatDate(weather.observedAt, true)} · Retrieved {formatDate(weather.fetchedAt, true)}</Text>
        <Text style={ui.small}>{grid}</Text>
        {(location?.providerGrid?.notes ?? []).map((note) => <Text key={note} style={ui.small}>• {note}</Text>)}
        <Text style={ui.small}>This is a weather-model value for the grid cell, not an exact measurement at your farm.{calc.heatIndexC !== null ? ' The heat index is an AgriReach estimate from air temperature and humidity, not PAGASA’s own value.' : ''}</Text>
      </View>
    </View>

    <View style={[styles.statusBox, cautious && styles.statusBoxCautious]} accessibilityRole={cautious ? 'alert' : undefined}>
      <Text style={styles.heading}>{advice.headline}</Text>
      <Text style={ui.small}>{advice.statement}</Text>
    </View>
    {advice.matched.map((match) => <View key={match.code} style={styles.matchCard}>
      <Text style={styles.matchTitle}>{match.label}</Text>
      <Text style={ui.small}>{match.measured}</Text>
      <Text style={ui.small}>Rule: {match.threshold}</Text>
      <Text style={ui.small}>{match.basisType === 'reference' ? 'PAGASA reference value' : 'Project-defined'}: {match.basisLabel}</Text>
    </View>)}
    {advice.limitations.length > 0 && <View style={styles.limitBox}>
      <Text style={styles.matchTitle}>{advice.assessment === 'unclear' ? 'Why the data is unclear' : 'What could not be checked'}</Text>
      {advice.limitations.map((item) => <Text key={item.code} style={ui.small}>• {item.label}: {item.reason}</Text>)}
    </View>}
    {advice.recommendations.map((item) => <View key={item.conditionCode} style={ui.card}>
      <View style={ui.row}><Icon name="advice" size={18} /><Text style={[ui.cardTitle, styles.flex]}>{item.conditionLabel} · {item.category}</Text></View>
      {item.actions.map((action, index) => <View key={index} style={styles.actionRow}><Text style={styles.bullet}>•</Text><Text style={[ui.body, styles.flex]}>{action}</Text></View>)}
      <Text style={ui.small}>Based on the reading from {formatDate(item.observedAt, true)} ({item.source}).</Text>
    </View>)}
    <Text style={ui.small}>{advice.disclaimer}</Text>
    <Text style={ui.small}>{advice.guidanceReview}</Text>
    <Text style={ui.small}>{advice.method} Rule set {advice.ruleSetVersion}. Admin advisories are shown separately below.</Text>
    </>}
    {loading && <ActivityIndicator color={palette.forest} accessibilityLabel="Refreshing weather-based advice" />}
    {!!error && <Text style={styles.staleText}>Refresh failed: {error}</Text>}
  </View>;
}

function gridText(location: WeatherAdviceResult['location'], weather: NonNullable<WeatherAdviceResult['weather']>) {
  const origin = location?.basis === 'farm_pin' ? 'your farm pin' : 'the Cabadbaran City-centre point';
  const grid = location?.providerGrid;
  if (!grid || grid.distanceKm === null) return `Model grid cell near ${origin}; the distance to it is not known.`;
  const elevation = grid.elevationM !== null ? ` · grid elevation about ${grid.elevationM} m` : weather.providerElevation != null ? ` · grid elevation about ${Math.round(weather.providerElevation)} m` : '';
  return `Model grid cell about ${number(grid.distanceKm)} km from ${origin}${elevation}.`;
}

/** Compact card for the home screen. */
export function WeatherAdviceSummary({ data, loading, error, errorCode = null, errorStatus = null, onRetry }: { data: WeatherAdviceResult | null; loading: boolean; error: string; errorCode?: ApiFailureCode | null; errorStatus?: number | null; onRetry: () => void }) {
  const open = () => router.push('/weather-advice');
  if (loading && !data) return <View style={[ui.card, styles.centered]}><ActivityIndicator color={palette.forest} accessibilityLabel="Loading weather-based advice" /><Text style={ui.small}>Loading weather-based advice…</Text></View>;
  if (!data || data.status === 'unavailable' || !data.advice || !data.weather) {
    return <Pressable accessibilityRole="button" onPress={open} style={ui.card}>
      <View style={styles.badgeRow}><Badge label="Weather-based advice" tone="blue" /></View>
      <Text style={ui.cardTitle}>{data ? unavailableTitle(data.reasonCode) : errorCode ? API_FAILURE_TITLES[errorCode] : 'Weather unavailable'}</Text>
      <Text style={ui.body}>{data?.reason || error || 'No real weather reading is available for your farm right now.'}</Text>
      <CodeNote code={data?.reasonCode ?? errorCode} status={data ? null : errorStatus} />
      <Pressable accessibilityRole="button" onPress={onRetry} style={styles.retry}><Text style={styles.retryText}>Try again</Text></Pressable>
    </Pressable>;
  }
  const stale = data.status === 'stale';
  const { advice, summary } = data;
  const cautious = advice.assessment === 'limited' || advice.assessment === 'unclear';
  // Older server without a summary: only a triggered rule shows an action; otherwise the plain statement.
  const body = advice.assessment === 'thresholds_exceeded' ? advice.recommendations[0]?.actions[0] : advice.statement;
  const grid = data.location?.providerGrid;
  const level = summary ? LEVEL_BADGES[summary.level] : null;
  // The whole card opens the full advice (one button: nested buttons confuse screen readers).
  const openFull = () => router.push({ pathname: '/weather-advice', params: { full: '1' } });
  return <Pressable accessibilityRole="button" accessibilityLabel="View full weather-based advice" onPress={openFull} style={[ui.card, summary ? levelCardStyle(summary.level) : (stale || cautious) && styles.staleCard]}>
    <View style={styles.badgeRow}><Badge label="Weather-based advice" tone="blue" /><Badge label="Rule-based · not AI" tone="neutral" />{level && <Badge label={level.label} tone={level.tone} />}{stale && <Badge label="Not current" tone="amber" />}</View>
    <View style={ui.row}><Icon name={categoryIcon(advice.category)} size={22} /><View style={styles.flex}><Text style={ui.cardTitle}>{advice.headline}</Text><Text style={ui.small}>{data.farm?.name} · {advice.category}</Text></View><Icon name="chevron" /></View>
    {summary ? <SummaryPoints summary={summary} /> : !!body && <Text style={ui.body} numberOfLines={3}>{body}</Text>}
    <Text style={ui.small}>{stale ? 'Not current · ' : ''}Reading time {formatDate(data.weather.observedAt, true)} · model grid cell{grid?.distanceKm != null ? ` ${number(grid.distanceKm)} km from the pin` : ''} · not a forecast or PAGASA warning</Text>
    <View style={styles.linkRow}><Icon name="advice" size={18} /><Text style={styles.linkText}>View full advice</Text></View>
  </Pressable>;
}

function Metric({ icon, label, value }: { icon: 'water' | 'wind' | 'weather'; label: string; value: string }) {
  return <View style={styles.metric}><Icon name={icon} size={15} /><Text style={[ui.small, styles.flex]}>{label}</Text><Text style={styles.metricValue}>{value}</Text></View>;
}

const styles = StyleSheet.create({
  stack: { gap: 12 }, flex: { flex: 1, flexShrink: 1 }, center: { textAlign: 'center' }, centered: { alignItems: 'center', paddingVertical: 24, gap: 10 },
  badgeRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  weatherRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 14 }, temperatureCopy: { minWidth: 110, flexGrow: 1 },
  temperature: { color: palette.deep, fontSize: 34, fontWeight: '800', letterSpacing: -1 },
  metrics: { flexGrow: 1, gap: 6, minWidth: 170 }, metric: { flexDirection: 'row', alignItems: 'center', gap: 6 }, metricValue: { color: palette.ink, fontSize: 12, fontWeight: '600', flexShrink: 1 },
  sourceBox: { borderTopWidth: 1, borderColor: palette.border, paddingTop: 6, gap: 2 },
  heading: { color: palette.ink, fontSize: 17, fontWeight: '700' },
  statusBox: { backgroundColor: palette.sage, borderColor: palette.border, borderWidth: 1, borderRadius: 12, padding: 12, gap: 4, marginTop: 4 },
  statusBoxCautious: { backgroundColor: palette.amberSoft, borderColor: '#E9D7AC' },
  limitBox: { backgroundColor: palette.paper, borderColor: '#E9D7AC', borderWidth: 1, borderRadius: 12, padding: 12, gap: 4 },
  matchCard: { backgroundColor: palette.amberSoft, borderColor: '#F0DFC1', borderWidth: 1, borderRadius: 12, padding: 12, gap: 3 }, matchTitle: { color: palette.amber, fontSize: 15, fontWeight: '800' },
  actionRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 }, bullet: { color: palette.forest, fontSize: 16, lineHeight: 21, fontWeight: '800' },
  staleBanner: { backgroundColor: palette.amberSoft, borderColor: '#E9D7AC', borderWidth: 1, borderRadius: 12, padding: 12, gap: 3 }, staleTitle: { color: palette.amber, fontSize: 14, fontWeight: '800' }, staleText: { color: palette.amber, fontSize: 12, lineHeight: 18 },
  staleCard: { borderColor: '#E9D7AC' }, unavailable: { backgroundColor: '#FFF8E8', borderColor: '#E9D7AC' },
  retry: { minHeight: 44, justifyContent: 'center' }, retryText: { color: palette.forest, fontSize: 14, fontWeight: '700' },
  urgentCard: { borderColor: '#E7C4BC', borderLeftWidth: 4, borderLeftColor: palette.red }, cautionCard: { borderColor: '#E9D7AC', borderLeftWidth: 4, borderLeftColor: palette.amber },
  points: { gap: 8 }, point: { gap: 3 }, pointCondition: { color: palette.amber, fontSize: 14, fontWeight: '800', lineHeight: 19 }, pointUrgent: { color: palette.red },
  pointAction: { color: palette.ink, fontSize: 15, fontWeight: '600', lineHeight: 21 },
  linkRow: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44, alignSelf: 'flex-start' }, linkText: { color: palette.forest, fontSize: 14, fontWeight: '700' },
});
