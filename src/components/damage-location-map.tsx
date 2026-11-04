import { Linking, Pressable, Text } from 'react-native';
export default function DamageLocationMap({ latitude, longitude, label }: { latitude: number; longitude: number; accuracy?: number | null; label: string }) {
  return <Pressable onPress={() => void Linking.openURL(`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latitude},${longitude}`)}`)} style={{ padding: 20, backgroundColor: '#EDF6E8', borderRadius: 12, marginVertical: 12 }}><Text style={{ color: '#356B27' }}>Open {label.toLowerCase()} on map: {latitude.toFixed(7)}, {longitude.toFixed(7)}</Text></Pressable>;
}
