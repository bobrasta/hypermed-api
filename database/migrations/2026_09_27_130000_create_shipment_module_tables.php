<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Section 18: import/export shipments. The status is stored as a 1-based
// step index (labels live in the `shipment_settings` Setting and are
// relabelable); gates live in App\Services\Shipment\ShipmentFlow. The
// clearing cost is a Section 16 vendor fee (vendor_fee_id), never a
// separate payment model.
return new class extends Migration
{
    private const NOTIFICATION_TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
        'leave_requested', 'leave_approved', 'leave_rejected', 'late_arrival',
        'stock_out_requested', 'stock_out_approved', 'stock_out_rejected',
        'per_diem_requested', 'per_diem_forwarded', 'per_diem_approved', 'per_diem_rejected',
        'expense_requested', 'expense_escalated', 'expense_approved', 'expense_rejected',
        'po_submitted', 'po_sales_approved', 'po_director_reviewed', 'po_payment_initiated',
        'po_approved', 'po_rejected', 'low_stock_alert', 'per_diem_paid', 'hr_alert',
        'expense_paid', 'ticket_billing_overridden',
        'vendor_fee_ready_for_payment', 'vendor_fee_paid', 'vendor_fee_rejected',
        'tender_deadline', 'tender_overdue', 'device_renewal',
        'shipment_created', 'shipment_status',
    ];

    // 18.4: Tender/Procurement staff run shipments. `logistics` is part of the
    // same Tendering, Compliance & Delivering Logistics department. CTO, MD
    // (super_admin) and the Sales Manager get read-only; department managers
    // get read-only on their own department's shipments in the controller.
    private const GRANTS = [
        'shipments.manage'  => ['procurement_manager', 'logistics', 'super_admin', 'admin'],
        'screens.shipments' => ['procurement_manager', 'logistics', 'cto', 'sales_manager', 'super_admin', 'admin'],
    ];

    private const LABELS = [
        'shipments.manage'  => ['module' => 'procurement', 'label' => 'Manage Import/Export Shipments'],
        'screens.shipments' => ['module' => 'screens', 'label' => 'View Shipments Screen'],
    ];

    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // The only department the original notes name; the rest are added
        // in Shipments settings (18.3 — must generalise).
        DB::table('departments')->insert(['name' => 'Microbiology', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->string('direction')->default('import');
            $t->string('freight_mode')->default('sea');
            $t->string('description', 500);
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('outbound_reason')->nullable();          // export: warranty return, RMA ref…
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $t->date('expected_arrival')->nullable();
            $t->string('port')->nullable();
            $t->text('location_notes')->nullable();
            $t->unsignedSmallInteger('step')->default(1);
            $t->string('tmda_application_ref')->nullable();
            $t->date('tmda_applied_at')->nullable();
            $t->date('tmda_issued_at')->nullable();
            $t->string('control_number')->nullable();
            $t->foreignId('vendor_fee_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->index(['direction', 'step']);
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_direction_check CHECK (direction IN ('import','export'))");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_freight_mode_check CHECK (freight_mode IN ('air','sea','road'))");

        Schema::create('shipment_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('path');
            $t->string('original_name');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['shipment_id', 'type']);
        });

        Schema::create('shipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('from_step')->nullable();
            $t->unsignedSmallInteger('to_step');
            $t->text('note')->nullable();
            $t->text('reason')->nullable();                     // required when going backward
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('machine_shipment', function (Blueprint $t) {
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $t->primary(['shipment_id', 'machine_id']);
        });

        // SHP-2026-0001 via DocumentNumberService (editable in Settings › Numbering).
        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => 'shipment', 'label' => 'Shipment', 'prefix' => 'SHP', 'digits' => 4,
            'reset_yearly' => true, 'next_number' => 1, 'year' => (int) now()->format('Y'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->setNotificationTypes(self::NOTIFICATION_TYPES);

        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], self::LABELS[$name]);
            foreach (Role::whereIn('name', $roles)->get() as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        foreach ([
            ['shipment.created', 'shipment_created', 'New Shipment {reference}',
                '{actor} opened {direction} shipment {reference}: {description}.',
                'CTO, MD, Sales Manager and the department manager — a shipment was created'],
            ['shipment.status_changed', 'shipment_status', 'Shipment {reference}: {status}',
                '{reference} ({description}) moved to "{status}".{note_suffix}',
                'CTO, MD, Sales Manager and the department manager — a shipment changed status'],
        ] as [$key, $type, $title, $body, $description]) {
            DB::table('notification_templates')->insertOrIgnore([
                'template_key' => $key, 'notification_type' => $type, 'title_template' => $title,
                'body_template' => $body, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->bust();
    }

    public function down(): void
    {
        DB::table('document_sequences')->where('document_type', 'shipment')->delete();
        DB::table('notification_templates')->whereIn('template_key', ['shipment.created', 'shipment.status_changed'])->delete();
        DB::table('notifications')->whereIn('type', ['shipment_created', 'shipment_status'])->delete();
        $this->setNotificationTypes(array_values(array_diff(self::NOTIFICATION_TYPES, ['shipment_created', 'shipment_status'])));
        foreach (array_keys(self::GRANTS) as $name) {
            Permission::where('name', $name)->delete();
        }
        foreach (['machine_shipment', 'shipment_events', 'shipment_documents', 'shipments', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->bust();
    }

    private function setNotificationTypes(array $types): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        $list = "'" . implode("','", $types) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }

    private function bust(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }
};
