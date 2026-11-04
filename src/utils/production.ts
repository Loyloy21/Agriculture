// Production report rules shared by the mobile screens. They mirror backend/lib/production_records.php;
// the server repeats every check, so these only give the farmer immediate feedback.

export const PRODUCTION_MAX_QUANTITY = 1000000;
export const PRODUCTION_MAX_QUANTITY_KG = 1000000;
export const PRODUCTION_REMARKS_MAX_LENGTH = 1000;
export const PRODUCTION_EARLIEST_HARVEST_DATE = '2000-01-01';

/** Form order: farm area -> category -> commodity -> date -> quantity -> unit -> remarks. */
export type ProductionDraft = {
  farmId: number | null;
  category: string | null;
  commodityId: number | null;
  harvestDate: string;
  quantity: string;
  unitCode: string | null;
  remarks: string;
};

export type ProductionDraftErrors = Partial<Record<keyof ProductionDraft, string>>;

/** The parts of a unit of measurement the rules need (from the server's measurement_units). */
export type UnitRule = { code: string; symbol: string; decimals: number; kgPerUnit: number | null };
/** The parts of a catalog commodity the rules need. */
export type CommodityRule = { id: number; name: string; category: string; group: string; searchNames: string; units: string[] };

const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000;

/** Today's date in Manila (UTC+8, no daylight saving) as YYYY-MM-DD, the calendar the server uses. */
export function manilaToday(now: Date = new Date()): string {
  return new Date(now.getTime() + MANILA_OFFSET_MS).toISOString().slice(0, 10);
}

/** YYYY-MM-DD from the picker's local calendar date (not its UTC instant). */
export function dateKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Local Date at midnight for a YYYY-MM-DD key, for the date picker. */
export function dateFromKey(key: string): Date {
  const [year, month, day] = key.split('-').map(Number);
  return new Date(year, month - 1, day);
}

export function isValidDateKey(key: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) return false;
  const date = dateFromKey(key);
  return dateKey(date) === key;
}

/** "October 23, 2026" for a YYYY-MM-DD key, independent of the phone's time zone. */
export function formatHarvestDate(key: string): string {
  if (!isValidDateKey(key)) return 'Not recorded';
  const [year, month, day] = key.split('-').map(Number);
  return `${MONTH_NAMES[month - 1]} ${day}, ${year}`;
}

export const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "October 2026" for a YYYY-MM month key. */
export function monthLabel(month: string): string {
  const [year, number] = month.split('-').map(Number);
  return MONTH_NAMES[number - 1] ? `${MONTH_NAMES[number - 1]} ${year}` : month;
}

/** The month before a YYYY-MM key; 2027-01 gives 2026-12. */
export function previousMonthKey(month: string): string {
  const [year, number] = month.split('-').map(Number);
  return number === 1 ? `${year - 1}-12` : `${year}-${String(number - 1).padStart(2, '0')}`;
}

/** Keeps only digits and one decimal point with at most `decimals` decimals (0 = whole numbers only). */
export function cleanQuantityInput(value: string, decimals = 2): string {
  if (decimals === 0) return value.replace(/\D/g, '');
  const digits = value.replace(/[^0-9.]/g, '');
  const [whole, ...rest] = digits.split('.');
  return rest.length ? `${whole}.${rest.join('').slice(0, decimals)}` : whole;
}

/** Same rules as production_quantity() and the kilogram limit in the PHP validation. */
export function quantityError(value: string, unit?: UnitRule | null): string | undefined {
  const decimals = unit ? unit.decimals : 2;
  const text = value.trim();
  if (!text) return 'Enter the quantity produced.';
  const pattern = decimals > 0 ? new RegExp(`^\\d{1,7}(?:\\.\\d{1,${decimals}})?$`) : /^\d{1,7}$/;
  const quantity = Number(text);
  if (/^\d+(?:\.\d+)?$/.test(text) && quantity > PRODUCTION_MAX_QUANTITY) return 'A single record cannot exceed 1,000,000.';
  if (!pattern.test(text) || quantity <= 0) return decimals === 0 ? 'Enter a whole number greater than 0.' : `Enter a number greater than 0 with up to ${decimals} decimal places.`;
  if (unit?.kgPerUnit && Math.round(quantity * 1000) * unit.kgPerUnit / 1000 > PRODUCTION_MAX_QUANTITY_KG) return 'A single record cannot exceed 1,000,000 kg (1,000 MT).';
  return undefined;
}

