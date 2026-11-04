<?php

namespace App\Services\Approvals;

use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PerDiemController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequisitionController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\SalaryAdjustmentController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\StockOutRequestController;
use App\Http\Controllers\Api\VendorBillController;
use App\Http\Controllers\Api\VendorFeeController;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\PerDiemRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\SalaryAdjustment;
use App\Models\SalesOrder;
use App\Models\StockOutRequest;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\VendorFee;
use App\Services\NotificationTemplateService;
use App\Services\StockService;
use App\Support\DirectorFastTrack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Director approves past every stage (user, 2026-11-04):
 * - their own requests are approved the moment they're submitted;
 * - "Approve now" on anyone's pending request skips the people still to act.
 *
 * Approval stages only. Where money is paid, Finance still records the
 * payment (method, reference, account): a fast-tracked expense or per diem
 * stops at "awaiting payment", a PO at "awaiting payment initiation" (its
 * final Director approval then follows on its own once Finance records the
 * payment), and a vendor fee at Finance. Each fast-track is in the approval
 * trail as 'fast_tracked'.
 *
 * The stage steps run through the controllers' own approve methods, so
 * stock moves, leave balances, ledger postings and notifications happen
 * exactly as on a normal approval.
 */
class FastTrackService
{
    public const TYPES = [
        'purchase_order'    => PurchaseOrder::class,
        'expense'           => Expense::class,
        'per_diem'          => PerDiemRequest::class,
        'vendor_fee'        => VendorFee::class,
        'requisition'       => PurchaseRequisition::class,
        'leave'             => LeaveRequest::class,
        'stock_out'         => StockOutRequest::class,
        'quotation'         => Quotation::class,
        'sales_order'       => SalesOrder::class,
        'credit_note'       => CreditNote::class,
        'vendor_bill'       => VendorBill::class,
        'payroll_run'       => PayrollRun::class,
        'salary_adjustment' => SalaryAdjustment::class,
    ];

    // AppNotification entity_type per type, so a tap opens the record.
    private const ENTITY = [
        'purchase_order' => 'purchase_order', 'expense' => 'expense', 'per_diem' => 'per_diem_request',
        'vendor_fee' => 'vendor_fee', 'requisition' => 'purchase_requisition', 'leave' => 'leave_request',
        'stock_out' => 'stock_out_request', 'quotation' => 'quotation', 'sales_order' => 'sales_order',
        'credit_note' => 'credit_note', 'vendor_bill' => 'vendor_bill', 'payroll_run' => 'payroll_run',
        'salary_adjustment' => 'salary_adjustment',
    ];

    private const LABEL = [
        'purchase_order' => 'purchase order', 'expense' => 'expense', 'per_diem' => 'travel plan',
        'vendor_fee' => 'vendor fee', 'requisition' => 'requisition', 'leave' => 'leave request',
        'stock_out' => 'stock-out request', 'quotation' => 'quotation', 'sales_order' => 'sales order',
        'credit_note' => 'credit note', 'vendor_bill' => 'vendor bill', 'payroll_run' => 'payroll run',
        'salary_adjustment' => 'salary adjustment',
    ];

    public static function find(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 422, 'Unknown request type.');

