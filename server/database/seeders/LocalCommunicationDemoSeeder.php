<?php

namespace Database\Seeders;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\ConversationMessageService;
use App\Conversations\DirectMessageService;
use App\Enums\ChannelVisibility;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationKind;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

final class LocalCommunicationDemoSeeder extends Seeder
{
    private const DEMO_EMAIL = 'katra-demo-teammate@localhost.invalid';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Local communication demo data is unavailable outside local development.');
        }

        $this->call(AuthorizationSeeder::class);

        $owner = User::query()->where('email', '!=', self::DEMO_EMAIL)->sole();
        $owner->assign(SystemRole::GlobalAdministrator->value);

        $organization = Organization::query()
            ->where('kind', OrganizationKind::Operating->value)
            ->first();

        if ($organization === null) {
            $organization = Organization::query()->create([
                'name' => 'DevOption',
                'slug' => 'devoption',
                'kind' => OrganizationKind::Operating,
                'created_by_user_id' => $owner->getKey(),
            ]);
        }

        $teammate = User::query()->firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'first_name' => 'Katra Demo',
                'last_name' => 'Teammate',
                'password' => Str::random(64),
            ],
        );

        if ($teammate->email_verified_at === null) {
            $teammate->forceFill(['email_verified_at' => now()])->save();
        }

        foreach ([$owner, $teammate] as $member) {
            OrganizationMembership::query()->firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $member->getKey(),
                ],
                [
                    'kind' => MembershipKind::Internal,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                    'created_by_user_id' => $owner->getKey(),
                ],
            );

            app(OrganizationAuthorization::class)->assign(
                $member,
                $organization,
                OrganizationRole::InternalMember,
            );
        }

        $channel = Channel::query()
            ->where('organization_id', $organization->getKey())
            ->where('slug', 'general')
            ->with('conversation')
            ->first();

        if ($channel === null) {
            $channel = app(ChannelService::class)->createInternal(
                $organization,
                $owner,
                'General',
                ChannelVisibility::Public,
            );
        }

        app(ChannelService::class)->join($channel, $teammate);

        $messages = app(ConversationMessageService::class);
        $welcome = $messages->send(
            $owner,
            $channel->conversation->public_id,
            'This Channel is connected to Katra Server. Messages here are stored in PostgreSQL and remain after a browser refresh.',
            'local-demo-channel-welcome-v1',
        );
        $messages->send(
            $teammate,
            $channel->conversation->public_id,
            'Thread replies are persisted too. Open this reply and add one of your own to verify the complete flow.',
            'local-demo-channel-thread-v1',
            $welcome->public_id,
        );

        $directMessage = app(DirectMessageService::class)->create(
            $organization,
            $owner,
            [$teammate],
        );
        $messages->send(
            $teammate,
            $directMessage->conversation->public_id,
            'This is a participant-private Direct Message backed by Laravel. Only the people in this conversation can read it.',
            'local-demo-direct-message-welcome-v1',
        );
    }
}
