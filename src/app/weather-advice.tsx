import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { Action, AppScreen, Badge, categoryIcon, EmptyState, formatDate, Hero, Icon, palette, ui } from '@/components/farm-ui';
import { useWeatherAdvice, WeatherAdvicePanel } from '@/components/weather-advice-panel';
import { FarmerDataApiError, listFarmerFarms, listWeatherAdvisories, type FarmerFarm, type WeatherAdvisory } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

type Category = WeatherAdvisory['category'];
export default function WeatherAdviceScreen() {
  // full=1: opened from "View full advice" on the home card, so the weather-based advice starts expanded.
  const { full } = useLocalSearchParams<{ full?: string }>();
  const { farmer, refreshSession } = useFarmerAuth();
  const [items, setItems] = useState<WeatherAdvisory[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [farms, setFarms] = useState<FarmerFarm[]>([]);
  const [farmId, setFarmId] = useState<number | null>(null);
  const [farmsReady, setFarmsReady] = useState(false);
  const [selected, setSelected] = useState<Category | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    refresh ? setRefreshing(true) : setLoading(true); setError('');
    try {
      const [advisories, farms] = await Promise.all([listWeatherAdvisories(), listFarmerFarms()]);
      if (request !== revision.current) return;
      const activeFarms = farms.farms.filter(f => f.status === 'Active');
      const active = Array.from(new Set(activeFarms.map(f => f.agriculturalCategory)));
      setItems(advisories.items); setCategories(active); setFarms(activeFarms); setFarmsReady(true);
      setFarmId(current => current && activeFarms.some(f => f.id === current) ? current : (activeFarms.find(f => f.isPrimary) ?? activeFarms[0])?.id ?? null);
      setSelected(current => current && active.includes(current) ? current : null);
    } catch (e) {
      if (request === revision.current) setError(e instanceof Error ? e.message : 'Advisories could not be loaded.');
      if (e instanceof FarmerDataApiError && e.status === 401) await refreshSession();
    } finally { if (request === revision.current) { setLoading(false); setRefreshing(false); } }
  }, [farmer?.id, refreshSession]);
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current++; }; }, [load]));
  const advice = useWeatherAdvice(farmId, farmsReady && farms.length > 0);
  const visible = selected ? items.filter(item => item.category === selected) : items;
  return <AppScreen tab="advice">
    <ScrollView contentContainerStyle={ui.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { void load(true); void advice.reload(true); }} />}>
      <Hero kind="weather" compact title="Weather Advice" subtitle="Practical guidance for your registered farms." />
      <View style={ui.row}><Icon name="pin" /><Text style={ui.body}>Cabadbaran City, Agusan del Norte</Text></View>
      {farms.length > 0 && <View style={{ gap: 8 }}>
        <Text style={ui.cardTitle}>Weather-based advice</Text>
        <Text style={ui.small}>Computed from a real weather reading for the farm you select, using fixed rules for its category. Showing: {farms.find(f => f.id === farmId)?.name ?? 'your farm'}.</Text>
        {farms.length > 1 && <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
          {farms.map(farm => <Pressable key={farm.id} accessibilityRole="button" accessibilityState={{ selected: farm.id === farmId }} accessibilityLabel={'Show weather advice for ' + farm.name} onPress={() => setFarmId(farm.id)} style={[ui.chip, farm.id === farmId && ui.chipActive]}><Icon name={categoryIcon(farm.agriculturalCategory)} color={farm.id === farmId ? 'white' : palette.forest} size={19} /><Text style={[ui.chipText, farm.id === farmId && ui.chipTextActive]}>{farm.name}</Text></Pressable>)}
        </ScrollView>}
        <WeatherAdvicePanel data={advice.data} loading={advice.loading} error={advice.error} errorCode={advice.errorCode} errorStatus={advice.errorStatus} onRetry={() => void advice.reload(true)} initiallyFull={full === '1'} />
      </View>}
      <View style={ui.row}><Icon name="alerts" size={20} color={palette.amber} /><Text style={ui.cardTitle}>Admin advisories</Text></View>
      <Text style={ui.small}>Guidance written and published by a Department of Agriculture administrator. Different from the automatic weather-based advice above.</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
        <Pressable accessibilityRole="button" onPress={() => setSelected(null)} style={[ui.chip, !selected && ui.chipActive]}><Text style={[ui.chipText, !selected && ui.chipTextActive]}>All</Text></Pressable>
        {categories.map(category => <Pressable key={category} accessibilityRole="button" onPress={() => setSelected(category)} style={[ui.chip, selected === category && ui.chipActive]}><Icon name={categoryIcon(category)} color={selected === category ? 'white' : palette.forest} size={19} /><Text style={[ui.chipText, selected === category && ui.chipTextActive]}>{category}</Text></Pressable>)}
      </ScrollView>
      {!categories.length && !loading && !error ? <EmptyState icon="farm" title="No active farm categories" message="Add or update a Cabadbaran farm to receive matching advice."><Action title="Open My Farms" secondary onPress={() => router.push('/my-farms')} /></EmptyState> : null}
      {loading ? <ActivityIndicator color={palette.forest} accessibilityLabel="Loading advisories" /> : null}
      {error ? <EmptyState title="Advice unavailable" message={error}><Action title="Try again" secondary onPress={() => void load()} /></EmptyState> : null}
      {visible.map(item => <View key={item.id} style={ui.card}>
        <View style={[ui.row, { flexWrap: 'wrap', gap: 6 }]}><Badge label="Admin advisory" tone="green" />{item.weatherCondition && item.weatherCondition !== 'Other' ? <Badge label={item.weatherCondition} tone={item.weatherCondition === 'Normal Weather' ? 'neutral' : 'amber'} /> : null}</View>
        <View style={ui.row}><Icon name={categoryIcon(item.category)} size={20} /><Text style={[ui.cardTitle, { flex: 1 }]}>{item.title}</Text></View>
        {/* The preview is the admin's Key advice or the whole short message, so it is never clipped. */}
        {item.preview ? <Text style={item.preview.source === 'none' ? ui.small : ui.body}>{item.preview.text}</Text> : <Text style={ui.body} numberOfLines={3}>{item.message}</Text>}
        <View style={ui.row}><Icon name="pin" size={17} /><Text style={[ui.small, { flex: 1 }]}>{item.locationCoverage}</Text></View>
        <View style={ui.row}><Icon name="calendar" size={17} /><Text style={[ui.small, { flex: 1 }]}>{formatDate(item.effectiveDate)}{item.expirationDate ? ' – ' + formatDate(item.expirationDate) : ' · Until further notice'}</Text></View>
        <View style={[ui.row, { justifyContent: 'space-between', flexWrap: 'wrap' }]}><Text style={ui.small}>{item.category}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={'View full advice: ' + item.title} onPress={() => router.push({ pathname: '/weather/[id]', params: { id: item.id } })} style={[ui.row, { minHeight: 44 }]}><Icon name="advice" size={18} /><Text style={{ color: palette.forest, fontSize: 14, fontWeight: '700' }}>View full advice</Text><Icon name="chevron" size={16} /></Pressable>
        </View>
      </View>)}
      {!loading && !error && categories.length > 0 && !visible.length ? <EmptyState icon="weather" title="No active advisories" message="There is no matching advice for your farms right now." /> : null}
    </ScrollView>
  </AppScreen>;
}
