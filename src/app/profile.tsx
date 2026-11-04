import { router } from 'expo-router';
import * as Location from 'expo-location';
import { useEffect, useMemo, useState, type ComponentProps, type ReactNode } from 'react';
import { Alert, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { changeFarmerPassword, deleteFarmerFarm, FarmerDataApiError, saveFarmerFarm, updateFarmerProfile } from '@/services/farmerDataApi';
import { AppScreen, Hero, Icon, palette, ui, type IconName } from '@/components/farm-ui';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import { extensionNameError, middleNameError, normalizeNamePart } from '@/utils/farmerName';

type FarmerLanguage = 'English' | 'Filipino' | 'Cebuano';
type FarmerProfile = { id: string; firstName: string; middleName: string; lastName: string; extensionName: string; gender: string; dateOfBirth: string; email: string; phone: string; address: string };
type FarmerFarm = { id: string; farmName: string; farmType: string; farmLocation: string; farmSize: string; mainProduct: string; status: 'Active' | 'Pending' | 'Rejected' };

const GREEN = '#174D36';
const OLIVE = '#637068';
const emptyFarm = (): Omit<FarmerFarm, 'id' | 'status'> => ({ farmName: '', farmType: '', farmLocation: '', farmSize: '', mainProduct: '' });

export default function ProfileScreen() {
  const { farmer: activeFarmer, logout, refreshSession } = useFarmerAuth();
  const [editProfileOpen, setEditProfileOpen] = useState(false);
  const [farmEditorOpen, setFarmEditorOpen] = useState(false);
  const [languagePickerOpen, setLanguagePickerOpen] = useState(false);
  const [language, setLanguage] = useState<FarmerLanguage>('English');
  const [personalDetailsOpen, setPersonalDetailsOpen] = useState(false);
  const [actionError, setActionError] = useState('');
  const [logoutBusy, setLogoutBusy] = useState(false);
  const [logoutError, setLogoutError] = useState('');

  const profile = useMemo<FarmerProfile | null>(() => activeFarmer ? ({
    id: String(activeFarmer.id), firstName: activeFarmer.firstName, middleName: activeFarmer.middleName || '', lastName: activeFarmer.lastName, extensionName: activeFarmer.extensionName || '',
    gender: activeFarmer.gender, dateOfBirth: activeFarmer.dateOfBirth || '', email: activeFarmer.email,
    phone: activeFarmer.contactNumber, address: activeFarmer.address,
  }) : null, [activeFarmer]);
  const currentFarm = useMemo<FarmerFarm | null>(() => activeFarmer?.farm ? ({
    id: String(activeFarmer.farm.id), farmName: activeFarmer.farm.name, farmType: activeFarmer.farm.type,
    farmLocation: activeFarmer.farm.location, farmSize: activeFarmer.farm.sizeHectares === null ? '' : String(activeFarmer.farm.sizeHectares),
    mainProduct: activeFarmer.farm.mainProduct, status: 'Active',
  }) : null, [activeFarmer]);
  if (!activeFarmer) return null;

  async function handleLogout() {
    if (logoutBusy) return;
    setLogoutBusy(true);
    setLogoutError('');
    try {
      await logout();
      router.replace('/');
    } catch {
      setLogoutError('Unable to securely log out. Check your connection and try again.');
    } finally {
      setLogoutBusy(false);
    }
  }
  return (
    <AppScreen tab="profile">
      <ScrollView contentContainerStyle={ui.content} showsVerticalScrollIndicator={false}>
        <Hero kind="profile" compact title="Farmer Profile" subtitle="Manage your information and registered farms." />
        <View style={[ui.card, ui.row, { alignItems: 'flex-start', flexWrap: 'wrap' }]}>
          <View style={{ flex: 1, minWidth: 180 }}><Text style={[ui.cardTitle, { fontSize: 20 }]}>{activeFarmer.fullName}</Text>
            <View style={[ui.row, { marginTop: 6 }]}><Icon name="person" size={15} /><Text style={ui.small}>{activeFarmer.accountStatus} account</Text></View>
          </View>
          <Pressable accessibilityRole="button" onPress={() => setEditProfileOpen(true)} style={[styles.editButton, ui.row]}><Icon name="edit" size={16} /><Text style={styles.editButtonText}>Edit Profile</Text></Pressable>
        </View>
        <Section title="Personal Information">
          <InfoRow icon="phone" label="Phone Number" value={profile?.phone ?? ''} />
          <InfoRow icon="mail" label="Email" value={profile?.email ?? ''} />
          <InfoRow icon="pin" label="Residential Address" value={profile?.address ?? ''} last />
          <Pressable accessibilityRole="button" accessibilityState={{ expanded: personalDetailsOpen }} onPress={() => setPersonalDetailsOpen(value => !value)} style={[ui.row, { minHeight: 44 }]}><Text style={[ui.small, { flex: 1, color: GREEN }]}>{personalDetailsOpen ? 'Hide account details' : 'More account details'}</Text><Icon name="chevron" size={16} /></Pressable>
          {personalDetailsOpen && <>
            <InfoRow label="Farmer ID" value={profile?.id ?? ''} />
            <InfoRow label="First Name" value={profile?.firstName || 'Not provided'} />
            <InfoRow label="Middle Name" value={profile?.middleName || 'None'} />
            <InfoRow label="Last Name" value={profile?.lastName || 'Not provided'} />
            <InfoRow label="Extension Name" value={profile?.extensionName || 'None'} />
            <InfoRow label="Gender" value={profile?.gender || 'Not provided'} />
            <InfoRow label="Date of Birth" value={profile?.dateOfBirth || 'Not provided'} />
            {activeFarmer.residentialLocation ? <>
              <InfoRow label="Residential Barangay" value={activeFarmer.residentialLocation.barangayName || 'Not available'} />
              <InfoRow label="Residential Purok / Sitio" value={activeFarmer.residentialLocation.purokSitio || 'Not available'} />
              <InfoRow label="Home Location Method" value={activeFarmer.residentialLocation.locationMethod} />
              <InfoRow label="Confirmed Home Pin" value={activeFarmer.residentialLocation.latitude !== null && activeFarmer.residentialLocation.longitude !== null ? activeFarmer.residentialLocation.latitude.toFixed(6) + ', ' + activeFarmer.residentialLocation.longitude.toFixed(6) : 'Not provided'} last />
            </> : <InfoRow label="Structured Residential Location" value="Legacy address — not yet reviewed" last />}
          </>}
        </Section>
        <Section title="My Farms">
          {currentFarm ? <Pressable accessibilityRole="button" accessibilityLabel="View / Manage Farms" onPress={() => router.push('/my-farms')} style={[ui.row, { alignItems: 'flex-start', minHeight: 44 }]}>
            <View style={{ backgroundColor: palette.sage, padding: 12, borderRadius: 12 }}><Icon name="farm" size={28} /></View>
            <View style={{ flex: 1, gap: 5 }}>
              <View style={[ui.row, { flexWrap: 'wrap' }]}><Text style={[ui.cardTitle, { flex: 1 }]}>{currentFarm.farmName}</Text><StatusBadge status={currentFarm.status} /></View>
              <Text style={ui.small}>{currentFarm.farmSize ? currentFarm.farmSize + ' hectares · ' : ''}{currentFarm.farmType}{currentFarm.mainProduct ? ' · ' + currentFarm.mainProduct : ''}</Text>
              <Text style={ui.small}>{currentFarm.farmLocation}</Text>
              <Text style={{ color: GREEN, fontSize: 12, fontWeight: '600' }}>View / Manage Farms</Text>
            </View><Icon name="chevron" size={16} />
          </Pressable> : <Text style={styles.emptyFarmText}>No farms have been added yet.</Text>}
          <Pressable accessibilityRole="button" onPress={() => router.push('/my-farms')} style={styles.addFarmButton}><Text style={styles.addFarmText}>{currentFarm ? '+ Add Another Farm' : '+ Add Farm'}</Text></Pressable>
        </Section>
        <Section title="Language">
          <Pressable accessibilityRole="button" onPress={() => setLanguagePickerOpen(true)} style={styles.languageSelector}><Icon name="language" /><Text style={styles.languageValue}>{language}</Text><Icon name="chevron" /></Pressable>
          <Text style={ui.small}>Language preference for this session. Interface translations are not yet available.</Text>
        </Section>
        {!!actionError && <Text style={styles.logoutError}>{actionError}</Text>}
        {!!logoutError && <Text style={styles.logoutError}>{logoutError}</Text>}
        <Pressable accessibilityRole="button" disabled={logoutBusy} onPress={handleLogout} style={[styles.logoutButton, logoutBusy && styles.disabledButton]}><Icon name="logout" color={palette.red} /><Text style={styles.logoutText}>{logoutBusy ? 'Logging out...' : 'Logout'}</Text></Pressable>
      </ScrollView>

      {profile && <EditProfileModal visible={editProfileOpen} profile={profile} onClose={() => setEditProfileOpen(false)} onSaved={async () => { await refreshSession(); setEditProfileOpen(false); }} />}
      <ChoiceModal visible={languagePickerOpen} title="Language" onClose={() => setLanguagePickerOpen(false)}>
        {(['English', 'Filipino', 'Cebuano'] as FarmerLanguage[]).map((option) => <Pressable key={option} onPress={() => { setLanguage(option); setLanguagePickerOpen(false); }} style={[styles.choiceRow, option === language && styles.choiceRowActive]}><Text style={styles.choiceTitle}>{option}</Text>{option === language && <Text style={styles.choiceCheck}>Selected</Text>}</Pressable>)}
      </ChoiceModal>
    </AppScreen>
  );
}

function EditProfileModal({ visible, profile, onClose, onSaved }: { visible: boolean; profile: FarmerProfile; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState<FarmerProfile>(profile);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [serverError, setServerError] = useState('');
  useEffect(() => { if (visible) { setForm(profile); setErrors({}); setPasswordOpen(false); setCurrentPassword(''); setNewPassword(''); setConfirmPassword(''); } }, [visible, profile]);
  function change(field: keyof FarmerProfile, value: string) { setForm((current) => ({ ...current, [field]: value })); setErrors((current) => ({ ...current, [field]: '' })); }
  async function save() {
    if (busy) return;
    const nextErrors: Record<string, string> = {};
    if (form.firstName.trim().length < 2) nextErrors.firstName = 'Enter a valid first name.';
    if (form.lastName.trim().length < 2) nextErrors.lastName = 'Enter a valid last name.';
    const middleProblem = middleNameError(normalizeNamePart(form.middleName, true));
    if (middleProblem) nextErrors.middleName = middleProblem;
    const extensionProblem = extensionNameError(normalizeNamePart(form.extensionName, true));
    if (extensionProblem) nextErrors.extensionName = extensionProblem;
    if (!form.gender.trim()) nextErrors.gender = 'Gender is required.';
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) nextErrors.email = 'Enter a valid email address.';
    if (!/^(09\d{9}|\+639\d{9})$/.test(form.phone.replace(/[\s()-]/g, ''))) nextErrors.phone = 'Enter a valid Philippine mobile number.';
    if (form.address.trim().length < 6) nextErrors.address = 'Enter a complete address.';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.dateOfBirth)) nextErrors.dateOfBirth = 'Use YYYY-MM-DD.';
    if (passwordOpen) {
      if (newPassword.length < 8) nextErrors.newPassword = 'Use at least 8 characters.';
      if (newPassword !== confirmPassword) nextErrors.confirmPassword = 'Passwords do not match.';
    }
    if (Object.keys(nextErrors).length) { setErrors(nextErrors); return; }
    setBusy(true);
    setServerError('');
    try {
      await updateFarmerProfile({ firstName: form.firstName.trim(), middleName: normalizeNamePart(form.middleName, true), lastName: form.lastName.trim(), extensionName: normalizeNamePart(form.extensionName, true), gender: form.gender.trim(), dateOfBirth: form.dateOfBirth, email: form.email.trim().toLowerCase(), contactNumber: form.phone, address: form.address.trim() });
      if (passwordOpen) await changeFarmerPassword(currentPassword, newPassword);
      await onSaved();
    } catch (error) {
      if (error instanceof FarmerDataApiError) setErrors((current) => ({ ...current, ...error.errors }));
      setServerError(error instanceof Error ? error.message : 'Profile changes could not be saved.');
    } finally { setBusy(false); }
  }
  return <FormModal visible={visible} title="Edit Profile" onClose={onClose}>
    <FormField label="First Name" value={form.firstName} onChangeText={(value) => change('firstName', value)} error={errors.firstName} />
    <FormField label="Middle Name (optional)" value={form.middleName} onChangeText={(value) => change('middleName', value)} error={errors.middleName} placeholder="Leave blank if none" maxLength={75} />
    <FormField label="Last Name" value={form.lastName} onChangeText={(value) => change('lastName', value)} error={errors.lastName} />
    <FormField label="Extension Name (optional)" value={form.extensionName} onChangeText={(value) => change('extensionName', value)} error={errors.extensionName} placeholder="e.g. Jr., Sr., III" maxLength={20} />
    <FormField label="Gender" value={form.gender} onChangeText={(value) => change('gender', value)} error={errors.gender} />
    <FormField label="Email" value={form.email} onChangeText={(value) => change('email', value)} error={errors.email} keyboardType="email-address" autoCapitalize="none" />
    <FormField label="Phone Number" value={form.phone} onChangeText={(value) => change('phone', value)} error={errors.phone} keyboardType="phone-pad" />
    <FormField label="Address" value={form.address} onChangeText={(value) => change('address', value)} error={errors.address} multiline />
    <FormField label="Date of Birth" value={form.dateOfBirth} onChangeText={(value) => change('dateOfBirth', value)} error={errors.dateOfBirth} placeholder="YYYY-MM-DD" />
    {!passwordOpen ? <Pressable onPress={() => setPasswordOpen(true)} style={styles.passwordToggle}><Text style={styles.passwordToggleText}>Change Password (Optional)</Text></Pressable> : <View style={styles.passwordArea}>
      <View style={styles.passwordHeader}><Text style={styles.formSectionTitle}>Change Password</Text><Pressable onPress={() => { setPasswordOpen(false); setCurrentPassword(''); setNewPassword(''); setConfirmPassword(''); }}><Text style={styles.cancelPassword}>Cancel password change</Text></Pressable></View>
      <FormField label="Current Password" value={currentPassword} onChangeText={setCurrentPassword} error={errors.currentPassword} secureTextEntry />
      <FormField label="New Password" value={newPassword} onChangeText={setNewPassword} error={errors.newPassword} secureTextEntry />
      <Text style={styles.passwordHint}>Use at least 8 characters.</Text>
      <FormField label="Confirm New Password" value={confirmPassword} onChangeText={setConfirmPassword} error={errors.confirmPassword} secureTextEntry />
    </View>}
    {!!serverError && <Text style={styles.errorText}>{serverError}</Text>}
    <ModalActions onCancel={onClose} onSave={() => void save()} saveLabel={busy ? 'Saving...' : 'Save Changes'} disabled={busy} />
  </FormModal>;
}

