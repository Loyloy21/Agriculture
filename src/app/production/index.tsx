import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Action, AppScreen, EmptyState, palette, SectionHeading, ui } from '@/components/farm-ui';
import { CropBreakdown, OtherMeasures, ProductionRecordCard, productionStyles } from '@/components/production-ui';
import { FarmerDataApiError, getProductionOverview, type ProductionMonthSummary, type ProductionOverview } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { formatKg, otherTotalsLine } from '@/utils/production';

export default function ProductionReportScreen() {
  const { refreshSession } = useFarmerAuth();
  const [overview, setOverview] = useState<ProductionOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [selected, setSelected] = useState<'current' | 'previous'>('current');
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    if (refresh) setRefreshing(true); else setLoading(true);
    setLoadError('');
    try { const result = await getProductionOverview(); if (request === revision.current) setOverview(result); }
    catch (error) {
      if (error instanceof FarmerDataApiError && error.status === 401) void refreshSession();
      if (request === revision.current) setLoadError(error instanceof Error ? error.message : 'Production records are unavailable.');
    } finally { if (request === revision.current) { setLoading(false); setRefreshing(false); } }
  }, [refreshSession]);
  // Reloads whenever the screen regains focus, so a record saved on the form is reflected immediately.
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current += 1; }; }, [load]));
  const month = overview ? (selected === 'current' ? overview.currentMonth : overview.previousMonth) : null;

  return <AppScreen tab="reports">
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={palette.forest} />}>
      <View style={productionStyles.titlePanel}>
        <Text style={productionStyles.title}>Production Report</Text>
        <Text style={productionStyles.subtitle}>Your harvests in kilograms, totalled by the month they were harvested.</Text>
      </View>
      <Action title="Add Production Record" icon="plus" onPress={() => router.push('/production/new')} />
      {loading && !overview ? <EmptyState title="Loading your production" loading /> : !overview || !month ? <EmptyState title="Production unavailable" message={loadError} retry={() => void load()} /> : <>
        {!!loadError && <Text style={productionStyles.error}>Refresh failed: {loadError}</Text>}
        <SectionHeading title="Production Summary" icon="chart" />
        <View style={styles.summaryRow} accessibilityRole="radiogroup">
          <MonthTotal caption="This month" summary={overview.currentMonth} active={selected === 'current'} onPress={() => setSelected('current')} />
          <MonthTotal caption="Previous month" summary={overview.previousMonth} active={selected === 'previous'} onPress={() => setSelected('previous')} />
        </View>
        <View style={ui.card}>
          <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>Production by crop</Text><Text style={ui.small}>{month.label}</Text></View>
          <CropBreakdown crops={month.crops} monthLabel={month.label} />
          <OtherMeasures measures={month.otherMeasures} />
          <Pressable accessibilityRole="button" onPress={() => router.push({ pathname: '/production/month/[month]', params: { month: month.month } })} style={productionStyles.linkButton}>
            <Text style={productionStyles.link}>View {month.label} details</Text>
          </Pressable>
        </View>
        <SectionHeading title="Recent Production Records" icon="history" />
        {overview.recent.length === 0
          ? <EmptyState title="No production records yet" message="Tap Add Production Record after each harvest. Your monthly totals are calculated from these records." />
          : overview.recent.map((record) => <ProductionRecordCard key={record.id} record={record} />)}
        <Action title="View Production History" icon="history" secondary onPress={() => router.push('/production/history')} />
      </>}
    </ScrollView>
  </AppScreen>;
}

function MonthTotal({ caption, summary, active, onPress }: { caption: string; summary: ProductionMonthSummary; active: boolean; onPress: () => void }) {
  const other = otherTotalsLine(summary.otherTotals);
  return <Pressable accessibilityRole="radio" accessibilityState={{ checked: active }} accessibilityLabel={`${caption}, ${summary.label}, ${formatKg(summary.totalKg)}${other ? ', plus ' + other : ''}, ${summary.recordCount} records`} onPress={onPress}
    style={[styles.monthCard, active && styles.monthCardActive]}>
    <Text style={[ui.small, active && styles.activeText]}>{caption}</Text>
    <Text style={[styles.monthKg, active && styles.activeText]} numberOfLines={1} adjustsFontSizeToFit>{formatKg(summary.totalKg)}</Text>
    {!!other && <Text style={[styles.other, active && styles.activeText]}>+ {other}</Text>}
    <Text style={[styles.monthLabel, active && styles.activeText]}>{summary.label}</Text>
    <Text style={[ui.small, active && styles.activeText]}>{summary.recordCount} {summary.recordCount === 1 ? 'record' : 'records'}</Text>
  </Pressable>;
}

const styles = StyleSheet.create({
  summaryRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  monthCard: { flex: 1, minWidth: 130, borderRadius: 14, padding: 12, gap: 2, backgroundColor: palette.paper, borderWidth: 1, borderColor: palette.border },
  monthCardActive: { backgroundColor: palette.forest, borderColor: palette.forest },
  monthKg: { color: palette.deep, fontSize: 22, fontWeight: '800', marginTop: 2 },
  monthLabel: { color: palette.ink, fontSize: 13, fontWeight: '600' },
  other: { color: palette.forest, fontSize: 12, fontWeight: '600' },
  activeText: { color: palette.paper },
});
