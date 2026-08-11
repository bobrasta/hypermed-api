<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankReconciliationResource;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;

class BankReconciliationController extends Controller
{
    public function index()
    {
        $recons = BankReconciliation::latest('period_from')->get();
        return BankReconciliationResource::collection($recons);
    }

    public function store(Request $request, BankReconciliationService $service)
    {
        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to'   => ['required', 'date', 'after_or_equal:period_from'],
            'currency'    => ['nullable', 'string', 'max:10'],
            'statement_closing_balance' => ['required', 'integer'],
        ]);

        $recon = $service->createOrGet(
            $data['period_from'], $data['period_to'], $data['currency'] ?? 'TZS',
            $data['statement_closing_balance'], $request->user()->id,
        );

        return response()->json(['data' => new BankReconciliationResource($recon->load('lines'))], 201);
    }

    public function show(BankReconciliation $bankReconciliation)
    {
        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->load('lines'))]);
    }

    public function update(Request $request, BankReconciliation $bankReconciliation)
    {
        $data = $request->validate([
            'statement_closing_balance' => ['sometimes', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $bankReconciliation->update($data);

        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->load('lines'))]);
    }

    public function destroy(BankReconciliation $bankReconciliation)
    {
        abort_if($bankReconciliation->status === 'complete', 422, 'Cannot delete a completed reconciliation — reopen it first.');
        $bankReconciliation->delete();
        return response()->json(null, 204);
    }

    public function importStatement(Request $request, BankReconciliation $bankReconciliation, BankReconciliationService $service)
    {
        $request->validate(['csv_file' => ['required', 'file']]);

        $result = $service->importStatement($bankReconciliation, $request->file('csv_file'));

        return response()->json(['data' => $result]);
    }

    public function clearStatement(BankReconciliation $bankReconciliation, BankReconciliationService $service)
    {
        $service->clearStatement($bankReconciliation);
        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }

    public function matchLine(Request $request, BankReconciliation $bankReconciliation, BankStatementLine $line, BankReconciliationService $service)
    {
        abort_if($line->reconciliation_id !== $bankReconciliation->id, 404);

        $data = $request->validate([
            'type'     => ['required', 'in:payment,expense,vendor_bill_payment'],
            'match_id' => ['required', 'integer'],
        ]);

        $service->match($line, $data['type'], $data['match_id']);

        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }

    public function unmatchLine(BankReconciliation $bankReconciliation, BankStatementLine $line, BankReconciliationService $service)
    {
        abort_if($line->reconciliation_id !== $bankReconciliation->id, 404);

        $service->unmatch($line);

        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }

    public function autoMatch(BankReconciliation $bankReconciliation, BankReconciliationService $service)
    {
        $matched = $service->autoMatch($bankReconciliation);

        return response()->json(['data' => ['matched' => $matched], 'reconciliation' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }

    public function complete(BankReconciliation $bankReconciliation, BankReconciliationService $service)
    {
        $service->complete($bankReconciliation);
        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }

    public function reopen(BankReconciliation $bankReconciliation, BankReconciliationService $service)
    {
        $service->reopen($bankReconciliation);
        return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh()->load('lines'))]);
    }
}
