<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * One-time cleanup: removes every hospital that is NOT part of the real
 * facility import (RealFacilityImportSeeder) -- the original demo/sample
 * hospitals from HospitalSeeder plus any ad-hoc test hospitals created
 * through the app during the testing phase. Deleting a Hospital cascades
 * (DB-level ON DELETE CASCADE) to its machines, service tickets, invoices
 * and contacts; sales_leads/sales_orders referencing it just lose the link
 * (hospital_id set null) rather than being deleted, since those aren't
 * hospital-scoped records.
 *
 * Found 2026-09-22 while adding ImportNationalFacilityRegistrySeeder:
 * REAL_CODES had silently drifted stale -- 27 genuinely real hospitals
 * added since this list was last updated (Amana/Chato/Dodoma/Iringa/
 * Kitete/Lindi/Mt.Meru/Shinyanga/Singida/Temeke RRH and others, several
 * with real machines/tickets/invoices attached) were NOT in it, so the
 * next deploy would have deleted all of them, cascading to their data.
 * This was a live, undetected bug -- the old "no-ops once no non-real
 * hospital remains" guard only holds if REAL_CODES is exhaustive, and
 * every hospital added by any means other than editing this list directly
 * (the UI, another seeder, a migration) silently broke that invariant.
 *
 * Fixed at the root instead of just patching REAL_CODES again: this now
 * runs its deletion logic at most once, ever, via a `hospitals_demo_data_removed`
 * Setting row. Once it has run successfully, it never re-evaluates "is
 * this row real" again -- so a future real hospital (via the UI,
 * ImportNationalFacilityRegistrySeeder's ~13,600 rows, or anything else)
 * can never be caught by a stale whitelist again. REAL_CODES itself is
 * left as-is (harmless once this only fires once) rather than expanded,
 * since expanding it would just recreate the same fragility next time
 * something new gets added.
 */
class RemoveDemoDataSeeder extends Seeder
{
    private const DONE_FLAG = 'hospitals_demo_data_removed';

    /** short_codes produced by RealFacilityImportSeeder -- everything else is demo/test data. */
    private const REAL_CODES = [
        'ARTH', 'BADH', 'BADH2', 'BADH3', 'BADH4', 'BIHC', 'BU', 'BUDC',
        'BUDH', 'BUDH2', 'BUDH3', 'BUDH4', 'BUDH5', 'BUDH6', 'BUDH7', 'BUHC',
        'BUHC2', 'BUHC3', 'BWHC', 'BWHC2', 'CHDH', 'CHDH2', 'CHDH3', 'CHHC',
        'DOHC', 'DOJI', 'FRDH', 'FUHC', 'GADH', 'GETC', 'HADH', 'HADH2',
        'HEHC', 'HIHC', 'HIHC2', 'HOHC', 'IDHC', 'IGDH', 'IGDH2', 'IGHC',
        'IHHC', 'IKDH', 'IKHC', 'ILDH', 'ILHC', 'ILHC2', 'INBH', 'INDH',
        'IPHC', 'IRDH', 'IRDH2', 'ISDH', 'ISHC', 'ITDH', 'ITDH2', 'IYHC',
        'KADH', 'KADH2', 'KADH3', 'KADH4', 'KADH5', 'KADH6', 'KAHC', 'KAHC2',
        'KAHC3', 'KAHC4', 'KAHC5', 'KAHC6', 'KAHC7', 'KC', 'KEHC', 'KEHC2',
        'KI', 'KIDH', 'KIDH2', 'KIDH3', 'KIDH4', 'KIDH5', 'KIDH6', 'KIDH7',
        'KIDH8', 'KIDH9', 'KIHC', 'KIHC2', 'KIHC3', 'KIHC4', 'KIHC5', 'KIHC6',
        'KIHC7', 'KODH', 'KOHC', 'KOTH', 'KWDH', 'KYDH', 'LEHC', 'LIDH',
        'LIDH2', 'LIHC', 'LODH', 'LOHC', 'MADH', 'MADH2', 'MADH3', 'MADH4',
        'MADH5', 'MAHC', 'MAHC2', 'MAHC3', 'MAHC4', 'MAHC5', 'MAHC6', 'MAHC7',
        'MAHO', 'MARR', 'MATH', 'MATH2', 'MBDH', 'MBDH2', 'MBDH3', 'MBHC',
        'MBTH', 'MEDH', 'MEDH2', 'MH', 'MHDI', 'MIDH', 'MIHC', 'MIHC2',
        'MIHC3', 'MJMWH', 'MKDH', 'MKDH2', 'MKDH3', 'MKHC', 'MKHC2', 'MLDH',
        'MLDH2', 'MLDH3', 'MNMMH', 'MO', 'MODH', 'MODH2', 'MPDH', 'MPMC',
        'MPMH', 'MSDH', 'MSHC', 'MTDH', 'MTHC', 'MTWAM', 'MUDC', 'MUDH',
        'MUHC', 'MVDH', 'MWDH', 'MWHC', 'MWHC2', 'MWPO', 'NADH', 'NADH2',
        'NADH3', 'NETC', 'NGHC', 'NGHC2', 'NJDH', 'NJHC', 'NKDH', 'NKHC',
        'NSDH', 'NYDC', 'NYDD', 'NYDH', 'NYDH2', 'NYDH3', 'NYHC', 'NYHC2',
        'NZDH', 'NZDH2', 'NZHC', 'NZTC', 'NZTH', 'OLDH', 'OLHC', 'PAHC',
        'PUHC', 'RODH', 'RODH2', 'SADD', 'SADH', 'SADH2', 'SAHC', 'SEDH',
        'SEDH2', 'SEHC', 'SHDH', 'SIDH', 'SIDH2', 'SIDH3', 'SIDH4', 'SIDH5',
        'SIHC', 'SIMC', 'SIMH', 'SODH', 'SOHC', 'SOHC2', 'SOMH', 'TADH',
        'TADH2', 'TAMH', 'TUHC', 'TUHC2', 'TUTH', 'UBDH', 'UHHO', 'UJMH',
        'URDH', 'URDH2', 'URKYH', 'USDH', 'UTDH', 'UTDH2', 'UVDH', 'UYDH',
        'VIHC', 'VWDH', 'VWDH2', 'WADH', 'WAHC', 'YOVIH', 'ZAHC',
    ];

    public function run(): void
    {
        if (Setting::get(self::DONE_FLAG)) {
            return;
        }

        $demo = Hospital::whereNotIn('short_code', self::REAL_CODES)->get();

        foreach ($demo as $hospital) {
            $hospital->delete();
        }

        Setting::set(self::DONE_FLAG, '1');
    }
}
