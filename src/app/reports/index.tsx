import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { AppScreen, Icon, palette, ui, type IconName } from '@/components/farm-ui';
import { productionStyles } from '@/components/production-ui';
import { FarmerDataApiError, getProductionOverview, listDamageReports } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { formatKg } from '@/utils/production';

/** The Reports tab: choose between production (harvest) reporting and the existing damage reports. */
export default function ReportsScreen() {
  const { refreshSession } = useFarmerAuth();
  const [productionLine, setProductionLine] = useState('');
  const [damageLine, setDamageLine] = useState('');
  const [refreshing, setRefreshing] = useState(false);
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    if (refresh) setRefreshing(true);
    // Short live figures only; each option card still works when they are unavailable.
    const [production, damage] = await Promise.allSettled([getProductionOverview(), listDamageReports()]);
    if (request !== revision.current) return;
    setProductionLine(production.status === 'fulfilled' ? `${production.value.currentMonth.label}: ${formatKg(production.value.currentMonth.totalKg)}` : '');
    if (damage.status === 'fulfilled') {
      const open = damage.value.filter((report) => ['Pending', 'Received', 'Under Review'].includes(report.status)).length;
      setDamageLine(open ? `${open} awaiting review` : `${damage.value.length} submitted`);
    } else setDamageLine('');
    if ([production, damage].some((result) => result.status === 'rejected' && result.reason instanceof FarmerDataApiError && result.reason.status === 401)) void refreshSession();
    setRefreshing(false);
  }, [refreshSession]);
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current += 1; }; }, [load]));

  return <AppScreen tab="reports">
    <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(true)} tintColor={palette.forest} />}>
      <View style={productionStyles.titlePanel}>
        <Text style={productionStyles.title}>Reports</Text>
        <Text style={productionStyles.subtitle}>Record your harvests and report damage to your farms.</Text>
      </View>
      <View style={styles.options}>
        <ReportOption title="Production Report" copy="Submit your harvests and view monthly production totals and history." icon="chart" tone="production" detail={productionLine} onPress={() => router.push('/production')} />
        <ReportOption title="Damage Report" copy="Report farm damage and track the review of your submitted reports." icon="damage" tone="damage" detail={damageLine} onPress={() => router.push('/my-reports')} />
      </View>
    </ScrollView>
  </AppScreen>;
}

function ReportOption({ title, copy, icon, tone, detail, onPress }: { title: string; copy: string; icon: IconName; tone: 'production' | 'damage'; detail: string; onPress: () => void }) {
  const color = tone === 'production' ? palette.forest : palette.red;
  return <Pressable accessibilityRole="button" accessibilityLabel={`${title}. ${copy}${detail ? ' ' + detail : ''}`} onPress={onPress}
    style={({ pressed }) => [ui.card, styles.option, { backgroundColor: tone === 'production' ? palette.sage : palette.redSoft }, pressed && { opacity: 0.8 }]}>
    <View style={styles.optionIcon}><Icon name={icon} size={28} color={color} /></View>
    <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>{title}</Text><Icon name="chevron" size={18} color={color} /></View>
    <Text style={ui.small}>{copy}</Text>
    {!!detail && <Text style={[styles.detail, { color }]}>{detail}</Text>}
  </Pressable>;
}

const styles = StyleSheet.create({
  options: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  option: { flex: 1, minWidth: 140, padding: 14, gap: 8, borderColor: 'transparent' },
  optionIcon: { width: 48, height: 48, borderRadius: 24, backgroundColor: palette.paper, alignItems: 'center', justifyContent: 'center' },
  detail: { fontSize: 13, fontWeight: '700' },
});
