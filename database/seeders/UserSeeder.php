<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Admin User',        'email' => 'admin@hypermed.tz',    'role' => 'admin',      'phone' => '+255 712 000 001', 'region' => 'Dar es Salaam', 'avatar_initials' => 'AU', 'avail_status' => 'At desk'],
            ['name' => 'James Mollel',       'email' => 'james@hypermed.tz',    'role' => 'technician', 'phone' => '+255 754 123 456', 'region' => 'Arusha',        'avatar_initials' => 'JM', 'avail_status' => 'Available'],
            ['name' => 'Grace Kimaro',       'email' => 'grace@hypermed.tz',    'role' => 'technician', 'phone' => '+255 713 234 567', 'region' => 'Mwanza',        'avatar_initials' => 'GK', 'avail_status' => 'On task'],
            ['name' => 'Peter Mwangi',       'email' => 'peter@hypermed.tz',    'role' => 'technician', 'phone' => '+255 765 345 678', 'region' => 'Dodoma',        'avatar_initials' => 'PM', 'avail_status' => 'Assigned'],
            ['name' => 'Amina Rashid',       'email' => 'amina@hypermed.tz',    'role' => 'sales',      'phone' => '+255 784 456 789', 'region' => 'Dar es Salaam', 'avatar_initials' => 'AR', 'avail_status' => 'At desk'],
            ['name' => 'Bernard Lyimo',      'email' => 'bernard@hypermed.tz',  'role' => 'finance',    'phone' => '+255 719 567 890', 'region' => 'Dar es Salaam', 'avatar_initials' => 'BL', 'avail_status' => 'At desk'],
            ['name' => 'Fatuma Hassan',      'email' => 'fatuma@hypermed.tz',   'role' => 'cs',         'phone' => '+255 753 678 901', 'region' => 'Dar es Salaam', 'avatar_initials' => 'FH', 'avail_status' => 'At desk'],
            ['name' => 'Emmanuel Shayo',     'email' => 'emmanuel@hypermed.tz', 'role' => 'technician', 'phone' => '+255 774 789 012', 'region' => 'Mbeya',         'avatar_initials' => 'ES', 'avail_status' => 'Available'],
        ];

        foreach ($users as $u) {
            User::create(array_merge($u, ['password' => Hash::make('password'), 'is_active' => true]));
        }
    }
}
