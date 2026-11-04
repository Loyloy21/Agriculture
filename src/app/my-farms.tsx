import * as Location from 'expo-location';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { archiveFarmerFarm, createFarmerFarm, FarmerDataApiError, listFarmerFarms, updateFarmerFarm, type CabadbaranBarangay, type FarmerFarm, type FarmInput } from '@/services/farmerDataApi';
import { AppScreen, Hero, Icon, ui } from '@/components/farm-ui';
import { useFarmerAuth } from '@/state/farmerAuthContext';

const GREEN = '#174D36';
const TEXT = '#26301F';
const BORDER = '#D8E1D1';
const CATEGORIES: FarmerFarm['agriculturalCategory'][] = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
type FarmForm = { name: string; category: FarmerFarm['agriculturalCategory'] | ''; barangayCode: string; address: string; landArea: string; mainProduct: string; latitude: string; longitude: string };
const emptyForm = (): FarmForm => ({ name: '', category: '', barangayCode: '', address: '', landArea: '', mainProduct: '', latitude: '', longitude: '' });

export default function MyFarmsScreen() {
  const { refreshSession } = useFarmerAuth();
  const [farms, setFarms] = useState<FarmerFarm[]>([]);
  const [barangays, setBarangays] = useState<CabadbaranBarangay[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [editorOpen, setEditorOpen] = useState(false);
  const [editingFarm, setEditingFarm] = useState<FarmerFarm | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setLoadError('');
    try { const result = await listFarmerFarms(); setFarms(result.farms); setBarangays(result.barangays); }
    catch (error) { setFarms([]); setLoadError(error instanceof Error ? error.message : 'Farms could not be loaded.'); }
    finally { setLoading(false); }
  }, []);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  function openAdd() { setEditingFarm(null); setEditorOpen(true); }
  function openEdit(farm: FarmerFarm) { setEditingFarm(farm); setEditorOpen(true); }
  function confirmArchive(farm: FarmerFarm) {
    Alert.alert('Archive farm?', `${farm.name} will no longer be available for new damage reports. Existing reports will keep their farm information.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Archive', style: 'destructive', onPress: () => void (async () => {
        try { await archiveFarmerFarm(farm.id); await load(); await refreshSession(); }
        catch (error) { Alert.alert('Farm not archived', error instanceof Error ? error.message : 'Please try again.'); }
      })() },
    ]);
  }

  return <AppScreen back>
    <ScrollView contentContainerStyle={ui.content}>
      <View style={styles.contentWidth}>
        <Hero kind="profile" compact title="My Farms" subtitle="Your registered Cabadbaran farms." />
        <View style={styles.titleRow}><View style={styles.titleCopy}><Text style={styles.title}>My Farms</Text><Text style={styles.subtitle}>Manage the farms registered to your account.</Text></View><Pressable onPress={openAdd} style={styles.addButton}><Text style={styles.addButtonText}>+ Add Farm</Text></Pressable></View>
        {loading ? <ActivityIndicator color={GREEN} size="large" style={styles.loader} /> : null}
        {!loading && loadError ? <View style={styles.stateCard}><Text style={styles.error}>{loadError}</Text><Pressable onPress={() => void load()} style={styles.retry}><Text style={styles.retryText}>Try Again</Text></Pressable></View> : null}
        {!loading && !loadError && farms.length === 0 ? <View style={styles.stateCard}><Text style={styles.emptyTitle}>No farms yet</Text><Text style={styles.emptyText}>Add your first Cabadbaran City farm to submit farm-specific damage reports.</Text><Pressable onPress={openAdd} style={styles.primaryWide}><Text style={styles.primaryText}>Add Farm</Text></Pressable></View> : null}
        {!loading && !loadError ? farms.map((farm) => <View key={farm.id} style={[styles.farmCard, farm.status === 'Archived' && styles.archivedCard]}>
          <View style={styles.farmHeading}><View style={styles.farmHeadingCopy}><Text style={styles.farmName}>{farm.name}</Text><Text style={styles.farmCategory}>{farm.agriculturalCategory}{farm.isPrimary ? ' · Primary farm' : ''}</Text></View><Text style={[styles.status, farm.status === 'Archived' && styles.archivedStatus]}>{farm.status}</Text></View>
          <Info label="Barangay" value={farm.barangayName || 'Needs review'} /><Info label="Address / landmark" value={farm.address} /><Info label="Land area" value={farm.landArea === null ? 'Not provided' : `${farm.landArea} hectares`} /><Info label="Map location" value={farm.latitude === null ? 'Not set' : `${farm.latitude.toFixed(6)}, ${farm.longitude?.toFixed(6)}`} />
          {farm.status === 'Active' ? <View style={styles.actions}><Pressable onPress={() => openEdit(farm)} style={styles.secondary}><Text style={styles.secondaryText}>View / Edit</Text></Pressable><Pressable onPress={() => confirmArchive(farm)} style={styles.archive}><Text style={styles.archiveText}>Archive</Text></Pressable></View> : <Text style={styles.archiveNote}>Archived farms remain linked to their existing reports.</Text>}
        </View>) : null}
      </View>
    </ScrollView>
    <FarmEditor visible={editorOpen} farm={editingFarm} barangays={barangays} onClose={() => setEditorOpen(false)} onSaved={async () => { setEditorOpen(false); await load(); await refreshSession(); }} />
  </AppScreen>;
}

function Info({ label, value }: { label: string; value: string }) { return <View style={styles.infoRow}><Text style={styles.infoLabel}>{label}</Text><Text style={styles.infoValue}>{value}</Text></View>; }

function FarmEditor({ visible, farm, barangays, onClose, onSaved }: { visible: boolean; farm: FarmerFarm | null; barangays: CabadbaranBarangay[]; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState<FarmForm>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [gpsBusy, setGpsBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [picker, setPicker] = useState<'category' | 'barangay' | null>(null);
  const reset = useCallback(() => {
    setForm(farm ? { name: farm.name, category: farm.agriculturalCategory, barangayCode: farm.barangayCode || '', address: farm.address, landArea: farm.landArea === null ? '' : String(farm.landArea), mainProduct: farm.mainProduct, latitude: farm.latitude === null ? '' : String(farm.latitude), longitude: farm.longitude === null ? '' : String(farm.longitude) } : emptyForm());
    setErrors({}); setMessage(''); setPicker(null);
  }, [farm]);
  const close = () => { if (!busy) onClose(); };
  function change(field: keyof FarmForm, value: string) { setForm((current) => ({ ...current, [field]: value })); setErrors((current) => ({ ...current, [field]: '', location: '' })); }
  function validate(): FarmInput | null {
    const next: Record<string, string> = {};
    if (form.name.trim().length < 2 || !/[A-Za-z]/.test(form.name)) next.name = 'Enter a valid farm name.';
    if (!form.category) next.agriculturalCategory = 'Select an agricultural category.';
    if (!barangays.some((item) => item.code === form.barangayCode)) next.barangayCode = 'Select a Cabadbaran City barangay.';
    if (form.address.trim().length < 5 || !/[A-Za-z]/.test(form.address)) next.address = 'Enter a clear address or landmark.';
    if (!/^\d+(?:\.\d+)?$/.test(form.landArea) || Number(form.landArea) <= 0) next.landArea = 'Enter a land area greater than zero.';
    const hasLat = form.latitude.trim() !== ''; const hasLng = form.longitude.trim() !== '';
    if (hasLat !== hasLng) next.location = 'Provide both coordinates, or leave both blank.';
    if (hasLat && (Number(form.latitude) < -90 || Number(form.latitude) > 90)) next.latitude = 'Enter a valid latitude.';
    if (hasLng && (Number(form.longitude) < -180 || Number(form.longitude) > 180)) next.longitude = 'Enter a valid longitude.';
    setErrors(next); if (Object.keys(next).length || !form.category) return null;
    return { name: form.name.trim(), type: form.category, agriculturalCategory: form.category, barangayCode: form.barangayCode, address: form.address.trim(), landArea: Number(form.landArea), landAreaUnit: 'hectares', mainProduct: form.mainProduct.trim(), latitude: hasLat ? Number(form.latitude) : null, longitude: hasLng ? Number(form.longitude) : null };
  }
  async function useLocation() {
    setGpsBusy(true); setMessage('Getting location…');
    try {
      if (!await Location.hasServicesEnabledAsync()) { setMessage('Location services are off. Coordinates are optional.'); return; }
      let permission = await Location.getForegroundPermissionsAsync(); if (permission.status !== 'granted') permission = await Location.requestForegroundPermissionsAsync();
      if (permission.status !== 'granted') { setMessage('Location permission was not granted. Coordinates are optional.'); return; }
      const result = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      change('latitude', result.coords.latitude.toFixed(7)); change('longitude', result.coords.longitude.toFixed(7)); setMessage('Map location captured.');
    } catch { setMessage('Location is unavailable. You can save the farm without coordinates.'); }
    finally { setGpsBusy(false); }
  }
  async function save() {
    if (busy) return; const payload = validate(); if (!payload) return;
    setBusy(true); setMessage('');
    try { if (farm) await updateFarmerFarm(farm.id, payload); else await createFarmerFarm(payload); await onSaved(); }
    catch (error) { if (error instanceof FarmerDataApiError) setErrors((current) => ({ ...current, ...error.errors })); setMessage(error instanceof Error ? error.message : 'The farm could not be saved.'); }
    finally { setBusy(false); }
  }
  return <Modal visible={visible} transparent animationType="slide" onShow={reset} onRequestClose={close}>
    <KeyboardAvoidingView style={styles.modalBackdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}><View style={styles.modalCard}>
      <View style={styles.modalHeader}><View><Text style={styles.modalTitle}>{farm ? 'View / Edit Farm' : 'Add Farm'}</Text><Text style={styles.modalSubtitle}>Cabadbaran City, Agusan del Norte</Text></View><Pressable onPress={close} hitSlop={10}><Text style={styles.close}>×</Text></Pressable></View>
      <ScrollView style={styles.modalBody} contentContainerStyle={styles.modalBodyContent} keyboardShouldPersistTaps="handled">
        <Field label="Farm Name" value={form.name} onChangeText={(v) => change('name', v)} error={errors.name} />
        <PickerField label="Agricultural Category / Farm Type" value={form.category} placeholder="Select category" onPress={() => setPicker('category')} error={errors.agriculturalCategory || errors.type} />
        <PickerField label="Barangay" value={barangays.find((item) => item.code === form.barangayCode)?.name || ''} placeholder="Select Cabadbaran barangay" onPress={() => setPicker('barangay')} error={errors.barangayCode} />
        <Field label="Address or Landmark" value={form.address} onChangeText={(v) => change('address', v)} error={errors.address} multiline />
        <Field label="Land Area" value={form.landArea} onChangeText={(v) => change('landArea', v.replace(/[^0-9.]/g, ''))} error={errors.landArea} keyboardType="decimal-pad" suffix="hectares" />
        <Field label="Main Product (Optional)" value={form.mainProduct} onChangeText={(v) => change('mainProduct', v)} error={errors.mainProduct} />
        <Text style={styles.fieldLabel}>Optional Map Location</Text><Pressable onPress={() => void useLocation()} disabled={gpsBusy} style={styles.gpsButton}><Text style={styles.gpsText}>{gpsBusy ? 'Getting location…' : 'Use My GPS Location'}</Text></Pressable>
        <View style={styles.coordinateRow}><View style={styles.coordinate}><Field label="Latitude" value={form.latitude} onChangeText={(v) => change('latitude', v)} error={errors.latitude} keyboardType="numbers-and-punctuation" /></View><View style={styles.coordinate}><Field label="Longitude" value={form.longitude} onChangeText={(v) => change('longitude', v)} error={errors.longitude} keyboardType="numbers-and-punctuation" /></View></View>
        {errors.location ? <Text style={styles.error}>{errors.location}</Text> : null}{message ? <Text style={styles.message}>{message}</Text> : null}
      </ScrollView>
      <View style={styles.modalFooter}><Pressable onPress={close} disabled={busy} style={styles.cancelButton}><Text style={styles.cancelText}>Cancel</Text></Pressable><Pressable onPress={() => void save()} disabled={busy} style={[styles.saveButton, busy && styles.disabled]}><Text style={styles.primaryText}>{busy ? 'Saving…' : 'Save Farm'}</Text></Pressable></View>
    </View></KeyboardAvoidingView>
    <Modal visible={picker !== null} transparent animationType="fade" onRequestClose={() => setPicker(null)}><Pressable style={styles.pickerBackdrop} onPress={() => setPicker(null)}><View style={styles.pickerCard}><Text style={styles.pickerTitle}>{picker === 'category' ? 'Agricultural Category' : 'Cabadbaran Barangay'}</Text><ScrollView>{(picker === 'category' ? CATEGORIES.map((name) => ({ code: name, name })) : barangays).map((item) => <Pressable key={item.code} onPress={() => { if (picker === 'category') change('category', item.name as FarmerFarm['agriculturalCategory']); else change('barangayCode', item.code); setPicker(null); }} style={styles.pickerOption}><Text style={styles.pickerOptionText}>{item.name}</Text></Pressable>)}</ScrollView></View></Pressable></Modal>
  </Modal>;
}

function Field({ label, error, suffix, multiline, ...props }: { label: string; error?: string; suffix?: string; multiline?: boolean } & React.ComponentProps<typeof TextInput>) { return <View style={styles.field}><Text style={styles.fieldLabel}>{label}</Text><View style={[styles.inputWrap, error && styles.inputError]}><TextInput {...props} multiline={multiline} style={[styles.input, multiline && styles.multiline]} placeholderTextColor="#97A08F" />{suffix ? <Text style={styles.suffix}>{suffix}</Text> : null}</View>{error ? <Text style={styles.error}>{error}</Text> : null}</View>; }
function PickerField({ label, value, placeholder, onPress, error }: { label: string; value: string; placeholder: string; onPress: () => void; error?: string }) { return <View style={styles.field}><Text style={styles.fieldLabel}>{label}</Text><Pressable onPress={onPress} style={[styles.pickerField, error && styles.inputError]}><Text style={value ? styles.pickerValue : styles.placeholder}>{value || placeholder}</Text><Text>⌄</Text></Pressable>{error ? <Text style={styles.error}>{error}</Text> : null}</View>; }

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#F8FAF5' }, header: { height: 58, backgroundColor: GREEN, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 18 }, back: { color: '#FFF', fontSize: 16, minWidth: 70 }, brand: { color: '#FFF', fontSize: 20, fontWeight: '800', flex: 1, textAlign: 'center' }, headerSpacer: { width: 70 }, content: { padding: 18, paddingBottom: 40, alignItems: 'center' }, contentWidth: { width: '100%', maxWidth: 720 }, titleRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 18 }, titleCopy: { flex: 1 }, title: { color: TEXT, fontSize: 28, fontWeight: '800' }, subtitle: { color: '#66705F', marginTop: 4 }, addButton: { backgroundColor: GREEN, paddingHorizontal: 15, paddingVertical: 12, borderRadius: 12 }, addButtonText: { color: '#FFF', fontWeight: '700' }, loader: { marginTop: 50 }, stateCard: { backgroundColor: '#FFF', borderWidth: 1, borderColor: BORDER, borderRadius: 18, padding: 24, alignItems: 'center' }, emptyTitle: { fontSize: 19, fontWeight: '800', color: TEXT }, emptyText: { color: '#6A7363', textAlign: 'center', marginVertical: 10, lineHeight: 20 }, primaryWide: { marginTop: 8, backgroundColor: GREEN, paddingHorizontal: 28, paddingVertical: 13, borderRadius: 12 }, primaryText: { color: '#FFF', fontWeight: '800' }, retry: { marginTop: 12, borderWidth: 1, borderColor: GREEN, paddingHorizontal: 18, paddingVertical: 10, borderRadius: 10 }, retryText: { color: GREEN, fontWeight: '700' }, farmCard: { backgroundColor: '#FFF', borderWidth: 1, borderColor: BORDER, borderRadius: 18, padding: 17, marginBottom: 13 }, archivedCard: { opacity: 0.7 }, farmHeading: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 12 }, farmHeadingCopy: { flex: 1 }, farmName: { color: TEXT, fontWeight: '800', fontSize: 19 }, farmCategory: { color: GREEN, marginTop: 3, fontWeight: '600' }, status: { color: GREEN, backgroundColor: '#E8F3E2', paddingHorizontal: 10, paddingVertical: 5, borderRadius: 20, fontSize: 12, fontWeight: '700' }, archivedStatus: { color: '#6B7167', backgroundColor: '#ECEEEA' }, infoRow: { flexDirection: 'row', gap: 12, paddingVertical: 8, borderTopWidth: 1, borderColor: '#EEF1EA' }, infoLabel: { width: 116, color: '#70786A', fontSize: 13 }, infoValue: { flex: 1, color: TEXT, fontSize: 14 }, actions: { flexDirection: 'row', gap: 10, marginTop: 14 }, secondary: { flex: 1, borderWidth: 1, borderColor: GREEN, padding: 11, borderRadius: 10, alignItems: 'center' }, secondaryText: { color: GREEN, fontWeight: '700' }, archive: { paddingHorizontal: 16, paddingVertical: 11, borderRadius: 10, backgroundColor: '#FFF0ED' }, archiveText: { color: '#B44937', fontWeight: '700' }, archiveNote: { color: '#737A6E', marginTop: 12, fontStyle: 'italic' },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(20,30,15,.52)', justifyContent: 'center', padding: 14 }, modalCard: { maxHeight: '92%', width: '100%', maxWidth: 680, alignSelf: 'center', backgroundColor: '#FFF', borderRadius: 20, overflow: 'hidden' }, modalHeader: { flexDirection: 'row', alignItems: 'center', padding: 18, borderBottomWidth: 1, borderColor: BORDER }, modalTitle: { color: TEXT, fontSize: 21, fontWeight: '800' }, modalSubtitle: { color: '#6B7465', marginTop: 2 }, close: { fontSize: 30, color: '#6B7465', paddingLeft: 18 }, modalBody: { flexShrink: 1 }, modalBodyContent: { padding: 18 }, modalFooter: { flexDirection: 'row', gap: 10, padding: 14, borderTopWidth: 1, borderColor: BORDER }, cancelButton: { flex: 1, backgroundColor: '#E9ECE7', padding: 13, borderRadius: 11, alignItems: 'center' }, cancelText: { color: TEXT, fontWeight: '700' }, saveButton: { flex: 1, backgroundColor: GREEN, padding: 13, borderRadius: 11, alignItems: 'center' }, disabled: { opacity: 0.6 }, field: { marginBottom: 14 }, fieldLabel: { color: TEXT, fontWeight: '700', marginBottom: 7 }, inputWrap: { minHeight: 48, borderWidth: 1, borderColor: BORDER, borderRadius: 11, flexDirection: 'row', alignItems: 'center', backgroundColor: '#FCFDFB' }, input: { flex: 1, color: TEXT, paddingHorizontal: 13, paddingVertical: 11 }, multiline: { minHeight: 72, textAlignVertical: 'top' }, suffix: { color: '#70786A', paddingRight: 12 }, inputError: { borderColor: '#C94D3D' }, error: { color: '#B93F31', fontSize: 12, marginTop: 5 }, message: { color: '#5E694F', marginBottom: 12 }, pickerField: { minHeight: 48, borderWidth: 1, borderColor: BORDER, borderRadius: 11, paddingHorizontal: 13, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: '#FCFDFB' }, pickerValue: { color: TEXT }, placeholder: { color: '#97A08F' }, gpsButton: { borderWidth: 1, borderColor: GREEN, borderRadius: 11, padding: 12, alignItems: 'center', marginBottom: 12 }, gpsText: { color: GREEN, fontWeight: '700' }, coordinateRow: { flexDirection: 'row', gap: 10 }, coordinate: { flex: 1 }, pickerBackdrop: { flex: 1, backgroundColor: 'rgba(20,30,15,.55)', justifyContent: 'center', padding: 20 }, pickerCard: { maxHeight: '75%', width: '100%', maxWidth: 520, alignSelf: 'center', backgroundColor: '#FFF', borderRadius: 18, padding: 14 }, pickerTitle: { fontSize: 19, fontWeight: '800', color: TEXT, padding: 8 }, pickerOption: { padding: 14, borderTopWidth: 1, borderColor: '#EEF1EA' }, pickerOptionText: { color: TEXT, fontSize: 16 },
});
