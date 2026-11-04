import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { AppScreen, categoryIcon, Icon, ui } from '@/components/farm-ui';
import { FarmerDataApiError, getWeatherAdvisory, type WeatherAdvisory } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

const GREEN = '#174D36';
const OLIVE = '#667447';
const categoryIcons: Record<string, string> = { Crops: '♧', Livestock: '◉', Poultry: '○', Fishpond: '≈' };

export default function WeatherAdvisoryDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { width } = useWindowDimensions();
  const { refreshSession } = useFarmerAuth();
  const [advisory, setAdvisory] = useState<WeatherAdvisory | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const contentWidth = Math.min(width * 0.92, 620);

  useEffect(() => {
    let active = true;
    setAdvisory(null); setLoading(true); setLoadError('');
    getWeatherAdvisory(String(id ?? '')).then((item) => { if (active) setAdvisory(item); }).catch(async (error) => {
      if (error instanceof FarmerDataApiError && error.status === 401) await refreshSession();
      if (active) setLoadError(error instanceof Error ? error.message : 'This advisory is temporarily unavailable.');
    }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, refreshSession]);

  return (
    <AppScreen back>
      <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}>
        <Text onPress={() => router.replace('/weather-advice')} style={styles.backLink}>← Back to Weather Advice</Text>
        {loading ? <View style={styles.notFound}><ActivityIndicator color={GREEN} /><Text style={styles.notFoundText}>Loading advisory...</Text></View> : advisory ? (
          <>
            <View style={styles.summaryCard}>
              <Text style={styles.summaryKind}>Admin advisory</Text>
              <Icon name={categoryIcon(advisory.category)} color="white" size={30} />
              <Text style={styles.summaryTitle}>{advisory.title}</Text>
              <Text style={styles.summaryCategory}>{advisory.category}</Text>
            </View>

            {advisory.keyAdvice ? <View style={styles.keyAdviceCard}>
              <Text style={styles.keyAdviceLabel}>Key advice</Text>
              <Text style={styles.keyAdviceText}>{advisory.keyAdvice}</Text>
            </View> : null}

            <View style={styles.informationCard}>
              <InfoRow label="Admin-selected condition" value={advisory.weatherCondition} />
              <InfoRow label="Covered location" value={advisory.locationCoverage} />
              <InfoRow label="Effective period" value={`${formatDate(advisory.effectiveDate)}${advisory.expirationDate ? ` to ${formatDate(advisory.expirationDate)}` : ' until further notice'}`} />
              <InfoRow label="Published" value={formatTimestamp(advisory.publishedAt)} last />
            </View>

            <View style={styles.noticeCard}>
              <Text style={styles.noticeTitle}>Admin advisory</Text>
              <Text style={styles.noticeText}>This guidance was written and published by an AgriReach administrator. The selected condition is the administrator's choice, not a live weather measurement or an external agency warning. Automatic, data-based guidance is shown separately as Weather-based advice.</Text>
            </View>

            <View style={styles.sectionTitleRow}><Text style={styles.sectionIcon}>✓</Text><Text style={styles.sectionTitle}>Full advice</Text></View>
            <Text style={styles.sectionNote}>The administrator's complete message, as published.</Text>
            <View style={styles.recommendationsCard}>
              {advisory.recommendations.map((recommendation, index) => (
                <View key={`${index}-${recommendation}`} style={[styles.recommendationRow, index === advisory.recommendations.length - 1 && styles.lastRow]}>
                  <View style={styles.numberCircle}><Text style={styles.numberText}>{index + 1}</Text></View>
                  <Text style={styles.recommendationText}>{recommendation}</Text>
                </View>
              ))}
            </View>
          </>
        ) : <View style={styles.notFound}><Text style={styles.notFoundTitle}>Advisory not available</Text><Text style={styles.notFoundText}>{loadError || 'This advisory may no longer apply to your registered farm.'}</Text></View>}
      </ScrollView>
    </AppScreen>
  );
}

function InfoRow({ label, value, last = false }: { label: string; value: string; last?: boolean }) {
  return <View style={[styles.infoRow, last && styles.lastRow]}><Text style={styles.infoLabel}>{label}</Text><Text style={styles.infoValue}>{value}</Text></View>;
}

