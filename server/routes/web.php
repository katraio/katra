<?php

use App\Http\Controllers\Auth\OrganizationInvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Katra Server',
        'documentation' => 'https://katra.io/docs/',
    ]);
});

Route::post('/auth/invitations/inspect', [OrganizationInvitationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('auth.invitations.show');

Route::post('/auth/invitations/accept', [OrganizationInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('auth.invitations.accept');
