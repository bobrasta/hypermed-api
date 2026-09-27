<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DeviceRegistration extends Model
{
    use LogsActivity;

    public const STATUSES = ['preparing_dossier', 'submitted', 'under_review', 'registered', 'renewal_due', 'expired'];

    /**
     * TMDA Annex V essential principles (GHTF-based list, 12 groups). Seeded
     * onto every new registration; confirm against TMDA's current Annex V.
     */
    public const PRINCIPLES = [
        'General requirements',
        'Chemical, physical and biological properties',
        'Infection and microbial contamination',
        'Manufacturing and environmental properties',
        'Devices with a diagnostic or measuring function',
        'Protection against radiation',
        'Requirements for devices connected to or equipped with an energy source',
        'Protection against mechanical risks',
        'Protection against the risks posed by the supply of energy or substances',
        'Protection against the risks posed by devices for self-testing',
        'Information supplied by the manufacturer',
        'Performance evaluation, including clinical evaluation',
    ];

    protected $fillable = [
        'brand_name', 'common_name', 'model', 'manufacturer', 'risk_class', 'registration_number',
        'status', 'submitted_at', 'registered_at', 'renewal_due_date', 'notes', 'created_by',
    ];

    protected $casts = ['submitted_at' => 'date', 'registered_at' => 'date', 'renewal_due_date' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function requirements()
    {
        return $this->hasMany(DeviceRequirement::class)->orderBy('principle_no');
    }

    public function files()
    {
        return $this->hasMany(DeviceRegistrationFile::class);
    }

    /** A Reason for Importation letter needs a live registration number. */
    public function allowsImport(): bool
    {
        return filled($this->registration_number) && in_array($this->status, ['registered', 'renewal_due'], true);
    }
}
