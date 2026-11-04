import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { router } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  type ScrollViewInstance,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Action, AppScreen, categoryIcon, Hero, Icon, Motion, palette, ui, useReducedMotion } from '@/components/farm-ui';
import { causeLabel, cleanNumberInput, DAMAGE_DETAILS_CONFIG, damageDetailsErrors, damageDetailsFields, damageSummary, emptyDamageDetails, isDamageCategory, measureFor, subjectName, unitFor, type DamageDetailsDraft } from '@/utils/damageDetails';
import { FarmerDataApiError, listFarmerFarms, submitDamageReport, validateDamagePoint, type DamagePhoto, type FarmerFarm } from '@/services/farmerDataApi';
import { MAX_DAMAGE_IMAGE_BYTES, MAX_DAMAGE_PHOTOS, MAX_DAMAGE_TOTAL_BYTES, preparePickedImage, UploadImagePreparationError } from '@/services/uploadImages';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import DamageLocationMap from '@/components/damage-location-map';
import DamageManualPinMap from '@/components/damage-manual-pin-map';
import { freshCaptureEvidence, requestCaptureLocationAccess } from '@/services/photoLocation';
import { galleryPhotoEvidence } from '@/services/galleryPhotoEvidence';
import { galleryEvent, galleryOperation, GalleryTimeoutError } from '@/services/galleryOperation';
import { androidOriginalPickerAvailable, pickAndroidOriginalPhotos } from '@/services/androidSystemPhotoPicker';
import { MAX_CAPTURE_ACCURACY_METERS, emptyPhotoEvidence, evidenceFromExif, photoLocationLabel, pointDistanceMeters, validPoint, type PhotoEvidence } from '@/services/photoLocationMetadata';

const GREEN = '#174D36';
const OLIVE = '#637068';
const TEXT = '#172B24';
const BORDER = '#E2E5DA';
// Step-1 fields (damage details); every other field belongs to step 2 (photos & location).
const DETAIL_KEYS = ['farmId', 'farmType', 'subjectType', 'subjectTypeOther', 'suspectedCause', 'suspectedCauseOther', 'description', 'cropDamagePercent', 'plantedArea', 'affectedArea', 'fishUnit', 'totalBefore', 'amountLost'];
const FARM_TYPES = [
  { label: 'Crops', icon: '✦' },
  { label: 'Livestock', icon: '◉' },
  { label: 'Poultry', icon: '○' },
  { label: 'Fishpond', icon: '≈' },
];

function ChoiceModal({ visible, title, options, onClose, onSelect }: { visible: boolean; title: string; options: string[]; onClose: () => void; onSelect: (value: string) => void }) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.modalBackdrop}>
        <View style={styles.choiceSheet}>
          <View style={styles.sheetHandle} />
          <Text style={styles.sheetTitle}>{title}</Text>
          <ScrollView style={{ maxHeight: 380 }} keyboardShouldPersistTaps="handled">
            {options.map((option) => (
              <Pressable key={option} accessibilityRole="button" onPress={() => onSelect(option)} style={styles.choiceRow}>
                <Text style={styles.choiceText}>{option}</Text>
              </Pressable>
            ))}
          </ScrollView>
          <Pressable onPress={onClose} style={styles.cancelRow}><Text style={styles.cancelText}>Cancel</Text></Pressable>
        </View>
      </View>
    </Modal>
  );
}

