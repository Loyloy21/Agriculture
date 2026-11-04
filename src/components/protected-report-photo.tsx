import { Image } from 'expo-image';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { getAuthenticatedImageSource } from '@/services/farmerDataApi';
import { Icon, palette, ui } from './farm-ui';

export default function ProtectedReportPhoto({ uri, height = 190, contain = false, compact = false, onPress }: { uri: string; height?: number; contain?: boolean; compact?: boolean; onPress?: () => void }) {
  const [source, setSource] = useState<Awaited<ReturnType<typeof getAuthenticatedImageSource>> | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const revision = useRef(0);
  useEffect(() => {
    let active = true; const request = ++revision.current;
    setSource(null); setState('loading'); setMessage('');
    void getAuthenticatedImageSource(uri).then(value => {
      if (!active) return;
      if (!value.uri) { setState('error'); setMessage('Sign in again to view this protected photo.'); }
      else setSource(value);
    }).catch(() => { if (active) { setState('error'); setMessage('The photo server is not configured or cannot be reached.'); } });
    const timer = setTimeout(() => { if (active && request === revision.current) { setState(current => current === 'ready' ? current : 'error'); setMessage('Photo loading timed out. Check your connection and retry.'); } }, 20000);
    return () => { active = false; clearTimeout(timer); revision.current++; };
  }, [uri, attempt]);
  async function diagnose() {
    if (!source?.uri) return;
    const request = revision.current;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    setState('error'); setMessage('Photo could not be loaded. Check your connection and retry.');
    try {
      const response = await fetch(source.uri, { headers: source.headers, signal: controller.signal, cache: 'no-store' });
      if (request !== revision.current) return;
      setMessage(response.status === 404 ? 'The saved photo file is unavailable on the server. Its report and metadata are preserved.' :
        response.status === 401 || response.status === 403 ? 'This photo requires your current account session. Sign in again and retry.' :
        response.ok ? 'The photo was reached but could not be displayed. Retry loading it.' : 'The photo server returned HTTP ' + response.status + '. Please retry.');
    } catch { if (request === revision.current) setMessage('Cannot reach the photo server. Check your connection and active API tunnel, then retry.'); }
    finally { clearTimeout(timer); }
  }
  return <View style={{ height, width: '100%', backgroundColor: palette.sage, borderRadius: 12, overflow: 'hidden' }}>
    {source && state !== 'error' ? <Pressable accessibilityRole="button" accessibilityLabel="Open damage photo fullscreen" onPress={onPress} disabled={!onPress} style={{ flex: 1 }}>
      <Image key={uri + ':' + attempt} source={source} contentFit={contain ? 'contain' : 'cover'} cachePolicy="none" style={{ width: '100%', height: '100%' }} onLoad={() => { setState('ready'); setMessage(''); }} onError={() => void diagnose()} />
    </Pressable> : null}
    {state === 'loading' ? <View pointerEvents="none" style={{ position: 'absolute', inset: 0, alignItems: 'center', justifyContent: 'center', gap: 8 }}><ActivityIndicator color={palette.forest} />{!compact && <Text style={ui.small}>Loading protected photo…</Text>}</View> : null}
    {state === 'error' ? compact ? <View accessibilityLabel={message} style={{ flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 }}><Icon name="photo" size={22} /><Text style={{ color: palette.muted, fontSize: 10 }}>Unavailable</Text></View> : <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 16, gap: 8 }}><Text style={[ui.small, { textAlign: 'center' }]}>{message}</Text><Pressable accessibilityRole="button" onPress={() => setAttempt(value => value + 1)} style={{ minHeight: 44, justifyContent: 'center', paddingHorizontal: 20 }}><Text style={{ color: palette.forest, fontWeight: '700' }}>Retry photo</Text></Pressable></View> : null}
  </View>;
}
