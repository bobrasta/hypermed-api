<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        $hospitals = [
            ['name' => 'Muhimbili National Hospital',   'short_code' => 'MNH',  'type' => 'public',   'region' => 'Dar es Salaam', 'district' => 'Ilala',      'latitude' => -6.8007,  'longitude' => 39.2694, 'zone' => 'coastal',   'machine_count' => 12, 'machines_operational' => 10, 'revenue_monthly' => 18500000, 'contact_name' => 'Dr. Hamisi Salim',    'contact_phone' => '+255 22 215 0610'],
            ['name' => 'Aga Khan Hospital Dar',         'short_code' => 'AKH',  'type' => 'private',  'region' => 'Dar es Salaam', 'district' => 'Upanga',     'latitude' => -6.7924,  'longitude' => 39.2830, 'zone' => 'coastal',   'machine_count' => 8,  'machines_operational' => 8,  'revenue_monthly' => 14200000, 'contact_name' => 'Nurse Rehema Ally',   'contact_phone' => '+255 22 211 4096'],
            ['name' => 'Kilimanjaro Christian Medical', 'short_code' => 'KCMC', 'type' => 'mission',  'region' => 'Kilimanjaro',   'district' => 'Moshi',      'latitude' => -3.3595,  'longitude' => 37.3437, 'zone' => 'northern',  'machine_count' => 10, 'machines_operational' => 9,  'revenue_monthly' => 12800000, 'contact_name' => 'Dr. Angela Mrema',    'contact_phone' => '+255 27 275 4377'],
            ['name' => 'Bugando Medical Centre',        'short_code' => 'BMC',  'type' => 'public',   'region' => 'Mwanza',        'district' => 'Nyamagana',  'latitude' => -2.5099,  'longitude' => 32.8966, 'zone' => 'lake',      'machine_count' => 9,  'machines_operational' => 7,  'revenue_monthly' => 11300000, 'contact_name' => 'Francis Magesa',      'contact_phone' => '+255 28 250 0611'],
            ['name' => 'Dodoma Regional Hospital',      'short_code' => 'DRH',  'type' => 'public',   'region' => 'Dodoma',        'district' => 'Dodoma',     'latitude' => -6.1725,  'longitude' => 35.7395, 'zone' => 'central',   'machine_count' => 6,  'machines_operational' => 5,  'revenue_monthly' => 8200000,  'contact_name' => 'Sister Consolata',    'contact_phone' => '+255 26 232 1180'],
            ['name' => 'Mbeya Zonal Referral Hospital', 'short_code' => 'MZRH', 'type' => 'public',   'region' => 'Mbeya',         'district' => 'Mbeya',      'latitude' => -8.9000,  'longitude' => 33.4500, 'zone' => 'shighland', 'machine_count' => 7,  'machines_operational' => 6,  'revenue_monthly' => 9600000,  'contact_name' => 'Dr. Joseph Mwambela', 'contact_phone' => '+255 25 250 2329'],
            ['name' => 'Arusha Lutheran Medical',       'short_code' => 'ALMC', 'type' => 'mission',  'region' => 'Arusha',        'district' => 'Arusha',     'latitude' => -3.3869,  'longitude' => 36.6827, 'zone' => 'northern',  'machine_count' => 5,  'machines_operational' => 5,  'revenue_monthly' => 7400000,  'contact_name' => 'Nurse Veronica',      'contact_phone' => '+255 27 254 8282'],
            ['name' => 'Mwananyamala Hospital',         'short_code' => 'MWH',  'type' => 'public',   'region' => 'Dar es Salaam', 'district' => 'Kinondoni',  'latitude' => -6.7640,  'longitude' => 39.2440, 'zone' => 'coastal',   'machine_count' => 4,  'machines_operational' => 3,  'revenue_monthly' => 5800000,  'contact_name' => 'Dr. Pius Nkya',       'contact_phone' => '+255 22 277 0006'],
            ['name' => 'Temeke Hospital',               'short_code' => 'TMH',  'type' => 'public',   'region' => 'Dar es Salaam', 'district' => 'Temeke',     'latitude' => -6.8700,  'longitude' => 39.2900, 'zone' => 'coastal',   'machine_count' => 4,  'machines_operational' => 3,  'revenue_monthly' => 5200000,  'contact_name' => 'Nurse Joyce',         'contact_phone' => '+255 22 285 0220'],
            ['name' => 'Sekou Toure Hospital Mwanza',   'short_code' => 'STH',  'type' => 'public',   'region' => 'Mwanza',        'district' => 'Ilemela',    'latitude' => -2.5140,  'longitude' => 32.9010, 'zone' => 'lake',      'machine_count' => 5,  'machines_operational' => 4,  'revenue_monthly' => 6700000,  'contact_name' => 'Dr. Charles Matata',  'contact_phone' => '+255 28 254 0770'],
        ];

        foreach ($hospitals as $h) {
            Hospital::create($h);
        }
    }
}
