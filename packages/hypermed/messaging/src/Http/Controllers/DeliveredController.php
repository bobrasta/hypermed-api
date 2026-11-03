<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Http\Requests\MarkDeliveredRequest;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Http\JsonResponse;

class DeliveredController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function store(MarkDeliveredRequest $request): JsonResponse
    {
        $result = $this->messaging->markDelivered(
            $this->participant($request)->conversation,
            $this->userId($request),
            (int) $request->validated('last_delivered_message_id'),
        );

        return ApiResponse::success($result);
    }
}
