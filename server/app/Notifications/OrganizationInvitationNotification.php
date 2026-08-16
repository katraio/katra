<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use App\Organizations\OrganizationInvitationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class OrganizationInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly OrganizationInvitation $invitation,
        public readonly string $tokenHash,
        private readonly string $acceptanceUrl,
    ) {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $invitation = $this->invitation->fresh();

        if (
            $invitation === null
            || ! hash_equals($invitation->token_hash, $this->tokenHash)
            || ! $invitation->isAcceptable()
        ) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing('organization');

        return (new MailMessage)
            ->subject("Invitation to {$invitation->organization->name} in Katra")
            ->greeting('You have been invited to Katra')
            ->line("You were invited to {$invitation->organization->name} as {$invitation->role->label()}.")
            ->action('Accept invitation', $this->acceptanceUrl)
            ->line('This single-use invitation expires in seven days.');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'organization-invitation:'.$this->invitation->public_id,
            'organization:'.$this->invitation->organization->public_id,
        ];
    }

    public function failed(Throwable $exception): void
    {
        app(OrganizationInvitationDelivery::class)->markFailed(
            $this->invitation,
            $this->tokenHash,
        );
    }
}
