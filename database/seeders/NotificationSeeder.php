<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $notifications = [
            ['user_id' => 1, 'type' => 'ticket_assigned',   'title' => 'New ticket assigned',           'body' => 'Ticket #1042 for Siemens ADVIA at MNH has been assigned to James Mollel.',    'entity_type' => 'ticket',  'entity_id' => 1,  'is_read' => false],
            ['user_id' => 1, 'type' => 'payment_overdue',   'title' => 'Invoice overdue',                'body' => 'Invoice INV-1003 for KCMC is overdue. Amount: TZS 2,242,000.',                'entity_type' => 'invoice', 'entity_id' => 3,  'is_read' => false],
            ['user_id' => 1, 'type' => 'warranty_expiring', 'title' => 'Warranty expiring soon',         'body' => 'Mindray SV300 (VEN-MNH-001) warranty expires on 2024-03-15.',                'entity_type' => 'machine', 'entity_id' => 1,  'is_read' => true],
            ['user_id' => 2, 'type' => 'ticket_assigned',   'title' => 'Ticket #1042 assigned to you',  'body' => 'You have been assigned to service ticket #1042 at Muhimbili National Hospital.','entity_type' => 'ticket',  'entity_id' => 1,  'is_read' => false],
            ['user_id' => 2, 'type' => 'ticket_assigned',   'title' => 'Ticket #1045 assigned to you',  'body' => 'Routine preventive maintenance ticket #1045 for MNH ICU assigned to you.',     'entity_type' => 'ticket',  'entity_id' => 4,  'is_read' => false],
            ['user_id' => 5, 'type' => 'deal_updated',      'title' => 'Lead moved to Demo Scheduled',  'body' => 'Lead for KCMC MRI has been moved to Demo Scheduled stage.',                    'entity_type' => 'deal',    'entity_id' => 2,  'is_read' => false],
            ['user_id' => 1, 'type' => 'service_due',       'title' => 'Preventive service due',         'body' => 'Hamilton C6 ventilator at KCMC ICU is due for quarterly service.',            'entity_type' => 'machine', 'entity_id' => 7,  'is_read' => false],
            ['user_id' => 1, 'type' => 'payment_overdue',   'title' => 'Invoice overdue',                'body' => 'Invoice INV-1006 for Dodoma Regional Hospital is 30 days overdue.',           'entity_type' => 'invoice', 'entity_id' => 6,  'is_read' => true],
        ];

        foreach ($notifications as $n) {
            AppNotification::create($n);
        }
    }
}
