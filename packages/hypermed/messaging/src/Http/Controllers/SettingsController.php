<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Contracts\AuditPolicy;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** What clients need to know about how messaging is set up right now. */
class SettingsController extends Controller
{
    public function show(Request $request, AuditPolicy $policy): JsonResponse
    {
        $enabled = $policy->enabled();

        return ApiResponse::success([
            // When true, clients tell users their chats may be reviewed.
            'audit_enabled' => $enabled,
            'can_audit' => $enabled && Gate::forUser($request->user())->allows('messaging.audit.view'),
            'calls_enabled' => (bool) config('messaging-calls.enabled', false),
        ]);
    }
}
