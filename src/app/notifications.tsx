import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, SectionList, Text, View } from 'react-native';
import { Action, AppScreen, Badge, EmptyState, formatDate, Icon, palette, ui } from '@/components/farm-ui';
import { FarmerDataApiError, listFarmerNotifications, markFarmerNotificationRead, type FarmerNotification } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

export default function NotificationsScreen() {
  const { farmer, refreshSession } = useFarmerAuth();
  const [items, setItems] = useState<FarmerNotification[]>([]);
  const [expanded, setExpanded] = useState<string[]>([]);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const pendingReads = useRef(new Set<string>());
  const revision = useRef(0);
  const load = useCallback(async (refresh = false) => {
    const request = ++revision.current;
    refresh ? setRefreshing(true) : setLoading(true); setError('');
    try { const data = await listFarmerNotifications(); if (request === revision.current) setItems(data); }
    catch (e) {
      if (request === revision.current) setError(e instanceof Error ? e.message : 'Notifications unavailable.');
      if (e instanceof FarmerDataApiError && e.status === 401) await refreshSession();
    } finally { if (request === revision.current) { setLoading(false); setRefreshing(false); } }
  }, [farmer?.id, refreshSession]);
  useFocusEffect(useCallback(() => { void load(); return () => { revision.current++; }; }, [load]));
  const unread = items.filter(item => !item.isRead).length;
  const groups = new Map<string, FarmerNotification[]>();
  for (const item of items.filter(item => !unreadOnly || !item.isRead)) {
    const day = formatDate(item.createdAt);
    groups.set(day, [...(groups.get(day) || []), item]);
  }
  async function open(item: FarmerNotification) {
    setExpanded(current => current.includes(item.id) ? current.filter(id => id !== item.id) : [...current, item.id]);
    if (!item.isRead && !pendingReads.current.has(item.id)) {
      pendingReads.current.add(item.id);
      try { await markFarmerNotificationRead(item.id); setItems(current => current.map(n => n.id === item.id ? { ...n, isRead: true } : n)); }
      catch (e) { setError(e instanceof Error ? e.message : 'Read state could not be saved.'); }
      finally { pendingReads.current.delete(item.id); }
    }
    if (item.relatedReportId) router.push({ pathname: '/reports/[id]', params: { id: item.relatedReportId } });
    else if (item.relatedAdvisoryId) router.push({ pathname: '/weather/[id]', params: { id: item.relatedAdvisoryId } });
  }
  return <AppScreen tab="alerts" unread={unread}>
    <SectionList sections={Array.from(groups, ([title, data]) => ({ title, data }))} keyExtractor={item => item.id}
      contentContainerStyle={ui.content} stickySectionHeadersEnabled={false} refreshing={refreshing} onRefresh={() => void load(true)}
      ListHeaderComponent={<View style={{ gap: 8 }}>
        <View style={[ui.sectionHeading, { flexWrap: 'wrap' }]}><Text style={ui.title}>Notifications</Text><Badge label={loading && !items.length ? 'Checking unread' : unread + ' unread'} /></View>
        <Text style={ui.body}>Updates on your reports, advisories, and account.</Text>
        <View style={ui.row}>{[false, true].map(value => <Pressable key={String(value)} accessibilityRole="button" onPress={() => setUnreadOnly(value)} style={[ui.chip, unreadOnly === value && ui.chipActive]}><Text style={[ui.chipText, unreadOnly === value && ui.chipTextActive]}>{value ? 'Unread (' + unread + ')' : 'All'}</Text></Pressable>)}</View>
        {loading ? <ActivityIndicator color={palette.forest} /> : null}
        {error ? <EmptyState title="Notification update failed" message={error}><Action title="Retry" secondary onPress={() => void load()} /></EmptyState> : null}
      </View>}
      renderSectionHeader={({ section }) => <Text style={[ui.cardTitle, { marginTop: 12 }]}>{section.title}</Text>}
      renderItem={({ item }) => <Pressable accessibilityRole="button" accessibilityLabel={item.title + (item.isRead ? '' : ', unread')} onPress={() => void open(item)} style={[ui.card, { flexDirection: 'row' }]}>
        <View style={{ backgroundColor: item.type === 'error' ? palette.redSoft : palette.sage, borderRadius: 20, padding: 8, alignSelf: 'flex-start' }}><Icon name={item.relatedAdvisoryId ? 'weather' : item.type === 'success' ? 'check' : item.relatedReportId ? 'reports' : 'message'} /></View>
        <View style={{ flex: 1, gap: 3 }}>
          <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>{item.title}</Text>{!item.isRead ? <View accessibilityLabel="Unread" style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: palette.forest }} /> : null}</View>
          {/* An advisory's short message is its Key advice (or whole short message), so it is shown in full; the detail screen has the complete advisory. */}
          {item.relatedAdvisoryId ? <Text style={ui.body}>{item.shortMessage}</Text> : <Text style={ui.body} numberOfLines={expanded.includes(item.id) ? undefined : 2}>{expanded.includes(item.id) ? item.fullMessage : item.shortMessage}</Text>}
          {!!item.context && <Text style={ui.small}>{item.context}</Text>}
          <Text style={ui.small}>{formatDate(item.createdAt, true)}</Text>
          {item.relatedAdvisoryId && <Text style={{ color: palette.forest, fontSize: 14, fontWeight: '700' }}>View full advice</Text>}
          {!item.relatedReportId && !item.relatedAdvisoryId && <Text style={ui.small}>{expanded.includes(item.id) ? 'Show less' : 'Tap to read'}</Text>}
        </View><Icon name="chevron" size={18} />
      </Pressable>}
      ListEmptyComponent={!loading && !error ? <EmptyState icon="alerts" title={unreadOnly ? 'All caught up' : 'No notifications yet'} message="Your real account updates will appear here." /> : undefined} />
  </AppScreen>;
}
