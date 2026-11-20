<?php

namespace App\Support;

/**
 * Bulk "Product details" Excel ke columns.
 *
 * Excel column key => product_info ka label (online store par yehi label dikhta hai).
 * Naya column chahiye to bas yahan ek line add karo.
 */
class ProductDetailsColumns
{
    public const INFO = [
        'unit' => 'Unit',
        'shelf_life' => 'Shelf Life',
        'storage_tips' => 'Storage Tips',
        'country_of_origin' => 'Country of Origin',
        'fssai_license' => 'FSSAI License',
        'nutrition_information' => 'Nutrition Information',
        'allergen_information' => 'Allergen Information',
        'ingredients' => 'Ingredients',
        'taste_profile' => 'Taste Profile',
        'return_policy' => 'Return Policy',
        'customer_care_details' => 'Customer Care Details',
        'seller' => 'Seller',
        'seller_fssai' => 'Seller FSSAI',
        'disclaimer' => 'Disclaimer',
    ];

    public const MAX_INFO_ROWS = 20;
    public const MAX_DESCRIPTION = 5000;
    public const MAX_VALUE = 1500;

    /** Excel ki heading row */
    public static function headings(): array
    {
        return array_merge(
            ['product_id', 'sku', 'barcode', 'name', 'description'],
            array_keys(self::INFO)
        );
    }

    /**
     * product_info me label (case-insensitive) ke hisaab se merge karo:
     * same label ki row ki value badal jaati hai, baaki rows jaisi hain waisi rehti hain.
     */
    public static function mergeInfo(array $existing, array $incoming): array
    {
        $rows = [];
        foreach ($existing as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $rows[mb_strtolower($label)] = ['label' => $label, 'value' => $value];
            }
        }

        foreach ($incoming as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $rows[mb_strtolower($label)] = ['label' => $label, 'value' => $value];
        }

        return array_values($rows);
    }
}