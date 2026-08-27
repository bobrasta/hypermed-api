<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\SerialNumber;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only path through which stock_levels / inventory_items.stock_qty / stock_movements
 * are ever written together. Every write is transactional and locks the relevant
 * stock_levels row(s) (and, for add(), the item row) before reading them.
 */
class StockService
{
    public function add(
        InventoryItem $item,
        Location $location,
        int $qty,
        int $unitCost,
        string $reason,
        ?Model $reference = null,
        string $type = 'receive',
    ): StockMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($item, $location, $qty, $unitCost, $reason, $reference, $type) {
            $level = $this->lockOrCreateLevel($item, $location);
            $freshItem = InventoryItem::query()->whereKey($item->id)->lockForUpdate()->first();

            $oldQty = (int) $level->quantity_on_hand;
            $oldAvg = (int) $freshItem->unit_cost;
            $newQty = $oldQty + $qty;

            // Weighted average cost, recalculated on every receipt.
            $newAvg = $newQty > 0
                ? (int) round((($oldQty * $oldAvg) + ($qty * $unitCost)) / $newQty)
                : $oldAvg;

            $level->quantity_on_hand = $newQty;
            $level->save();

            $freshItem->unit_cost = $newAvg;
            $freshItem->increment('stock_qty', $qty);
            $freshItem->save();

            $movement = StockMovement::create([
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'type' => $type,
                'quantity' => $qty,
                'quantity_before' => $oldQty,
                'quantity_after' => $newQty,
                'unit_cost' => $unitCost,
                'reason' => $reason,
                'notes' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'performed_by' => auth()->id(),
            ]);

            $this->createSerialsForReceipt($item, $location, $qty, $movement);

            $this->syncReorderAlert($item->id);

            return $movement;
        });
    }

    // Per-unit tracking for every product (2026-08-26 — previously adopted
    // per-item, mostly for capital equipment). One SerialNumber row per unit
    // received, auto-numbered off this movement's id so it's unique without
    // a separate per-item counter. Equipment (creates_machine_record) still
    // gets its richer chain via MachineRegistrationService when sold; every
    // other item gets the lighter consumed_reference_* chain in deduct()
    // below — both read back through the same /inventory/{item}/history.
    private function createSerialsForReceipt(InventoryItem $item, Location $location, int $qty, StockMovement $movement): void
    {
        for ($i = 1; $i <= $qty; $i++) {
            SerialNumber::create([
                'inventory_item_id' => $item->id,
                'serial_number'     => "{$item->sku}-{$movement->id}-{$i}",
                'status'            => 'available',
                'location_id'       => $location->id,
                'purchase_date'     => now()->toDateString(),
            ]);
        }
    }

    public function deduct(
        InventoryItem $item,
        Location $location,
        int $qty,
        string $reason,
        ?Model $reference = null,
        bool $allowNegative = false,
        string $type = 'issue',
    ): StockMovement {
        $this->assertPositiveQuantity($qty);

        return DB::transaction(function () use ($item, $location, $qty, $reason, $reference, $allowNegative, $type) {
            $level = $this->lockOrCreateLevel($item, $location);

            $available = (int) $level->quantity_on_hand;

            abort_if(! $allowNegative && $qty > $available, 422, 'Insufficient stock for this movement.');

            $newQty = $available - $qty;
            $level->quantity_on_hand = $newQty;
            $level->save();

            InventoryItem::whereKey($item->id)->decrement('stock_qty', $qty);

            $movement = StockMovement::create([
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'type' => $type,
                'quantity' => -$qty,
                'quantity_before' => $available,
                'quantity_after' => $newQty,
                'reason' => $reason,
                'notes' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'performed_by' => auth()->id(),
            ]);

            $this->consumeSerials($item, $qty, $reference);

            $this->syncReorderAlert($item->id);

            return $movement;
        });
    }

    // Equipment (creates_machine_record) keeps its own richer consumption
    // path via MachineRegistrationService — skip here to avoid double-
    // consuming the same 'available' serials. Everything else gets this
    // lighter generic chain: oldest-available-first, stamped with whatever
    // deduct() was called for (sales order, stock-out request, etc.) via
    // the same reference_type/reference_id shape stock_movements already
    // uses. Doesn't hard-fail when fewer tracked units exist than $qty —
    // per-unit tracking started 2026-08-26, so older stock may predate it.
    private function consumeSerials(InventoryItem $item, int $qty, ?Model $reference): void
    {
        if ($item->creates_machine_record) {
            return;
        }

        $serials = SerialNumber::where('inventory_item_id', $item->id)
            ->where('status', 'available')
            ->oldest('id')
            ->limit($qty)
            ->get();

        foreach ($serials as $serial) {
            $serial->update([
                'status' => 'sold',
                'consumed_reference_type' => $reference?->getMorphClass(),
                'consumed_reference_id'   => $reference?->getKey(),
                'consumed_at'             => now(),
            ]);
        }
    }

    public function transfer(
        InventoryItem $item,
        Location $from,
        Location $to,
        int $qty,
        string $reason = 'transfer',
    ): StockMovement {
        $this->assertPositiveQuantity($qty);

        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination location must differ.');
        }

        return DB::transaction(function () use ($item, $from, $to, $qty, $reason) {
            // Lock both stock_levels rows in a stable (id-ascending) order to avoid cross
            // deadlocks when two transfers move stock between the same pair of locations
            // in opposite directions at the same time.
            $orderedIds = collect([$from->id, $to->id])->sort()->values();

            $levels = StockLevel::query()
                ->where('inventory_item_id', $item->id)
                ->whereIn('location_id', $orderedIds)
                ->orderBy('location_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('location_id');

            $fromLevel = $levels->get($from->id) ?? StockLevel::create([
                'inventory_item_id' => $item->id, 'location_id' => $from->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0,
            ]);
            $toLevel = $levels->get($to->id) ?? StockLevel::create([
                'inventory_item_id' => $item->id, 'location_id' => $to->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0,
            ]);

            $available = (int) $fromLevel->quantity_on_hand;
            abort_if($qty > $available, 422, 'Insufficient stock at source location for this transfer.');

            $fromLevel->quantity_on_hand = $available - $qty;
            $fromLevel->save();

            $toLevel->quantity_on_hand = (int) $toLevel->quantity_on_hand + $qty;
            $toLevel->save();

            return StockMovement::create([
                'inventory_item_id' => $item->id,
                'location_id' => $from->id,
                'location_from_id' => $from->id,
                'location_to_id' => $to->id,
                'type' => 'transfer',
                'quantity' => $qty,
                'quantity_before' => $available,
                'quantity_after' => $available - $qty,
                'reason' => $reason,
                'notes' => $reason,
                'performed_by' => auth()->id(),
            ]);
        });
    }

    public function adjust(
        InventoryItem $item,
        Location $location,
        int $newQty,
        string $reason,
    ): StockMovement {
        if ($newQty < 0) {
            throw new InvalidArgumentException('New quantity cannot be negative.');
        }

        return DB::transaction(function () use ($item, $location, $newQty, $reason) {
            $level = $this->lockOrCreateLevel($item, $location);

            $before = (int) $level->quantity_on_hand;
            $diff = $newQty - $before;

            $level->quantity_on_hand = $newQty;
            $level->save();

            InventoryItem::whereKey($item->id)->increment('stock_qty', $diff);

            $movement = StockMovement::create([
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'type' => 'adjustment',
                'quantity' => $diff,
                'quantity_before' => $before,
                'quantity_after' => $newQty,
                'reason' => $reason,
                'notes' => $reason,
                'performed_by' => auth()->id(),
            ]);

            $this->syncReorderAlert($item->id);

            return $movement;
        });
    }

    /**
     * Like adjust(), but takes a signed delta instead of an absolute target quantity.
     * Locks the level row before computing the new total, so it's race-safe even
     * though the caller never reads the current quantity itself.
     */
    public function adjustBy(
        InventoryItem $item,
        Location $location,
        int $delta,
        string $reason,
    ): StockMovement {
        if ($delta === 0) {
            throw new InvalidArgumentException('Delta must be non-zero.');
        }

        return DB::transaction(function () use ($item, $location, $delta, $reason) {
            $level = $this->lockOrCreateLevel($item, $location);

            $before = (int) $level->quantity_on_hand;
            $newQty = $before + $delta;

            abort_if($newQty < 0, 422, 'Adjustment would take stock below zero.');

            $level->quantity_on_hand = $newQty;
            $level->save();

            InventoryItem::whereKey($item->id)->increment('stock_qty', $delta);

            $movement = StockMovement::create([
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'type' => 'adjustment',
                'quantity' => $delta,
                'quantity_before' => $before,
                'quantity_after' => $newQty,
                'reason' => $reason,
                'notes' => $reason,
                'performed_by' => auth()->id(),
            ]);

            $this->syncReorderAlert($item->id);

            return $movement;
        });
    }

    /**
     * Checks the item's up-to-the-moment stock_qty (re-queried, not trusted
     * from any in-memory copy — deduct()/adjust()/adjustBy() mutate via a
     * static query builder call, not the passed-in $item instance) against
     * its reorder_level and either fires the low-stock notification once
     * (deduping via reorder_notified_at until restocked) or clears the flag
     * once it's back above threshold, so the next crossing notifies again.
     * Deliberately notify-only per the 2026-08-25 plan — no auto-drafted
     * requisition.
     */
    private function syncReorderAlert(int $itemId): void
    {
        $item = InventoryItem::find($itemId);
        if (! $item) {
            return;
        }

        if ($item->stock_qty > $item->reorder_level) {
            if ($item->reorder_notified_at !== null) {
                $item->update(['reorder_notified_at' => null]);
            }
            return;
        }

        if ($item->reorder_notified_at !== null) {
            return; // already notified for this crossing
        }

        User::whereIn('role', ['procurement_manager', 'storekeeper'])
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'low_stock_alert',
                'title'       => 'Low Stock',
                'body'        => "{$item->name} ({$item->sku}) is at {$item->stock_qty}, at or below its reorder level of {$item->reorder_level}.",
                'entity_type' => 'inventory_item',
                'entity_id'   => $item->id,
                'is_read'     => false,
            ]));

        $item->update(['reorder_notified_at' => now()]);
    }

    private function lockOrCreateLevel(InventoryItem $item, Location $location): StockLevel
    {
        $level = StockLevel::query()
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();

        return $level ?? StockLevel::create([
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
        ]);
    }

    private function assertPositiveQuantity(int $qty): void
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }
}
