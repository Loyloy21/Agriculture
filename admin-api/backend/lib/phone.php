<?php
declare(strict_types=1);

/** Canonical form for Philippine mobile numbers: +639XXXXXXXXX. */
function agrireach_ph_mobile(string $input): ?string
{
    $compact = preg_replace('/[\s().-]+/', '', trim($input));
    if (!is_string($compact) || !preg_match('/^(?:\+?63)?0?(9\d{9})$/', $compact, $matches)) {
        return null;
    }
    return '+63' . $matches[1];
}

/** Legacy rows can contain a local 09 format; check equivalent values before inserting. */
function agrireach_ph_mobile_equivalents(string $canonical): array
{
    $digits = substr($canonical, 3);
    return [$canonical, '0' . $digits, $digits, '63' . $digits];
}