function FarmEditorModal({ visible, farm, onClose, onSaved }: { visible: boolean; farm: FarmerFarm | null; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState<Omit<FarmerFarm, 'id' | 'status'>>(emptyFarm());
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [locationBusy, setLocationBusy] = useState(false);
  const [locationMessage, setLocationMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [serverError, setServerError] = useState('');
  useEffect(() => { if (visible) { setForm(farm ? { farmName: farm.farmName, farmType: farm.farmType, farmLocation: farm.farmLocation, farmSize: farm.farmSize, mainProduct: farm.mainProduct } : emptyFarm()); setErrors({}); } }, [visible, farm]);
  function change(field: keyof typeof form, value: string) { setForm((current) => ({ ...current, [field]: value })); setErrors((current) => ({ ...current, [field]: '' })); }
  async function useGpsLocation() {
    setLocationBusy(true); setLocationMessage('Getting your farm location...');
    try {
      if (!await Location.hasServicesEnabledAsync()) { setLocationMessage('Location services are off. You can enter the location manually.'); return; }
      let permission = await Location.getForegroundPermissionsAsync();
      if (permission.status !== 'granted') permission = await Location.requestForegroundPermissionsAsync();
      if (permission.status !== 'granted') { setLocationMessage('Location permission was not granted. You can enter the location manually.'); return; }
      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      const { latitude, longitude } = position.coords;
      const addresses = await Location.reverseGeocodeAsync({ latitude, longitude });
      const address = addresses[0];
      const readableLocation = [address?.district, address?.city, address?.region].filter(Boolean).join(', ');
      if (readableLocation) { change('farmLocation', readableLocation); setLocationMessage('Farm location captured.'); }
      else setLocationMessage('Location captured, but no address was found. Please enter the farm location manually.');
    } catch { setLocationMessage('Unable to get your location. You can enter the location manually.'); }
    finally { setLocationBusy(false); }
  }
  async function save() { const nextErrors: Record<string, string> = {}; if (form.farmName.trim().length < 2) nextErrors.farmName = 'Enter a farm name.'; if (!['Crops', 'Livestock', 'Poultry', 'Fishpond'].includes(form.farmType.trim())) nextErrors.farmType = 'Use Crops, Livestock, Poultry, or Fishpond.'; if (form.farmLocation.trim().length < 5) nextErrors.farmLocation = 'Enter a complete farm location.'; if (!/^\d+(?:\.\d+)?$/.test(form.farmSize) || Number(form.farmSize) <= 0) nextErrors.farmSize = 'Enter a farm size greater than zero.'; if (form.mainProduct.trim().length < 2) nextErrors.mainProduct = 'Enter the main product.'; if (Object.keys(nextErrors).length) { setErrors(nextErrors); return; } setBusy(true); setServerError(''); try { await saveFarmerFarm({ name: form.farmName.trim(), type: form.farmType.trim(), agriculturalCategory: form.farmType.trim(), location: form.farmLocation.trim(), sizeHectares: Number(form.farmSize), mainProduct: form.mainProduct.trim() }); await onSaved(); } catch (error) { if (error instanceof FarmerDataApiError) setErrors((current) => ({ ...current, ...error.errors })); setServerError(error instanceof Error ? error.message : 'Farm changes could not be saved.'); } finally { setBusy(false); } }
  return <FormModal visible={visible} title={farm ? 'Edit Farm' : 'Add New Farm'} onClose={onClose}>
    <FormField label="Farm Name" value={form.farmName} onChangeText={(value) => change('farmName', value)} error={errors.farmName} />
    <FormField label="Farm Type" value={form.farmType} onChangeText={(value) => change('farmType', value)} error={errors.farmType} placeholder="e.g. Crops" />
    <FormField label="Farm Location" value={form.farmLocation} onChangeText={(value) => change('farmLocation', value)} error={errors.farmLocation} />
    <Pressable disabled={locationBusy} onPress={useGpsLocation} style={[styles.gpsButton, locationBusy && styles.disabledButton]}><Text style={styles.gpsButtonText}>{locationBusy ? 'Getting location...' : 'Use My GPS Location'}</Text></Pressable>
    {locationMessage ? <Text style={styles.locationMessage}>{locationMessage}</Text> : null}
    <FormField label="Farm Size (hectares)" value={form.farmSize} onChangeText={(value) => change('farmSize', value.replace(/[^0-9.]/g, ''))} error={errors.farmSize} keyboardType="numeric" />
    <FormField label="Main Product" value={form.mainProduct} onChangeText={(value) => change('mainProduct', value)} error={errors.mainProduct} />
    {!!serverError && <Text style={styles.errorText}>{serverError}</Text>}
    <ModalActions onCancel={onClose} onSave={() => void save()} saveLabel={busy ? 'Saving...' : (farm ? 'Save Farm' : 'Add Farm')} disabled={busy} />
  </FormModal>;
}

function FormModal({ visible, title, onClose, children }: { visible: boolean; title: string; onClose: () => void; children: ReactNode }) { return <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}><KeyboardAvoidingView style={styles.modalBackdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}><View style={styles.modalCard}><View style={styles.modalHeader}><Text style={styles.modalTitle}>{title}</Text><Pressable onPress={onClose} hitSlop={10}><Text style={styles.closeText}>X</Text></Pressable></View><ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.modalContent}>{children}</ScrollView></View></KeyboardAvoidingView></Modal>; }
function ChoiceModal({ visible, title, onClose, children }: { visible: boolean; title: string; onClose: () => void; children: ReactNode }) { return <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}><Pressable style={styles.modalBackdrop} onPress={onClose}><Pressable style={styles.choiceSheet} onPress={() => undefined}><Text style={styles.modalTitle}>{title}</Text>{children}<Pressable onPress={onClose} style={styles.choiceCancel}><Text style={styles.choiceCancelText}>Cancel</Text></Pressable></Pressable></Pressable></Modal>; }
function FormField({ label, error, multiline, ...props }: { label: string; error?: string; multiline?: boolean } & ComponentProps<typeof TextInput>) { return <View style={styles.fieldWrap}><Text style={styles.fieldLabel}>{label}</Text><TextInput {...props} multiline={multiline} style={[styles.input, multiline && styles.multilineInput, error && styles.inputError]} placeholderTextColor="#98A18F" /><>{error ? <Text style={styles.errorText}>{error}</Text> : null}</></View>; }
function ModalActions({ onCancel, onSave, saveLabel, disabled = false }: { onCancel: () => void; onSave: () => void; saveLabel: string; disabled?: boolean }) { return <View style={styles.modalActions}><Pressable disabled={disabled} onPress={onCancel} style={styles.cancelButton}><Text style={styles.cancelButtonText}>Cancel</Text></Pressable><Pressable disabled={disabled} onPress={onSave} style={[styles.saveButton, disabled && styles.disabledButton]}><Text style={styles.saveButtonText}>{saveLabel}</Text></Pressable></View>; }
function Section({ title, children }: { title: string; children: ReactNode }) { return <View style={styles.section}><Text style={styles.sectionTitle}>{title}</Text>{children}</View>; }
function InfoRow({ label, value, last, icon }: { label: string; value: string; last?: boolean; icon?: IconName }) { return <View style={[styles.infoRow, last && styles.infoRowLast]}>
  {icon && <Icon name={icon} size={18} />}
  <View accessibilityLabel={label + ': ' + value} style={{ flex: 1, minWidth: 0 }}>{!icon && <Text style={styles.infoLabel}>{label}</Text>}<Text style={styles.infoValue}>{value}</Text></View>
</View>; }
function StatusBadge({ status }: { status: FarmerFarm['status'] }) { const colors = status === 'Active' ? styles.activeBadge : status === 'Pending' ? styles.pendingBadge : styles.rejectedBadge; return <Text style={[styles.statusBadge, colors]}>{status}</Text>; }
function BottomNavigation() { return <View style={styles.bottomNav}><Pressable onPress={() => router.replace('/farmer-home')} style={styles.navItem}><Text style={styles.navIcon}>⌂</Text><Text style={styles.navText}>Home</Text></Pressable><Pressable onPress={() => router.push('/my-reports')} style={styles.navItem}><Text style={styles.navIcon}>▤</Text><Text style={styles.navText}>My{`\n`}Reports</Text></Pressable><Pressable onPress={() => router.push('/weather-advice')} style={styles.navItem}><Text style={styles.navIcon}>☁</Text><Text style={styles.navText}>Weather{`\n`}Advice</Text></Pressable><Pressable onPress={() => router.push('/notifications')} style={styles.navItem}><Text style={styles.navIcon}>♧</Text><Text style={styles.navText}>Notifications</Text></Pressable><Pressable onPress={() => router.replace('/profile')} style={[styles.navItem, styles.navItemActive]}><Text style={styles.navIconActive}>○</Text><Text style={styles.navTextActive}>Profile</Text></Pressable></View>; }

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#F7F5EE' },
  header: { height: 58, backgroundColor: GREEN, justifyContent: 'center', paddingHorizontal: 20 }, brand: { color: '#FFFFFF', fontSize: 21, fontWeight: '800' },
  pageContent: { padding: 18, paddingBottom: 26 }, contentWidth: { width: '100%', maxWidth: 620, alignSelf: 'center' },
  headingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, marginBottom: 20 }, title: { color: '#27301F', fontSize: 26, fontWeight: '800' }, subtitle: { color: OLIVE, fontSize: 13, lineHeight: 19, marginTop: 5, maxWidth: 230 },
  editButton: { borderColor: GREEN, borderWidth: 1, borderRadius: 12, minHeight: 44, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10, paddingVertical: 8 }, editButtonText: { color: GREEN, fontSize: 12, fontWeight: '800' },
  section: { backgroundColor: '#FFFDF8', borderColor: '#DCE5D6', borderWidth: 1, borderRadius: 16, padding: 12, marginBottom: 0 }, sectionTitle: { color: '#27301F', fontSize: 17, fontWeight: '800', marginBottom: 10 },
  infoRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 8, borderBottomColor: '#EDF1E9', borderBottomWidth: 1 }, infoRowLast: { borderBottomWidth: 0 }, infoLabel: { color: '#637068', fontSize: 12 }, infoValue: { color: '#172B24', fontSize: 14, lineHeight: 21, fontWeight: '500', flexShrink: 1 },
  farmSelector: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', backgroundColor: '#F5F8F1', borderColor: '#DCE5D6', borderWidth: 1, borderRadius: 13, padding: 12, marginBottom: 12 }, selectorCaption: { color: '#77826E', fontSize: 11 }, selectorName: { color: '#303B27', fontSize: 15, fontWeight: '800', marginTop: 3 }, selectorChevron: { color: OLIVE, fontSize: 17, fontWeight: '800' },
  farmDetails: { borderColor: '#E6ECE1', borderWidth: 1, borderRadius: 13, padding: 12 }, farmTitleRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 2 }, farmTitle: { color: '#303B27', fontSize: 15, fontWeight: '800', flexShrink: 1 },
  statusBadge: { overflow: 'hidden', borderRadius: 10, fontSize: 11, fontWeight: '800', paddingHorizontal: 8, paddingVertical: 4 }, activeBadge: { color: '#367A2A', backgroundColor: '#E5F3DF' }, pendingBadge: { color: '#A76809', backgroundColor: '#FFF0D7' }, rejectedBadge: { color: '#B54343', backgroundColor: '#FBE6E5' },
  farmActions: { flexDirection: 'row', gap: 9, marginTop: 13 }, secondaryAction: { flex: 1, minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 11, borderColor: GREEN, borderWidth: 1 }, secondaryActionText: { color: GREEN, fontSize: 12, fontWeight: '800' }, deleteAction: { flex: 1, minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 11, borderColor: '#C96A67', borderWidth: 1 }, deleteActionText: { color: '#B54343', fontSize: 12, fontWeight: '800' },
  addFarmButton: { minHeight: 47, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderColor: GREEN, borderWidth: 1, borderStyle: 'dashed', marginTop: 10 }, addFarmText: { color: GREEN, fontSize: 13, fontWeight: '800' }, emptyFarmText: { color: OLIVE, fontSize: 13, textAlign: 'center', paddingVertical: 14 },
  languageSelector: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderColor: '#DCE5D6', borderWidth: 1, borderRadius: 12, paddingHorizontal: 12 }, languageValue: { color: '#303B27', fontSize: 14, fontWeight: '700' },
  logoutError: { color: '#A83F35', fontSize: 12, lineHeight: 17, textAlign: 'center', marginBottom: 8 }, logoutButton: { minHeight: 44, backgroundColor: '#F8E9E3', borderRadius: 14, flexDirection: 'row', gap: 10, alignItems: 'center', justifyContent: 'center', marginTop: 4 }, logoutText: { color: '#A83F35', fontSize: 14, fontWeight: '700' },
  bottomNav: { minHeight: 72, backgroundColor: '#FFFDF8', borderTopWidth: 1, borderColor: '#E2E8DE', flexDirection: 'row', justifyContent: 'space-around', paddingHorizontal: 4, paddingTop: 7, paddingBottom: 5 }, navItem: { flex: 1, alignItems: 'center', justifyContent: 'center', borderRadius: 12, paddingVertical: 3 }, navItemActive: { backgroundColor: '#E7F3E0' }, navIcon: { color: OLIVE, fontSize: 20 }, navIconActive: { color: GREEN, fontSize: 20 }, navText: { color: OLIVE, fontSize: 10, marginTop: 2, textAlign: 'center', lineHeight: 12 }, navTextActive: { color: GREEN, fontSize: 10, fontWeight: '700', marginTop: 2, textAlign: 'center', lineHeight: 12 },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(25, 34, 21, 0.48)', justifyContent: 'center', padding: 16 }, modalCard: { width: '100%', maxWidth: 620, maxHeight: '90%', alignSelf: 'center', backgroundColor: '#FFFDF8', borderRadius: 18, overflow: 'hidden' }, modalHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderBottomColor: '#E5ECE0', borderBottomWidth: 1, paddingHorizontal: 17, paddingVertical: 15 }, modalTitle: { color: '#27301F', fontSize: 19, fontWeight: '800' }, closeText: { color: OLIVE, fontSize: 17, fontWeight: '800' }, modalContent: { padding: 17, paddingBottom: 24 },
  fieldWrap: { marginBottom: 13 }, fieldLabel: { color: '#36412D', fontSize: 13, fontWeight: '700', marginBottom: 6 }, input: { minHeight: 48, color: '#303B27', fontSize: 14, borderColor: '#D6E1D0', borderWidth: 1, borderRadius: 12, paddingHorizontal: 12, backgroundColor: '#FBFCF9' }, multilineInput: { minHeight: 78, paddingTop: 12, textAlignVertical: 'top' }, inputError: { borderColor: '#C35C58' }, errorText: { color: '#B54343', fontSize: 11, marginTop: 5 },
  passwordToggle: { alignItems: 'flex-start', paddingVertical: 7, marginBottom: 9 }, passwordToggleText: { color: GREEN, fontSize: 13, fontWeight: '800' }, passwordArea: { borderTopColor: '#E5ECE0', borderTopWidth: 1, paddingTop: 14, marginTop: 3 }, passwordHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }, formSectionTitle: { color: '#27301F', fontSize: 15, fontWeight: '800' }, cancelPassword: { color: '#B54343', fontSize: 11, fontWeight: '700' }, passwordHint: { color: '#77826E', fontSize: 11, marginTop: -8, marginBottom: 11 },
  modalActions: { flexDirection: 'row', gap: 10, marginTop: 6 }, cancelButton: { flex: 1, minHeight: 49, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: '#EEF1EC' }, cancelButtonText: { color: '#45513B', fontSize: 13, fontWeight: '800' }, saveButton: { flex: 1, minHeight: 49, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: GREEN }, saveButtonText: { color: '#FFFFFF', fontSize: 13, fontWeight: '800' },
  choiceSheet: { width: '100%', maxWidth: 520, alignSelf: 'center', backgroundColor: '#FFFDF8', borderRadius: 18, padding: 16 }, choiceRow: { minHeight: 60, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderBottomColor: '#E8EDE4', borderBottomWidth: 1, paddingHorizontal: 4 }, choiceRowActive: { backgroundColor: '#F0F7EC' }, choiceTitle: { color: '#303B27', fontSize: 14, fontWeight: '800' }, choiceSubtitle: { color: '#77826E', fontSize: 12, marginTop: 3 }, choiceCheck: { color: GREEN, fontSize: 11, fontWeight: '800', marginRight: 8 }, choiceCancel: { minHeight: 46, alignItems: 'center', justifyContent: 'center', marginTop: 10 }, choiceCancelText: { color: '#B54343', fontSize: 13, fontWeight: '800' },
  gpsButton: { minHeight: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 11, borderColor: GREEN, borderWidth: 1, marginTop: -5, marginBottom: 7 }, gpsButtonText: { color: GREEN, fontSize: 12, fontWeight: '800' }, disabledButton: { opacity: 0.6 }, locationMessage: { color: OLIVE, fontSize: 11, lineHeight: 16, marginTop: -2, marginBottom: 10 },
});
