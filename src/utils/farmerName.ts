/**
 * Farmer name parts. Mirrors backend/lib/farmer_identity.php so the app shows the same errors and the
 * same composed full name the server stores.
 */
const PLACEHOLDER = /^(?:n\/?a|n\.a\.?|none|null|undefined|-+|\.)$/i;
const MIDDLE_NAME = /^\p{L}+(?:(?:[ '-]|\. ?)\p{L}+)*\.?$/u;
const EXTENSION_NAME = /^[\p{L}\p{N}]+\.?(?: [\p{L}\p{N}]+\.?)*$/u;

/** Collapses inner whitespace; an optional part typed as a placeholder ("N/A", "none", "-") becomes empty. */
export function normalizeNamePart(value: string, optional = false) {
  const text = (value || '').trim().replace(/\s+/g, ' ');
  return optional && PLACEHOLDER.test(text) ? '' : text;
}

/** "First Middle Last Extension" with empty parts omitted and single spaces. */
export function composeFullName(first: string, middle: string, last: string, extension: string) {
  return [first, middle, last, extension].map((part) => normalizeNamePart(part)).filter(Boolean).join(' ');
}

/** A full middle name or an initial ("Dela Cruz", "Ma. Luisa", "D."); blank is valid. */
export function middleNameError(value: string) {
  if (!value) return '';
  if (value.length > 75) return 'Middle name must be 75 characters or fewer.';
  return MIDDLE_NAME.test(value) ? '' : 'Enter a valid middle name, or leave it blank.';
}

/** Jr., Sr., II, III, IV, 3rd and similar; blank is valid and nothing is forced. */
export function extensionNameError(value: string) {
  if (!value) return '';
  if (value.length > 20) return 'Extension name must be 20 characters or fewer.';
  return EXTENSION_NAME.test(value) ? '' : 'Enter a valid extension such as Jr., Sr., II or III, or leave it blank.';
}
