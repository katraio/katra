<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The production PHP-FPM service is reachable only through Katra's
        // private reverse-proxy network, so forwarded scheme and client IP
        // headers can be trusted here without trusting arbitrary public hops.
        $middleware->trustProxies(at: '*');

        $middleware->statefulApi();
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (
                ! $request->is('auth/*')
                || $exception instanceof ValidationException
                || $exception instanceof HttpExceptionInterface
            ) {
                return null;
            }

            return response()->json([
                'message' => 'Katra Server could not complete the request. Please try again.',
            ], 500);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->expectsJson() || $request->is('api/*'),
        );
    })->create();
