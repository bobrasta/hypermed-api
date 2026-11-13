<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\SerialNumber;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryItem::with(['compatibleModels', 'category', 'stockLevels.location'])
            ->where('is_active', true);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_qty', '<=', 'reorder_level');
        }
        if ($request->filled('creates_machine_record')) {
            $query->where('creates_machine_record', $request->boolean('creates_machine_record'));
        }
        if ($request->filled('supplier')) {
            $query->where('supplier', $request->supplier);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('sku', 'ilike', '%' . $request->search . '%');
            });
        }

        // Default stays small for anything hitting this without an explicit per_page
        // (e.g. a future paginated table), but every current caller — Inventory Items,
        // POS catalog/scan, Add Part, Quotations/Sales Orders line-item pickers,
        // Requisitions, Stock Movements — loads the FULL catalog once client-side and
        // paginates/filters locally, so cap high rather than silently truncating a
        // 300+ item real catalog to 20.
        $perPage = min($request->integer('per_page', 20), 1000);

        return InventoryItemResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasInventoryManageAuthority(), 403, 'You are not authorised to manage the inventory catalog.');

        $data = $request->validate([
            // Blank SKU → numbered automatically (HM-000123).
            'sku'                 => ['nullable', 'string', 'unique:inventory_items'],
            'name'                => ['required', 'string'],
            'manufacturer'        => ['nullable', 'string', 'max:255'],
            'model_number'        => ['nullable', 'string', 'max:255'],
            'has_ce'              => ['nullable', 'boolean'],
            'has_fda'             => ['nullable', 'boolean'],
            'has_tbs'             => ['nullable', 'boolean'],
            'description'         => ['nullable', 'string'],
            'category_id'         => ['nullable', 'exists:categories,id'],
            'unit_of_measure'     => ['nullable', 'in:piece,box,litre,set,kg,roll,pair,bottle'],
            'unit_cost'           => ['required', 'integer', 'min:0'],
            'currency'            => ['nullable', 'string', 'max:10'],
            'cost_currency'       => ['nullable', 'string', 'size:3'],
            'stock_qty'           => ['required', 'integer', 'min:0'],
            'reorder_level'       => ['required', 'integer', 'min:0'],
            'supplier'            => ['nullable', 'string'],
            'creates_machine_record' => ['nullable', 'boolean'],
            'warranty_months'     => ['nullable', 'integer', 'min:0'],
            'compatible_models'   => ['nullable', 'array'],
            'compatible_models.*' => ['string'],
        ]);

        $models = $data['compatible_models'] ?? [];
        unset($data['compatible_models']);
        // The unit cost's currency (TZS unless given). 'currency' was never
        // stored (not fillable); older apps sending it mean the cost's.
        $data['cost_currency'] = strtoupper($data['cost_currency'] ?? $data['currency'] ?? 'TZS');
        unset($data['currency']);
        if (isset($data['manufacturer'])) {
            $data['manufacturer'] = trim($data['manufacturer']) ?: null;
        }
        if (blank($data['sku'] ?? null)) {
            $data['sku'] = $this->nextSku();
        }

        $item = InventoryItem::create($data);

        foreach ($models as $model) {
            $item->compatibleModels()->create(['machine_model' => $model]);
        }

        return response()->json(['data' => new InventoryItemResource($item->load(['compatibleModels', 'category']))], 201);
    }

    // GET /inventory/manufacturers — every manufacturer name already in the
    // system (items, machines, and suppliers marked Manufacturer), for the
    // item form's type-ahead. Same name in different case is listed once.
    public function manufacturers()
    {
        $names = DB::table('inventory_items')->whereNotNull('manufacturer')->pluck('manufacturer')
            ->merge(DB::table('machines')->whereNotNull('manufacturer')->pluck('manufacturer'))
            ->merge(DB::table('suppliers')->where('type', 'manufacturer')->pluck('name'))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['data' => $names]);
    }

    /** Next free automatic SKU: HM-000001, HM-000002, … */
    private function nextSku(): string
    {
        $n = (int) InventoryItem::where('sku', 'like', 'HM-%')
            ->pluck('sku')
            ->map(fn ($s) => ctype_digit(substr($s, 3)) ? (int) substr($s, 3) : 0)
            ->max();
        do {
            $sku = sprintf('HM-%06d', ++$n);
        } while (InventoryItem::where('sku', $sku)->exists());

        return $sku;
    }

    // Minimal-friction creation for a part discovered mid-service that isn't
    // catalogued yet — auto-fills everything store() otherwise requires and
    // flags the item for inventory admin to properly fill in later.
    public function quickCreate(Request $request)
    {
        $data = $request->validate([
            'name'          => ['required', 'string'],
            'cost_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $item = InventoryItem::create([
            'sku'           => 'QR-' . strtoupper(uniqid()),
            'name'          => $data['name'],
            'category_id'   => null,
            'unit_cost'     => 0,
            'cost_currency' => strtoupper($data['cost_currency'] ?? 'TZS'),
            'stock_qty'     => 0,
            'reorder_level' => 0,
            'needs_review'  => true,
        ]);

        return response()->json(['data' => new InventoryItemResource($item->fresh())], 201);
    }

    // Route param is {inventory} — variable name must match
    public function show(InventoryItem $inventory)
    {
        $inventory->load(['compatibleModels', 'category', 'stockLevels.location']);

        return response()->json(['data' => new InventoryItemResource($inventory)]);
    }

    public function update(Request $request, InventoryItem $inventory)
    {
        abort_if(! $request->user()->hasInventoryManageAuthority(), 403, 'You are not authorised to manage the inventory catalog.');

        $data = $request->validate([
            'sku'             => ['sometimes', 'string', 'unique:inventory_items,sku,' . $inventory->id],
            'name'            => ['sometimes', 'string'],
            'manufacturer'    => ['nullable', 'string', 'max:255'],
            'model_number'    => ['nullable', 'string', 'max:255'],
            'has_ce'          => ['sometimes', 'boolean'],
            'has_fda'         => ['sometimes', 'boolean'],
            'has_tbs'         => ['sometimes', 'boolean'],
            'description'     => ['nullable', 'string'],
            'category_id'     => ['nullable', 'exists:categories,id'],
            'unit_of_measure' => ['nullable', 'in:piece,box,litre,set,kg,roll,pair,bottle'],
            'unit_cost'       => ['sometimes', 'integer', 'min:0'],
            'cost_currency'   => ['sometimes', 'string', 'size:3'],
            'stock_qty'       => ['sometimes', 'integer', 'min:0'],
            'reorder_level'   => ['sometimes', 'integer', 'min:0'],
            'supplier'        => ['nullable', 'string'],
            'is_active'       => ['sometimes', 'boolean'],
            'creates_machine_record' => ['sometimes', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'needs_review'    => ['sometimes', 'boolean'],
        ]);

        if (isset($data['cost_currency'])) {
            $data['cost_currency'] = strtoupper($data['cost_currency']);
        }
        $inventory->update($data);

        return response()->json(['data' => new InventoryItemResource($inventory->load(['compatibleModels', 'category']))]);
    }

    public function destroy(Request $request, InventoryItem $inventory)
    {
        abort_if(! $request->user()->hasInventoryManageAuthority(), 403, 'You are not authorised to manage the inventory catalog.');

        $inventory->update(['is_active' => false]);

        return response()->json(['message' => 'Item deactivated.']);
    }

    public function adjust(Request $request, InventoryItem $inventoryItem, StockService $stockService)
    {
        abort_if(! $request->user()->hasStockAdjustAuthority(), 403, 'You are not authorised to adjust stock quantities.');

        $data = $request->validate([
            'location_id'  => ['required', 'exists:locations,id'],
            'new_quantity' => ['required', 'integer', 'min:0'],
            'reason'       => ['nullable', 'string'],
        ]);

        $location = Location::findOrFail($data['location_id']);

        $stockService->adjust($inventoryItem, $location, $data['new_quantity'], $data['reason'] ?? 'Manual adjustment');

        return response()->json(['data' => new InventoryItemResource(
            $inventoryItem->fresh()->load(['compatibleModels', 'category', 'stockLevels.location'])
        )]);
    }

    // One row per individually tracked unit, oldest-received first — the
    // "20 X-rays: track each one sold where / installed by / signed off by
    // whom, until the count reaches zero" case. Assembled server-side from
    // SerialNumber (received) + Machine (sold/installed/signed-off), since
    // those already carry the full chain (see MachineRegistrationService /
    // ServiceTicketController::resolve() / MachineController::signOff()).
    public function history(InventoryItem $inventoryItem)
    {
        $serials = $inventoryItem->serialNumbers()
            ->with([
                'assignedMachine.hospital',
                'assignedMachine.salesOrder',
                'assignedMachine.installedBy',
                'assignedMachine.signedOffBy',
            ])
            ->orderBy('id')
            ->get();

        $history = $serials->map(function (SerialNumber $serial) {
            $machine = $serial->assignedMachine;

            return [
                'serial_number' => $serial->serial_number,
                'status'        => $serial->status,
                'received_at'   => ($serial->purchase_date ?? $serial->created_at)?->toDateString(),
                'sold' => $machine?->salesOrder ? [
                    'sales_order_id'     => $machine->salesOrder->id,
                    'sales_order_number' => $machine->salesOrder->order_number,
                    'hospital_name'      => $machine->hospital?->name,
                    'delivered_at'       => $machine->created_at?->toDateString(),
                ] : $this->genericConsumption($serial),
                'installed' => $machine?->installed_at ? [
                    'by'   => $machine->installedBy?->name,
                    'at'   => $machine->installed_at?->toIso8601String(),
                ] : null,
                'signed_off' => $machine?->signed_off_at ? [
                    'by' => $machine->signedOffBy?->name,
                    'at' => $machine->signed_off_at?->toIso8601String(),
                ] : null,
                // Equipment (has a Machine record) goes through install/sign-off;
                // everything else just has received/sold, so the Flutter card
                // knows not to render steps that will never apply.
                'is_equipment'   => $machine !== null,
                'machine_status' => $machine?->status,
            ];
        });

        return response()->json(['data' => $history]);
    }

    // Non-equipment items (no Machine record) still get a lightweight
    // "sold/issued" entry from SerialNumber's own consumed_reference_*
    // columns (see StockService::consumeSerials()) — resolves a couple of
    // known reference types for a readable label, falls back to a generic
    // one for anything else so a new reference type never breaks History.
    private function genericConsumption(SerialNumber $serial): ?array
    {
        if (! $serial->consumed_reference_type) {
            return null;
        }

        $label = match ($serial->consumed_reference_type) {
            \App\Models\SalesOrder::class => optional(
                \App\Models\SalesOrder::find($serial->consumed_reference_id)
            )->order_number,
            \App\Models\ServiceTicket::class => optional(
                \App\Models\ServiceTicket::find($serial->consumed_reference_id)
            )->ticket_number,
            default => class_basename($serial->consumed_reference_type) . ' #' . $serial->consumed_reference_id,
        };

        return [
            'sales_order_id'     => null,
            'sales_order_number' => $label,
            'hospital_name'      => null,
            'delivered_at'       => $serial->consumed_at?->toDateString(),
        ];
    }
}
