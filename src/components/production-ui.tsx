import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Badge, categoryIcon, Icon, palette, ui } from '@/components/farm-ui';
import type { ProductionCropTotal, ProductionOtherMeasure, ProductionRecord, ProductionRecordStatus } from '@/services/farmerDataApi';
import { formatHarvestDate, formatKg, formatPercent, formatQuantity } from '@/utils/production';

export function ProductionStatusBadge({ status }: { status: ProductionRecordStatus }) {
  return <Badge label={status} tone={status === 'Verified' ? 'green' : status === 'Rejected' ? 'red' : 'blue'} />;
}

/** Each crop's kilograms and its share of the month's total (percentages come from the server). */
export function CropBreakdown({ crops, monthLabel }: { crops: ProductionCropTotal[]; monthLabel: string }) {
  if (!crops.length) return <Text style={ui.body}>No production in kilograms recorded for {monthLabel}.</Text>;
  return <View style={styles.breakdown}>
    {crops.map((crop) => <View key={crop.commodityId} accessible accessibilityLabel={`${crop.name}, ${formatKg(crop.totalKg)}, ${formatPercent(crop.percent)} of ${monthLabel}`} style={styles.cropRow}>
      <View style={ui.row}>
        <Icon name={categoryIcon(crop.category)} size={18} />
        <Text style={styles.cropName} numberOfLines={1}>{crop.name}</Text>
        <Text style={styles.cropKg}>{formatKg(crop.totalKg)}</Text>
        <Text style={styles.cropPercent}>{formatPercent(crop.percent)}</Text>
      </View>
      <View style={styles.track}><View style={[styles.fill, { width: `${Math.max(crop.percent, 2)}%` }]} /></View>
    </View>)}
  </View>;
}

/** Production in head, pieces, trays or liters: listed per commodity and unit, never added to kilograms. */
export function OtherMeasures({ measures }: { measures: ProductionOtherMeasure[] }) {
  if (!measures.length) return null;
  return <View style={styles.other}>
    <Text style={ui.small}>Recorded in other units (not added to the kg total)</Text>
    {measures.map((measure) => <View key={measure.commodityId + ':' + measure.unitCode} accessible accessibilityLabel={`${measure.name}, ${formatQuantity(measure.total, measure.unitSymbol)}`} style={ui.row}>
      <Icon name={categoryIcon(measure.category)} size={18} />
      <Text style={styles.cropName} numberOfLines={1}>{measure.name}</Text>
      <Text style={styles.cropKg}>{formatQuantity(measure.total, measure.unitSymbol)}</Text>
    </View>)}
  </View>;
}

export function ProductionRecordCard({ record }: { record: ProductionRecord }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={`${record.commodityName}, ${formatQuantity(record.quantity, record.unitSymbol)}, harvested ${formatHarvestDate(record.harvestDate)}, ${record.status}`}
    onPress={() => router.push({ pathname: '/production/record/[id]', params: { id: record.id } })} style={({ pressed }) => [ui.card, styles.record, pressed && { opacity: 0.8 }]}>
    <View style={styles.recordIcon}><Icon name={categoryIcon(record.category)} size={24} /></View>
    <View style={styles.recordCopy}>
      <View style={styles.recordHeading}><Text style={[ui.cardTitle, { flexShrink: 1 }]} numberOfLines={1}>{record.commodityName}</Text><ProductionStatusBadge status={record.status} /></View>
      <Text style={[styles.recordKg, !record.counted && styles.notCounted]}>{formatQuantity(record.quantity, record.unitSymbol)}</Text>
      <View style={ui.row}><Icon name="calendar" size={14} color={palette.muted} /><Text style={[ui.small, { flex: 1 }]} numberOfLines={1}>{formatHarvestDate(record.harvestDate)} · {record.farmName || 'Farm not recorded'}</Text></View>
      {!record.counted && <Text style={styles.notCountedText}>Not counted in your totals.</Text>}
    </View>
    <Icon name="chevron" size={18} color={palette.muted} />
  </Pressable>;
}

export const productionStyles = StyleSheet.create({
  titlePanel: { backgroundColor: palette.forest, borderRadius: 16, padding: 14, gap: 5 },
  title: { color: palette.paper, fontSize: 24, fontWeight: '800' },
  subtitle: { color: '#DFEADB', fontSize: 13, lineHeight: 19 },
  link: { color: palette.forest, fontSize: 13, fontWeight: '700' },
  linkButton: { minHeight: 44, justifyContent: 'center' },
  error: { color: palette.red, fontSize: 13, lineHeight: 19 },
});

const styles = StyleSheet.create({
  breakdown: { gap: 12 },
  other: { gap: 8, borderTopWidth: 1, borderColor: palette.border, paddingTop: 10 },
  cropRow: { gap: 6 },
  cropName: { flex: 1, minWidth: 0, color: palette.ink, fontSize: 14, fontWeight: '600' },
  cropKg: { color: palette.ink, fontSize: 14, fontWeight: '700' },
  cropPercent: { width: 52, textAlign: 'right', color: palette.muted, fontSize: 13, fontWeight: '600' },
  track: { height: 8, borderRadius: 4, backgroundColor: palette.sage, overflow: 'hidden' },
  fill: { height: 8, borderRadius: 4, backgroundColor: palette.forest },
  record: { flexDirection: 'row', alignItems: 'center', gap: 11 },
  recordIcon: { backgroundColor: palette.sage, width: 48, height: 56, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  recordCopy: { flex: 1, minWidth: 0, gap: 3 },
  recordHeading: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 5 },
  recordKg: { color: palette.deep, fontSize: 18, fontWeight: '800' },
  notCounted: { color: palette.muted, textDecorationLine: 'line-through' },
  notCountedText: { color: palette.red, fontSize: 12 },
});
