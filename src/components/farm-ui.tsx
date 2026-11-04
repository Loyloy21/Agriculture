import { router, usePathname, type Href } from 'expo-router';
import { SymbolView } from 'expo-symbols';
import { Image } from 'expo-image';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AccessibilityInfo, ActivityIndicator, Animated, Pressable, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

export const palette = {
  forest: '#174D36', deep: '#103E2C', sage: '#E8EFDF', ivory: '#F7F5EE', paper: '#FFFDF8',
  ink: '#172B24', muted: '#637068', border: '#E2E5DA', amber: '#996018', amberSoft: '#FFF0D8',
  blue: '#256588', blueSoft: '#E7F1F8', red: '#A83F35', redSoft: '#F8E9E3',
};
const icons = {
  leaf: { ios: 'leaf.fill', android: 'eco', web: 'eco' },
  home: { ios: 'house.fill', android: 'home', web: 'home' },
  reports: { ios: 'doc.text.fill', android: 'description', web: 'description' },
  advice: { ios: 'book.closed.fill', android: 'menu_book', web: 'menu_book' },
  alerts: { ios: 'bell.fill', android: 'notifications', web: 'notifications' },
  person: { ios: 'person.fill', android: 'person', web: 'person' },
  farm: { ios: 'leaf.circle.fill', android: 'agriculture', web: 'agriculture' },
  pin: { ios: 'mappin.and.ellipse', android: 'location_on', web: 'location_on' },
  chevron: { ios: 'chevron.right', android: 'chevron_right', web: 'chevron_right' },
  back: { ios: 'chevron.left', android: 'arrow_back', web: 'arrow_back' },
  plus: { ios: 'plus', android: 'add', web: 'add' },
  camera: { ios: 'camera.fill', android: 'photo_camera', web: 'photo_camera' },
  photo: { ios: 'photo.fill', android: 'image', web: 'image' },
  check: { ios: 'checkmark.circle.fill', android: 'check_circle', web: 'check_circle' },
  close: { ios: 'xmark', android: 'close', web: 'close' },
  edit: { ios: 'pencil', android: 'edit', web: 'edit' },
  weather: { ios: 'cloud.rain.fill', android: 'rainy', web: 'rainy' },
  sun: { ios: 'sun.max.fill', android: 'sunny', web: 'sunny' },
  wind: { ios: 'wind', android: 'air', web: 'air' },
  water: { ios: 'drop.fill', android: 'water_drop', web: 'water_drop' },
  calendar: { ios: 'calendar', android: 'calendar_month', web: 'calendar_month' },
  livestock: { ios: 'pawprint.fill', android: 'pets', web: 'pets' },
  poultry: { ios: 'bird.fill', android: 'egg_alt', web: 'egg_alt' },
  fish: { ios: 'fish.fill', android: 'water', web: 'water' },
  phone: { ios: 'phone.fill', android: 'call', web: 'call' },
  mail: { ios: 'envelope.fill', android: 'mail', web: 'mail' },
  language: { ios: 'globe', android: 'language', web: 'language' },
  logout: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout', web: 'logout' },
  message: { ios: 'bubble.left.fill', android: 'chat', web: 'chat' },
  search: { ios: 'magnifyingglass', android: 'search', web: 'search' },
  lock: { ios: 'lock.fill', android: 'lock', web: 'lock' },
  chart: { ios: 'chart.bar.fill', android: 'bar_chart', web: 'bar_chart' },
  damage: { ios: 'exclamationmark.shield.fill', android: 'gpp_maybe', web: 'gpp_maybe' },
  history: { ios: 'clock.arrow.circlepath', android: 'history', web: 'history' },
} as const;
export type IconName = keyof typeof icons;
export function Icon({ name, size = 22, color = palette.forest }: { name: IconName; size?: number; color?: string }) {
  return <SymbolView name={icons[name]} size={size} tintColor={color} style={{ width: size, height: size }} />;
}
export function categoryIcon(category: string): IconName {
  return ({ Crops: 'leaf', Livestock: 'livestock', Poultry: 'poultry', Fishpond: 'fish' } as Record<string, IconName>)[category] || 'farm';
}

