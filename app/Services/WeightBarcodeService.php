<?php

namespace App\Services;

class WeightBarcodeService
{
    const PREFIX = '20';

    public static function isWeightBarcode(string $barcode): bool
    {
        return strlen($barcode) === 13
            && substr($barcode, 0, 2) === self::PREFIX
            && ctype_digit($barcode);
    }

    public static function decode(string $barcode): ?array
    {
        if (! self::isWeightBarcode($barcode)) {
            return null;
        }

        if (! self::isValidCheckDigit($barcode)) {
            return null;
        }

        $pluCode = substr($barcode, 2, 5);
        $pricePaise = (int) substr($barcode, 7, 5);

        return [
            'plu_code' => $pluCode,
            'price' => round($pricePaise / 100, 2),
        ];
    }

    private static function isValidCheckDigit(string $barcode): bool
    {
        $digits = str_split(substr($barcode, 0, 12));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += ($i % 2 === 0) ? (int) $d : (int) $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return (int) $barcode[12] === $check;
    }

    /** Testing ke liye — khud se ek sample weight-barcode banane ke liye */
    public static function encode(string $pluCode, float $price): string
    {
        $pluPadded = str_pad($pluCode, 5, '0', STR_PAD_LEFT);
        $pricePaise = (int) round($price * 100);
        $pricePadded = str_pad((string) $pricePaise, 5, '0', STR_PAD_LEFT);

        $base = self::PREFIX.$pluPadded.$pricePadded;

        $sum = 0;
        foreach (str_split($base) as $i => $d) {
            $sum += ($i % 2 === 0) ? (int) $d : (int) $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $base.$check;
    }
}
