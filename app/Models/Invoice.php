<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use HasFactory;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'invoice_number', 'hospital_id', 'machine_id',
        'sales_order_id', 'client_name', 'client_contact', 'client_email', 'client_tin',
        'issue_date', 'due_date', 'subtotal', 'tax_rate',
        'tax_amount', 'total', 'amount_paid', 'status', 'currency', 'notes',
        'pay_term_number', 'pay_term_type', 'shipping_charges',
        'created_by', 'added_by_name', 'staff_note',
        'shipping_status', 'shipping_address', 'shipping_details', 'delivered_to',
    ];

    public const SHIPPING_STATUSES = ['ordered', 'packed', 'shipped', 'delivered', 'cancelled'];

    /** Saved but not yet a real sale: no ledger posting, no receivable. */
    public const UNFINAL = ['draft', 'proforma'];

    protected static function booted(): void
    {
        // Drafts and proformas are hidden from every invoice query —
        // revenue, receivables, dashboards, customer totals — unless a
        // caller opts in with withUnfinal(). Only the sales screens do.
        static::addGlobalScope('final', fn ($q) => $q->whereNotIn('invoices.status', self::UNFINAL));

        // Who added the sale ("Added by" on All sales).
        static::creating(function (Invoice $invoice) {
            $invoice->created_by ??= auth()->id();
        });
    }

    public function scopeWithUnfinal($query)
    {
        return $query->withoutGlobalScope('final');
    }

    public function isFinal(): bool
    {
        return ! in_array($this->status, self::UNFINAL, true);
    }

    // /invoices/{invoice} must still find drafts and proformas.
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withUnfinal()->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Clickhuduma payment status: paid, partial, due or overdue (or cancelled/waived). */
    public function paymentStatus(): string
    {
        if (in_array($this->status, ['cancelled', 'waived', 'paid', 'draft', 'proforma'], true)) {
            return $this->status;
        }
        if ($this->due_date && $this->due_date->lt(now()->startOfDay())) {
            return 'overdue';
        }

        return $this->amount_paid > 0 ? 'partial' : 'due';
    }

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'integer',
        'tax_rate' => 'float',
        'tax_amount' => 'integer',
        'total' => 'integer',
        'amount_paid' => 'integer',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function lineItems()
    {
        return $this->hasMany(InvoiceLineItem::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function getBalanceDueAttribute(): int
    {
        $applied = $this->creditNotes()->where('status', 'applied')->sum('amount');
        return max(0, $this->total - $this->amount_paid - $applied);
    }
}
