<?php

namespace App\Support;

class StockLabel
{
    /**
     * Human readable quantity.
     * Normal products (factor 1): "12".  Loose-selling products: "5 strips + 3 tablets".
     */
    public static function format($units, $factor = 1, ?string $packType = null, ?string $looseUnit = null): string
    {
        $units = (float) $units;
        $factor = max(1, (int) $factor);

        if ($factor === 1) {
            return self::num($units);
        }

        $sign = $units < 0 ? '-' : '';
        $abs = abs(round($units, 3));
        $packs = (int) floor($abs / $factor);
        $rest = round($abs - $packs * $factor, 3);

        $parts = [];

        if ($packs > 0) {
            $parts[] = $packs.' '.self::plural($packType ?: 'pack', $packs);
        }

        if ($rest > 0 || empty($parts)) {
            $parts[] = self::num($rest).' '.self::plural($looseUnit ?: 'unit', $rest);
        }

        return $sign.implode(' + ', $parts);
    }

    private static function num(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    private static function plural(string $word, $n): string
    {
        return ((float) $n === 1.0 || strtolower($word) === 'ml') ? $word : $word.'s';
    }
}
