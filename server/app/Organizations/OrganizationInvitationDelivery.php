<?php

namespace App\Organizations;

use App\Enums\OrganizationInvitationDeliveryStatus;
use App\Enums\OrganizationInvitationEventKind;
use App\Models\OrganizationInvitation;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class OrganizationInvitationDelivery
{
    public function dispatch(IssuedOrganizationInvitation $issued): void
    {
        if (! $this->hasDeliveringTransport()) {
            $this->markDelivery(
                $issued->invitation,
                $issued->invitation->token_hash,
                OrganizationInvitationDeliveryStatus::CopyLinkOnly,
                OrganizationInvitationEventKind::DeliverySkipped,
            );

            return;
        }

        $this->markDelivery(
            $issued->invitation,
            $issued->invitation->token_hash,
            OrganizationInvitationDeliveryStatus::Queued,
            OrganizationInvitationEventKind::DeliveryQueued,
        );

        try {
            Notification::route('mail', $issued->invitation->email)->notify(
                new OrganizationInvitationNotification(
                    $issued->invitation,
                    $issued->invitation->token_hash,
                    $issued->acceptanceUrl,
                ),
            );
        } catch (Throwable $exception) {
            $this->markFailed($issued->invitation, $issued->invitation->token_hash);
            report($exception);
        }
    }

    public function markSent(OrganizationInvitation $invitation, string $tokenHash): void
    {
        $this->markDelivery(
            $invitation,
            $tokenHash,
            OrganizationInvitationDeliveryStatus::Sent,
            OrganizationInvitationEventKind::DeliverySent,
        );
    }

    public function markFailed(OrganizationInvitation $invitation, string $tokenHash): void
    {
        $this->markDelivery(
            $invitation,
            $tokenHash,
            OrganizationInvitationDeliveryStatus::Failed,
            OrganizationInvitationEventKind::DeliveryFailed,
        );
    }

    public function hasDeliveringTransport(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        return ! in_array($transport, ['array', 'log'], true);
    }

    private function markDelivery(
        OrganizationInvitation $invitation,
        string $tokenHash,
        OrganizationInvitationDeliveryStatus $status,
        OrganizationInvitationEventKind $eventKind,
    ): void {
        DB::transaction(function () use ($invitation, $tokenHash, $status, $eventKind): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->find($invitation->getKey());

            if ($locked === null || ! hash_equals($locked->token_hash, $tokenHash)) {
                return;
            }

            $values = [
                'last_delivery_status' => $status,
                'last_delivery_at' => now(),
            ];

            if ($status === OrganizationInvitationDeliveryStatus::Sent) {
                $values['last_sent_at'] = now();
            }

            $locked->forceFill($values)->save();
            $locked->events()->create(['kind' => $eventKind]);
        });

        $invitation->refresh();
    }
}
