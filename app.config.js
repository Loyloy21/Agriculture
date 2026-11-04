// The Android Maps SDK key belongs to the EAS development environment (Sensitive
// visibility so EAS CLI can resolve the config plugin), never source control.
// This configuration leaves the existing iOS Expo settings untouched.
// `config` is the resolved app.json "expo" object, passed in by Expo.
const androidMapsKey = process.env.AGRIREACH_ANDROID_MAPS_API_KEY?.trim();

// Release builds (EAS "preview" or "production" profile) must call the API over public HTTPS. The address comes from
// the EAS environment (EXPO_PUBLIC_API_URL), never from a developer's .env.local tunnel or localhost.
const releaseProfile = ['preview', 'production'].includes(process.env.EAS_BUILD_PROFILE ?? '');
if (releaseProfile) {
  const apiUrl = process.env.EXPO_PUBLIC_API_URL?.trim() ?? '';
  let problem = '';
  try {
    const url = new URL(apiUrl);
    const host = url.hostname.toLowerCase();
    if (url.protocol !== 'https:') problem = 'must use https';
    else if (/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/.test(host) || host.endsWith('.local') || host.endsWith('.trycloudflare.com') || !host.includes('.')) problem = 'must be a public, permanent host';
  } catch {
    problem = 'is missing or not a valid URL';
  }
  if (problem) throw new Error(`EXPO_PUBLIC_API_URL for the ${process.env.EAS_BUILD_PROFILE} build ${problem} (set it in the EAS environment, e.g. https://<your-domain>/<path>/api).`);
}

module.exports = ({ config }) => ({
  ...config,
  plugins: [
    ...(config.plugins ?? []),
    ...(androidMapsKey ? [['react-native-maps', { androidGoogleMapsApiKey: androidMapsKey }]] : []),
  ],
  extra: {
    ...config.extra,
    androidMapsKeyConfigured: Boolean(androidMapsKey),
  },
});
