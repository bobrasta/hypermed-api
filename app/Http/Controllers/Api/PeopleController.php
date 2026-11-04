<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A colleague's profile as any staff member may see it (from chat, the org
 * chart, a name anywhere): who they are and what they do, never personal
 * or sensitive data. Deliberately an allow-list, not UserResource: no
 * NIDA/TIN/NSSF numbers, bank or payment details, salary, next of kin,
 * gender, permissions, discount/commission rates or biometric id.
 */
class PeopleController extends Controller
{
    public function show(Request $request, User $user)
    {
        $me = $request->user();
        abort_if($me->isVendorStaff() || $me->vendor_id !== null, 403, 'Staff profiles are for Hypermed staff.');
        abort_if($user->isVendorStaff() || $user->vendor_id !== null, 404, 'Resource not found.');

        $user->loadMissing(['position', 'manager.position']);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'position' => $user->position?->title,
            'department' => $user->position?->department,
            'role' => $user->role,
            'region' => $user->region ?: $user->zone,
            'email' => $user->email,
            'phone' => $user->phone,
            'joined' => $user->hire_date?->toDateString(),
            'bio' => $user->bio,
            'qualifications' => array_values((array) ($user->qualifications ?? [])),
            'reports_to' => $user->manager && $user->manager->is_active ? [
                'id' => $user->manager->id,
                'name' => $user->manager->name,
                'position' => $user->manager->position?->title,
            ] : null,
            'is_active' => (bool) $user->is_active,
        ]]);
    }
}
