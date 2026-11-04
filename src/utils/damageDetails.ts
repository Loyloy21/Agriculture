/**
 * Category-specific damage details for a new damage report. Mirrors backend/lib/damage_report_details.php
 * (a test compares the option lists): the farmer chooses what was affected, a SUSPECTED cause (not a proven
 * one), and the loss in the category's own measure. Nothing here converts a loss into money.
 */
export type DamageCategory = 'Crops' | 'Livestock' | 'Poultry' | 'Fishpond';
export type MeasureType = 'crop_percent' | 'head_count' | 'bird_count' | 'fish_count' | 'fish_weight_kg';
export type FishUnit = 'count' | 'kg';

export const DAMAGE_DETAILS_VERSION = 1;
const MAX_COUNT = 10000000;
const MAX_KG = 100000000;
const MAX_HECTARES = 9999999;

export const DAMAGE_DETAILS_CONFIG: Record<DamageCategory, { measure: MeasureType; subjectLabel: string; subjects: string[]; causes: string[]; unit: string; title: string; subtitle: string }> = {
  Crops: {
    measure: 'crop_percent', subjectLabel: 'Crop', unit: '%', title: 'Crops', subtitle: 'Report damage to your crops',
    subjects: ['Rice', 'Corn', 'Coconut', 'Banana', 'Vegetables', 'Root crops', 'Fruit trees', 'Sugarcane', 'Other'],
    causes: ['Flooding', 'Typhoon / strong wind', 'Drought / dry spell', 'Pest infestation', 'Plant disease', 'Fire', 'Landslide', 'Other', 'Unsure'],
  },
  Livestock: {
    measure: 'head_count', subjectLabel: 'Animal type', unit: 'head', title: 'Livestock', subtitle: 'Report losses of your livestock',
    subjects: ['Pigs', 'Cattle', 'Carabao', 'Goats', 'Sheep', 'Horses', 'Other'],
    causes: ['Disease', 'Heat stress', 'Flooding', 'Typhoon / strong wind', 'Lack of feed or water', 'Injury / predator attack', 'Poisoning', 'Other', 'Unsure'],
  },
  Poultry: {
    measure: 'bird_count', subjectLabel: 'Bird type', unit: 'birds', title: 'Poultry', subtitle: 'Report losses of your poultry',
    subjects: ['Chickens', 'Ducks', 'Turkeys', 'Quail', 'Geese', 'Other'],
    causes: ['Disease', 'Heat stress', 'Flooding', 'Typhoon / strong wind', 'Lack of feed or water', 'Predator attack', 'Other', 'Unsure'],
  },
  Fishpond: {
    measure: 'fish_count', subjectLabel: 'Fish type', unit: 'fish', title: 'Fishpond', subtitle: 'Report losses in your fishpond',
    subjects: ['Tilapia', 'Bangus (milkfish)', 'Hito (catfish)', 'Carp', 'Shrimp / prawn', 'Other'],
    causes: ['Poor water quality', 'Low oxygen / fish kill', 'Flooding / pond overflow', 'Drought / low water level', 'Disease', 'Pollution', 'Typhoon / strong wind', 'Other', 'Unsure'],
  },
};

/** The farmer's entries, kept as typed text until submission. */
export type DamageDetailsDraft = {
  subjectType: string; subjectTypeOther: string;
  suspectedCause: string; suspectedCauseOther: string;
  cropDamagePercent: string; plantedArea: string; affectedArea: string;
  fishUnit: FishUnit | '';
  totalBefore: string; amountLost: string;
};

export function emptyDamageDetails(): DamageDetailsDraft {
  return { subjectType: '', subjectTypeOther: '', suspectedCause: '', suspectedCauseOther: '', cropDamagePercent: '', plantedArea: '', affectedArea: '', fishUnit: '', totalBefore: '', amountLost: '' };
}

export function isDamageCategory(value: string): value is DamageCategory {
  return value === 'Crops' || value === 'Livestock' || value === 'Poultry' || value === 'Fishpond';
}

