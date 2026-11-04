import { useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Modal, Pressable, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Action, AppScreen, EmptyState, formatDate, Icon, SectionHeading, StatusBadge, ui } from '@/components/farm-ui';
import ProtectedReportPhoto from '@/components/protected-report-photo';
import DamageLocationMap from '@/components/damage-location-map';
import { FarmerDataApiError, getDamageReport, type DamageReport } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { photoLocationLabel, photoLocationStatusLabel, validPoint } from '@/services/photoLocationMetadata';

export default function DamageReportDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { refreshSession } = useFarmerAuth();
  const { height } = useWindowDimensions();
  const [report, setReport] = useState<DamageReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [viewerId, setViewerId] = useState<string | null>(null);
  useEffect(() => {
    let active = true; setReport(null); setViewerId(null); setLoading(true); setError('');
    void (async () => {
      try { const data = await getDamageReport(String(id || '')); if (active) setReport(data); }
      catch (e) {
        if (active) setError(e instanceof Error ? e.message : 'Report unavailable.');
        if (e instanceof FarmerDataApiError && e.status === 401) await refreshSession();
      } finally { if (active) setLoading(false); }
    })();
    return () => { active = false; };
  }, [id, retry, refreshSession]);
  const viewerIndex = report?.photos.findIndex(photo => photo.id === viewerId) ?? -1;
  const photo = viewerIndex >= 0 ? report!.photos[viewerIndex] : null;
  return <AppScreen back>
    <ScrollView contentContainerStyle={ui.content}>
      {loading ? <EmptyState loading title="Loading report…" /> : !report ? <EmptyState title="Report unavailable" message={error} retry={() => setRetry(value => value + 1)} /> : <>
        <View style={ui.sectionHeading}><Text style={[ui.title, { flex: 1 }]}>Report Details</Text><StatusBadge status={report.status} /></View>
        <Text style={ui.small}>{report.id} · {formatDate(report.timestamp || report.date, true)}</Text>
        <View style={ui.card}>
          <Detail label="Farm" value={report.farmName || 'Not recorded for this report'} />
          <Detail label="Category" value={report.farmType} />
          {report.damageDetails ? <>
            <Detail label={report.damageDetails.subjectLabel} value={report.damageDetails.subjectType} />
            <Detail label="Suspected cause (your report)" value={report.damageDetails.suspectedCauseLabel} />
            <Detail label="Your reported loss" value={report.damageDetails.summary} />
            {report.damageDetails.measureType === 'crop_percent' ? <Text style={ui.small}>The percentage is your estimate for this crop or plot, not a measurement of the whole farm.</Text> : null}
            <Detail label="What happened?" value={report.description || 'Not provided'} />
          </> : <>
            <Detail label="Calamity" value={report.calamityType} />
            {report.estimatedCost !== null ? <Detail label="Estimated damage cost" value={'₱' + report.estimatedCost.toLocaleString('en-PH', { minimumFractionDigits: 2 })} /> : null}
            <Detail label="Description" value={report.description || 'Not provided'} />
          </>}
          <Detail label="Damage address / landmark" value={report.location || 'Not recorded'} />
          <Detail label="Confirmed damage point source" value={report.locationSource === 'photo' ? 'Farmer-confirmed photo ' + report.locationPhotoOrder + ' location' : report.locationSource === 'device' ? 'Farmer-confirmed device GPS' : report.locationSource === 'manual' ? 'Farmer-confirmed manual pin' : 'Not recorded for this older report'} />
          {validPoint(report.latitude, report.longitude) ? <DamageLocationMap latitude={report.latitude!} longitude={report.longitude!} label="Confirmed report point" /> : null}
        </View>
        {report.damageDetails?.verified ? <>
          <SectionHeading title="Verified by the Department of Agriculture" icon="check" />
          <View style={ui.card}>
            <Detail label="Verified cause" value={report.damageDetails.verified.causeLabel} />
            <Detail label="Verified loss" value={report.damageDetails.verified.summary || 'Not recorded'} />
            {report.damageDetails.verified.verifiedAt ? <Text style={ui.small}>Verified {formatDate(report.damageDetails.verified.verifiedAt, true)}</Text> : null}
          </View>
        </> : null}
        <SectionHeading title="Admin Response" icon="message" />
        <View style={ui.card}><Text style={ui.body}>{report.adminResponse || 'No admin response yet.'}</Text>{report.statusUpdatedAt ? <Text style={ui.small}>Updated {formatDate(report.statusUpdatedAt, true)}</Text> : null}</View>
        <SectionHeading title={'Damage Photos (' + report.photos.length + ')'} icon="photo" />
        {report.photos.length ? report.photos.map((item, index) => <View key={item.id} style={ui.card}>
          <ProtectedReportPhoto uri={item.uri} onPress={() => setViewerId(item.id)} />
          <Text style={ui.cardTitle}>Photo {index + 1}</Text>
          <Text style={ui.body}>{photoLocationLabel(item.locationSource, validPoint(item.latitude, item.longitude))}</Text>
          <Text style={ui.small}>{item.capturedAt ? (item.captureTimeSource === 'camera_return' ? 'Camera returned: ' : 'Photo timestamp: ') + formatDate(item.capturedAt, true) : 'Capture time not recorded'}</Text>
          <Text style={ui.small}>{photoLocationStatusLabel(item.locationStatus)}</Text>
          <Action title="View photo & its location" icon="photo" secondary onPress={() => setViewerId(item.id)} />
        </View>) : <EmptyState title="No damage photos" message="No images are recorded for this report." />}
      </>}
    </ScrollView>
    <Modal visible={photo !== null} animationType="fade" onRequestClose={() => setViewerId(null)}>
      <SafeAreaView style={ui.screen}><ScrollView contentContainerStyle={ui.content}>
        <View style={ui.sectionHeading}><Text style={[ui.cardTitle, { flex: 1 }]}>Photo {viewerIndex + 1} of {report?.photos.length}</Text><Pressable accessibilityRole="button" accessibilityLabel="Close photo viewer" onPress={() => setViewerId(null)} style={{ padding: 12 }}><Icon name="close" /></Pressable></View>
        {photo ? <>
          <ProtectedReportPhoto key={photo.id} uri={photo.uri} height={Math.max(220, Math.min(height * 0.52, 520))} contain />
          <Text style={ui.body}>{photoLocationLabel(photo.locationSource, validPoint(photo.latitude, photo.longitude))}</Text>
          {validPoint(photo.latitude, photo.longitude) ? <DamageLocationMap key={photo.id} latitude={photo.latitude!} longitude={photo.longitude!} accuracy={photo.accuracy} label={'Saved photo ' + (viewerIndex + 1) + ' location'} /> : <Text style={ui.body}>No accessible photo GPS recorded for this image.</Text>}
          <Text style={ui.small}>{photo.capturedAt ? (photo.captureTimeSource === 'camera_return' ? 'Camera returned: ' : 'Photo timestamp: ') + formatDate(photo.capturedAt, true) : 'Capture time not recorded'}</Text>
          {photo.locationTimestamp ? <Text style={ui.small}>GPS recorded {formatDate(photo.locationTimestamp, true)}</Text> : null}
          <View style={{ gap: 8 }}><Action title="Previous photo" secondary disabled={viewerIndex <= 0} onPress={() => setViewerId(report!.photos[viewerIndex - 1].id)} /><Action title="Next photo" secondary disabled={viewerIndex >= (report?.photos.length || 0) - 1} onPress={() => setViewerId(report!.photos[viewerIndex + 1].id)} /></View>
        </> : null}
      </ScrollView></SafeAreaView>
    </Modal>
  </AppScreen>;
}
function Detail({ label, value }: { label: string; value: string }) { return <View style={{ gap: 3, paddingVertical: 6 }}><Text style={ui.small}>{label}</Text><Text style={ui.body}>{value}</Text></View>; }
