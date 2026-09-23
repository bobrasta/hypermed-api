<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLog;
use App\Models\DeliveryJob;
use App\Models\Vendor;
use Illuminate\Http\Request;

class DeliveryJobController extends Controller
{
    private function assertOpsAccess(Request $request): void
    {
        $user = $request->user();
        abort_if(
            ! $user->hasLogisticsDeliverAuthority()
                && ! $user->hasProcurementCreateAuthority()
                && ! $user->hasAccountantAuthority()
                && ! $user->hasDirectorAuthority(),
            403,
            'You are not authorised to manage delivery jobs.'
        );
    }

    private function fmt(DeliveryJob $job): array
    {
        $job->loadMissing(['vendor', 'destinationHospital', 'originLocation', 'fee']);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'vendor' => $job->vendor ? ['id' => $job->vendor->id, 'name' => $job->vendor->name] : null,
            'origin_location' => $job->originLocation?->name,
            'destination' => $job->destinationHospital?->name ?? $job->destination_name,
            'destination_address' => $job->destination_address,
            'goods_list' => $job->goods_list,
            'status' => $job->status,
            'has_delivery_note' => $job->hasDeliveryNote(),
            'delivery_note_receiver_name' => $job->delivery_note_receiver_name,
            'delivery_note_date' => $job->delivery_note_date?->toDateString(),
            'delivery_note_goods_mismatch' => $job->delivery_note_goods_mismatch,
            'fee_id' => $job->fee?->id,
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DeliveryJob::with(['vendor', 'destinationHospital', 'fee']);

        if ($user->isVendorStaff()) {
            // Forced, not filtered — same reasoning as VendorFeeController.
            $query->where('vendor_id', $user->vendor_id);
        } else {
            $this->assertOpsAccess($request);
            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['data' => $query->latest()->paginate(50)->through(fn ($j) => $this->fmt($j))]);
    }

    public function show(Request $request, DeliveryJob $deliveryJob)
    {
        $user = $request->user();
        if ($user->isVendorStaff()) {
            abort_if($deliveryJob->vendor_id !== $user->vendor_id, 403, 'You can only view your own vendor\'s jobs.');
        } else {
            $this->assertOpsAccess($request);
        }

        return response()->json(['data' => $this->fmt($deliveryJob)]);
    }

    public function store(Request $request)
    {
        $this->assertOpsAccess($request);

        $data = $request->validate([
            'job_number' => ['required', 'string', 'max:100', 'unique:delivery_jobs,job_number'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'origin_location_id' => ['nullable', 'exists:locations,id'],
            'destination_hospital_id' => ['nullable', 'exists:hospitals,id'],
            'destination_name' => ['nullable', 'string', 'max:255'],
            'destination_address' => ['nullable', 'string'],
            'goods_list' => ['required', 'array', 'min:1'],
            'goods_list.*.item' => ['required', 'string', 'max:255'],
            'goods_list.*.serial' => ['nullable', 'string', 'max:255'],
            'goods_list.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);
        abort_if($vendor->type !== 'delivery', 422, 'Delivery jobs can only be linked to a delivery/transport vendor.');

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'pending';

        $job = DeliveryJob::create($data);
        ApprovalLog::record($job, 'created', $request->user());

        return response()->json(['data' => $this->fmt($job)], 201);
    }

    // Same upload-access shape as ReceiptController::assertUploadAccess()
    // — internal ops staff, or vendor_staff scoped to their own vendor.
    public function uploadDeliveryNote(Request $request, DeliveryJob $deliveryJob)
    {
        $user = $request->user();
        if ($user->isVendorStaff()) {
            abort_if($user->vendor_id !== $deliveryJob->vendor_id, 403, 'You can only upload documents for your own vendor.');
        } else {
            $this->assertOpsAccess($request);
        }
        abort_if($deliveryJob->hasDeliveryNote(), 422, 'A delivery note is already attached to this job.');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'delivery_date' => ['required', 'date'],
            'delivered_goods' => ['required', 'array', 'min:1'],
            'delivered_goods.*.item' => ['required', 'string', 'max:255'],
            'delivered_goods.*.serial' => ['nullable', 'string', 'max:255'],
            'delivered_goods.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $file = $request->file('file');
        $path = $file->store('delivery-notes/' . $deliveryJob->id, 'public');

        // Flag rather than block — 16.3 says "flag if it doesn't match",
        // the receipt/note pair is still what unblocks payment, a mismatch
        // is something for Finance/Director to see and judge, not a hard
        // stop on its own.
        $mismatch = $this->goodsMismatch($deliveryJob->goods_list, $data['delivered_goods']);

        $deliveryJob->update([
            'delivery_note_original_name' => $file->getClientOriginalName(),
            'delivery_note_stored_name' => basename($path),
            'delivery_note_mime' => $file->getClientMimeType(),
            'delivery_note_size' => $file->getSize(),
            'delivery_note_receiver_name' => $data['receiver_name'],
            'delivery_note_date' => $data['delivery_date'],
            'delivery_note_goods_mismatch' => $mismatch,
            'delivery_note_uploaded_by' => $user->id,
            'delivery_note_uploaded_at' => now(),
            'status' => 'delivered',
        ]);
        ApprovalLog::record($deliveryJob, 'delivery_note_uploaded', $user);

        return response()->json(['data' => $this->fmt($deliveryJob->fresh())]);
    }

    private function goodsMismatch(array $expected, array $delivered): bool
    {
        $norm = fn (array $rows) => collect($rows)
            ->map(fn ($r) => strtolower(trim($r['item'])) . '|' . (int) $r['quantity'])
            ->sort()
            ->values()
            ->all();

        return $norm($expected) !== $norm($delivered);
    }
}
