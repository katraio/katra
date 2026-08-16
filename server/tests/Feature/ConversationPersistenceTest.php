<?php

namespace Tests\Feature;

use App\Conversations\ConversationMessageWriter;
use App\Conversations\ConversationReadState;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\DirectMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_uses_an_opaque_conversation_identity_and_constrained_visibility(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization,
            'created_by_user_id' => $creator,
        ]);
        $channel = Channel::factory()->create([
            'conversation_id' => $conversation,
            'organization_id' => $organization,
            'name' => 'Announcements',
            'slug' => 'announcements',
            'visibility' => ChannelVisibility::Public,
        ]);

        $this->assertSame(ConversationType::Channel, $conversation->type);
        $this->assertSame(ChannelVisibility::Public, $channel->visibility);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $conversation->public_id);
        $this->assertSame($organization->getKey(), $channel->organization_id);
    }

    public function test_an_organization_can_have_only_one_client_team_channel(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();

        $this->createChannel($organization, $creator, 'team', ChannelVisibility::ClientTeam);

        $this->expectException(QueryException::class);

        $this->createChannel($organization, $creator, 'another-team', ChannelVisibility::ClientTeam);
    }

    public function test_conversation_subtype_rows_must_match_the_base_conversation_type(): void
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->create();
        $conversation = Conversation::factory()->directMessage()->create([
            'organization_id' => $organization,
            'created_by_user_id' => $creator,
        ]);

        $this->expectException(QueryException::class);

        Channel::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => 'Invalid',
            'slug' => 'invalid',
            'visibility' => ChannelVisibility::Private,
        ]);
    }

    public function test_direct_message_participant_sets_are_canonical_and_unique_per_organization(): void
    {
        $organization = Organization::factory()->create();
        $participants = User::factory()->count(3)->create()->sortBy('id')->values();

        $this->createDirectMessage($organization, $participants->all());

        $this->expectException(QueryException::class);

        $this->createDirectMessage($organization, $participants->reverse()->values()->all());
    }

    public function test_direct_message_factory_creates_structural_participants_and_initial_transition(): void
    {
        $directMessage = DirectMessage::factory()->create();

        $this->assertCount(2, $directMessage->participants()->get());
        $this->assertCount(1, $directMessage->transitions()->get());
        $this->assertDatabaseCount('conversation_memberships', 2);
    }

    public function test_message_writer_allocates_sequences_and_replays_an_identical_idempotent_command(): void
    {
        $author = User::factory()->create();
        $mentioned = User::factory()->create();
        $attentionTarget = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $writer = app(ConversationMessageWriter::class);

        $first = $writer->append(
            $conversation,
            $author,
            '  Hello from Katra.  ',
            'message-command-1',
            mentionedUsers: [$mentioned, $mentioned],
            attentionUsers: [$attentionTarget, $attentionTarget],
        );
        $replayed = $writer->append(
            $conversation,
            $author,
            'Hello from Katra.',
            'message-command-1',
            mentionedUsers: [$mentioned],
            attentionUsers: [$attentionTarget],
        );
        $second = $writer->append(
            $conversation,
            $author,
            'Second message.',
            'message-command-2',
        );

        $this->assertTrue($first->is($replayed));
        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(3, $conversation->fresh()->next_message_sequence);
        $this->assertCount(1, $first->mentions);
        $this->assertCount(1, $first->attentionTargets);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_reusing_an_idempotency_key_for_different_content_fails(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $writer = app(ConversationMessageWriter::class);

        $writer->append($conversation, $author, 'Original.', 'same-command');

        $this->expectException(ValidationException::class);

        $writer->append($conversation, $author, 'Changed.', 'same-command');
    }

    public function test_threads_allow_replies_to_roots_but_reject_nested_replies(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $writer = app(ConversationMessageWriter::class);
        $root = $writer->append($conversation, $author, 'Root.', 'root-command');
        $reply = $writer->append($conversation, $author, 'Reply.', 'reply-command', $root);

        $this->assertTrue($reply->parent->is($root));

        $this->expectException(ValidationException::class);

        $writer->append($conversation, $author, 'Nested.', 'nested-command', $reply);
    }

    public function test_persisted_messages_cannot_be_updated(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $message = app(ConversationMessageWriter::class)
            ->append($conversation, $author, 'Immutable.', 'immutable-command');

        $this->expectException(QueryException::class);

        $message->forceFill(['body' => 'Changed.'])->save();
    }

    public function test_persisted_messages_cannot_be_deleted(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $message = app(ConversationMessageWriter::class)
            ->append($conversation, $author, 'Immutable.', 'immutable-delete-command');

        $this->expectException(QueryException::class);

        $message->delete();
    }

    public function test_persisted_message_revisions_cannot_be_updated(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $message = app(ConversationMessageWriter::class)
            ->append($conversation, $author, 'Original.', 'immutable-revision-update');
        $revision = $message->revisions()->create([
            'actor_user_id' => $author->getKey(),
            'sequence' => 1,
            'operation' => 'edit',
            'body' => 'Corrected.',
        ]);

        $this->expectException(QueryException::class);

        $revision->forceFill(['body' => 'Rewritten.'])->save();
    }

    public function test_persisted_message_revisions_cannot_be_deleted(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $message = app(ConversationMessageWriter::class)
            ->append($conversation, $author, 'Original.', 'immutable-revision-delete');
        $revision = $message->revisions()->create([
            'actor_user_id' => $author->getKey(),
            'sequence' => 1,
            'operation' => 'delete',
            'body' => null,
        ]);

        $this->expectException(QueryException::class);

        $revision->delete();
    }

    public function test_reactions_are_idempotent_per_user_message_and_kind(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $message = app(ConversationMessageWriter::class)
            ->append($conversation, $author, 'React to this.', 'reaction-command');

        $message->reactions()->create(['user_id' => $author->getKey(), 'kind' => 'approve']);

        $this->expectException(QueryException::class);

        $message->reactions()->create(['user_id' => $author->getKey(), 'kind' => 'approve']);
    }

    public function test_read_position_advances_monotonically_and_not_beyond_the_conversation(): void
    {
        $author = User::factory()->create();
        $conversation = Conversation::factory()->create(['created_by_user_id' => $author]);
        $membership = ConversationMembership::factory()->create([
            'conversation_id' => $conversation,
            'user_id' => $author,
        ]);
        $writer = app(ConversationMessageWriter::class);
        $writer->append($conversation, $author, 'One.', 'read-command-1');
        $writer->append($conversation, $author, 'Two.', 'read-command-2');
        $readState = app(ConversationReadState::class);

        $readState->advance($membership, 2);
        $readState->advance($membership, 1);

        $this->assertSame(2, $membership->fresh()->last_read_sequence);

        $this->expectException(ValidationException::class);

        $readState->advance($membership, 3);
    }

    private function createChannel(
        Organization $organization,
        User $creator,
        string $slug,
        ChannelVisibility $visibility,
    ): Channel {
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization,
            'created_by_user_id' => $creator,
        ]);

        return Channel::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'visibility' => $visibility,
        ]);
    }

    private function createDirectMessage(
        Organization $organization,
        array $participants,
    ): DirectMessage {
        $participants = collect($participants)->sortBy('id')->values();
        $initiator = $participants->first();
        $conversation = Conversation::factory()->directMessage()->create([
            'organization_id' => $organization,
            'created_by_user_id' => $initiator,
        ]);

        $directMessage = DirectMessage::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'participant_set_hash' => md5($participants->pluck('id')->implode(',')),
            'initiated_by_user_id' => $initiator->getKey(),
            'internal_owner_user_id' => $initiator->getKey(),
        ]);

        foreach ($participants as $participant) {
            $directMessage->participantRecords()->create(['user_id' => $participant->getKey()]);
        }

        return $directMessage;
    }
}
