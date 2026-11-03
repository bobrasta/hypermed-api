<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Http\Requests\UploadAttachmentRequest;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Models\Attachment;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Services\AttachmentService;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly AttachmentService $attachments,
    ) {}

    public function store(UploadAttachmentRequest $request): JsonResponse
    {
        $conversation = $this->participant($request)->conversation;
        $userId = $this->userId($request);
        $clientUuid = strtolower((string) $request->validated('client_uuid'));

        if ($existing = $this->messaging->existingForClientUuid($conversation, $userId, $clientUuid)) {
            return ApiResponse::success($this->messaging->render($existing), 200);
        }

        $this->authorizeGate($request, 'messaging.send', [$conversation]);
        MessageController::hitSendRateLimit($userId);

        $stored = $this->attachments->store($request->file('file'), (int) $conversation->id);

        try {
            $result = $this->messaging->createMessage($conversation, $userId, [
                'type' => Message::TYPE_FILE,
                'body' => $request->validated('body'),
                'client_uuid' => $clientUuid,
                'reply_to_id' => $request->validated('reply_to_id'),
            ], function (Message $message) use ($stored) {
                Attachment::query()->create($stored + ['message_id' => $message->id]);
            });
        } catch (\Throwable $e) {
            Storage::disk($stored['disk'])->delete(array_filter([$stored['path'], $stored['thumb_path']]));
            throw $e;
        }

        if (! $result['created']) {
            Storage::disk($stored['disk'])->delete(array_filter([$stored['path'], $stored['thumb_path']]));
        }

        return ApiResponse::success($this->messaging->render($result['message']), $result['created'] ? 201 : 200);
    }

    /** Signed, unauthenticated (no bearer) download. Participation was checked when the URL was issued. */
    public function download(Request $request, string $attachment): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            throw MessagingException::forbidden('This download link is invalid or has expired.');
        }

        $model = Attachment::query()->with('message')->find((int) $attachment)
            ?? throw MessagingException::notFound('File not found.');

        if (! $model->message || $model->message->isDeleted()) {
            throw MessagingException::notFound('File not found.');
        }

        return $this->attachments->download($model, $request->boolean('thumb'));
    }
}
