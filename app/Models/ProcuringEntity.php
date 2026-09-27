<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// The buyer running a tender (hospital, university, agency). Optional link
// to a Hospital record for entities that are also customers.
class ProcuringEntity extends Model
{
    protected $fillable = ['name', 'addressee', 'address', 'short_code', 'contact', 'hospital_id'];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function tenders()
    {
        return $this->hasMany(Tender::class);
    }

    /** Addressee block as printed on the declarations/letters. */
    public function addressLines(): array
    {
        return array_values(array_filter(array_map('trim', array_merge(
            [$this->addressee ? rtrim($this->addressee, ',') . ',' : null, $this->name ? rtrim($this->name, ',') . ',' : null],
            preg_split('/\r?\n/', (string) $this->address)
        )), fn ($l) => $l !== null && $l !== ''));
    }
}
