import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Action, AppScreen, categoryIcon, EmptyState, formatDate, Icon, palette, StatusBadge, ui } from '@/components/farm-ui';
import { FarmerDataApiError, listDamageReports, type DamageReport } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import ProtectedReportPhoto from '@/components/protected-report-photo';

const filters = ['All', 'Pending', 'Under Review', 'Resolved', 'Approved', 'Rejected'] as const;
export default function MyReportsScreen() {
  const { refreshSession } = useFarmerAuth();
  const [reports, setReports] = useState<DamageReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [filter, setFilter] = useState<string>('All');
  const [visible, setVisible] = useState(20);
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    if (refresh) setRefreshing(true); else setLoading(true);
    setLoadError('');
    try { const result = await listDamageReports(); if (request === revision.current) setReports(result); }
    catch (error) {
      if (error instanceof FarmerDataApiError && error.status === 401) void refreshSession();
      if (request === revision.current) setLoadError(error instanceof Error ? error.message : 'Reports are unavailable.');
    } finally { if (request === revision.current) { setLoading(false); setRefreshing(false); } }
  }, [refreshSession]);
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current += 1; }; }, [load]));
  const normalized = (report: DamageReport) => report.status === 'Received' ? 'Pending' : report.status;
  const filtered = reports.filter((report) => filter === 'All' || normalized(report) === filter);
  return <AppScreen tab="reports">
    <FlatList data={filtered.slice(0, visible)} keyExtractor={(report) => report.id} contentContainerStyle={ui.content}
      refreshing={refreshing} onRefresh={() => void load(true)} showsVerticalScrollIndicator={false}
      ListHeaderComponent={<View style={styles.heading}>
        <View style={styles.titlePanel}><View style={{ flex: 1 }}><Text style={styles.title}>Damage Reports</Text><Text style={styles.subtitle}>Track and manage your damage reports.</Text></View><Action title="New report" icon="plus" secondary onPress={() => router.push('/reports/new')} /></View>
        <View style={styles.summary}>{(['Pending', 'Under Review', 'Resolved'] as const).map((status) => <View key={status} style={[styles.summaryCard, { backgroundColor: status === 'Pending' ? palette.amberSoft : status === 'Under Review' ? palette.blueSoft : palette.sage }]}>
          <Text style={styles.count}>{loading || (loadError && reports.length === 0) ? '—' : reports.filter((report) => normalized(report) === status).length}</Text><Text style={ui.small}>{status}</Text>
        </View>)}</View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={ui.chips}>{filters.map((value) => <Pressable accessibilityRole="button" accessibilityState={{ selected: value === filter }} key={value} onPress={() => { setFilter(value); setVisible(20); }} style={[ui.chip, filter === value && ui.chipActive]}><Text style={[ui.chipText, filter === value && ui.chipTextActive]}>{value}</Text></Pressable>)}</ScrollView>
        {!!loadError && reports.length > 0 && <Text style={styles.error}>{loadError}</Text>}
      </View>}
      renderItem={({ item }) => <Pressable accessibilityRole="button" accessibilityLabel={item.id + ', ' + (item.farmName || item.farmType) + ', ' + item.status} onPress={() => router.push({ pathname: '/reports/[id]', params: { id: item.id } })} style={({ pressed }) => [ui.card, styles.report, pressed && { opacity: 0.8 }]}>
        {item.photos?.[0]?.uri ? <View style={{ width: 64 }}><ProtectedReportPhoto uri={item.photos[0].uri} height={76} compact /></View> : <View accessibilityLabel="No saved photo thumbnail" style={styles.category}><Icon name={categoryIcon(item.farmType)} size={26} /></View>}
        <View style={styles.reportCopy}><View style={styles.reportHeading}><Text style={ui.small}>{item.farmType}</Text><StatusBadge status={item.status} /></View>
          <Text style={ui.cardTitle}>{item.farmName || 'Farm name unavailable'}</Text>
          <Text style={ui.body} numberOfLines={2}>{item.damageDetails?.summary || item.description}</Text>
          <View style={ui.row}><Icon name="calendar" size={14} color={palette.muted} /><Text style={ui.small}>{formatDate(item.date)}</Text></View>
        </View><Icon name="chevron" size={18} color={palette.muted} />
      </Pressable>}
      ListEmptyComponent={<EmptyState title={loading ? 'Loading your reports' : loadError ? 'Reports unavailable' : filter === 'All' ? 'No reports yet' : 'No ' + filter.toLowerCase() + ' reports'} message={loadError || (filter === 'All' ? 'Your submitted reports and latest Admin responses will appear here.' : 'Choose another status to see your reports.')} loading={loading} retry={loadError ? () => void load() : undefined} />}
      ListFooterComponent={filtered.length > visible ? <Action title="Show more reports" secondary onPress={() => setVisible((count) => count + 20)} /> : undefined}
    />
  </AppScreen>;
}
const styles = StyleSheet.create({
  heading: { gap: 10, marginBottom: 4 }, titlePanel: { backgroundColor: palette.forest, borderRadius: 16, padding: 14, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 12 }, title: { color: palette.paper, fontSize: 24, fontWeight: '800' }, subtitle: { color: '#DFEADB', fontSize: 13, marginTop: 5, lineHeight: 19 },
  summary: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, summaryCard: { flex: 1, minWidth: 82, borderRadius: 13, padding: 10, gap: 2 }, count: { color: palette.ink, fontSize: 21, fontWeight: '800' }, report: { flexDirection: 'row', alignItems: 'center', gap: 11 }, category: { backgroundColor: palette.sage, width: 48, height: 64, borderRadius: 12, alignItems: 'center', justifyContent: 'center' }, reportCopy: { flex: 1, minWidth: 0, gap: 3 }, reportHeading: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 5 }, error: { color: palette.red, fontSize: 13, lineHeight: 19 },
});