        return self::TYPES[$type]::findOrFail($id);
    }

    /**
     * "Approve now": approves past every stage still open. Returns the
     * record, fresh. 422 when nothing is waiting on an approval.
     */
    public function approve(string $type, Model $record, User $director, bool $ownRequest = false): Model
    {
        abort_unless($director->isDirector(), 403, 'Only the Director can approve past the stages.');

        $skipped = DB::transaction(fn () => DirectorFastTrack::run(fn () => $this->run($type, $record, $director)));
        if ($skipped === null) {
            abort(422, 'This ' . self::LABEL[$type] . ' is not waiting on an approval.');
        }

        ApprovalLog::record($record, 'fast_tracked', $director, $ownRequest
            ? 'Director (own request): approved at once.'
            : 'Director approved past the remaining stages.');
        // On the Director's own request nobody was asked yet, so there's no one to tell.
        if (! $ownRequest) {
            $this->notifySkipped($type, $record, $director, $skipped);
        }

        return $record->fresh();
    }

    /** The Director's own request: approved as it's submitted. */
    public function approveOwn(string $type, Model $record, User $creator): Model
    {
        if (! $creator->isDirector()) {
            return $record;
        }

        return $this->approve($type, $record, $creator, ownRequest: true);
    }

    /** True when the Director fast-tracked this record (the PO's final step follows on its own). */
    public static function fastTrackedBy(Model $record): ?User
    {
        $actorId = ApprovalLog::where('subject_type', class_basename($record))
            ->where('subject_id', $record->getKey())
            ->where('action', 'fast_tracked')
            ->latest('id')->value('actor_id');

        return $actorId ? User::find($actorId) : null;
    }

    /**
     * Runs the stage steps. Returns the ids of people whose turn was skipped
     * (to tell them), or null when nothing was waiting on an approval.
     *
     * @return Collection<int, int>|null
     */
    private function run(string $type, Model $r, User $d): ?Collection
    {
        $req = fn (array $input = []) => tap(Request::create('/', 'POST', $input), fn ($q) => $q->setUserResolver(fn () => $d));

        switch ($type) {
            case 'purchase_order':
                /** @var PurchaseOrder $r */
                $c = app(PurchaseOrderController::class);
                if ($r->status === 'pending_director_final') {
                    $c->approveDirectorFinal($req(), $r);

                    return collect();
                }
                if (! in_array($r->status, ['draft', 'pending_sales_manager', 'pending_director_review'], true)) {
                    return null;
                }
                $skipped = $r->status === 'pending_director_review' ? collect() : User::where('role', 'sales_manager')->pluck('id');
                $r->update([
                    'status'               => 'pending_payment_initiation',
                    'sales_approved_by'    => $r->sales_approved_by ?? $d->id,
                    'sales_approved_at'    => $r->sales_approved_at ?? now(),
                    'director_reviewed_by' => $d->id,
                    'director_reviewed_at' => now(),
                ]);
                $c->notifyStage($r, 'accountant', 'purchase_order.director_reviewed', ['po_number' => $r->po_number]);

                return $skipped;

            case 'expense':
                /** @var Expense $r */
                if (! in_array($r->status, ['pending_manager', 'pending_cto', 'pending_director'], true)) {
                    return null;
                }
                $skipped = match ($r->status) {
                    'pending_manager' => collect([$r->approver_id])->filter(),
                    'pending_cto'     => User::holdingPermission('authority.cto_tier')->pluck('id'),
                    default           => collect(),
                };
                $r->update(['status' => 'pending_director']);
                app(ExpenseController::class)->approve($req(), $r->fresh());

                return $skipped;

            case 'per_diem':
                /** @var PerDiemRequest $r */
                if (! in_array($r->status, ['pending_team_lead', 'pending_cto'], true)) {
                    return null;
                }
                $skipped = User::holdingPermission('authority.cto_tier')->pluck('id');
                if ($r->status === 'pending_team_lead') {
                    $skipped = $skipped->merge(User::holdingPermission('authority.team_lead_tier')->pluck('id'));
                    $r->update(['status' => 'pending_cto', 'team_lead_reviewed_by' => $d->id, 'team_lead_reviewed_at' => now()]);
                }
                app(PerDiemController::class)->approve($req(), $r->fresh());

                return $skipped;

            case 'vendor_fee':
                /** @var VendorFee $r */
                if ($r->status !== 'pending_manager') {
                    return null;
                }
                $skipped = collect([$r->approver_id])->filter();
                app(VendorFeeController::class)->managerApprove($req(), $r);

                return $skipped;

            case 'requisition':
                /** @var PurchaseRequisition $r */
                $c = app(PurchaseRequisitionController::class);
                if ($r->status === 'draft') {
                    $c->submit($req(), $r);
                    $r->refresh();
                }
                if ($r->status !== 'submitted') {
                    return null;
                }
                $c->approve($req(), $r);

                return User::holdingPermission('procurement.approve_requisition')->pluck('id');

            case 'leave':
                /** @var LeaveRequest $r */
                if ($r->status !== 'pending') {
                    return null;
                }
                app(LeaveController::class)->approve($req(['days_count' => $r->days_count]), $r);

                return User::holdingPermission('hr.approve_leave')->pluck('id');

            case 'stock_out':
                /** @var StockOutRequest $r */
                if ($r->status !== 'pending') {
                    return null;
                }
                app(StockOutRequestController::class)->approve($req(), $r, app(StockService::class));

                return User::holdingPermission('authority.cto_tier')->pluck('id');

            case 'quotation':
            case 'sales_order':
                if ($r->approval_status !== 'pending') {
                    return null;
                }
                $type === 'quotation'
                    ? app(QuotationController::class)->approve($req(), $r)
                    : app(SalesOrderController::class)->approve($req(), $r);

                return User::holdingPermission('sales.approve_order')->pluck('id');

            case 'credit_note':
                if ($r->status !== 'draft') {
                    return null;
                }
                app(CreditNoteController::class)->approve($req(), $r);

                return collect();

            case 'vendor_bill':
                if ($r->status !== 'pending') {
                    return null;
                }
                app(VendorBillController::class)->approve($req(), $r);

                return collect();

            case 'payroll_run':
                /** @var PayrollRun $r */
                $c = app(PayrollController::class);
                if ($r->status === 'draft') {
                    $c->review($req(), $r);
                    $r->refresh();
                }
                if ($r->status !== 'reviewed') {
                    return null;
                }
                $c->approve($req(), $r);

                return collect();

            case 'salary_adjustment':
                /** @var SalaryAdjustment $r */
                if ($r->status !== 'pending') {
                    return null;
                }
                app(SalaryAdjustmentController::class)->approve($req(), User::findOrFail($r->user_id), $r);

                return collect();
        }

        return null;
    }

    /** Tells the people whose turn was skipped that it's done, and the requester. */
    private function notifySkipped(string $type, Model $record, User $director, Collection $skipped): void
    {
        $requesterId = $record->ordered_by ?? $record->created_by ?? $record->requested_by ?? $record->user_id ?? null;
        $templates = app(NotificationTemplateService::class);
        $vars = ['director' => $director->name, 'item' => self::LABEL[$type], 'reference' => $this->reference($record)];

        $send = fn (int $userId, string $key) => AppNotification::create([
            'user_id'     => $userId,
            ...$templates->render($key, $vars),
            'entity_type' => self::ENTITY[$type],
            'entity_id'   => $record->getKey(),
            'is_read'     => false,
        ]);

        $skipped->unique()->reject(fn ($id) => $id === $director->id || $id === $requesterId)
            ->each(fn ($id) => $send((int) $id, 'approval.fast_tracked_skipped'));
        if ($requesterId && $requesterId !== $director->id) {
            $send((int) $requesterId, 'approval.fast_tracked');
        }
    }

    private function reference(Model $r): string
    {
        foreach (['po_number', 'requisition_number', 'quote_number', 'quotation_number', 'order_number', 'credit_note_number', 'bill_number', 'name', 'destination', 'description'] as $field) {
            if (! empty($r->{$field})) {
                return (string) $r->{$field};
            }
        }

        return '#' . $r->getKey();
    }
}
