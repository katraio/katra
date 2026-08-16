<x-mail::message>
# You're invited to a Katra meeting

{{ $organizerName }} invited you to join a meeting for {{ $organizationName }}.

<x-mail::panel>
<span class="meeting-label">MEETING</span>

## {{ $meetingTitle }}

**When** &nbsp; {{ $startsAt }}<br>
**Duration** &nbsp; {{ $duration }} minutes
</x-mail::panel>

<x-mail::button :url="$actionUrl" color="primary">
Open meeting
</x-mail::button>

You can join without creating a Katra account.

<span class="privacy-note">This invitation is specific to your email address and expires when the meeting is scheduled to end. Please do not forward it.</span>

<x-slot:subcopy>
If the button does not open Katra, copy and paste this private invitation URL into your browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
