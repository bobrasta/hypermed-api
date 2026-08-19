<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PermissionSeeder::class,
            TestAccessControlSeeder::class,
            ChartOfAccountsSeeder::class,
            ExpenseCategorySeeder::class,
            HospitalSeeder::class,
            MachineSeeder::class,
            RealFacilityImportSeeder::class,
            SupplierSeeder::class,
            CategorySeeder::class,
            LocationSeeder::class,
            InventoryItemSeeder::class,
            StockLevelSeeder::class,
            SparePartSeeder::class,
            ServiceTicketSeeder::class,
            InvoiceSeeder::class,
            SalesLeadSeeder::class,
            ContactSeeder::class,
            NotificationSeeder::class,
            LicenseSeeder::class,
        ]);
    }
}
