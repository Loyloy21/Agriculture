import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { router } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, type ScrollViewInstance, StyleSheet, Text, TextInput, View } from 'react-native';
import { Action, AppScreen, categoryIcon, EmptyState, Icon, Motion, palette, ui } from '@/components/farm-ui';
import { productionStyles } from '@/components/production-ui';
import SearchablePicker, { type PickerSection } from '@/components/searchable-picker';
import { FarmerDataApiError, getProductionCatalog, listFarmerFarms, submitProductionRecord, type FarmerFarm, type ProductionCatalog, type ProductionCategoryCode, type ProductionRecord } from '@/services/farmerDataApi';
import { useFarmerAuth } from '@/state/farmerAuthContext';
import {
  cleanQuantityInput, dateFromKey, dateKey, formatHarvestDate, formatQuantity, manilaToday, matchesSearch, monthLabel, PRODUCTION_EARLIEST_HARVEST_DATE,
  PRODUCTION_REMARKS_MAX_LENGTH, productionDraftErrors, searchCommodities, type ProductionDraft, type ProductionDraftErrors,
} from '@/utils/production';

const FIELD_ORDER: (keyof ProductionDraft)[] = ['farmId', 'category', 'commodityId', 'harvestDate', 'quantity', 'unitCode', 'remarks'];
const newSubmissionId = () => `production_${Date.now()}_${Math.random().toString(36).slice(2, 14)}`;
const emptyDraft = (today: string): ProductionDraft => ({ farmId: null, category: null, commodityId: null, harvestDate: today, quantity: '', unitCode: null, remarks: '' });

