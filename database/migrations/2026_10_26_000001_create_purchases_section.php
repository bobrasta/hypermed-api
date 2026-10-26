<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Purchases (user, 2026-10-26): Clickhuduma's Purchases menu — list
 * purchases, add purchase, purchase returns — just above Sales, for the
 * Administrator, sales officers, the Sales Manager and admin. A purchase is
 * a purchase order (Clickhuduma's purchases were imported as POs), so adding
 * one goes through the PO approval chain. These roles get purchases.create
 * (add a purchase, record a return) rather than procurement.create_po, which
 * also opens vendors, vendor fees and delivery jobs. Returns are new: goods
 * sent back to the supplier out of a received purchase.
 */
return new class extends Migration
{
    private const ROLES = ['administrator', 'sales', 'sales_manager', 'admin', 'super_admin'];

    private const STOCK_TYPES = ['receive', 'issue', 'transfer', 'write_off', 'adjustment', 'return'];

    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_number')->unique();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->date('return_date');
            $t->string('currency', 10)->default('TZS');
            $t->bigInteger('total_amount')->default(0);
            // Set when the return went on the supplier's account (the order
            // had a supplier invoice): Dr Trade Creditors / Cr Inventory.
            $t->boolean('posted')->default(false);
            $t->decimal('exchange_rate', 16, 6)->default(1);
            $t->bigInteger('total_tzs')->default(0);
            $t->text('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_cost');
            $t->bigInteger('total');
            $t->timestamps();
        });

        Schema::table('creditor_allocations', function (Blueprint $t) {
            $t->foreignId('purchase_return_id')->nullable()->after('opening_owed_id')->constrained()->cascadeOnDelete();
        });

        // Goods going back to a supplier get their own movement type.
        if (DB::getDriverName() === 'pgsql') {
            $this->setStockTypes([...self::STOCK_TYPES, 'supplier_return']);
        }

        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => 'purchase_return', 'label' => 'Purchase return', 'prefix' => 'PRET', 'digits' => 4,
            'reset_yearly' => true, 'next_number' => 1, 'year' => (int) now()->format('Y'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $screen = Permission::firstOrCreate(
            ['name' => 'screens.purchases', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Purchases Screen']
        );
        $create = Permission::firstOrCreate(
            ['name' => 'purchases.create', 'guard_name' => 'web'],
            ['module' => 'procurement', 'label' => 'Add Purchases', 'description' => 'Add a purchase (starts the purchase-order approval chain) and record purchase returns']
        );
        foreach (Role::whereIn('name', self::ROLES)->get() as $role) {
            foreach ([$screen, $create] as $p) {
                if (! $role->hasPermissionTo($p)) {
                    $role->givePermissionTo($p);
                }
            }
        }
        $this->forget();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['screens.purchases', 'purchases.create'])->delete();
        DB::table('document_sequences')->where('document_type', 'purchase_return')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::table('stock_movements')->where('type', 'supplier_return')->update(['type' => 'adjustment']);
            $this->setStockTypes(self::STOCK_TYPES);
        }
        Schema::table('creditor_allocations', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_return_id'));
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        $this->forget();
    }

    private function setStockTypes(array $types): void
    {
        $list = implode(', ', array_map(fn ($t) => "'{$t}'::character varying", $types));
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_type_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK ((type)::text = ANY (ARRAY[{$list}]::text[]))");
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::whereIn('role', self::ROLES)->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
