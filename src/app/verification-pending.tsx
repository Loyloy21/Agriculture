import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function VerificationPendingScreen() {
  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.content}>
        <Text style={styles.brand}>AgriReach</Text>
        <View style={styles.iconCircle}><Text style={styles.icon}>◷</Text></View>
        <Text style={styles.title}>Verification Pending</Text>
        <Text style={styles.message}>Your registration is under review.</Text>
        <Text style={styles.detail}>The Department of Agriculture will review your submitted information and ID photos. You will be notified once your account has been verified.</Text>
        <Pressable onPress={() => router.replace('/login')} style={styles.button}><Text style={styles.buttonText}>Return to Login</Text></Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#FCFDF9' },
  content: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 28 },
  brand: { color: '#4B842F', fontSize: 24, fontWeight: '800', marginBottom: 44 },
  iconCircle: { width: 112, height: 112, borderRadius: 56, backgroundColor: '#E8F2E1', alignItems: 'center', justifyContent: 'center' },
  icon: { color: '#4B842F', fontSize: 58 },
  title: { color: '#27301F', fontSize: 26, fontWeight: '800', textAlign: 'center', marginTop: 28 },
  message: { color: '#667447', fontSize: 17, fontWeight: '700', textAlign: 'center', marginTop: 10 },
  detail: { color: '#667447', fontSize: 15, lineHeight: 22, textAlign: 'center', marginTop: 16, maxWidth: 470 },
  button: { minHeight: 56, width: '100%', maxWidth: 420, borderRadius: 15, backgroundColor: '#4B842F', alignItems: 'center', justifyContent: 'center', marginTop: 34 },
  buttonText: { color: '#FFFFFF', fontSize: 17, fontWeight: '700' },
});
