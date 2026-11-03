<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Http\Requests\ListMessagesRequest;
use Hypermed\Messaging\Http\Requests\SendMessageRequest;
use Hypermed\Messaging\Http\Requests\UpdateMessageRequest;
use Hypermed\Messaging\Http\Resources\MessageResource;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    /**
     * History: no cursor or `before` → newest first (desc); `after` → ascending
     * (catch-up after reconnect). Strict id cursors: no gaps, no duplicates.
     */
    public function index(ListMessagesRequest $request): JsonResponse
    {
        $conversationId = (int) $this->participant($request)->conversation_id;
        $limit = (int) ($request->validated('limit') ?? config('messaging.messages.default_limit', 50));
        $after = $request->validated('after');
        $before = $request->validated('before');

        $query = Message::query()->where('conversation_id', $conversationId)->with(Message::resourceRelations());

        if ($after !== null) {
            $query->where('id', '>', (int) $after)->orderBy('id');
        } else {
            if ($before !== null) {
                $query->where('id', '<', (int) $before);
            }
            $query->orderByDesc('id');
        }

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;

        return ApiResponse::success(
            MessageResource::collection($messages->take($limit))->resolve($request),
            200,
            ['has_more' => $hasMore],
        );
    }

    public function store(SendMessageRequest $request): JsonResponse
    {
        $participant = $this->participant($request);
        $conversation = $participant->conversation;
        $payload = $request->payload();
        $userId = $this->userId($request);

        // Idempotent replay: return the stored message without consuming rate limit.
        if ($existing = $this->messaging->existingForClientUuid($conversation, $userId, $payload['client_uuid'])) {
            return ApiResponse::success($this->messaging->render($existing), 200);
        }

        $this->authorizeGate($request, 'messaging.send', [$conversation]);
        self::hitSendRateLimit($userId);

        $result = $this->messaging->sendUserMessage($conversation, $userId, $payload);

        return ApiResponse::success($this->messaging->render($result['message']), $result['created'] ? 201 : 200);
    }

    public function update(UpdateMessageRequest $request): JsonResponse
    {
        $message = $this->messaging->editMessage($this->message($request), $this->userId($request), (string) $request->validated('body'));

        return ApiResponse::success($this->messaging->render($message));
    }

    public function destroy(Request $request): JsonResponse
    {
        $message = $this->messaging->deleteMessage($this->message($request), $this->userId($request));

        return ApiResponse::success($this->messaging->render($message));
    }

    public static function hitSendRateLimit(int $userId): void
    {
        $key = 'messaging:send:'.$userId;
        $max = (int) config('messaging.rate_limit.send_per_minute', 30);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw MessagingException::rateLimited(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
    }
}
