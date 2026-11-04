// iPhone: the native Apple Maps view, exactly as registration used it before. Needs no API key.
// Android and web use registration-pin-map.tsx (plain tiles) because the Android Google Maps SDK draws nothing
// without an authorised key in the installed APK.
import { useEffect, useRef } from 'react';
import MapView, { Marker, type MapPressEvent } from 'react-native-maps';
import { StyleSheet } from 'react-native';

import type { RegistrationPinMapProps } from './registration-pin-map.types';

export default function RegistrationPinMap({ pin, initialRegion, focus, onPick, height, pinLabel }: RegistrationPinMapProps) {
  const mapRef = useRef<MapView>(null);
  useEffect(() => {
    if (focus) mapRef.current?.animateToRegion(focus.region, 500);
  }, [focus?.nonce]);
  return (
    <MapView
      ref={mapRef}
      accessibilityLabel={`Map for choosing the ${pinLabel} point`}
      style={[styles.map, { height }]}
      initialRegion={initialRegion}
      onPress={(event: MapPressEvent) => onPick(event.nativeEvent.coordinate)}
    >
      {pin && <Marker coordinate={pin} draggable title={`Proposed ${pinLabel} location`} description={`Drag this marker to the actual ${pinLabel} point`} onDragEnd={(event) => onPick(event.nativeEvent.coordinate)} />}
    </MapView>
  );
}

const styles = StyleSheet.create({ map: { width: '100%', minHeight: 180 } });
