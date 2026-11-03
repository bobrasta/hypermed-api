<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Http\Requests\ListConversationsRequest;
use Hypermed\Messaging\Http\Requests\MuteConversationRequest;
use Hypermed\Messaging\Http\Requests\StoreDirectConversationRequest;
use Hypermed\Messaging\Http\Requests\StoreGroupConversationRequest;
use Hypermed\Messaging\Http\Resources\ConversationResource;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Services\ConversationQuery;
use Hypermed\Messaging\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationQuery $query,
        private readonly ConversationService $conversations,
    ) {}

    public function index(ListConversationsRequest $request): JsonResponse
    {
        $limit = (int) ($request->validated('limit') ?? config('messaging.list.default_limit', 30));
        $page = $this->query->page($this->userId($request), $request->validated('cursor'), $limit);

        return ApiResponse::success(
            ConversationResource::collection($page['items'])->resolve($request),
            200,
            ['next_cursor' => $page['next_cursor']],
        );
    }

    public function storeDirect(StoreDirectConversationRequest $request): JsonResponse
    {
        [$conversation, $created] = $this->conversations->findOrCreateDirect(
            $this->userId($request),
            (int) $request->validated('user_id'),
        );

        return $this->respond($request, (int) $conversation->id, $created ? 201 : 200);
    }

    public function storeGroup(StoreGroupConversationRequest $request): JsonResponse
    {
        $this->authorizeGate($request, 'messaging.group.create');

        $conversation = $this->conversations->createGroup(
            $this->userId($request),
            (string) $request->validated('title'),
            $request->validated('user_ids'),
        );

        return $this->respond($request, (int) $conversation->id, 201);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, (int) $this->participant($request)->conversation_id);
    }

    public function mute(MuteConversationRequest $request): JsonResponse
    {
        $until = $request->validated('muted_until');
        $this->conversations->mute($this->participant($request), $until ? Carbon::parse($until) : null);

        return $this->respond($request, (int) $this->participant($request)->conversation_id);
    }

    private function respond(Request $request, int $conversationId, int $status = 200): JsonResponse
    {
        $conversation = $this->query->find($this->userId($request), $conversationId);

        return ApiResponse::success((new ConversationResource($conversation))->resolve($request), $status);
    }
}
