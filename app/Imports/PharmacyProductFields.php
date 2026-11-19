<?php

namespace App\Imports;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Excel row se pharmacy columns padhta hai, clean karta hai aur validate karta hai.
 *
 * Use (ProductController::importExcel):
 *   - Pharmacy store : PharmacyProductFields::parse($raw, true)   // pharmacy columns COMPULSORY
 *   - Mart store     : PharmacyProductFields::hasAny($raw)        // pharmacy columns allowed NAHI
 *
 * parse() sirf wahi fields return karta hai jo Excel mein bhare hain.
 * Error messages user ko import result mein dikhte hain, isliye English mein hain.
 */
class PharmacyProductFields
{
    public const SCHEDULES = ['none', 'G', 'H', 'H1', 'X'];

    // Pharmacy store par ye columns har row mein compulsory hain
    // (requires_prescription optional hai: H/H1/X par apne aap 1 hota hai)
    public const REQUIRED = [
        'salt_composition',
        'manufacturer',
        'drug_schedule',
        'pack_type',
        'units_per_pack',
    ];

    // Schedules jin par prescription zaroori hoti hai
    private const RX_SCHEDULES = ['H', 'H1', 'X'];

    // Excel heading (WithHeadingRow ke baad snake_case) ke alternate naam
    private const ALIASES = [
        'salt_composition' => ['salt_composition', 'salt', 'composition', 'generic_name'],
        'manufacturer' => ['manufacturer', 'mfr', 'company', 'manufacturer_name'],
        'drug_schedule' => ['drug_schedule', 'schedule'],
        'requires_prescription' => ['requires_prescription', 'prescription_required', 'rx'],
        'pack_type' => ['pack_type', 'pack'],
        'units_per_pack' => ['units_per_pack', 'pack_size', 'strip_size'],
    ];

    /**
     * @param  array|Arrayable  $row
     * @param  bool  $required  true = pharmacy store (REQUIRED columns bharna zaroori)
     * @return array{data: array<string, mixed>, errors: array<int, string>}
     */
    public static function parse($row, bool $required = false): array
    {
        $row = $row instanceof Arrayable ? $row->toArray() : (array) $row;

        $data = [];
        $errors = [];

        if ($required) {
            foreach (self::REQUIRED as $field) {
                if (self::get($row, $field) === null) {
                    $errors[] = "{$field} is required for pharmacy products.";
                }
            }
        }

        // salt_composition, manufacturer
        foreach (['salt_composition', 'manufacturer'] as $field) {
            $value = self::get($row, $field);

            if ($value === null) {
                continue;
            }

            if (mb_strlen($value) > 255) {
                $errors[] = "{$field} must not be longer than 255 characters.";
                continue;
            }

            $data[$field] = $value;
        }

        // drug_schedule
        $rawSchedule = self::get($row, 'drug_schedule');
        $schedule = null;

        if ($rawSchedule !== null) {
            $schedule = self::normalizeSchedule($rawSchedule);

            if ($schedule === null) {
                $errors[] = "drug_schedule '{$rawSchedule}' is invalid (allowed: ".implode(', ', self::SCHEDULES).').';
            } else {
                $data['drug_schedule'] = $schedule;
            }
        }

        // requires_prescription
        $rawRx = self::get($row, 'requires_prescription');

        if ($rawRx !== null) {
            $rx = self::toBool($rawRx);

            if ($rx === null) {
                $errors[] = "requires_prescription '{$rawRx}' is invalid (use 1 or 0).";
            } else {
                $data['requires_prescription'] = $rx;
            }
        }

        // Schedule H/H1/X par prescription hamesha zaroori (ProductController::store ka same rule),
        // chahe Excel mein khali ho ya 0 likha ho.
        if ($schedule !== null && in_array($schedule, self::RX_SCHEDULES, true)) {
            $data['requires_prescription'] = true;
        }

        // pack_type
        $packType = self::get($row, 'pack_type');

        if ($packType !== null) {
            $packType = mb_strtolower($packType);

            if (mb_strlen($packType) > 30) {
                $errors[] = 'pack_type must not be longer than 30 characters.';
            } else {
                $data['pack_type'] = $packType;
            }
        }

        // units_per_pack
        $units = self::get($row, 'units_per_pack');

        if ($units !== null) {
            if (is_numeric($units) && (float) $units == (int) $units && (int) $units > 0) {
                $data['units_per_pack'] = (int) $units;
            } else {
                $errors[] = "units_per_pack '{$units}' must be a whole number of 1 or more.";
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Row mein koi bhi pharmacy column bhara hai ya nahi.
     * Mart (non-pharmacy) store par ye true aaye to row reject hoti hai.
     */
    public static function hasAny($row): bool
    {
        $row = $row instanceof Arrayable ? $row->toArray() : (array) $row;

        foreach (array_keys(self::ALIASES) as $field) {
            if (self::get($row, $field) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function get(array $row, string $field): ?string
    {
        foreach (self::ALIASES[$field] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function normalizeSchedule(string $raw): ?string
    {
        // "Schedule H1", "sch h", "H 1" -> "H1"
        $value = strtoupper($raw);
        $value = preg_replace('/SCHEDULE|SCH\.?/', '', $value);
        $value = preg_replace('/[\s\-_]+/', '', $value);

        if (in_array($value, ['NONE', 'OTC', 'NA', 'N/A'], true)) {
            return 'none';
        }

        return in_array($value, ['G', 'H', 'H1', 'X'], true) ? $value : null;
    }

    private static function toBool(string $raw): ?bool
    {
        $value = strtolower($raw);

        if (in_array($value, ['1', 'yes', 'y', 'true', 'rx'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'no', 'n', 'false'], true)) {
            return false;
        }

        return null;
    }
}