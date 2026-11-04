import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { AuthBrand, AuthIcon, AuthScenery, authColors, authStyles } from '@/components/auth-ui';
import { completePasswordReset, PasswordResetApiError, requestPasswordResetCode, verifyPasswordResetCode } from '@/services/passwordResetApi';

// One screen, four steps: the farmer types the emailed code here, so no app deep link is needed.
type Step = 'email' | 'code' | 'password' | 'done';
const CODE_LENGTH = 6;
const PASSWORD_RULE = 'Use at least 8 characters with uppercase, lowercase, and a number.';

function waitLabel(seconds: number) {
  return seconds > 90 ? `${Math.ceil(seconds / 60)} min` : `${seconds}s`;
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof PasswordResetApiError || error instanceof Error ? error.message : fallback;
}

export default function ForgotPasswordScreen() {
  const { width } = useWindowDimensions();
  const [step, setStep] = useState<Step>('email');
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [resetToken, setResetToken] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [confirmVisible, setConfirmVisible] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(false);
  const [resendSeconds, setResendSeconds] = useState(0);
  const contentWidth = Math.min(width * 0.88, 440);

  useEffect(() => {
    if (resendSeconds <= 0) return undefined;
    const timer = setTimeout(() => setResendSeconds((seconds) => Math.max(0, seconds - 1)), 1000);
    return () => clearTimeout(timer);
  }, [resendSeconds]);

  const normalizedEmail = email.trim().toLowerCase();
  function emailProblem() {
    if (!normalizedEmail) return 'Email is required.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) return 'Enter a valid email address.';
    return '';
  }

  async function sendCode(resend = false) {
    if (loading) return;
    const problem = emailProblem();
    if (problem) { setErrors({ email: problem }); setStep('email'); return; }
    setErrors({}); setNotice(''); setLoading(true);
    try {
      const result = await requestPasswordResetCode(normalizedEmail);
      setResendSeconds(result.resendAfterSeconds);
      setCode('');
      setNotice(resend ? 'If an AgriReach farmer account uses this email, a new code was sent. Only the newest code works.' : result.message);
      setStep('code');
    } catch (error) {
      if (error instanceof PasswordResetApiError && error.code === 'cooldown') {
        // A code was requested moments ago for this email; let the farmer enter it.
        setResendSeconds(error.retryAfterSeconds || 60);
        setNotice(`A code was requested recently. Enter it below, or request another in ${error.retryAfterSeconds || 60} seconds.`);
        setStep('code');
      } else {
        if (error instanceof PasswordResetApiError && error.retryAfterSeconds) setResendSeconds(error.retryAfterSeconds);
        setErrors({ [step === 'email' ? 'email' : 'code']: errorMessage(error, 'The code could not be requested. Please try again.') });
      }
    } finally {
      setLoading(false);
    }
  }

  async function verifyCode() {
    if (loading) return;
    if (code.length !== CODE_LENGTH) { setErrors({ code: `Enter the ${CODE_LENGTH}-digit code from the email.` }); return; }
    setErrors({}); setLoading(true);
    try {
      const result = await verifyPasswordResetCode(normalizedEmail, code);
      setResetToken(result.resetToken);
      setCode('');
      setNotice('');
      setStep('password');
    } catch (error) {
      setErrors({ code: errorMessage(error, 'The code could not be checked. Please try again.') });
    } finally {
      setLoading(false);
    }
  }

  async function savePassword() {
    if (loading) return;
    const nextErrors: Record<string, string> = {};
    if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/\d/.test(password)) nextErrors.password = PASSWORD_RULE;
    if (!confirmPassword) nextErrors.confirmPassword = 'Please confirm your new password.';
    else if (password !== confirmPassword) nextErrors.confirmPassword = 'Passwords do not match.';
    if (Object.keys(nextErrors).length) { setErrors(nextErrors); return; }
    setErrors({}); setLoading(true);
    try {
      const message = await completePasswordReset(resetToken, password, confirmPassword);
      setResetToken(''); setPassword(''); setConfirmPassword('');
      setNotice(message);
      setStep('done');
    } catch (error) {
      if (error instanceof PasswordResetApiError && error.code === 'invalid_token') {
        // The 15-minute reset window passed or the code was replaced: start again from the email step.
        setResetToken(''); setPassword(''); setConfirmPassword('');
        setNotice(error.message);
        setStep('email');
      } else if (error instanceof PasswordResetApiError && Object.keys(error.fields).length) {
        setErrors(error.fields);
      } else {
        setErrors({ form: errorMessage(error, 'Your password could not be changed. Please try again.') });
      }
    } finally {
      setLoading(false);
    }
  }

  function startOver() {
    setStep('email'); setCode(''); setResetToken(''); setPassword(''); setConfirmPassword(''); setErrors({}); setNotice('');
  }

  const titles: Record<Step, [string, string]> = {
    email: ['Reset your password', 'Enter the email address you registered with. We will email you a 6-digit code.'],
    code: ['Enter your code', `Check the inbox (and spam folder) of ${normalizedEmail} for an AgriReach email with a 6-digit code. It expires in 10 minutes.`],
    password: ['Create a new password', 'Choose a new password for your AgriReach account. You will be signed out on every device.'],
    done: ['Password changed', 'You can now log in with your new password.'],
  };
  const [title, subtitle] = titles[step];

  return <SafeAreaView style={styles.safeArea}><StatusBar style="dark" /><KeyboardAvoidingView style={styles.keyboardView} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}><ScrollView contentContainerStyle={[styles.content, { width: contentWidth }]} keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'} showsVerticalScrollIndicator={false}>
    <Pressable accessibilityRole="button" accessibilityLabel="Go back" onPress={() => (step === 'email' || step === 'done' ? router.back() : startOver())} style={styles.backButton}><AuthIcon name="back" /></Pressable>
    <View style={styles.formArea}>
      <AuthBrand />
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.subtitle}>{subtitle}</Text>

      {step === 'email' ? <>
        <Text style={styles.label}>Email</Text>
        <View style={[styles.inputShell, errors.email && styles.inputError]}><AuthIcon name="mail" /><TextInput accessibilityLabel="Email" value={email} onChangeText={(value) => { setEmail(value); setErrors({}); setNotice(''); }} placeholder="Enter your email address" placeholderTextColor="#8C948A" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} textContentType="emailAddress" autoComplete="email" returnKeyType="send" onSubmitEditing={() => sendCode()} style={styles.input} /></View>
        {errors.email ? <Text style={styles.errorText}>{errors.email}</Text> : null}
        {notice ? <View style={styles.notice}><Text style={styles.noticeText}>{notice}</Text></View> : null}
        <Pressable accessibilityRole="button" accessibilityState={{ disabled: loading, busy: loading }} disabled={loading} onPress={() => sendCode()} style={[styles.primaryButton, loading && styles.disabledButton]}>{loading ? <ActivityIndicator color="#FFFFFF" /> : <><Text style={styles.primaryButtonText}>Send code</Text><AuthIcon name="arrow" color={authColors.paper} /></>}</Pressable>
        <Pressable accessibilityRole="button" onPress={() => { const problem = emailProblem(); if (problem) setErrors({ email: problem }); else { setErrors({}); setNotice(''); setStep('code'); } }} style={styles.secondaryLink}><Text style={styles.secondaryLinkText}>I already have a code</Text></Pressable>
      </> : null}

      {step === 'code' ? <>
        {notice ? <View style={[styles.notice, styles.noticeTop]}><Text style={styles.noticeText}>{notice}</Text></View> : null}
        <Text style={styles.label}>6-digit code</Text>
        <View style={[styles.inputShell, errors.code && styles.inputError]}><AuthIcon name="lock" /><TextInput accessibilityLabel="6-digit code" value={code} onChangeText={(value) => { setCode(value.replace(/\D/g, '').slice(0, CODE_LENGTH)); setErrors({}); }} placeholder="000000" placeholderTextColor="#B5BBAF" keyboardType="number-pad" inputMode="numeric" textContentType="oneTimeCode" autoComplete="one-time-code" maxLength={CODE_LENGTH} autoCorrect={false} returnKeyType="done" onSubmitEditing={verifyCode} style={[styles.input, styles.codeInput]} /></View>
        {errors.code ? <Text style={styles.errorText}>{errors.code}</Text> : null}
        <Pressable accessibilityRole="button" accessibilityState={{ disabled: loading, busy: loading }} disabled={loading} onPress={verifyCode} style={[styles.primaryButton, loading && styles.disabledButton]}>{loading ? <ActivityIndicator color="#FFFFFF" /> : <><Text style={styles.primaryButtonText}>Verify code</Text><AuthIcon name="arrow" color={authColors.paper} /></>}</Pressable>
        <Pressable accessibilityRole="button" accessibilityState={{ disabled: loading || resendSeconds > 0 }} disabled={loading || resendSeconds > 0} onPress={() => sendCode(true)} style={[styles.secondaryButton, (loading || resendSeconds > 0) && styles.disabledButton]}><Text style={styles.secondaryButtonText}>{resendSeconds > 0 ? `Resend code in ${waitLabel(resendSeconds)}` : 'Resend code'}</Text></Pressable>
        <Pressable accessibilityRole="button" onPress={startOver} style={styles.secondaryLink}><Text style={styles.secondaryLinkText}>Use a different email</Text></Pressable>
      </> : null}

      {step === 'password' ? <>
        <PasswordField label="New Password" value={password} visible={passwordVisible} error={errors.password} onToggle={() => setPasswordVisible((visible) => !visible)} onChangeText={(value) => { setPassword(value); setErrors((current) => ({ ...current, password: '', form: '' })); }} />
        <Text style={styles.hint}>{PASSWORD_RULE}</Text>
        <PasswordField label="Confirm New Password" value={confirmPassword} visible={confirmVisible} error={errors.confirmPassword} onToggle={() => setConfirmVisible((visible) => !visible)} onChangeText={(value) => { setConfirmPassword(value); setErrors((current) => ({ ...current, confirmPassword: '', form: '' })); }} onSubmitEditing={savePassword} />
        {errors.form ? <Text style={styles.errorText}>{errors.form}</Text> : null}
        <Pressable accessibilityRole="button" accessibilityState={{ disabled: loading, busy: loading }} disabled={loading} onPress={savePassword} style={[styles.primaryButton, loading && styles.disabledButton]}>{loading ? <ActivityIndicator color="#FFFFFF" /> : <><Text style={styles.primaryButtonText}>Set new password</Text><AuthIcon name="arrow" color={authColors.paper} /></>}</Pressable>
      </> : null}

      {step === 'done' ? <>
        {notice ? <View style={styles.notice}><Text style={styles.noticeText}>{notice}</Text></View> : null}
        <Pressable accessibilityRole="button" onPress={() => router.replace('/login')} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Back to login</Text><AuthIcon name="arrow" color={authColors.paper} /></Pressable>
      </> : null}

      {step !== 'done' ? <Pressable accessibilityRole="button" onPress={() => router.replace('/login')} style={styles.backLogin}><AuthIcon name="back" /><Text style={styles.backLoginText}>Back to login</Text></Pressable> : null}
    </View>
    <AuthScenery height={190} />
  </ScrollView></KeyboardAvoidingView></SafeAreaView>;
}

