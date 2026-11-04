import { router, type Href } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { AuthBrand, AuthIcon, AuthScenery, authColors, authStyles } from '@/components/auth-ui';

import { FarmerAuthApiError } from '@/services/farmerAuthApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';

export default function LoginScreen() {
  const { width } = useWindowDimensions();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [emailError, setEmailError] = useState('');
  const [passwordError, setPasswordError] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [accountMessageType, setAccountMessageType] = useState<'error' | 'status'>('error');
  const [loading, setLoading] = useState(false);
  const { login } = useFarmerAuth();
  const formWidth = Math.min(width * 0.88, 440);

  async function handleLogin() {
    if (loading) return;
    const trimmedEmail = email.trim().toLowerCase();
    const isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail);
    if (!trimmedEmail) {
      setEmailError('Email is required.');
      setPasswordError(password.length === 0 ? 'Password is required.' : '');
      return;
    }
    if (!isValidEmail) {
      setEmailError('Enter a valid email address.');
      return;
    }
    if (!password) {
      setPasswordError('Password is required.');
      return;
    }
    setEmailError('');
    setPasswordError('');
    setAccountMessage('');
    setLoading(true);
    try {
      const result = await login(trimmedEmail, password);
      if (result.status === 'authenticated') {
        router.replace('/farmer-home');
      } else if (result.status === 'pending') {
        router.replace('/verification-pending');
      } else if (result.status === 'rejected') {
        setAccountMessageType('status');
        setAccountMessage(result.rejectionReason ? `${result.message} Reason: ${result.rejectionReason}` : result.message);
      } else {
        setAccountMessageType(result.status === 'invalid' ? 'error' : 'status');
        setAccountMessage(result.message);
      }
    } catch (error) {
      setAccountMessageType('error');
      setAccountMessage(error instanceof FarmerAuthApiError || error instanceof Error ? error.message : 'Login could not be completed. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar style="dark" />
      <KeyboardAvoidingView
        style={styles.keyboardView}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      >
        <ScrollView
          contentContainerStyle={[styles.content, { width: formWidth }]}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
          showsVerticalScrollIndicator={false}
        >
          <Pressable accessibilityRole="button" accessibilityLabel="Back to welcome" onPress={() => router.replace('/')} style={styles.backButton}>
            <AuthIcon name="back" />
          </Pressable>

          <View style={styles.formArea}>
            <AuthBrand />
            <Text style={styles.title}>Welcome back</Text>
            <Text style={styles.subtitle}>Log in to your account to continue{ '\n' }accessing AgriReach services.</Text>

            <View style={styles.fields}>
              <View>
                <Text style={styles.label}>Email</Text>
                <View style={[styles.inputShell, emailError && styles.inputError]}>
                  <AuthIcon name="mail" />
                  <TextInput
                    accessibilityLabel="Email"
                    value={email}
                    onChangeText={(value) => {
                      setEmail(value);
                      if (emailError) setEmailError('');
                      if (accountMessage) setAccountMessage('');
                    }}
                    placeholder="Enter your email address"
                    placeholderTextColor="#9AA08D"
                    keyboardType="email-address"
                    autoCapitalize="none"
                    autoCorrect={false}
                    textContentType="emailAddress"
                    style={styles.input}
                  />
                </View>
                {!!emailError && <Text style={styles.errorText}>{emailError}</Text>}
              </View>

              <View>
                <Text style={styles.label}>Password</Text>
                <View style={[styles.inputShell, passwordError && styles.inputError]}>
                  <AuthIcon name="lock" />
                  <TextInput
                    accessibilityLabel="Password"
                    value={password}
                    onChangeText={(value) => {
                      setPassword(value);
                      if (passwordError) setPasswordError('');
                      if (accountMessage) setAccountMessage('');
                    }}
                    placeholder="Enter your password"
                    returnKeyType="go"
                    onSubmitEditing={handleLogin}
                    placeholderTextColor="#9AA08D"
                    secureTextEntry={!passwordVisible}
                    autoCapitalize="none"
                    autoCorrect={false}
                    textContentType="password"
                    style={styles.input}
                  />
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={passwordVisible ? 'Hide password' : 'Show password'}
                    accessibilityState={{ selected: passwordVisible }}
                    hitSlop={10}
                    onPress={() => setPasswordVisible((visible) => !visible)}
                    style={styles.visibilityButton}
                  >
                    <AuthIcon name={passwordVisible ? 'eyeOff' : 'eye'} />
                  </Pressable>
                </View>
                {!!passwordError && <Text style={styles.errorText}>{passwordError}</Text>}
              </View>
            </View>

            <Pressable onPress={() => router.push('/forgot-password' as Href)} style={styles.forgotPasswordButton}>
              <Text style={styles.forgotPasswordText}>Forgot password?</Text>
            </Pressable>

            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: loading, busy: loading }}
              disabled={loading}
              onPress={handleLogin}
              style={({ pressed }) => [styles.loginButton, loading && styles.disabledButton, pressed && styles.pressed]}
            >
              {loading ? <ActivityIndicator color="#FFFFFF" /> : <><Text style={styles.loginButtonText}>Login</Text><AuthIcon name="arrow" color={authColors.paper} /></>}
            </Pressable>

            {!!accountMessage && <Text style={[styles.accountMessage, accountMessageType === 'status' && styles.statusMessage]}>{accountMessage}</Text>}

            <View style={styles.divider}><View style={styles.rule} /><Text style={styles.dividerText}>or</Text><View style={styles.rule} /></View>
            <Pressable accessibilityRole="button" onPress={() => router.push('/register')} style={styles.registerLinkButton}>
              <Text style={styles.registerLink}>Create account</Text><AuthIcon name="arrow" />
            </Pressable>
            <View style={styles.approval}><AuthIcon name="clock" color={authColors.gold} size={25} /><View style={styles.approvalCopy}><Text style={styles.approvalTitle}>Account approval required</Text><Text style={styles.approvalText}>New accounts are reviewed by the agriculture team. You can log in once your registration is approved.</Text></View></View>
          </View>
          <AuthScenery height={100} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: authStyles.safeArea, keyboardView: authStyles.keyboardView, content: authStyles.content,
  backButton: authStyles.back, formArea: authStyles.form, title: authStyles.title, subtitle: authStyles.subtitle, label: authStyles.label,
  fields: { gap: 18 },
  inputShell: authStyles.inputShell,
  inputError: { borderColor: '#B85C5C' },
  input: authStyles.input,
  visibilityButton: { width: 38, minHeight: 48, alignItems: 'center', justifyContent: 'center', marginLeft: 4 },
  errorText: { color: '#B14545', fontSize: 13, marginTop: 6, marginLeft: 4 },
  forgotPasswordButton: { alignSelf: 'flex-end', paddingVertical: 10, paddingLeft: 14, marginTop: 2 },
  forgotPasswordText: { color: authColors.forest, fontSize: 14, fontWeight: '600', textDecorationLine: 'underline' },
  loginButton: { ...authStyles.primary, marginTop: 16 },
  loginButtonText: { color: '#FFFFFF', fontSize: 17, fontWeight: '700' },
  disabledButton: { opacity: 0.65 },
  accountMessage: { color: '#B14545', fontSize: 13, lineHeight: 19, textAlign: 'center', marginTop: 13 },
  statusMessage: { color: '#805A15', backgroundColor: '#FFF6DD', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10 },
  registerLinkButton: authStyles.secondary, registerLink: authStyles.secondaryText,
  divider: { flexDirection: 'row', gap: 12, alignItems: 'center', marginVertical: 20 }, rule: { flex: 1, height: 1, backgroundColor: authColors.border }, dividerText: { color: authColors.muted, fontSize: 14 },
  approval: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, backgroundColor: authColors.sage, borderRadius: 13, padding: 14, marginTop: 20 }, approvalCopy: { flex: 1, minWidth: 0 }, approvalTitle: { color: authColors.deep, fontSize: 13, fontWeight: '700' }, approvalText: { color: authColors.muted, fontSize: 12, lineHeight: 18, marginTop: 5 },
  pressed: { opacity: 0.78 },
});
