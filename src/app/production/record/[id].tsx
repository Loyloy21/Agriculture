import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { Action, AppScreen, EmptyState, formatDate, ui } from '@/components/farm-ui';
import { ProductionStatusBadge } from '@/components/production-ui';
import { FarmerDataApiError, getProductionRecord, type ProductionRecord } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { formatHarvestDate, formatKg, formatQuantity, monthLabel } from '@/utils/production';

/** Read-only: a submitted production record is not edited or deleted from the app. */
export default function ProductionRecordScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { refreshSession } = useFarmerAuth();
  const [record, setRecord] = useState<ProductionRecord | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let active = true;
    setRecord(null); setLoading(true); setError('');
    void (async () => {
      try { const result = await getProductionRecord(String(id || '')); if (active) setRecord(result); }
      catch (e) {
        if (e instanceof FarmerDataApiError && e.status === 401) await refreshSession();
        if (active) setError(e instanceof Error ? e.message : 'This record is unavailable.');
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
  }, [id, retry, refreshSession]);

  const month = record?.harvestDate.slice(0, 7) || '';
  return <AppScreen back>
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}>
      {loading ? <EmptyState title="Loading record…" loading /> : !record ? <EmptyState title="Record unavailable" message={error} retry={() => setRetry((value) => value + 1)} /> : <>
        <View style={ui.sectionHeading}><Text style={[ui.title, { flex: 1 }]}>Production Record</Text><ProductionStatusBadge status={record.status} /></View>
        <View style={ui.card}>
          {!!record.reportCode && <Detail label="Report ID" value={record.reportCode} strong />}
          <Detail label="Farm area" value={record.farmName ? `${record.farmName}${record.farmBarangay ? ' · ' + record.farmBarangay : ''}` : 'Not recorded'} />
          <Detail label="Production category" value={record.categoryLabel} />
          <Detail label="Commodity" value={`${record.commodityName} (${record.commodityGroup})`} />
          <Detail label="Date harvested / produced" value={formatHarvestDate(record.harvestDate)} />
          <Detail label="Quantity produced" value={formatQuantity(record.quantity, record.unitSymbol) + (record.unitCode !== 'kg' && record.quantityKg !== null ? ` (${formatKg(record.quantityKg)})` : '')} strong />
          <Detail label="Unit of measurement" value={record.unitName + (record.quantityKg === null ? ' · not added to kilogram totals' : '')} />
          <Detail label="Remarks" value={record.remarks || 'None'} />
          <Detail label="Submitted" value={formatDate(record.submittedAt, true)} />
          <Detail label="Status" value={record.status === 'Rejected' ? 'Rejected · not counted in your totals' : record.status === 'Verified' ? 'Verified by the office' : 'Submitted · counted in your totals'} />
        </View>
        <Action title={`View ${monthLabel(month)}`} secondary onPress={() => router.push({ pathname: '/production/month/[month]', params: { month } })} />
      </>}
    </ScrollView>
  </AppScreen>;
}

function Detail({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return <View style={{ gap: 2, paddingVertical: 3 }}><Text style={ui.small}>{label}</Text><Text style={strong ? ui.cardTitle : ui.body}>{value}</Text></View>;
}
