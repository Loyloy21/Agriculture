import { useEffect, useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import MapView, { Marker, PROVIDER_GOOGLE } from 'react-native-maps';
import { hasNativeGoogleMapsKey } from '@/services/androidNativeMapsKey';
import { parseAndroidDamagePoint } from '@/services/androidDamagePoint';
import { validPoint } from '@/services/photoLocationMetadata';

type Point = { latitude: number; longitude: number };
const START = { latitude: 9.1236, longitude: 125.5354, latitudeDelta: 0.18, longitudeDelta: 0.18 };

export default function DamageManualPinMap({ point, onChange }: { point: Point | null; onChange: (point: Point | null) => void }) {
  const nativeKeyReady = hasNativeGoogleMapsKey();
  const [text, setText] = useState(point ? `${point.latitude}, ${point.longitude}` : '');
  const [showMap, setShowMap] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => { if (point) setText(`${point.latitude}, ${point.longitude}`); }, [point?.latitude, point?.longitude]);
  const candidate = parseAndroidDamagePoint(text);
  const choose = (next: Point) => {
    if (!validPoint(next.latitude, next.longitude)) return;
    setText(`${next.latitude}, ${next.longitude}`);
    setError('');
    onChange(next);
  };
  const open = () => {
    const query = point ? `${point.latitude},${point.longitude}` : 'Cabadbaran City';
    void Linking.openURL(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`)
      .catch(() => setError('Google Maps could not be opened. Enter the actual coordinates below.'));
  };
  return <ScrollView style={styles.container} keyboardShouldPersistTaps="handled">
    <Text style={styles.help}>Farmer-selected damage location — not photo GPS. In Google Maps, press and hold the actual damage site and copy its coordinates. Paste latitude, longitude below. Returning from Google Maps does not automatically import a location.</Text>
    <Pressable accessibilityRole="link" onPress={open} style={styles.primary}><Text style={styles.white}>{point ? 'Open exact point in Google Maps' : 'Open Google Maps to find the damage site'}</Text></Pressable>
    {!point && <Text style={styles.help}>Cabadbaran is only a starting search, not your selected damage point.</Text>}
    <Text style={styles.label}>Latitude, longitude</Text>
    <TextInput accessibilityLabel="Farmer-selected latitude, longitude" placeholder="Paste latitude, longitude" value={text} autoCorrect={false}
      onChangeText={(value) => { setText(value); setError(''); onChange(null); }} style={styles.input} />
    <Pressable accessibilityRole="button" disabled={!candidate} onPress={() => candidate && choose(candidate)} style={[styles.primary, !candidate && styles.disabled]}>
      <Text style={styles.white}>Apply these farmer-selected coordinates</Text>
    </Pressable>
    {!!text && !candidate && <Text style={styles.error}>Enter two valid decimal coordinates separated by a comma. Blank, invalid and 0,0 points are not accepted.</Text>}
    {!!error && <Text style={styles.error}>{error}</Text>}
    <Text style={styles.help}>Use this farmer-selected point below, then explicitly confirm it in the report. Cabadbaran validation is required; map tiles are not.</Text>
    {nativeKeyReady ? <Pressable accessibilityRole="button" onPress={() => setShowMap((shown) => !shown)} style={styles.secondary}><Text>{showMap ? 'Hide' : 'Show'} optional embedded map</Text></Pressable>
      : <Text style={styles.help}>The in-app map needs a new Android APK with a Maps SDK key. You can still set and confirm coordinates here.</Text>}
    {nativeKeyReady && showMap && <View style={styles.mapFrame}><MapView provider={PROVIDER_GOOGLE} mapType="standard" style={styles.map}
      initialRegion={point ? { ...point, latitudeDelta: 0.012, longitudeDelta: 0.012 } : START}
      onPress={(event) => choose(event.nativeEvent.coordinate)} rotateEnabled={false} pitchEnabled={false}>
      {point && validPoint(point.latitude, point.longitude) && <Marker coordinate={point} draggable title="Farmer-selected point (not photo GPS)" onDragEnd={(event) => choose(event.nativeEvent.coordinate)} />}
    </MapView></View>}
  </ScrollView>;
}

const styles = StyleSheet.create({
  container: { flex: 1 }, help: { color: '#46553D', lineHeight: 20, marginVertical: 8 },
  label: { fontWeight: '700', marginTop: 12 }, input: { borderWidth: 1, borderColor: '#9BB782', padding: 12, borderRadius: 8, marginVertical: 8 },
  primary: { backgroundColor: '#356B27', padding: 14, borderRadius: 8, marginVertical: 6 },
  white: { color: '#FFFFFF', fontWeight: '700', textAlign: 'center' }, disabled: { opacity: 0.45 },
  secondary: { padding: 12, borderWidth: 1, borderColor: '#9BB782', borderRadius: 8, marginVertical: 8 },
  error: { color: '#A52A2A', marginVertical: 8 }, mapFrame: { height: 240 }, map: { flex: 1 },
});
