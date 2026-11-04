import MapView, { Circle, Marker } from 'react-native-maps';
import { View, StyleSheet } from 'react-native';
import { useEffect, useRef } from 'react';
import { damageMapRegion } from '@/services/photoLocationMetadata';

export default function DamageLocationMap({ latitude, longitude, accuracy, label }: { latitude: number; longitude: number; accuracy?: number | null; label: string }) {
  const map = useRef<MapView>(null);
  const region = damageMapRegion(latitude, longitude);
  useEffect(() => {
    const nextRegion = damageMapRegion(latitude, longitude);
    if (nextRegion) map.current?.animateToRegion(nextRegion, 250);
  }, [latitude, longitude]);
  if (!region) return null;
  return <View style={styles.frame}><MapView ref={map} style={styles.map}
    initialRegion={region}
    rotateEnabled={false} pitchEnabled={false} zoomEnabled scrollEnabled
    accessibilityLabel={`${label}: ${latitude}, ${longitude}`}>
    <Marker coordinate={{ latitude, longitude }} title={label} description="Review this point before confirming the damage location." />
    {typeof accuracy === 'number' && accuracy > 0 && <Circle center={{ latitude, longitude }} radius={accuracy} fillColor="rgba(75,132,47,0.12)" strokeColor="#4B842F" />}
  </MapView></View>;
}
const styles = StyleSheet.create({ frame: { height: 240, borderRadius: 14, overflow: 'hidden', marginVertical: 12 }, map: { flex: 1 } });
