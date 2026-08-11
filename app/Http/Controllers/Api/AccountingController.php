<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChartOfAccountResource;
use App\Models\AccountCategory;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use App\Services\PeriodCloseService;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    // Admin-only: sweep all Revenue/Expense account balances into Retained
    // Earnings. See PeriodCloseService for why the Balance Sheet doesn't
    // actually require this to have been run to be correct.
    public function closePeriod(Request $request, PeriodCloseService $service)
    {
        abort_if(! $request->user()->hasFinanceApprovalAuthority(), 403, 'You are not authorised to close an accounting period.');

        $result = $service->closeToRetainedEarnings();

        return response()->json(['data' => $result]);
    }

    // Active accounts grouped by category type, with subtotals and an all-time
    // (not period-scoped — see Finance E's Profit & Loss for that) net profit
    // figure, for a quick dashboard-style overview of the ledger.
    public function summary()
    {
        $categories = AccountCategory::with(['accounts' => fn ($q) => $q->where('status', 'active')->orderBy('code')])->get();

        $byType = $categories->mapWithKeys(fn ($cat) => [$cat->type => [
            'total'    => (int) $cat->accounts->sum('balance'),
            'accounts' => $cat->accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'balance' => $a->balance]),
        ]]);

        $revenue = (int) ($byType['revenue']['total'] ?? 0);
        $expense = (int) ($byType['expense']['total'] ?? 0);

        return response()->json(['data' => [
            'by_type'          => $byType,
            'quick_net_profit' => $revenue - $expense,
        ]]);
    }

    public function accounts(Request $request)
    {
        $accounts = ChartOfAccount::with('category')
            ->when($request->category, fn ($q, $c) => $q->whereHas('category', fn ($q) => $q->where('type', $c)))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('code')
            ->get();

        return ChartOfAccountResource::collection($accounts);
    }

    // Last 50 ledger postings, most recent first — a plain chronological journal view.
    public function journal(Request $request)
    {
        $entries = Transaction::with('account:id,code,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $entries->map(fn ($t) => [
            'id'           => $t->id,
            'account_code' => $t->account->code,
            'account_name' => $t->account->name,
            'type'         => $t->type,
            'amount'       => $t->amount,
            'description'  => $t->description,
            'reference'    => $t->reference,
            'created_at'   => $t->created_at?->toIso8601String(),
        ])]);
    }
}
