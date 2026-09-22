<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => new UserResource($request->user()->load(['position', 'manager', 'paymentProfile']))]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $user->update($data);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required'],
            'new_password'     => ['required', 'string', 'min:8'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['new_password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    // Section 15.7: own payment details, editable only by the owner —
    // full (never masked) here, since this is always a self-view. Used to
    // pre-fill the Settings screen's payment-profile form and gate
    // per-diem submission (PerDiemController::store()).
    public function updatePaymentProfile(Request $request)
    {
        $data = $request->validate([
            'provider'       => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'account_name'   => ['required', 'string', 'max:255'],
        ]);

        $profile = $request->user()->paymentProfile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json(['data' => [
            'provider'       => $profile->provider,
            'account_number' => $profile->account_number,
            'account_name'   => $profile->account_name,
        ]]);
    }
}
