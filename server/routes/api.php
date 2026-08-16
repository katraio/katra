<?php

use App\Http\Controllers\Api\V1\Attention\AttentionIndexController;
use App\Http\Controllers\Api\V1\Attention\AttentionResolveController;
use App\Http\Controllers\Api\V1\Attention\AttentionViewedController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Channels\ChannelArchiveController;
use App\Http\Controllers\Api\V1\Channels\ChannelCreateController;
use App\Http\Controllers\Api\V1\Channels\ChannelFavoriteDestroyController;
use App\Http\Controllers\Api\V1\Channels\ChannelFavoriteStoreController;
use App\Http\Controllers\Api\V1\Channels\ChannelIndexController;
use App\Http\Controllers\Api\V1\Channels\ChannelJoinController;
use App\Http\Controllers\Api\V1\Channels\ChannelLeaveController;
use App\Http\Controllers\Api\V1\Channels\ChannelMemberCandidateIndexController;
use App\Http\Controllers\Api\V1\Channels\ChannelMemberDestroyController;
use App\Http\Controllers\Api\V1\Channels\ChannelMemberIndexController;
use App\Http\Controllers\Api\V1\Channels\ChannelMemberStoreController;
use App\Http\Controllers\Api\V1\Channels\ChannelOwnerDestroyController;
use App\Http\Controllers\Api\V1\Channels\ChannelOwnerStoreController;
use App\Http\Controllers\Api\V1\Channels\ChannelShowController;
use App\Http\Controllers\Api\V1\Channels\ChannelUpdateController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageCandidateIndexController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageCompleteController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageContinuationController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageCreateController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageFavoriteDestroyController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageFavoriteStoreController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageIndexController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageReopenController;
use App\Http\Controllers\Api\V1\DirectMessages\DirectMessageShowController;
use App\Http\Controllers\Api\V1\MeetingGuests\EmailMeetingAdmissionController;
use App\Http\Controllers\Api\V1\MeetingGuests\EmailMeetingInspectController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingAdmissionController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingInspectController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingMediaCredentialController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingMessageController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingMessageReactionController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingOutcomeController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingRoomReactionController;
use App\Http\Controllers\Api\V1\MeetingGuests\GuestMeetingSessionController;
use App\Http\Controllers\Api\V1\Meetings\ConversationMeetingController;
use App\Http\Controllers\Api\V1\Meetings\MeetingCalendarController;
use App\Http\Controllers\Api\V1\Meetings\MeetingCandidateIndexController;
use App\Http\Controllers\Api\V1\Meetings\MeetingCreateController;
use App\Http\Controllers\Api\V1\Meetings\MeetingGuestLinkController;
use App\Http\Controllers\Api\V1\Meetings\MeetingIndexController;
use App\Http\Controllers\Api\V1\Meetings\MeetingInstantCreateController;
use App\Http\Controllers\Api\V1\Meetings\MeetingInvitationController;
use App\Http\Controllers\Api\V1\Meetings\MeetingMediaCredentialController;
use App\Http\Controllers\Api\V1\Meetings\MeetingMessageIndexController;
use App\Http\Controllers\Api\V1\Meetings\MeetingMessageReactionDestroyController;
use App\Http\Controllers\Api\V1\Meetings\MeetingMessageReactionStoreController;
use App\Http\Controllers\Api\V1\Meetings\MeetingMessageStoreController;
use App\Http\Controllers\Api\V1\Meetings\MeetingOutcomeIndexController;
use App\Http\Controllers\Api\V1\Meetings\MeetingOutcomeStoreController;
use App\Http\Controllers\Api\V1\Meetings\MeetingParticipantRemoveController;
use App\Http\Controllers\Api\V1\Meetings\MeetingParticipantStoreController;
use App\Http\Controllers\Api\V1\Meetings\MeetingRoomCommandController;
use App\Http\Controllers\Api\V1\Meetings\MeetingRoomReactionStoreController;
use App\Http\Controllers\Api\V1\Meetings\MeetingShowController;
use App\Http\Controllers\Api\V1\MemberAdministration\MemberAdministrationInvitationIndexController;
use App\Http\Controllers\Api\V1\MemberAdministration\MemberAdministrationMemberIndexController;
use App\Http\Controllers\Api\V1\MemberAdministration\MemberAdministrationScopeIndexController;
use App\Http\Controllers\Api\V1\Messages\ConversationMentionableUserIndexController;
use App\Http\Controllers\Api\V1\Messages\ConversationMessageCreateController;
use App\Http\Controllers\Api\V1\Messages\ConversationMessageDestroyController;
use App\Http\Controllers\Api\V1\Messages\ConversationMessageIndexController;
use App\Http\Controllers\Api\V1\Messages\ConversationMessageUpdateController;
use App\Http\Controllers\Api\V1\Messages\ConversationReadPositionUpdateController;
use App\Http\Controllers\Api\V1\Messages\MessageReactionDestroyController;
use App\Http\Controllers\Api\V1\Messages\MessageReactionStoreController;
use App\Http\Controllers\Api\V1\OrganizationAdministration\OrganizationAdministrationCreateController;
use App\Http\Controllers\Api\V1\OrganizationAdministration\OrganizationAdministrationIndexController;
use App\Http\Controllers\Api\V1\OrganizationAdministration\OrganizationAdministrationUpdateController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationIndexController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationInvitationCreateController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationInvitationReissueController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationInvitationRevokeController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationShowController;
use App\Http\Controllers\Api\V1\Profile\ProfilePasswordUpdateController;
use App\Http\Controllers\Api\V1\Profile\ProfileUpdateController;
use App\Http\Controllers\Api\V1\Search\CommunicationSearchController;
use App\Http\Controllers\Api\V1\SystemConnectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/system/connection', SystemConnectionController::class)
        ->name('api.v1.system.connection');

    Route::post('/meeting-guests/{meeting}/inspect', GuestMeetingInspectController::class)
        ->middleware('throttle:meeting-guest-inspection')
        ->whereUlid('meeting')
        ->name('api.v1.meeting-guests.inspect');
    Route::post('/meeting-guests/{meeting}/admit', GuestMeetingAdmissionController::class)
        ->middleware('throttle:meeting-guest-admission')
        ->whereUlid('meeting')
        ->name('api.v1.meeting-guests.admit');
    Route::post('/meeting-invitations/{invitation}/inspect', EmailMeetingInspectController::class)
        ->middleware('throttle:meeting-guest-inspection')
        ->whereUlid('invitation')
        ->name('api.v1.meeting-invitations.inspect');
    Route::post('/meeting-invitations/{invitation}/admit', EmailMeetingAdmissionController::class)
        ->middleware('throttle:meeting-guest-admission')
        ->whereUlid('invitation')
        ->name('api.v1.meeting-invitations.admit');

    Route::middleware('auth:meeting-guest')->prefix('meeting-guest')->group(function (): void {
        Route::get('/session', [GuestMeetingSessionController::class, 'show'])->name('api.v1.meeting-guest.session.show');
        Route::post('/join', [GuestMeetingSessionController::class, 'join'])
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.session.join');
        Route::post('/leave', [GuestMeetingSessionController::class, 'leave'])
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.session.leave');
        Route::post('/media-credential', GuestMeetingMediaCredentialController::class)
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.media-credential.store');
        Route::get('/outcomes', [GuestMeetingOutcomeController::class, 'index'])->name('api.v1.meeting-guest.outcomes.index');
        Route::post('/outcomes', [GuestMeetingOutcomeController::class, 'store'])
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.outcomes.store');
        Route::get('/messages', [GuestMeetingMessageController::class, 'index'])->name('api.v1.meeting-guest.messages.index');
        Route::post('/messages', [GuestMeetingMessageController::class, 'store'])
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.messages.store');
        Route::put('/messages/{message}/reactions', [GuestMeetingMessageReactionController::class, 'store'])
            ->middleware('throttle:meeting-guest-writes')
            ->whereUlid('message')->name('api.v1.meeting-guest.messages.reactions.store');
        Route::delete('/messages/{message}/reactions', [GuestMeetingMessageReactionController::class, 'destroy'])
            ->middleware('throttle:meeting-guest-writes')
            ->whereUlid('message')->name('api.v1.meeting-guest.messages.reactions.destroy');
        Route::post('/reactions', GuestMeetingRoomReactionController::class)
            ->middleware('throttle:meeting-guest-writes')
            ->name('api.v1.meeting-guest.reactions.store');
    });

    Route::get('/auth/user', CurrentUserController::class)
        ->middleware('auth:sanctum')
        ->name('api.v1.auth.user');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::patch('/profile', ProfileUpdateController::class)
            ->middleware('throttle:account-profile')
            ->name('api.v1.profile.update');

        Route::put('/profile/password', ProfilePasswordUpdateController::class)
            ->middleware('throttle:account-password')
            ->name('api.v1.profile.password.update');

        Route::get('/member-administration', MemberAdministrationScopeIndexController::class)
            ->name('api.v1.member-administration.index');

        Route::get('/organization-administration', OrganizationAdministrationIndexController::class)
            ->name('api.v1.organization-administration.index');

        Route::post('/organization-administration', OrganizationAdministrationCreateController::class)
            ->middleware('throttle:organization-administration')
            ->name('api.v1.organization-administration.store');

        Route::patch(
            '/organization-administration/{organization}',
            OrganizationAdministrationUpdateController::class,
        )
            ->middleware('throttle:organization-administration')
            ->whereUlid('organization')
            ->name('api.v1.organization-administration.update');

        Route::get(
            '/member-administration/{organization}/members',
            MemberAdministrationMemberIndexController::class,
        )
            ->whereUlid('organization')
            ->name('api.v1.member-administration.members.index');

        Route::get(
            '/member-administration/{organization}/invitations',
            MemberAdministrationInvitationIndexController::class,
        )
            ->whereUlid('organization')
            ->name('api.v1.member-administration.invitations.index');

        Route::get('/search/communications', CommunicationSearchController::class)
            ->name('api.v1.search.communications');

        Route::get('/attention', AttentionIndexController::class)
            ->name('api.v1.attention.index');

        Route::put('/attention/{attentionItem}/viewed', AttentionViewedController::class)
            ->whereUlid('attentionItem')
            ->name('api.v1.attention.viewed');

        Route::post('/attention/{attentionItem}/resolve', AttentionResolveController::class)
            ->whereUlid('attentionItem')
            ->name('api.v1.attention.resolve');

        Route::get('/organizations', OrganizationIndexController::class)
            ->name('api.v1.organizations.index');

        Route::get('/organizations/{organization}', OrganizationShowController::class)
            ->whereUlid('organization')
            ->name('api.v1.organizations.show');

        Route::get('/meetings', MeetingIndexController::class)
            ->name('api.v1.meetings.index');

        Route::get('/meetings/{meeting}', MeetingShowController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.show');

        Route::get('/meetings/{meeting}/calendar.ics', MeetingCalendarController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.calendar');

        Route::post('/meetings/{meeting}/guest-link/revoke', [MeetingGuestLinkController::class, 'revoke'])
            ->whereUlid('meeting')
            ->name('api.v1.meetings.guest-link.revoke');
        Route::post('/meetings/{meeting}/guest-link/regenerate', [MeetingGuestLinkController::class, 'regenerate'])
            ->whereUlid('meeting')
            ->name('api.v1.meetings.guest-link.regenerate');

        Route::post('/meetings/{meeting}/participants', MeetingParticipantStoreController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.participants.store');
        Route::post('/meetings/{meeting}/participants/{participant}/remove', MeetingParticipantRemoveController::class)
            ->whereUlid('meeting')->whereUlid('participant')
            ->name('api.v1.meetings.participants.remove');
        Route::post('/meetings/{meeting}/guest-invitations', [MeetingInvitationController::class, 'store'])
            ->whereUlid('meeting')
            ->name('api.v1.meetings.guest-invitations.store');
        Route::post('/meetings/{meeting}/guest-invitations/{invitation}/resend', [MeetingInvitationController::class, 'resend'])
            ->whereUlid('meeting')->whereUlid('invitation')
            ->name('api.v1.meetings.guest-invitations.resend');
        Route::delete('/meetings/{meeting}/guest-invitations/{invitation}', [MeetingInvitationController::class, 'revoke'])
            ->whereUlid('meeting')->whereUlid('invitation')
            ->name('api.v1.meetings.guest-invitations.revoke');

        Route::get('/meetings/{meeting}/outcomes', MeetingOutcomeIndexController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.outcomes.index');

        Route::post('/meetings/{meeting}/outcomes', MeetingOutcomeStoreController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.outcomes.store');

        Route::get('/meetings/{meeting}/messages', MeetingMessageIndexController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.messages.index');

        Route::post('/meetings/{meeting}/messages', MeetingMessageStoreController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.messages.store');

        Route::put(
            '/meetings/{meeting}/messages/{message}/reactions',
            MeetingMessageReactionStoreController::class,
        )
            ->whereUlid('meeting')
            ->whereUlid('message')
            ->name('api.v1.meetings.messages.reactions.store');

        Route::delete(
            '/meetings/{meeting}/messages/{message}/reactions',
            MeetingMessageReactionDestroyController::class,
        )
            ->whereUlid('meeting')
            ->whereUlid('message')
            ->name('api.v1.meetings.messages.reactions.destroy');

        Route::post('/meetings/{meeting}/reactions', MeetingRoomReactionStoreController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.reactions.store');

        Route::post('/meetings/{meeting}/media-credential', MeetingMediaCredentialController::class)
            ->whereUlid('meeting')
            ->name('api.v1.meetings.media-credential.store');

        Route::post('/meetings/{meeting}/{command}', MeetingRoomCommandController::class)
            ->whereUlid('meeting')
            ->whereIn('command', ['start', 'join', 'leave', 'end', 'cancel'])
            ->name('api.v1.meetings.room.command');

        Route::post('/conversations/{conversation}/meeting', ConversationMeetingController::class)
            ->whereUlid('conversation')
            ->name('api.v1.conversations.meeting.store');

        Route::post('/organizations/{organization}/meetings', MeetingCreateController::class)
            ->whereUlid('organization')
            ->name('api.v1.organizations.meetings.store');

        Route::post('/organizations/{organization}/meetings/instant', MeetingInstantCreateController::class)
            ->whereUlid('organization')
            ->name('api.v1.organizations.meetings.instant.store');

        Route::get(
            '/organizations/{organization}/meeting-candidates',
            MeetingCandidateIndexController::class,
        )
            ->whereUlid('organization')
            ->name('api.v1.organizations.meeting-candidates.index');

        Route::post('/organizations/{organization}/channels', ChannelCreateController::class)
            ->whereUlid('organization')
            ->name('api.v1.organizations.channels.store');

        Route::get('/channels', ChannelIndexController::class)
            ->name('api.v1.channels.index');

        Route::get('/channels/{channel}', ChannelShowController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.show');

        Route::patch('/channels/{channel}', ChannelUpdateController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.update');

        Route::post('/channels/{channel}/join', ChannelJoinController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.join');

        Route::get('/channels/{channel}/members', ChannelMemberIndexController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.members.index');

        Route::get('/channels/{channel}/member-candidates', ChannelMemberCandidateIndexController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.member-candidates.index');

        Route::post('/channels/{channel}/members', ChannelMemberStoreController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.members.store');

        Route::delete('/channels/{channel}/members/{member}', ChannelMemberDestroyController::class)
            ->whereUlid('channel')
            ->whereUlid('member')
            ->name('api.v1.channels.members.destroy');

        Route::put('/channels/{channel}/members/{member}/owner', ChannelOwnerStoreController::class)
            ->whereUlid('channel')
            ->whereUlid('member')
            ->name('api.v1.channels.members.owner.store');

        Route::delete('/channels/{channel}/members/{member}/owner', ChannelOwnerDestroyController::class)
            ->whereUlid('channel')
            ->whereUlid('member')
            ->name('api.v1.channels.members.owner.destroy');

        Route::put('/channels/{channel}/favorite', ChannelFavoriteStoreController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.favorite.store');

        Route::delete('/channels/{channel}/favorite', ChannelFavoriteDestroyController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.favorite.destroy');

        Route::delete('/channels/{channel}/membership', ChannelLeaveController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.leave');

        Route::post('/channels/{channel}/archive', ChannelArchiveController::class)
            ->whereUlid('channel')
            ->name('api.v1.channels.archive');

        Route::get('/direct-messages', DirectMessageIndexController::class)
            ->name('api.v1.direct-messages.index');

        Route::get('/direct-messages/{directMessage}', DirectMessageShowController::class)
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.show');

        Route::post('/organizations/{organization}/direct-messages', DirectMessageCreateController::class)
            ->whereUlid('organization')
            ->name('api.v1.organizations.direct-messages.store');

        Route::get(
            '/organizations/{organization}/direct-message-candidates',
            DirectMessageCandidateIndexController::class,
        )
            ->whereUlid('organization')
            ->name('api.v1.organizations.direct-message-candidates.index');

        Route::post('/direct-messages/{directMessage}/complete', DirectMessageCompleteController::class)
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.complete');

        Route::put('/direct-messages/{directMessage}/favorite', DirectMessageFavoriteStoreController::class)
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.favorite.store');

        Route::delete('/direct-messages/{directMessage}/favorite', DirectMessageFavoriteDestroyController::class)
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.favorite.destroy');

        Route::post(
            '/direct-messages/{directMessage}/continuation-requests',
            DirectMessageContinuationController::class,
        )
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.continuation-requests.store');

        Route::post('/direct-messages/{directMessage}/reopen', DirectMessageReopenController::class)
            ->whereUlid('directMessage')
            ->name('api.v1.direct-messages.reopen');

        Route::get('/conversations/{conversation}/messages', ConversationMessageIndexController::class)
            ->whereUlid('conversation')
            ->name('api.v1.conversations.messages.index');

        Route::get(
            '/conversations/{conversation}/mentionable-users',
            ConversationMentionableUserIndexController::class,
        )
            ->whereUlid('conversation')
            ->name('api.v1.conversations.mentionable-users.index');

        Route::post('/conversations/{conversation}/messages', ConversationMessageCreateController::class)
            ->middleware('throttle:conversation-writes')
            ->whereUlid('conversation')
            ->name('api.v1.conversations.messages.store');

        Route::patch(
            '/conversations/{conversation}/messages/{message}',
            ConversationMessageUpdateController::class,
        )
            ->middleware('throttle:conversation-writes')
            ->whereUlid('conversation')
            ->whereUlid('message')
            ->name('api.v1.conversations.messages.update');

        Route::delete(
            '/conversations/{conversation}/messages/{message}',
            ConversationMessageDestroyController::class,
        )
            ->middleware('throttle:conversation-writes')
            ->whereUlid('conversation')
            ->whereUlid('message')
            ->name('api.v1.conversations.messages.destroy');

        Route::put(
            '/conversations/{conversation}/messages/{message}/reactions',
            MessageReactionStoreController::class,
        )
            ->middleware('throttle:conversation-writes')
            ->whereUlid('conversation')
            ->whereUlid('message')
            ->name('api.v1.conversations.messages.reactions.store');

        Route::delete(
            '/conversations/{conversation}/messages/{message}/reactions',
            MessageReactionDestroyController::class,
        )
            ->middleware('throttle:conversation-writes')
            ->whereUlid('conversation')
            ->whereUlid('message')
            ->name('api.v1.conversations.messages.reactions.destroy');

        Route::put(
            '/conversations/{conversation}/read-position',
            ConversationReadPositionUpdateController::class,
        )
            ->whereUlid('conversation')
            ->name('api.v1.conversations.read-position.update');

        Route::post('/organizations/{organization}/invitations', OrganizationInvitationCreateController::class)
            ->middleware('throttle:organization-invitations')
            ->whereUlid('organization')
            ->name('api.v1.organizations.invitations.store');

        Route::post(
            '/organizations/{organization}/invitations/{invitation}/reissue',
            OrganizationInvitationReissueController::class,
        )
            ->middleware('throttle:organization-invitations')
            ->whereUlid('organization')
            ->whereUlid('invitation')
            ->name('api.v1.organizations.invitations.reissue');

        Route::delete(
            '/organizations/{organization}/invitations/{invitation}',
            OrganizationInvitationRevokeController::class,
        )
            ->whereUlid('organization')
            ->whereUlid('invitation')
            ->name('api.v1.organizations.invitations.destroy');
    });
});
