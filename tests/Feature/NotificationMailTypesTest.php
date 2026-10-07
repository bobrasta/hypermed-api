<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use Tests\TestCase;

// Which notification types also go out by email (config/notification_mail.php).
class NotificationMailTypesTest extends TestCase
{
    public function test_every_workflow_type_is_emailed_by_default_except_low_stock(): void
    {
        foreach (['per_diem_requested', 'expense_approved', 'leave_rejected', 'po_submitted', 'ticket_assigned', 'shipment_status'] as $type) {
            $this->assertTrue(SendNotificationEmail::wanted($type), $type);
        }
        $this->assertFalse(SendNotificationEmail::wanted('low_stock_alert'));
    }

    public function test_an_explicit_list_limits_it(): void
    {
        config(['notification_mail.types' => ['shipment_created'], 'notification_mail.exclude_types' => []]);
        $this->assertTrue(SendNotificationEmail::wanted('shipment_created'));
        $this->assertFalse(SendNotificationEmail::wanted('per_diem_requested'));
    }

    public function test_test_domains_are_never_mailed(): void
    {
        $this->assertTrue(SendNotificationEmail::deliverable('neville.temu@hypermed.co.tz'));
        $this->assertFalse(SendNotificationEmail::deliverable('tech@hypermed.tz'));
        $this->assertFalse(SendNotificationEmail::deliverable('not-an-email'));
    }
}
