<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Single place that produces the response envelope required by section 7.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        ?array $meta = null,
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(self::body($data, $message, $meta), $status, $headers);
    }

    /**
     * Builds the success envelope as a plain array (used by the idempotency layer to store & replay responses).
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    public static function body(mixed $data = null, string $message = 'OK', ?array $meta = null): array
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        $body = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $body;
    }

    /**
     * @param  LengthAwarePaginator<int, \Illuminate\Database\Eloquent\Model>  $paginator
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'OK'): JsonResponse
    {
        /** @var ResourceCollection $collection */
        $collection = $resourceClass::collection($paginator->getCollection());

        return self::success(
            $collection->resolve(request()),
            $message,
            200,
            [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        );
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, $headers);
    }
}
