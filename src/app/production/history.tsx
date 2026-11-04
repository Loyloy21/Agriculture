import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { AppScreen, EmptyState, Icon, palette, SectionHeading, ui } from '@/components/farm-ui';
import { productionStyles } from '@/components/production-ui';
import { FarmerDataApiError, getProductionYear, type ProductionYear, type ProductionYearMonth } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { formatKg, manilaToday, otherTotalsLine } from '@/utils/production';

const CHART_HEIGHT = 140;

export default function ProductionHistoryScreen() {
  const { refreshSession } = useFarmerAuth();
  const [year, setYear] = useState(() => Number(manilaToday().slice(0, 4)));
  const [data, setData] = useState<ProductionYear | null>(null);
  const [years, setYears] = useState<number[]>([]);
  const [monthFilter, setMonthFilter] = useState<string>('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    void (async () => {
      try {
        const result = await getProductionYear(year);
        if (!active) return;
        setData(result);
        setYears(result.years);
      } catch (e) {
        if (e instanceof FarmerDataApiError && e.status === 401) void refreshSession();
        if (active) { setData(null); setError(e instanceof Error ? e.message : 'Production history is unavailable.'); }
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
  }, [year, retry, refreshSession]);

  const months = data ? data.months.filter((month) => monthFilter === 'all' || month.month.slice(5) === monthFilter) : [];
  const highest = data ? Math.max(0, ...data.months.map((month) => month.totalKg)) : 0;
  const openMonth = (month: ProductionYearMonth) => router.push({ pathname: '/production/month/[month]', params: { month: month.month } });

  return <AppScreen back>
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}>
      <View style={productionStyles.titlePanel}>
        <Text style={productionStyles.title}>Production History</Text>
        <Text style={productionStyles.subtitle}>Monthly totals in kilograms. Tap a month to see its crops and records.</Text>
      </View>
      {years.length > 0 && <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={ui.chips} accessibilityLabel="Year">
        {years.map((value) => <Pressable key={value} accessibilityRole="button" accessibilityState={{ selected: value === year }} onPress={() => { setYear(value); setMonthFilter('all'); }} style={[ui.chip, value === year && ui.chipActive]}>
          <Text style={[ui.chipText, value === year && ui.chipTextActive]}>{value}</Text>
        </Pressable>)}
      </ScrollView>}
      {loading ? <EmptyState title={`Loading ${year}`} loading /> : !data ? <EmptyState title="History unavailable" message={error} retry={() => setRetry((value) => value + 1)} /> : <>
        <View style={ui.card}>
          <View style={ui.row}><Icon name="chart" /><Text style={[ui.cardTitle, { flex: 1 }]}>{data.year} production</Text><Text style={styles.total}>{formatKg(data.totalKg)}</Text></View>
          {data.otherTotals.length > 0 && <Text style={styles.other}>+ {otherTotalsLine(data.otherTotals)} (not added to kg)</Text>}
          <Text style={ui.small}>{data.recordCount} counted {data.recordCount === 1 ? 'record' : 'records'} · the chart shows kilograms</Text>
          <View style={styles.chart} accessibilityLabel={`Monthly production chart for ${data.year}`}>
            {data.months.map((month) => {
              const height = highest > 0 ? Math.max(month.totalKg > 0 ? 6 : 2, Math.round(month.totalKg / highest * CHART_HEIGHT)) : 2;
              const selected = monthFilter === month.month.slice(5);
              return <Pressable key={month.month} accessibilityRole="button" accessibilityLabel={`${month.label}: ${formatKg(month.totalKg)}`} disabled={month.isFuture} onPress={() => openMonth(month)} style={styles.barColumn}>
                <View style={styles.barArea}><View style={[styles.bar, { height }, month.totalKg === 0 && styles.barEmpty, selected && styles.barSelected]} /></View>
                <Text style={[styles.barLabel, month.isFuture && { opacity: 0.45 }]}>{month.shortLabel}</Text>
              </Pressable>;
            })}
          </View>
          {highest === 0 && <Text style={[ui.body, { textAlign: 'center' }]}>No production recorded in {data.year}.</Text>}
        </View>
        <SectionHeading title="Months" icon="calendar" />
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={ui.chips} accessibilityLabel="Month">
          {[{ key: 'all', label: 'All months' }, ...data.months.map((month) => ({ key: month.month.slice(5), label: month.shortLabel }))].map((option) => <Pressable key={option.key} accessibilityRole="button" accessibilityState={{ selected: option.key === monthFilter }} onPress={() => setMonthFilter(option.key)} style={[ui.chip, option.key === monthFilter && ui.chipActive]}>
            <Text style={[ui.chipText, option.key === monthFilter && ui.chipTextActive]}>{option.label}</Text>
          </Pressable>)}
        </ScrollView>
        {months.map((month) => <Pressable key={month.month} accessibilityRole="button" disabled={month.isFuture} accessibilityLabel={`${month.label}, ${formatKg(month.totalKg)}, ${month.recordCount} records`} onPress={() => openMonth(month)}
          style={({ pressed }) => [ui.card, styles.monthRow, month.isFuture && { opacity: 0.55 }, pressed && { opacity: 0.8 }]}>
          <View style={{ flex: 1 }}>
            <Text style={ui.cardTitle}>{month.label}</Text>
            <Text style={ui.small}>{month.isFuture ? 'Upcoming month' : month.recordCount ? `${month.recordCount} ${month.recordCount === 1 ? 'record' : 'records'}` : 'No production records'}</Text>
            {month.otherTotals.length > 0 && <Text style={styles.other}>+ {otherTotalsLine(month.otherTotals)}</Text>}
          </View>
          <Text style={[styles.monthKg, month.totalKg === 0 && { color: palette.muted }]}>{formatKg(month.totalKg)}</Text>
          {!month.isFuture && <Icon name="chevron" size={18} color={palette.muted} />}
        </Pressable>)}
      </>}
    </ScrollView>
  </AppScreen>;
}

const styles = StyleSheet.create({
  total: { color: palette.deep, fontSize: 18, fontWeight: '800' },
  chart: { flexDirection: 'row', alignItems: 'flex-end', gap: 4, marginTop: 8 },
  barColumn: { flex: 1, minWidth: 0, alignItems: 'center', gap: 4 },
  barArea: { height: CHART_HEIGHT, width: '100%', justifyContent: 'flex-end', alignItems: 'center' },
  bar: { width: '80%', maxWidth: 22, borderTopLeftRadius: 5, borderTopRightRadius: 5, backgroundColor: palette.forest },
  barEmpty: { backgroundColor: palette.border },
  barSelected: { backgroundColor: palette.amber },
  barLabel: { color: palette.muted, fontSize: 10 },
  monthRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  monthKg: { color: palette.deep, fontSize: 16, fontWeight: '800' },
  other: { color: palette.forest, fontSize: 12, fontWeight: '600' },
});
