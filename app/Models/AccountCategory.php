<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountCategory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function accounts()
    {
        return $this->hasMany(ChartOfAccount::class, 'category_id');
    }
}
