<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Http\Requests\DeleteDeviceRequest;
use Hypermed\Messaging\Http\Requests\RegisterDeviceRequest;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    /** Register/refresh a push token. A token moves to the latest user who registers it. */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $token = (string) $request->validated('token');
        $platform = (string) $request->validated('platform');

        DeviceToken::query()->updateOrCreate(
            ['token_hash' => DeviceToken::hashToken($token)],
            ['token' => $token, 'user_id' => $this->userId($request), 'platform' => $platform, 'last_seen_at' => now()],
        );

        return ApiResponse::success(['token' => $token, 'platform' => $platform]);
    }

    public function destroy(DeleteDeviceRequest $request): JsonResponse
    {
        DeviceToken::query()
            ->where('token_hash', DeviceToken::hashToken((string) $request->validated('token')))
            ->where('user_id', $this->userId($request))
            ->delete();

        return ApiResponse::success(['deleted' => true]);
    }
}