export default function AddProductionRecordScreen() {
  const { refreshSession } = useFarmerAuth();
  const today = manilaToday();
  const [draft, setDraft] = useState<ProductionDraft>(() => emptyDraft(today));
  const [errors, setErrors] = useState<ProductionDraftErrors & { form?: string }>({});
  const [catalog, setCatalog] = useState<ProductionCatalog | null>(null);
  const [farms, setFarms] = useState<FarmerFarm[]>([]);
  const [optionsLoading, setOptionsLoading] = useState(true);
  const [optionsError, setOptionsError] = useState('');
  const [optionsRevision, setOptionsRevision] = useState(0);
  const [step, setStep] = useState<'form' | 'review' | 'done'>('form');
  const [picker, setPicker] = useState<'farm' | 'category' | 'commodity' | null>(null);
  const [dateOpen, setDateOpen] = useState(false);
  const [dateDraft, setDateDraft] = useState(dateFromKey(today));
  const [submitBusy, setSubmitBusy] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [duplicateNotice, setDuplicateNotice] = useState('');
  const [saved, setSaved] = useState<{ record: ProductionRecord; alreadySaved: boolean } | null>(null);
  const submitLock = useRef(false);
  // True while the category is the one suggested by the selected farm (not chosen by the farmer).
  const categorySuggested = useRef(false);
  // One identifier per filled-in form: a retry of the same values can never be saved twice.
  const clientSubmissionId = useRef(newSubmissionId());
  const scrollRef = useRef<ScrollViewInstance>(null);
  const fieldOffsets = useRef<Partial<Record<keyof ProductionDraft, number>>>({});

  useEffect(() => {
    let active = true;
    setOptionsLoading(true); setOptionsError('');
    void (async () => {
      try {
        const [catalogResult, farmList] = await Promise.all([getProductionCatalog(), listFarmerFarms()]);
        if (!active) return;
        const activeFarms = farmList.farms.filter((farm) => farm.status === 'Active');
        setCatalog(catalogResult);
        setFarms(activeFarms);
        // A farmer with one active farm area does not need to choose it.
        if (activeFarms.length === 1) setDraft((current) => current.farmId ? current : withFarm(current, activeFarms[0]));
      } catch (error) {
        if (error instanceof FarmerDataApiError && error.status === 401) void refreshSession();
        if (active) setOptionsError(error instanceof Error ? error.message : 'The form options could not be loaded.');
      } finally { if (active) setOptionsLoading(false); }
    })();
    return () => { active = false; };
  }, [optionsRevision, refreshSession]);

  const farm = farms.find((item) => item.id === draft.farmId) || null;
  const category = catalog?.categories.find((item) => item.code === draft.category) || null;
  const commodity = catalog?.commodities.find((item) => item.id === draft.commodityId) || null;
  const unit = catalog?.units.find((item) => item.code === draft.unitCode) || null;
  const commodityUnits = commodity && catalog ? commodity.units.map((code) => catalog.units.find((item) => item.code === code)).filter((item) => item !== undefined) : [];

  /** Selecting a farm suggests its registered activity; a category the farmer chose stays (mixed farming). */
  function withFarm(current: ProductionDraft, selected: FarmerFarm): ProductionDraft {
    const next = { ...current, farmId: selected.id };
    if (!current.category || categorySuggested.current) {
      categorySuggested.current = true;
      if (current.category !== selected.agriculturalCategory) return { ...next, category: selected.agriculturalCategory, commodityId: null, unitCode: null };
    }
    return next;
  }

  function changed(keys: (keyof ProductionDraft)[]) {
    setErrors((current) => { const next = { ...current, form: undefined }; for (const key of keys) next[key] = undefined; return next; });
    setDuplicateNotice('');
    setSubmitError('');
    clientSubmissionId.current = newSubmissionId();
  }

  function update<K extends keyof ProductionDraft>(key: K, value: ProductionDraft[K]) {
    setDraft((current) => ({ ...current, [key]: value }));
    changed([key]);
  }

  function selectFarm(id: number) {
    const selected = farms.find((item) => item.id === id);
    if (selected) setDraft((current) => withFarm(current, selected));
    changed(['farmId', 'category', 'commodityId', 'unitCode']);
    setPicker(null);
  }

  function selectCategory(code: ProductionCategoryCode) {
    categorySuggested.current = false;
    // A new category clears the commodity (and its unit): commodities belong to one category only.
    setDraft((current) => current.category === code ? current : { ...current, category: code, commodityId: null, unitCode: null });
    changed(['category', 'commodityId', 'unitCode']);
    setPicker(null);
  }

  function selectCommodity(id: number) {
    const selected = catalog?.commodities.find((item) => item.id === id);
    // The commodity's recommended unit is selected automatically; only its own units are offered.
    if (selected) setDraft((current) => ({ ...current, commodityId: selected.id, unitCode: current.commodityId === selected.id && current.unitCode && selected.units.includes(current.unitCode) ? current.unitCode : selected.defaultUnit }));
    changed(['commodityId', 'unitCode', 'quantity']);
    setPicker(null);
  }

  function scrollToFirstError(found: Partial<Record<string, string | undefined>>) {
    const first = FIELD_ORDER.find((key) => found[key]);
    if (first && fieldOffsets.current[first] !== undefined) scrollRef.current?.scrollTo({ y: Math.max(0, fieldOffsets.current[first]! - 16), animated: true });
  }

  const validate = () => productionDraftErrors(draft, today, commodity, unit);

  function review() {
    const found = validate();
    setErrors(found);
    if (Object.keys(found).length) { scrollToFirstError(found); return; }
    setStep('review');
    scrollRef.current?.scrollTo({ y: 0, animated: false });
  }

  async function submit(confirmDuplicate = false) {
    if (submitLock.current) return;
    const found = validate();
    if (Object.keys(found).length) { setErrors(found); setStep('form'); return; }
    submitLock.current = true;
    setSubmitBusy(true); setSubmitError(''); setDuplicateNotice('');
    try {
      const result = await submitProductionRecord({
        farmId: draft.farmId!, category: draft.category as ProductionCategoryCode, commodityId: draft.commodityId!, harvestDate: draft.harvestDate,
        quantity: draft.quantity.trim(), unitCode: draft.unitCode!, remarks: draft.remarks.trim(), clientSubmissionId: clientSubmissionId.current,
        ...(confirmDuplicate ? { confirmDuplicate: true } : {}),
      });
      setSaved(result);
      setStep('done');
      scrollRef.current?.scrollTo({ y: 0, animated: false });
    } catch (error) {
      if (error instanceof FarmerDataApiError) {
        if (error.status === 401) await refreshSession();
        if (error.status === 409 && error.errors.duplicate) { setDuplicateNotice(error.errors.duplicate); return; }
        if (error.status === 422 && Object.keys(error.errors).length) {
          setErrors(error.errors);
          setStep('form');
          scrollToFirstError(error.errors);
        }
      }
      setSubmitError(error instanceof Error ? error.message : 'The production record could not be saved.');
    } finally {
      submitLock.current = false;
      setSubmitBusy(false);
    }
  }

  function addAnother() {
    clientSubmissionId.current = newSubmissionId();
    categorySuggested.current = false;
    const sameFarm = farms.length === 1 ? farms[0] : null;
    setDraft(sameFarm ? withFarm(emptyDraft(manilaToday()), sameFarm) : emptyDraft(manilaToday()));
    setErrors({}); setSaved(null); setSubmitError(''); setDuplicateNotice(''); setStep('form');
  }

  function backToReport() {
    if (router.canGoBack()) router.back(); else router.replace('/production');
  }

  function openDatePicker() {
    setDateDraft(dateFromKey(draft.harvestDate || today));
    setDateOpen(true);
  }

  function handleDateChange(event: DateTimePickerEvent, selectedDate?: Date) {
    if (Platform.OS !== 'ios') setDateOpen(false);
    if (event.type === 'set' && selectedDate) {
      if (Platform.OS === 'ios') setDateDraft(selectedDate);
      else update('harvestDate', dateKey(selectedDate));
    }
  }

  const farmSections = (query: string): PickerSection[] => [{ title: '', data: farms
    .filter((item) => matchesSearch(`${item.name} ${item.barangayName || ''} ${item.address} ${item.agriculturalCategory} ${item.mainProduct}`, query))
    .map((item) => ({ key: String(item.id), title: item.name, subtitle: `${catalog?.categories.find((entry) => entry.code === item.agriculturalCategory)?.label || item.agriculturalCategory} · ${item.barangayName || item.address}`, icon: categoryIcon(item.agriculturalCategory) })) }];
  const categorySections = (query: string): PickerSection[] => [{ title: '', data: (catalog?.categories || [])
    .filter((item) => matchesSearch(`${item.label} ${item.code}`, query))
    .map((item) => ({ key: item.code, title: item.label, icon: categoryIcon(item.code), badge: item.code === farm?.agriculturalCategory ? 'Registered activity of this farm area' : undefined })) }];
  // Commodity search is case-insensitive partial matching on English, Filipino and local names, within the category.
  const commodityIndex = useMemo(() => catalog?.commodities || [], [catalog]);
  const commoditySections = (query: string): PickerSection[] => {
    const groups = new Map<string, PickerSection>();
    for (const item of searchCommodities(commodityIndex, draft.category, query)) {
      const section = groups.get(item.group) || { title: item.group, data: [] };
      section.data.push({ key: String(item.id), title: item.name, subtitle: item.units.map((code) => catalog?.units.find((entry) => entry.code === code)?.name || code).join(' or '), icon: categoryIcon(item.category) });
      groups.set(item.group, section);
    }
    return [...groups.values()];
  };

  const remember = (key: keyof ProductionDraft) => (event: { nativeEvent: { layout: { y: number } } }) => { fieldOffsets.current[key] = event.nativeEvent.layout.y; };
  const isWeight = unit?.kgPerUnit != null;

  return <AppScreen back>
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView ref={scrollRef} contentContainerStyle={ui.content} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {step === 'done' && saved ? <Motion trigger={step} style={[ui.card, styles.done]}>
          <View style={styles.doneIcon}><Icon name="check" size={44} /></View>
          <Text style={[ui.title, { textAlign: 'center' }]}>{saved.alreadySaved ? 'Already saved' : 'Production record saved'}</Text>
          <Text style={[ui.body, { textAlign: 'center' }]}>{formatQuantity(saved.record.quantity, saved.record.unitSymbol)} of {saved.record.commodityName} on {formatHarvestDate(saved.record.harvestDate)} is now part of your {monthLabel(saved.record.harvestDate.slice(0, 7))} production{saved.record.quantityKg === null ? `, shown separately from the kilogram total` : ''}.</Text>
          {saved.alreadySaved && <Text style={[ui.small, { textAlign: 'center' }]}>This form had already been submitted, so no second record was created.</Text>}
          <Action title="View Production Report" icon="chart" onPress={backToReport} />
          <Action title="View this record" secondary onPress={() => router.replace({ pathname: '/production/record/[id]', params: { id: saved.record.id } })} />
          <Action title="Add another record" icon="plus" secondary onPress={addAnother} />
        </Motion> : <>
          <View style={productionStyles.titlePanel}>
            <Text style={productionStyles.title}>{step === 'review' ? 'Review Submission' : 'Add Production Record'}</Text>
            <Text style={productionStyles.subtitle}>{step === 'review' ? 'Check every detail before you submit.' : 'Choose the farm area first, then the category and the commodity.'}</Text>
          </View>
          {optionsLoading ? <EmptyState title="Loading your farm areas and commodities" loading /> : optionsError || !catalog ? <EmptyState title="Form unavailable" message={optionsError || 'The commodity list could not be loaded.'} retry={() => setOptionsRevision((value) => value + 1)} /> : farms.length === 0 ? <EmptyState title="No active farm area" message="Add a farm under My Farms before recording production." icon="farm"><Action title="Go to My Farms" onPress={() => router.push('/my-farms')} /></EmptyState> : step === 'form' ? <>
            <View onLayout={remember('farmId')} style={styles.field}>
              <Text style={styles.label}>1. Farm Area / Farm Location *</Text>
              <Pressable accessibilityRole="button" accessibilityLabel={`Farm area: ${farm?.name || 'not selected'}`} onPress={() => setPicker('farm')} style={[styles.select, errors.farmId && styles.inputError]}>
                <Icon name="farm" size={20} />
                <View style={{ flex: 1 }}><Text style={[styles.selectText, !farm && styles.placeholder]}>{farm ? farm.name : 'Select your farm area'}</Text>{farm && <Text style={ui.small}>{farm.barangayName || farm.address}</Text>}</View><Icon name="chevron" />
              </Pressable>
              {!!errors.farmId && <Text style={styles.errorText}>{errors.farmId}</Text>}
            </View>
            <View onLayout={remember('category')} style={styles.field}>
              <Text style={[styles.label, !farm && styles.labelDisabled]}>2. Production Category *</Text>
              <Pressable accessibilityRole="button" accessibilityState={{ disabled: !farm }} disabled={!farm} accessibilityLabel={`Production category: ${category?.label || 'not selected'}`} onPress={() => setPicker('category')} style={[styles.select, !farm && styles.selectDisabled, errors.category && styles.inputError]}>
                {category && <Icon name={categoryIcon(category.code)} size={20} />}
                <Text style={[styles.selectText, !category && styles.placeholder]}>{category ? category.label : farm ? 'Select production category' : 'Select a farm area first'}</Text><Icon name="chevron" color={farm ? palette.forest : palette.muted} />
              </Pressable>
              {!!farm && <Text style={styles.help}>Registered activity: {catalog.categories.find((item) => item.code === farm.agriculturalCategory)?.label || farm.agriculturalCategory}. Mixed farming: any category can be chosen.</Text>}
              {!!errors.category && <Text style={styles.errorText}>{errors.category}</Text>}
            </View>
            <View onLayout={remember('commodityId')} style={styles.field}>
              <Text style={[styles.label, !category && styles.labelDisabled]}>3. Commodity / Agricultural Product *</Text>
              <Pressable accessibilityRole="button" accessibilityState={{ disabled: !category }} disabled={!category} accessibilityLabel={`Commodity: ${commodity?.name || 'not selected'}`} onPress={() => setPicker('commodity')} style={[styles.select, !category && styles.selectDisabled, errors.commodityId && styles.inputError]}>
                <Icon name="search" size={20} color={category ? palette.forest : palette.muted} />
                <View style={{ flex: 1 }}><Text style={[styles.selectText, !commodity && styles.placeholder]}>{commodity ? commodity.name : category ? `Search ${category.label.toLowerCase()} commodities` : 'Select a category first'}</Text>{commodity && <Text style={ui.small}>{commodity.group}</Text>}</View><Icon name="chevron" color={category ? palette.forest : palette.muted} />
              </Pressable>
              {!!errors.commodityId && <Text style={styles.errorText}>{errors.commodityId}</Text>}
            </View>
            <View onLayout={remember('harvestDate')} style={styles.field}>
              <Text style={styles.label}>4. Date Harvested / Produced *</Text>
              <Pressable accessibilityRole="button" accessibilityLabel={`Date harvested or produced: ${formatHarvestDate(draft.harvestDate)}. Change date`} onPress={openDatePicker} style={[styles.select, errors.harvestDate && styles.inputError]}>
                <Icon name="calendar" size={20} /><Text style={styles.selectText}>{formatHarvestDate(draft.harvestDate)}</Text><Icon name="edit" size={18} />
              </Pressable>
              <Text style={styles.help}>Future dates cannot be selected.</Text>
              {!!errors.harvestDate && <Text style={styles.errorText}>{errors.harvestDate}</Text>}
            </View>
            <View onLayout={remember('quantity')} style={styles.field}>
              <Text style={styles.label}>5. Quantity Produced *</Text>
              <View style={[styles.numberShell, errors.quantity && styles.inputError]}>
                <TextInput accessibilityLabel={`Quantity produced${unit ? ', in ' + unit.name.toLowerCase() : ''}`} value={draft.quantity} onChangeText={(text) => update('quantity', cleanQuantityInput(text, unit ? unit.decimals : 3))}
                  placeholder="0" placeholderTextColor="#9AA08D" keyboardType={unit && unit.decimals === 0 ? 'number-pad' : 'decimal-pad'} inputMode={unit && unit.decimals === 0 ? 'numeric' : 'decimal'} maxLength={11} style={styles.numberInput} />
                <Text style={styles.unit}>{unit ? unit.symbol : '—'}</Text>
              </View>
              <Text style={styles.help}>{unit ? (unit.decimals === 0 ? `Whole ${unit.name.toLowerCase()} only.` : `Up to ${unit.decimals} decimal places.`) : 'Choose a commodity to see its units.'}</Text>
              {!!errors.quantity && <Text style={styles.errorText}>{errors.quantity}</Text>}
            </View>
            <View onLayout={remember('unitCode')} style={styles.field}>
              <Text style={[styles.label, !commodity && styles.labelDisabled]}>6. Unit of Measurement *</Text>
              {commodity ? <View style={styles.units} accessibilityRole="radiogroup">
                {commodityUnits.map((item) => <Pressable key={item.code} accessibilityRole="radio" accessibilityState={{ checked: item.code === draft.unitCode }} accessibilityLabel={item.name} onPress={() => update('unitCode', item.code)} style={[styles.unitOption, item.code === draft.unitCode && styles.unitOptionSelected]}>
                  <Text style={[styles.unitOptionText, item.code === draft.unitCode && styles.unitOptionTextSelected]}>{item.name} ({item.symbol})</Text>
                  {item.code === commodity.defaultUnit && <Text style={styles.recommended}>Recommended</Text>}
                </Pressable>)}
              </View> : <Text style={styles.help}>Available after you choose a commodity.</Text>}
              {!!commodity && commodity.productType !== 'animal_product' && commodity.units.includes('head') && <Text style={styles.help}>Head counts the animals; kilograms record their live weight.</Text>}
              {!!unit && !isWeight && <Text style={styles.help}>{unit.name} are totalled separately and never added to kilograms.</Text>}
              {!!errors.unitCode && <Text style={styles.errorText}>{errors.unitCode}</Text>}
            </View>
            <View onLayout={remember('remarks')} style={styles.field}>
              <Text style={styles.label}>7. Remarks / Notes <Text style={styles.optional}>(optional)</Text></Text>
              <TextInput accessibilityLabel="Remarks or notes, optional" value={draft.remarks} onChangeText={(text) => update('remarks', text)} placeholder="e.g. Second cropping, harvested after the rains." placeholderTextColor="#9AA08D" multiline maxLength={PRODUCTION_REMARKS_MAX_LENGTH} textAlignVertical="top" style={[styles.input, styles.remarks, errors.remarks && styles.inputError]} />
              <Text style={[styles.help, { textAlign: 'right' }]}>{draft.remarks.length} / {PRODUCTION_REMARKS_MAX_LENGTH}</Text>
              {!!errors.remarks && <Text style={styles.errorText}>{errors.remarks}</Text>}
            </View>
            {!!errors.form && <Text style={productionStyles.error}>{errors.form}</Text>}
            {!!submitError && <Text style={productionStyles.error}>{submitError}</Text>}
            <Action title="Review Submission" onPress={review} />
            <Pressable accessibilityRole="button" onPress={backToReport} style={styles.cancel}><Text style={styles.cancelText}>Cancel</Text></Pressable>
          </> : <>
            <View style={ui.card}>
              <View style={ui.row}><Text style={[ui.cardTitle, { flex: 1 }]}>Production record</Text><Pressable accessibilityRole="button" accessibilityLabel="Edit production record" disabled={submitBusy} onPress={() => setStep('form')} style={styles.editButton}><Icon name="edit" /></Pressable></View>
              <ReviewRow label="Farm area" value={farm ? `${farm.name}${farm.barangayName ? ' · ' + farm.barangayName : ''}` : 'Not selected'} />
              <ReviewRow label="Category" value={category?.label || 'Not selected'} />
              <ReviewRow label="Commodity" value={commodity ? `${commodity.name} (${commodity.group})` : 'Not selected'} />
              <ReviewRow label="Date" value={formatHarvestDate(draft.harvestDate)} />
              <ReviewRow label="Quantity" value={unit ? formatQuantity(Number(draft.quantity), unit.symbol) : draft.quantity} strong />
              <ReviewRow label="Unit" value={unit?.name || 'Not selected'} />
              <ReviewRow label="Remarks" value={draft.remarks.trim() || 'None'} />
              <Text style={ui.small}>{isWeight ? `This record counts toward your ${monthLabel(draft.harvestDate.slice(0, 7))} kilogram total.` : `This record is shown in ${unit?.name.toLowerCase() || 'its unit'} for ${monthLabel(draft.harvestDate.slice(0, 7))}, separately from the kilogram total.`}</Text>
            </View>
            {!!duplicateNotice && <View style={[ui.card, styles.notice]}>
              <Text style={styles.noticeTitle}>Possible duplicate</Text>
              <Text style={ui.body}>{duplicateNotice}</Text>
              <Action title="Yes, this is a separate harvest" busy={submitBusy} onPress={() => void submit(true)} />
              <Action title="Edit record" secondary disabled={submitBusy} onPress={() => { setDuplicateNotice(''); setStep('form'); }} />
            </View>}
            {!!submitError && <Text style={productionStyles.error}>{submitError}</Text>}
            {!duplicateNotice && <Action title="Confirm & Submit" icon="check" busy={submitBusy} onPress={() => void submit()} />}
            <Pressable accessibilityRole="button" disabled={submitBusy} onPress={() => setStep('form')} style={styles.cancel}><Text style={styles.cancelText}>Back to form</Text></Pressable>
          </>}
        </>}
      </ScrollView>
    </KeyboardAvoidingView>

    <SearchablePicker visible={picker === 'farm'} title="Select your farm area" searchPlaceholder="Search farm areas by name or barangay" emptyText="No matching farm area found." searchable={farms.length > 1}
      selectedKey={draft.farmId ? String(draft.farmId) : null} getSections={farmSections} onSelect={(key) => selectFarm(Number(key))} onClose={() => setPicker(null)} />
    <SearchablePicker visible={picker === 'category'} title="Select production category" searchPlaceholder="Search categories" emptyText="No matching category found."
      selectedKey={draft.category} getSections={categorySections} onSelect={(key) => selectCategory(key as ProductionCategoryCode)} onClose={() => setPicker(null)} />
    <SearchablePicker visible={picker === 'commodity'} title={category ? `${category.label} commodities` : 'Commodities'} searchPlaceholder="Search, e.g. banana, kamote, itlog" emptyText="No matching commodity found."
      selectedKey={draft.commodityId ? String(draft.commodityId) : null} getSections={commoditySections} onSelect={(key) => selectCommodity(Number(key))} onClose={() => setPicker(null)} />
    {dateOpen && Platform.OS !== 'ios' && <DateTimePicker value={dateDraft} mode="date" display="default" maximumDate={dateFromKey(today)} minimumDate={dateFromKey(PRODUCTION_EARLIEST_HARVEST_DATE)} onChange={handleDateChange} />}
    {dateOpen && Platform.OS === 'ios' && <Modal visible transparent animationType="fade" onRequestClose={() => setDateOpen(false)}>
      <View style={styles.backdrop}><View style={styles.sheet}>
        <Text style={styles.sheetTitle}>Date Harvested / Produced</Text>
        <DateTimePicker value={dateDraft} mode="date" display="spinner" maximumDate={dateFromKey(today)} minimumDate={dateFromKey(PRODUCTION_EARLIEST_HARVEST_DATE)} onChange={handleDateChange} themeVariant="light" textColor={palette.ink} accentColor={palette.forest} />
        <Text style={[ui.body, { textAlign: 'center' }]}>Selected: {formatHarvestDate(dateKey(dateDraft))}</Text>
        <View style={styles.sheetButtons}>
          <View style={{ flex: 1 }}><Action title="Cancel" secondary onPress={() => setDateOpen(false)} /></View>
          <View style={{ flex: 1 }}><Action title="Confirm Date" onPress={() => { update('harvestDate', dateKey(dateDraft)); setDateOpen(false); }} /></View>
        </View>
      </View></View>
    </Modal>}
  </AppScreen>;
}

function ReviewRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return <View style={styles.reviewRow}><Text style={styles.reviewLabel}>{label}</Text><Text style={[strong ? ui.cardTitle : ui.body, { flex: 1 }]}>{value}</Text></View>;
}

const styles = StyleSheet.create({
  field: { gap: 6 },
  label: { color: palette.ink, fontSize: 14, fontWeight: '600' },
  labelDisabled: { color: palette.muted },
  optional: { color: palette.muted, fontSize: 13, fontWeight: '400' },
  help: { color: palette.muted, fontSize: 12, lineHeight: 18, marginLeft: 3 },
  select: { minHeight: 50, paddingVertical: 10, paddingHorizontal: 15, gap: 10, borderWidth: 1, borderColor: palette.border, borderRadius: 15, backgroundColor: palette.paper, flexDirection: 'row', alignItems: 'center' },
  selectDisabled: { backgroundColor: '#F1F1EC', opacity: 0.7 },
  selectText: { flex: 1, color: palette.ink, fontSize: 16 },
  placeholder: { color: '#9AA08D' },
  input: { minHeight: 46, paddingVertical: 10, paddingHorizontal: 15, borderWidth: 1, borderColor: palette.border, borderRadius: 15, backgroundColor: palette.paper, color: palette.ink, fontSize: 16 },
  remarks: { minHeight: 110, paddingTop: 12 },
  numberShell: { minHeight: 52, borderWidth: 1, borderColor: palette.border, borderRadius: 15, backgroundColor: palette.paper, paddingHorizontal: 15, flexDirection: 'row', alignItems: 'center' },
  numberInput: { flex: 1, minWidth: 0, paddingVertical: 10, color: palette.ink, fontSize: 20, fontWeight: '700' },
  unit: { color: palette.muted, fontSize: 16, fontWeight: '700', marginLeft: 8 },
  units: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  unitOption: { flexGrow: 1, minWidth: 130, minHeight: 50, borderWidth: 1, borderColor: palette.border, borderRadius: 15, backgroundColor: palette.paper, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10, paddingVertical: 6 },
  unitOptionSelected: { borderColor: palette.forest, backgroundColor: '#EDF6E8' },
  unitOptionText: { color: palette.ink, fontSize: 14, fontWeight: '600', textAlign: 'center' },
  unitOptionTextSelected: { color: palette.forest, fontWeight: '800' },
  recommended: { color: palette.forest, fontSize: 11, marginTop: 2 },
  inputError: { borderColor: '#B14545' },
  errorText: { color: '#B14545', fontSize: 13, marginLeft: 3 },
  cancel: { minHeight: 48, alignItems: 'center', justifyContent: 'center' },
  cancelText: { color: palette.forest, fontSize: 15, fontWeight: '700' },
  editButton: { minWidth: 44, minHeight: 44, alignItems: 'center', justifyContent: 'center' },
  reviewRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, paddingVertical: 4 },
  reviewLabel: { color: palette.muted, fontSize: 12, width: 92, paddingTop: 2 },
  notice: { backgroundColor: palette.amberSoft, borderColor: '#F0DFC1' },
  noticeTitle: { color: palette.amber, fontSize: 15, fontWeight: '800' },
  done: { alignItems: 'stretch', paddingVertical: 24, gap: 12 },
  doneIcon: { alignSelf: 'center', width: 84, height: 84, borderRadius: 42, backgroundColor: palette.sage, alignItems: 'center', justifyContent: 'center' },
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: palette.paper, borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 22, paddingBottom: 30, gap: 6 },
  sheetTitle: { color: palette.ink, fontSize: 20, fontWeight: '800', marginBottom: 6 },
  sheetButtons: { flexDirection: 'row', gap: 10, marginTop: 8 },
});
