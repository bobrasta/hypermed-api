<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConnectionsService;
use Illuminate\Http\Request;

/**
 * GET /connections/{type}/{id}: every record connected to this one, one
 * timeline across them, and the ledger entries they posted. Finance, the
 * Director and procurement can open it; only finance readers see the
 * ledger legs.
 */
class ConnectionsController extends Controller
{
    public function show(Request $request, string $type, int $id, ConnectionsService $connections)
    {
        $u = $request->user();
        $finance = SupplierPaymentController::canRead($u);
        abort_unless($finance || $u->hasProcurementCreateAuthority() || $u->hasProcurementApprovalAuthority()
            || $u->hasProcurementSalesStageAuthority() || $u->hasLogisticsReceiveAuthority(), 403, 'You are not authorised to view these connections.');

        $web = $connections->build($type, $id);
        if (! $finance) {
            $web['ledger'] = null;
        }

        return response()->json(['data' => $web]);
    }
}
