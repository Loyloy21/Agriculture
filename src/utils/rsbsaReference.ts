/**
 * RSBSA reference number as printed on the RSBSA ID card: five groups of 2, 2, 2, 3 and 6 digits
 * (00-00-00-000-000000). Always handled as a string so leading zeroes are kept.
 *
 * The form stores the groups joined by hyphens, including partly filled groups ("16-0--" keeps the
 * typed digits in their own group), so Back/Continue never moves digits between groups.
 */
export const RSBSA_GROUP_SIZES = [2, 2, 2, 3, 6] as const;
export const RSBSA_DIGIT_COUNT = 15;
export const RSBSA_REFERENCE_FORMAT = '00-00-00-000-000000';
const LAST_GROUP = RSBSA_GROUP_SIZES.length - 1;

function fillGroups(groups: string[], digits: string, start: number) {
  let rest = digits;
  let last = start;
  for (let index = start; index <= LAST_GROUP && rest; index++) {
    groups[index] = rest.slice(0, RSBSA_GROUP_SIZES[index]);
    rest = rest.slice(RSBSA_GROUP_SIZES[index]);
    last = index;
  }
  return last;
}

/** The five digit groups of a stored value: grouped, partly grouped, bare digits or empty. */
export function rsbsaGroups(value: string): string[] {
  const text = (value || '').trim();
  const groups = RSBSA_GROUP_SIZES.map(() => '');
  if (/^\d+$/.test(text)) {
    fillGroups(groups, text.slice(0, RSBSA_DIGIT_COUNT), 0);
    return groups;
  }
  const parts = text.split('-');
  return groups.map((_, index) => (parts[index] || '').replace(/\D/g, '').slice(0, RSBSA_GROUP_SIZES[index]));
}

/** The stored form value for the given groups ('' when nothing was entered). */
export function rsbsaValue(groups: string[]) {
  return groups.every((group) => !group) ? '' : groups.join('-');
}

export function rsbsaDigitCount(value: string) {
  return rsbsaGroups(value).join('').length;
}

export function isCompleteRsbsaReference(value: string) {
  return /^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}$/.test(value);
}

/** Field-level message for the reference number, or '' when complete. */
export function rsbsaReferenceError(value: string) {
  if (rsbsaDigitCount(value) === 0) return 'Enter the 15-digit RSBSA reference number printed on your card.';
  return isCompleteRsbsaReference(value) ? '' : `Enter all 15 digits of the RSBSA reference number (${RSBSA_REFERENCE_FORMAT}).`;
}

/**
 * Applies the text a group's input reported. Typing fills the group and moves on when it is full; a
 * whole pasted number (with or without hyphens) fills every group; extra digits spill into the next
 * groups. Returns the new value and the group that should receive focus, if any.
 */
export function rsbsaApplyInput(value: string, groupIndex: number, text: string): { value: string; focus: number | null } {
  const groups = rsbsaGroups(value);
  const digits = text.replace(/\D/g, '');
  const size = RSBSA_GROUP_SIZES[groupIndex];
  if (digits.length >= RSBSA_DIGIT_COUNT) {
    fillGroups(groups, digits.slice(0, RSBSA_DIGIT_COUNT), 0);
    return { value: rsbsaValue(groups), focus: LAST_GROUP };
  }
  if (digits.length <= size) {
    const grew = digits.length > groups[groupIndex].length;
    groups[groupIndex] = digits;
    return { value: rsbsaValue(groups), focus: grew && digits.length === size && groupIndex < LAST_GROUP ? groupIndex + 1 : null };
  }
  const last = fillGroups(groups, digits, groupIndex);
  const lastFull = groups[last].length === RSBSA_GROUP_SIZES[last];
  return { value: rsbsaValue(groups), focus: lastFull && last < LAST_GROUP ? last + 1 : last };
}

/** Backspace pressed in an empty group: remove the previous group's last digit and move focus there. */
export function rsbsaApplyBackspace(value: string, groupIndex: number): { value: string; focus: number } | null {
  const groups = rsbsaGroups(value);
  if (groupIndex === 0 || groups[groupIndex]) return null;
  const previous = groupIndex - 1;
  groups[previous] = groups[previous].slice(0, -1);
  return { value: rsbsaValue(groups), focus: previous };
}
