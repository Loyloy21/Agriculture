import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function ReportSuccessScreen() {
  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.content}>
        <Text style={styles.brand}>AgriReach</Text>
        <View style={styles.successCircle}><Text style={styles.check}>✓</Text></View>
        <Text style={styles.title}>Report submitted successfully.</Text>
        <Text style={styles.message}>Your damage report has been submitted for review.</Text>
        <Pressable onPress={() => router.push('/my-reports')} style={styles.primaryButton}><Text style={styles.primaryText}>View My Reports</Text></Pressable>
        <Pressable onPress={() => router.replace('/farmer-home')} style={styles.secondaryButton}><Text style={styles.secondaryText}>Back to Home</Text></Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#FCFDF9' },
  content: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 28 },
  brand: { color: '#4B842F', fontSize: 24, fontWeight: '800', marginBottom: 44 },
  successCircle: { width: 112, height: 112, borderRadius: 56, backgroundColor: '#E8F2E1', alignItems: 'center', justifyContent: 'center' },
  check: { color: '#4B842F', fontSize: 58, fontWeight: '800' },
  title: { color: '#27301F', fontSize: 25, fontWeight: '800', textAlign: 'center', marginTop: 28 },
  message: { color: '#667447', fontSize: 16, textAlign: 'center', lineHeight: 22, marginTop: 12 },
  primaryButton: { minHeight: 56, width: '100%', maxWidth: 420, borderRadius: 15, backgroundColor: '#4B842F', alignItems: 'center', justifyContent: 'center', marginTop: 34 },
  primaryText: { color: '#FFFFFF', fontSize: 17, fontWeight: '700' },
  secondaryButton: { minHeight: 54, width: '100%', maxWidth: 420, borderRadius: 15, alignItems: 'center', justifyContent: 'center', marginTop: 10 },
  secondaryText: { color: '#4B842F', fontSize: 16, fontWeight: '700' },
});
