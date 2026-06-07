<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number', 'hospital_id', 'machine_id',
        'sales_order_id', 'client_name', 'client_contact', 'client_email',
        'issue_date', 'due_date', 'subtotal', 'tax_rate',
        'tax_amount', 'total', 'amount_paid', 'status', 'currency', 'notes',
    ];

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

    public function getBalanceDueAttribute(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }
}
