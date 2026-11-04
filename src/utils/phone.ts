/** Digits shown after the fixed +63 registration prefix. */
export function registrationMobileDigits(input: string): string {
  let value = input.trim().replace(/[\s().-]/g, '');
  if (value.startsWith('+63')) value = value.slice(3);
  else if (value.startsWith('63')) value = value.slice(2);
  if (value.startsWith('0')) value = value.slice(1);
  return value;
}

export function isValidRegistrationMobile(input: string): boolean {
  return /^9\d{9}$/.test(registrationMobileDigits(input));
}

export function registrationMobileE164(input: string): string {
  const digits = registrationMobileDigits(input);
  if (!/^9\d{9}$/.test(digits)) throw new Error('INVALID_PH_MOBILE');
  return `+63${digits}`;
}
