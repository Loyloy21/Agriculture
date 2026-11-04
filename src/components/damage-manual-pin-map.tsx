import { Text, View } from 'react-native';

type Point = { latitude: number; longitude: number };

export default function DamageManualPinMap(_props: { point: Point | null; onChange: (point: Point) => void }) {
  return <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
    <Text>The interactive map is available in the Android app. You can enter coordinates in the report form.</Text>
  </View>;
}
