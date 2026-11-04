import MapView, { Marker } from 'react-native-maps';
import { StyleSheet, View } from 'react-native';
import { validPoint } from '@/services/photoLocationMetadata';

type Point = { latitude: number; longitude: number };
const CABADBARAN_START = { latitude: 9.1236, longitude: 125.5354, latitudeDelta: 0.18, longitudeDelta: 0.18 };

export default function DamageManualPinMap({ point, onChange }: { point: Point | null; onChange: (point: Point) => void }) {
  const region = point && validPoint(point.latitude, point.longitude)
    ? { ...point, latitudeDelta: 0.012, longitudeDelta: 0.012 }
    : CABADBARAN_START;
  const choose = (next: Point) => {
    if (validPoint(next.latitude, next.longitude)) onChange(next);
  };
  return <View style={styles.frame}>
    <MapView style={styles.map} initialRegion={region} onPress={(event) => choose(event.nativeEvent.coordinate)} rotateEnabled={false} pitchEnabled={false}>
      {point && validPoint(point.latitude, point.longitude) && <Marker coordinate={point} draggable title="Farmer-selected damage point" onDragEnd={(event) => choose(event.nativeEvent.coordinate)} />}
    </MapView>
  </View>;
}

const styles = StyleSheet.create({ frame: { flex: 1, minHeight: 220, borderRadius: 14, overflow: 'hidden', marginBottom: 12 }, map: { flex: 1 } });
