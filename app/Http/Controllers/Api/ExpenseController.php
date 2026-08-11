<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function categories()
    {
        return response()->json(['data' => ExpenseCategory::orderBy('name')->get(['id', 'name', 'account_id'])]);
    }

    public function index(Request $request)
    {
        $query = Expense::with(['category', 'createdBy']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('payment_mode')) {
            $query->where('payment_mode', $request->payment_mode);
        }
        if ($request->filled('date_from')) {
            $query->where('expense_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('expense_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return ExpenseResource::collection($query->latest('expense_date')->paginate(50));
    }

    public function store(Request $request, FinancePostingService $financePosting)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'category_id'  => ['required', 'exists:expense_categories,id'],
            'amount'       => ['required', 'integer', 'min:1'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_mode' => ['required', 'in:cash,bank,mobile_money'],
            'expense_date' => ['required', 'date'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;
        $data['tax_rate']   = $data['tax_rate'] ?? 0;
        $data['tax_amount'] = (int) round($data['amount'] * $data['tax_rate'] / 100);

        $expense = DB::transaction(function () use ($data, $financePosting) {
            $expense = Expense::create($data);
            $financePosting->postExpense($expense);
            return $expense;
        });

        return response()->json(['data' => new ExpenseResource($expense->load(['category', 'createdBy']))], 201);
    }

    public function show(Expense $expense)
    {
        return response()->json(['data' => new ExpenseResource($expense->load(['category', 'createdBy']))]);
    }

    public function update(Request $request, Expense $expense, FinancePostingService $financePosting)
    {
        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'category_id'  => ['sometimes', 'exists:expense_categories,id'],
            'amount'       => ['sometimes', 'integer', 'min:1'],
            'tax_rate'     => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payment_mode' => ['sometimes', 'in:cash,bank,mobile_money'],
            'expense_date' => ['sometimes', 'date'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);

        $newAmount  = $data['amount']   ?? $expense->amount;
        $newTaxRate = $data['tax_rate'] ?? $expense->tax_rate;
        $data['tax_rate']   = $newTaxRate;
        $data['tax_amount'] = (int) round($newAmount * $newTaxRate / 100);

        DB::transaction(function () use ($data, $expense, $financePosting) {
            $financePosting->reverseExpense($expense);
            $expense->update($data);
            $financePosting->postExpense($expense->fresh());
        });

        return response()->json(['data' => new ExpenseResource($expense->fresh()->load(['category', 'createdBy']))]);
    }

    public function destroy(Expense $expense, FinancePostingService $financePosting)
    {
        DB::transaction(function () use ($expense, $financePosting) {
            $financePosting->reverseExpense($expense);
            $expense->delete();
        });

        return response()->json(null, 204);
    }
}
