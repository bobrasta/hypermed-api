<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplinaryCaseNote extends Model
{
    protected $fillable = ['disciplinary_case_id', 'note', 'created_by'];

    public function case()
    {
        return $this->belongsTo(DisciplinaryCase::class, 'disciplinary_case_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