export function measureFor(category: DamageCategory, fishUnit: FishUnit | ''): MeasureType {
  if (category === 'Fishpond') return fishUnit === 'kg' ? 'fish_weight_kg' : 'fish_count';
  return DAMAGE_DETAILS_CONFIG[category].measure;
}

export function unitFor(measure: MeasureType) {
  return { crop_percent: '%', head_count: 'head', bird_count: 'birds', fish_count: 'fish', fish_weight_kg: 'kg' }[measure];
}

/** Keeps only digits (and one decimal point when decimals are allowed) while typing. */
export function cleanNumberInput(value: string, allowDecimal: boolean) {
  const digits = value.replace(allowDecimal ? /[^0-9.]/g : /\D/g, '');
  if (!allowDecimal) return digits;
  const [whole, ...rest] = digits.split('.');
  return rest.length ? `${whole}.${rest.join('')}` : whole;
}

function parseNumber(value: string, whole: boolean, decimals: number, maximum: number): number | '' | null {
  const text = value.trim();
  if (!text) return '';
  const pattern = whole ? /^\d+$/ : new RegExp(`^\\d+(?:\\.\\d{1,${decimals}})?$`);
  if (!pattern.test(text) || Number(text) > maximum) return null;
  return Number(text);
}

export function formatAmount(value: number, decimals = 2) {
  return value.toLocaleString('en-PH', { maximumFractionDigits: decimals });
}

/** Loss as a percentage of the stock before the incident (one decimal), or null when the total is unknown/zero. */
export function lossPercent(total: number | null, lost: number | null) {
  if (total === null || lost === null || total <= 0) return null;
  return Math.round((lost / total) * 1000) / 10;
}

/** Field errors for the current category (keys match the API's error keys). */
export function damageDetailsErrors(category: DamageCategory, draft: DamageDetailsDraft): Record<string, string> {
  const config = DAMAGE_DETAILS_CONFIG[category];
  const errors: Record<string, string> = {};
  if (!config.subjects.includes(draft.subjectType)) errors.subjectType = `Select the ${config.subjectLabel.toLowerCase()}.`;
  else if (draft.subjectType === 'Other') {
    const other = draft.subjectTypeOther.trim();
    if (other.length < 2 || other.length > 100) errors.subjectTypeOther = `Type the ${config.subjectLabel.toLowerCase()} (2 to 100 characters).`;
  }
  if (!config.causes.includes(draft.suspectedCause)) errors.suspectedCause = 'Select the suspected cause, or choose Unsure.';
  else if (draft.suspectedCause === 'Other') {
    const other = draft.suspectedCauseOther.trim();
    if (other.length < 3 || other.length > 255) errors.suspectedCauseOther = 'Explain the other cause (3 to 255 characters).';
  }
  if (category === 'Crops') {
    const percent = parseNumber(draft.cropDamagePercent, false, 2, 100);
    if (percent === null) errors.cropDamagePercent = 'Enter a percentage from 0 to 100.';
    else if (percent === '') errors.cropDamagePercent = 'Enter your estimate of how much of this crop was damaged (0 to 100%).';
    const planted = parseNumber(draft.plantedArea, false, 4, MAX_HECTARES);
    const affected = parseNumber(draft.affectedArea, false, 4, MAX_HECTARES);
    if (planted === null) errors.plantedArea = 'Enter the planted area in hectares, or leave it blank.';
    if (affected === null) errors.affectedArea = 'Enter the affected area in hectares, or leave it blank.';
    else if (typeof planted === 'number' && typeof affected === 'number' && affected > planted) errors.affectedArea = 'The affected area cannot be larger than the planted area.';
    return errors;
  }
  if (category === 'Fishpond' && !draft.fishUnit) {
    errors.fishUnit = 'Choose whether you are recording a number of fish or kilograms.';
    return errors;
  }
  const measure = measureFor(category, draft.fishUnit);
  const unit = unitFor(measure);
  const whole = measure !== 'fish_weight_kg';
  const kind = whole ? 'a whole number' : 'a number with up to 2 decimal places';
  const counted = measure === 'head_count' || measure === 'bird_count';
  const total = parseNumber(draft.totalBefore, whole, 2, whole ? MAX_COUNT : MAX_KG);
  const lost = parseNumber(draft.amountLost, whole, 2, whole ? MAX_COUNT : MAX_KG);
  if (total === null) errors.totalBefore = `Enter the total before the incident as ${kind} (${unit}).`;
  else if (total === '' && counted) errors.totalBefore = `Enter how many ${unit} there were before the incident.`;
  else if (typeof total === 'number' && total <= 0) errors.totalBefore = counted ? 'The total before the incident must be more than 0.' : 'The total before the incident must be more than 0, or leave it blank if unknown.';
  if (lost === null) errors.amountLost = `Enter the amount ${counted ? 'that died' : 'lost'} as ${kind} (${unit}).`;
  else if (lost === '') errors.amountLost = counted ? `Enter how many ${unit} died (0 if none).` : `Enter the amount lost (${unit}).`;
  else if (typeof total === 'number' && total > 0 && lost > total) errors.amountLost = counted ? 'The number that died cannot be more than the total before the incident.' : 'The amount lost cannot be more than the total stock before the incident.';
  return errors;
}

