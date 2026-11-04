import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Action, AppScreen, Badge, EmptyState, Hero, Icon, palette, ui, formatDate } from '@/components/farm-ui';
import { useWeatherAdvice, WeatherAdviceSummary } from '@/components/weather-advice-panel';
import { FarmerDataApiError, getCurrentWeather, listWeatherAdvisories, type CurrentWeather, type WeatherAdvisory } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

export default function FarmerHomeScreen() {
  const { farmer, refreshSession } = useFarmerAuth();
  const [weather, setWeather] = useState<CurrentWeather | null>(null);
  const [weatherLoading, setWeatherLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [weatherError, setWeatherError] = useState('');
  const [advisory, setAdvisory] = useState<WeatherAdvisory | null>(null);
  const [advisoryLoading, setAdvisoryLoading] = useState(true);
  const [advisoryError, setAdvisoryError] = useState('');
  const lastObservation = useRef<string | null>(null);
  const adviceState = useWeatherAdvice(null, Boolean(farmer));
  const revision = useRef(0);

  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    if (refresh) setRefreshing(true);
    else { setWeatherLoading(true); setAdvisoryLoading(true); }
    setWeatherError(''); setAdvisoryError('');
    if (refresh) void adviceState.reload(true);
    const results = await Promise.allSettled([getCurrentWeather(refresh), listWeatherAdvisories()]);
    if (request !== revision.current) return;
    const [current, advice] = results;
    if (current.status === 'fulfilled') {
      const value = current.value;
      setWeather({ ...value, statusMessage: value.statusMessage || (refresh && lastObservation.current === value.observedAt ? 'Provider checked; the latest observation has not changed.' : '') });
      lastObservation.current = value.observedAt;
    } else {
      if (current.reason instanceof FarmerDataApiError && current.reason.status === 401) void refreshSession();
      setWeatherError(current.reason instanceof Error ? current.reason.message : 'Current weather is unavailable.');
    }
    if (advice.status === 'fulfilled') {
      setAdvisory([...advice.value.items].sort((a, b) => (b.publishedAt || b.updatedAt).localeCompare(a.publishedAt || a.updatedAt))[0] || null);
    } else {
      setAdvisoryError(advice.reason instanceof Error ? advice.reason.message : 'Advisories are unavailable.');
    }
    setWeatherLoading(false); setAdvisoryLoading(false); setRefreshing(false);
  }, [farmer?.id, refreshSession, adviceState.reload]);
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current += 1; }; }, [load]));
  if (!farmer) return null;
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  return <AppScreen tab="home">
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={palette.forest} />}>
      <Hero kind="home" title={greeting + ', ' + (farmer.firstName || farmer.fullName)} subtitle="Stronger farms for a brighter Cabadbaran.">
        <Action title="Report Damage" icon="reports" onPress={() => router.push('/reports/new')} />
      </Hero>
      {weatherLoading && !weather ? <EmptyState title="Loading today's weather" loading /> : !weather ? <EmptyState title="Weather unavailable" message={weatherError} retry={() => void load(true)} /> :
        <View style={ui.card}>
          <View style={ui.row}><Icon name={weather.weatherCode === 0 ? 'sun' : 'weather'} size={28} /><View style={{ flex: 1 }}><Text style={ui.cardTitle}>Today's Weather</Text><Text style={ui.small}>{weather.location} · city-wide reading</Text></View></View>
          <View style={styles.weatherRow}>
            <View style={styles.temperatureCopy}><Text style={styles.temperature}>{weather.temperature === null ? '—' : number(weather.temperature) + weather.temperatureUnit}</Text><Text style={ui.body}>{weather.condition}</Text></View>
            <View style={styles.metrics}>
              {weather.humidity !== null && <Metric icon="water" label="Humidity" value={number(weather.humidity) + weather.humidityUnit} />}
              {weather.windSpeed !== null && <Metric icon="wind" label="Wind" value={number(weather.windSpeed) + ' ' + weather.windSpeedUnit} />}
              {weather.rain !== null ? <Metric icon="weather" label="Rain / past hour" value={number(weather.rain) + ' ' + weather.rainUnit} /> : weather.precipitation !== null && <Metric icon="weather" label="Precipitation" value={number(weather.precipitation) + ' ' + weather.precipitationUnit} />}
            </View>
          </View>
          <View style={styles.source}><Text style={ui.small}>{weather.provider} · {weather.observationType}</Text><Text style={ui.small}>Observed {formatDate(weather.observedAt, true)}</Text><Text style={ui.small}>Retrieved {formatDate(weather.fetchedAt, true)}</Text></View>
          {!!(weather.isStale || weather.statusMessage) && <Text style={styles.notice}>{weather.isStale ? 'Not current: showing a saved reading from ' + weather.cacheAgeMinutes + ' minutes ago. ' : ''}{weather.statusMessage}</Text>}
          {!!weatherError && <Text style={styles.notice}>Refresh failed: {weatherError}</Text>}
        </View>}
      <WeatherAdviceSummary data={adviceState.data} loading={adviceState.loading} error={adviceState.error} errorCode={adviceState.errorCode} errorStatus={adviceState.errorStatus} onRetry={() => void adviceState.reload(true)} />
      {advisoryLoading ? <EmptyState title="Checking your advisories" loading /> : advisoryError ? <EmptyState title="Advisories unavailable" message={advisoryError} retry={() => void load(true)} /> : advisory ? <Pressable accessibilityRole="button" onPress={() => router.push({ pathname: '/weather/[id]', params: { id: advisory.id } })} style={[ui.card, styles.advisory]}>
        <View style={ui.row}><Icon name="alerts" color={palette.amber} /><Badge label="Admin advisory" tone="green" /><Text style={[ui.cardTitle, { flex: 1 }]}>Latest advisory</Text><Icon name="chevron" /></View>
        <Text style={styles.advisoryTitle}>{advisory.title}</Text>
        {advisory.preview ? <Text style={advisory.preview.source === 'none' ? ui.small : ui.body}>{advisory.preview.text}</Text> : <Text style={ui.body} numberOfLines={2}>{advisory.message}</Text>}
        <Text style={ui.small}>{advisory.context ? advisory.context + ' · ' : ''}{advisory.locationCoverage}</Text>
        <View style={ui.row}><Icon name="advice" size={18} /><Text style={styles.fullLink}>View full advice</Text></View>
      </Pressable> : <View style={ui.card}><Text style={ui.cardTitle}>No active admin advisory</Text><Text style={ui.body}>No active administrator advisories currently match your registered farms.</Text></View>}
      <View style={styles.quickRow}>
        <Quick title="Reports" copy="Production and damage reports" icon="reports" onPress={() => router.push('/reports')} />
        <Quick title="My Farms" copy="Manage your farm locations" icon="farm" onPress={() => router.push('/my-farms')} />
      </View>
    </ScrollView>
  </AppScreen>;
}
function number(value: number) { return Number.isInteger(value) ? String(value) : value.toFixed(1); }
function Metric({ icon, label, value }: { icon: 'water' | 'wind' | 'weather'; label: string; value: string }) {
  return <View style={styles.metric}><Icon name={icon} size={15} /><Text style={[ui.small, { flex: 1 }]}>{label}</Text><Text style={styles.metricValue}>{value}</Text></View>;
}
function Quick({ title, copy, icon, onPress }: { title: string; copy: string; icon: 'reports' | 'farm'; onPress: () => void }) {
  return <Pressable accessibilityRole="button" onPress={onPress} style={({ pressed }) => [ui.card, styles.quick, pressed && { opacity: 0.8 }]}><View style={ui.row}><Icon name={icon} size={24} /><Text style={[ui.cardTitle, { flex: 1 }]}>{title}</Text><Icon name="chevron" size={16} /></View><Text style={ui.small}>{copy}</Text></Pressable>;
}
const styles = StyleSheet.create({
  farm: { color: '#EEF5E7', fontSize: 13, marginBottom: 3 }, weatherRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 14 }, temperatureCopy: { minWidth: 110, flexGrow: 1 },
  temperature: { color: palette.deep, fontSize: 34, fontWeight: '800', letterSpacing: -1 }, metrics: { flexGrow: 1, gap: 6, minWidth: 155 }, metric: { flexDirection: 'row', alignItems: 'center', gap: 6 }, metricValue: { color: palette.ink, fontSize: 12, fontWeight: '600', flexShrink: 1 },
  source: { borderTopWidth: 1, borderColor: palette.border, paddingTop: 6 }, notice: { color: palette.amber, fontSize: 12, lineHeight: 18 },
  advisory: { backgroundColor: '#FFF4E1', borderColor: '#F0DFC1' }, advisoryTitle: { color: palette.ink, fontWeight: '600', fontSize: 14 }, fullLink: { color: palette.forest, fontSize: 14, fontWeight: '700' }, quickRow: { flexDirection: 'row', gap: 12 }, quick: { flex: 1, minWidth: 0, padding: 12 },
});
