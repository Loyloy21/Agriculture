// Auth-only presentation: does not alter the farmer app, picker or GPS services.
import { Image } from 'expo-image';
import { SymbolView } from 'expo-symbols';
import { StyleSheet, Text, View } from 'react-native';

export const authColors = {
  forest: '#174D36', deep: '#103E2C', ivory: '#F7F5EE', paper: '#FFFDF8',
  sage: '#E8EFDF', border: '#DCE1D5', ink: '#172B24', muted: '#637068', gold: '#BD9229',
};
const symbols = {
  mail: { ios: 'envelope', android: 'mail', web: 'mail' },
  lock: { ios: 'lock', android: 'lock', web: 'lock' },
  eye: { ios: 'eye', android: 'visibility', web: 'visibility' },
  eyeOff: { ios: 'eye.slash', android: 'visibility_off', web: 'visibility_off' },
  back: { ios: 'arrow.left', android: 'arrow_back', web: 'arrow_back' },
  arrow: { ios: 'arrow.right', android: 'arrow_forward', web: 'arrow_forward' },
  pin: { ios: 'mappin.and.ellipse', android: 'location_on', web: 'location_on' },
  person: { ios: 'person', android: 'person', web: 'person' },
  farm: { ios: 'house', android: 'agriculture', web: 'agriculture' },
  crop: { ios: 'leaf.fill', android: 'eco', web: 'eco' },
  livestock: { ios: 'pawprint.fill', android: 'pets', web: 'pets' },
  poultry: { ios: 'bird.fill', android: 'egg_alt', web: 'egg_alt' },
  fish: { ios: 'fish.fill', android: 'water', web: 'water' },
  calendar: { ios: 'calendar', android: 'calendar_month', web: 'calendar_month' },
  chevron: { ios: 'chevron.down', android: 'expand_more', web: 'expand_more' },
  camera: { ios: 'camera', android: 'photo_camera', web: 'photo_camera' },
  photo: { ios: 'photo', android: 'image', web: 'image' },
  id: { ios: 'person.text.rectangle', android: 'badge', web: 'badge' },
  idBack: { ios: 'rectangle.and.text.magnifyingglass', android: 'description', web: 'description' },
  area: { ios: 'square.dashed', android: 'square_foot', web: 'square_foot' },
  clock: { ios: 'clock', android: 'schedule', web: 'schedule' },
} as const;
export type AuthIconName = keyof typeof symbols;
export function AuthIcon({ name, size = 21, color = authColors.forest }: { name: AuthIconName; size?: number; color?: string }) {
  return <SymbolView name={symbols[name]} size={size} tintColor={color} style={{ width: size, height: size }} />;
}
export function AuthBrand({ compact = false, large = false }: { compact?: boolean; large?: boolean }) {
  const size = compact ? 30 : large ? 76 : 54;
  return <View style={[brand.brand, compact && brand.compact]} accessibilityLabel="AgriReach">
    <View style={{ width: size, height: size }} accessible={false}>
      <View style={[brand.leaf, { width: size * .39, height: size * .76, left: size * .13, top: size * .1, transform: [{ rotate: '-28deg' }] }]} />
      <View style={[brand.leaf, { width: size * .34, height: size * .49, left: size * .57, top: size * .02, backgroundColor: authColors.gold, transform: [{ rotate: '35deg' }] }]} />
      <View style={[brand.leaf, { width: size * .3, height: size * .51, left: size * .54, top: size * .39, transform: [{ rotate: '48deg' }] }]} />
    </View>
    <Text style={[brand.name, compact && brand.smallName, large && brand.largeName]}>AgriReach</Text>
  </View>;
}
export function AuthScenery({ height = 130 }: { height?: number }) {
  return <View style={{ height, width: '100%', overflow: 'hidden' }} accessible={false}>
    <Image source={require('@/assets/images/redesign/home-hero.jpg')} style={{ width: '100%', height: '100%' }} contentFit="cover" contentPosition={{ top: '65%', left: '50%' }} />
    <View style={{ position: 'absolute', top: -30, left: '-10%', width: '120%', height: 53, borderBottomLeftRadius: 180, borderBottomRightRadius: 100, backgroundColor: authColors.ivory }} />
  </View>;
}
export function AuthLandscape() {
  return <View style={[brand.landscape, { pointerEvents: 'none' }]} accessible={false}>
    <View style={[brand.hill, { backgroundColor: '#EDF0DF', left: '-18%', bottom: -52, transform: [{ rotate: '7deg' }] }]} />
    <View style={[brand.hill, { backgroundColor: '#DBE5CE', right: '-24%', bottom: -65, transform: [{ rotate: '-8deg' }] }]} />
    <View style={[brand.hill, { backgroundColor: '#C7D8B4', left: '-34%', bottom: -82, transform: [{ rotate: '8deg' }] }]} />
  </View>;
}
const brand = StyleSheet.create({
  brand: { alignItems: 'center', gap: 3 }, compact: { flexDirection: 'row', gap: 7 },
  leaf: { position: 'absolute', backgroundColor: authColors.forest, borderTopLeftRadius: 2, borderTopRightRadius: 40, borderBottomLeftRadius: 40, borderBottomRightRadius: 2 },
  name: { color: authColors.deep, fontSize: 26, fontWeight: '800', letterSpacing: -.8 }, smallName: { fontSize: 21 }, largeName: { fontSize: 40 },
  landscape: { position: 'absolute', bottom: 0, left: 0, right: 0, height: 74, overflow: 'hidden' },
  hill: { position: 'absolute', width: '130%', height: 120, borderRadius: 240 },
});
export const authStyles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: authColors.ivory }, keyboardView: { flex: 1, minHeight: 0 },
  content: { flexGrow: 1, alignSelf: 'center', paddingHorizontal: 4, paddingTop: 8, paddingBottom: 28 },
  back: { alignSelf: 'flex-start', minHeight: 44, minWidth: 44, justifyContent: 'center' },
  form: { paddingTop: 8, paddingBottom: 18 },
  title: { color: authColors.deep, fontSize: 29, lineHeight: 35, fontWeight: '800', textAlign: 'center', letterSpacing: -.7, marginTop: 26 },
  subtitle: { color: authColors.muted, fontSize: 15, lineHeight: 22, textAlign: 'center', marginTop: 8, marginBottom: 27 },
  label: { color: authColors.ink, fontSize: 14, fontWeight: '600', marginBottom: 8 },
  inputShell: { minHeight: 52, flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: authColors.border, borderRadius: 11, backgroundColor: authColors.paper, paddingHorizontal: 13, gap: 10 },
  input: { flex: 1, minWidth: 0, color: authColors.ink, fontSize: 16, minHeight: 50, paddingVertical: 12 },
  primary: { minHeight: 54, flexDirection: 'row', gap: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: authColors.forest, borderRadius: 13, paddingHorizontal: 16, paddingVertical: 12 },
  primaryText: { color: authColors.paper, fontSize: 16, fontWeight: '700' },
  secondary: { minHeight: 52, flexDirection: 'row', gap: 12, alignItems: 'center', justifyContent: 'center', borderColor: authColors.forest, borderWidth: 1, borderRadius: 13, paddingHorizontal: 16, paddingVertical: 12 },
  secondaryText: { color: authColors.forest, fontSize: 16, fontWeight: '700' },
  error: { color: '#A83F35', fontSize: 13, lineHeight: 19, marginTop: 6 },
});
