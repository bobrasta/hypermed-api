<?php

namespace App\Messaging;

use App\Models\User;
use Hypermed\Messaging\Events\AuditAccessed;

/** Every chat-review read goes into the Activity Log, naming whose chats were read. */
class LogChatReview
{
    public function handle(AuditAccessed $e): void
    {
        $viewer = User::find($e->viewerId);

        if ($e->action === 'conversations') {
            $subject = User::find($e->subjectUserId);
            activity()->causedBy($viewer)->performedOn($subject)
                ->withProperties(['chat_review' => 'conversations', 'user_id' => $e->subjectUserId])
                ->log('Opened the chat list of '.($subject?->name ?? 'user #'.$e->subjectUserId));

            return;
        }

        $names = User::whereIn('id', $e->participantIds)->orderBy('name')->pluck('name')->all();
        activity()->causedBy($viewer)
            ->withProperties(['chat_review' => 'messages', 'conversation_id' => $e->conversationId, 'participant_ids' => $e->participantIds])
            ->log('Read the chat between '.($names ? $this->join($names) : 'conversation #'.$e->conversationId));
    }

    private function join(array $names): string
    {
        $last = array_pop($names);

        return $names ? implode(', ', $names).' and '.$last : $last;
    }
}
