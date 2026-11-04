import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { SymbolView } from 'expo-symbols';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { router } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  type ScrollViewInstance,
  StyleSheet,
  Text,
  TextInput,
  type TextInputInstance,
  type TextInputProps,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import type { Region } from 'react-native-maps';

import RegistrationPinMap from '@/components/registration-pin-map';
import { PinReview } from '@/components/registration-pin-review';
import { CABADBARAN_BARANGAYS, CABADBARAN_CITY } from '@/data/cabadbaranBarangays';
import { registerFarmer, RegistrationApiError, validateFarmPin } from '@/services/authApi';
import { MAX_ID_IMAGE_BYTES, preparePickedImage, UploadImagePreparationError } from '@/services/uploadImages';
import { isValidRegistrationMobile, registrationMobileDigits, registrationMobileE164 } from '@/utils/phone';
import { extensionNameError, middleNameError, normalizeNamePart } from '@/utils/farmerName';
import { RSBSA_GROUP_SIZES, RSBSA_REFERENCE_FORMAT, rsbsaApplyBackspace, rsbsaApplyInput, rsbsaDigitCount, rsbsaGroups, rsbsaReferenceError } from '@/utils/rsbsaReference';
import { AuthBrand, AuthIcon, AuthLandscape, authColors, type AuthIconName } from '@/components/auth-ui';

type Step = 1 | 2 | 3;

type RegistrationData = {
  firstName: string;
  middleName: string;
  lastName: string;
  extensionName: string;
  gender: string;
  customGender: string;
  email: string;
  phone: string;
  residentialBarangayCode: string;
  residentialPurokSitio: string;
  residentialLandmark: string;
  residentialLocationMethod: 'Manual' | 'Map';
  residentialLatitude: number | null;
  residentialLongitude: number | null;
  residentialAccuracy: number | null;
  residentialPinConfirmed: boolean;
  dateOfBirth: Date | null;
  password: string;
  confirmPassword: string;
  farmName: string;
  farmType: string;
  farmBarangayCode: string;
  farmPurokSitio: string;
  farmLandmark: string;
  farmLocationMethod: 'Manual' | 'Map';
  latitude: number | null;
  longitude: number | null;
  accuracy: number | null;
  pinConfirmed: boolean;
  farmSize: string;
  mainProduct: string;
  idType: string;
  idNumber: string;
  idFrontImageUri: string;
  idFrontImageName: string;
  idFrontImageMimeType: string;
  idFrontImageSize: number | null;
  idBackImageUri: string;
  idBackImageName: string;
  idBackImageMimeType: string;
  idBackImageSize: number | null;
};

type FieldProps = {
  label: string;
  icon: string;
  placeholder: string;
  value: string;
  onChangeText: (value: string) => void;
  onBlur: () => void;
  error?: string;
  keyboardType?: 'default' | 'email-address' | 'phone-pad' | 'numeric';
  secureTextEntry?: boolean;
  passwordVisible?: boolean;
  onTogglePasswordVisibility?: () => void;
  multiline?: boolean;
  required?: boolean;
  maxLength?: number;
  textContentType?: TextInputProps['textContentType'];
  autoComplete?: TextInputProps['autoComplete'];
  importantForAutofill?: TextInputProps['importantForAutofill'];
  autoCapitalize?: TextInputProps['autoCapitalize'];
  autoCorrect?: TextInputProps['autoCorrect'];
};

const GREEN = authColors.forest;
const OLIVE = authColors.muted;
const BORDER = authColors.border;
const TEXT = authColors.ink;
// The physical RSBSA ID card (front + back + printed number) is the registration ID, reviewed manually by an admin.
const RSBSA_ID_TYPE = 'RSBSA ID';
// Fields validated before leaving each step, in on-screen order (the first error is scrolled into view).
const STEP_FIELDS: Record<Step, string[]> = {
  1: ['idType', 'idFrontImageUri', 'idBackImageUri', 'idNumber'],
  2: ['firstName', 'middleName', 'lastName', 'extensionName', 'gender', 'customGender', 'dateOfBirth', 'email', 'phone', 'residentialLocationMethod', 'residentialPin', 'residentialBarangayCode', 'residentialPurokSitio', 'residentialLandmark'],
  3: ['farmName', 'farmType', 'farmLocationMethod', 'farmBarangayCode', 'farmPurokSitio', 'farmLandmark', 'farmPin', 'farmSize', 'mainProduct', 'password', 'confirmPassword'],
};
const INITIAL_DATA: RegistrationData = {
  firstName: '',
  middleName: '',
  lastName: '',
  extensionName: '',
  gender: '',
  customGender: '',
  email: '',
  phone: '',
  residentialBarangayCode: '',
  residentialPurokSitio: '',
  residentialLandmark: '',
  residentialLocationMethod: 'Manual',
  residentialLatitude: null,
  residentialLongitude: null,
  residentialAccuracy: null,
  residentialPinConfirmed: false,
  dateOfBirth: null,
  password: '',
  confirmPassword: '',
  farmName: '',
  farmType: '',
  farmBarangayCode: '',
  farmPurokSitio: '',
  farmLandmark: '',
  farmLocationMethod: 'Manual',
  latitude: null,
  longitude: null,
  accuracy: null,
  pinConfirmed: false,
  farmSize: '',
  mainProduct: '',
  idType: RSBSA_ID_TYPE,
  idNumber: '',
  idFrontImageUri: '',
  idFrontImageName: '',
  idFrontImageMimeType: '',
  idFrontImageSize: null,
  idBackImageUri: '',
  idBackImageName: '',
  idBackImageMimeType: '',
  idBackImageSize: null,
};

const GENDERS = ['Male', 'Female', 'Non-binary', 'Prefer not to say', 'Prefer to self-describe'];
const FARM_TYPES = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
const CABADBARAN_MAP_REGION: Region = {
  latitude: 9.1236,
  longitude: 125.5354,
  latitudeDelta: 0.18,
  longitudeDelta: 0.18,
};

type CabadbaranBarangay = (typeof CABADBARAN_BARANGAYS)[number];

function compareBarangayNames(left: CabadbaranBarangay, right: CabadbaranBarangay) {
  const leftPoblacion = /^Poblacion\s+(\d+)$/i.exec(left.name);
  const rightPoblacion = /^Poblacion\s+(\d+)$/i.exec(right.name);
  if (leftPoblacion && rightPoblacion) return Number(leftPoblacion[1]) - Number(rightPoblacion[1]);
  return left.name.localeCompare(right.name, 'en', { sensitivity: 'base' });
}

const SORTED_CABADBARAN_BARANGAYS = [...CABADBARAN_BARANGAYS].sort(compareBarangayNames);

