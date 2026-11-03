<?php

namespace Hypermed\Messaging\Calls\Http\Controllers;

use Hypermed\Messaging\Calls\LiveKit\WebhookVerifier;
use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Services\CallService;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * POST /livekit/webhook — no sanctum; authenticated by the LiveKit webhook signature.
 * Used to repair call state when clients crash (room_finished, participant_left/joined).
 */
class LiveKitWebhookController extends Controller
{
    public function __construct(protected WebhookVerifier $verifier, protected CallService $calls) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->verifier->verify($request->getContent(), $request->header('Authorization'));
        } catch (InvalidArgumentException) {
            return ApiResponse::error('Invalid webhook signature.', 'invalid_signature', 401);
        }

        $event = (string) ($payload['event'] ?? '');
        $callId = Call::roomToId((string) ($payload['room']['name'] ?? ''));
        $handled = false;

        if ($callId !== null) {
            $at = isset($payload['createdAt']) && is_numeric($payload['createdAt'])
                ? Carbon::createFromTimestamp((int) $payload['createdAt'])
                : null;
            $identity = $payload['participant']['identity'] ?? null;
            $userId = is_numeric($identity) ? (int) $identity : null;

            switch ($event) {
                case 'room_finished':
                    $this->calls->handleRoomFinished($callId);
                    $handled = true;
                    break;
                case 'participant_left':
                    if ($userId !== null) {
                        $this->calls->handleParticipantLeft($callId, $userId, $at);
                        $handled = true;
                    }
                    break;
                case 'participant_joined':
                    if ($userId !== null) {
                        $this->calls->handleParticipantJoined($callId, $userId, $at);
                        $handled = true;
                    }
                    break;
            }
        }

        return ApiResponse::success(['event' => $event, 'handled' => $handled]);
    }
}
