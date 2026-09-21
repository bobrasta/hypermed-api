<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Machine extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'serial_no', 'model', 'type', 'hospital_id', 'ward',
        'install_date', 'warranty_expiry', 'status', 'revenue_per_month',
        'sales_order_id', 'installation_ticket_id',
        'installed_by', 'installed_at', 'signed_off_by', 'signed_off_at',
        'lifecycle_stage', 'manufacturer', 'condition', 'arrival_date', 'store_location_id',
        'purchase_cost', 'purchase_cost_currency', 'purchase_cost_fx_rate',
        'purchase_cost_tsh', 'purchase_cost_recorded_at',
    ];

    protected $casts = [
        'install_date' => 'date',
        'warranty_expiry' => 'date',
        'revenue_per_month' => 'integer',
        'installed_at' => 'datetime',
        'signed_off_at' => 'datetime',
        'arrival_date' => 'date',
        'purchase_cost' => 'integer',
        'purchase_cost_fx_rate' => 'decimal:4',
        'purchase_cost_tsh' => 'integer',
        'purchase_cost_recorded_at' => 'datetime',
    ];

    // Section 13 of hypermed_claude_code_prompt.md: "Every step audit-logged".
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty();
    }

    // Maps DB status to Flutter CSS short code
    public static array $statusCodes = [
        'pending_installation' => 'pending_install',
        'pending_signoff'      => 'pending_signoff',
        'operational'  => 'op',
        'needs_service' => 'svc',
        'down'         => 'down',
        'warranty'     => 'claim',
        'idle'         => 'idle',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function storeLocation()
    {
        return $this->belongsTo(Location::class, 'store_location_id');
    }

    // Direct machine_id match only — a ticket built through Section 6's
    // multi-machine picker won't show up here even if this machine is on
    // it. Kept as-is for existing callers; see allTicketIds()/allTickets()
    // below for the combined set.
    public function tickets()
    {
        return $this->hasMany(ServiceTicket::class);
    }

    // Section 6 of hypermed_claude_code_prompt.md: this machine's tickets
    // via either the legacy machine_id column (a ticket that's never used
    // the multi-machine picker) or the service_ticket_machines pivot (any
    // ticket built through it, of any type — generalized beyond Installation
    // per direct instruction). MachineCostService and the machine's Service
    // History tab both need this combined set, not just direct matches.
    public function allTicketIds()
    {
        $direct = ServiceTicket::where('machine_id', $this->id)->pluck('id');
        $viaPivot = \DB::table('service_ticket_machines')
            ->where('machine_id', $this->id)
            ->whereNull('removed_at')
            ->pluck('service_ticket_id');

        return $direct->merge($viaPivot)->unique()->values();
    }

    public function allTickets()
    {
        return ServiceTicket::whereIn('id', $this->allTicketIds());
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function installationTicket()
    {
        return $this->belongsTo(ServiceTicket::class, 'installation_ticket_id');
    }

    public function installedBy()
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function signedOffBy()
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function transfers()
    {
        return $this->hasMany(MachineTransfer::class);
    }

    public function isInstalled(): bool
    {
        return $this->lifecycle_stage === 'installed';
    }
}