export function useReducedMotion() {
  const [reduced, setReduced] = useState(true);
  useEffect(() => {
    let active = true;
    void AccessibilityInfo.isReduceMotionEnabled().then((value) => { if (active) setReduced(value); }).catch(() => {});
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduced);
    return () => { active = false; subscription.remove(); };
  }, []);
  return reduced;
}
export function Motion({ children, trigger, style }: { children: ReactNode; trigger?: unknown; style?: StyleProp<ViewStyle> }) {
  const reduced = useReducedMotion();
  const opacity = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    if (reduced) { opacity.setValue(1); return; }
    opacity.setValue(0.78);
    const animation = Animated.timing(opacity, { toValue: 1, duration: 180, useNativeDriver: true });
    animation.start();
    return () => animation.stop();
  }, [trigger, reduced, opacity]);
  return <Animated.View style={[style, { opacity }]}>{children}</Animated.View>;
}
export function Action({ title, onPress, icon, secondary = false, disabled = false, busy = false }: {
  title: string; onPress: () => void; icon?: IconName; secondary?: boolean; disabled?: boolean; busy?: boolean;
}) {
  const reduced = useReducedMotion();
  const scale = useRef(new Animated.Value(1)).current;
  const press = (value: number) => {
    if (!reduced) Animated.timing(scale, { toValue: value, duration: 90, useNativeDriver: true }).start();
  };
  return <Animated.View style={{ transform: [{ scale }], opacity: disabled ? 0.5 : 1 }}>
    <Pressable accessibilityRole="button" disabled={disabled || busy} onPress={onPress} onPressIn={() => press(0.985)} onPressOut={() => press(1)} style={[ui.action, secondary && ui.secondaryAction]}>
      {busy ? <ActivityIndicator color={secondary ? palette.forest : palette.paper} /> : <>
        {icon && <Icon name={icon} color={secondary ? palette.forest : palette.paper} size={20} />}
        <Text style={[ui.actionText, secondary && { color: palette.forest }]}>{title}</Text>
      </>}
    </Pressable>
  </Animated.View>;
}

