<?php

namespace Hypermed\Messaging\Calls\Http\Controllers;

use Hypermed\Messaging\Calls\Http\Requests\CallHistoryRequest;
use Hypermed\Messaging\Calls\Http\Requests\StartCallRequest;
use Hypermed\Messaging\Calls\Http\Resources\CallResource;
use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Services\CallService;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CallController extends Controller
{
    public function __construct(protected CallService $calls) {}

    /** POST /conversations/{conversation}/calls → {call, livekit} (201 new / 200 existing active call). */
    public function store(StartCallRequest $request, int $conversation): JsonResponse
    {
        $result = $this->calls->start($conversation, $request->user(), $request->validated('type'));

        return $this->withLiveKit($result['call'], $result['livekit'], $result['created'] ? 201 : 200);
    }

    public function accept(Request $request, int $call): JsonResponse
    {
        $result = $this->calls->accept($call, $request->user());

        return $this->withLiveKit($result['call'], $result['livekit']);
    }

    public function decline(Request $request, int $call): JsonResponse
    {
        return $this->callOnly($this->calls->decline($call, $request->user()));
    }

    public function cancel(Request $request, int $call): JsonResponse
    {
        return $this->callOnly($this->calls->cancel($call, $request->user()));
    }

    public function leave(Request $request, int $call): JsonResponse
    {
        return $this->callOnly($this->calls->leave($call, $request->user()));
    }

    public function show(Request $request, int $call): JsonResponse
    {
        $model = $this->calls->findForUser($call, (int) $request->user()->getKey());

        return $this->callOnly($model->load('participants.user'));
    }

    /** GET /calls?conversation_id=&cursor=&limit= — newest first, cursor-paginated. */
    public function index(CallHistoryRequest $request): JsonResponse
    {
        $conversationId = (int) $request->validated('conversation_id');
        $this->calls->ensureConversationParticipant($conversationId, (int) $request->user()->getKey());

        $limit = (int) ($request->validated('limit') ?? config('messaging-calls.history_default_limit', 30));

        $page = Call::query()
            ->where('conversation_id', $conversationId)
            ->with('participants.user')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $request->validated('cursor'));

        return ApiResponse::success(
            collect($page->items())->map(fn (Call $c) => CallResource::make($c)->resolve($request))->all(),
            200,
            ['next_cursor' => $page->nextCursor()?->encode()],
        );
    }

    protected function withLiveKit(Call $call, array $livekit, int $status = 200): JsonResponse
    {
        return ApiResponse::success([
            'call' => CallResource::make($call)->resolve(),
            'livekit' => $livekit,
        ], $status);
    }

    protected function callOnly(Call $call): JsonResponse
    {
        return ApiResponse::success(['call' => CallResource::make($call)->resolve()]);
    }
}
