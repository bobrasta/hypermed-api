<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLog;
use App\Models\ChartOfAccount;
use App\Models\CreditorOpeningBalance;
use App\Services\Creditors\CreditorLedgerService;
use App\Services\Creditors\CreditorLedgerXlsxService;
use App\Services\Creditors\CreditorService;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Creditors Ledger: suppliers and vendors Hypermed owes, their statements
 * (screen, Excel in the accounts team's layout, PDF), opening balances at
 * the cut-over, and a check that the ledgers add up to Trade Creditors.
 */
class CreditorController extends Controller
{
    public function __construct(private CreditorLedgerService $ledger, private CreditorService $creditors) {}

    private function assertRead(Request $r): void
    {
        abort_unless(SupplierPaymentController::canRead($r->user()), 403, 'You are not authorised to view creditors.');
    }

    public function index(Request $request)
    {
        $this->assertRead($request);
        $data = $request->validate([
            'as_at' => ['nullable', 'date'],
            'category' => ['nullable', Rule::in(['international', 'local'])],
            'q' => ['nullable', 'string', 'max:100'],
            'with_balance' => ['nullable', 'boolean'],
        ]);
        $asAt = $data['as_at'] ?? now()->toDateString();
        $rows = $this->ledger->balances($asAt, $data['category'] ?? null, $data['q'] ?? null);
        if ($request->boolean('with_balance')) {
            $rows = $rows->filter(fn ($r) => $r['lines'] > 0)->values();
        }

        return response()->json(['data' => [
            'as_at' => $asAt,
            'creditors' => $rows,
            'totals' => [
                'international_tzs' => $rows->where('category', 'international')->sum('book_balance_tzs'),
                'local_tzs' => $rows->where('category', 'local')->sum('book_balance_tzs'),
                'pending_payments' => $rows->sum('pending_payments'),
            ],
            'reconciliation' => $this->reconciliation(),
            'can_manage' => $request->user()->hasAccountantAuthority(),
        ]]);
    }

    /** Trade Creditors in the books vs. the sum of the ledgers (today). */
    private function reconciliation(): array
    {
        $all = $this->ledger->balances();
        $out = [];
        foreach (['international' => CreditorService::INTERNATIONAL, 'local' => CreditorService::LOCAL] as $cat => $code) {
            $books = (int) ChartOfAccount::where('code', $code)->value('balance');
            $ledgers = (int) $all->where('category', $cat)->sum('book_balance_tzs');
            $out[$cat] = ['account' => $code, 'books' => $books, 'ledgers' => $ledgers, 'difference' => $books - $ledgers];
        }

        return $out;
    }

    private function statementFor(Request $request, string $type, int $id): array
    {
        abort_unless(in_array($type, ['supplier', 'vendor'], true), 404);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->ledger->statement($type, $id, $data['from'] ?? null, $data['to'] ?? null);
    }

    public function statement(Request $request, string $type, int $id)
    {
        $this->assertRead($request);
        $s = $this->statementFor($request, $type, $id);
        $s['opening'] = CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $id)->first()?->only(['id', 'as_at', 'amount', 'exchange_rate', 'notes']);

        return response()->json(['data' => $s]);
    }

    public function statementXlsx(Request $request, string $type, int $id, CreditorLedgerXlsxService $xlsx)
    {
        $this->assertRead($request);
        $s = $this->statementFor($request, $type, $id);

        return $this->download($xlsx->build([$s]), CreditorLedgerXlsxService::filename($s['creditor']['name'], $s['period']['to']));
    }

    /** All creditors with activity: INTERNATIONAL and LOCAL sheets. */
    public function allXlsx(Request $request, CreditorLedgerXlsxService $xlsx)
    {
        $this->assertRead($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $statements = $this->ledger->creditors()
            ->map(fn ($c) => $this->ledger->statement($c['type'], $c['id'], $data['from'] ?? null, $data['to'] ?? null))
            ->filter(fn ($s) => $s['entries'] || abs($s['opening_balance']) > 0.004)
            ->values()->all();

        return $this->download($xlsx->build($statements), CreditorLedgerXlsxService::filename('All', $data['to'] ?? null));
    }

    public function statementPdf(Request $request, string $type, int $id, DocumentPdfService $pdf)
    {
        $this->assertRead($request);

        return $pdf->creditorStatementPdf($this->statementFor($request, $type, $id));
    }

    private function download(string $bytes, string $name)
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    // ─────────────────────────────────────────────────── opening balances

    /** Sets (or corrects) a creditor's opening balance; it posts to the books. */
    public function saveOpening(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->hasAccountantAuthority(), 403, 'You are not authorised to set opening balances.');
        $creditor = $this->ledger->find($type, $id);
        $currency = $creditor['currency'];
        $data = $request->validate([
            'as_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'exchange_rate' => [$currency === 'TZS' ? 'nullable' : 'required', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['exchange_rate.required' => "Enter the exchange rate on that date (TZS per {$currency})."]);
        $rate = $currency === 'TZS' ? 1 : (float) $data['exchange_rate'];
        $cents = CreditorService::cents($data['amount']);

        $opening = DB::transaction(function () use ($type, $id, $data, $rate, $cents, $currency, $request) {
            $existing = CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $id)->first();
            if ($existing) {
                $this->creditors->reverseOpening($existing);
            }
            $opening = CreditorOpeningBalance::updateOrCreate(
                ['creditor_type' => $type, 'creditor_id' => $id],
                ['as_at' => $data['as_at'], 'currency' => $currency, 'amount' => $cents / 100, 'exchange_rate' => $rate,
                    'amount_tzs' => CreditorService::tzs($cents, $rate), 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id],
            );
            $this->creditors->postOpening($opening);

            return $opening;
        });
        ApprovalLog::record($opening, $opening->wasRecentlyCreated ? 'created' : 'updated', $request->user());

        return response()->json(['data' => $this->ledger->statement($type, $id)], 201);
    }

    public function deleteOpening(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->hasAccountantAuthority(), 403, 'You are not authorised to change opening balances.');
        $opening = CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $id)->firstOrFail();
        DB::transaction(function () use ($opening) {
            $this->creditors->reverseOpening($opening);
            $opening->delete();
        });

        return response()->json(null, 204);
    }
}
