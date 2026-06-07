<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            // OEMs / Manufacturers
            [
                'name'          => 'Mindray Medical International',
                'short_code'    => 'MINDRAY',
                'type'          => 'manufacturer',
                'contact_name'  => 'East Africa Sales Team',
                'contact_email' => 'africa@mindray.com',
                'contact_phone' => '+86 755 2658 2000',
                'website'       => 'https://www.mindray.com',
                'city'          => 'Shenzhen',
                'country'       => 'China',
                'currency'      => 'USD',
                'payment_terms' => 'net_60',
                'lead_time_days'=> 45,
                'rating'        => 5,
                'notes'         => 'Primary supplier for ventilators, patient monitors, ultrasound and anaesthesia machines.',
            ],
            [
                'name'          => 'Siemens Healthineers',
                'short_code'    => 'SIEMENS',
                'type'          => 'manufacturer',
                'contact_name'  => 'Africa Region Office',
                'contact_email' => 'africa.healthineers@siemens.com',
                'contact_phone' => '+49 9131 84 0',
                'website'       => 'https://www.siemens-healthineers.com',
                'city'          => 'Erlangen',
                'country'       => 'Germany',
                'currency'      => 'EUR',
                'payment_terms' => 'net_60',
                'lead_time_days'=> 60,
                'rating'        => 5,
                'notes'         => 'X-ray, CT, MRI, laboratory diagnostics and biochemistry analysers.',
            ],
            [
                'name'          => 'GE Healthcare Africa',
                'short_code'    => 'GE',
                'type'          => 'manufacturer',
                'contact_name'  => 'Sub-Saharan Africa Team',
                'contact_email' => 'africa@ge.com',
                'contact_phone' => '+27 11 928 4000',
                'website'       => 'https://www.gehealthcare.com',
                'city'          => 'Johannesburg',
                'country'       => 'South Africa',
                'currency'      => 'USD',
                'payment_terms' => 'net_60',
                'lead_time_days'=> 45,
                'rating'        => 5,
                'notes'         => 'Ultrasound, MRI, CT, patient monitoring, ECG and anaesthesia systems.',
            ],
            [
                'name'          => 'Philips Healthcare',
                'short_code'    => 'PHILIPS',
                'type'          => 'manufacturer',
                'contact_name'  => 'East Africa Representative',
                'contact_email' => 'eastafrica@philips.com',
                'contact_phone' => '+31 40 279 9111',
                'website'       => 'https://www.philips.com/healthcare',
                'city'          => 'Amsterdam',
                'country'       => 'Netherlands',
                'currency'      => 'EUR',
                'payment_terms' => 'net_60',
                'lead_time_days'=> 60,
                'rating'        => 4,
                'notes'         => 'Defibrillators, patient monitors, ECG machines and ultrasound.',
            ],
            [
                'name'          => 'Fresenius Medical Care',
                'short_code'    => 'FRESENIUS',
                'type'          => 'manufacturer',
                'contact_name'  => 'Africa Sales',
                'contact_email' => 'africa@freseniusmedicalcare.com',
                'contact_phone' => '+49 9132 40 0',
                'website'       => 'https://www.freseniusmedicalcare.com',
                'city'          => 'Bad Homburg',
                'country'       => 'Germany',
                'currency'      => 'EUR',
                'payment_terms' => 'net_30',
                'lead_time_days'=> 30,
                'rating'        => 5,
                'notes'         => 'Dialysis machines, dialysate solutions and consumables.',
            ],
            // Regional distributors
            [
                'name'          => 'Medisel Kenya Limited',
                'short_code'    => 'MEDISEL',
                'type'          => 'distributor',
                'contact_name'  => 'James Kariuki',
                'contact_email' => 'sales@mediselkenya.com',
                'contact_phone' => '+254 20 444 5000',
                'website'       => null,
                'city'          => 'Nairobi',
                'country'       => 'Kenya',
                'currency'      => 'USD',
                'payment_terms' => 'net_30',
                'lead_time_days'=> 14,
                'rating'        => 4,
                'notes'         => 'Regional distributor for medical consumables and spare parts. Fast delivery to Tanzania.',
            ],
            [
                'name'          => 'Power Solutions Tanzania',
                'short_code'    => 'POWERSOL',
                'type'          => 'local_vendor',
                'contact_name'  => 'Ahmed Mwamba',
                'contact_email' => 'info@powersolutions.co.tz',
                'contact_phone' => '+255 22 260 1234',
                'website'       => null,
                'city'          => 'Dar es Salaam',
                'country'       => 'Tanzania',
                'currency'      => 'TZS',
                'payment_terms' => 'net_15',
                'lead_time_days'=> 5,
                'rating'        => 3,
                'notes'         => 'Local supplier for UPS batteries, power backup systems and electrical components.',
            ],
            [
                'name'          => 'ZOLL Medical Corporation',
                'short_code'    => 'ZOLL',
                'type'          => 'manufacturer',
                'contact_name'  => 'Africa Distributor Network',
                'contact_email' => 'africa@zoll.com',
                'contact_phone' => '+1 978 421 9655',
                'website'       => 'https://www.zoll.com',
                'city'          => 'Chelmsford',
                'country'       => 'USA',
                'currency'      => 'USD',
                'payment_terms' => 'net_30',
                'lead_time_days'=> 21,
                'rating'        => 4,
                'notes'         => 'Defibrillators, AEDs and resuscitation equipment.',
            ],
        ];

        foreach ($suppliers as $s) {
            Supplier::create($s);
        }
    }
}
