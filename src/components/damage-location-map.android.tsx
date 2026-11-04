// Android only. iOS continues to use the untouched .native.tsx component.
import { useEffect, useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import MapView, { Circle, Marker, PROVIDER_GOOGLE } from 'react-native-maps';
import { hasNativeGoogleMapsKey, tryOpenInstalledGoogleMaps } from '@/services/androidNativeMapsKey';
import { damageMapRegion } from '@/services/photoLocationMetadata';

type Props = { latitude: number; longitude: number; accuracy?: number | null; label: string };

function AndroidMap({ latitude, longitude, accuracy, label }: Props) {
  const nativeKeyReady = hasNativeGoogleMapsKey();
  const [loaded, setLoaded] = useState(false);
  const [timedOut, setTimedOut] = useState(false);
  const [externalFailed, setExternalFailed] = useState(false);
  const region = damageMapRegion(latitude, longitude);
  const point = `${latitude},${longitude}`;
  useEffect(() => {
    setLoaded(false);
    setTimedOut(false);
    setExternalFailed(false);
    if (!nativeKeyReady || !region) return;
    const timer = setTimeout(() => setTimedOut(true), 15000);
    return () => clearTimeout(timer);
  }, [latitude, longitude, nativeKeyReady, !!region]);
  if (!region) return null;
  const openExactPoint = () => {
    setExternalFailed(false);
    if (tryOpenInstalledGoogleMaps(latitude, longitude)) return;
    // Older APKs lack the explicit package launcher. Android's geo: intent is
    // then the best available handoff, with a web fallback if it is unavailable.
    void Linking.openURL(`geo:${point}?q=${encodeURIComponent(point)}`)
      .catch(() => Linking.openURL(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(point)}`))
      .catch(() => setExternalFailed(true));
  };
  return <View style={styles.section}>
    <Text style={styles.source}>{label}</Text>
    <Text style={styles.coordinates}>{latitude.toFixed(7)}, {longitude.toFixed(7)}
      {typeof accuracy === 'number' && Number.isFinite(accuracy) && accuracy > 0 ? `  ·  ±${Math.round(accuracy)} m` : ''}
    </Text>
    {nativeKeyReady ? <View style={styles.frame}>
      <MapView key={point} style={styles.map}
        provider={PROVIDER_GOOGLE} mapType="satellite" initialRegion={region}
        rotateEnabled={false} pitchEnabled={false}
        accessibilityLabel={`${label}: ${latitude.toFixed(7)}, ${longitude.toFixed(7)}`}
        onMapLoaded={() => { setLoaded(true); setTimedOut(false); }}
        onPress={openExactPoint}>
        <Marker coordinate={{ latitude, longitude }} title={label} onPress={openExactPoint} />
        {typeof accuracy === 'number' && Number.isFinite(accuracy) && accuracy > 0 && <Circle
          center={{ latitude, longitude }} radius={accuracy} fillColor="rgba(75,132,47,0.12)" strokeColor="#4B842F" />}
      </MapView>
    </View> : <Text style={styles.help}>Satellite preview needs a new Android APK with a Maps SDK key. Your photo and exact point remain available below.</Text>}
    {nativeKeyReady && <Text style={styles.help}>Tap the satellite preview to open this exact point in Google Maps.</Text>}
    {nativeKeyReady && timedOut && !loaded && <Text style={styles.warning}>Map tiles have not loaded. Use Google Maps below; this does not change your selected photo or point.</Text>}
    <Pressable accessibilityRole="link" accessibilityLabel="Open exact photo point in Google Maps" style={styles.primaryButton} onPress={openExactPoint}>
      <Text style={styles.primaryText}>Open exact point in Google Maps</Text>
    </Pressable>
    {externalFailed && <Text style={styles.warning}>Google Maps could not be opened. The selected photo and coordinates are still here.</Text>}
  </View>;
}

export default function DamageLocationMap(props: Props) {
  if (!damageMapRegion(props.latitude, props.longitude)) return null;
  return <AndroidMap {...props} />;
}

const styles = StyleSheet.create({
  section: { marginVertical: 12, gap: 8 },
  source: { color: '#264B1F', fontWeight: '700', fontSize: 14 },
  coordinates: { color: '#354331', fontSize: 13, fontVariant: ['tabular-nums'] },
  primaryButton: { backgroundColor: '#356B27', padding: 14, borderRadius: 10, alignItems: 'center' },
  primaryText: { color: '#FFFFFF', fontSize: 15, fontWeight: '700' },
  help: { color: '#46553D', lineHeight: 19 },
  // Explicit Android surface size; avoid clipping a native map surface in a rounded parent.
  frame: { height: 240, width: '100%', backgroundColor: '#E8EEE5', overflow: 'hidden', borderRadius: 10 },
  map: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 },
  warning: { color: '#665024', marginVertical: 8 },
});
