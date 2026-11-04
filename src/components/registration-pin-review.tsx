// Shown under the map on every platform: the exact selected coordinates and exact-point links, so the farmer can
// review the pin even if map imagery is slow. It never confirms anything; confirming is the footer button.
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import { formatCoordinates, googleMapsLink, openStreetMapLink } from '@/services/mapTiles';
import type { PinPoint } from './registration-pin-map.types';

export function PinReview({ pin, pinLabel, confirmed }: { pin: PinPoint | null; pinLabel: string; confirmed: boolean }) {
  if (!pin) {
    return <View style={styles.box}><Text style={styles.status}>No {pinLabel} point selected yet. Tap the map where the {pinLabel} is.</Text></View>;
  }
  const open = (primary: string, fallback: string) => { void Linking.openURL(primary).catch(() => Linking.openURL(fallback).catch(() => undefined)); };
  return (
    <View style={styles.box}>
      <Text style={styles.label}>Selected {pinLabel} point{confirmed ? ' (confirmed)' : ' (not confirmed yet)'}</Text>
      <Text selectable style={styles.coords}>{formatCoordinates(pin)}</Text>
      <View style={styles.links}>
        <Pressable accessibilityRole="link" accessibilityLabel={`Open the exact ${pinLabel} point in Google Maps`} onPress={() => open(googleMapsLink(pin), openStreetMapLink(pin))} style={styles.link}><Text style={styles.linkText}>Open in Google Maps</Text></Pressable>
        <Pressable accessibilityRole="link" accessibilityLabel={`Open the exact ${pinLabel} point in OpenStreetMap`} onPress={() => open(openStreetMapLink(pin), googleMapsLink(pin))} style={styles.link}><Text style={styles.linkText}>Open in OpenStreetMap</Text></Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { backgroundColor: '#F1F7EC', borderRadius: 10, padding: 10, marginTop: 9, gap: 4 },
  status: { color: '#3B4A33', fontSize: 13, lineHeight: 18 },
  label: { color: '#174D36', fontSize: 11, fontWeight: '800' },
  coords: { color: '#172B24', fontSize: 15, fontWeight: '700' },
  links: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  link: { minHeight: 40, paddingHorizontal: 12, borderRadius: 10, borderWidth: 1, borderColor: '#174D36', backgroundColor: '#FFFFFF', alignItems: 'center', justifyContent: 'center' },
  linkText: { color: '#174D36', fontSize: 13, fontWeight: '700' },
});