type Tab = 'home' | 'reports' | 'advice' | 'alerts' | 'profile';
const tabs: { key: Tab; label: string; icon: IconName; route: Href }[] = [
  { key: 'home', label: 'Home', icon: 'home', route: '/farmer-home' },
  { key: 'reports', label: 'Reports', icon: 'reports', route: '/reports' },
  { key: 'advice', label: 'Advice', icon: 'advice', route: '/weather-advice' },
  { key: 'alerts', label: 'Alerts', icon: 'alerts', route: '/notifications' },
  { key: 'profile', label: 'Profile', icon: 'person', route: '/profile' },
];
export function BottomTabs({ active, unread = 0 }: { active: Tab; unread?: number }) {
  const insets = useSafeAreaInsets();
  const pathname = usePathname();
  // Damage Reports and Production Report sit under the Reports tab: tapping it there returns to the Reports screen.
  return <View style={[ui.tabs, { paddingBottom: Math.max(insets.bottom, 8) }]}>
    {tabs.map((tab) => <Pressable key={tab.key} accessibilityRole="tab" accessibilityState={{ selected: active === tab.key }} accessibilityLabel={tab.label} onPress={() => (active !== tab.key || pathname !== tab.route) && router.replace(tab.route)} style={ui.tab}>
      <View><Icon name={tab.icon} color={active === tab.key ? palette.forest : palette.muted} />{tab.key === 'alerts' && unread > 0 && <View style={ui.dot} />}</View>
      <Text style={[ui.tabLabel, active === tab.key && { color: palette.forest, fontWeight: '700' }]}>{tab.label}</Text>
    </Pressable>)}
  </View>;
}
export function AppScreen({ children, tab, unread, back = false }: { children: ReactNode; tab?: Tab; unread?: number; back?: boolean }) {
  return <SafeAreaView edges={tab ? ['top', 'left', 'right'] : ['top', 'bottom', 'left', 'right']} style={ui.screen}>
    <StatusBar style="dark" />
    <View style={ui.header}>
      {back && <Pressable accessibilityRole="button" accessibilityLabel="Go back" onPress={() => router.back()} style={ui.headerButton}><Icon name="back" /></Pressable>}
      <View style={ui.brand}><Icon name="leaf" size={27} /><Text style={ui.brandText}>AgriReach</Text></View>
      {!back && <Pressable accessibilityRole="button" accessibilityLabel="Open notifications" onPress={() => router.push('/notifications')} style={ui.headerButton}><Icon name="alerts" />{!!unread && <View style={ui.dot} />}</Pressable>}
    </View>
    <Motion style={{ flex: 1 }}>{children}</Motion>
    {tab && <BottomTabs active={tab} unread={unread} />}
  </SafeAreaView>;
}
const heroes = {
  home: require('@/assets/images/redesign/home-hero.jpg'),
  weather: require('@/assets/images/redesign/weather-hero.jpg'),
  report: require('@/assets/images/redesign/report-damage-hero.jpg'),
  profile: require('@/assets/images/redesign/profile-farms-hero.jpg'),
};
export function Hero({ kind, title, subtitle, children, compact = false }: { kind: keyof typeof heroes; title: string; subtitle?: string; children?: ReactNode; compact?: boolean }) {
  return <View style={[ui.hero, compact && { minHeight: 136 }]}>
    <Image source={heroes[kind]} contentFit="cover" contentPosition={{ left: kind === 'home' ? '75%' : '65%', top: '50%' }} style={[StyleSheet.absoluteFill, { width: '100%', height: '100%' }]} accessible={false} />
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, ui.gradient]} />
    <View style={ui.heroContent}><Text style={ui.heroTitle}>{title}</Text>{subtitle && <Text style={ui.heroSubtitle}>{subtitle}</Text>}{children}</View>
  </View>;
}
export function Badge({ label, tone = 'green' }: { label: string; tone?: 'green' | 'blue' | 'amber' | 'red' | 'neutral' }) {
  const tones = { green: [palette.forest, palette.sage], blue: [palette.blue, palette.blueSoft], amber: [palette.amber, palette.amberSoft], red: [palette.red, palette.redSoft], neutral: [palette.muted, '#ECEDE6'] };
  return <View style={[ui.badge, { backgroundColor: tones[tone][1] }]}><Text style={[ui.badgeText, { color: tones[tone][0] }]}>{label}</Text></View>;
}
export function StatusBadge({ status }: { status: string }) {
  const tone = status === 'Rejected' ? 'red' : status === 'Under Review' ? 'blue' : ['Pending', 'Received'].includes(status) ? 'amber' : ['Approved', 'Resolved', 'Active'].includes(status) ? 'green' : 'neutral';
  return <Motion trigger={status}><Badge label={status} tone={tone} /></Motion>;
}
export function EmptyState({ title, message, loading, retry, icon = 'leaf', children }: { title: string; message?: string; loading?: boolean; retry?: () => void; icon?: IconName; children?: ReactNode }) {
  return <View style={[ui.card, ui.empty]}>{loading ? <ActivityIndicator color={palette.forest} /> : <Icon name={icon} size={30} />}<Text style={ui.cardTitle}>{title}</Text>{message && <Text style={[ui.body, { textAlign: 'center' }]}>{message}</Text>}{retry && <Action title="Try again" onPress={retry} secondary />}{children}</View>;
}
export function SectionHeading({ title, icon, action }: { title: string; icon?: IconName; action?: ReactNode }) {
  return <View style={ui.sectionHeading}>{icon && <Icon name={icon} />}<Text style={[ui.sectionTitle, { flex: 1 }]}>{title}</Text>{action}</View>;
}
export function formatDate(value: string | null, includeTime = false): string {
  if (!value) return 'Not recorded';
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00+08:00` : !/[Zz]|[+-]\d{2}:?\d{2}$/.test(value) ? value.replace(' ', 'T') + '+08:00' : value;
  const date = new Date(normalized);
  return Number.isFinite(date.getTime()) ? date.toLocaleString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', ...(includeTime ? { hour: 'numeric', minute: '2-digit' } : {}) }) : 'Not recorded';
}

export const ui = StyleSheet.create({
  screen: { flex: 1, backgroundColor: palette.ivory }, header: { minHeight: 50, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', gap: 4, borderBottomWidth: 1, borderColor: palette.border },
  brand: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 9 }, brandText: { color: palette.deep, fontSize: 20, fontWeight: '800', letterSpacing: -0.6 }, headerButton: { minWidth: 44, minHeight: 44, alignItems: 'center', justifyContent: 'center' },
  content: { width: '100%', maxWidth: 720, alignSelf: 'center', padding: 16, paddingBottom: 24, gap: 12 },
  hero: { minHeight: 196, overflow: 'hidden', justifyContent: 'flex-end', borderRadius: 16 }, heroContent: { padding: 16, gap: 6 },
  gradient: { backgroundColor: 'transparent', experimental_backgroundImage: 'linear-gradient(180deg, rgba(10,36,24,0.04) 0%, rgba(10,36,24,0.28) 45%, rgba(10,36,24,0.64) 100%)' },
  heroTitle: { color: '#FFFFFF', fontSize: 25, fontWeight: '800', letterSpacing: -0.7 }, heroSubtitle: { color: '#F5F8EF', fontSize: 14, lineHeight: 20 },
  title: { color: palette.ink, fontSize: 24, fontWeight: '800', letterSpacing: -0.7 }, body: { color: palette.muted, fontSize: 14, lineHeight: 21 }, small: { color: palette.muted, fontSize: 12, lineHeight: 18 },
  card: { backgroundColor: palette.paper, borderWidth: 1, borderColor: palette.border, borderRadius: 14, padding: 12, gap: 7, shadowColor: '#173C2A', shadowOpacity: 0.04, shadowRadius: 10, shadowOffset: { width: 0, height: 3 }, elevation: 1 },
  cardTitle: { color: palette.ink, fontSize: 16, fontWeight: '700', lineHeight: 21 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 }, sectionHeading: { flexDirection: 'row', alignItems: 'center', gap: 9, marginTop: 7 }, sectionTitle: { color: palette.ink, fontSize: 18, fontWeight: '700' },
  action: { minHeight: 44, backgroundColor: palette.forest, borderRadius: 12, paddingVertical: 10, paddingHorizontal: 14, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'center', gap: 9 }, actionText: { color: palette.paper, fontWeight: '700', fontSize: 14, textAlign: 'center', flexShrink: 1 }, secondaryAction: { backgroundColor: palette.sage, borderWidth: 1, borderColor: '#CAD8C4' },
  tabs: { flexDirection: 'row', borderTopWidth: 1, borderColor: palette.border, backgroundColor: palette.paper, paddingTop: 8 }, tab: { flex: 1, minHeight: 48, paddingHorizontal: 2, alignItems: 'center', justifyContent: 'center', gap: 4 }, tabLabel: { color: palette.muted, fontSize: 11, textAlign: 'center' }, dot: { position: 'absolute', right: -3, top: -3, width: 8, height: 8, borderRadius: 4, backgroundColor: '#C84E40', borderWidth: 1, borderColor: palette.paper },
  badge: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }, badgeText: { fontSize: 12, fontWeight: '600' },
  empty: { alignItems: 'center', paddingVertical: 28, gap: 12 }, chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, chip: { minHeight: 40, flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 13, paddingVertical: 8, backgroundColor: '#EFEEE7', borderRadius: 22 }, chipActive: { backgroundColor: palette.forest }, chipText: { color: palette.muted, fontSize: 13 }, chipTextActive: { color: palette.paper, fontWeight: '700' },
});
