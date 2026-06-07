<?php

namespace Database\Seeders;

use App\Models\Machine;
use Illuminate\Database\Seeder;

class MachineSeeder extends Seeder
{
    public function run(): void
    {
        $machines = [
            // MNH (hospital 1)
            ['serial_no' => 'VEN-MNH-001', 'model' => 'Mindray SV300',     'type' => 'Ventilator',       'hospital_id' => 1, 'ward' => 'ICU',         'install_date' => '2021-03-15', 'warranty_expiry' => '2024-03-15', 'status' => 'operational',   'revenue_per_month' => 1800000],
            ['serial_no' => 'XRY-MNH-001', 'model' => 'Siemens MOBILETT',  'type' => 'X-Ray',            'hospital_id' => 1, 'ward' => 'Radiology',   'install_date' => '2020-07-10', 'warranty_expiry' => '2023-07-10', 'status' => 'operational',   'revenue_per_month' => 1500000],
            ['serial_no' => 'DIA-MNH-001', 'model' => 'Siemens ADVIA',     'type' => 'Dialysis',         'hospital_id' => 1, 'ward' => 'Renal Unit',  'install_date' => '2022-01-20', 'warranty_expiry' => '2025-01-20', 'status' => 'needs_service', 'revenue_per_month' => 2100000],
            // AKH (hospital 2)
            ['serial_no' => 'MRI-AKH-001', 'model' => 'GE Signa 1.5T',    'type' => 'MRI',              'hospital_id' => 2, 'ward' => 'Imaging',     'install_date' => '2021-09-01', 'warranty_expiry' => '2026-09-01', 'status' => 'operational',   'revenue_per_month' => 3500000],
            ['serial_no' => 'ECG-AKH-001', 'model' => 'Philips PageWriter','type' => 'ECG Machine',      'hospital_id' => 2, 'ward' => 'Cardiology',  'install_date' => '2022-05-12', 'warranty_expiry' => '2025-05-12', 'status' => 'operational',   'revenue_per_month' => 900000],
            // KCMC (hospital 3)
            ['serial_no' => 'ULT-KCM-001', 'model' => 'Mindray DC-80',    'type' => 'Ultrasound',       'hospital_id' => 3, 'ward' => 'Obstetrics',  'install_date' => '2020-11-05', 'warranty_expiry' => '2023-11-05', 'status' => 'operational',   'revenue_per_month' => 1200000],
            ['serial_no' => 'VEN-KCM-001', 'model' => 'Hamilton C6',       'type' => 'Ventilator',       'hospital_id' => 3, 'ward' => 'ICU',         'install_date' => '2023-02-14', 'warranty_expiry' => '2026-02-14', 'status' => 'warranty',      'revenue_per_month' => 1700000],
            ['serial_no' => 'BIO-KCM-001', 'model' => 'Roche Cobas c311', 'type' => 'Biochem Analyser', 'hospital_id' => 3, 'ward' => 'Lab',         'install_date' => '2021-06-20', 'warranty_expiry' => '2024-06-20', 'status' => 'operational',   'revenue_per_month' => 1100000],
            // BMC (hospital 4)
            ['serial_no' => 'XRY-BMC-001', 'model' => 'Philips Bucky',    'type' => 'X-Ray',            'hospital_id' => 4, 'ward' => 'Radiology',   'install_date' => '2019-04-18', 'warranty_expiry' => '2022-04-18', 'status' => 'down',          'revenue_per_month' => 1300000],
            ['serial_no' => 'ULT-BMC-001', 'model' => 'GE Vivid E90',     'type' => 'Ultrasound',       'hospital_id' => 4, 'ward' => 'Radiology',   'install_date' => '2022-08-30', 'warranty_expiry' => '2025-08-30', 'status' => 'operational',   'revenue_per_month' => 1400000],
            // DRH (hospital 5)
            ['serial_no' => 'VEN-DRH-001', 'model' => 'Drager Evita XL',  'type' => 'Ventilator',       'hospital_id' => 5, 'ward' => 'ICU',         'install_date' => '2020-03-22', 'warranty_expiry' => '2023-03-22', 'status' => 'needs_service', 'revenue_per_month' => 1600000],
            ['serial_no' => 'DFB-DRH-001', 'model' => 'ZOLL R Series',    'type' => 'Defibrillator',    'hospital_id' => 5, 'ward' => 'Emergency',   'install_date' => '2021-12-01', 'warranty_expiry' => '2024-12-01', 'status' => 'operational',   'revenue_per_month' => 800000],
            // MZRH (hospital 6)
            ['serial_no' => 'MRI-MZR-001', 'model' => 'Siemens MAGNETOM', 'type' => 'MRI',              'hospital_id' => 6, 'ward' => 'Imaging',     'install_date' => '2022-04-10', 'warranty_expiry' => '2027-04-10', 'status' => 'operational',   'revenue_per_month' => 3200000],
            ['serial_no' => 'ECG-MZR-001', 'model' => 'GE MAC 5500',      'type' => 'ECG Machine',      'hospital_id' => 6, 'ward' => 'Cardiology',  'install_date' => '2021-07-15', 'warranty_expiry' => '2024-07-15', 'status' => 'idle',          'revenue_per_month' => 750000],
        ];

        foreach ($machines as $m) {
            Machine::create($m);
        }
    }
}
