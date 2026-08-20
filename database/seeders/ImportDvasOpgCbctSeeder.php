<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Machine;
use App\Models\ServiceTicket;
use Illuminate\Database\Seeder;

/**
 * Real DVAS-C dental X-ray, OPG (orthopantomograph) and CBCT (cone-beam CT)
 * installed base, sourced from the user's own updated asset register --
 * 38 machines across 37 hospitals. 10 of those
 * machines attach to hospitals RealFacilityImportSeeder already created (same
 * real place, matched by name + region); the rest are new hospitals not
 * covered by that import.
 *
 * Also derives ServiceTicket history from the register's own record:
 * completed preventive-maintenance visits (only where the service date has
 * actually passed -- future-scheduled ones aren't ticketed since they
 * haven't happened yet), and resolved/ongoing fault repairs. This is real
 * service history, not sample/demo data.
 *
 * Fully idempotent row-by-row (Hospital/Machine via firstOrCreate, tickets
 * rebuilt only for a machine whose ticket count doesn't match what it
 * should have) rather than one top-level guard -- safe to re-run, retry, or
 * leave in the deploy chain permanently; a partial failure on one row can
 * never wipe or duplicate data belonging to another.
 */
class ImportDvasOpgCbctSeeder extends Seeder
{
    public function run(): void
    {
        $newHospitals = [
            [
                'name' => 'Kaliua DH', 'short_code' => 'KADH7', 'type' => 'public',
                'region' => 'Tabora', 'district' => null, 'zone' => 'central',
                'latitude' => -5.059465, 'longitude' => 31.792861,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Dr. Jakaya Hosp. Kishapu', 'short_code' => 'DRJAH', 'type' => 'public',
                'region' => 'Shinyanga', 'district' => 'Kishapu', 'zone' => 'lake',
                'latitude' => -3.6827822, 'longitude' => 33.8177131,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Shinyanga RRH', 'short_code' => 'SHRR', 'type' => 'public',
                'region' => 'Shinyanga', 'district' => null, 'zone' => 'lake',
                'latitude' => -3.7622225, 'longitude' => 33.2320361,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Makongoro HC', 'short_code' => 'MAHC8', 'type' => 'public',
                'region' => 'Mwanza', 'district' => null, 'zone' => 'lake',
                'latitude' => -2.5099567, 'longitude' => 32.9002508,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Sokoine HC', 'short_code' => 'SOHC3', 'type' => 'public',
                'region' => 'Singida', 'district' => null, 'zone' => 'central',
                'latitude' => -5.2902148, 'longitude' => 34.6367444,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Shinyanga MC', 'short_code' => 'SHMC', 'type' => 'public',
                'region' => 'Shinyanga', 'district' => null, 'zone' => 'lake',
                'latitude' => -3.7622225, 'longitude' => 33.2320361,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Amana RRH', 'short_code' => 'AMRR', 'type' => 'public',
                'region' => 'Dar Es Salaam', 'district' => null, 'zone' => 'coastal',
                'latitude' => -6.8160837, 'longitude' => 39.2803583,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Nshambya DH', 'short_code' => 'NSDH2', 'type' => 'public',
                'region' => 'Kagera', 'district' => 'Bukoba', 'zone' => 'lake',
                'latitude' => -1.3071541, 'longitude' => 31.8099237,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mugana Hosp.', 'short_code' => 'MUHO', 'type' => 'public',
                'region' => 'Kagera', 'district' => 'Bukoba', 'zone' => 'lake',
                'latitude' => -1.3311508, 'longitude' => 31.8125605,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Lindi RRH', 'short_code' => 'LIRR', 'type' => 'public',
                'region' => 'Lindi', 'district' => null, 'zone' => 'southern',
                'latitude' => -9.3985098, 'longitude' => 37.8997467,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Bumbuli DH', 'short_code' => 'BUDH8', 'type' => 'public',
                'region' => 'Tanga', 'district' => 'Lushoto', 'zone' => 'coastal',
                'latitude' => -4.4952844, 'longitude' => 38.4480235,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mafia DH', 'short_code' => 'MADH6', 'type' => 'public',
                'region' => 'Pwani', 'district' => 'Mafia', 'zone' => 'coastal',
                'latitude' => -7.8166985, 'longitude' => 39.8080259,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Kaloleni HC', 'short_code' => 'KAHC8', 'type' => 'public',
                'region' => 'Arusha', 'district' => null, 'zone' => 'northern',
                'latitude' => -3.3665057, 'longitude' => 36.6890925,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mkomaindo DH', 'short_code' => 'MKDH4', 'type' => 'public',
                'region' => 'Mtwara', 'district' => null, 'zone' => 'southern',
                'latitude' => -10.7310331, 'longitude' => 38.7962486,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mbuba DH', 'short_code' => 'MBDH4', 'type' => 'public',
                'region' => 'Kagera', 'district' => 'Ngara', 'zone' => 'lake',
                'latitude' => -2.7252664, 'longitude' => 30.5935542,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Kilosa DH', 'short_code' => 'KIDH10', 'type' => 'public',
                'region' => 'Morogoro', 'district' => null, 'zone' => 'coastal',
                'latitude' => -6.8363175, 'longitude' => 36.985732,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Ludewa DH', 'short_code' => 'LUDH', 'type' => 'public',
                'region' => 'Njombe', 'district' => null, 'zone' => 'shighland',
                'latitude' => -10.1066532, 'longitude' => 34.6923137,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Makuyuni DH', 'short_code' => 'MADH7', 'type' => 'public',
                'region' => 'Tanga', 'district' => 'Korogwe', 'zone' => 'coastal',
                'latitude' => -5.0168812, 'longitude' => 38.3285107,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Muhimbili National Hosp.', 'short_code' => 'MUNAH', 'type' => 'public',
                'region' => 'Dar Es Salaam', 'district' => null, 'zone' => 'coastal',
                'latitude' => -6.8056066, 'longitude' => 39.2729341,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mt.Meru RRH', 'short_code' => 'MTRR', 'type' => 'public',
                'region' => 'Arusha', 'district' => null, 'zone' => 'northern',
                'latitude' => -3.3696827, 'longitude' => 36.6880794,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Mawenzi RRH', 'short_code' => 'MARR2', 'type' => 'public',
                'region' => 'Kilimanjaro', 'district' => null, 'zone' => 'northern',
                'latitude' => -3.0786534, 'longitude' => 37.4198556,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Lugalo Millitary Hosp', 'short_code' => 'LUMIH', 'type' => 'public',
                'region' => 'Dar Es Salaam', 'district' => null, 'zone' => 'coastal',
                'latitude' => -6.8160837, 'longitude' => 39.2803583,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Dodoma General/RRH', 'short_code' => 'DOGE', 'type' => 'public',
                'region' => 'Dodoma', 'district' => null, 'zone' => 'central',
                'latitude' => -6.1791181, 'longitude' => 35.7468174,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Singida RRH', 'short_code' => 'SIRR', 'type' => 'public',
                'region' => 'Singida', 'district' => null, 'zone' => 'central',
                'latitude' => -5.2902148, 'longitude' => 34.6367444,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Temeke RRH', 'short_code' => 'TERR', 'type' => 'public',
                'region' => 'Dar Es Salaam', 'district' => null, 'zone' => 'coastal',
                'latitude' => -6.8160837, 'longitude' => 39.2803583,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Chato RRH', 'short_code' => 'CHRR', 'type' => 'public',
                'region' => 'Geita', 'district' => null, 'zone' => 'lake',
                'latitude' => -3.1865265, 'longitude' => 32.0855702,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Iringa RRH', 'short_code' => 'IRRR', 'type' => 'public',
                'region' => 'Iringa', 'district' => null, 'zone' => 'shighland',
                'latitude' => -7.7742718, 'longitude' => 35.4826339,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
            [
                'name' => 'Kitete RRH', 'short_code' => 'KIRR', 'type' => 'public',
                'region' => 'Tabora', 'district' => null, 'zone' => 'central',
                'latitude' => -5.1913822, 'longitude' => 32.5080629,
                'machine_count' => 0, 'machines_operational' => 0,
            ],
        ];
        foreach ($newHospitals as $h) {
            Hospital::firstOrCreate(['short_code' => $h['short_code']], $h);
        }

        $hospitalIds = Hospital::whereIn('short_code', [
            'KADH7',
            'DRJAH',
            'SHRR',
            'MAHC8',
            'SOHC3',
            'SHMC',
            'AMRR',
            'NSDH2',
            'MUHO',
            'LIRR',
            'BUDH8',
            'MADH6',
            'KAHC8',
            'MKDH4',
            'MBDH4',
            'KIDH10',
            'LUDH',
            'MADH7',
            'MUNAH',
            'MTRR',
            'MARR2',
            'LUMIH',
            'DOGE',
            'SIRR',
            'TERR',
            'CHRR',
            'IRRR',
            'KIRR',
            'BUDH3',
            'IGDH2',
            'KADH4',
            'MBDH3',
            'MODH2',
            'MUDH',
            'RODH',
            'TADH',
            'UHHO',
        ])->pluck('id', 'short_code');

        $machineDefs = [
            [
                'short_code' => 'KADH7', 'serial_no' => 'GDS-322202-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5F27760(PO4-8211195-025). Defective unit removed: 3M17418(P04-3070014-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'DRJAH', 'serial_no' => 'GDS-308212-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 4A18217(PO4-3280222-024). Defective unit removed: 3M17494(P04-3070012-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'SHRR', 'serial_no' => 'GDS-C07207-90223',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 7J57809(P04-6131939-025). Defective unit removed: 3K17053(P04- C070007-23).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'MAHC8', 'serial_no' => 'GDS-308214-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 0D80376(P04-6131936-025). Defective unit removed: 0M98324(P04-3110006-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'SOHC3', 'serial_no' => 'GDS-126206-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5C25663(P04-8141150-025). Defective unit removed: 3M17335(P04-1260006-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'SHMC', 'serial_no' => 'GDS-C07209-90223',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 0D81593(P04-6131938-025). Defective unit removed: 3k16041(P04-C070009-23).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'AMRR', 'serial_no' => 'GDS-405219-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5D26259(P04-8211200-025). Defective unit removed: 4A17680(P04-3110099-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'NSDH2', 'serial_no' => 'GDS-305204-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 3K12420(P04-6131937-025). Defective unit removed: 3M17438(P04-3050004-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'MUHO', 'serial_no' => 'GDS-308204-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5D26347(P04-8211192-025). Defective unit removed: 3M17397(P04-3070004-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'LIRR', 'serial_no' => 'GDS-322203-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5D26051(P04-8211190-025). Defective unit removed: 3M17501(P04-3110005-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'BUDH8', 'serial_no' => 'GDS-308219-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'operational', 'install_date' => null,
                'tickets' => [['status' => 'resolved', 'description' => 'Head Assey tube replaced ..', 'resolution_notes' => 'Head assembly tube replaced. New unit: 5F28618(P04-8211183-025). Defective unit removed: 3M17375(P04-3070019-24).', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'RODH', 'serial_no' => 'GDS-419206-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'down', 'install_date' => null,
                'tickets' => [['status' => 'in_progress', 'description' => 'Machine not powering on.', 'resolution_notes' => null, 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'MADH6', 'serial_no' => 'GDS-322213-90224',
                'model' => 'DVAS-C Dental X-Ray Unit', 'type' => 'DVAS-C Dental X-Ray',
                'status' => 'down', 'install_date' => null,
                'tickets' => [['status' => 'in_progress', 'description' => 'Oil leakage on Head Assey tube ..', 'resolution_notes' => null, 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'KAHC8', 'serial_no' => 'GDP-A01002-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-03-20',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-03-20', 'created_at' => '2026-03-20']],
            ],
            [
                'short_code' => 'TADH', 'serial_no' => 'GDP-517002-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-04-18',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-04-18', 'created_at' => '2026-04-18']],
            ],
            [
                'short_code' => 'UHHO', 'serial_no' => 'GDP-520003-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-06-25',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-06-25', 'created_at' => '2026-06-25']],
            ],
            [
                'short_code' => 'MUDH', 'serial_no' => 'GDP-A08002-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-08-07',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-08-07', 'created_at' => '2026-08-07']],
            ],
            [
                'short_code' => 'IGDH2', 'serial_no' => 'GDP-A08005-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-09-27',
                'tickets' => [],
            ],
            [
                'short_code' => 'MKDH4', 'serial_no' => 'GDP-520006-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2025-12-18',
                'tickets' => [],
            ],
            [
                'short_code' => 'MBDH4', 'serial_no' => 'GDP-520001-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-01-17',
                'tickets' => [],
            ],
            [
                'short_code' => 'KIDH10', 'serial_no' => 'GDP-520007-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-01-24',
                'tickets' => [],
            ],
            [
                'short_code' => 'MODH2', 'serial_no' => 'GDP-517001-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-02-05',
                'tickets' => [['status' => 'resolved', 'description' => 'Graphics card error & power supply fault ,both replaced issue resolved..', 'resolution_notes' => 'Fault resolved on site.', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'RODH', 'serial_no' => 'GDP-517008-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-02-08',
                'tickets' => [],
            ],
            [
                'short_code' => 'BUDH3', 'serial_no' => 'GDP-A07001-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-04-09',
                'tickets' => [],
            ],
            [
                'short_code' => 'KADH4', 'serial_no' => 'GDP-A01001-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'needs_service', 'install_date' => '2026-04-10',
                'tickets' => [['status' => 'in_progress', 'description' => 'Gantry movement error,under repair progress', 'resolution_notes' => null, 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'MBDH3', 'serial_no' => 'GDP-517003-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-05-22',
                'tickets' => [],
            ],
            [
                'short_code' => 'LUDH', 'serial_no' => 'GDP-A02007-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'idle', 'install_date' => '2026-05-27',
                'tickets' => [],
            ],
            [
                'short_code' => 'MADH7', 'serial_no' => 'GDP-A08004-40424',
                'model' => 'OPG Machine (Orthopantomograph)', 'type' => 'OPG Machine',
                'status' => 'operational', 'install_date' => '2026-06-24',
                'tickets' => [],
            ],
            [
                'short_code' => 'MUNAH', 'serial_no' => 'GCT-911008-70423',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2024-03-06',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2025-03-06', 'created_at' => '2025-03-06'], ['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-03-06', 'created_at' => '2026-03-06']],
            ],
            [
                'short_code' => 'MTRR', 'serial_no' => 'GCT-830020-70523',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2024-06-18',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2025-06-18', 'created_at' => '2025-06-18'], ['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-06-18', 'created_at' => '2026-06-18']],
            ],
            [
                'short_code' => 'MARR2', 'serial_no' => 'GCT-418003-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-01-09',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-01-09', 'created_at' => '2026-01-09'], ['status' => 'resolved', 'description' => 'Error on machine Software,Software reinstalled issue resolved', 'resolution_notes' => 'Fault resolved on site.', 'resolved_at' => null, 'created_at' => null]],
            ],
            [
                'short_code' => 'LUMIH', 'serial_no' => 'GCT-419004-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-01-14',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-05-05', 'created_at' => '2026-05-05']],
            ],
            [
                'short_code' => 'DOGE', 'serial_no' => 'GCT-418005-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-02-17',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-04-03', 'created_at' => '2026-04-03']],
            ],
            [
                'short_code' => 'SIRR', 'serial_no' => 'GCT-419002-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-07-11',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-03-11', 'created_at' => '2026-03-11']],
            ],
            [
                'short_code' => 'TERR', 'serial_no' => 'GCT-4190003-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-08-19',
                'tickets' => [['status' => 'resolved', 'description' => 'Scheduled preventive maintenance service', 'resolution_notes' => 'No issues found during service.', 'resolved_at' => '2026-08-19', 'created_at' => '2026-08-19']],
            ],
            [
                'short_code' => 'CHRR', 'serial_no' => 'GCT-418001-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-12-15',
                'tickets' => [],
            ],
            [
                'short_code' => 'IRRR', 'serial_no' => 'GCT-422003-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2025-12-24',
                'tickets' => [],
            ],
            [
                'short_code' => 'KIRR', 'serial_no' => 'GCT-422002-70524',
                'model' => 'CBCT Machine (Cone Beam CT)', 'type' => 'CBCT Machine',
                'status' => 'operational', 'install_date' => '2026-01-16',
                'tickets' => [['status' => 'resolved', 'description' => 'Fault on gantry movements, rotating belts tightened ,issue resolved.', 'resolution_notes' => 'Fault resolved on site.', 'resolved_at' => null, 'created_at' => null]],
            ],
        ];

        $touchedHospitalIds = [];
        foreach ($machineDefs as $def) {
            $hospitalId = $hospitalIds[$def['short_code']];
            $touchedHospitalIds[] = $hospitalId;
            $machine = Machine::firstOrCreate(
                ['serial_no' => $def['serial_no']],
                [
                    'hospital_id' => $hospitalId, 'model' => $def['model'], 'type' => $def['type'],
                    'status' => $def['status'], 'install_date' => $def['install_date'],
                    'revenue_per_month' => 0,
                ]
            );

            // Idempotent per machine: if its ticket count doesn't match what this
            // machine should have, clear just its own tickets and rebuild them --
            // scoped to one machine, never a wholesale table wipe, so a retry or
            // re-deploy can never lose tickets belonging to other machines.
            $expected = count($def['tickets']);
            if (ServiceTicket::where('machine_id', $machine->id)->count() !== $expected) {
                ServiceTicket::where('machine_id', $machine->id)->delete();
                foreach ($def['tickets'] as $t) {
                    $lastNum = (int) ltrim(ServiceTicket::query()->max('ticket_number') ?? '#999', '#');
                    $createdAt = ($t['created_at'] ?? now()->toDateString()) . ' 09:00:00';
                    ServiceTicket::create([
                        'ticket_number'    => '#' . ($lastNum + 1),
                        'machine_id'       => $machine->id,
                        'hospital_id'      => $hospitalId,
                        'status'           => $t['status'],
                        'description'      => $t['description'],
                        'resolution_notes' => $t['resolution_notes'],
                        'resolved_at'      => $t['resolved_at'] ? $t['resolved_at'] . ' 12:00:00' : null,
                        'created_at'       => $createdAt,
                        'updated_at'       => $createdAt,
                    ]);
                }
            }
        }

        foreach (array_unique($touchedHospitalIds) as $hid) {
            Hospital::whereKey($hid)->update([
                'machine_count'        => Machine::where('hospital_id', $hid)->count(),
                'machines_operational' => Machine::where('hospital_id', $hid)->where('status', 'operational')->count(),
            ]);
        }
    }
}
