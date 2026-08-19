<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        // Added: these are credential-equivalent and were being flashed back into
        // the session on any validation failure.
        'new_password',
        'new_password_confirmation',
        'token',
        'id_token',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        /*
         * Single JSON error contract for the API.
         *
         * Before this, app/Exceptions/Handler.php was the untouched Laravel stub, so
         * an API error rendered however the framework happened to render it. With the
         * shipped APP_DEBUG=true that meant any 500 returned the exception message,
         * the absolute file path, the line number and the full stack trace to the
         * caller — reachable unauthenticated, because POST /verify-email had no
         * validation and dereferenced missing request keys directly.
         *
         * Every API error now looks the same:
         *
         *     { "message": "<safe, human-readable>", "code": "<machine-readable>" }
         *
         * plus "errors" for 422 and a "ref" correlation id for 500s. `code` is what
         * the storefront branches on — e.g. insufficient_credits renders the user's
         * balance and an "Add credits" link instead of a bare toast.
         */
        /*
         * A delete blocked by a foreign key, shown as a sentence instead of a stack trace.
         *
         * The new ON DELETE RESTRICT constraints are deliberate — deleting a product
         * that has orders would orphan those orders and silently drop them out of the
         * CMS profit query, which INNER JOINs products_variations. But an admin
         * clicking Delete should be told "this has orders attached", not shown
         * SQLSTATE[23000] 1451.
         *
         * Handled here rather than by overriding CmsPageController::destroy, because
         * the vendor registers a delete route per CMS page and this covers all of them
         * at once.
         */
        $this->renderable(function (QueryException $e, Request $request) {
            if ($this->wantsApiResponse($request) || ($e->errorInfo[1] ?? null) !== 1451) {
                return null;
            }

            Log::warning('CMS delete blocked by a foreign key', [
                'url' => $request->fullUrl(),
                'message' => $e->getMessage(),
            ]);

            // Flashed as `success` on purpose: the vendor's dashboard layout renders
            // Session::get('success') inside the toast and only checks for the
            // presence of `error`, so flashing `error` alone shows an empty box.
            return back()->with([
                'error' => true,
                'success' => 'This record cannot be deleted because other records still reference it '
                    . '(for example an order that uses this product). Deactivate it instead, or remove '
                    . 'the records that depend on it first.',
            ]);
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if (!$this->wantsApiResponse($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], $e->status),

                $e instanceof AuthenticationException => response()->json([
                    'message' => __('auth.unauthenticated'),
                    'code' => 'unauthenticated',
                ], Response::HTTP_UNAUTHORIZED),

                $e instanceof AuthorizationException => response()->json([
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                    'code' => 'forbidden',
                ], Response::HTTP_FORBIDDEN),

                // findOrFail / firstOrFail are used throughout the controllers and
                // previously surfaced as a raw framework 404 page.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => response()->json([
                    'message' => 'The requested resource was not found.',
                    'code' => 'not_found',
                ], Response::HTTP_NOT_FOUND),

                $e instanceof TooManyRequestsHttpException => response()->json([
                    'message' => 'Too many attempts. Please wait a moment and try again.',
                    'code' => 'rate_limited',
                ], Response::HTTP_TOO_MANY_REQUESTS),

                // abort(403) / abort(503, '…') and friends. The message on these is
                // author-written, so it is safe to pass through.
                $e instanceof HttpExceptionInterface => response()->json([
                    'message' => $e->getMessage() ?: 'Request failed.',
                    'code' => 'http_error',
                ], $e->getStatusCode()),

                default => $this->renderUnexpected($e),
            };
        });
    }

    /**
     * Unhandled exceptions: log with a correlation id, return that id, and say
     * nothing else. The id is what makes a user report supportable ("error ref
     * 9f3c…") without handing internals to the caller.
     *
     * The exception message is echoed back only when APP_DEBUG is on, so local
     * development still shows the cause.
     */
    protected function renderUnexpected(Throwable $e)
    {
        $ref = (string) Str::uuid();

        Log::error('Unhandled API exception', [
            'ref' => $ref,
            'exception' => $e,
        ]);

        $payload = [
            'message' => 'Something went wrong on our side. Please try again.',
            'code' => 'server_error',
            'ref' => $ref,
        ];

        if (config('app.debug')) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return response()->json($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Scoped to the API by path only.
     *
     * Deliberately NOT `|| $request->expectsJson()`: the CMS makes its own AJAX
     * calls, and re-shaping their error responses would change behaviour inside the
     * vendor package. The CMS keeps its normal Blade error pages and redirects.
     */
    protected function wantsApiResponse(Request $request): bool
    {
        return $request->is('api/*');
    }
}
