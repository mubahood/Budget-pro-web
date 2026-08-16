<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // All /api/* responses are JSON, in the standard { code, message, data, errors } envelope.
        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $this->renderApiException($e, $request);
            }

            return null;
        });
    }

    /**
     * Render an exception into the standard API envelope with a correct HTTP status code,
     * never leaking internal details to the client (details are logged instead).
     */
    protected function renderApiException(Throwable $e, Request $request)
    {
        [$status, $message, $errors] = $this->mapApiException($e);

        $payload = [
            'code' => 0,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        // In debug mode, attach diagnostic detail for developers (never in production).
        if (config('app.debug') && $status >= 500) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return response()->json($payload, $status);
    }

    /**
     * Map a throwable to [http status, safe client message, errors|null].
     *
     * @return array{0:int,1:string,2:mixed}
     */
    protected function mapApiException(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [422, 'The given data was invalid.', $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'Unauthenticated.', null];
        }

        if ($e instanceof AuthorizationException) {
            return [403, 'You are not allowed to perform this action.', null];
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return [404, 'Resource not found.', null];
        }

        if ($e instanceof ThrottleRequestsException) {
            return [429, 'Too many requests. Please slow down and try again shortly.', null];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: 'Request failed.';

            return [$status, $message, null];
        }

        // Unhandled server error — log the detail, return a generic message.
        return [500, 'A server error occurred. Please try again later.', null];
    }
}
