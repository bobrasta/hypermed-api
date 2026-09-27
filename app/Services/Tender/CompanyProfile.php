<?php

namespace App\Services\Tender;

use App\Models\Setting;

/**
 * Company-standing details every generated tender/TMDA document reuses
 * (19.4) — entered once in Settings, never retyped per tender. Defaults are
 * taken from the company's own executed documents.
 */
class CompanyProfile
{
    public const KEY = 'company_profile';

    public static function defaults(): array
    {
        return [
            'legal_name'       => 'HYPERMED HEALTHCARE LIMITED',
            'name'             => 'Hypermed Healthcare Limited',
            'short_name'       => 'Hypermed Healthcare Ltd',
            'physical_address' => 'Trust House, 2nd Floor, Plot No. 58/29B, Mwinyijuma Road, Mwananyamala Komakoma, Kinondoni District',
            'po_box'           => '14118',
            'city'             => 'Dar es Salaam',
            'tin'              => '138-960-609',
            'md_name'          => 'Moses Deogratias Kiduduye',
            'md_title'         => 'Managing Director',
            'md_address'       => 'Plot number 334/43, House number KJM-MWG 334, Bamaga, Kijitonyama, Kinondoni District, Dar es Salaam',
            'md_phone'         => '+255 767 026655',
            'tmda_offices'     => [
                ['name' => 'TMDA Head Office', 'address' => "DIRECTOR GENERAL\nTMDA –Head Office PSSF\nHouse ,10th Floor Makole\nRoad, P.O. Box 1253\nDodoma, Tanzania"],
            ],
        ];
    }

    public static function get(): array
    {
        $saved = json_decode((string) Setting::get(self::KEY, ''), true);

        return is_array($saved) ? array_replace(self::defaults(), $saved) : self::defaults();
    }

    public static function save(array $data, ?int $userId): array
    {
        $profile = array_replace(self::get(), array_intersect_key($data, self::defaults()));
        Setting::set(self::KEY, json_encode($profile), $userId);

        return $profile;
    }
}
