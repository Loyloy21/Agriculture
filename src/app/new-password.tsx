import { Redirect } from 'expo-router';

// The new password is now chosen inside the Forgot Password flow, right after the emailed code is
// verified (no reset links or deep links). This older route only forwards there.
export default function NewPasswordScreen() {
  return <Redirect href="/forgot-password" />;
}
