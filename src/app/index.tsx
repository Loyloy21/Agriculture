import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

const GREEN = '#4B842F';
const LIGHT_GREEN = '#6EA94A';

function LeafMark({ size }: { size: number }) {
  return (
    <View style={[styles.leafMark, { width: size, height: size }]} accessibilityLabel="AgriReach leaf logo">
      <View style={[styles.leaf, styles.leftLeaf, { width: size * 0.25, height: size * 0.48 }]} />
      <View style={[styles.leaf, styles.rightLeaf, { width: size * 0.25, height: size * 0.48 }]} />
      <View style={[styles.stem, { height: size * 0.45 }]} />
    </View>
  );
}

export default function LandingScreen() {
  const { width, height } = useWindowDimensions();
  const isTablet = width >= 600;
  const contentWidth = Math.min(width * 0.88, isTablet ? 420 : 390);
  const logoSize = Math.min(Math.max(width * 0.25, 104), isTablet ? 176 : 142);
  const compactScreen = height < 680;

  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={[styles.content, { width: contentWidth }]}>
        <View style={[styles.upperContent, compactScreen && styles.compactUpperContent]}>
          <View style={[styles.logoCircle, { width: logoSize, height: logoSize, borderRadius: logoSize / 2 }]}>
            <LeafMark size={logoSize} />
          </View>
          <Text style={[styles.title, { fontSize: isTablet ? 38 : 32 }]}>AgriReach</Text>
          <Text style={[styles.subtitle, { fontSize: isTablet ? 19 : 16 }]}>Connecting farmers with support</Text>
        </View>

        <View style={styles.actions}>
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push('/login')}
            style={({ pressed }) => [styles.button, styles.loginButton, pressed && styles.pressed]}
          >
            <Text style={styles.loginText}>Login</Text>
          </Pressable>
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push('/register')}
            style={({ pressed }) => [styles.button, styles.registerButton, pressed && styles.pressed]}
          >
            <Text style={styles.registerText}>Register</Text>
          </Pressable>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: GREEN },
  content: {
    flex: 1,
    alignSelf: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 4,
    paddingTop: '10%',
    paddingBottom: '5%',
  },
  upperContent: { alignItems: 'center', justifyContent: 'center', flex: 1, gap: 12 },
  compactUpperContent: { gap: 7 },
  logoCircle: { backgroundColor: LIGHT_GREEN, alignItems: 'center', justifyContent: 'center', marginBottom: 8 },
  leafMark: { alignItems: 'center', justifyContent: 'center' },
  leaf: { position: 'absolute', backgroundColor: '#FFFFFF', borderRadius: 100 },
  leftLeaf: { transform: [{ rotate: '-38deg' }, { translateX: -10 }, { translateY: -10 }] },
  rightLeaf: { transform: [{ rotate: '38deg' }, { translateX: 10 }, { translateY: -10 }] },
  stem: { width: 5, backgroundColor: '#FFFFFF', borderRadius: 5, transform: [{ translateY: 13 }] },
  title: { color: '#FFFFFF', fontWeight: '800', textAlign: 'center' },
  subtitle: { color: '#FFFFFF', textAlign: 'center', fontWeight: '400' },
  actions: { width: '100%', gap: 14 },
  button: { width: '100%', minHeight: 56, alignItems: 'center', justifyContent: 'center', borderRadius: 18 },
  loginButton: { backgroundColor: '#FFFFFF' },
  registerButton: { borderWidth: 2, borderColor: '#FFFFFF', backgroundColor: 'transparent' },
  loginText: { color: GREEN, fontSize: 17, fontWeight: '700' },
  registerText: { color: '#FFFFFF', fontSize: 17, fontWeight: '700' },
  pressed: { opacity: 0.78 },
});
