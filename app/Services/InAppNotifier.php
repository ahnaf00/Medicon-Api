<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\MedicalRecord;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AppNotice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates in-app notifications (audit L5). A failure here is logged and
 * never breaks the action that caused it (sending a message, saving a summary).
 */
class InAppNotifier
{
    public function send(?User $to, AppNotice $notice): void
    {
        if (! $to) {
            return;
        }

        try {
            if ($notice->dedupe !== null
                && $to->unreadNotifications()->where('data->dedupe', $notice->dedupe)->exists()) {
                return;
            }

            $to->notify($notice);
        } catch (Throwable $e) {
            Log::warning("In-app notification {$notice->kind} for user {$to->id} failed: ".$e->getMessage());
        }
    }

    /** A doctor answered a question, or someone wrote in a direct chat. */
    public function messageSent(Conversation $conversation, Message $message): void
    {
        $senderId = (int) $message->sender_user_id;
        $fromPatient = $senderId === (int) $conversation->patient_user_id;
        $preview = Str::limit($message->body, 120);

        if ($conversation->type === Conversation::TYPE_DIRECT) {
            $sender = User::find($senderId);
            $this->send(
                User::find($fromPatient ? $conversation->doctor_user_id : $conversation->patient_user_id),
                new AppNotice(
                    AppNotice::CHAT_MESSAGE,
                    'New message from '.($sender?->name ?? 'your '.($fromPatient ? 'patient' : 'doctor')),
                    $preview,
                    ['conversation_id' => $conversation->id],
                    "chat:{$conversation->id}",
                ),
            );

            return;
        }

        if (! $fromPatient) {
            $this->send(User::find($conversation->patient_user_id), new AppNotice(
                AppNotice::QUESTION_ANSWERED,
                'A doctor answered your question',
                $preview,
                ['conversation_id' => $conversation->id],
                "question:{$conversation->id}",
            ));
        }
    }

    public function reportAnalysed(MedicalRecord $record): void
    {
        $failed = $record->analysis_status === 'failed';
        $name = $record->title ?: 'your report';

        $this->send(User::find($record->patient_user_id), new AppNotice(
            $failed ? AppNotice::REPORT_FAILED : AppNotice::REPORT_READY,
            $failed ? 'Report analysis did not finish' : 'Your report analysis is ready',
            $failed
                ? "We could not analyse {$name}. Your original document is kept."
                : "The AI-generated interpretation of {$name} is ready. Discuss it with your doctor.",
            ['record_id' => $record->id],
        ));
    }

    public function summaryShared(Appointment $appointment): void
    {
        $doctor = User::find($appointment->doctor_user_id);

        $this->send(User::find($appointment->patient_user_id), new AppNotice(
            AppNotice::SUMMARY_SHARED,
            'Your visit summary is ready',
            ($doctor?->name ?? 'Your doctor').' shared the summary of your visit.',
            ['appointment_id' => $appointment->id],
        ));
    }

    /** Both people get a reminder; the time is shown in the clinic's time zone. */
    public function visitReminder(Appointment $appointment): void
    {
        $patient = User::find($appointment->patient_user_id);
        $doctor = User::find($appointment->doctor_user_id);
        $time = $appointment->appointment_datetime
            ->copy()->setTimezone(DoctorSlotService::timezone())->format('g:i A');
        $target = [
            'appointment_id' => $appointment->id,
            'patient_user_id' => $appointment->patient_user_id,
        ];

        $this->send($patient, new AppNotice(
            AppNotice::VISIT_REMINDER,
            'Your visit starts soon',
            'Your visit with '.($doctor?->name ?? 'your doctor')." starts at {$time}.",
            $target + ['for' => 'patient'],
        ));
        $this->send($doctor, new AppNotice(
            AppNotice::VISIT_REMINDER,
            'Visit starts soon',
            'Your visit with '.($patient?->name ?? 'a patient')." starts at {$time}.",
            $target + ['for' => 'doctor'],
        ));
    }
}