/** The subject the farmer chose or typed. */
export function subjectName(draft: DamageDetailsDraft) {
  return draft.subjectType === 'Other' ? draft.subjectTypeOther.trim() : draft.subjectType;
}

export function causeLabel(cause: string, other: string) {
  if (cause === 'Other') return `Other: ${other.trim()}`;
  if (cause === 'Unsure') return 'Unsure (cause not known)';
  return cause;
}

/** Live one-line summary, e.g. "30 of 100 head died · 30%". Empty until the needed figures are valid. */
export function damageSummary(category: DamageCategory, draft: DamageDetailsDraft) {
  if (category === 'Crops') {
    const percent = parseNumber(draft.cropDamagePercent, false, 2, 100);
    if (typeof percent !== 'number') return '';
    return `${subjectName(draft) || 'This crop'}: ${formatAmount(percent)}% of this crop damaged`;
  }
  if (category === 'Fishpond' && !draft.fishUnit) return '';
  const measure = measureFor(category, draft.fishUnit);
  const whole = measure !== 'fish_weight_kg';
  const unit = unitFor(measure);
  const verb = measure === 'head_count' || measure === 'bird_count' ? 'died' : 'lost';
  const decimals = whole ? 0 : 2;
  const total = parseNumber(draft.totalBefore, whole, 2, whole ? MAX_COUNT : MAX_KG);
  const lost = parseNumber(draft.amountLost, whole, 2, whole ? MAX_COUNT : MAX_KG);
  if (typeof lost !== 'number') return '';
  if (typeof total !== 'number' || total <= 0) return total === '' ? `${formatAmount(lost, decimals)} ${unit} ${verb} · total stock not provided` : '';
  if (lost > total) return '';
  return `${formatAmount(lost, decimals)} of ${formatAmount(total, decimals)} ${unit} ${verb} · ${formatAmount(lossPercent(total, lost)!, 1)}%`;
}

/** Multipart fields for the report API (details version 1). Only the current category's figures are sent. */
export function damageDetailsFields(category: DamageCategory, draft: DamageDetailsDraft): Record<string, string> {
  const fields: Record<string, string> = {
    damage_details_version: String(DAMAGE_DETAILS_VERSION),
    subject_type: draft.subjectType,
    subject_type_other: draft.subjectType === 'Other' ? draft.subjectTypeOther.trim() : '',
    suspected_cause: draft.suspectedCause,
    suspected_cause_other: draft.suspectedCause === 'Other' ? draft.suspectedCauseOther.trim() : '',
  };
  if (category === 'Crops') {
    fields.crop_damage_percent = draft.cropDamagePercent.trim();
    fields.planted_area_ha = draft.plantedArea.trim();
    fields.affected_area_ha = draft.affectedArea.trim();
  } else {
    if (category === 'Fishpond') fields.fish_unit = draft.fishUnit;
    fields.total_before = draft.totalBefore.trim();
    fields.amount_lost = draft.amountLost.trim();
  }
  return fields;
}
