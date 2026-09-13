<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadEvent extends Model
{
    protected $fillable = ['sales_lead_id', 'type', 'title', 'note', 'created_by'];

    public function lead()
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
