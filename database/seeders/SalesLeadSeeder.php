<?php

namespace Database\Seeders;

use App\Models\SalesLead;
use Illuminate\Database\Seeder;

class SalesLeadSeeder extends Seeder
{
    public function run(): void
    {
        $leads = [
            ['hospital_id' => null, 'hospital_name_raw' => 'Singida Regional Hospital', 'contact_name_raw' => 'Dr. Amani Kibona',  'machine_type' => 'Ventilator',  'deal_value' => 42000000, 'stage' => 'qualified',       'assigned_to' => 5],
            ['hospital_id' => 3,   'hospital_name_raw' => null,                         'contact_name_raw' => 'Engr. Paul Njau',   'machine_type' => 'MRI',          'deal_value' => 95000000, 'stage' => 'demo_scheduled',  'demo_date' => '2026-06-15', 'assigned_to' => 5],
            ['hospital_id' => null, 'hospital_name_raw' => 'Iringa Lutheran Hospital',  'contact_name_raw' => 'Sister Magdalena',  'machine_type' => 'Ultrasound',  'deal_value' => 28000000, 'stage' => 'proposal_sent',   'assigned_to' => 5],
            ['hospital_id' => 7,   'hospital_name_raw' => null,                         'contact_name_raw' => 'Dr. John Masanja',  'machine_type' => 'X-Ray',       'deal_value' => 35000000, 'stage' => 'negotiation',     'assigned_to' => 5],
            ['hospital_id' => null, 'hospital_name_raw' => 'Tabora Regional Hospital',  'contact_name_raw' => 'Admin Baraka',      'machine_type' => 'Dialysis',    'deal_value' => 68000000, 'stage' => 'lead',            'assigned_to' => 5],
            ['hospital_id' => 6,   'hospital_name_raw' => null,                         'contact_name_raw' => 'Dr. Mwambe',        'machine_type' => 'Biochem Analyser', 'deal_value' => 22000000, 'stage' => 'won',        'assigned_to' => 5],
            ['hospital_id' => null, 'hospital_name_raw' => 'Private Clinic Dodoma',     'contact_name_raw' => 'Nurse Sarah',       'machine_type' => 'ECG Machine', 'deal_value' => 15000000, 'stage' => 'lost',            'assigned_to' => 5],
        ];

        foreach ($leads as $lead) {
            SalesLead::create($lead);
        }
    }
}