function normalizeBarangayLabel(value: string | null | undefined) {
  return (value || '')
    .trim()
    .toLocaleLowerCase('en')
    .replace(/^(?:barangay|brgy\.?)[\s-]*/i, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function exactBarangayFromGeocode(addresses: Location.LocationGeocodedAddress[]) {
  for (const address of addresses) {
    if (normalizeBarangayLabel(address.city) !== 'cabadbaran city'
      && normalizeBarangayLabel(address.city) !== 'cabadbaran') continue;
    // District is the only geocoder field used as a barangay suggestion. A street
    // name or comma-separated formatted address is not evidence of barangay coverage.
    const district = normalizeBarangayLabel(address.district);
    const match = CABADBARAN_BARANGAYS.find((barangay) => normalizeBarangayLabel(barangay.name) === district);
    if (match) return match;
  }
  return null;
}

function normalizeSpaces(value: string) {
  return value.trim().replace(/\s+/g, ' ');
}

function hasObviousJunk(value: string) {
  const compact = value.toLowerCase().replace(/[\s'-]/g, '');
  return /^(.)\1{3,}$/.test(compact) || /^(.{2,5})\1{2,}$/.test(compact);
}

function isValidDate(date: Date | null) {
  if (!date || !Number.isFinite(date.getTime())) return false;
  const today = new Date();
  today.setHours(23, 59, 59, 999);
  return date <= today;
}

function normalizeFormData(current: RegistrationData): RegistrationData {
  return {
    ...current,
    firstName: normalizeSpaces(current.firstName),
    middleName: normalizeNamePart(current.middleName, true),
    lastName: normalizeSpaces(current.lastName),
    extensionName: normalizeNamePart(current.extensionName, true),
    customGender: normalizeSpaces(current.customGender),
    email: current.email.trim().toLowerCase(),
    phone: registrationMobileDigits(current.phone),
    residentialPurokSitio: normalizeSpaces(current.residentialPurokSitio),
    residentialLandmark: normalizeSpaces(current.residentialLandmark),
    farmName: normalizeSpaces(current.farmName),
    farmPurokSitio: normalizeSpaces(current.farmPurokSitio),
    farmLandmark: normalizeSpaces(current.farmLandmark),
    farmSize: current.farmSize.trim(),
    mainProduct: normalizeSpaces(current.mainProduct),
    idNumber: normalizeSpaces(current.idNumber),
  };
}

function getFieldError(field: string, current: RegistrationData) {
  const namePattern = /^[A-Za-zÀ-ÖØ-öø-ÿ]+(?:[ '-][A-Za-zÀ-ÖØ-öø-ÿ]+)*$/;
  const farmNamePattern = /^[A-Za-zÀ-ÖØ-öø-ÿ0-9][A-Za-zÀ-ÖØ-öø-ÿ0-9 &'().,-]*$/;
  const productPattern = /^[A-Za-zÀ-ÖØ-öø-ÿ]+(?:[ -][A-Za-zÀ-ÖØ-öø-ÿ]+)*$/;
  const lettersIn = (value: string) => (value.match(/[A-Za-zÀ-ÖØ-öø-ÿ]/g) || []).length;

  switch (field) {
    case 'firstName':
    case 'lastName': {
      const value = current[field];
      if (!value) return 'Please enter a valid name.';
      if (lettersIn(value) < 2) return 'Name is too short.';
      if (!namePattern.test(value)) return 'Name must contain letters only.';
      if (/(.)\1\1/i.test(value) || hasObviousJunk(value)) return 'Please enter a valid name.';
      return '';
    }
    case 'middleName':
      return middleNameError(current.middleName);
    case 'extensionName':
      return extensionNameError(current.extensionName);
    case 'customGender':
      return current.gender === 'Prefer to self-describe' && !current.customGender ? 'Please describe your gender.' : '';
    case 'gender':
      return current.gender ? '' : 'Please select a gender.';
    case 'email':
      if (!current.email) return 'Email is required.';
      return /^\S+@\S+\.\S+$/.test(current.email) ? '' : 'Please enter a valid email address.';
    case 'phone':
      return isValidRegistrationMobile(current.phone) ? '' : 'Enter 10 digits after +63, beginning with 9.';
    case 'residentialBarangayCode':
      return CABADBARAN_BARANGAYS.some((barangay) => barangay.code === current.residentialBarangayCode) ? '' : 'Please select your residential barangay.';
    case 'residentialPurokSitio':
      return current.residentialPurokSitio.length >= 2 && /^[\p{L}\p{N}][\p{L}\p{N}\s.'#\/-]*$/u.test(current.residentialPurokSitio) ? '' : 'Enter a valid residential purok or sitio.';
    case 'residentialLandmark':
      return !current.residentialLandmark || (current.residentialLandmark.length <= 100 && /^[\p{L}\p{N}][\p{L}\p{N}\s.,'#\/()-]*$/u.test(current.residentialLandmark)) ? '' : 'Enter a valid house, street, or landmark, or leave it blank.';
    case 'dateOfBirth':
      return isValidDate(current.dateOfBirth) ? '' : 'Please enter a valid date of birth.';
    case 'password':
      if (!current.password) return 'Password is required.';
      if (current.password.length < 8) return 'Password must be at least 8 characters.';
      if (!/[A-Z]/.test(current.password) || !/[a-z]/.test(current.password) || !/\d/.test(current.password)) {
        return 'Use at least 1 uppercase letter, 1 lowercase letter, and 1 number.';
      }
      return '';
    case 'confirmPassword':
      if (!current.confirmPassword) return 'Please confirm your password.';
      return current.password === current.confirmPassword ? '' : 'Passwords do not match.';
    case 'farmName':
      if (current.farmName.length < 2 || lettersIn(current.farmName) < 2) return 'Please enter a valid farm name.';
      if (!farmNamePattern.test(current.farmName) || hasObviousJunk(current.farmName)) return 'Please enter a valid farm name.';
      return '';
    case 'farmType':
      return current.farmType ? '' : 'Please select a farm type.';
    case 'farmBarangayCode':
      return CABADBARAN_BARANGAYS.some((barangay) => barangay.code === current.farmBarangayCode) ? '' : 'Please select your farm barangay.';
    case 'farmPurokSitio':
      return current.farmPurokSitio.length >= 2 && /^[\p{L}\p{N}][\p{L}\p{N}\s.'#\/-]*$/u.test(current.farmPurokSitio) ? '' : 'Enter a valid purok or sitio.';
    case 'farmLandmark':
      return !current.farmLandmark || (current.farmLandmark.length <= 100 && /^[\p{L}\p{N}][\p{L}\p{N}\s.,'#\/()-]*$/u.test(current.farmLandmark)) ? '' : 'Enter a valid landmark or leave it blank.';
    case 'farmLocationMethod':
      return current.farmLocationMethod === 'Manual' || current.farmLocationMethod === 'Map' ? '' : 'Select how you want to provide the farm location.';
    case 'farmPin':
      return current.farmLocationMethod === 'Manual' || (current.pinConfirmed && current.latitude !== null && current.longitude !== null)
        ? ''
        : 'Choose and confirm the actual farm point on the map.';
    case 'farmSize':
      if (!current.farmSize) return 'Farm size is required.';
      return /^\d+(?:\.\d+)?$/.test(current.farmSize) && Number(current.farmSize) > 0 ? '' : 'Enter a positive farm size.';
    case 'mainProduct':
      if (current.mainProduct.length < 2) return 'Please enter a valid main product.';
      if (!productPattern.test(current.mainProduct) || hasObviousJunk(current.mainProduct)) return 'Please enter a valid main product.';
      return '';
    case 'idFrontImageUri':
      return current.idFrontImageUri ? '' : 'Add a photo of the front of your RSBSA ID.';
    case 'idBackImageUri':
      return current.idBackImageUri ? '' : 'Add a photo of the back of your RSBSA ID.';
    case 'idType':
      return current.idType === RSBSA_ID_TYPE ? '' : 'Registration requires your RSBSA ID.';
    case 'idNumber':
      // All 15 digits of 00-00-00-000-000000 (same rule as the PHP API for new RSBSA ID registrations).
      return rsbsaReferenceError(current.idNumber);
    default:
      return '';
  }
}

function Field({ label, icon, placeholder, value, onChangeText, onBlur, error, passwordVisible, onTogglePasswordVisibility, required = true, ...inputProps }: FieldProps) {
  const iconName: AuthIconName = label === 'Farm Name' ? 'farm' : label === 'Main Product' ? 'crop' : label.startsWith('Farm Size') ? 'area' : ({ o: 'person', '@': 'mail', '*': 'lock', '+': 'pin', '#': 'id' } as Record<string, AuthIconName>)[icon] || 'person';
  return (
    <View style={styles.fieldGroup}>
      <Text style={styles.label}>{label}{required ? ' *' : ''}</Text>
      <View style={[styles.inputShell, error && styles.inputShellError]}>
        <AuthIcon name={iconName} size={19} color={OLIVE} />
        <TextInput
          {...inputProps}
          accessibilityLabel={label}
          value={value}
          onChangeText={onChangeText}
          onBlur={onBlur}
          placeholder={placeholder}
          placeholderTextColor="#8C948A"
          style={[styles.input, inputProps.multiline && styles.multilineInput]}
        />
        {onTogglePasswordVisibility ? <Pressable
          accessibilityRole="button"
          accessibilityLabel={passwordVisible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
          accessibilityState={{ selected: passwordVisible }}
          hitSlop={10}
          onPress={onTogglePasswordVisibility}
          style={styles.visibilityButton}
        ><SymbolView name={passwordVisible
          ? { ios: 'eye.slash', android: 'visibility_off', web: 'visibility_off' }
          : { ios: 'eye', android: 'visibility', web: 'visibility' }} size={22} tintColor={OLIVE} />
        </Pressable> : null}
      </View>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

function PhoneField({ value, onChangeText, onBlur, error }: { value: string; onChangeText: (value: string) => void; onBlur: () => void; error?: string }) {
  return <View style={styles.fieldGroup}>
    <Text style={styles.label}>Phone Number *</Text>
    <View style={[styles.inputShell, error && styles.inputShellError]}>
      <Text style={styles.phonePrefix}>+63</Text>
      <View style={styles.phoneDivider} />
      <TextInput
        accessibilityLabel="Philippine mobile number after plus 63"
        autoComplete="tel"
        keyboardType="phone-pad"
        maxLength={20}
        onBlur={onBlur}
        onChangeText={onChangeText}
        placeholder="9XXXXXXXXX"
        placeholderTextColor="#9AA08D"
        style={styles.input}
        value={value}
      />
    </View>
    {!!error && <Text style={styles.errorText}>{error}</Text>}
  </View>;
}

/**
 * RSBSA reference number as five boxes (00-00-00-000-000000). Typing moves to the next box when one is
 * full, Backspace in an empty box goes back, and pasting the whole number (with or without hyphens)
 * fills every box. Validation runs on Continue, not on blur, because focus moves between the boxes.
 */
function RsbsaReferenceField({ value, onChangeValue, error }: { value: string; onChangeValue: (value: string) => void; error?: string }) {
  const inputs = useRef<(TextInputInstance | null)[]>([]);
  const groups = rsbsaGroups(value);
  const focusGroup = (index: number | null) => { if (index !== null) inputs.current[index]?.focus(); };
  return <View style={styles.fieldGroup}>
    <Text style={styles.label}>RSBSA Reference Number *</Text>
    <View style={styles.rsbsaRow}>
      {RSBSA_GROUP_SIZES.flatMap((size, index) => [
        index > 0 ? <Text key={`separator-${index}`} style={styles.rsbsaSeparator} accessible={false}>-</Text> : null,
        <View key={`group-${index}`} style={[styles.inputShell, styles.rsbsaShell, { flex: size }, error && styles.inputShellError]}>
          <TextInput
            ref={(input) => { inputs.current[index] = input; }}
            accessibilityLabel={`RSBSA reference number, group ${index + 1} of 5, ${size} digits`}
            value={groups[index]}
            onChangeText={(text) => { const next = rsbsaApplyInput(value, index, text); onChangeValue(next.value); focusGroup(next.focus); }}
            onKeyPress={({ nativeEvent }) => {
              if (nativeEvent.key !== 'Backspace') return;
              const next = rsbsaApplyBackspace(value, index);
              if (next) { onChangeValue(next.value); focusGroup(next.focus); }
            }}
            placeholder={'0'.repeat(size)}
            placeholderTextColor="#B5BBAF"
            keyboardType="number-pad"
            inputMode="numeric"
            autoComplete="off"
            autoCorrect={false}
            importantForAutofill="no"
            textContentType="none"
            style={[styles.input, styles.rsbsaInput]}
          />
        </View>,
      ])}
    </View>
    <Text style={styles.rsbsaHelp}>{`${rsbsaDigitCount(value)} of 15 digits · ${RSBSA_REFERENCE_FORMAT}. You can paste the whole number.`}</Text>
    {!!error && <Text style={styles.errorText}>{error}</Text>}
  </View>;
}

function SelectField({ label, value, placeholder, error, onPress }: { label: string; value: string; placeholder: string; error?: string; onPress: () => void }) {
  return (
    <View style={styles.fieldGroup}>
      <Text style={styles.label}>{label} *</Text>
      <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} style={[styles.inputShell, error && styles.inputShellError]}>
        <Text style={[styles.selectText, !value && styles.placeholderText]}>{value || placeholder}</Text>
        <AuthIcon name="chevron" size={20} color={OLIVE} />
      </Pressable>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

function ChoiceModal({ visible, title, options, onClose, onSelect }: { visible: boolean; title: string; options: string[]; onClose: () => void; onSelect: (value: string) => void }) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalBackdrop}>
        <View style={styles.choiceSheet}>
          <View style={styles.sheetHandle} />
          <Text style={styles.sheetTitle}>{title}</Text>
          <ScrollView style={{ maxHeight: 360 }} keyboardShouldPersistTaps="handled">
          {options.map((option) => (
            <Pressable key={option} onPress={() => onSelect(option)} style={styles.choiceRow}>
              <Text style={styles.choiceText}>{option}</Text>
            </Pressable>
          ))}
          </ScrollView>
          <Pressable onPress={onClose} style={styles.sheetCancel}>
            <Text style={styles.sheetCancelText}>Cancel</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function BarangayPickerModal({
  visible,
  title,
  selectedCode,
  onClose,
  onSelect,
}: {
  visible: boolean;
  title: string;
  selectedCode: string;
  onClose: () => void;
  onSelect: (barangay: CabadbaranBarangay) => void;
}) {
  const [query, setQuery] = useState('');
  const normalizedQuery = normalizeBarangayLabel(query);
  const filteredBarangays = normalizedQuery
    ? SORTED_CABADBARAN_BARANGAYS.filter((barangay) => normalizeBarangayLabel(barangay.name).includes(normalizedQuery))
    : SORTED_CABADBARAN_BARANGAYS;

  useEffect(() => {
    if (visible) setQuery('');
  }, [visible]);

  return (
    <Modal visible={visible} animationType="slide" presentationStyle="fullScreen" onRequestClose={onClose}>
      <SafeAreaView style={styles.barangayPickerSafeArea} edges={['top', 'bottom']}>
        <KeyboardAvoidingView style={styles.barangayPickerKeyboard} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <View style={styles.barangayPickerHeader}>
            <View style={styles.barangayPickerHeading}>
              <Text style={styles.barangayPickerEyebrow}>Cabadbaran City</Text>
              <Text style={styles.barangayPickerTitle}>{title}</Text>
            </View>
            <Pressable accessibilityRole="button" accessibilityLabel="Close barangay picker" hitSlop={8} onPress={onClose} style={styles.barangayPickerClose}>
              <Text style={styles.barangayPickerCloseText}>Close</Text>
            </Pressable>
          </View>
          <View style={styles.barangaySearchWrap}>
            <TextInput
              accessibilityLabel="Search Cabadbaran barangays"
              autoCapitalize="words"
              autoCorrect={false}
              clearButtonMode="while-editing"
              onChangeText={setQuery}
              placeholder="Search barangay"
              placeholderTextColor="#8D9783"
              returnKeyType="search"
              style={styles.barangaySearchInput}
              value={query}
            />
          </View>
          <FlatList
            data={filteredBarangays}
            style={styles.barangayList}
            keyExtractor={(barangay) => barangay.code}
            keyboardDismissMode="on-drag"
            keyboardShouldPersistTaps="handled"
            contentContainerStyle={[styles.barangayListContent, filteredBarangays.length === 0 && styles.barangayListEmptyContent]}
            ListEmptyComponent={<Text style={styles.barangayEmptyText}>No Cabadbaran barangay matches your search.</Text>}
            renderItem={({ item }) => {
              const selected = item.code === selectedCode;
              return (
                <Pressable
                  accessibilityRole="radio"
                  accessibilityState={{ checked: selected }}
                  accessibilityLabel={`Select ${item.name}`}
                  onPress={() => onSelect(item)}
                  style={({ pressed }) => [styles.barangayRow, selected && styles.barangayRowSelected, pressed && styles.barangayRowPressed]}
                >
                  <View style={[styles.barangayRadio, selected && styles.barangayRadioSelected]} />
                  <Text style={[styles.barangayRowText, selected && styles.barangayRowTextSelected]}>{item.name}</Text>
                  {selected && <Text style={styles.barangaySelectedText}>Selected</Text>}
                </Pressable>
              );
            }}
          />
          <View style={styles.barangayPickerFooter}>
            <Pressable accessibilityRole="button" onPress={onClose} style={styles.barangayCancelButton}>
              <Text style={styles.barangayCancelText}>Cancel</Text>
            </Pressable>
          </View>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </Modal>
  );
}

export default function RegisterScreen() {
  const { width, height } = useWindowDimensions();
  const [step, setStep] = useState<Step>(1);
  const [data, setData] = useState<RegistrationData>(INITIAL_DATA);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [genderPickerOpen, setGenderPickerOpen] = useState(false);
  const [farmPickerOpen, setFarmPickerOpen] = useState(false);
  const [residentialBarangayPickerOpen, setResidentialBarangayPickerOpen] = useState(false);
  const [barangayPickerOpen, setBarangayPickerOpen] = useState(false);
  const [datePickerOpen, setDatePickerOpen] = useState(false);
  const [dateDraft, setDateDraft] = useState(new Date(2000, 0, 1));
  const [residentialMapOpen, setResidentialMapOpen] = useState(false);
  const [residentialMapRegion, setResidentialMapRegion] = useState<Region>(CABADBARAN_MAP_REGION);
  const [residentialDraftPin, setResidentialDraftPin] = useState<{ latitude: number; longitude: number } | null>(null);
  const [residentialLocationBusy, setResidentialLocationBusy] = useState(false);
  const [residentialPinValidationBusy, setResidentialPinValidationBusy] = useState(false);
  const [residentialLocationMessage, setResidentialLocationMessage] = useState('');
  const [residentialReverseGeocodeSuggestion, setResidentialReverseGeocodeSuggestion] = useState('');
  const [residentialBarangayResolutionMessage, setResidentialBarangayResolutionMessage] = useState('');
  const [residentialMapSuggestedBarangayCode, setResidentialMapSuggestedBarangayCode] = useState('');
  const [mapOpen, setMapOpen] = useState(false);
  const [mapRegion, setMapRegion] = useState<Region>(CABADBARAN_MAP_REGION);
  const [draftPin, setDraftPin] = useState<{ latitude: number; longitude: number } | null>(null);
  const [locationBusy, setLocationBusy] = useState(false);
  const [pinValidationBusy, setPinValidationBusy] = useState(false);
  const [locationMessage, setLocationMessage] = useState('');
  const [reverseGeocodeSuggestion, setReverseGeocodeSuggestion] = useState('');
  const [barangayResolutionMessage, setBarangayResolutionMessage] = useState('');
  const [mapSuggestedBarangayCode, setMapSuggestedBarangayCode] = useState('');
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [uploadSide, setUploadSide] = useState<'front' | 'back'>('front');
  const [successVisible, setSuccessVisible] = useState(false);
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [confirmPasswordVisible, setConfirmPasswordVisible] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submissionError, setSubmissionError] = useState('');
  // A GPS "starting point" only moves the map view (and proposes a draft pin); it never confirms either pin.
  const [residentialMapFocus, setResidentialMapFocus] = useState<{ region: Region; nonce: number } | null>(null);
  const [farmMapFocus, setFarmMapFocus] = useState<{ region: Region; nonce: number } | null>(null);
  const [mapGestureActive, setMapGestureActive] = useState(false);
  const scrollRef = useRef<ScrollViewInstance>(null);
  // Set synchronously on the first tap, so a fast double tap cannot start a second submission.
  const submitLock = useRef(false);
  const residentialPinRevision = useRef(0);
  const farmPinRevision = useRef(0);
  const fieldOffsets = useRef<Record<string, number>>({});
  const formOffset = useRef(0);
  const contentWidth = Math.min(width * 0.92, 560);

  useEffect(() => {
    fieldOffsets.current = {};
    scrollRef.current?.scrollTo({ y: 0, animated: false });
  }, [step]);

  useEffect(() => {
    if (!successVisible) return undefined;
    const timer = setTimeout(() => router.replace('/verification-pending'), 2500);
    return () => clearTimeout(timer);
  }, [successVisible]);

  function updateData<K extends keyof RegistrationData>(key: K, value: RegistrationData[K]) {
    setData((current) => ({ ...current, [key]: value }));
    if (errors[key]) setErrors((current) => ({ ...current, [key]: '' }));
    if (submissionError) setSubmissionError('');
  }

  function validateField(field: string) {
    const normalized = normalizeFormData(data);
    setData(normalized);
    const message = getFieldError(field, normalized);
    setErrors((current) => ({ ...current, [field]: message }));
    return !message;
  }

  function setFieldOffset(key: string, y: number) {
    fieldOffsets.current[key] = y;
  }

  function scrollToFirstError(errorKeys: string[]) {
    const firstKey = errorKeys[0];
    const y = fieldOffsets.current[firstKey];
    if (y !== undefined) setTimeout(() => scrollRef.current?.scrollTo({ y: Math.max(0, formOffset.current + y - 16), animated: true }), 50);
  }

  function stepErrors(target: Step, normalized: RegistrationData) {
    const nextErrors: Record<string, string> = {};
    STEP_FIELDS[target].forEach((field) => {
      const message = getFieldError(field, normalized);
      if (message) nextErrors[field] = message;
    });
    return nextErrors;
  }

  /** Validates one step and shows its field errors; entered values are only normalized, never cleared. */
  function validateStep(target: Step) {
    const normalized = normalizeFormData(data);
    const nextErrors = stepErrors(target, normalized);
    setData(normalized);
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length) scrollToFirstError(Object.keys(nextErrors));
    return Object.keys(nextErrors).length === 0;
  }

  function stepForField(field: string): Step | null {
    if (STEP_FIELDS[1].includes(field)) return 1;
    if (STEP_FIELDS[2].includes(field)) return 2;
    if (STEP_FIELDS[3].includes(field)) return 3;
    return null;
  }

  function openResidentialMap() {
    const confirmedPin = data.residentialLatitude !== null && data.residentialLongitude !== null
      ? { latitude: data.residentialLatitude, longitude: data.residentialLongitude }
      : null;
    setResidentialDraftPin(confirmedPin);
    setResidentialMapFocus(null);
    setResidentialMapRegion(confirmedPin ? { ...confirmedPin, latitudeDelta: 0.025, longitudeDelta: 0.025 } : CABADBARAN_MAP_REGION);
    setResidentialReverseGeocodeSuggestion('');
    setResidentialLocationMessage('Tap the map to place or move the pin on your actual home, check the coordinates, then confirm it.');
    setResidentialMapOpen(true);
  }

  function updateResidentialDraftPin(coordinate: { latitude: number; longitude: number }) {
    residentialPinRevision.current += 1;
    setResidentialDraftPin(coordinate);
    setResidentialMapSuggestedBarangayCode('');
    setResidentialBarangayResolutionMessage('');
    setResidentialReverseGeocodeSuggestion('');
    setErrors((current) => ({ ...current, residentialPin: '' }));
    setResidentialLocationMessage('Pin moved. Confirm this point and review the barangay and purok below. Your entered address is kept.');
  }

  function removeResidentialPin() {
    setData((current) => ({
      ...current,
      residentialLocationMethod: 'Manual',
      residentialLatitude: null,
      residentialLongitude: null,
      residentialAccuracy: null,
      residentialPinConfirmed: false,
    }));
    setResidentialDraftPin(null);
    setResidentialMapSuggestedBarangayCode('');
    setResidentialReverseGeocodeSuggestion('');
    setResidentialBarangayResolutionMessage('');
    setResidentialLocationMessage('Home pin removed. Your selected residential barangay and purok/sitio are still saved in the form.');
    setErrors((current) => ({ ...current, residentialPin: '' }));
  }

  async function useResidentialGpsLocation() {
    setResidentialLocationBusy(true);
    setResidentialLocationMessage('Checking location access...');
    try {
      const servicesEnabled = await Location.hasServicesEnabledAsync();
      if (!servicesEnabled) {
        setResidentialLocationMessage('Location services are turned off. Please enable GPS or use the manual residential address option.');
        return;
      }

      let permission = await Location.getForegroundPermissionsAsync();
      if (permission.status !== 'granted') {
        setResidentialLocationMessage('Requesting location permission...');
        permission = await Location.requestForegroundPermissionsAsync();
      }
      if (permission.status !== 'granted') {
        setResidentialLocationMessage(permission.canAskAgain
          ? 'Location permission was denied. You can try again or enter your residential address manually.'
          : 'Location permission is blocked. Enable it in device settings or enter your residential address manually.');
        return;
      }

      const position = await Promise.race([
        Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }),
        new Promise<never>((_, reject) => setTimeout(() => reject(new Error('location-timeout')), 12000)),
      ]);
      const { latitude, longitude, accuracy } = position.coords;
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        setResidentialLocationMessage('The device returned an invalid location. Please try again or enter your residential address manually.');
        return;
      }

      const currentPoint = { latitude, longitude };
      updateResidentialDraftPin(currentPoint);
      const startRegion = { ...currentPoint, latitudeDelta: 0.018, longitudeDelta: 0.018 };
      setResidentialMapRegion(startRegion);
      setResidentialMapFocus((current) => ({ region: startRegion, nonce: (current?.nonce ?? 0) + 1 }));
      setResidentialLocationMessage('Current phone location loaded only as a starting point. Move the pin to your actual home, then confirm it.');
    } catch {
      setResidentialLocationMessage('GPS is unavailable right now. Please try again or enter your residential address manually.');
    } finally {
      setResidentialLocationBusy(false);
    }
  }

  async function confirmResidentialPin() {
    if (!residentialDraftPin || residentialPinValidationBusy) {
      setErrors((current) => ({ ...current, residentialPin: 'Tap the map to choose your actual home point.' }));
      return;
    }
    setResidentialPinValidationBusy(true);
    setErrors((current) => ({ ...current, residentialPin: '' }));
    const point = { ...residentialDraftPin };
    const revision = residentialPinRevision.current;
    try {
      await validateFarmPin(point.latitude, point.longitude, 'residential');
      if (revision !== residentialPinRevision.current) return;
      let suggestedBarangay: CabadbaranBarangay | null = null;
      let readableLocation = '';
      try {
        const addresses = await Promise.race([
          Location.reverseGeocodeAsync(point),
          new Promise<never>((_, reject) => setTimeout(() => reject(new Error('reverse-geocode-timeout')), 7000)),
        ]);
        suggestedBarangay = exactBarangayFromGeocode(addresses);
        const address = addresses[0];
        readableLocation = [address?.name, address?.district, address?.city, address?.region].filter(Boolean).join(', ');
      } catch {
        // A valid city pin remains usable when optional reverse geocoding is unavailable.
      }
      if (revision !== residentialPinRevision.current) return;
      setData((current) => ({
        ...current,
        residentialLocationMethod: 'Map',
        residentialLatitude: point.latitude,
        residentialLongitude: point.longitude,
        residentialPinConfirmed: true,
        residentialBarangayCode: current.residentialBarangayCode || suggestedBarangay?.code || '',
      }));
      setResidentialReverseGeocodeSuggestion(readableLocation);
      setResidentialMapSuggestedBarangayCode(suggestedBarangay?.code || '');
      setErrors((current) => ({
        ...current,
        residentialPin: '',
        residentialBarangayCode: data.residentialBarangayCode || suggestedBarangay ? '' : 'Please select the residential barangay for this confirmed pin.',
      }));
      setResidentialBarangayResolutionMessage(suggestedBarangay
        ? (data.residentialBarangayCode
          ? `The home point suggests ${suggestedBarangay.name}. The barangay you selected was NOT changed; check that it is correct.`
          : `${suggestedBarangay.name} was pre-filled only as a suggestion from the home point and may be wrong. Check it and change it if needed.`)
        : 'The barangay could not be identified reliably from this point. Choose your barangay below; your confirmed pin is preserved.');
      setResidentialLocationMessage(suggestedBarangay
        ? 'Home pin confirmed. Review the barangay and complete your purok/sitio below.'
        : 'Home pin confirmed. Review or select your barangay and complete your purok/sitio below.');
      setResidentialMapOpen(false);
    } catch (error) {
      if (revision !== residentialPinRevision.current) return;
      const message = error instanceof RegistrationApiError
        ? error.fields.residentialPin || error.message
        : 'The home pin could not be checked. Try again or use the manual residential address option.';
      setErrors((current) => ({ ...current, residentialPin: message }));
      setResidentialLocationMessage('');
    } finally {
      setResidentialPinValidationBusy(false);
    }
  }

  function closeResidentialMap() {
    residentialPinRevision.current += 1;
    setResidentialMapOpen(false);
  }

  function closeFarmMap() {
    farmPinRevision.current += 1;
    setMapOpen(false);
  }

  function selectLocationMethod(method: 'Manual' | 'Map') {
    setData((current) => ({
      ...current,
      farmLocationMethod: method,
      latitude: method === 'Manual' ? null : current.latitude,
      longitude: method === 'Manual' ? null : current.longitude,
      accuracy: method === 'Manual' ? null : current.accuracy,
      pinConfirmed: method === 'Manual' ? false : current.pinConfirmed,
    }));
    setErrors((current) => ({ ...current, farmLocationMethod: '', farmPin: '' }));
    setLocationMessage('');
    if (method === 'Manual') {
      setMapSuggestedBarangayCode('');
      setBarangayResolutionMessage('');
      setReverseGeocodeSuggestion('');
    }
    if (method === 'Map') openFarmMap();
  }

  function openFarmMap() {
    const confirmedPin = data.latitude !== null && data.longitude !== null
      ? { latitude: data.latitude, longitude: data.longitude }
      : null;
    setDraftPin(confirmedPin);
    setFarmMapFocus(null);
    setMapRegion(confirmedPin ? { ...confirmedPin, latitudeDelta: 0.025, longitudeDelta: 0.025 } : CABADBARAN_MAP_REGION);
    setReverseGeocodeSuggestion('');
    setLocationMessage('Tap the map to place or move the pin on the actual farm, check the coordinates, then confirm it.');
    setMapOpen(true);
  }

  function updateDraftPin(coordinate: { latitude: number; longitude: number }) {
    farmPinRevision.current += 1;
    setDraftPin(coordinate);
    setMapSuggestedBarangayCode('');
    setBarangayResolutionMessage('');
    setReverseGeocodeSuggestion('');
    setErrors((current) => ({ ...current, farmPin: '' }));
    setLocationMessage('Pin moved. Confirm this point and review the farm address below. Your entered address is kept.');
  }

  async function useGpsLocation() {
    setLocationBusy(true);
    setLocationMessage('Checking location access...');
    try {
      const servicesEnabled = await Location.hasServicesEnabledAsync();
      if (!servicesEnabled) {
        setLocationMessage('Location services are turned off. Please enable GPS or enter the address manually.');
        return;
      }

      let permission = await Location.getForegroundPermissionsAsync();
      if (permission.status !== 'granted') {
        setLocationMessage('Requesting location permission...');
        permission = await Location.requestForegroundPermissionsAsync();
      }
      if (permission.status !== 'granted') {
        setLocationMessage(permission.canAskAgain
          ? 'Location permission was denied. You can try again or enter the address manually.'
          : 'Location permission is blocked. Enable it in your device settings or enter the address manually.');
        return;
      }

      const timeout = new Promise<never>((_, reject) => {
        setTimeout(() => reject(new Error('location-timeout')), 12000);
      });
      const position = await Promise.race([
        Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }),
        timeout,
      ]);
      const { latitude, longitude, accuracy } = position.coords;
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        setLocationMessage('The device returned an invalid location. Please try again or enter the address manually.');
        return;
      }

      const currentPoint = { latitude, longitude };
      updateDraftPin(currentPoint);
      const startRegion = { ...currentPoint, latitudeDelta: 0.018, longitudeDelta: 0.018 };
      setMapRegion(startRegion);
      setFarmMapFocus((current) => ({ region: startRegion, nonce: (current?.nonce ?? 0) + 1 }));
      setLocationMessage('Current phone location loaded only as a starting point. Move the pin to the actual farm, then confirm it.');
    } catch {
      setLocationMessage('GPS is unavailable right now. Please try again or enter the address manually.');
    } finally {
      setLocationBusy(false);
    }
  }

  async function confirmFarmPin() {
    if (!draftPin || pinValidationBusy) {
      setErrors((current) => ({ ...current, farmPin: 'Tap the map to choose the actual farm point.' }));
      return;
    }
    setPinValidationBusy(true);
    setErrors((current) => ({ ...current, farmPin: '' }));
    const point = { ...draftPin };
    const revision = farmPinRevision.current;
    try {
      await validateFarmPin(point.latitude, point.longitude);
      if (revision !== farmPinRevision.current) return;
      let suggestedBarangay: CabadbaranBarangay | null = null;
      let readableLocation = '';
      try {
        const addresses = await Promise.race([
          Location.reverseGeocodeAsync(point),
          new Promise<never>((_, reject) => setTimeout(() => reject(new Error('reverse-geocode-timeout')), 7000)),
        ]);
        suggestedBarangay = exactBarangayFromGeocode(addresses);
        const address = addresses[0];
        readableLocation = [address?.name, address?.district, address?.city, address?.region].filter(Boolean).join(', ');
      } catch {
        // A valid city pin remains usable when optional reverse geocoding is unavailable.
      }
      if (revision !== farmPinRevision.current) return;
      setData((current) => ({
        ...current,
        farmLocationMethod: 'Map',
        latitude: point.latitude,
        longitude: point.longitude,
        pinConfirmed: true,
        farmBarangayCode: current.farmBarangayCode || suggestedBarangay?.code || '',
      }));
      setReverseGeocodeSuggestion(readableLocation);
      setMapSuggestedBarangayCode(suggestedBarangay?.code || '');
      setErrors((current) => ({
        ...current,
        farmPin: '',
        farmBarangayCode: data.farmBarangayCode || suggestedBarangay ? '' : 'Please select the farm barangay for this confirmed pin.',
      }));
      setBarangayResolutionMessage(suggestedBarangay
        ? (data.farmBarangayCode
          ? `The farm point suggests ${suggestedBarangay.name}. The barangay you selected was NOT changed; check that it is correct.`
          : `${suggestedBarangay.name} was pre-filled only as a suggestion from the farm point and may be wrong. Check it and change it if needed.`)
        : 'The barangay could not be identified reliably from this point. Choose the farm barangay below; your confirmed pin is preserved.');
      setLocationMessage(suggestedBarangay
        ? 'Farm pin confirmed. Review the barangay and complete the farm purok/sitio below.'
        : 'Farm pin confirmed. Review or select the barangay and complete the farm purok/sitio below.');
      setMapOpen(false);
    } catch (error) {
      if (revision !== farmPinRevision.current) return;
      const message = error instanceof RegistrationApiError
        ? error.fields.farmPin || error.message
        : 'The farm pin could not be checked. Try again or use the manual address option.';
      setErrors((current) => ({ ...current, farmPin: message }));
      setLocationMessage('');
    } finally {
      setPinValidationBusy(false);
    }
  }

  function handleDateChange(event: DateTimePickerEvent, selectedDate?: Date) {
    if (Platform.OS !== 'ios') setDatePickerOpen(false);
    if (event.type === 'set' && selectedDate) {
      if (Platform.OS === 'ios') setDateDraft(selectedDate);
      else updateData('dateOfBirth', selectedDate);
    }
  }

  function openDatePicker() {
    setDateDraft(data.dateOfBirth || new Date(2000, 0, 1));
    setDatePickerOpen(true);
  }

  async function chooseImage(source: 'camera' | 'gallery', selectedSide: 'front' | 'back' = uploadSide) {
    setUploadModalOpen(false);
    const errorKey = selectedSide === 'front' ? 'idFrontImageUri' : 'idBackImageUri';
    const result = source === 'camera'
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (result.status !== 'granted') {
      setErrors((current) => ({ ...current, [errorKey]: `Permission is required to ${source === 'camera' ? 'use the camera' : 'choose an image'}.` }));
      return;
    }
    const pickerResult = source === 'camera'
      ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [4, 3], quality: 1 })
      : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, aspect: [4, 3], quality: 1 });
    if (!pickerResult.canceled && pickerResult.assets?.[0]?.uri) {
      try {
        const prepared = await preparePickedImage(pickerResult.assets[0], `rsbsa-id-${selectedSide}`, MAX_ID_IMAGE_BYTES);
        if (selectedSide === 'front') {
          updateData('idFrontImageUri', prepared.uri);
          updateData('idFrontImageName', prepared.fileName);
          updateData('idFrontImageMimeType', prepared.mimeType);
          updateData('idFrontImageSize', prepared.size);
        } else {
          updateData('idBackImageUri', prepared.uri);
          updateData('idBackImageName', prepared.fileName);
          updateData('idBackImageMimeType', prepared.mimeType);
          updateData('idBackImageSize', prepared.size);
        }
      } catch (error) {
        setErrors((current) => ({ ...current, [errorKey]: error instanceof UploadImagePreparationError ? error.message : 'The ID image could not be prepared.' }));
      }
    }
  }

  function handleNext() {
    if (step < 3 && validateStep(step)) setStep((step + 1) as Step);
  }

  async function handleSubmit() {
    if (submitLock.current || submitting) return;
    const normalized = normalizeFormData(data);
    // Every step is checked again; the first incomplete step is reopened with its errors.
    for (const target of [1, 2, 3] as Step[]) {
      const targetErrors = stepErrors(target, normalized);
      if (Object.keys(targetErrors).length) {
        setData(normalized);
        setErrors(targetErrors);
        if (target !== step) {
          setStep(target);
          setTimeout(() => scrollToFirstError(Object.keys(targetErrors)), 100);
        } else {
          scrollToFirstError(Object.keys(targetErrors));
        }
        return;
      }
    }
    if (!normalized.dateOfBirth) return;

    submitLock.current = true;
    let succeeded = false;
    setData(normalized);
    setSubmitting(true);
    setSubmissionError('');
    try {
      await registerFarmer({
        firstName: normalized.firstName,
        middleName: normalized.middleName,
        lastName: normalized.lastName,
        extensionName: normalized.extensionName,
        gender: normalized.gender,
        customGender: normalized.customGender,
        email: normalized.email,
        phone: registrationMobileE164(normalized.phone),
        residentialBarangayCode: normalized.residentialBarangayCode,
        residentialPurokSitio: normalized.residentialPurokSitio,
        residentialLandmark: normalized.residentialLandmark,
        residentialLocationMethod: normalized.residentialLocationMethod,
        residentialLatitude: normalized.residentialLatitude,
        residentialLongitude: normalized.residentialLongitude,
        dateOfBirth: formatDatabaseDate(normalized.dateOfBirth),
        password: normalized.password,
        confirmPassword: normalized.confirmPassword,
        farmName: normalized.farmName,
        farmType: normalized.farmType,
        farmBarangayCode: normalized.farmBarangayCode,
        farmPurokSitio: normalized.farmPurokSitio,
        farmLandmark: normalized.farmLandmark,
        farmLocationMethod: normalized.farmLocationMethod,
        farmSize: normalized.farmSize,
        mainProduct: normalized.mainProduct,
        latitude: normalized.latitude,
        longitude: normalized.longitude,
        idType: normalized.idType,
        idNumber: normalized.idNumber,
        idFrontImage: { uri: normalized.idFrontImageUri, name: normalized.idFrontImageName || 'rsbsa-id-front.jpg', type: normalized.idFrontImageMimeType || 'image/jpeg' },
        idBackImage: normalized.idBackImageUri
          ? { uri: normalized.idBackImageUri, name: normalized.idBackImageName || 'rsbsa-id-back.jpg', type: normalized.idBackImageMimeType || 'image/jpeg' }
          : null,
      });
      succeeded = true;
      setSuccessVisible(true);
    } catch (error) {
      if (error instanceof RegistrationApiError) {
        setErrors((current) => ({ ...current, ...error.fields }));
        // A dropped connection can hide a registration the server already saved; retrying then reports
        // the email/number as taken. Point the farmer to the pending status instead of a dead end.
        setSubmissionError(error.status === 409
          ? `${error.message} If you already submitted this registration (for example, the connection dropped), it is waiting for admin review: log in with this email to check its status.`
          : error.message);
        const firstField = Object.keys(error.fields).find((field) => error.fields[field]);
        const target = firstField ? stepForField(firstField) : null;
        if (target && target !== step) setStep(target);
        if (firstField) setTimeout(() => scrollToFirstError([firstField]), 100);
      } else {
        setSubmissionError('Registration could not be completed. Please try again.');
      }
    } finally {
      // After success the lock stays on until the pending-status screen opens.
      if (!succeeded) submitLock.current = false;
      setSubmitting(false);
    }
  }

  function renderPersonalStep() {
    return (
      <View style={styles.formCard}>
        <Text style={styles.sectionTitle}>Personal information</Text>
        {/* Reading order First, Middle, Last, Extension: two per row on wider screens, stacked on narrow ones. */}
        <View style={width >= 380 ? styles.nameRow : undefined} onLayout={(event) => { setFieldOffset('firstName', event.nativeEvent.layout.y); setFieldOffset('middleName', event.nativeEvent.layout.y); }}>
          <View style={styles.nameCell}><Field label="First Name" icon="o" placeholder="First name" value={data.firstName} onChangeText={(value) => updateData('firstName', value)} onBlur={() => validateField('firstName')} error={errors.firstName} autoCapitalize="words" /></View>
          <View style={styles.nameCell}><Field label="Middle Name" icon="o" placeholder="Optional" value={data.middleName} onChangeText={(value) => updateData('middleName', value)} onBlur={() => validateField('middleName')} error={errors.middleName} required={false} maxLength={75} autoCapitalize="words" /></View>
        </View>
        <View style={width >= 380 ? styles.nameRow : undefined} onLayout={(event) => { setFieldOffset('lastName', event.nativeEvent.layout.y); setFieldOffset('extensionName', event.nativeEvent.layout.y); }}>
          <View style={styles.nameCell}><Field label="Last Name" icon="o" placeholder="Last name" value={data.lastName} onChangeText={(value) => updateData('lastName', value)} onBlur={() => validateField('lastName')} error={errors.lastName} autoCapitalize="words" /></View>
          <View style={styles.nameCell}><Field label="Extension Name" icon="o" placeholder="e.g. Jr., III" value={data.extensionName} onChangeText={(value) => updateData('extensionName', value)} onBlur={() => validateField('extensionName')} error={errors.extensionName} required={false} maxLength={20} autoCapitalize="words" autoCorrect={false} /></View>
        </View>
        <Text style={styles.fieldHelp}>Middle Name and Extension Name (Jr., Sr., II, III) are optional. Leave them blank if they do not apply.</Text>
        <View onLayout={(event) => setFieldOffset('gender', event.nativeEvent.layout.y)}><SelectField label="Gender" value={data.gender} placeholder="Select gender" error={errors.gender} onPress={() => setGenderPickerOpen(true)} /></View>
        {data.gender === 'Prefer to self-describe' && <View onLayout={(event) => setFieldOffset('customGender', event.nativeEvent.layout.y)}><Field label="Describe Gender" icon="o" placeholder="Describe yourself" value={data.customGender} onChangeText={(value) => updateData('customGender', value)} onBlur={() => validateField('customGender')} error={errors.customGender} maxLength={30} /></View>}
        <View onLayout={(event) => setFieldOffset('dateOfBirth', event.nativeEvent.layout.y)} style={styles.fieldGroup}>
          <Text style={styles.label}>Date of Birth *</Text>
          <Pressable accessibilityRole="button" accessibilityLabel="Choose date of birth" onPress={openDatePicker} style={[styles.inputShell, errors.dateOfBirth && styles.inputShellError]}>
            <AuthIcon name="calendar" size={20} color={OLIVE} />
            <Text style={[styles.selectText, !data.dateOfBirth && styles.placeholderText]}>{data.dateOfBirth ? formatDate(data.dateOfBirth) : 'mm/dd/yyyy'}</Text>
          </Pressable>
          {!!errors.dateOfBirth && <Text style={styles.errorText}>{errors.dateOfBirth}</Text>}
        </View>
        <View onLayout={(event) => setFieldOffset('email', event.nativeEvent.layout.y)}><Field label="Email" icon="@" placeholder="you@example.com" value={data.email} onChangeText={(value) => updateData('email', value)} onBlur={() => validateField('email')} error={errors.email} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} textContentType="emailAddress" /></View>
        <View onLayout={(event) => setFieldOffset('phone', event.nativeEvent.layout.y)}><PhoneField value={data.phone} onChangeText={(value) => updateData('phone', registrationMobileDigits(value))} onBlur={() => validateField('phone')} error={errors.phone} /></View>
        <Text style={styles.subsectionTitle}>Home address</Text>
        <View style={styles.fixedLocationCard}>
          <Text style={styles.fixedLocationLabel}>City / Province</Text>
          <Text style={styles.fixedLocationValue}>{CABADBARAN_CITY.name}, {CABADBARAN_CITY.province}</Text>
          <Text style={styles.fixedLocationHelp}>AgriReach registration is limited to this city. Enter where you actually live, separately from the farm location.</Text>
        </View>
        <View onLayout={(event) => setFieldOffset('residentialPin', event.nativeEvent.layout.y)}>
          <Text style={styles.label}>Pin Home Location on Map (optional)</Text>
          <Text style={styles.locationInstruction}>Mark your actual home. Your phone location only starts the map; you can continue with a manual address without location permission.</Text>
          <Pressable accessibilityRole="button" accessibilityLabel="Pin home location on map" onPress={openResidentialMap} style={[styles.gpsBox, errors.residentialPin && styles.gpsBoxError]}>
            <AuthIcon name="pin" size={25} />
            <Text style={styles.gpsTitle}>{data.residentialPinConfirmed ? 'Home pin confirmed — tap to review' : 'Pin Home Location on Map'}</Text>
            <Text style={styles.gpsSubtitle}>{data.residentialPinConfirmed ? 'Check the residential address fields below' : 'Optional: tap the map to place the pin, then confirm'}</Text>
            {data.residentialPinConfirmed && data.residentialLatitude !== null && data.residentialLongitude !== null && <View style={styles.coordinatesRow}><Text style={styles.coordinateText}>Selected home point: {data.residentialLatitude.toFixed(6)}, {data.residentialLongitude.toFixed(6)}</Text></View>}
          </Pressable>
          {data.residentialPinConfirmed && <Pressable accessibilityRole="button" accessibilityLabel="Remove confirmed home pin" onPress={removeResidentialPin} style={styles.manualToggle}><Text style={styles.manualToggleText}>Remove home pin</Text></Pressable>}
          {!!residentialLocationMessage && !errors.residentialPin && <Text style={styles.locationMessage}>{residentialLocationMessage}</Text>}
          {!!errors.residentialPin && <Text style={styles.errorText}>{errors.residentialPin}</Text>}
        </View>
        <View onLayout={(event) => setFieldOffset('residentialBarangayCode', event.nativeEvent.layout.y)}>
          <SelectField label="Residential Barangay" value={CABADBARAN_BARANGAYS.find((barangay) => barangay.code === data.residentialBarangayCode)?.name || ''} placeholder="Select barangay" error={errors.residentialBarangayCode} onPress={() => setResidentialBarangayPickerOpen(true)} />
          {data.residentialLocationMethod === 'Map' && !!residentialBarangayResolutionMessage && <Text style={residentialMapSuggestedBarangayCode && data.residentialBarangayCode === residentialMapSuggestedBarangayCode ? styles.mapSuggestionSuccess : styles.mapSuggestionHelp}>{residentialBarangayResolutionMessage}</Text>}
        </View>
        <View onLayout={(event) => setFieldOffset('residentialPurokSitio', event.nativeEvent.layout.y)}>
          <Field label="Purok / Sitio" icon="+" placeholder="e.g., Purok 3 or Sitio Riverside" value={data.residentialPurokSitio} onChangeText={(value) => updateData('residentialPurokSitio', value)} onBlur={() => validateField('residentialPurokSitio')} error={errors.residentialPurokSitio} maxLength={100} />
          <Text style={styles.fieldHelp}>Enter your locally used purok or sitio. No reliable maintained purok boundary dataset is configured, so it is never invented from the pin.</Text>
        </View>
        <View onLayout={(event) => setFieldOffset('residentialLandmark', event.nativeEvent.layout.y)}>
          <Field label="House / Street / Landmark" icon="+" placeholder="Optional house, street, or nearby landmark" value={data.residentialLandmark} onChangeText={(value) => updateData('residentialLandmark', value)} onBlur={() => validateField('residentialLandmark')} error={errors.residentialLandmark} required={false} maxLength={100} />
        </View>
      </View>
    );
  }

  // Rendered inside the farm card (direct children), so field offsets stay relative to the same card.
  function renderAccountSection() {
    return (
      <>
        <Text style={styles.subsectionTitle}>Secure your account</Text>
        <Text style={styles.accountIntro}>Create the password you will use to log in. AgriReach never creates one for you.</Text>
        <View onLayout={(event) => setFieldOffset('password', event.nativeEvent.layout.y)}><Field label="Password" icon="*" placeholder="Enter password" value={data.password} onChangeText={(value) => updateData('password', value)} onBlur={() => validateField('password')} error={errors.password} secureTextEntry={!passwordVisible} passwordVisible={passwordVisible} onTogglePasswordVisibility={() => setPasswordVisible((visible) => !visible)} textContentType="none" autoComplete="off" importantForAutofill="no" autoCapitalize="none" autoCorrect={false} /><Text style={styles.passwordHint}>At least 8 characters, 1 uppercase, 1 lowercase, and 1 number.</Text></View>
        <View onLayout={(event) => setFieldOffset('confirmPassword', event.nativeEvent.layout.y)}><Field label="Confirm Password" icon="*" placeholder="Confirm password" value={data.confirmPassword} onChangeText={(value) => updateData('confirmPassword', value)} onBlur={() => validateField('confirmPassword')} error={errors.confirmPassword} secureTextEntry={!confirmPasswordVisible} passwordVisible={confirmPasswordVisible} onTogglePasswordVisibility={() => setConfirmPasswordVisible((visible) => !visible)} textContentType="none" autoComplete="off" importantForAutofill="no" autoCapitalize="none" autoCorrect={false} /></View>
      </>
    );
  }

  function renderStructuredFarmStep() {
    return (
      <View style={styles.formCard}>
        <Text style={styles.sectionTitle}>Basic farm details</Text>
        <View onLayout={(event) => setFieldOffset('farmName', event.nativeEvent.layout.y)}><Field label="Farm Name" icon="+" placeholder="Enter farm name" value={data.farmName} onChangeText={(value) => updateData('farmName', value)} onBlur={() => validateField('farmName')} error={errors.farmName} /></View>
        <View onLayout={(event) => setFieldOffset('farmType', event.nativeEvent.layout.y)} style={styles.fieldGroup}>
          <Text style={styles.label}>Farm category *</Text>
          <View style={styles.categoryGrid}>{FARM_TYPES.map((type) => <Pressable key={type} accessibilityRole="radio" accessibilityLabel={type} accessibilityState={{ checked: data.farmType === type }} onPress={() => updateData('farmType', type)} style={[styles.category, data.farmType === type && styles.categorySelected]}>
            <AuthIcon name={({ Crops: 'crop', Livestock: 'livestock', Poultry: 'poultry', Fishpond: 'fish' } as Record<string, AuthIconName>)[type]} size={24} color={data.farmType === type ? GREEN : OLIVE} /><Text style={styles.categoryText}>{type}</Text>
          </Pressable>)}</View>
          {!!errors.farmType && <Text style={styles.errorText}>{errors.farmType}</Text>}
        </View>
        <View style={styles.fixedLocationCard}>
          <Text style={styles.fixedLocationLabel}>City / Province</Text>
          <Text style={styles.fixedLocationValue}>{CABADBARAN_CITY.name}, {CABADBARAN_CITY.province}</Text>
          <Text style={styles.fixedLocationHelp}>AgriReach farm registration is limited to this city.</Text>
        </View>
        <View onLayout={(event) => setFieldOffset('farmLocationMethod', event.nativeEvent.layout.y)}>
          <Text style={styles.label}>How will you provide the actual farm location? *</Text>
          <Text style={styles.locationInstruction}>Choose where the farm is located. It may be different from where you are registering now.</Text>
          <Pressable accessibilityRole="radio" accessibilityState={{ checked: data.farmLocationMethod === 'Manual' }} onPress={() => selectLocationMethod('Manual')} style={[styles.locationChoice, data.farmLocationMethod === 'Manual' && styles.locationChoiceActive]}>
            <View style={[styles.radioDot, data.farmLocationMethod === 'Manual' && styles.radioDotActive]} />
            <View style={styles.locationChoiceText}><Text style={styles.locationChoiceTitle}>Select farm address</Text><Text style={styles.locationChoiceDescription}>Select the barangay and enter the farm&apos;s purok or sitio.</Text></View>
          </Pressable>
          <Pressable accessibilityRole="radio" accessibilityState={{ checked: data.farmLocationMethod === 'Map' }} onPress={() => selectLocationMethod('Map')} style={[styles.locationChoice, data.farmLocationMethod === 'Map' && styles.locationChoiceActive]}>
            <View style={[styles.radioDot, data.farmLocationMethod === 'Map' && styles.radioDotActive]} />
            <View style={styles.locationChoiceText}><Text style={styles.locationChoiceTitle}>Pin farm location on map</Text><Text style={styles.locationChoiceDescription}>Place and confirm a pin at the actual farm. Phone GPS is only a starting point.</Text></View>
          </Pressable>
          {!!errors.farmLocationMethod && <Text style={styles.errorText}>{errors.farmLocationMethod}</Text>}
        </View>
        <View onLayout={(event) => setFieldOffset('farmBarangayCode', event.nativeEvent.layout.y)}>
          <SelectField label="Farm Barangay" value={CABADBARAN_BARANGAYS.find((barangay) => barangay.code === data.farmBarangayCode)?.name || ''} placeholder="Select barangay" error={errors.farmBarangayCode} onPress={() => setBarangayPickerOpen(true)} />
          {data.farmLocationMethod === 'Map' && !!barangayResolutionMessage && <Text style={mapSuggestedBarangayCode && data.farmBarangayCode === mapSuggestedBarangayCode ? styles.mapSuggestionSuccess : styles.mapSuggestionHelp}>{barangayResolutionMessage}</Text>}
        </View>
        <View onLayout={(event) => setFieldOffset('farmPurokSitio', event.nativeEvent.layout.y)}>
          <Field label="Purok / Sitio" icon="+" placeholder="e.g., Purok 3 or Sitio Riverside" value={data.farmPurokSitio} onChangeText={(value) => updateData('farmPurokSitio', value)} onBlur={() => validateField('farmPurokSitio')} error={errors.farmPurokSitio} maxLength={100} />
          <Text style={styles.fieldHelp}>Enter the farm&apos;s locally used purok or sitio. No reliable maintained purok boundary dataset is configured, so this is never invented from the pin.</Text>
        </View>
        <View onLayout={(event) => setFieldOffset('farmLandmark', event.nativeEvent.layout.y)}>
          <Field label="Landmark / Detailed Address" icon="+" placeholder="Optional landmark near the farm" value={data.farmLandmark} onChangeText={(value) => updateData('farmLandmark', value)} onBlur={() => validateField('farmLandmark')} error={errors.farmLandmark} required={false} maxLength={100} />
        </View>
        {data.farmLocationMethod === 'Map' && <View onLayout={(event) => setFieldOffset('farmPin', event.nativeEvent.layout.y)}>
          <Text style={styles.label}>Confirmed Farm Pin *</Text>
          <Pressable onPress={openFarmMap} style={[styles.gpsBox, errors.farmPin && styles.gpsBoxError]}>
            <AuthIcon name="pin" size={25} />
            <Text style={styles.gpsTitle}>{data.pinConfirmed ? 'Farm pin confirmed' : 'Choose farm point on map'}</Text>
            <Text style={styles.gpsSubtitle}>{data.pinConfirmed ? 'Tap to review or correct the pin' : 'Tap the map to place the pin on the actual farm'}</Text>
            {data.pinConfirmed && data.latitude !== null && data.longitude !== null && <View style={styles.coordinatesRow}><Text style={styles.coordinateText}>Latitude: {data.latitude.toFixed(6)}</Text><Text style={styles.coordinateText}>Longitude: {data.longitude.toFixed(6)}</Text></View>}
          </Pressable>
          {!!locationMessage && <Text style={styles.locationMessage}>{locationMessage}</Text>}
          {!!errors.farmPin && <Text style={styles.errorText}>{errors.farmPin}</Text>}
        </View>}
        <View onLayout={(event) => setFieldOffset('farmSize', event.nativeEvent.layout.y)}><Field label="Farm Size (hectares)" icon="#" placeholder="Farm Size (hectares)" value={data.farmSize} onChangeText={(value) => updateData('farmSize', value)} onBlur={() => validateField('farmSize')} error={errors.farmSize} keyboardType="numeric" /></View>
        <View onLayout={(event) => setFieldOffset('mainProduct', event.nativeEvent.layout.y)}><Field label="Main Product" icon="+" placeholder="e.g., Rice, Corn, Chicken, Fish" value={data.mainProduct} onChangeText={(value) => updateData('mainProduct', value)} onBlur={() => validateField('mainProduct')} error={errors.mainProduct} /></View>
        {renderAccountSection()}
      </View>
    );
  }

  function renderRsbsaStep() {
    const idSlots = [
      { side: 'front' as const, label: 'Front of RSBSA ID', uri: data.idFrontImageUri, error: errors.idFrontImageUri },
      { side: 'back' as const, label: 'Back of RSBSA ID', uri: data.idBackImageUri, error: errors.idBackImageUri },
    ];
    return (
      <View style={styles.formCard}>
        <Text style={styles.sectionTitle}>RSBSA ID card</Text>
        <Text style={styles.acceptedText}>Use your physical RSBSA ID card, not the paper enrollment client&apos;s copy. Take a clear photo of each side or choose one from your gallery. Each prepared image must be 5MB or smaller.</Text>
        {!!errors.idType && <Text style={styles.errorText}>{errors.idType}</Text>}
          {idSlots.map((slot) => <View key={slot.side} onLayout={(event) => setFieldOffset(slot.side === 'front' ? 'idFrontImageUri' : 'idBackImageUri', event.nativeEvent.layout.y)} style={styles.idSlot}>
            <View style={styles.idHeading}><AuthIcon name={slot.side === 'front' ? 'id' : 'idBack'} /><Text style={styles.label}>{slot.label} *</Text></View>
            <Pressable accessibilityRole="button" accessibilityLabel={`${slot.uri ? 'Replace' : 'Add'} ${slot.label}`} onPress={() => { setUploadSide(slot.side); setUploadModalOpen(true); }} style={[styles.uploadBox, slot.error && styles.uploadBoxError]}>
              {slot.uri ? <ImagePreview uri={slot.uri} /> : <AuthIcon name="photo" size={30} color="#8C948A" />}
              <Text style={styles.uploadTitle}>{slot.uri ? `Replace ${slot.label}` : `Add ${slot.label}`}</Text>
              <Text style={styles.uploadSubtitle}>{slot.uri ? 'Tap to replace this photo' : `Tap to upload the ${slot.side} side`}</Text>
            </Pressable>
            <View style={styles.idActions}>
              <Pressable accessibilityRole="button" accessibilityLabel={`Take photo for ${slot.label}`} onPress={() => chooseImage('camera', slot.side)} style={styles.idAction}><AuthIcon name="camera" size={18} /><Text style={styles.idActionText}>{slot.uri ? 'Retake photo' : 'Take photo'}</Text></Pressable>
              <Pressable accessibilityRole="button" accessibilityLabel={`Choose from gallery for ${slot.label}`} onPress={() => chooseImage('gallery', slot.side)} style={styles.idAction}><AuthIcon name="photo" size={18} /><Text style={styles.idActionText}>Choose from gallery</Text></Pressable>
            </View>
            {!!slot.uri && <Pressable onPress={() => {
              if (slot.side === 'front') {
                updateData('idFrontImageUri', ''); updateData('idFrontImageName', ''); updateData('idFrontImageMimeType', ''); updateData('idFrontImageSize', null);
              } else {
                updateData('idBackImageUri', ''); updateData('idBackImageName', ''); updateData('idBackImageMimeType', ''); updateData('idBackImageSize', null);
              }
            }}><Text style={styles.removeText}>Remove {slot.label}</Text></Pressable>}
            {!!slot.error && <Text style={styles.errorText}>{slot.error}</Text>}
          </View>)}
        <View onLayout={(event) => setFieldOffset('idNumber', event.nativeEvent.layout.y)} style={styles.rsbsaBlock}>
          <RsbsaReferenceField value={data.idNumber} onChangeValue={(value) => updateData('idNumber', value)} error={errors.idNumber} />
        </View>
        <Text style={styles.reviewNote}>An AgriReach administrator compares these photos and the number by hand. Uploading your RSBSA ID does not verify your RSBSA registration or approve your account by itself.</Text>
      </View>
    );
  }

  const isLastStep = step === 3;
  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar style="dark" />
      <KeyboardAvoidingView style={styles.keyboardView} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <View style={styles.header}>
          <Pressable accessibilityRole="button" accessibilityLabel={step === 1 ? 'Back to login' : 'Back to previous step'} onPress={() => step === 1 ? router.replace('/login') : setStep((step - 1) as Step)} style={styles.backButton}><AuthIcon name="back" /></Pressable>
          <AuthBrand compact /><View style={{ width: 44 }} />
        </View>
        <ScrollView ref={scrollRef} contentContainerStyle={[styles.scrollContent, { width: contentWidth }]} keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'} showsVerticalScrollIndicator={false}>
          <View style={styles.progressCard}>
            <AuthLandscape />
            <View style={styles.progressLine}><View style={[styles.progressFill, { width: step === 1 ? '0%' : step === 2 ? '50%' : '100%' }]} /></View>
            <View style={styles.progressRow}>{['RSBSA ID', 'Personal information', 'Farm & account'].map((label, index) => <View key={label} style={styles.progressItem} accessible accessibilityLabel={`Step ${index + 1}, ${label}${step === index + 1 ? ', current' : step > index + 1 ? ', complete' : ''}`}>
              <View style={[styles.progressDot, index + 1 <= step && styles.progressDotActive]}><Text style={styles.progressNumber}>{index + 1}</Text></View>
              <Text style={[styles.progressLabel, index + 1 === step && styles.progressLabelActive]}>{label}</Text>
            </View>)}</View>
          </View>
          <Text style={styles.pageTitle}>{step === 1 ? 'RSBSA ID' : step === 2 ? 'Personal information' : 'Farm information & account'}</Text>
          <Text style={styles.stepText}>Step {step} of 3</Text>
          <Text style={styles.stepIntro}>{step === 1 ? 'Upload clear photos of both sides of your RSBSA ID card.' : step === 2 ? 'Tell us about yourself and where you live.' : 'Tell us about your farm and create your password.'}</Text>
          <View onLayout={(event) => { formOffset.current = event.nativeEvent.layout.y; }}>{step === 1 ? renderRsbsaStep() : step === 2 ? renderPersonalStep() : renderStructuredFarmStep()}</View>
          {!!submissionError && <View style={styles.submissionErrorBox}><Text style={styles.submissionErrorText}>{submissionError}</Text></View>}
          <View style={styles.navigationRow}>
            {step > 1 && <Pressable accessibilityRole="button" accessibilityState={{ disabled: submitting }} disabled={submitting} onPress={() => setStep((step - 1) as Step)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Back</Text></Pressable>}
            <Pressable accessibilityRole="button" accessibilityState={{ disabled: submitting, busy: submitting }} disabled={submitting} onPress={isLastStep ? handleSubmit : handleNext} style={[styles.primaryButton, step === 1 && styles.fullButton, submitting && styles.disabledButton]}>{submitting ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.primaryButtonText}>{isLastStep ? 'Submit for verification' : 'Continue'}</Text>}</Pressable>
          </View>
          {isLastStep && <Text style={styles.submitNote}>An administrator reviews your registration before you can log in.</Text>}
        </ScrollView>
      </KeyboardAvoidingView>

      <ChoiceModal visible={genderPickerOpen} title="Select gender" options={GENDERS} onClose={() => setGenderPickerOpen(false)} onSelect={(value) => { updateData('gender', value); setGenderPickerOpen(false); }} />
      <ChoiceModal visible={farmPickerOpen} title="Select farm type" options={FARM_TYPES} onClose={() => setFarmPickerOpen(false)} onSelect={(value) => { updateData('farmType', value); setFarmPickerOpen(false); }} />
      <BarangayPickerModal
        visible={residentialBarangayPickerOpen}
        title="Select residential barangay"
        selectedCode={data.residentialBarangayCode}
        onClose={() => setResidentialBarangayPickerOpen(false)}
        onSelect={(barangay) => {
          updateData('residentialBarangayCode', barangay.code);
          if (data.residentialLocationMethod === 'Map') {
            setResidentialBarangayResolutionMessage(`${barangay.name} selected by the farmer for the confirmed home point.`);
            if (barangay.code !== residentialMapSuggestedBarangayCode) setResidentialMapSuggestedBarangayCode('');
          }
          setResidentialBarangayPickerOpen(false);
        }}
      />
      <BarangayPickerModal
        visible={barangayPickerOpen}
        title="Select farm barangay"
        selectedCode={data.farmBarangayCode}
        onClose={() => setBarangayPickerOpen(false)}
        onSelect={(barangay) => {
          updateData('farmBarangayCode', barangay.code);
          if (data.farmLocationMethod === 'Map') {
            setBarangayResolutionMessage(`${barangay.name} selected by the farmer for the confirmed map point.`);
            if (barangay.code !== mapSuggestedBarangayCode) setMapSuggestedBarangayCode('');
          }
          setBarangayPickerOpen(false);
        }}
      />
      <Modal visible={residentialMapOpen} animationType="slide" presentationStyle="fullScreen" onRequestClose={closeResidentialMap}>
        <SafeAreaProvider>
        <SafeAreaView style={styles.mapScreen} edges={['top', 'right', 'bottom', 'left']}>
          <View style={styles.mapHeader}>
            <View style={styles.mapHeaderText}><Text style={styles.mapTitle}>Pin my home location</Text><Text style={styles.mapSubtitle}>Move the pin to your actual home. Your phone position is only a starting point.</Text></View>
            <Pressable accessibilityRole="button" accessibilityLabel="Close home map" hitSlop={10} onPress={closeResidentialMap} style={styles.mapClose}><Text style={styles.mapCloseText}>×</Text></Pressable>
          </View>
          <ScrollView style={styles.mapBody} contentContainerStyle={styles.mapBodyContent} keyboardShouldPersistTaps="handled" scrollEnabled={!mapGestureActive}>
          <RegistrationPinMap key={residentialMapOpen ? `home-open-${data.residentialLatitude ?? 'city'}-${data.residentialLongitude ?? 'center'}` : 'home-closed'} pin={residentialDraftPin} initialRegion={residentialMapRegion} focus={residentialMapFocus} onPick={updateResidentialDraftPin} height={Math.max(180, Math.min(360, height * 0.4))} pinLabel="home" onGestureActive={setMapGestureActive} />
          <View style={styles.mapActions}>
            <Text style={styles.mapCityLabel}>{CABADBARAN_CITY.name}, {CABADBARAN_CITY.province}</Text>
            <Text style={styles.mapHelp}>Tap the map to place the pin on your actual home, then check the coordinates below. By tapping Confirm Home Pin, you confirm that this point marks your home. The server also checks that it is inside the mapped Cabadbaran City boundary.</Text>
            <PinReview pin={residentialDraftPin} pinLabel="home" confirmed={data.residentialPinConfirmed && data.residentialLatitude === residentialDraftPin?.latitude && data.residentialLongitude === residentialDraftPin?.longitude} />
            {!!errors.residentialPin && <Text style={styles.errorText} accessibilityRole="alert">{errors.residentialPin}</Text>}
            <Pressable disabled={residentialLocationBusy} onPress={useResidentialGpsLocation} style={styles.mapSecondaryButton}>
              {residentialLocationBusy ? <ActivityIndicator color={GREEN} /> : <Text style={styles.mapSecondaryButtonText}>Use my current location as starting point</Text>}
            </Pressable>
            {!!residentialReverseGeocodeSuggestion && <View style={styles.suggestionBox}><Text style={styles.suggestionLabel}>Address suggestion (please confirm yourself)</Text><Text style={styles.suggestionText}>{residentialReverseGeocodeSuggestion}</Text></View>}
            {!!residentialLocationMessage && <Text style={styles.locationMessage}>{residentialLocationMessage}</Text>}
          </View>
          </ScrollView>
            <View style={styles.mapConfirmFooter}>
              <Pressable onPress={closeResidentialMap} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
              <Pressable disabled={!residentialDraftPin || residentialPinValidationBusy} onPress={confirmResidentialPin} style={[styles.primaryButton, (!residentialDraftPin || residentialPinValidationBusy) && styles.disabledButton]}>{residentialPinValidationBusy ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.primaryButtonText}>Confirm Home Pin</Text>}</Pressable>
            </View>
        </SafeAreaView>
        </SafeAreaProvider>
      </Modal>
      <Modal visible={mapOpen} animationType="slide" presentationStyle="fullScreen" onRequestClose={closeFarmMap}>
        <SafeAreaProvider>
        <SafeAreaView style={styles.mapScreen} edges={['top', 'right', 'bottom', 'left']}>
          <View style={styles.mapHeader}>
            <View style={styles.mapHeaderText}><Text style={styles.mapTitle}>Pin farm location</Text><Text style={styles.mapSubtitle}>Move the pin to the actual farm, not just your current position.</Text></View>
            <Pressable accessibilityRole="button" accessibilityLabel="Close map" hitSlop={10} onPress={closeFarmMap} style={styles.mapClose}><Text style={styles.mapCloseText}>×</Text></Pressable>
          </View>
          <ScrollView style={styles.mapBody} contentContainerStyle={styles.mapBodyContent} keyboardShouldPersistTaps="handled" scrollEnabled={!mapGestureActive}>
          <RegistrationPinMap key={mapOpen ? `open-${data.latitude ?? 'city'}-${data.longitude ?? 'center'}` : 'closed'} pin={draftPin} initialRegion={mapRegion} focus={farmMapFocus} onPick={updateDraftPin} height={Math.max(180, Math.min(360, height * 0.4))} pinLabel="farm" onGestureActive={setMapGestureActive} />
          <View style={styles.mapActions}>
            <Text style={styles.mapCityLabel}>{CABADBARAN_CITY.name}, {CABADBARAN_CITY.province}</Text>
            <Text style={styles.mapHelp}>Tap the map to place the pin on the actual farm, then check the coordinates below. The server checks the point against a mapped city boundary when you confirm.</Text>
            <PinReview pin={draftPin} pinLabel="farm" confirmed={data.pinConfirmed && data.latitude === draftPin?.latitude && data.longitude === draftPin?.longitude} />
            {!!errors.farmPin && <Text style={styles.errorText} accessibilityRole="alert">{errors.farmPin}</Text>}
            <Pressable disabled={locationBusy} onPress={useGpsLocation} style={styles.mapSecondaryButton}>
              {locationBusy ? <ActivityIndicator color={GREEN} /> : <Text style={styles.mapSecondaryButtonText}>Use my current location as starting point</Text>}
            </Pressable>
            {!!reverseGeocodeSuggestion && <View style={styles.suggestionBox}><Text style={styles.suggestionLabel}>Address suggestion (please confirm yourself)</Text><Text style={styles.suggestionText}>{reverseGeocodeSuggestion}</Text></View>}
            {!!locationMessage && <Text style={styles.locationMessage}>{locationMessage}</Text>}
          </View>
          </ScrollView>
            <View style={styles.mapConfirmFooter}>
              <Pressable onPress={closeFarmMap} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
              <Pressable disabled={!draftPin || pinValidationBusy} onPress={confirmFarmPin} style={[styles.primaryButton, (!draftPin || pinValidationBusy) && styles.disabledButton]}>{pinValidationBusy ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.primaryButtonText}>Confirm Farm Pin</Text>}</Pressable>
            </View>
        </SafeAreaView>
        </SafeAreaProvider>
      </Modal>
      <Modal visible={uploadModalOpen} transparent animationType="slide" onRequestClose={() => setUploadModalOpen(false)}>
        <View style={styles.modalBackdrop}><View style={styles.choiceSheet}><View style={styles.sheetHandle} /><Text style={styles.sheetTitle}>Add {uploadSide === 'front' ? 'Front of RSBSA ID' : 'Back of RSBSA ID'}</Text><Pressable onPress={() => chooseImage('camera')} style={styles.choiceRow}><Text style={styles.choiceText}>Take Photo</Text></Pressable><Pressable onPress={() => chooseImage('gallery')} style={styles.choiceRow}><Text style={styles.choiceText}>Choose from Gallery</Text></Pressable><Pressable onPress={() => setUploadModalOpen(false)} style={styles.sheetCancel}><Text style={styles.sheetCancelText}>Cancel</Text></Pressable></View></View>
      </Modal>
      {datePickerOpen && Platform.OS !== 'ios' && <DateTimePicker value={dateDraft} mode="date" display="default" maximumDate={new Date()} onChange={handleDateChange} />}
      {datePickerOpen && Platform.OS === 'ios' && <Modal visible transparent animationType="fade" onRequestClose={() => setDatePickerOpen(false)}>
        <View style={styles.modalBackdrop}><View style={styles.dateSheet}>
          <Text style={styles.dateSheetTitle}>Date of Birth</Text>
          <DateTimePicker value={dateDraft} mode="date" display="spinner" maximumDate={new Date()} onChange={handleDateChange} themeVariant="light" textColor={TEXT} accentColor={GREEN} style={styles.datePicker} />
          <Text style={styles.dateSelected}>Selected: {formatDate(dateDraft)}</Text>
          <View style={styles.mapFooterButtons}>
            <Pressable accessibilityRole="button" onPress={() => setDatePickerOpen(false)} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Cancel</Text></Pressable>
            <Pressable accessibilityRole="button" onPress={() => { updateData('dateOfBirth', dateDraft); setDatePickerOpen(false); }} style={styles.primaryButton}><Text style={styles.primaryButtonText}>Confirm Date</Text></Pressable>
          </View>
        </View></View>
      </Modal>}
      <Modal visible={successVisible} transparent animationType="fade"><View style={styles.modalBackdrop}><View style={styles.successCard}><Text style={styles.successIcon}>✓</Text><Text style={styles.successTitle}>Registration submitted successfully.</Text><Text style={styles.successText}>Your information is being prepared for review.</Text></View></View></Modal>
    </SafeAreaView>
  );
}

