// Android and web: map imagery drawn from standard map tiles with plain React Native <Image> views.
// No native map module and no Google Maps key are involved, so this works in the already-installed development
// APK. Tap to place the pin; drag to pan; pinch or use +/- to zoom. iPhone uses registration-pin-map.ios.tsx.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Image, Linking, PanResponder, Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';

import {
  clampZoom, formatCoordinates, googleMapsLink, MAX_ZOOM, MIN_ZOOM, openStreetMapLink, panCenter, pointToViewport,
  resolveTileUrlTemplate, TILE_ATTRIBUTION, TILE_USER_AGENT, viewportToPoint, visibleTiles, zoomForLongitudeDelta, type LatLng,
} from '@/services/mapTiles';
import type { PinRegion, RegistrationPinMapProps } from './registration-pin-map.types';

const TILE_TEMPLATE = resolveTileUrlTemplate(typeof process !== 'undefined' ? process.env.EXPO_PUBLIC_MAP_TILE_URL : undefined);
const TILE_TIMEOUT_MS = 10000;
const TAP_SLOP_PX = 8;
const TAP_MAX_MS = 700;
const GREEN = '#174D36';

type View2D = { center: LatLng; zoom: number };

function viewFor(region: PinRegion, widthPx: number): View2D {
  return { center: { latitude: region.latitude, longitude: region.longitude }, zoom: zoomForLongitudeDelta(region.longitudeDelta, widthPx) };
}

