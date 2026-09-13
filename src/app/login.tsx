import { Link } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function LoginScreen() {
  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.content}>
        <Text style={styles.title}>Login</Text>
        <Text style={styles.subtitle}>Login screen placeholder</Text>
        <Link href="/" style={styles.link}>Back to AgriReach</Link>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#4B842F' },
  content: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  title: { color: '#FFFFFF', fontSize: 32, fontWeight: '800' },
  subtitle: { color: '#FFFFFF', marginTop: 10, fontSize: 16 },
  link: { color: '#FFFFFF', marginTop: 24, textDecorationLine: 'underline' },
});
