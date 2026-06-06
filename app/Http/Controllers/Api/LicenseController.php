<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    /**
     * Check license status for a given install_id.
     * Falls back to the single active license if no install_id is supplied
     * (backwards-compat for clients that haven't upgraded yet).
     */
    public function check(Request $request)
    {
        $installId = $request->query('install_id');

        $license = $installId
            ? License::where('install_id', $installId)->first()
            : License::where('is_active', true)->first();

        if (! $license) {
            return response()->json([
                'data' => [
                    'valid'      => false,
                    'status'     => 'unknown',
                    'expires_at' => null,
                    'customer'   => null,
                    'message'    => 'Installation not registered. Contact your administrator.',
                ],
            ]);
        }

        if ($license->status === 'pending') {
            return response()->json([
                'data' => [
                    'valid'      => false,
                    'status'     => 'pending',
                    'expires_at' => null,
                    'customer'   => $license->customer_name,
                    'message'    => 'Your trial request is awaiting approval.',
                ],
            ]);
        }

        if ($license->status === 'revoked' || ! $license->is_active) {
            return response()->json([
                'data' => [
                    'valid'      => false,
                    'status'     => 'revoked',
                    'expires_at' => $license->expires_at?->toIso8601ZuluString(),
                    'customer'   => $license->customer_name,
                    'message'    => 'This license has been revoked. Contact sales@hypermed.app.',
                ],
            ]);
        }

        if ($license->expires_at && $license->expires_at->isPast()) {
            return response()->json([
                'data' => [
                    'valid'      => false,
                    'status'     => 'expired',
                    'expires_at' => $license->expires_at->toIso8601ZuluString(),
                    'customer'   => $license->customer_name,
                    'message'    => 'Your trial has ended. Contact sales@hypermed.app to continue.',
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'valid'      => true,
                'status'     => 'active',
                'expires_at' => $license->expires_at->toIso8601ZuluString(),
                'customer'   => $license->customer_name,
                'message'    => null,
            ],
        ]);
    }

    /**
     * Register a new installation and request a trial license.
     * Idempotent: calling again with the same install_id returns the existing record.
     */
    public function store(Request $request)
    {
        $request->validate([
            'install_id'    => 'required|string|max:64',
            'machine_name'  => 'nullable|string|max:255',
            'customer_name' => 'nullable|string|max:255',
        ]);

        $license = License::firstOrCreate(
            ['install_id' => $request->install_id],
            [
                'machine_name'  => $request->machine_name,
                'customer_name' => $request->customer_name,
                'status'        => 'pending',
                'is_active'     => false,
            ]
        );

        return response()->json([
            'data' => [
                'status'     => $license->status,
                'install_id' => $license->install_id,
                'message'    => 'Trial request received. You will be notified when approved.',
            ],
        ], $license->wasRecentlyCreated ? 201 : 200);
    }
}