export default function RegistrationPinMap({ pin, initialRegion, focus, onPick, height, pinLabel, onGestureActive }: RegistrationPinMapProps) {
  const { width: windowWidth } = useWindowDimensions();
  const [size, setSize] = useState({ width: 0, height });
  const [view, setView] = useState<View2D>(() => viewFor(initialRegion, windowWidth));
  const [loaded, setLoaded] = useState(0);
  const [failed, setFailed] = useState(0);
  const [timedOut, setTimedOut] = useState(false);
  const [reloadNonce, setReloadNonce] = useState(0);
  const loadedKeys = useRef(new Set<string>());
  const failedKeys = useRef(new Set<string>());
  const viewRef = useRef(view);
  const sizeRef = useRef(size);
  const pickRef = useRef(onPick);
  const activeRef = useRef(onGestureActive);
  viewRef.current = view;
  sizeRef.current = size;
  pickRef.current = onPick;
  activeRef.current = onGestureActive;
  const gesture = useRef({ startCenter: view.center, startZoom: view.zoom, pinchStart: 0, moved: false, startTime: 0, startX: 0, startY: 0 });

  // A focus request moves the view only. It never sets a pin.
  useEffect(() => {
    if (focus) setView(viewFor(focus.region, sizeRef.current.width || windowWidth));
  }, [focus?.nonce]);

  // If no tile at all arrives in time, say so instead of leaving a blank rectangle.
  useEffect(() => {
    setTimedOut(false);
    const timer = setTimeout(() => { if (loadedKeys.current.size === 0) setTimedOut(true); }, TILE_TIMEOUT_MS);
    return () => clearTimeout(timer);
  }, [reloadNonce]);

  const tiles = useMemo(() => visibleTiles(view.center, view.zoom, size.width, size.height, TILE_TEMPLATE), [view.center.latitude, view.center.longitude, view.zoom, size.width, size.height]);
  const noImagery = loaded === 0 && (timedOut || (tiles.length > 0 && failed >= tiles.length));
  const someMissing = loaded > 0 && failed > 0;

  const responder = useMemo(() => PanResponder.create({
    onStartShouldSetPanResponder: () => true,
    onMoveShouldSetPanResponder: () => true,
    onPanResponderTerminationRequest: () => false,
    onPanResponderGrant: (event) => {
      const g = gesture.current;
      g.startCenter = viewRef.current.center;
      g.startZoom = viewRef.current.zoom;
      g.pinchStart = 0;
      g.moved = false;
      g.startTime = Date.now();
      g.startX = event.nativeEvent.locationX;
      g.startY = event.nativeEvent.locationY;
      activeRef.current?.(true);
    },
    onPanResponderMove: (event, state) => {
      const g = gesture.current;
      const touches = event.nativeEvent.touches;
      if (touches && touches.length >= 2) {
        const distance = Math.hypot(touches[0].pageX - touches[1].pageX, touches[0].pageY - touches[1].pageY);
        if (!g.pinchStart) { g.pinchStart = distance; g.startZoom = viewRef.current.zoom; }
        g.moved = true;
        const zoom = clampZoom(g.startZoom + Math.log2(distance / g.pinchStart));
        if (zoom !== viewRef.current.zoom) setView((current) => ({ ...current, zoom }));
        return;
      }
      if (g.pinchStart) return;
      if (Math.abs(state.dx) + Math.abs(state.dy) > TAP_SLOP_PX) g.moved = true;
      if (g.moved) setView((current) => ({ ...current, center: panCenter(g.startCenter, current.zoom, state.dx, state.dy) }));
    },
    onPanResponderRelease: () => {
      const g = gesture.current;
      activeRef.current?.(false);
      const isTap = !g.moved && !g.pinchStart && Date.now() - g.startTime < TAP_MAX_MS;
      g.pinchStart = 0;
      const { width, height: h } = sizeRef.current;
      if (!isTap || !width) return;
      pickRef.current(viewportToPoint(g.startX, g.startY, viewRef.current.center, viewRef.current.zoom, width, h));
    },
    onPanResponderTerminate: () => { gesture.current.pinchStart = 0; activeRef.current?.(false); },
  }), []);

  const zoomBy = useCallback((delta: number) => setView((current) => ({ ...current, zoom: clampZoom(current.zoom + delta) })), []);
  const retry = useCallback(() => {
    loadedKeys.current.clear();
    failedKeys.current.clear();
    setLoaded(0);
    setFailed(0);
    setReloadNonce((value) => value + 1);
  }, []);
  const markLoaded = (url: string) => { if (!loadedKeys.current.has(url)) { loadedKeys.current.add(url); failedKeys.current.delete(url); setLoaded(loadedKeys.current.size); setFailed(failedKeys.current.size); } };
  const markFailed = (url: string) => { if (!failedKeys.current.has(url) && !loadedKeys.current.has(url)) { failedKeys.current.add(url); setFailed(failedKeys.current.size); } };

  const pinPixel = pin ? pointToViewport(pin, view.center, view.zoom, size.width, size.height) : null;
  const pinVisible = !!pinPixel && pinPixel.x >= -20 && pinPixel.x <= size.width + 20 && pinPixel.y >= -40 && pinPixel.y <= size.height + 20;

  return (
    <View style={[styles.frame, { height }]} onLayout={(event) => setSize({ width: event.nativeEvent.layout.width, height: event.nativeEvent.layout.height })} testID="registration-pin-map">
      <View style={styles.gestureLayer} {...responder.panHandlers} accessible accessibilityLabel={`Map for choosing the ${pinLabel} point. Tap to place the pin.`}>
        <View pointerEvents="none" style={StyleSheet.absoluteFill}>
          {tiles.map((tile) => (
            <Image
              key={`${reloadNonce}:${tile.key}`}
              source={{ uri: tile.url, headers: { 'User-Agent': TILE_USER_AGENT } }}
              style={[styles.tile, { left: tile.left, top: tile.top }]}
              resizeMode="stretch"
              fadeDuration={0}
              onLoad={() => markLoaded(tile.url)}
              onError={() => markFailed(tile.url)}
            />
          ))}
          {pinVisible && pinPixel && <View style={[styles.pinAnchor, { left: pinPixel.x, top: pinPixel.y }]}>
            <View style={styles.pinHead}><View style={styles.pinHeadDot} /></View>
            <View style={styles.pinStem} />
            <View testID="registration-pin-tip" style={styles.pinTip} />
          </View>}
        </View>
      </View>

      {noImagery && <View style={styles.unavailable} accessibilityRole="alert">
        <Text style={styles.unavailableTitle}>Map imagery could not be loaded</Text>
        <Text style={styles.unavailableText}>Check your internet connection. Nothing is wrong with your location: you can still use the coordinates and links below, or enter the address manually.</Text>
        {pin ? <Text style={styles.unavailableCoords}>Selected point: {formatCoordinates(pin)}</Text> : <Text style={styles.unavailableText}>No point is selected yet. Tap Retry once the connection is back.</Text>}
        <View style={styles.unavailableActions}>
          <Pressable accessibilityRole="button" onPress={retry} style={styles.unavailableButton}><Text style={styles.unavailableButtonText}>Retry map</Text></Pressable>
          {pin && <Pressable accessibilityRole="link" onPress={() => { void Linking.openURL(googleMapsLink(pin)).catch(() => Linking.openURL(openStreetMapLink(pin))); }} style={styles.unavailableButton}><Text style={styles.unavailableButtonText}>Open exact point in Google Maps</Text></Pressable>}
        </View>
      </View>}

      <View style={styles.zoomColumn}>
        <Pressable accessibilityRole="button" accessibilityLabel="Zoom in" disabled={view.zoom >= MAX_ZOOM} onPress={() => zoomBy(1)} style={[styles.zoomButton, view.zoom >= MAX_ZOOM && styles.zoomDisabled]}><Text style={styles.zoomText}>+</Text></Pressable>
        <Pressable accessibilityRole="button" accessibilityLabel="Zoom out" disabled={view.zoom <= MIN_ZOOM} onPress={() => zoomBy(-1)} style={[styles.zoomButton, view.zoom <= MIN_ZOOM && styles.zoomDisabled]}><Text style={styles.zoomText}>−</Text></Pressable>
      </View>
      <Pressable accessibilityRole="link" onPress={() => { void Linking.openURL('https://www.openstreetmap.org/copyright'); }} style={styles.attribution}><Text style={styles.attributionText}>{TILE_ATTRIBUTION}</Text></Pressable>
      {someMissing && !noImagery && <View style={styles.partial}><Text style={styles.partialText}>Some map tiles did not load.</Text></View>}
    </View>
  );
}

