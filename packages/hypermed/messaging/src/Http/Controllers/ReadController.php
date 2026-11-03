<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Http\Requests\MarkReadRequest;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function store(MarkReadRequest $request): JsonResponse
    {
        $result = $this->messaging->markRead(
            $this->participant($request)->conversation,
            $this->userId($request),
            (int) $request->validated('last_read_message_id'),
        );

        return ApiResponse::success($result);
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->messaging->unreadSummary($this->userId($request)));
    }
}
