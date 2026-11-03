<?php

namespace Hypermed\Messaging\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The single response envelope used by every messaging endpoint:
 *   success: {"data": ..., "meta": {...}?}
 *   error:   {"message": "...", "code": "snake_code", "errors": {...}?}
 */
final class ApiResponse
{
    public static function success(mixed $data, int $status = 200, ?array $meta = null, array $headers = []): JsonResponse
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status, $headers);
    }

    public static function created(mixed $data, ?array $meta = null): JsonResponse
    {
        return self::success($data, 201, $meta);
    }

    public static function error(string $message, string $code, int $status, ?array $errors = null, array $headers = []): JsonResponse
    {
        $payload = ['message' => $message, 'code' => $code];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status, $headers);
    }
}