const styles = StyleSheet.create({
  frame: { width: '100%', minHeight: 180, backgroundColor: '#DCE5D3', overflow: 'hidden' },
  gestureLayer: { position: 'absolute', left: 0, top: 0, right: 0, bottom: 0 },
  tile: { position: 'absolute', width: 256, height: 256, backgroundColor: '#E6ECDD' },
  pinAnchor: { position: 'absolute', width: 0, height: 0, alignItems: 'center' },
  pinHead: { position: 'absolute', left: -13, top: -41, width: 26, height: 26, borderRadius: 13, backgroundColor: '#C62828', borderWidth: 2, borderColor: '#FFFFFF', alignItems: 'center', justifyContent: 'center' },
  pinHeadDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#FFFFFF' },
  pinStem: { position: 'absolute', left: -1.5, top: -16, width: 3, height: 15, backgroundColor: '#C62828' },
  pinTip: { position: 'absolute', left: -4, top: -4, width: 8, height: 8, borderRadius: 4, backgroundColor: '#C62828', borderWidth: 1.5, borderColor: '#FFFFFF' },
  zoomColumn: { position: 'absolute', right: 10, top: 10, gap: 8 },
  zoomButton: { width: 44, height: 44, borderRadius: 10, backgroundColor: '#FFFFFF', borderWidth: 1, borderColor: '#B9C7AE', alignItems: 'center', justifyContent: 'center' },
  zoomDisabled: { opacity: 0.4 },
  zoomText: { color: GREEN, fontSize: 24, fontWeight: '700', lineHeight: 26 },
  attribution: { position: 'absolute', left: 0, bottom: 0, backgroundColor: 'rgba(255,255,255,0.85)', paddingHorizontal: 6, paddingVertical: 3 },
  attributionText: { color: '#333333', fontSize: 11 },
  partial: { position: 'absolute', left: 8, top: 8, backgroundColor: 'rgba(255,248,232,0.95)', borderRadius: 8, paddingHorizontal: 8, paddingVertical: 4 },
  partialText: { color: '#7A5314', fontSize: 11 },
  unavailable: { position: 'absolute', left: 0, top: 0, right: 0, bottom: 0, backgroundColor: '#FFF8E8', padding: 16, justifyContent: 'center', gap: 8 },
  unavailableTitle: { color: '#7A5314', fontSize: 16, fontWeight: '800' },
  unavailableText: { color: '#5A4A2A', fontSize: 13, lineHeight: 18 },
  unavailableCoords: { color: '#2A2A2A', fontSize: 13, fontWeight: '700' },
  unavailableActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  unavailableButton: { minHeight: 44, paddingHorizontal: 14, borderRadius: 10, borderWidth: 1, borderColor: GREEN, backgroundColor: '#FFFFFF', alignItems: 'center', justifyContent: 'center' },
  unavailableButtonText: { color: GREEN, fontSize: 13, fontWeight: '700', textAlign: 'center' },
});
