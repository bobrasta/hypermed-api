<?php

namespace App\Services;

// Tanzania Mainland statutory payroll figures — auto-calculated so nobody
// has to look up rates/bands by hand every payroll run. Confirmed current
// for 2026 via TRA-sourced guides (countrytaxcalc.com, anoorehr.com) as of
// 2026-09-18; re-verify if the Finance Act changes these (typically
// effective 1 July each year) before trusting them again next tax year.
class PayrollCalculator
{
    private const NSSF_RATE = 0.10;

    public static function nssfEmployee(int $grossPay): int
    {
        return (int) round($grossPay * self::NSSF_RATE);
    }

    public static function nssfEmployer(int $grossPay): int
    {
        return (int) round($grossPay * self::NSSF_RATE);
    }

    // PAYE applies to taxable income, not raw gross — NSSF's employee
    // contribution is deductible before PAYE is computed.
    public static function paye(int $grossPay, int $nssfEmployee): int
    {
        $taxable = max(0, $grossPay - $nssfEmployee);

        return match (true) {
            $taxable <= 270_000 => 0,
            $taxable <= 520_000 => (int) round(($taxable - 270_000) * 0.09),
            $taxable <= 760_000 => 22_500 + (int) round(($taxable - 520_000) * 0.20),
            $taxable <= 1_000_000 => 70_500 + (int) round(($taxable - 760_000) * 0.25),
            default => 130_500 + (int) round(($taxable - 1_000_000) * 0.30),
        };
    }
}
