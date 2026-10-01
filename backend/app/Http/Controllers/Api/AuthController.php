<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customers and staff sign in the same way; a Sanctum token is returned and sent back as
 * a bearer token. The app keeps it in the device's secure storage.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'phone', 'password']));

        return $this->signedIn($user, $request->string('device_name')->toString(), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        // Hash something even when there's no such account, so the response time doesn't
        // reveal which emails are registered.
        $passwordMatches = Hash::check(
            $request->string('password')->toString(),
            $user !== null ? $user->password : Hash::make(Str::random(32)),
        );

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        return $this->signedIn($user, $request->string('device_name')->toString());
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function signedIn(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load('memberships.restaurant')),
            ],
        ], $status);
    }
}
