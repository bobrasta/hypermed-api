<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Shipments, 2026-10-20 requests from Procurement:
// - a per-shipment "CoA required" switch (off = the CoA is not applicable);
// - couriers (DHL, FedEx) as a vendor type that both clears and delivers,
//   pickable as clearing agent, vendor-fee vendor, delivery vendor and as
//   the shipment's carrier;
// - "paid by the manufacturer": no cost to HyperMed, so the control-number
//   and clearing-payment gates don't apply; who paid is recorded.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vendors DROP CONSTRAINT vendors_type_check');
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_check CHECK (type IN ('clearing','delivery','courier','other'))");

        foreach (['DHL', 'FedEx'] as $name) {
            if (! DB::table('vendors')->whereRaw('lower(name) = ?', [strtolower($name)])->exists()) {
                DB::table('vendors')->insert(['name' => $name, 'type' => 'courier', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('shipments', function (Blueprint $t) {
            $t->boolean('coa_required')->default(true)->after('freight_mode');
            $t->foreignId('carrier_vendor_id')->nullable()->after('supplier_id')->constrained('vendors')->nullOnDelete();
            $t->string('cost_bearer')->default('hypermed')->after('control_number');
            $t->string('paid_by')->nullable()->after('cost_bearer');
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_cost_bearer_check CHECK (cost_bearer IN ('hypermed','manufacturer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_cost_bearer_check');
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('carrier_vendor_id');
            $t->dropColumn(['coa_required', 'cost_bearer', 'paid_by']);
        });
        DB::table('vendors')->where('type', 'courier')->update(['type' => 'other']);
        DB::statement('ALTER TABLE vendors DROP CONSTRAINT vendors_type_check');
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_check CHECK (type IN ('clearing','delivery','other'))");
    }
};
