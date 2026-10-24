<?php

namespace App\Services;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;

/**
 * Loads a payroll run with everyone who has pay on record (2026-10-24):
 * one line per active staff member with an active contract and a basic
 * salary — base + recurring allowances, NSSF/PAYE from PayrollCalculator,
 * and the company-paid health insurance premium as an employer cost.
 * Lines already on the run are left as they are (they may have been edited).
 */
class PayrollStaffService
{
    /** @return int lines added */
    public function load(PayrollRun $run): int
    {
        $existing = $run->items()->pluck('user_id')->all();
        $added = 0;

        User::where('is_active', true)->whereNotIn('id', $existing)
            ->with('activeContract.allowances')->get()
            ->each(function (User $u) use ($run, &$added) {
                $line = self::lineFor($u);
                if ($line === null) {
                    return;
                }
                PayrollItem::create(['payroll_run_id' => $run->id, 'user_id' => $u->id] + $line);
                $added++;
            });

        return $added;
    }

    /** A staff member's computed line, or null without a salary on record. */
    public static function lineFor(User $u): ?array
    {
        $contract = $u->activeContract;
        if ($contract?->base_salary === null) {
            return null;
        }
        $base = (int) $contract->base_salary;
        $allowances = (int) $contract->allowances->where('recurring', true)->sum('amount');
        $gross = $base + $allowances;
        $nssf = PayrollCalculator::nssfEmployee($gross);
        $paye = PayrollCalculator::paye($gross, $nssf);

        return [
            'base_salary' => $base, 'allowances_total' => $allowances, 'overtime_amount' => 0,
            'paye_amount' => $paye, 'nssf_amount' => $nssf,
            'nssf_employer_amount' => PayrollCalculator::nssfEmployer($gross),
            'health_insurance_employer' => (int) ($contract->health_insurance_amount ?? 0),
            'heslb_amount' => 0, 'other_deductions' => 0,
            'gross_pay' => $gross, 'net_pay' => $gross - $paye - $nssf,
            'notes' => $contract->salary_placeholder ? 'Placeholder salary — set the real salary before paying' : null,
        ];
    }

    /** Lines on the run whose salary is still a placeholder. */
    public static function placeholderCount(PayrollRun $run): int
    {
        return $run->items()->whereHas('user.activeContract', fn ($q) => $q->where('salary_placeholder', true))->count();
    }

    /** Active staff with no salary on record — shown, so the gap is visible. */
    public static function withoutSalary(): array
    {
        return User::where('is_active', true)->with('activeContract')->orderBy('name')->get(['id', 'name', 'role'])
            ->filter(fn (User $u) => $u->activeContract?->base_salary === null)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role])->values()->all();
    }
}
