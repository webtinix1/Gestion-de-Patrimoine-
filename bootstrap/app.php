<?php

use App\Http\Middleware\ApplySessionLifetime;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // RG-85, E41 : durée d'inactivité lue dans les paramètres, avant le démarrage de la session.
        $middleware->web(prepend: [ApplySessionLifetime::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // E44 : messages génériques en français pour les réponses JSON (le HTML a ses pages dans resources/views/errors).
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            $messages = [
                404 => 'Ressource introuvable.',
                419 => 'Votre session a expiré. Reconnectez-vous.',
                429 => 'Trop de demandes. Patientez avant de réessayer.',
                500 => 'Une erreur est survenue.',
                503 => 'Application en maintenance. Réessayez dans quelques minutes.',
            ];

            if ($request->expectsJson() && ! $request->hasHeader('X-Livewire') && isset($messages[$response->getStatusCode()])
                && ! config('app.debug')) {
                return response()->json(['message' => $messages[$response->getStatusCode()]], $response->getStatusCode(), array_filter(['Retry-After' => $response->headers->get('Retry-After')]));
            }

            return $response;
        });
    })->create();
