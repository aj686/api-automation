<?php

use App\Http\Middleware\AutomationToken;
use App\Http\Middleware\RememberWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/capture.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['automation.token' => AutomationToken::class]);
        $middleware->web(append: [RememberWorkspace::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Machine endpoints have no login in front of them, so they never get a
        // stack trace, file path or class name, whatever APP_DEBUG says
        // (decisions.md D-029). Validation errors keep Laravel's 422 format.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*', 'capture/*')
                || $e instanceof ValidationException || $e instanceof HttpResponseException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json(
                ['message' => SymfonyResponse::$statusTexts[$status] ?? 'Error'],
                $status,
                $e instanceof HttpExceptionInterface ? $e->getHeaders() : [],
            );
        });
    })->create();
