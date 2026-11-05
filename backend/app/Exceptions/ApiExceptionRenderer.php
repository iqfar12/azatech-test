<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps every exception to the standard envelope:
 * { "success": false, "message": "...", "errors": {...} }.
 *
 * In production (APP_DEBUG=false) a 500 never exposes the exception message,
 * class, file or stack trace — only a generic message and the request id so the
 * error can be found in the logs.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): Response
    {
        return match (true) {
            $e instanceof HttpResponseException => $e->getResponse(),
            $e instanceof ValidationException => ApiResponse::error(
                'Data yang dikirim tidak valid',
                422,
                $e->errors(),
            ),
            $e instanceof BusinessRuleException => ApiResponse::error(
                $e->getMessage(),
                $e->status(),
                $e->errors(),
                $e->headers(),
            ),
            $e instanceof AuthenticationException => ApiResponse::error('Tidak terautentikasi', 401),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error('Anda tidak memiliki akses untuk aksi ini', 403),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error('Data tidak ditemukan', 404),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error('Method tidak diizinkan', 405),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Terlalu banyak request, coba lagi nanti',
                429,
                [],
                array_map('strval', $e->getHeaders()),
            ),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Request tidak dapat diproses',
                $e->getStatusCode(),
                [],
                array_map('strval', $e->getHeaders()),
            ),
            default => $this->serverError($e),
        };
    }

    private function serverError(Throwable $e): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => 'Terjadi kesalahan pada server',
            'request_id' => Context::get('request_id'),
        ];

        if (config('app.debug') === true) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return new JsonResponse($body, 500);
    }
}