function formatDate(value: string) {
  const date = new Date(`${value}T00:00:00+08:00`);
  return Number.isFinite(date.getTime()) ? date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : value;
}

function formatTimestamp(value: string | null) {
  if (!value) return 'Not available';
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? date.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Not available';
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#FCFDF9' },
  header: { height: 58, backgroundColor: GREEN, justifyContent: 'center', paddingHorizontal: 20 },
  brand: { color: '#FFFFFF', fontSize: 21, fontWeight: '800' },
  content: { flexGrow: 1, alignSelf: 'center', paddingHorizontal: 4, paddingTop: 15, paddingBottom: 32 },
  backLink: { color: OLIVE, fontSize: 14, fontWeight: '700', paddingVertical: 9, alignSelf: 'flex-start' },
  summaryCard: { minHeight: 180, backgroundColor: GREEN, borderRadius: 18, padding: 21, justifyContent: 'center', marginTop: 11 },
  summaryKind: { color: '#DDEED5', fontSize: 11, fontWeight: '800', textTransform: 'uppercase', marginBottom: 8 },
  summaryIcon: { color: '#FFFFFF', fontSize: 29, marginBottom: 8 },
  summaryTitle: { color: '#FFFFFF', fontSize: 22, fontWeight: '800' },
  summaryCategory: { color: '#EAF4E4', fontSize: 14, fontWeight: '600', marginTop: 5 },
  informationCard: { backgroundColor: '#FFFDF8', borderWidth: 1, borderColor: '#DCE5D6', borderRadius: 15, marginTop: 16, paddingHorizontal: 15 },
  infoRow: { paddingVertical: 13, borderBottomWidth: 1, borderBottomColor: '#EEF1EB' },
  infoLabel: { color: OLIVE, fontSize: 12, fontWeight: '700' },
  infoValue: { color: '#27301F', fontSize: 14, lineHeight: 20, marginTop: 4 },
  noticeCard: { backgroundColor: '#FFF8E8', borderWidth: 1, borderColor: '#E9D7AC', borderRadius: 14, padding: 14, marginTop: 15 },
  noticeTitle: { color: '#7B5B16', fontSize: 13, fontWeight: '800' },
  noticeText: { color: '#6F654E', fontSize: 13, lineHeight: 19, marginTop: 5 },
  sectionTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 24, marginBottom: 11 },
  sectionIcon: { color: GREEN, fontSize: 18, fontWeight: '800' },
  sectionTitle: { color: '#27301F', fontSize: 18, fontWeight: '800' },
  sectionNote: { color: OLIVE, fontSize: 12, marginTop: -6, marginBottom: 9 },
  keyAdviceCard: { backgroundColor: '#E7F3E0', borderWidth: 1, borderColor: '#CFE3C4', borderRadius: 15, padding: 15, marginTop: 16, gap: 5 },
  keyAdviceLabel: { color: GREEN, fontSize: 11, fontWeight: '800', textTransform: 'uppercase' },
  keyAdviceText: { color: '#27301F', fontSize: 16, lineHeight: 23, fontWeight: '700' },
  recommendationsCard: { backgroundColor: '#FFFDF8', borderWidth: 1, borderColor: '#DCE5D6', borderRadius: 15, paddingHorizontal: 14, paddingVertical: 4 },
  recommendationRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 11, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#EEF1EB' },
  lastRow: { borderBottomWidth: 0 },
  numberCircle: { width: 25, height: 25, borderRadius: 13, backgroundColor: '#E7F3E0', alignItems: 'center', justifyContent: 'center' },
  numberText: { color: GREEN, fontSize: 12, fontWeight: '800' },
  recommendationText: { flex: 1, color: '#48523F', fontSize: 14, lineHeight: 20, paddingTop: 2 },
  notFound: { alignItems: 'center', paddingVertical: 60 },
  notFoundTitle: { color: '#27301F', fontSize: 20, fontWeight: '800' },
  notFoundText: { color: OLIVE, fontSize: 14, textAlign: 'center', marginTop: 8 },
});
