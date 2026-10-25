<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notification (audit L5). Stored in the `notifications` table
 * only; there is no phone push. `kind` and `target` tell the app which
 * screen to open.
 */
class AppNotice extends Notification
{
    public const QUESTION_ANSWERED = 'question_answered';

    public const CHAT_MESSAGE = 'chat_message';

    public const REPORT_READY = 'report_ready';

    public const REPORT_FAILED = 'report_failed';

    public const SUMMARY_SHARED = 'summary_shared';

    public const VISIT_REMINDER = 'visit_reminder';

    /** An admin approved or rejected a doctor's registration (audit L2). */
    public const ACCOUNT_REVIEWED = 'account_reviewed';

    /**
     * @param  array<string, int|string|null>  $target  ids the app needs to open the screen
     * @param  string|null  $dedupe  while an unread notice with this key exists, no new one is added
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public array $target = [],
        public ?string $dedupe = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'target' => $this->target,
            'dedupe' => $this->dedupe,
        ];
    }
}
