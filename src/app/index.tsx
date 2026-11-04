import { router } from 'expo-router';
import { Image } from 'expo-image';
import { StatusBar } from 'expo-status-bar';
import { Pressable, ScrollView, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { AuthBrand, AuthIcon, AuthLandscape, authColors } from '@/components/auth-ui';

export default function LandingScreen() {
  const { width, height } = useWindowDimensions();
  const contentWidth = Math.min(width - 40, 440);
  const sceneryHeight = Math.min(Math.max(height * .38, 205), 350);

  function handleLoginPress() {
    router.push('/login');
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar style="dark" />
      <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
        <View style={[styles.scenery, { height: sceneryHeight }]} accessible={false}>
          <Image source={require('@/assets/images/redesign/home-hero.jpg')} style={styles.image} contentFit="cover" contentPosition={{ left: '50%', top: '65%' }} />
          <View style={styles.wave} />
        </View>
        <View style={[styles.content, { width: contentWidth }]}>
          <AuthBrand large />
          <Text style={styles.subtitle}>Connecting farmers{ '\n' }with support</Text>
          <View style={styles.goldRule} />
        <View style={styles.actions}>
          <Pressable
            accessibilityRole="button"
            onPress={handleLoginPress}
            style={({ pressed }) => [styles.button, styles.loginButton, pressed && styles.pressed]}
          >
            <Text style={styles.loginText}>Login</Text><AuthIcon name="arrow" color={authColors.paper} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push('/register')}
            style={({ pressed }) => [styles.button, styles.registerButton, pressed && styles.pressed]}
          >
            <Text style={styles.registerText}>Register</Text><AuthIcon name="arrow" />
          </Pressable>
        </View>
          <View style={styles.footer}><Text style={styles.city}>Cabadbaran City</Text><Text style={styles.tagline}>STRONGER FARMERS{ '\n' }BRIGHTER TOMORROWS</Text></View>
        </View>
        <AuthLandscape />
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: authColors.ivory }, scroll: { flexGrow: 1, paddingBottom: 18 },
  scenery: { width: '100%', overflow: 'hidden' }, image: { width: '100%', height: '100%' },
  wave: { position: 'absolute', bottom: -38, left: '-10%', width: '120%', height: 68, borderTopLeftRadius: 160, borderTopRightRadius: 260, backgroundColor: authColors.ivory, transform: [{ rotate: '4deg' }] },
  content: { alignSelf: 'center', alignItems: 'center', paddingTop: 8, paddingBottom: 35, zIndex: 1 },
  subtitle: { color: authColors.deep, fontSize: 17, lineHeight: 24, textAlign: 'center', letterSpacing: 1.6, marginTop: 8 },
  goldRule: { width: 32, height: 2, backgroundColor: authColors.gold, marginVertical: 17 },
  actions: { width: '100%', gap: 12 },
  button: { width: '100%', minHeight: 54, flexDirection: 'row', gap: 14, alignItems: 'center', justifyContent: 'center', borderRadius: 28, paddingVertical: 12, paddingHorizontal: 16 },
  loginButton: { backgroundColor: authColors.forest }, registerButton: { borderWidth: 1, borderColor: authColors.forest },
  loginText: { color: authColors.paper, fontSize: 17, fontWeight: '700' }, registerText: { color: authColors.forest, fontSize: 17, fontWeight: '700' },
  footer: { alignItems: 'center', marginTop: 24 }, city: { color: authColors.deep, fontSize: 13, fontWeight: '600' },
  tagline: { color: authColors.muted, fontSize: 9, lineHeight: 15, letterSpacing: 2, textAlign: 'center', marginTop: 6 },
  pressed: { opacity: 0.78 },
});
