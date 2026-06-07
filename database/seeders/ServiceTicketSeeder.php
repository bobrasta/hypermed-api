<?php

namespace Database\Seeders;

use App\Models\ServiceTicket;
use Illuminate\Database\Seeder;

class ServiceTicketSeeder extends Seeder
{
    public function run(): void
    {
        $tickets = [
            ['ticket_number' => '#1042', 'machine_id' => 3,  'hospital_id' => 1, 'ward' => 'Renal Unit',  'assigned_to' => 2, 'status' => 'in_progress', 'description' => 'Dialysis machine showing error code E-04. Patient sessions disrupted.'],
            ['ticket_number' => '#1043', 'machine_id' => 9,  'hospital_id' => 4, 'ward' => 'Radiology',   'assigned_to' => 3, 'status' => 'open',        'description' => 'X-Ray unit not powering on. Checked fuses — all intact.'],
            ['ticket_number' => '#1044', 'machine_id' => 11, 'hospital_id' => 5, 'ward' => 'ICU',         'assigned_to' => 4, 'status' => 'overdue',     'description' => 'Ventilator alarm intermittently triggered. Needs calibration.'],
            ['ticket_number' => '#1045', 'machine_id' => 1,  'hospital_id' => 1, 'ward' => 'ICU',         'assigned_to' => 2, 'status' => 'open',        'description' => 'Routine 6-month preventive maintenance due.'],
            ['ticket_number' => '#1040', 'machine_id' => 6,  'hospital_id' => 3, 'ward' => 'Obstetrics',  'assigned_to' => 8, 'status' => 'resolved',    'description' => 'Ultrasound probe replaced. Unit fully operational.'],
            ['ticket_number' => '#1039', 'machine_id' => 10, 'hospital_id' => 4, 'ward' => 'Radiology',   'assigned_to' => 3, 'status' => 'resolved',    'description' => 'GE Ultrasound firmware update applied successfully.'],
        ];

        foreach ($tickets as $t) {
            $ticket = ServiceTicket::create($t);
        }

        // Add checklist items to ticket #1042
        $ticket1042 = ServiceTicket::where('ticket_number', '#1042')->first();
        $ticket1042->checklistItems()->createMany([
            ['label' => 'Inspect water filtration system', 'is_checked' => true],
            ['label' => 'Check dialysate concentration',   'is_checked' => true],
            ['label' => 'Test blood pump flow rate',       'is_checked' => false],
            ['label' => 'Replace bicarbonate cartridge',   'is_checked' => false],
        ]);

        $ticket1045 = ServiceTicket::where('ticket_number', '#1045')->first();
        $ticket1045->checklistItems()->createMany([
            ['label' => 'Clean and inspect filters',     'is_checked' => false],
            ['label' => 'Calibrate pressure sensors',    'is_checked' => false],
            ['label' => 'Test alarm systems',            'is_checked' => false],
            ['label' => 'Lubricate moving parts',        'is_checked' => false],
            ['label' => 'Update firmware if available',  'is_checked' => false],
        ]);
    }
}
