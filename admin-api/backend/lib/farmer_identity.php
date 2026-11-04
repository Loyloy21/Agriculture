<?php
declare(strict_types=1);

// Farmer name parts and the RSBSA reference number, shared by registration, the farmer profile API and
// the admin pages so every path validates, stores and composes them the same way.

const RSBSA_ID_TYPE = 'RSBSA ID';
/** Five groups of 2, 2, 2, 3 and 6 digits, as printed on the RSBSA ID card. */
const RSBSA_REFERENCE_FORMAT = '00-00-00-000-000000';
const FARMER_NAME_MAX_LENGTH = 75;
const FARMER_EXTENSION_NAME_MAX_LENGTH = 20;

/**
 * The canonical "00-00-00-000-000000" form of a pasted or typed RSBSA reference number, or null.
 * Accepts the 15 digits alone or already grouped with hyphens. It stays a string: leading zeroes are
 * significant.
 */
function rsbsa_reference_normalize(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^\d{15}$/', $value) && !preg_match('/^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}$/', $value)) return null;
    $digits = str_replace('-', '', $value);
    return substr($digits, 0, 2) . '-' . substr($digits, 2, 2) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 3) . '-' . substr($digits, 9, 6);
}

/** Error message for a new RSBSA ID registration's reference number, or null when it is valid. */
function rsbsa_reference_error(string $value): ?string
{
    if (trim($value) === '') return 'Enter the 15-digit RSBSA reference number printed on your card.';
    return rsbsa_reference_normalize($value) === null
        ? 'Enter all 15 digits of the RSBSA reference number (' . RSBSA_REFERENCE_FORMAT . ').'
        : null;
}

/** Collapses inner whitespace; an optional part typed as a placeholder ("N/A", "none", "-") becomes empty. */
function farmer_name_part(string $value, bool $optional = false): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($optional && preg_match('/^(?:n\/?a|n\.a\.?|none|null|undefined|-+|\.)$/i', $value)) return '';
    return $value;
}

/** First and Last Name: letters with single spaces, apostrophes or hyphens between them (unchanged rule). */
function farmer_required_name_valid(string $value): bool
{
    return $value !== '' && mb_strlen($value) <= FARMER_NAME_MAX_LENGTH && preg_match("/^[\p{L}]+(?:[ '-][\p{L}]+)*$/u", $value) === 1;
}

/** Middle Name: a full name or an initial ("Dela Cruz", "Ma. Luisa", "D."); blank is allowed. */
function farmer_middle_name_valid(string $value): bool
{
    return $value === '' || (mb_strlen($value) <= FARMER_NAME_MAX_LENGTH && preg_match("/^\p{L}+(?:(?:[ '-]|\. ?)\p{L}+)*\.?$/u", $value) === 1);
}

/** Extension Name / Suffix such as Jr., Sr., II, III, IV or 3rd; blank is allowed and nothing is forced. */
function farmer_extension_name_valid(string $value): bool
{
    return $value === '' || (mb_strlen($value) <= FARMER_EXTENSION_NAME_MAX_LENGTH && preg_match('/^[\p{L}\p{N}]+\.?(?: [\p{L}\p{N}]+\.?)*$/u', $value) === 1);
}

/** "First Middle Last Extension" with empty parts omitted and no double spaces. */
function farmer_compose_full_name(string $first, string $middle, string $last, string $extension): string
{
    $parts = array_filter(array_map(static fn (string $part): string => farmer_name_part($part), [$first, $middle, $last, $extension]), static fn (string $part): bool => $part !== '');
    return implode(' ', $parts);
}

/**
 * Validates the four name parts. Returns [errors, parts] where parts holds the normalized values and the
 * composed full_name. Error keys match the mobile form fields.
 *
 * @return array{0:array<string,string>,1:array{first:string,middle:string,last:string,extension:string,full:string}}
 */
function farmer_validate_name_parts(string $first, string $middle, string $last, string $extension): array
{
    $parts = [
        'first' => farmer_name_part($first),
        'middle' => farmer_name_part($middle, true),
        'last' => farmer_name_part($last),
        'extension' => farmer_name_part($extension, true),
    ];
    $errors = [];
    if (!farmer_required_name_valid($parts['first'])) $errors['firstName'] = 'Enter a valid first name.';
    if (!farmer_middle_name_valid($parts['middle'])) $errors['middleName'] = 'Enter a valid middle name, or leave it blank.';
    if (!farmer_required_name_valid($parts['last'])) $errors['lastName'] = 'Enter a valid last name.';
    if (!farmer_extension_name_valid($parts['extension'])) $errors['extensionName'] = 'Enter a valid extension such as Jr., Sr., II or III, or leave it blank.';
    $parts['full'] = farmer_compose_full_name($parts['first'], $parts['middle'], $parts['last'], $parts['extension']);
    if (!$errors && mb_strlen($parts['full']) > 150) $errors['middleName'] = 'The full name is too long. Shorten the middle name.';
    return [$errors, $parts];
}
