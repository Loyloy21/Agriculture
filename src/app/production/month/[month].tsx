import { useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { AppScreen, EmptyState, palette, SectionHeading, ui } from '@/components/farm-ui';
import { CropBreakdown, OtherMeasures, ProductionRecordCard } from '@/components/production-ui';
import { FarmerDataApiError, getProductionMonth, type ProductionMonthDetail } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { formatKg, monthLabel, otherTotalsLine } from '@/utils/production';

export default function ProductionMonthScreen() {
  const { month } = useLocalSearchParams<{ month: string }>();
  const { refreshSession } = useFarmerAuth();
  const [detail, setDetail] = useState<ProductionMonthDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let active = true;
    setDetail(null); setLoading(true); setError('');
    void (async () => {
      try { const result = await getProductionMonth(String(month || '')); if (active) setDetail(result); }
      catch (e) {
        if (e instanceof FarmerDataApiError && e.status === 401) await refreshSession();
        if (active) setError(e instanceof Error ? e.message : 'This month is unavailable.');
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
  }, [month, retry, refreshSession]);

  return <AppScreen back>
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}>
      {loading ? <EmptyState title="Loading month…" loading /> : !detail ? <EmptyState title="Month unavailable" message={error} retry={() => setRetry((value) => value + 1)} /> : <>
        <View style={styles.totalPanel}>
          <Text style={styles.caption}>{detail.label}</Text>
          <Text style={styles.total}>{formatKg(detail.totalKg)}</Text>
          {detail.otherTotals.length > 0 && <Text style={styles.other}>+ {otherTotalsLine(detail.otherTotals)}</Text>}
          <Text style={styles.caption}>{detail.recordCount} counted {detail.recordCount === 1 ? 'record' : 'records'}</Text>
        </View>
        <View style={ui.card}>
          <Text style={ui.cardTitle}>Production by crop</Text>
          <CropBreakdown crops={detail.crops} monthLabel={detail.label} />
          <OtherMeasures measures={detail.otherMeasures} />
        </View>
        <SectionHeading title="Production records" icon="reports" />
        {detail.records.length === 0
          ? <EmptyState title={`No records for ${monthLabel(detail.month)}`} message="Production you record with a harvest date in this month will appear here." />
          : detail.records.map((record) => <ProductionRecordCard key={record.id} record={record} />)}
      </>}
    </ScrollView>
  </AppScreen>;
}

const styles = StyleSheet.create({
  totalPanel: { backgroundColor: palette.forest, borderRadius: 16, padding: 16, gap: 4 },
  caption: { color: '#DFEADB', fontSize: 13, fontWeight: '600' },
  total: { color: palette.paper, fontSize: 30, fontWeight: '800', letterSpacing: -0.6 },
  other: { color: palette.paper, fontSize: 14, fontWeight: '700' },
});
