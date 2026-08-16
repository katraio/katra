<?php

namespace App\Providers;

use App\Listeners\MarkMeetingInvitationSent;
use App\Listeners\MarkOrganizationInvitationSent;
use App\Meetings\MeetingGuestAccess;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Pulse\Facades\Pulse;
use Silber\Bouncer\BouncerFacade as Bouncer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12));

        Bouncer::scope()
            ->onlyRelations()
            ->dontScopeRoleAbilities();

        Gate::before(
            fn (User $user): ?bool => $user->isGlobalAdministrator() ? true : null,
        );

        Auth::viaRequest(
            'meeting-guest',
            fn (Request $request) => app(MeetingGuestAccess::class)->resolveSessionToken($request->bearerToken()),
        );
        RateLimiter::for('meeting-guest-inspection', fn (Request $request): array => [
            Limit::perMinute(30)->by('inspection-ip|'.$request->ip()),
            Limit::perMinute(60)->by('inspection-target|'.$this->meetingGuestTarget($request)),
        ]);
        RateLimiter::for('meeting-guest-admission', fn (Request $request): array => [
            Limit::perMinute(10)->by('admission-ip|'.$request->ip()),
            Limit::perMinute(40)->by('admission-target|'.$this->meetingGuestTarget($request)),
        ]);
        RateLimiter::for('meeting-guest-writes', fn (Request $request): array => [
            Limit::perMinute(120)->by('guest-write-ip|'.$request->ip()),
            Limit::perMinute(60)->by(
                'guest-write-session|'.hash('sha256', (string) $request->bearerToken()),
            ),
        ]);
        RateLimiter::for('conversation-writes', fn (Request $request): array => [
            Limit::perMinute(120)->by('conversation-write-ip|'.$request->ip()),
            Limit::perMinute(60)->by(
                'conversation-write-user|'.($request->user()?->getAuthIdentifier() ?? 'guest'),
            ),
        ]);
        RateLimiter::for('account-profile', fn (Request $request) => Limit::perMinute(30)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('account-password', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('organization-invitations', fn (Request $request) => Limit::perMinute(20)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        RateLimiter::for('organization-administration', fn (Request $request) => Limit::perMinute(20)->by(
            ($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip(),
        ));
        Event::listen(NotificationSent::class, MarkMeetingInvitationSent::class);
        Event::listen(NotificationSent::class, MarkOrganizationInvitationSent::class);

        Pulse::user(fn ($user) => [
            'name' => $user->name,
            'extra' => $user->email,
            'avatar' => null,
        ]);
    }

    private function meetingGuestTarget(Request $request): string
    {
        return (string) ($request->route('meeting') ?? $request->route('invitation') ?? 'unknown');
    }
}
