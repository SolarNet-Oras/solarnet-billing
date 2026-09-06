<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LegacyDefaultAdministrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $request->input('email')))])
            ->where('is_active', true)
            ->first();

        if ($user && ! LegacyDefaultAdministrator::isReservedEmail($user->email)) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        // Do not reveal whether a staff email exists or whether it is disabled.
        return response()->json([
            'status' => 'success',
            'message' => 'If that active staff email exists, a password-reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])->first();
        if (! $user || ! $user->is_active || LegacyDefaultAdministrator::isReservedEmail($data['email'])) {
            return response()->json(['status' => 'error', 'message' => 'This password-reset link is invalid or expired.'], 422);
        }

        $status = Password::broker()->reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['status' => 'error', 'message' => 'This password-reset link is invalid or expired.'], 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Password reset successfully. You may now sign in.']);
    }

    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (LegacyDefaultAdministrator::isReservedEmail($request->input('email'))) {
            return response()->json([
                'status' => 'error',
                'message' => 'This legacy account is retired. Sign in with your assigned staff account.',
            ], 403);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'is_active' => true,
        ]);

        // Assign default 'customer' role for public registration
        $user->assignRole('customer');

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'roles' => $user->roles->pluck('name'),
                ],
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ],
        ], 201);
    }

    /**
     * Login user and return JWT token
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (LegacyDefaultAdministrator::isReservedEmail($request->input('email'))) {
            return response()->json([
                'status' => 'error',
                'message' => 'This legacy account is retired. Sign in with your assigned staff account.',
            ], 403);
        }

        $credentials = $request->only('email', 'password');

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = auth('api')->user();

        // Check if user is active
        if (!$user->is_active) {
            auth('api')->logout();
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        return $this->respondWithToken($token);
    }

    /**
     * Get authenticated user details
     */
    public function me(): JsonResponse
    {
        $user = auth('api')->user();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at,
                'email_verified_at' => $user->email_verified_at,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                    ];
                }),
                'permissions' => collect($user->permissions())->map(function ($permission) {
                    return $permission->name;
                })->values(),
            ],
        ]);
    }

    /**
     * Logout user (invalidate token)
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Refresh JWT token
     */
    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    /**
     * Get the token array structure
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => collect($user->permissions())->pluck('name'),
                ],
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ],
        ]);
    }
}