function ImagePreview({ uri }: { uri: string }) {
  const { Image } = require('react-native');
  return <Image source={{ uri }} style={styles.previewImage} resizeMode="contain" />;
}

function formatDate(date: Date) {
  return `${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}/${date.getFullYear()}`;
}

function formatDatabaseDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: authColors.ivory }, keyboardView: { flex: 1, minHeight: 0 },
  header: { minHeight: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16 },
  scrollContent: { flexGrow: 1, alignSelf: 'center', paddingTop: 4, paddingBottom: 32 }, backButton: { minHeight: 44, minWidth: 44, justifyContent: 'center' },
  progressCard: { paddingTop: 10, paddingBottom: 16, marginBottom: 16, position: 'relative' },
  progressRow: { flexDirection: 'row', zIndex: 1 }, progressItem: { flex: 1, alignItems: 'center', gap: 8 },
  progressLine: { position: 'absolute', top: 24, left: '16.66%', right: '16.66%', height: 2, backgroundColor: '#BBC4B7' },
  progressFill: { height: 2, backgroundColor: GREEN }, progressDot: { width: 28, height: 28, borderRadius: 14, backgroundColor: '#B8C0B4', justifyContent: 'center', alignItems: 'center' }, progressDotActive: { backgroundColor: GREEN },
  progressNumber: { color: authColors.paper, fontSize: 13, fontWeight: '600' }, progressLabel: { color: OLIVE, fontSize: 11, lineHeight: 15, textAlign: 'center', maxWidth: 78 }, progressLabelActive: { color: TEXT, fontWeight: '700' },
  pageTitle: { color: authColors.deep, fontSize: 25, lineHeight: 31, fontWeight: '800', letterSpacing: -.5 },
  stepText: { color: OLIVE, fontSize: 13, marginTop: 4 }, stepIntro: { color: OLIVE, fontSize: 13, lineHeight: 19, marginTop: 4, marginBottom: 14 },
  formCard: { borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: authColors.paper, padding: 14 },
  nameRow: { flexDirection: 'row', gap: 10 }, nameCell: { flex: 1, minWidth: 0 },
  sectionTitle: { color: authColors.deep, fontSize: 16, fontWeight: '700', backgroundColor: '#F0F3E7', marginHorizontal: -14, marginTop: -14, paddingHorizontal: 14, paddingVertical: 11, borderTopLeftRadius: 15, borderTopRightRadius: 15, marginBottom: 14 },
  subsectionTitle: { color: authColors.deep, fontSize: 16, fontWeight: '700', backgroundColor: '#F0F3E7', marginHorizontal: -14, marginTop: 10, paddingHorizontal: 14, paddingVertical: 11, marginBottom: 14 },
  categoryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 }, category: { flexGrow: 1, flexBasis: 65, minWidth: 60, minHeight: 72, borderWidth: 1, borderColor: BORDER, borderRadius: 10, alignItems: 'center', justifyContent: 'center', gap: 7, padding: 7 }, categorySelected: { borderColor: GREEN, backgroundColor: authColors.sage }, categoryText: { color: TEXT, fontSize: 11, fontWeight: '500' },
  fieldGroup: { marginBottom: 16 },
  fixedLocationCard: { borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#F1F7EC', paddingHorizontal: 16, paddingVertical: 13, marginBottom: 16 },
  fixedLocationLabel: { color: OLIVE, fontSize: 12, fontWeight: '700' },
  fixedLocationValue: { color: TEXT, fontSize: 15, fontWeight: '700', marginTop: 4 },
  fixedLocationHelp: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: 5 },
  label: { color: TEXT, fontSize: 13, fontWeight: '600', marginBottom: 7 },
  inputShell: { minHeight: 48, flexDirection: 'row', alignItems: 'center', gap: 9, borderWidth: 1, borderColor: BORDER, borderRadius: 9, backgroundColor: '#FFFDF8', paddingHorizontal: 11 },
  inputShellError: { borderColor: '#B14545' },
  inputIcon: { color: OLIVE, width: 28, textAlign: 'center', fontSize: 18, marginRight: 6 },
  input: { flex: 1, minWidth: 0, color: TEXT, fontSize: 16, minHeight: 46, paddingVertical: 10 },
  phonePrefix: { color: TEXT, fontSize: 16, fontWeight: '800', minWidth: 39 },
  phoneDivider: { width: 1, height: 27, backgroundColor: BORDER, marginRight: 12 },
  visibilityButton: { width: 38, minHeight: 48, alignItems: 'center', justifyContent: 'center', marginLeft: 4 },
  visibilityIcon: { color: OLIVE, fontSize: 22 },
  multilineInput: { minHeight: 90, textAlignVertical: 'top', paddingTop: 14 },
  passwordHint: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: -8, marginLeft: 3 },
  fieldHelp: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: -10, marginBottom: 16, marginHorizontal: 3 },
  rsbsaBlock: { marginTop: 16 },
  rsbsaRow: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  rsbsaShell: { minWidth: 0, gap: 0, paddingHorizontal: 3, justifyContent: 'center' },
  rsbsaInput: { textAlign: 'center', fontSize: 15, fontWeight: '700', paddingHorizontal: 0 },
  rsbsaSeparator: { color: OLIVE, fontSize: 16, fontWeight: '700' },
  rsbsaHelp: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: 7, marginHorizontal: 3 },
  mapSuggestionSuccess: { color: GREEN, fontSize: 12, lineHeight: 18, marginTop: -9, marginBottom: 15, marginHorizontal: 3 },
  mapSuggestionHelp: { color: OLIVE, fontSize: 12, lineHeight: 18, marginTop: -9, marginBottom: 15, marginHorizontal: 3 },
  selectText: { flex: 1, color: TEXT, fontSize: 16 },
  placeholderText: { color: '#9AA08D' },
  chevron: { color: OLIVE, fontSize: 20 },
  errorText: { color: '#B14545', fontSize: 13, marginTop: 6, marginLeft: 3 },
  labelRow: { flexDirection: 'row', alignItems: 'center', gap: 7, marginBottom: 8 },
  infoIcon: { borderWidth: 1, borderColor: OLIVE, color: OLIVE, borderRadius: 10, width: 17, height: 17, textAlign: 'center', fontSize: 12 },
  locationInstruction: { color: OLIVE, fontSize: 13, lineHeight: 19, marginTop: -2, marginBottom: 10 },
  locationChoice: { minHeight: 68, flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: BORDER, borderRadius: 11, backgroundColor: authColors.paper, padding: 12, marginBottom: 10 },
  locationChoiceActive: { borderColor: GREEN, backgroundColor: '#F1F7EC' },
  radioDot: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: '#929C84', marginRight: 12 },
  radioDotActive: { borderWidth: 6, borderColor: GREEN },
  locationChoiceText: { flex: 1 },
  locationChoiceTitle: { color: TEXT, fontSize: 15, fontWeight: '700' },
  locationChoiceDescription: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: 3 },
  gpsBox: { minHeight: 90, borderWidth: 1, borderColor: '#C5D8B8', backgroundColor: '#F1F5E9', borderRadius: 11, alignItems: 'center', justifyContent: 'center', gap: 4, padding: 12 },
  gpsBoxError: { borderColor: '#B14545' },
  gpsIcon: { color: GREEN, fontSize: 28, marginBottom: 5 },
  gpsTitle: { color: GREEN, fontSize: 16, fontWeight: '700' },
  gpsSubtitle: { color: OLIVE, fontSize: 13, marginTop: 5, textAlign: 'center' },
  coordinatesRow: { alignItems: 'center', marginTop: 10, gap: 3 },
  coordinateText: { color: OLIVE, fontSize: 12 },
  locationMessage: { color: OLIVE, fontSize: 13, marginTop: 8, lineHeight: 18 },
  manualToggle: { alignSelf: 'flex-start', paddingVertical: 10 },
  manualToggleText: { color: GREEN, textDecorationLine: 'underline', fontSize: 14, fontWeight: '600' },
  uploadBox: { minHeight: 120, borderWidth: 1, borderStyle: 'dashed', borderColor: '#C5D1BC', backgroundColor: '#FAFBF6', borderRadius: 10, alignItems: 'center', justifyContent: 'center', gap: 4, padding: 12 },
  uploadBoxError: { borderColor: '#B14545' },
  uploadIcon: { color: GREEN, fontSize: 36, marginBottom: 6 },
  uploadedTitle: { color: GREEN, fontSize: 17, fontWeight: '800', marginBottom: 6 },
  uploadTitle: { color: GREEN, fontSize: 14, fontWeight: '700' },
  uploadSubtitle: { color: OLIVE, fontSize: 13, textAlign: 'center', marginTop: 5 },
  acceptedText: { color: '#7C8475', fontSize: 13, marginTop: 12, lineHeight: 18 },
  accountIntro: { color: '#7C8475', fontSize: 13, lineHeight: 18, marginTop: -2, marginBottom: 14 },
  reviewNote: { color: GREEN, fontSize: 12, lineHeight: 18, marginTop: 8, marginBottom: 12 },
  submitNote: { color: OLIVE, fontSize: 12, lineHeight: 17, textAlign: 'center', marginTop: 10 },
  idSlotGrid: { gap: 18, marginTop: 14 },
  idSlot: { minWidth: 0, borderWidth: 1, borderColor: BORDER, borderRadius: 12, padding: 12, marginTop: 14 },
  idHeading: { flexDirection: 'row', gap: 9, alignItems: 'center', marginBottom: 4 },
  idActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 9 }, idAction: { flex: 1, flexBasis: 115, minHeight: 44, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, borderWidth: 1, borderColor: BORDER, borderRadius: 8, padding: 8 }, idActionText: { color: TEXT, fontSize: 12, flexShrink: 1, textAlign: 'center' },
  previewWrap: { alignItems: 'center', marginTop: 14 },
  previewImage: { width: '100%', height: 170, borderRadius: 13, backgroundColor: '#E9EEE3' },
  removeText: { color: '#B14545', fontWeight: '600', marginTop: 8 },
  submissionErrorBox: { borderWidth: 1, borderColor: '#DCAAAA', backgroundColor: '#FFF4F3', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 11, marginTop: 18 },
  submissionErrorText: { color: '#9E3434', fontSize: 13, lineHeight: 18 },
  navigationRow: { flexDirection: 'row', gap: 12, marginTop: 18 },
  primaryButton: { flex: 1, minWidth: 0, minHeight: 50, borderRadius: 11, backgroundColor: GREEN, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10, paddingVertical: 12 },
  fullButton: { flex: 1 },
  primaryButtonText: { color: '#FFFFFF', fontSize: 15, fontWeight: '700', textAlign: 'center' },
  disabledButton: { opacity: 0.65 },
  secondaryButton: { flex: 1, minWidth: 0, minHeight: 50, borderRadius: 11, borderWidth: 1, borderColor: GREEN, backgroundColor: authColors.paper, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10, paddingVertical: 12 },
  secondaryButtonText: { color: GREEN, fontSize: 15, fontWeight: '700', textAlign: 'center' },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)', justifyContent: 'flex-end' },
  choiceSheet: { backgroundColor: '#FFFFFF', borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 22, paddingBottom: 30 },
  sheetHandle: { width: 42, height: 4, borderRadius: 4, backgroundColor: '#CBD3C4', alignSelf: 'center', marginBottom: 16 },
  sheetTitle: { color: TEXT, fontSize: 20, fontWeight: '800', marginBottom: 10 },
  choiceRow: { minHeight: 52, justifyContent: 'center', borderBottomWidth: 1, borderBottomColor: '#EEF1EB' },
  choiceText: { color: TEXT, fontSize: 16 },
  sheetCancel: { minHeight: 50, alignItems: 'center', justifyContent: 'center', marginTop: 10 },
  sheetCancelText: { color: GREEN, fontSize: 16, fontWeight: '700' },
  barangayPickerSafeArea: { flex: 1, backgroundColor: '#FCFDF9' },
  barangayPickerKeyboard: { flex: 1, minHeight: 0 },
  barangayPickerHeader: { minHeight: 72, flexDirection: 'row', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: BORDER, backgroundColor: '#FFFFFF', paddingHorizontal: 18, paddingVertical: 10 },
  barangayPickerHeading: { flex: 1, minWidth: 0, paddingRight: 12 },
  barangayPickerEyebrow: { color: GREEN, fontSize: 11, fontWeight: '800', letterSpacing: 0.7, textTransform: 'uppercase' },
  barangayPickerTitle: { color: TEXT, fontSize: 21, fontWeight: '800', marginTop: 2 },
  barangayPickerClose: { minWidth: 58, minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
  barangayPickerCloseText: { color: GREEN, fontSize: 15, fontWeight: '800' },
  barangaySearchWrap: { backgroundColor: '#FFFFFF', paddingHorizontal: 18, paddingTop: 12, paddingBottom: 10 },
  barangaySearchInput: { minHeight: 50, borderWidth: 1, borderColor: BORDER, borderRadius: 14, backgroundColor: '#F8FAF5', color: TEXT, fontSize: 16, paddingHorizontal: 15 },
  barangayList: { flex: 1, minHeight: 0 },
  barangayListContent: { paddingHorizontal: 16, paddingTop: 4, paddingBottom: 18 },
  barangayListEmptyContent: { flexGrow: 1, alignItems: 'center', justifyContent: 'center' },
  barangayRow: { minHeight: 58, flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: '#E3E9DD', borderRadius: 14, backgroundColor: '#FFFFFF', paddingHorizontal: 15, paddingVertical: 10, marginTop: 9 },
  barangayRowSelected: { borderColor: GREEN, backgroundColor: '#EDF6E8' },
  barangayRowPressed: { opacity: 0.75 },
  barangayRadio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: '#929C84', marginRight: 12 },
  barangayRadioSelected: { borderWidth: 6, borderColor: GREEN },
  barangayRowText: { flex: 1, color: TEXT, fontSize: 16, fontWeight: '600' },
  barangayRowTextSelected: { color: GREEN, fontWeight: '800' },
  barangaySelectedText: { color: GREEN, fontSize: 11, fontWeight: '800', marginLeft: 8 },
  barangayEmptyText: { color: OLIVE, fontSize: 14, lineHeight: 20, textAlign: 'center', paddingHorizontal: 28 },
  barangayPickerFooter: { borderTopWidth: 1, borderTopColor: BORDER, backgroundColor: '#FFFFFF', paddingHorizontal: 18, paddingTop: 10, paddingBottom: 8 },
  barangayCancelButton: { minHeight: 50, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: GREEN, borderRadius: 14 },
  barangayCancelText: { color: GREEN, fontSize: 16, fontWeight: '800' },
  mapScreen: { flex: 1, backgroundColor: '#FCFDF9' },
  mapHeader: { flexDirection: 'row', alignItems: 'flex-start', backgroundColor: '#FFFFFF', paddingHorizontal: 18, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: BORDER },
  mapHeaderText: { flex: 1, paddingRight: 12 },
  mapTitle: { color: TEXT, fontSize: 20, fontWeight: '800' },
  mapSubtitle: { color: OLIVE, fontSize: 13, lineHeight: 18, marginTop: 3 },
  mapClose: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  mapCloseText: { color: TEXT, fontSize: 30, lineHeight: 32 },
  mapBody: { flex: 1 },
  mapBodyContent: { flexGrow: 1 },
  map: { width: '100%', minHeight: 180 },
  mapConfirmFooter: { flexDirection: 'row', gap: 10, flexShrink: 0, borderTopWidth: 1, borderTopColor: BORDER, backgroundColor: '#FFFFFF', paddingHorizontal: 18, paddingVertical: 12 },
  mapActions: { backgroundColor: '#FFFFFF', borderTopWidth: 1, borderTopColor: BORDER, paddingHorizontal: 18, paddingTop: 12, paddingBottom: 14 },
  mapCityLabel: { color: TEXT, fontSize: 14, fontWeight: '800' },
  mapHelp: { color: OLIVE, fontSize: 12, lineHeight: 17, marginTop: 3 },
  mapSecondaryButton: { minHeight: 44, borderWidth: 1, borderColor: GREEN, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginTop: 10, paddingHorizontal: 12 },
  mapSecondaryButtonText: { color: GREEN, fontSize: 14, fontWeight: '700', textAlign: 'center' },
  suggestionBox: { backgroundColor: '#F1F7EC', borderRadius: 10, padding: 10, marginTop: 9 },
  suggestionLabel: { color: GREEN, fontSize: 11, fontWeight: '800' },
  suggestionText: { color: TEXT, fontSize: 12, lineHeight: 17, marginTop: 2 },
  mapFooterButtons: { flexDirection: 'row', gap: 10, marginTop: 12 },
  dateSheet: { backgroundColor: '#FFFFFF', padding: 20, borderRadius: 20, margin: 20 },
  dateSheetTitle: { color: TEXT, fontSize: 18, fontWeight: '800' },
  datePicker: { height: 190, backgroundColor: '#FFFFFF' },
  dateSelected: { color: TEXT, fontSize: 14, fontWeight: '600', textAlign: 'center', marginTop: 4 },
  successCard: { backgroundColor: '#FFFFFF', borderRadius: 20, padding: 26, margin: 28, alignItems: 'center' },
  successIcon: { color: GREEN, fontSize: 42, fontWeight: '800' },
  successTitle: { color: TEXT, fontSize: 19, fontWeight: '800', textAlign: 'center', marginTop: 10 },
  successText: { color: OLIVE, textAlign: 'center', marginTop: 8, lineHeight: 20 },
});