function PasswordField({ label, value, visible, error, onToggle, onChangeText, onSubmitEditing }: { label: string; value: string; visible: boolean; error?: string; onToggle: () => void; onChangeText: (value: string) => void; onSubmitEditing?: () => void }) {
  return <View style={styles.field}>
    <Text style={styles.label}>{label}</Text>
    <View style={[styles.inputShell, error && styles.inputError]}>
      <AuthIcon name="lock" />
      {/* Autofill stays off, as on registration, so the exact typed password is what gets saved. */}
      <TextInput accessibilityLabel={label} value={value} onChangeText={onChangeText} placeholder={label} placeholderTextColor="#8C948A" secureTextEntry={!visible} autoCapitalize="none" autoCorrect={false} textContentType="none" autoComplete="off" returnKeyType={onSubmitEditing ? 'done' : 'next'} onSubmitEditing={onSubmitEditing} style={styles.input} />
      <Pressable accessibilityRole="button" accessibilityLabel={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`} accessibilityState={{ selected: visible }} hitSlop={10} onPress={onToggle} style={styles.visibilityButton}><AuthIcon name={visible ? 'eyeOff' : 'eye'} /></Pressable>
    </View>
    {error ? <Text style={styles.errorText}>{error}</Text> : null}
  </View>;
}

const styles = StyleSheet.create({
  safeArea: authStyles.safeArea, keyboardView: authStyles.keyboardView, content: authStyles.content, backButton: authStyles.back, formArea: authStyles.form,
  title: authStyles.title, subtitle: authStyles.subtitle, label: authStyles.label, inputShell: authStyles.inputShell, input: authStyles.input,
  codeInput: { fontSize: 24, letterSpacing: 8, fontWeight: '700' }, field: { marginBottom: 16 },
  inputError: { borderColor: '#A83F35' }, errorText: authStyles.error,
  visibilityButton: { width: 38, minHeight: 48, alignItems: 'center', justifyContent: 'center', marginLeft: 4 },
  hint: { color: authColors.muted, fontSize: 12, lineHeight: 17, marginTop: -8, marginBottom: 16, marginLeft: 3 },
  notice: { backgroundColor: authColors.sage, borderRadius: 12, padding: 13, marginTop: 16 }, noticeTop: { marginTop: 0, marginBottom: 16 }, noticeText: { color: authColors.deep, fontSize: 13, lineHeight: 19 },
  primaryButton: { ...authStyles.primary, marginTop: 20 }, primaryButtonText: authStyles.primaryText, disabledButton: { opacity: .65 },
  secondaryButton: { ...authStyles.secondary, marginTop: 12 }, secondaryButtonText: authStyles.secondaryText,
  backLogin: { ...authStyles.secondary, marginTop: 12 }, backLoginText: authStyles.secondaryText,
  secondaryLink: { alignSelf: 'center', padding: 14, marginTop: 4 }, secondaryLinkText: { color: authColors.forest, fontSize: 13, fontWeight: '600' },
});
