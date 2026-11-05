<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    /**
     * Login and receive a Sanctum bearer token.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) $request->validated('device_name', 'web'),
        );

        return ApiResponse::success([
            'token_type' => 'Bearer',
            'access_token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'user' => (new UserResource($result['user']))->resolve($request),
        ], 'Login berhasil');
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->actor($request));

        return ApiResponse::success(null, 'Logout berhasil');
    }

    /**
     * Current user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $token = $user->currentAccessToken();

        return ApiResponse::success([
            ...(new UserResource($user))->resolve($request),
            'token_expires_at' => $token instanceof PersonalAccessToken ? $token->expires_at?->toIso8601String() : null,
        ]);
    }
}
