<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Real Hypermed staff accounts + org structure, from "proposed accounts.md"
 * and the org chart supplied on 2026-09-28. Runs once (settings flag).
 *
 * Reporting lines: Director → CTO, Administrative Wing, Sales, Accounts &
 * Finance, Legal; CTO → Service (team leader Tito → technicians),
 * Application, Tender & Procurement (head Patrick), Microbiology (lead Miraj
 * → Nicolaus, Moses Rogers), Radiography (Jackline), IT; Admin Wing →
 * Warehouse & Logistics, Facilities & Support Services.
 *
 * New accounts start with the app's standard invite password (Hypermed@123,
 * same as StaffController::store) and must change it on first login.
 */
class RealStaffOrgSeeder extends Seeder
{
    private const DONE_FLAG = 'real_staff_org_seeded_2026_09_28';

    public function run(): void
    {
        if (Setting::get(self::DONE_FLAG)) {
            return;
        }

        DB::transaction(function () {
            $now = now();

            // ── Positions (title => department) ─────────────────────────────
            $positions = [
                'Director' => 'Executive',
                'Chief Technical Officer' => 'Executive',
                'System Administrator' => 'IT',
                'IT Technician' => 'IT',
                'Biomedical Engineer' => 'Service',
                'Field Team Leader' => 'Service',
                'Radiography Specialist' => 'Radiography',
                'Microbiology Lead' => 'Microbiology',
                'Laboratory Technician' => 'Microbiology',
                'Head of Tender & Procurement' => 'Tender & Procurement',
                'Tender & Procurement Officer' => 'Tender & Procurement',
                'Accountant' => 'Accounts & Finance',
                'Sales Manager' => 'Sales',
                'Sales Officer' => 'Sales',
                'Company Driver' => 'Warehouse & Logistics',
                'Administrative Officer' => 'Administrative Wing',
            ];
            $posId = [];
            foreach ($positions as $title => $dept) {
                $row = DB::table('positions')->where('title', $title)->first();
                $posId[$title] = $row?->id ?? DB::table('positions')->insertGetId([
                    'title' => $title, 'department' => $dept, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // ── People: email => [name, role, position, group, reports-to email, note] ──
            $people = [
                'moseskiduduye@hypermed.co.tz'    => ['Moses Kiduduye', 'admin', 'Director', 'admin', null],
                'bobtem@hypermed.co.tz'           => ['Bob Tem', 'super_admin', 'System Administrator', 'admin', 'moseskiduduye@hypermed.co.tz'],
                'sharifrajabu@hypermed.co.tz'     => ['Sharif Rajabu', 'cto', 'Chief Technical Officer', 'office', 'moseskiduduye@hypermed.co.tz'],
                'support@hypermed.co.tz'          => ['Administration', 'Sales and Administration', 'Administrative Officer', 'office', 'moseskiduduye@hypermed.co.tz'],
                'gabrielbenedict@hypermed.co.tz'  => ['Gabriel Benedict', 'sales_manager', 'Sales Manager', 'office', 'moseskiduduye@hypermed.co.tz'],
                'florian.mwaisumbe@hypermed.co.tz'=> ['Florian Mwaisumbe', 'sales', 'Sales Officer', 'office', 'gabrielbenedict@hypermed.co.tz'],
                'atpmwaika@hypermed.co.tz'        => ['Atp Mwaika', 'accountant', 'Accountant', 'office', 'moseskiduduye@hypermed.co.tz'],
                'rashidsaid@hypermed.co.tz'       => ['Rashid Said', 'logistics', 'Company Driver', 'field', 'moseskiduduye@hypermed.co.tz'],
                'titoyusuph@hypermed.co.tz'       => ['Tito Yusuph', 'team_leader', 'Field Team Leader', 'field', 'sharifrajabu@hypermed.co.tz'],
                'allenmissana@hypermed.co.tz'     => ['Allen Missana', 'technician', 'IT Technician', 'office', 'sharifrajabu@hypermed.co.tz'],
                'neville.temu@hypermed.co.tz'     => ['Neville Temu', 'technician', 'IT Technician', 'office', 'sharifrajabu@hypermed.co.tz'],
                'patrick.umbe@hypermed.co.tz'     => ['Patrick Umbe', 'procurement_manager', 'Head of Tender & Procurement', 'office', 'sharifrajabu@hypermed.co.tz'],
                'info@hypermed.co.tz'             => ['Tender & Procurement (Info)', 'procurement_manager', 'Tender & Procurement Officer', 'office', 'patrick.umbe@hypermed.co.tz'],
                'dianafrancis@hypermed.co.tz'     => ['Diana Francis', 'procurement_manager', 'Tender & Procurement Officer', 'office', 'patrick.umbe@hypermed.co.tz'],
                'ishimwe@hypermed.co.tz'          => ['Ishimwe', 'procurement_manager', 'Tender & Procurement Officer', 'office', 'patrick.umbe@hypermed.co.tz'],
                'kihinjajacque@hypermed.co.tz'    => ['Jackline Kihinja', 'technician', 'Radiography Specialist', 'field', 'sharifrajabu@hypermed.co.tz'],
                'mirajmtwaenzi@hypermed.co.tz'    => ['Miraj Mtwaenzi (Mzee Hussein)', 'team_leader', 'Microbiology Lead', 'field', 'sharifrajabu@hypermed.co.tz'],
                'nicolauspeter@hypermed.co.tz'    => ['Nicolaus Peter', 'technician', 'Laboratory Technician', 'field', 'mirajmtwaenzi@hypermed.co.tz'],
                'moses.rogers@hypermed.co.tz'     => ['Moses Rogers', 'technician', 'Laboratory Technician', 'field', 'mirajmtwaenzi@hypermed.co.tz'],
                'alhaji.k@hypermed.co.tz'         => ['Alhaji K.', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'amanbrayson0@hypermed.co.tz'     => ['Aman Brayson', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'denismarandu@hypermed.co.tz'     => ['Denis Marandu', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'emmandetelwa@hypermed.co.tz'     => ['Emman Detelwa', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz', 'Contract engineer (not a full-time employee).'],
                'innocentmichael@hypermed.co.tz'  => ['Innocent Michael', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'jacksonjanuary@hypermed.co.tz'   => ['Jackson January', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'jacksonmengi@hypermed.co.tz'     => ['Jackson Mengi', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'karimrajabu@hypermed.co.tz'      => ['Karim Rajabu', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'modester.dome@hypermed.co.tz'    => ['Modester Dome', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
                'yonahleonard@hypermed.co.tz'     => ['Yonah Leonard', 'technician', 'Biomedical Engineer', 'field', 'titoyusuph@hypermed.co.tz'],
            ];

            $users = [];
            foreach ($people as $email => $p) {
                [$name, $role, $position, $group] = $p;
                $note = $p[5] ?? null;
                $user = User::where('email', $email)->first();
                $attrs = [
                    'role' => $role,
                    'position_id' => $posId[$position],
                    'staff_group' => $group,
                    'is_active' => true,
                ];
                if ($user) {
                    $user->update($attrs); // keep existing name/password
                } else {
                    $user = User::create($attrs + [
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make('Hypermed@123'),
                        'avail_status' => 'Available',
                        'bio' => $note,
                    ]);
                }
                $user->syncRoles([$role]);
                $users[$email] = $user;
            }

            foreach ($people as $email => $p) {
                $managerEmail = $p[4];
                $users[$email]->update(['manager_id' => $managerEmail ? $users[$managerEmail]->id : null]);
            }

            // ── Departments (chart units) with their heads ──────────────────
            $departments = [
                'Administrative Wing' => 'support@hypermed.co.tz',
                'Sales' => 'gabrielbenedict@hypermed.co.tz',
                'Accounts & Finance' => 'atpmwaika@hypermed.co.tz',
                'Legal' => null,
                'Service' => 'titoyusuph@hypermed.co.tz',
                'Application' => null,
                'Warehouse & Logistics' => null,
                'Facilities & Support Services' => null,
                'Tender & Procurement' => 'patrick.umbe@hypermed.co.tz',
                'Microbiology' => 'mirajmtwaenzi@hypermed.co.tz',
                'Radiography' => 'kihinjajacque@hypermed.co.tz',
                'IT' => null,
            ];
            foreach ($departments as $name => $head) {
                $managerId = $head ? $users[$head]->id : null;
                $row = DB::table('departments')->where('name', $name)->first();
                if ($row) {
                    DB::table('departments')->where('id', $row->id)->update(['manager_id' => $managerId, 'updated_at' => $now]);
                } else {
                    DB::table('departments')->insert(['name' => $name, 'manager_id' => $managerId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            $resolver = app(EffectivePermissionResolver::class);
            foreach ($users as $u) {
                $resolver->invalidate($u);
            }

            Setting::set(self::DONE_FLAG, now()->toIso8601String());
        });
    }
}
