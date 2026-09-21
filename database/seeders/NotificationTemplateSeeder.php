<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

// Seeds one row per (event, audience) pair, with title/body copied EXACTLY
// from the hardcoded strings each call site used before this feature —
// zero behavior change on first deploy, editable afterward without a
// redeploy. See project_clickhuduma_goldmine_buildout memory for the full
// audit this was built from.
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // ── Sales leads ──────────────────────────────────────────────
            ['lead.follow_up_due', 'lead_follow_up', 'Follow-up Due',
                'Follow up with {client_name} — {machine_type} deal is due for a check-in.',
                'Sales rep — a lead\'s follow-up date is due or overdue'],

            // ── Expenses ─────────────────────────────────────────────────
            ['expense.submitted_cto', 'expense_requested', 'Expense Submitted',
                "{name} submitted an expense: {expense_name} (TZS {gross_amount}).",
                'CTO — a new expense needs review'],
            ['expense.submitted_escalated', 'expense_escalated', 'Expense Submitted',
                "{name} submitted an expense: {expense_name} (TZS {gross_amount}).",
                'Director — a new expense needs review (category/amount requires director sign-off)'],
            ['expense.paid', 'expense_paid', 'Expense Paid',
                "Your expense '{expense_name}' has been paid.",
                'Requester — their expense payment was released'],
            ['expense.ready_to_pay', 'expense_approved', 'Expense Ready to Pay',
                "{name}'s expense '{expense_name}' was approved — needs payment initiated.",
                'Finance — an approved expense is ready for payment'],
            ['expense.escalated_to_director', 'expense_escalated', 'Expense Escalated',
                "CTO escalated {name}'s expense '{expense_name}' (TZS {gross_amount}) for your approval.",
                'Director — CTO escalated an expense for approval'],
            ['expense.approved_requester', 'expense_approved', 'Expense Approved',
                "Your expense '{expense_name}' was approved.",
                'Requester — their expense was approved'],
            ['expense.rejected_requester', 'expense_rejected', 'Expense Rejected',
                "Your expense '{expense_name}' was rejected.{reason_suffix}",
                'Requester — their expense was rejected'],

            // ── HR: late arrival, leave, contract/probation alerts ──────
            ['late_arrival.notify_hr', 'late_arrival', 'Running Late',
                "{name} will be late today{when_suffix}.{reason_suffix}",
                'HR — a staff member reported they will be late'],
            ['hr_alert.contract_expiring', 'hr_alert', 'Contract expiring',
                'Contract expiring for {staff_name} on {due_date}.',
                'HR — a staff contract is expiring soon'],
            ['hr_alert.probation_ending', 'hr_alert', 'Probation period ending',
                'Probation period ending for {staff_name} on {due_date}.',
                'HR — a staff probation period is ending soon'],
            ['leave.notify_hr', 'leave_requested', 'Leave Request Submitted',
                "{name} requested {leave_type} leave, {start_date} to {end_date} ({days_count} day(s)).",
                'HR — a new leave request needs review'],
            ['leave.approved_requester', 'leave_approved', 'Leave Approved',
                "Your {leave_type} leave ({start_date} to {end_date}) was approved.",
                'Requester — their leave request was approved'],
            ['leave.rejected_requester', 'leave_rejected', 'Leave Rejected',
                "Your {leave_type} leave request was rejected.{reason_suffix}",
                'Requester — their leave request was rejected'],

            // ── Stock-out requests ───────────────────────────────────────
            ['stock_out.notify_cto', 'stock_out_requested', 'Stock-Out Request Submitted',
                "{name} requested to {action_type} {quantity} x {item_name}.",
                'CTO — a new stock-out request needs review'],
            ['stock_out.approved_requester', 'stock_out_approved', 'Stock-Out Approved',
                "Your request to {action_type} {quantity} x {item_name} was approved.",
                'Requester — their stock-out request was approved'],
            ['stock_out.rejected_requester', 'stock_out_rejected', 'Stock-Out Rejected',
                "Your stock-out request for {item_name} was rejected.{reason_suffix}",
                'Requester — their stock-out request was rejected'],

            // ── Per-diem ──────────────────────────────────────────────────
            ['per_diem.paid', 'per_diem_paid', 'Per-Diem Paid',
                "Your per-diem request for {destination} has been paid.",
                'Requester — their per-diem payment was released'],
            ['per_diem.notify_team_lead', 'per_diem_requested', 'Per-Diem Request Submitted',
                "{name} requested per-diem for {destination}, {start_date} to {end_date} ({days_count} day(s)).",
                'Team lead (or CTO if no team lead) — a new per-diem request needs review'],
            ['per_diem.forwarded_cto', 'per_diem_forwarded', 'Per-Diem Request Forwarded',
                "Team lead forwarded {name}'s per-diem request for {destination}.",
                'CTO — team lead forwarded a per-diem request'],
            ['per_diem.ready_to_pay_finance', 'per_diem_approved', 'Per-Diem Ready to Pay',
                "{name}'s per-diem request for {destination} was approved — needs payment initiated.",
                'Finance — an approved per-diem request is ready for payment'],
            ['per_diem.final_authorization_director', 'per_diem_approved', 'Per-Diem — Final Authorization',
                "Finance initiated payment for {name}'s per-diem request — needs your authorization to pay.",
                'Director — final sign-off needed after finance initiated payment'],
            ['per_diem.approved_requester', 'per_diem_approved', 'Per-Diem Approved',
                "Your per-diem request for {destination} was approved.",
                'Requester — their per-diem request was approved'],
            ['per_diem.rejected_requester', 'per_diem_rejected', 'Per-Diem Rejected',
                "Your per-diem request for {destination} was rejected.{reason_suffix}",
                'Requester — their per-diem request was rejected'],

            // ── Tasks ─────────────────────────────────────────────────────
            ['task.completed_managers', 'task_completed', 'Task Completed',
                "{assignee_name} completed: {task_title}",
                'Managers with task-board access — a task was marked complete'],
            ['task.assigned', 'task_assigned', 'New Task Assigned',
                "{creator_name} assigned you: {task_title}",
                'Assignee — a new task was assigned to them'],

            // ── Inventory ─────────────────────────────────────────────────
            ['inventory.low_stock_alert', 'low_stock_alert', 'Low Stock',
                "{item_name} ({item_sku}) is at {stock_qty}, at or below its reorder level of {reorder_level}.",
                'Procurement/storekeeper — an item hit its reorder level'],

            // ── Service tickets ───────────────────────────────────────────
            ['ticket.assigned_technician', 'ticket_assigned', 'Service Ticket Assigned',
                "You've been assigned {ticket_number} — {machine}{description_suffix}",
                'Technician — a service ticket was assigned to them'],
            ['ticket.assigned_team_lead_notice', 'ticket_assigned', 'Technician Deployed',
                "{assignee_name} was assigned to {ticket_number} — {machine}.",
                'Team lead — a technician was deployed to a ticket'],
            ['ticket.billing_overridden', 'ticket_billing_overridden', 'Ticket Billing Decision Changed',
                "Ticket #{ticket_number} was reclassified as {label} by {actor_name}.",
                'Finance — a ticket\'s warranty/billable classification changed'],
            // Section 6: "requester and CTO are notified when the ticket is
            // fully complete." notification_type reuses 'ticket_updated'
            // (already in the notifications type check constraint) rather
            // than adding a new one just for this.
            ['ticket.fully_resolved', 'ticket_updated', 'Service Ticket Resolved',
                "Ticket #{ticket_number} ({machine_label}) has been resolved.",
                'Assignee and CTO — a service ticket (possibly covering several machines) was fully resolved'],

            // ── Sales orders ──────────────────────────────────────────────
            ['sales_order.stock_pull_required', 'stock_pull_required', 'Order Ready to Pack',
                "{order_number} for {client_name} is confirmed — ready stock for delivery.",
                'Storekeeper — a confirmed order needs stock pulled for delivery'],

            // ── Purchase orders ───────────────────────────────────────────
            ['purchase_order.rejected', 'po_rejected', 'Purchase Order Rejected',
                "{po_number} was rejected.{reason_suffix}",
                'Order creator — their purchase order was rejected at any stage'],
            ['purchase_order.submitted', 'po_submitted', 'Purchase Order Submitted',
                '{po_number} was submitted and needs sales review.',
                'Sales manager — a new PO needs review'],
            ['purchase_order.sales_approved_director', 'po_sales_approved', 'Purchase Order — Director Review',
                '{po_number} passed sales review and needs director review.',
                'Director — a PO passed sales review'],
            ['purchase_order.sales_approved_cto_notice', 'po_sales_approved', 'Purchase Order — Director Review',
                '{po_number} passed sales review and is now with the director.',
                'CTO — visibility-only notice that a PO moved to director review'],
            ['purchase_order.director_reviewed', 'po_director_reviewed', 'Purchase Order — Payment Needed',
                '{po_number} was approved by the director and needs payment initiated.',
                'Accountant — a director-approved PO needs payment initiated'],
            ['purchase_order.payment_initiated', 'po_payment_initiated', 'Purchase Order — Final Approval',
                'Payment was initiated for {po_number} — needs final director approval.',
                'Director — final approval needed after payment was initiated'],
            ['purchase_order.approved', 'po_approved', 'Purchase Order Approved',
                '{po_number} is fully approved and ready to send to the supplier.',
                'Order creator — their PO cleared every approval stage'],
        ];

        foreach ($templates as [$key, $type, $title, $body, $description]) {
            NotificationTemplate::firstOrCreate(
                ['template_key' => $key],
                [
                    'notification_type' => $type,
                    'title_template'    => $title,
                    'body_template'     => $body,
                    'description'       => $description,
                ],
            );
        }
    }
}
