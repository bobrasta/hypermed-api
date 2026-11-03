<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Http\Requests\AddParticipantsRequest;
use Hypermed\Messaging\Http\Resources\ConversationResource;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Services\ConversationQuery;
use Hypermed\Messaging\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    public function __construct(
        private readonly ConversationQuery $query,
        private readonly ConversationService $conversations,
    ) {}

    public function store(AddParticipantsRequest $request): JsonResponse
    {
        $participant = $this->participant($request);
        $this->assertCanContact($request, $request->validated('user_ids'), 'user_ids');
        $this->conversations->addParticipants($participant, $request->validated('user_ids'));

        $conversation = $this->query->find($this->userId($request), (int) $participant->conversation_id);

        return ApiResponse::success((new ConversationResource($conversation))->resolve($request));
    }

    public function destroy(Request $request, string $conversation, string $user): JsonResponse
    {
        $participant = $this->participant($request);
        $this->conversations->removeParticipant($participant, (int) $user);

        return ApiResponse::success([
            'conversation_id' => (int) $participant->conversation_id,
            'user_id' => (int) $user,
        ]);
    }
}
