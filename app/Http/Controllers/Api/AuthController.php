<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Support\LegacyValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $data = $request->all();
            $fullName = trim((string) ($data['full_name'] ?? ''));
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $password = (string) ($data['password'] ?? '');

            if (! LegacyValidator::required($fullName)) {
                return ApiResponse::json(false, 'Full name is required.', [], 422);
            }
            if (! LegacyValidator::email($email)) {
                return ApiResponse::json(false, 'Valid email is required.', [], 422);
            }
            if (strlen($password) < 8) {
                return ApiResponse::json(false, 'Password must be at least 8 characters.', [], 422);
            }
            if (User::query()->where('email', $email)->exists()) {
                return ApiResponse::json(false, 'Email already registered.', [], 409);
            }

            $user = User::query()->create([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => Hash::make($password),
            ]);

            return ApiResponse::json(true, 'Registration successful.', ['user_id' => $user->id], 201);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Registration failed due to server error.', [], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $data = $request->all();
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $password = (string) ($data['password'] ?? '');

            if (! LegacyValidator::email($email) || ! LegacyValidator::required($password)) {
                return ApiResponse::json(false, 'Email and password are required.', [], 422);
            }

            if (! Auth::attempt(['email' => $email, 'password' => $password], false)) {
                return ApiResponse::json(false, 'Invalid credentials.', [], 401);
            }

            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();

            return ApiResponse::json(true, 'Login successful.', [
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                ],
            ]);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Login failed due to server error.', [], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return ApiResponse::json(true, 'Logout successful.', []);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Logout failed due to server error.', [], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            if (! Auth::check()) {
                return ApiResponse::json(false, 'Unauthorized.', [], 401);
            }

            /** @var User $user */
            $user = Auth::user();
            $data = [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $user->updated_at?->format('Y-m-d H:i:s'),
            ];

            return ApiResponse::json(true, 'Current user fetched.', $data);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to fetch current user.', [], 500);
        }
    }
}