function Field({ label, value, placeholder, onChangeText, error, multiline = false, compact = false, editable = true, keyboardType = 'default', optional = false, maxLength }: { label: string; value: string; placeholder: string; onChangeText: (value: string) => void; error?: string; multiline?: boolean; compact?: boolean; editable?: boolean; keyboardType?: 'default' | 'numeric' | 'numbers-and-punctuation'; optional?: boolean; maxLength?: number }) {
  return (
    <View style={styles.fieldGroup}>
      <Text style={styles.label}>{label}{optional ? <Text style={styles.optionalText}> (optional)</Text> : ' *'}</Text>
      <TextInput
        value={value}
        editable={editable}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor="#9AA08D"
        keyboardType={keyboardType}
        multiline={multiline}
        maxLength={maxLength}
        style={[styles.input, multiline && (compact ? styles.addressInput : styles.descriptionInput), error && styles.inputError]}
        textAlignVertical={multiline ? 'top' : 'center'}
      />
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

/** Large, readable number entry with its unit shown inside the field (%, head, birds, fish, kg, hectares). */
function NumberField({ label, value, unit, onChangeText, error, optional = false, decimal = false, help }: { label: string; value: string; unit: string; onChangeText: (value: string) => void; error?: string; optional?: boolean; decimal?: boolean; help?: string }) {
  return (
    <View style={styles.fieldGroup}>
      <Text style={styles.label}>{label}{optional ? <Text style={styles.optionalText}> (optional)</Text> : ' *'}</Text>
      <View style={[styles.numberShell, error && styles.inputError]}>
        <TextInput accessibilityLabel={`${label}, ${unit}`} value={value} onChangeText={(text) => onChangeText(cleanNumberInput(text, decimal))} placeholder="0" placeholderTextColor="#9AA08D" keyboardType={decimal ? 'decimal-pad' : 'number-pad'} inputMode={decimal ? 'decimal' : 'numeric'} maxLength={14} style={styles.numberInput} />
        <Text style={styles.numberUnit}>{unit}</Text>
      </View>
      {!!help && <Text style={styles.fieldHelp}>{help}</Text>}
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

export default function NewDamageReportScreen() {
  const { width } = useWindowDimensions();
  const [farmType, setFarmType] = useState('');
  const [farmId, setFarmId] = useState<number | null>(null);
  const [farms, setFarms] = useState<FarmerFarm[]>([]);
  const [farmsLoading, setFarmsLoading] = useState(true);
  const [farmsError, setFarmsError] = useState('');
  // Category-specific damage details; reset whenever the selected farm's category changes.
  const [details, setDetails] = useState<DamageDetailsDraft>(emptyDamageDetails);
  const [description, setDescription] = useState('');
  const [damageAddress, setDamageAddress] = useState('');
  const [damageLatitude, setDamageLatitude] = useState('');
  const [damageLongitude, setDamageLongitude] = useState('');
  const [locationBusy, setLocationBusy] = useState(false);
  const [locationMessage, setLocationMessage] = useState('');
  const [locationConfirmed, setLocationConfirmed] = useState(false);
  const [locationSource, setLocationSource] = useState<'photo' | 'device' | 'manual'>('manual');
  const [locationPhotoId, setLocationPhotoId] = useState<string | null>(null);
  const locationRevision = useRef(0);
  const photoLock = useRef(false);
  const gallerySheetDismissed = useRef<(() => void) | null>(null);
  const galleryNativePending = useRef(false);
  const submitLock = useRef(false);
  const [photos, setPhotos] = useState<DamagePhoto[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [detailPicker, setDetailPicker] = useState<'subject' | 'cause' | null>(null);
  const [photoOptionsOpen, setPhotoOptionsOpen] = useState(false);
  const [manualMapOpen, setManualMapOpen] = useState(false);
  const [manualDraft, setManualDraft] = useState<{ latitude: number; longitude: number } | null>(null);
  const [photoBusy, setPhotoBusy] = useState(false);
  const [submitBusy, setSubmitBusy] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const { refreshSession } = useFarmerAuth();
  const scrollRef = useRef<ScrollViewInstance>(null);
  const fieldOffsets = useRef<Record<string, number>>({});
  const clientSubmissionId = useRef(`report_${Date.now()}_${Math.random().toString(36).slice(2, 14)}`);
  const contentWidth = Math.min(width * 0.92, 560);
  const selectedPhoto = photos.find((photo) => photo.id === locationPhotoId);
  const hasCandidate = damageLatitude.trim() !== '' && damageLongitude.trim() !== '' && validPoint(Number(damageLatitude), Number(damageLongitude));
  const conflictingPhotos = photos.filter((photo) => validPoint(photo.latitude, photo.longitude));
  const maximumPhotoDistance = conflictingPhotos.reduce((max, photo) => Math.max(max, ...conflictingPhotos.map((other) => pointDistanceMeters(
    { latitude: photo.latitude!, longitude: photo.longitude! }, { latitude: other.latitude!, longitude: other.longitude! }))), 0);

  const [step, setStep] = useState<1 | 2 | 3>(1);
  const reducedMotion = useReducedMotion();
  const category = isDamageCategory(farmType) ? farmType : null;
  const categoryConfig = category ? DAMAGE_DETAILS_CONFIG[category] : null;
  const measure = category ? measureFor(category, details.fishUnit) : null;
  const lossSummary = category ? damageSummary(category, details) : '';

  function setDetail<K extends keyof DamageDetailsDraft>(key: K, value: DamageDetailsDraft[K]) {
    setDetails((current) => ({ ...current, [key]: value }));
    clearError(key);
  }

  /** Selecting a farm of another category clears the previous category's details (no stale values). */
  function selectFarm(farm: FarmerFarm) {
    if (farm.agriculturalCategory !== farmType) {
      setDetails(emptyDamageDetails());
      setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !DETAIL_KEYS.includes(key) || key === 'description')));
    }
    setFarmId(farm.id); setFarmType(farm.agriculturalCategory);
  }

  /** Changing between fish count and kilograms clears the figures: the two units are never mixed. */
  function selectFishUnit(unit: 'count' | 'kg') {
    if (details.fishUnit === unit) return;
    setDetails((current) => ({ ...current, fishUnit: unit, totalBefore: '', amountLost: '' }));
    setErrors((current) => ({ ...current, fishUnit: '', totalBefore: '', amountLost: '' }));
  }
  const [farmPickerOpen, setFarmPickerOpen] = useState(false);
  const [photoHelpOpen, setPhotoHelpOpen] = useState(false);

  function changeLocation(latitude: string, longitude: string, source: 'photo' | 'device' | 'manual', photoId: string | null = null) {
    locationRevision.current += 1;
    setDamageLatitude(latitude); setDamageLongitude(longitude);
    setLocationSource(source); setLocationPhotoId(photoId); setLocationConfirmed(false);
    clearError('damageLocation');
  }

  function selectPhotoLocation(photo: DamagePhoto) {
    const usable = validPoint(photo.latitude, photo.longitude) && photo.locationStatus === 'available';
    changeLocation(usable ? String(photo.latitude) : '', usable ? String(photo.longitude) : '', usable ? 'photo' : 'manual', usable ? photo.id : null);
    setLocationMessage(usable ? 'Photo location detected. Review the pin, then confirm that this is the damaged location.' : (photo.locationIssue || 'No accessible photo GPS. Enter the actual damage point manually.'));
    scrollToFirstError(['damageLocation']);
  }

  function openManualMap() {
    if (photoLock.current || submitLock.current || locationBusy) return;
    setManualDraft(hasCandidate ? { latitude: Number(damageLatitude), longitude: Number(damageLongitude) } : null);
    setManualMapOpen(true);
  }

  function useManualMapPoint() {
    if (!manualDraft || !validPoint(manualDraft.latitude, manualDraft.longitude)) return;
    changeLocation(manualDraft.latitude.toFixed(7), manualDraft.longitude.toFixed(7), 'manual');
    setLocationMessage('Farmer-selected map pin. This is not photo GPS. Review the point and confirm it against the Cabadbaran City boundary below.');
    setManualMapOpen(false);
  }

  function removePhoto(id: string) {
    if (submitLock.current || photoLock.current) return;
    setPhotos((current) => current.filter((photo) => photo.id !== id));
    if (locationPhotoId === id) {
      changeLocation('', '', 'manual');
      setLocationMessage('The selected photo was removed. Select and confirm another damage location.');
    }
  }

  function movePhoto(id: string, direction: -1 | 1) {
    if (submitLock.current || photoLock.current) return;
    setPhotos((current) => {
      const index = current.findIndex((photo) => photo.id === id);
      const nextIndex = index + direction;
      if (index < 0 || nextIndex < 0 || nextIndex >= current.length) return current;
      const reordered = [...current];
      [reordered[index], reordered[nextIndex]] = [reordered[nextIndex], reordered[index]];
      return reordered;
    });
  }

  async function confirmDamageLocation() {
    if (!hasCandidate || locationBusy) return;
    if (selectedPhoto?.locationStatus === 'poor_accuracy') {
      setError('damageLocation', 'This photo has insufficient GPS accuracy. Take a new photo with a clearer GPS fix or explicitly select the damage point manually.');
      return;
    }
    const revision = locationRevision.current;
    setLocationBusy(true); clearError('damageLocation');
    try {
      await validateDamagePoint(Number(damageLatitude), Number(damageLongitude));
      if (revision !== locationRevision.current) return;
      setLocationConfirmed(true);
      setLocationMessage('Damage point confirmed within the mapped Cabadbaran City boundary. Review the address/landmark below.');
    } catch (error) {
      if (revision !== locationRevision.current) return;
      setLocationConfirmed(false);
      setError('damageLocation', error instanceof Error ? error.message : 'The damage point could not be checked. Please try again.');
    } finally { setLocationBusy(false); }
  }

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const result = await listFarmerFarms();
        if (!active) return;
        const activeFarms = result.farms.filter((farm) => farm.status === 'Active');
        setFarms(activeFarms);
        if (activeFarms.length === 1) { setFarmId(activeFarms[0].id); setFarmType(activeFarms[0].agriculturalCategory); }
      } catch (error) { if (active) setFarmsError(error instanceof Error ? error.message : 'Farms could not be loaded.'); }
      finally { if (active) setFarmsLoading(false); }
    })();
    return () => { active = false; };
  }, []);

  function setError(key: string, message: string) {
    setErrors((current) => ({ ...current, [key]: message }));
  }

  function clearError(key: string) {
    if (errors[key]) setErrors((current) => ({ ...current, [key]: '' }));
  }

  function rememberField(key: string, y: number) {
    fieldOffsets.current[key] = y;
  }

  function scrollToFirstError(keys: string[]) {
    const target = DETAIL_KEYS.includes(keys[0]) ? 1 : 2;
    setStep(target);
    setTimeout(() => {
      const y = (fieldOffsets.current['_step' + target] || 0) + (fieldOffsets.current[keys[0]] || 0);
      scrollRef.current?.scrollTo({ y: Math.max(0, y - 24), animated: !reducedMotion });
    }, 80);
  }

  function navigateStep(target: 1 | 2 | 3) {
    if (photoBusy || submitBusy || locationBusy) return;
    if (target > step && !validate(step)) return;
    setStep(target);
    scrollRef.current?.scrollTo({ y: 0, animated: !reducedMotion });
  }

  function validate(scope?: 1 | 2 | 3) {
    const nextErrors: Record<string, string> = {};
    if (!farmId) nextErrors.farmId = 'Please select the affected farm.';
    if (!farmType) nextErrors.farmType = 'The selected farm category is unavailable.';
    else if (!category) nextErrors.farmType = 'This farm category is not supported for damage reports.';
    else if (farmId) Object.assign(nextErrors, damageDetailsErrors(category, details));
    // "What happened?" is optional; only its length is limited.
    if (description.trim().length > 5000) nextErrors.description = 'Keep the description to 5,000 characters or fewer.';
    if (damageAddress.trim().length < 5) nextErrors.damageAddress = 'Enter a clear damage location or landmark.';
    if (!locationConfirmed || !hasCandidate) nextErrors.damageLocation = 'Select a photo location or enter the actual damage point, then confirm “This is the damaged location”.';
    const hasLatitude = damageLatitude.trim() !== '';
    const hasLongitude = damageLongitude.trim() !== '';
    if (hasLatitude !== hasLongitude) nextErrors.damageLocation = 'Enter both latitude and longitude, or clear both.';
    if (hasLatitude && (!Number.isFinite(Number(damageLatitude)) || Number(damageLatitude) < -90 || Number(damageLatitude) > 90)) nextErrors.damageLatitude = 'Latitude must be from -90 to 90.';
    if (hasLongitude && (!Number.isFinite(Number(damageLongitude)) || Number(damageLongitude) < -180 || Number(damageLongitude) > 180)) nextErrors.damageLongitude = 'Longitude must be from -180 to 180.';
    if (!photos.length) nextErrors.photos = 'At least one damage photo is required.';
    else if (photos.length > MAX_DAMAGE_PHOTOS) nextErrors.photos = `You can attach up to ${MAX_DAMAGE_PHOTOS} damage photos.`;
    else if (photos.reduce((total, photo) => total + (photo.size || 0), 0) > MAX_DAMAGE_TOTAL_BYTES) nextErrors.photos = 'Keep the combined damage photos at or below 32MB.';
    if (scope === 1 || scope === 2) {
      for (const key of Object.keys(nextErrors)) {
        if ((scope === 1) !== DETAIL_KEYS.includes(key)) delete nextErrors[key];
      }
    }
    setErrors(nextErrors);
    const keys = Object.keys(nextErrors);
    if (keys.length) scrollToFirstError(keys);
    return !keys.length;
  }

  async function useMyLocation() {
    if (locationBusy || photoLock.current || submitLock.current) return;
    const revision = locationRevision.current;
    setLocationBusy(true);
    setLocationMessage('Getting your current location...');
    clearError('damageLocation');
    try {
      if (!(await Location.hasServicesEnabledAsync())) {
        setLocationMessage('Location services are off. Enter the actual damage coordinates and address manually, or turn on Location Services and try again.');
        return;
      }
      let permission = await Location.getForegroundPermissionsAsync();
      if (permission.status !== 'granted') permission = await Location.requestForegroundPermissionsAsync();
      if (permission.status !== 'granted') {
        setLocationMessage('Location permission was not granted. Choose a photo with accessible GPS or enter and confirm the actual damage coordinates manually.');
        return;
      }
      const position = await Promise.race([
        Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }),
        new Promise<never>((_, reject) => setTimeout(() => reject(new Error('location-timeout')), 8000)),
      ]);
      if (revision !== locationRevision.current) return;
      if (!validPoint(position.coords.latitude, position.coords.longitude) || Date.now() - position.timestamp > 30000 || position.coords.accuracy === null || position.coords.accuracy > MAX_CAPTURE_ACCURACY_METERS) throw new Error('A fresh, accurate GPS fix was not available. Please retry.');
      changeLocation(position.coords.latitude.toFixed(7), position.coords.longitude.toFixed(7), 'device');
      setLocationMessage('Current phone position shown only because you requested it. This does not change any photo metadata. Confirm only if this is the damage site.');
    } catch {
      setLocationMessage('A fresh, accurate location could not be retrieved. Retry, choose a geotagged photo, or enter and confirm the actual damage coordinates manually.');
    } finally {
      setLocationBusy(false);
    }
  }

  async function addPhoto(source: 'camera' | 'gallery') {
    if (photoLock.current || submitLock.current) return;
    if (source === 'gallery' && galleryNativePending.current) {
      setPhotoOptionsOpen(false);
      setError('photos', 'The previous system picker has not closed. Close it or reload Expo Go before trying again.');
      return;
    }
    if (source === 'camera') setPhotoOptionsOpen(false);
    if (photos.length >= MAX_DAMAGE_PHOTOS) {
      setPhotoOptionsOpen(false);
      setError('photos', `You can attach up to ${MAX_DAMAGE_PHOTOS} damage photos.`);
      return;
    }
    photoLock.current = true;
    setPhotoBusy(true);
    const addedIds: string[] = [];
    // Attaching Android gallery photos must not discard an explicitly selected
    // manual/device point. Photo metadata remains separate and selectable.
    const keepExplicitPoint = Platform.OS === 'android' && source === 'gallery' && hasCandidate && locationSource !== 'photo';
    try {
      if (source === 'gallery') {
        if (Platform.OS === 'ios' && photoOptionsOpen) {
          // UIKit cannot safely present a picker from a controller being dismissed.
          // Register first; launch only after RN reports the actual dismissal.
          await galleryOperation('sheet_dismiss', () => new Promise<void>((resolve) => {
            gallerySheetDismissed.current = resolve;
            setPhotoOptionsOpen(false);
          }), 5000);
        } else setPhotoOptionsOpen(false);
      }
      if (source === 'camera') {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) {
          setError('photos', 'Camera permission is required to take a photo. You can still choose a gallery photo.');
          return;
        }
      }
      // The system gallery picker remains usable when full-library metadata access
      // is denied. Only accessible metadata from the selected asset is used.
      let locationFailure: PhotoEvidence | null = null;
      if (source === 'camera') {
        try { locationFailure = await requestCaptureLocationAccess(); }
        catch { locationFailure = { ...emptyPhotoEvidence('Location could not be requested. Check permissions and take a new photo to retry.'), locationStatus: 'unavailable' }; }
      }
      let androidOriginal: Awaited<ReturnType<typeof pickAndroidOriginalPhotos>> | null = null;
      const result = source === 'camera'
        ? await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], allowsEditing: false, exif: true, quality: 1 })
        : Platform.OS === 'android' && androidOriginalPickerAvailable()
        ? await galleryOperation('picker', async () => {
          galleryNativePending.current = true;
          try {
            androidOriginal = await pickAndroidOriginalPhotos(Math.max(1, MAX_DAMAGE_PHOTOS - photos.length));
            return { canceled: androidOriginal.length === 0, assets: androidOriginal.map((item) => item.picked) };
          } finally { galleryNativePending.current = false; }
        }, 120000)
        : await galleryOperation('picker', async () => {
          galleryNativePending.current = true;
          try {
            return await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: false, exif: true, orderedSelection: true, preferredAssetRepresentationMode: ImagePicker.UIImagePickerPreferredAssetRepresentationMode.Current, allowsMultipleSelection: true, selectionLimit: Math.max(1, MAX_DAMAGE_PHOTOS - photos.length), quality: 1 });
          } finally { galleryNativePending.current = false; }
        }, 120000);
      if (result.canceled || !result.assets?.length) {
        if (source === 'gallery') galleryEvent('cancelled');
        return;
      }
      const cameraReturnedAt = Date.now();
      // One native camera invocation represents one new capture, never a batch.
      const assets = result.assets.slice(0, source === 'camera' ? 1 : MAX_DAMAGE_PHOTOS - photos.length);
      // Show original previews before GPS lookup/conversion, but block submission until prepared.
      const previews: DamagePhoto[] = assets.map((asset, index) => ({
        id: `${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`,
        uri: asset.uri, ...emptyPhotoEvidence('Reading location for this photo...'),
      }));
      addedIds.push(...previews.map((photo) => photo.id));
      setPhotos((current) => [...current, ...previews]);
      if (source === 'gallery') galleryEvent('previews_added');
      if (!keepExplicitPoint) selectPhotoLocation(previews[0]);
      const captureEvidence = source === 'camera' ? (locationFailure
        ? { ...locationFailure, capturedAt: new Date(cameraReturnedAt).toISOString(), captureTimeSource: 'camera_return' as const }
        : await freshCaptureEvidence(cameraReturnedAt, assets[0].exif)) : null;
      const selectedPhotos: DamagePhoto[] = [];
      let locatedSelected = false;
      for (let index = 0; index < previews.length; index += 1) {
        const evidence = captureEvidence || (Platform.OS === 'android' && androidOriginal ? (androidOriginal as Awaited<ReturnType<typeof pickAndroidOriginalPhotos>>)[index].evidence : null) || await galleryOperation('metadata', () => galleryPhotoEvidence(assets[index]), 35000)
          .catch((error) => {
            // Optional metadata must never prevent attaching a photo. Keep only
            // this asset's validated picker EXIF if the native lookup fails.
            const fallback = evidenceFromExif(assets[index].exif);
            if (fallback.latitude === null) fallback.locationIssue = error instanceof GalleryTimeoutError
              ? 'No accessible photo GPS: metadata reading timed out. You can still attach this photo.'
              : 'No accessible photo GPS: metadata could not be read. You can still attach this photo.';
            return fallback;
          });
        const preview = { ...previews[index], ...evidence };
        setPhotos((current) => current.map((photo) => photo.id === preview.id ? preview : photo));
        if (!keepExplicitPoint && ((!locatedSelected && validPoint(evidence.latitude, evidence.longitude)) || index === 0)) {
          selectPhotoLocation(preview);
          locatedSelected = validPoint(evidence.latitude, evidence.longitude);
        }
        const prepare = () => preparePickedImage(assets[index], `damage-${Date.now()}-${index + 1}`, MAX_DAMAGE_IMAGE_BYTES);
        const prepared = source === 'camera' ? await prepare() : await galleryOperation('conversion', prepare, 30000);
        const readyPhoto: DamagePhoto = {
          ...preview,
          uri: prepared.uri,
          mimeType: prepared.mimeType,
          fileName: prepared.fileName,
          size: prepared.size,
        };
        selectedPhotos.push(readyPhoto);
        setPhotos((current) => current.map((photo) => photo.id === readyPhoto.id ? readyPhoto : photo));
        if (source === 'gallery') galleryEvent('form_updated');
      }
      const combinedBytes = [...photos, ...selectedPhotos].reduce((total, photo) => total + (photo.size || 0), 0);
      if (combinedBytes > MAX_DAMAGE_TOTAL_BYTES) {
        throw new UploadImagePreparationError('Those photos exceed the 32MB combined upload limit. Choose fewer or smaller photos.');
      }
      clearError('photos');
    } catch (error) {
      if (source === 'gallery') galleryEvent('failed');
      if (addedIds.length) {
        setPhotos((current) => current.filter((photo) => !addedIds.includes(photo.id)));
        if (!keepExplicitPoint) {
          changeLocation('', '', 'manual');
          setLocationMessage('The new photos could not be prepared. Existing photos are kept; select and confirm the damage point again.');
        }
      }
      setError('photos', error instanceof GalleryTimeoutError || error instanceof UploadImagePreparationError ? error.message
        : source === 'gallery' ? 'The gallery photo could not be added. Check Photos access and that the photo is downloaded, then retry.'
          : error instanceof Error ? error.message : 'The photo could not be added. Please try again.');
    } finally {
      gallerySheetDismissed.current = null;
      photoLock.current = false;
      setPhotoBusy(false);
      if (source === 'gallery') galleryEvent('loading_cleared');
    }
  }

  async function submitReport() {
    if (submitLock.current || photoLock.current || locationBusy || !validate()) return;
    submitLock.current = true;
    setSubmitBusy(true);
    setSubmitError('');
    try {
      await submitDamageReport({
      farmId: farmId as number,
      farmType,
      description: description.trim(),
      detailFields: damageDetailsFields(category!, details),
      photos,
      damageAddress: damageAddress.trim(),
      damageLatitude: damageLatitude.trim() === '' ? null : Number(damageLatitude),
      damageLongitude: damageLongitude.trim() === '' ? null : Number(damageLongitude),
      clientSubmissionId: clientSubmissionId.current,
      locationConfirmed, locationSource, locationPhotoId,
      });
      router.replace('/report-success');
    } catch (error) {
      if (error instanceof FarmerDataApiError) {
        if (error.status === 401) await refreshSession();
        setErrors((current) => ({ ...current, ...error.errors }));
        const serverErrorFields = Object.keys(error.errors);
        if (serverErrorFields.length) scrollToFirstError(serverErrorFields);
      }
      setSubmitError(error instanceof Error ? error.message : 'The damage report could not be submitted.');
    } finally {
      submitLock.current = false;
      setSubmitBusy(false);
    }
  }

  return (
    <AppScreen back>
      <KeyboardAvoidingView style={styles.keyboardView} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView ref={scrollRef} contentContainerStyle={[styles.scrollContent, { width: contentWidth }]} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          {step === 1 && <Hero kind="report" compact title="Report Damage" subtitle="Document damage to your registered farm." />}
          <Motion trigger={step} style={[ui.card, { marginBottom: 14, marginTop: 8, gap: 8 }]}>
            <Text style={[ui.cardTitle, { textAlign: 'center' }]}>{step} of 3 · {step === 1 ? 'Damage details' : step === 2 ? 'Photos & location' : 'Review report'}</Text>
            <View style={{ flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14 }}>
              {[1, 2, 3].map(number => <View key={number} style={{ flex: 1, alignItems: 'center' }}><View style={{ height: 2, backgroundColor: step >= number ? GREEN : BORDER, position: 'absolute', left: 0, right: 0, top: 5 }} /><View style={{ width: 12, height: 12, borderRadius: 6, backgroundColor: step >= number ? GREEN : BORDER }} /></View>)}
            </View>
          </Motion>
          <View onLayout={(event) => rememberField('_step1', event.nativeEvent.layout.y)} style={step !== 1 && { display: 'none' }} accessibilityElementsHidden={step !== 1} importantForAccessibility={step !== 1 ? 'no-hide-descendants' : 'auto'}>
          <Text style={styles.sectionTitle}>Affected Farm *</Text>
          <View onLayout={(event) => rememberField('farmId', event.nativeEvent.layout.y)}>
            {farmsLoading ? <ActivityIndicator color={GREEN} /> : null}
            {!farmsLoading && farmsError ? <Text style={styles.errorText}>{farmsError}</Text> : null}
            {!farmsLoading && !farmsError && farms.length === 0 ? <View style={styles.noFarmCard}><Text style={styles.noFarmText}>Add an active farm before submitting a damage report.</Text><Pressable onPress={() => router.push('/my-farms')} style={styles.manageFarmButton}><Text style={styles.manageFarmText}>Go to My Farms</Text></Pressable></View> : null}
            {farms.length > 0 && <Pressable accessibilityRole="button" accessibilityLabel="Choose affected farm" onPress={() => setFarmPickerOpen(true)} style={styles.farmChoice}>
              <Icon name="farm" />
              <View style={styles.farmChoiceCopy}><Text style={styles.farmChoiceName}>{farms.find(farm => farm.id === farmId)?.name || 'Select affected farm'}</Text>
                {farmId && <Text style={styles.farmChoiceMeta}>{farmType} · {farms.find(farm => farm.id === farmId)?.barangayName || farms.find(farm => farm.id === farmId)?.address}</Text>}
              </View><Icon name="chevron" size={18} />
            </Pressable>}
          </View>
          {!!errors.farmId && <Text style={styles.errorText}>{errors.farmId}</Text>}
          {!!errors.farmType && <Text style={styles.errorText}>{errors.farmType}</Text>}

          {farmId && category && categoryConfig && measure && <>
            {/* Only the selected farm's category fields are shown; each category is an alternative form. */}
            <View style={styles.categoryHeader} accessible accessibilityLabel={`${categoryConfig.title} damage report`}>
              <View style={styles.categoryBadge}><Icon name={categoryIcon(category)} size={26} /></View>
              <View style={{ flex: 1 }}><Text style={styles.categoryHeaderTitle}>{categoryConfig.title.toUpperCase()}</Text><Text style={styles.categoryHeaderText}>{categoryConfig.subtitle}</Text></View>
            </View>
            <View onLayout={(event) => rememberField('subjectType', event.nativeEvent.layout.y)} style={styles.sectionBlock}>
              <Text style={styles.label}>{categoryConfig.subjectLabel} *</Text>
              <Pressable accessibilityRole="button" accessibilityLabel={`Choose ${categoryConfig.subjectLabel.toLowerCase()}`} onPress={() => setDetailPicker('subject')} style={[styles.select, errors.subjectType && styles.inputError]}><Text style={[styles.selectText, !details.subjectType && styles.placeholder]}>{details.subjectType || `Select ${categoryConfig.subjectLabel.toLowerCase()}`}</Text><Icon name="chevron" /></Pressable>
              {!!errors.subjectType && <Text style={styles.errorText}>{errors.subjectType}</Text>}
            </View>
            {details.subjectType === 'Other' && <View onLayout={(event) => rememberField('subjectTypeOther', event.nativeEvent.layout.y)}><Field label={`Type the ${categoryConfig.subjectLabel.toLowerCase()}`} value={details.subjectTypeOther} placeholder={`Enter the ${categoryConfig.subjectLabel.toLowerCase()}`} onChangeText={(value) => setDetail('subjectTypeOther', value)} error={errors.subjectTypeOther} maxLength={100} /></View>}
            <View onLayout={(event) => rememberField('suspectedCause', event.nativeEvent.layout.y)} style={styles.sectionBlock}>
              <Text style={styles.label}>Suspected cause *</Text>
              <Pressable accessibilityRole="button" accessibilityLabel="Choose suspected cause" onPress={() => setDetailPicker('cause')} style={[styles.select, errors.suspectedCause && styles.inputError]}><Text style={[styles.selectText, !details.suspectedCause && styles.placeholder]}>{details.suspectedCause || 'Select suspected cause'}</Text><Icon name="chevron" /></Pressable>
              <Text style={styles.fieldHelp}>Choose Unsure if the cause is not known. This is your suspected cause; the office verifies it.</Text>
              {!!errors.suspectedCause && <Text style={styles.errorText}>{errors.suspectedCause}</Text>}
            </View>
            {details.suspectedCause === 'Other' && <View onLayout={(event) => rememberField('suspectedCauseOther', event.nativeEvent.layout.y)}><Field label="Explain the other cause" value={details.suspectedCauseOther} placeholder="What do you think caused the damage?" onChangeText={(value) => setDetail('suspectedCauseOther', value)} error={errors.suspectedCauseOther} maxLength={255} /></View>}
            <View onLayout={(event) => rememberField('description', event.nativeEvent.layout.y)}><Field label="What happened?" optional value={description} placeholder={category === 'Crops' ? 'e.g. Heavy rains flooded the field for two days.' : category === 'Fishpond' ? 'e.g. The water turned muddy and the fish lacked oxygen.' : 'e.g. Several animals showed symptoms before they died.'} onChangeText={(value) => { setDescription(value); clearError('description'); }} error={errors.description} multiline maxLength={5000} /></View>

            {category === 'Crops' && <>
              <View onLayout={(event) => rememberField('cropDamagePercent', event.nativeEvent.layout.y)}><NumberField label="Estimated crop damage" unit="%" decimal value={details.cropDamagePercent} onChangeText={(value) => setDetail('cropDamagePercent', value)} error={errors.cropDamagePercent} help={`Your estimate for this ${subjectName(details) ? subjectName(details).toLowerCase() : 'crop'} or plot only (0 to 100%), not a measurement of the whole farm.`} /></View>
              <View onLayout={(event) => rememberField('plantedArea', event.nativeEvent.layout.y)}><NumberField label="Planted area" unit="hectares" decimal optional value={details.plantedArea} onChangeText={(value) => setDetail('plantedArea', value)} error={errors.plantedArea} /></View>
              <View onLayout={(event) => rememberField('affectedArea', event.nativeEvent.layout.y)}><NumberField label="Affected area" unit="hectares" decimal optional value={details.affectedArea} onChangeText={(value) => setDetail('affectedArea', value)} error={errors.affectedArea} /></View>
            </>}
            {(category === 'Livestock' || category === 'Poultry') && <>
              <View onLayout={(event) => rememberField('totalBefore', event.nativeEvent.layout.y)}><NumberField label={category === 'Poultry' ? 'Total birds before the incident' : 'Total head before the incident'} unit={unitFor(measure)} value={details.totalBefore} onChangeText={(value) => setDetail('totalBefore', value)} error={errors.totalBefore} /></View>
              <View onLayout={(event) => rememberField('amountLost', event.nativeEvent.layout.y)}><NumberField label="Number that died" unit={unitFor(measure)} value={details.amountLost} onChangeText={(value) => setDetail('amountLost', value)} error={errors.amountLost} help="Whole numbers only. Enter 0 if none died." /></View>
            </>}
            {category === 'Fishpond' && <>
              <View onLayout={(event) => rememberField('fishUnit', event.nativeEvent.layout.y)} style={styles.sectionBlock}>
                <Text style={styles.label}>Record the loss by *</Text>
                <View style={styles.unitToggle} accessibilityRole="radiogroup">
                  {([['count', 'Count (fish)'], ['kg', 'Kilograms (kg)']] as const).map(([unit, label]) => <Pressable key={unit} accessibilityRole="radio" accessibilityState={{ checked: details.fishUnit === unit }} accessibilityLabel={label} onPress={() => selectFishUnit(unit)} style={[styles.unitOption, details.fishUnit === unit && styles.unitOptionSelected]}><Text style={[styles.unitOptionText, details.fishUnit === unit && styles.unitOptionTextSelected]}>{label}</Text></Pressable>)}
                </View>
                <Text style={styles.fieldHelp}>Use one unit for this report. Counts and kilograms are never combined.</Text>
                {!!errors.fishUnit && <Text style={styles.errorText}>{errors.fishUnit}</Text>}
              </View>
              {!!details.fishUnit && <>
                <View onLayout={(event) => rememberField('amountLost', event.nativeEvent.layout.y)}><NumberField label="Amount lost" unit={unitFor(measure)} decimal={measure === 'fish_weight_kg'} value={details.amountLost} onChangeText={(value) => setDetail('amountLost', value)} error={errors.amountLost} /></View>
                <View onLayout={(event) => rememberField('totalBefore', event.nativeEvent.layout.y)}><NumberField label="Total stock before the incident" unit={unitFor(measure)} decimal={measure === 'fish_weight_kg'} optional value={details.totalBefore} onChangeText={(value) => setDetail('totalBefore', value)} error={errors.totalBefore} help="Leave blank if you do not know it; no percentage is calculated then." /></View>
              </>}
            </>}
            <View style={styles.lossCard} accessible accessibilityLabel={`Farmer's reported loss: ${lossSummary || 'not complete yet'}`}>
              <Icon name="reports" size={22} />
              <View style={{ flex: 1 }}><Text style={styles.lossLabel}>Farmer&apos;s reported loss</Text><Text style={lossSummary ? styles.lossValue : styles.lossPending}>{lossSummary || 'Enter the figures above to see your summary.'}</Text></View>
            </View>
          </>}
          </View>
          <View onLayout={(event) => rememberField('_step2', event.nativeEvent.layout.y)} style={step !== 2 && { display: 'none' }} accessibilityElementsHidden={step !== 2} importantForAccessibility={step !== 2 ? 'no-hide-descendants' : 'auto'}>
          <View onLayout={(event) => rememberField('photos', event.nativeEvent.layout.y)} style={styles.sectionBlock}>
            <View style={[ui.row, { flexWrap: 'wrap' }]}><Text style={[styles.label, { flex: 1 }]}>Damage Photos *</Text><Text style={ui.small}>{photos.length} of {MAX_DAMAGE_PHOTOS}</Text><Pressable accessibilityRole="button" accessibilityLabel="About photo GPS and location" accessibilityState={{ expanded: photoHelpOpen }} onPress={() => setPhotoHelpOpen(value => !value)} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={ui.small}>{photoHelpOpen ? 'Hide GPS help' : 'GPS help'}</Text></Pressable><Pressable accessibilityRole="button" accessibilityLabel="Photo options" onPress={() => setPhotoOptionsOpen(true)} disabled={photoBusy || photos.length >= MAX_DAMAGE_PHOTOS} style={{ minWidth: 44, minHeight: 44, alignItems: 'center', justifyContent: 'center' }}><Icon name="plus" size={20} /></Pressable></View>
            <View style={[ui.row, { alignItems: 'stretch', flexWrap: 'wrap' }]}>
              <Pressable accessibilityRole="button" onPress={() => addPhoto('camera')} disabled={photoBusy || submitBusy || photos.length >= MAX_DAMAGE_PHOTOS} style={[styles.photoAction, (photoBusy || photos.length >= MAX_DAMAGE_PHOTOS) && styles.disabledButton]}><Icon name="camera" size={26} color="white" /><Text style={[styles.photoActionText, { color: 'white' }]}>Take Photo Now</Text></Pressable>
              <Pressable accessibilityRole="button" onPress={() => addPhoto('gallery')} disabled={photoBusy || submitBusy || photos.length >= MAX_DAMAGE_PHOTOS} style={[styles.photoAction, { backgroundColor: palette.sage }, (photoBusy || photos.length >= MAX_DAMAGE_PHOTOS) && styles.disabledButton]}><Icon name="photo" size={26} /><Text style={styles.photoActionText}>Choose from Gallery</Text></Pressable>
            </View>
            {photoBusy && <View style={[ui.row, { marginTop: 8 }]}><ActivityIndicator color={GREEN} /><Text style={ui.small}>Preparing selected photos…</Text></View>}
            {photoHelpOpen && <View style={[ui.card, { marginBottom: 8 }]}>
              <Text style={ui.body}>Gallery uses only GPS available from that selected photo. Take Photo Now pairs a fresh device reading with the captured image; stay at the damage site until it completes.</Text>
              <Text style={ui.small}>Each photo keeps its own GPS and source. Photo coordinates do not establish a barangay or purok. For a photo without accessible GPS, choose a separately labeled manual or current-position damage point.</Text>
              <Text style={ui.small}>Map tiles are not required to confirm coordinates. Review the exact point in Google Maps if the preview is unavailable; Cabadbaran boundary validation still applies.</Text>
            </View>}
            {!!errors.photos && <Text style={styles.errorText}>{errors.photos}</Text>}
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 10, paddingTop: 8 }}><Motion trigger={photos.length} style={styles.photoStrip}>{photos.map((photo, index) => <View key={photo.id} style={styles.stripPhoto}>
              <Pressable accessibilityRole="button" accessibilityLabel={`Review location for photo ${index + 1}`} onPress={() => selectPhotoLocation(photo)} disabled={submitBusy || photoBusy}><Image source={{ uri: photo.uri }} style={[styles.thumbnail, photo.id === locationPhotoId && styles.selectedPhoto]} /></Pressable>
              <Text style={styles.photoOrder}>{index + 1}</Text><Pressable accessibilityRole="button" accessibilityLabel={`Remove damage photo ${index + 1}`} hitSlop={10} onPress={() => removePhoto(photo.id)} style={styles.removePhoto}><Icon name="close" size={16} color="white" /></Pressable>
              {photoHelpOpen && <View style={[styles.photoReorderRow, { flexWrap: 'wrap', gap: 4 }]}>
                <Pressable accessibilityRole="button" accessibilityLabel={`Move photo ${index + 1} earlier`} disabled={index === 0 || photoBusy || submitBusy} onPress={() => movePhoto(photo.id, -1)}><Text style={[styles.photoReorderText, index === 0 && styles.disabledButton]}>Move earlier</Text></Pressable>
                <Pressable accessibilityRole="button" accessibilityLabel={`Move photo ${index + 1} later`} disabled={index === photos.length - 1 || photoBusy || submitBusy} onPress={() => movePhoto(photo.id, 1)}><Text style={[styles.photoReorderText, index === photos.length - 1 && styles.disabledButton]}>Move later</Text></Pressable>
              </View>}
              <Text style={[styles.metadataText, photo.id === locationPhotoId && { color: GREEN, fontWeight: '700' }]}>{validPoint(photo.latitude, photo.longitude) ? photo.locationSource === 'device_capture' ? 'Capture GPS' : photo.locationSource === 'asset_location' ? 'Original asset GPS' : photo.locationSource === 'photo_exif' ? 'Photo EXIF GPS' : 'GPS source unavailable' : 'No accessible GPS'}</Text>
              {!!photo.locationIssue && (photoHelpOpen || photo.locationStatus !== 'available') && <Text style={styles.metadataText}>{photo.locationIssue}</Text>}
              {photoHelpOpen && <Text style={styles.metadataText}>{photo.capturedAt ? `${photo.captureTimeSource === 'camera_return' ? 'Camera returned' : 'Photo time'}: ${new Date(photo.capturedAt).toLocaleString()}` : 'Capture time not available'}</Text>}
              {validPoint(photo.latitude, photo.longitude) && <Pressable onPress={() => selectPhotoLocation(photo)} disabled={submitBusy || photoBusy}><Text style={styles.metadataText}>{photo.latitude!.toFixed(5)}, {photo.longitude!.toFixed(5)}</Text></Pressable>}
            </View>)}</Motion></ScrollView>
          </View>

          <View onLayout={(event) => { rememberField('damageAddress', event.nativeEvent.layout.y); rememberField('damageLocation', event.nativeEvent.layout.y); }} style={styles.locationSection}>
            <Text style={styles.sectionTitle}>Damage Location</Text>
            <Motion trigger={damageLatitude + ':' + damageLongitude}>
            {hasCandidate ? <>
              <Text style={styles.label}>{locationSource === 'photo' ? photoLocationLabel(selectedPhoto?.locationSource, true) : locationSource === 'device' ? (locationConfirmed ? 'Farmer-confirmed device GPS' : 'Current phone location (not yet confirmed)') : Platform.OS === 'android' ? 'Farmer-selected damage point (not photo GPS)' : 'Manually entered damage point'}</Text>
              {step === 2 && <DamageLocationMap latitude={Number(damageLatitude)} longitude={Number(damageLongitude)} accuracy={selectedPhoto?.accuracy} label={Platform.OS === 'android' && locationSource === 'photo' ? photoLocationLabel(selectedPhoto?.locationSource, true) : locationSource === 'photo' ? 'Photo location' : 'Damage point to confirm'} />}
              <Text style={styles.locationHelp}>{Number(damageLatitude).toFixed(7)}, {Number(damageLongitude).toFixed(7)}{selectedPhoto?.accuracy != null ? ` · ±${Math.round(selectedPhoto.accuracy)} m` : ''}</Text>
              <Pressable accessibilityRole="button" disabled={locationBusy || photoBusy || submitBusy || selectedPhoto?.locationStatus === 'poor_accuracy'} onPress={() => void confirmDamageLocation()} style={[styles.locationButton, (locationBusy || photoBusy || submitBusy) && styles.disabledButton]}>
                {locationBusy ? <ActivityIndicator color={GREEN} /> : <Text style={styles.locationButtonText}>{locationConfirmed ? 'Damage location confirmed' : 'This is the damaged location'}</Text>}
              </Pressable>
            </> : <Text style={styles.locationMessage}>{'No photo GPS selected. Set a separate damage point if needed.'}</Text>}
            </Motion>
            {maximumPhotoDistance > 1 && <Text style={[styles.locationMessage, { color: palette.amber }]}>Photos span {Math.round(maximumPhotoDistance)} m. Confirm the main damage point; each photo keeps its own GPS.</Text>}
            {Platform.OS === 'android' && <Pressable accessibilityRole="button" disabled={locationBusy || photoBusy || submitBusy} onPress={openManualMap} style={[styles.locationButton, styles.manualMapButton, (locationBusy || photoBusy || submitBusy) && styles.disabledButton]}>
              <Text style={styles.locationButtonText}>Set separate damage location (not photo GPS)</Text>
            </Pressable>}
            {Platform.OS !== 'android' && locationSource !== 'manual' && <Pressable accessibilityRole="button" onPress={() => { changeLocation('', '', 'manual'); setLocationMessage('Enter a separate damage point. This is not photo GPS.'); }} style={styles.locationButton}><Text style={styles.locationButtonText}>Enter separate damage coordinates</Text></Pressable>}
            <Pressable disabled={locationBusy || photoBusy || submitBusy} onPress={() => void useMyLocation()} style={[styles.locationButton, (locationBusy || photoBusy || submitBusy) && styles.disabledButton]}>
              <Text style={styles.locationButtonText}>{'Use current position (not photo GPS)'}</Text>
            </Pressable>
            {!!locationMessage && (!hasCandidate || !!errors.damageLocation || photoHelpOpen) && <Text style={styles.locationMessage}>{locationMessage}</Text>}
            <Field label="Address / Landmark" value={damageAddress} placeholder="Actual barangay, road or landmark in Cabadbaran City" onChangeText={(value) => { setDamageAddress(value); clearError('damageAddress'); }} error={errors.damageAddress} multiline compact />
            <Text style={styles.photoHelp}>Use the actual address or landmark; GPS alone does not establish it.</Text>
            <View style={[styles.coordinateRow, locationSource !== 'manual' && { display: 'none' }]} accessibilityElementsHidden={locationSource !== 'manual'} importantForAccessibility={locationSource !== 'manual' ? 'no-hide-descendants' : 'auto'}>
              <View style={styles.coordinateField}><Field editable={locationSource === 'manual'} label="Latitude" value={damageLatitude} placeholder="Actual latitude" onChangeText={(value) => { changeLocation(value.replace(/[^0-9.-]/g, ''), damageLongitude, 'manual'); clearError('damageLatitude'); }} error={errors.damageLatitude} keyboardType="numbers-and-punctuation" /></View>
              <View style={styles.coordinateField}><Field editable={locationSource === 'manual'} label="Longitude" value={damageLongitude} placeholder="Actual longitude" onChangeText={(value) => { changeLocation(damageLatitude, value.replace(/[^0-9.-]/g, ''), 'manual'); clearError('damageLongitude'); }} error={errors.damageLongitude} keyboardType="numbers-and-punctuation" /></View>
            </View>
            {!!errors.damageLocation && <Text style={styles.errorText}>{errors.damageLocation}</Text>}
            {!!(damageLatitude || damageLongitude) && <Pressable onPress={() => { changeLocation('', '', 'manual'); setLocationMessage('Select another photo location or enter the actual damage point.'); }} style={styles.clearLocationButton}><Text style={styles.clearLocationText}>Clear selected damage point</Text></Pressable>}
          </View>

          </View>
          {step === 3 && <View style={{ gap: 14 }}>
            <View style={ui.card}>
              <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>Damage report summary</Text><Pressable accessibilityRole="button" onPress={() => navigateStep(1)} style={{ padding: 10 }}><Icon name="edit" /></Pressable></View>
              <View style={styles.reviewRow}><Text style={styles.reviewLabel}>Farm</Text><Text style={[ui.body, styles.reviewValue]}>{farms.find(farm => farm.id === farmId)?.name || 'No farm selected'} · {farmType}</Text></View>
              {categoryConfig && <View style={styles.reviewRow}><Text style={styles.reviewLabel}>{categoryConfig.subjectLabel}</Text><Text style={[ui.body, styles.reviewValue]}>{subjectName(details)}</Text></View>}
              <View style={styles.reviewRow}><Text style={styles.reviewLabel}>Suspected cause</Text><Text style={[ui.body, styles.reviewValue]}>{causeLabel(details.suspectedCause, details.suspectedCauseOther)}</Text></View>
              <View style={styles.reviewRow}><Text style={styles.reviewLabel}>Reported loss</Text><Text style={[ui.cardTitle, styles.reviewValue]}>{lossSummary}</Text></View>
              {category === 'Crops' && (!!details.plantedArea || !!details.affectedArea) && <View style={styles.reviewRow}><Text style={styles.reviewLabel}>Area</Text><Text style={[ui.body, styles.reviewValue]}>{details.affectedArea ? `${details.affectedArea} hectares affected` : 'Affected area not given'}{details.plantedArea ? ` of ${details.plantedArea} hectares planted` : ''}</Text></View>}
              <Text style={ui.small}>What happened?</Text><Text style={ui.body}>{description.trim() || 'Not provided'}</Text>
              <Text style={ui.small}>Figures and cause are your own report; the Department of Agriculture verifies them.</Text>
            </View>
            <View style={ui.card}>
              <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>Photos ({photos.length})</Text><Pressable accessibilityRole="button" onPress={() => navigateStep(2)} style={{ padding: 10 }}><Icon name="edit" /></Pressable></View>
              <View style={styles.photoGrid}>{photos.map((photo, index) => <View key={photo.id} style={styles.photoItem}><Image source={{ uri: photo.uri }} style={styles.thumbnail} /><Text style={ui.small}>Photo {index + 1} · {photoLocationLabel(photo.locationSource, validPoint(photo.latitude, photo.longitude))}</Text>{validPoint(photo.latitude, photo.longitude) && <Text style={ui.small}>{photo.latitude!.toFixed(7)}, {photo.longitude!.toFixed(7)}</Text>}</View>)}</View>
            </View>
            <View style={ui.card}>
              <Text style={ui.cardTitle}>Confirmed damage location</Text>
              <Text style={ui.body}>{damageAddress}</Text>
              <Text style={ui.body}>{locationSource === 'photo' ? photoLocationLabel(selectedPhoto?.locationSource, true) : locationSource === 'device' ? 'Farmer-confirmed device GPS' : 'Farmer-confirmed manual pin'}</Text>
              <Text style={ui.body}>{damageLatitude}, {damageLongitude}</Text>
              <Text style={{ color: locationConfirmed ? GREEN : palette.red }}>{locationConfirmed ? 'Confirmed within the Cabadbaran boundary' : 'Confirmation required'}</Text>
              {hasCandidate && <DamageLocationMap latitude={Number(damageLatitude)} longitude={Number(damageLongitude)} accuracy={selectedPhoto?.accuracy} label="Confirmed damage point" />}
            </View>
            <Text style={ui.small}>Your report will be sent for validation and assessment. Photo locations remain linked to their individual images.</Text>
          </View>}
          {step < 3 && <Pressable accessibilityRole="button" disabled={photoBusy || submitBusy || locationBusy} onPress={() => navigateStep(step === 1 ? 2 : 3)} style={[styles.submitButton, (photoBusy || submitBusy || locationBusy) && styles.disabledButton]}><Text style={styles.submitText}>{step === 1 ? 'Next: Photos & location' : 'Next: Review report'}</Text></Pressable>}
          {step === 3 && <>
          {!!submitError && <Text style={styles.submitError}>{submitError}</Text>}
          <Pressable disabled={submitBusy || photoBusy || locationBusy} onPress={() => void submitReport()} style={[styles.submitButton, (submitBusy || photoBusy || locationBusy) && styles.disabledButton]}>{submitBusy ? <ActivityIndicator color="#FFFFFF" /> : <Text style={styles.submitText}>Submit Report</Text>}</Pressable>
          </>}
          {step > 1 && <Pressable accessibilityRole="button" disabled={photoBusy || submitBusy || locationBusy} onPress={() => navigateStep(step === 3 ? 2 : 1)} style={styles.cancelRow}><Text style={styles.cancelText}>Back to {step === 3 ? 'photos & location' : 'damage details'}</Text></Pressable>}
        </ScrollView>
      </KeyboardAvoidingView>

      <Modal visible={farmPickerOpen} transparent animationType="slide" onRequestClose={() => setFarmPickerOpen(false)}>
        <View style={styles.modalBackdrop}><View style={styles.choiceSheet}><Text style={styles.sheetTitle}>Choose affected farm</Text><ScrollView style={{ maxHeight: 360 }}>{farms.map(farm => <Pressable key={farm.id} accessibilityRole="button" onPress={() => { selectFarm(farm); clearError('farmId'); clearError('farmType'); setFarmPickerOpen(false); }} style={[styles.farmChoice, farmId === farm.id && styles.farmChoiceSelected]}><View style={styles.farmChoiceCopy}><Text style={styles.farmChoiceName}>{farm.name}</Text><Text style={styles.farmChoiceMeta}>{farm.agriculturalCategory} · {farm.barangayName || farm.address}</Text></View><Icon name={farmId === farm.id ? 'check' : 'farm'} /></Pressable>)}</ScrollView><Pressable accessibilityRole="button" onPress={() => setFarmPickerOpen(false)} style={styles.cancelRow}><Text style={styles.cancelText}>Cancel</Text></Pressable></View></View>
      </Modal>
      <ChoiceModal visible={detailPicker === 'subject'} title={categoryConfig ? `Select ${categoryConfig.subjectLabel.toLowerCase()}` : 'Select'} options={categoryConfig?.subjects || []} onClose={() => setDetailPicker(null)} onSelect={(value) => { setDetail('subjectType', value); if (value !== 'Other') setDetail('subjectTypeOther', ''); setDetailPicker(null); }} />
      <ChoiceModal visible={detailPicker === 'cause'} title="Suspected cause" options={categoryConfig?.causes || []} onClose={() => setDetailPicker(null)} onSelect={(value) => { setDetail('suspectedCause', value); if (value !== 'Other') setDetail('suspectedCauseOther', ''); setDetailPicker(null); }} />
      <Modal visible={photoOptionsOpen} transparent animationType="slide" onDismiss={() => gallerySheetDismissed.current?.()} onRequestClose={() => setPhotoOptionsOpen(false)}><View style={styles.modalBackdrop}><View style={styles.choiceSheet}><View style={styles.sheetHandle} /><Text style={styles.sheetTitle}>Add Damage Photo</Text><Text style={styles.photoHelp}>Only accessible GPS saved in a gallery photo can locate it. For a new photo, stay at the damage site while a fresh GPS reading is obtained.</Text><Pressable onPress={() => addPhoto('camera')} style={styles.choiceRow}><Text style={styles.choiceText}>Take Photo Now</Text></Pressable><Pressable onPress={() => addPhoto('gallery')} style={styles.choiceRow}><Text style={styles.choiceText}>Choose from Gallery</Text></Pressable><Pressable onPress={() => setPhotoOptionsOpen(false)} style={styles.cancelRow}><Text style={styles.cancelText}>Cancel</Text></Pressable></View></View></Modal>
      {Platform.OS === 'android' && <Modal visible={manualMapOpen} animationType="slide" onRequestClose={() => setManualMapOpen(false)}>
        <SafeAreaView style={styles.manualMapScreen}>
          <Text style={styles.sheetTitle}>Set damage location yourself</Text>
          <Text style={styles.manualMapHelp}>Paste the actual damage coordinates from Google Maps, or use the optional embedded map. This point is farmer-selected, not photo GPS.</Text>
          <DamageManualPinMap point={manualDraft} onChange={setManualDraft} />
          <Text style={styles.manualMapHelp}>{manualDraft ? `Selected: ${manualDraft.latitude.toFixed(7)}, ${manualDraft.longitude.toFixed(7)}` : 'No point selected yet.'}</Text>
          <Pressable accessibilityRole="button" disabled={!manualDraft || !validPoint(manualDraft.latitude, manualDraft.longitude)} onPress={useManualMapPoint} style={[styles.submitButton, styles.manualMapUseButton, (!manualDraft || !validPoint(manualDraft.latitude, manualDraft.longitude)) && styles.disabledButton]}><Text style={styles.submitText}>Use this farmer-selected point</Text></Pressable>
          <Pressable accessibilityRole="button" onPress={() => setManualMapOpen(false)} style={styles.cancelRow}><Text style={styles.cancelText}>Cancel</Text></Pressable>
        </SafeAreaView>
      </Modal>}
    </AppScreen>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#F7F5EE' },
  keyboardView: { flex: 1 },
  header: { height: 58, backgroundColor: GREEN, justifyContent: 'center', paddingHorizontal: 20 },
  headerText: { color: '#FFFFFF', fontSize: 21, fontWeight: '800' },
  scrollContent: { flexGrow: 1, alignSelf: 'center', paddingHorizontal: 0, paddingTop: 14, paddingBottom: 36 },
  backButton: { alignSelf: 'flex-start', paddingVertical: 8 },
  backText: { color: OLIVE, fontSize: 15, fontWeight: '600' },
  title: { color: TEXT, fontSize: 30, fontWeight: '800', marginTop: 12 },
  subtitle: { color: OLIVE, fontSize: 15, lineHeight: 21, marginTop: 7, marginBottom: 24 },
  sectionTitle: { color: TEXT, fontSize: 17, fontWeight: '700', marginBottom: 8 },
  sectionBlock: { marginTop: 12 },
  locationSection: { marginTop: 14, borderTopWidth: 1, borderTopColor: '#E6ECE1', paddingTop: 14 },
  locationHelp: { color: OLIVE, fontSize: 13, lineHeight: 19, marginTop: 4, marginBottom: 8 },
  locationButton: { minHeight: 44, paddingVertical: 10, paddingHorizontal: 10, marginTop: 8, borderWidth: 1, borderColor: GREEN, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: '#F1F7EC' },
  manualMapButton: { marginVertical: 10, paddingHorizontal: 10 },
  locationButtonText: { color: GREEN, fontSize: 14, textAlign: 'center', fontWeight: '700' },
  locationMessage: { color: OLIVE, fontSize: 12, lineHeight: 18, marginTop: 8 },
  coordinateRow: { flexDirection: 'row', gap: 10 },
  coordinateField: { flex: 1, minWidth: 0 },
  clearLocationButton: { alignSelf: 'flex-start', paddingVertical: 9 },
  clearLocationText: { color: '#B14545', fontSize: 12, fontWeight: '700' },
  categoryGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  categoryCard: { width: '48%', minHeight: 104, borderWidth: 1, borderColor: BORDER, borderRadius: 16, backgroundColor: '#FFFDF8', alignItems: 'center', justifyContent: 'center', padding: 12 },
  categoryCardSelected: { borderColor: GREEN, backgroundColor: '#EDF6E8' },
  categoryIcon: { color: OLIVE, fontSize: 30, marginBottom: 8 },
  categoryIconSelected: { color: GREEN },
  categoryLabel: { color: TEXT, fontSize: 15, fontWeight: '600' },
  categoryLabelSelected: { color: GREEN },
  farmChoice: { minHeight: 56, gap: 10, borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#FFFDF8', padding: 12, flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
  farmChoiceSelected: { borderColor: GREEN, backgroundColor: '#EDF6E8' },
  farmChoiceCopy: { flex: 1 }, farmChoiceName: { color: TEXT, fontSize: 16, fontWeight: '700' }, farmChoiceMeta: { color: OLIVE, fontSize: 13, marginTop: 4 },
  farmChoiceCheck: { color: OLIVE, fontSize: 23 }, farmChoiceCheckSelected: { color: GREEN, fontSize: 22, fontWeight: '800' },
  noFarmCard: { borderWidth: 1, borderColor: BORDER, borderRadius: 15, padding: 16, backgroundColor: '#FFFDF8' }, noFarmText: { color: OLIVE, lineHeight: 20 },
  manageFarmButton: { alignSelf: 'flex-start', marginTop: 12, backgroundColor: GREEN, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 10 }, manageFarmText: { color: '#FFFFFF', fontWeight: '700' },
  fieldGroup: { marginTop: 12 },
  label: { color: TEXT, fontSize: 14, fontWeight: '600', marginBottom: 6 },
  input: { minHeight: 46, paddingVertical: 10, borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#FFFDF8', paddingHorizontal: 15, color: TEXT, fontSize: 16 },
  descriptionInput: { minHeight: 128, paddingTop: 12 }, addressInput: { minHeight: 74, paddingTop: 12 },
  photoAction: { flex: 1, minWidth: 135, minHeight: 76, padding: 12, backgroundColor: GREEN, borderRadius: 12, alignItems: 'center', justifyContent: 'center', gap: 6 }, photoActionText: { color: GREEN, fontSize: 13, fontWeight: '600', textAlign: 'center' },
  inputError: { borderColor: '#B14545' },
  errorText: { color: '#B14545', fontSize: 13, marginTop: 6, marginLeft: 3 },
  optionalText: { color: OLIVE, fontSize: 13, fontWeight: '400' },
  fieldHelp: { color: OLIVE, fontSize: 12, lineHeight: 18, marginTop: 6, marginLeft: 3 },
  numberShell: { minHeight: 52, borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#FFFDF8', paddingHorizontal: 15, flexDirection: 'row', alignItems: 'center' },
  numberInput: { flex: 1, minWidth: 0, paddingVertical: 10, color: TEXT, fontSize: 20, fontWeight: '700' },
  numberUnit: { color: OLIVE, fontSize: 15, fontWeight: '600', marginLeft: 8 },
  categoryHeader: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderColor: BORDER, borderRadius: 16, backgroundColor: '#EDF6E8', padding: 14, marginTop: 4 },
  categoryBadge: { width: 46, height: 46, borderRadius: 23, backgroundColor: '#FFFDF8', alignItems: 'center', justifyContent: 'center' },
  categoryHeaderTitle: { color: GREEN, fontSize: 15, fontWeight: '800', letterSpacing: 0.5 },
  categoryHeaderText: { color: OLIVE, fontSize: 13, marginTop: 2 },
  unitToggle: { flexDirection: 'row', gap: 10 },
  unitOption: { flex: 1, minHeight: 46, borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#FFFDF8', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
  unitOptionSelected: { borderColor: GREEN, backgroundColor: '#EDF6E8' },
  unitOptionText: { color: TEXT, fontSize: 14, fontWeight: '600', textAlign: 'center' },
  unitOptionTextSelected: { color: GREEN, fontWeight: '800' },
  lossCard: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderColor: '#9BB782', borderRadius: 16, backgroundColor: '#F1F7EC', padding: 14, marginTop: 16 },
  lossLabel: { color: OLIVE, fontSize: 12, fontWeight: '600', textTransform: 'uppercase', letterSpacing: 0.4 },
  lossValue: { color: GREEN, fontSize: 17, fontWeight: '800', marginTop: 3, lineHeight: 23 },
  lossPending: { color: OLIVE, fontSize: 14, marginTop: 3, lineHeight: 20 },
  select: { minHeight: 46, paddingVertical: 10, borderWidth: 1, borderColor: BORDER, borderRadius: 15, backgroundColor: '#FFFDF8', paddingHorizontal: 15, flexDirection: 'row', alignItems: 'center' },
  selectText: { flex: 1, color: TEXT, fontSize: 16 },
  placeholder: { color: '#9AA08D' },
  chevron: { color: OLIVE, fontSize: 20 },
  addPhotoCard: { minHeight: 132, borderWidth: 1.5, borderStyle: 'dashed', borderColor: '#9BB782', backgroundColor: '#F1F7EC', borderRadius: 16, alignItems: 'center', justifyContent: 'center', padding: 16 },
  photoHelp: { color: OLIVE, fontSize: 12, lineHeight: 18, marginTop: -2, marginBottom: 10 },
  photoCount: { color: GREEN, fontSize: 13, fontWeight: '700', alignSelf: 'flex-end', marginTop: -28, marginBottom: 12 },
  addPhotoIcon: { color: GREEN, fontSize: 34, marginBottom: 3 },
  addPhotoTitle: { color: GREEN, fontSize: 16, fontWeight: '700' },
  addPhotoSubtitle: { color: OLIVE, fontSize: 13, marginTop: 5 },
  photoGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 },
  photoItem: { width: '47%', position: 'relative' },
  reviewRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, paddingVertical: 4 }, reviewLabel: { color: OLIVE, fontSize: 12, width: 92 }, reviewValue: { flex: 1 },
  photoStrip: { flexDirection: 'row', gap: 10 }, stripPhoto: { width: 132, position: 'relative' },
  thumbnail: { width: '100%', height: 112, borderRadius: 12, backgroundColor: '#E9EEE3' },
  selectedPhoto: { borderWidth: 3, borderColor: GREEN },
  removePhoto: { position: 'absolute', top: 6, right: 6, width: 26, height: 26, borderRadius: 13, backgroundColor: 'rgba(0,0,0,0.65)', alignItems: 'center', justifyContent: 'center' },
  photoOrder: { position: 'absolute', top: 6, left: 6, minWidth: 24, height: 24, paddingHorizontal: 6, borderRadius: 12, overflow: 'hidden', backgroundColor: GREEN, color: '#FFFFFF', textAlign: 'center', lineHeight: 24, fontSize: 12, fontWeight: '800' },
  photoReorderRow: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 5 },
  photoReorderText: { color: GREEN, fontSize: 12, fontWeight: '600', paddingVertical: 12 },
  removePhotoText: { color: '#FFFFFF', fontSize: 20, lineHeight: 22 },
  metadataText: { color: OLIVE, fontSize: 11, marginTop: 4 },
  submitButton: { minHeight: 46, paddingVertical: 12, paddingHorizontal: 12, borderRadius: 15, backgroundColor: GREEN, alignItems: 'center', justifyContent: 'center', marginTop: 16 },
  submitText: { color: '#FFFFFF', fontSize: 15, fontWeight: '700' },
  submitError: { color: '#B14545', fontSize: 13, lineHeight: 19, textAlign: 'center', marginTop: 22 },
  disabledButton: { opacity: 0.6 },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)', justifyContent: 'flex-end' },
  choiceSheet: { backgroundColor: '#FFFDF8', borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 22, paddingBottom: 30 },
  sheetHandle: { width: 42, height: 4, borderRadius: 4, backgroundColor: '#CBD3C4', alignSelf: 'center', marginBottom: 16 },
  sheetTitle: { color: TEXT, fontSize: 20, fontWeight: '800', marginBottom: 10 },
  choiceRow: { minHeight: 52, justifyContent: 'center', borderBottomWidth: 1, borderBottomColor: '#EEF1EB' },
  choiceText: { color: TEXT, fontSize: 16 },
  cancelRow: { minHeight: 50, alignItems: 'center', justifyContent: 'center', marginTop: 10 },
  cancelText: { color: GREEN, fontSize: 16, fontWeight: '700' },
  manualMapScreen: { flex: 1, backgroundColor: '#FFFDF8', paddingHorizontal: 18, paddingTop: 18 },
  manualMapHelp: { color: OLIVE, fontSize: 13, lineHeight: 19, marginBottom: 12 },
  manualMapUseButton: { marginTop: 10 },
});
