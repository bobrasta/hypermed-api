<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            HospitalSeeder::class,
            MachineSeeder::class,
            SupplierSeeder::class,
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