export function productionDraftErrors(draft: ProductionDraft, today: string, commodity?: CommodityRule | null, unit?: UnitRule | null): ProductionDraftErrors {
  const errors: ProductionDraftErrors = {};
  if (!draft.farmId) errors.farmId = 'Select the farm area where this was produced.';
  if (!draft.category) errors.category = 'Select the production category.';
  if (!draft.commodityId) errors.commodityId = 'Select the commodity you produced.';
  else if (commodity && draft.category && commodity.category !== draft.category) errors.commodityId = 'The selected commodity does not belong to this category.';
  if (!draft.harvestDate) errors.harvestDate = 'Select the date harvested or produced.';
  else if (!isValidDateKey(draft.harvestDate)) errors.harvestDate = 'Select a valid date.';
  else if (draft.harvestDate > today) errors.harvestDate = 'The date cannot be in the future.';
  else if (draft.harvestDate < PRODUCTION_EARLIEST_HARVEST_DATE) errors.harvestDate = 'Select a date from the year 2000 onward.';
  if (!draft.unitCode) errors.unitCode = 'Select the unit of measurement.';
  else if (commodity && !commodity.units.includes(draft.unitCode)) errors.unitCode = `This unit cannot be used for ${commodity.name}.`;
  const quantity = quantityError(draft.quantity, unit);
  if (quantity) errors.quantity = quantity;
  if (draft.remarks.trim().length > PRODUCTION_REMARKS_MAX_LENGTH) errors.remarks = 'Remarks must be 1,000 characters or fewer.';
  // eslint-disable-next-line no-control-regex
  else if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(draft.remarks)) errors.remarks = 'Remarks contain unsupported characters.';
  return errors;
}

/** Lower case, accents removed (ñ -> n), punctuation as spaces: "Cow's Milk" -> "cow s milk". */
export function normalizeSearch(text: string): string {
  const decomposed = typeof text.normalize === 'function' ? text.normalize('NFD') : text;
  return decomposed.replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

/** True when every typed word appears (as part of a word) in the text: case-insensitive partial matching. */
export function matchesSearch(text: string, query: string): boolean {
  const words = normalizeSearch(query).split(' ').filter(Boolean);
  if (!words.length) return true;
  const target = normalizeSearch(text);
  return words.every((word) => target.includes(word));
}

/**
 * Commodities of the selected category whose English, Filipino or local names match the query. Ranked: whole
 * words of the displayed name ("kamote" -> Sweet Potato (Kamote)), then partial name matches ("Kamoteng Kahoy"),
 * then matches on other names only; catalog order within a rank. No category -> nothing.
 */
export function searchCommodities<T extends CommodityRule>(commodities: T[], category: string | null, query: string): T[] {
  if (!category) return [];
  const words = normalizeSearch(query).split(' ').filter(Boolean);
  const matching = commodities.filter((item) => item.category === category && matchesSearch(`${item.name} ${item.group} ${item.searchNames}`, query));
  if (!words.length) return matching;
  const rank = (item: T) => {
    const nameWords = normalizeSearch(item.name).split(' ');
    return words.every((word) => nameWords.includes(word)) ? 0 : matchesSearch(item.name, query) ? 1 : 2;
  };
  return [0, 1, 2].flatMap((level) => matching.filter((item) => rank(item) === level));
}

/** "1,234.5 kg", "1,200 pcs", "0.2 MT": thousands separators, at most three decimals, no trailing zeros. */
export function formatQuantity(value: number, symbol: string): string {
  const fixed = (Math.round(value * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
  const [whole, decimals] = fixed.split('.');
  return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${decimals ? '.' + decimals : ''} ${symbol}`;
}

/** "1,234.5 kg": kilogram totals keep two decimals. */
export function formatKg(value: number): string {
  return formatQuantity(Math.round(value * 100) / 100, 'kg');
}

/** "1,200 pcs · 5 head": totals in units that are never added to kilograms (or to each other). */
export function otherTotalsLine(totals: { total: number; unitSymbol: string }[]): string {
  return totals.map((item) => formatQuantity(item.total, item.unitSymbol)).join(' · ');
}

export function formatPercent(value: number): string {
  return `${Number.isInteger(value) ? value : value.toFixed(1)}%`;
}
