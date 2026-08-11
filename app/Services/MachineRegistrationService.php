<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Machine;
use App\Models\SerialNumber;

/**
 * Shared by SalesOrderController::deliver() and PosSaleService — registers
 * one Machine record per unit of equipment delivered to a hospital-linked
 * sale. Kept as its own service so both call paths stay in sync.
 */
class MachineRegistrationService
{
    /**
     * @return Machine[]
     */
    public function registerForDelivery(
        InventoryItem $item,
        int $hospitalId,
        string $orderNumber,
        int $priorQtyDelivered,
        int $qtyJustDelivered,
    ): array {
        if (! $item->creates_machine_record) {
            return [];
        }

        // Prefer consuming real, individually-tracked units when this item
        // has any — units flagged has_missing_parts (cannibalized for a field
        // repair, see PartCannibalization) are excluded so an incomplete unit
        // can never ship. Falls back to fabricating a serial number when this
        // item isn't tracked at the SerialNumber level at all — tracking is
        // adopted gradually per item, not a hard prerequisite for delivery.
        $isTracked = SerialNumber::where('inventory_item_id', $item->id)
            ->where('status', 'available')
            ->exists();

        return $isTracked
            ? $this->registerFromTrackedUnits($item, $hospitalId, $qtyJustDelivered)
            : $this->registerWithFabricatedSerials($item, $hospitalId, $orderNumber, $priorQtyDelivered, $qtyJustDelivered);
    }

    /**
     * @return Machine[]
     */
    private function registerFromTrackedUnits(InventoryItem $item, int $hospitalId, int $qtyJustDelivered): array
    {
        $warrantyMonths = $item->warranty_months ?? 12;

        $completeUnits = SerialNumber::where('inventory_item_id', $item->id)
            ->where('status', 'available')
            ->where('has_missing_parts', false)
            ->oldest('id')
            ->limit($qtyJustDelivered)
            ->get();

        abort_if($completeUnits->count() < $qtyJustDelivered, 422,
            "Cannot deliver {$qtyJustDelivered}x {$item->name} — only {$completeUnits->count()} complete tracked unit(s) available. " .
            'Some stocked units are flagged as missing parts (cannibalized for a field repair) and cannot ship until repaired.');

        $machines = [];

        foreach ($completeUnits as $serial) {
            $machine = Machine::create([
                'serial_no'       => $serial->serial_number,
                'model'           => $item->name,
                'type'            => $item->category?->name ?? 'Equipment',
                'hospital_id'     => $hospitalId,
                'install_date'    => now()->toDateString(),
                'warranty_expiry' => now()->addMonths($warrantyMonths)->toDateString(),
                'status'          => 'operational',
            ]);

            $serial->update(['status' => 'in_service', 'assigned_to_machine_id' => $machine->id]);

            $machines[] = $machine;
        }

        return $machines;
    }

    /**
     * @return Machine[]
     */
    private function registerWithFabricatedSerials(
        InventoryItem $item,
        int $hospitalId,
        string $orderNumber,
        int $priorQtyDelivered,
        int $qtyJustDelivered,
    ): array {
        $warrantyMonths = $item->warranty_months ?? 12;
        $machines = [];

        for ($i = 1; $i <= $qtyJustDelivered; $i++) {
            $seq = $priorQtyDelivered + $i;
            $machines[] = Machine::create([
                'serial_no'       => "{$item->sku}-{$orderNumber}-{$seq}",
                'model'           => $item->name,
                'type'            => $item->category?->name ?? 'Equipment',
                'hospital_id'     => $hospitalId,
                'install_date'    => now()->toDateString(),
                'warranty_expiry' => now()->addMonths($warrantyMonths)->toDateString(),
                'status'          => 'operational',
            ]);
        }

        return $machines;
    }
}
