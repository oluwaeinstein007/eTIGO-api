<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminChangePasswordRequest;
use App\Http\Requests\Auth\AdminLoginCodeRequest;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\AdminLoginResendRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AdminPasswordChangedNotification;
use App\Services\AdminLoginChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAuthController extends Controller
{
    public function __construct(private AdminLoginChallengeService $challenges) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])
            ->where('type', UserType::Admin)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact a system administrator.',
            ], 403);
        }

        if ($this->challenges->enabled()) {
            $challenge = $this->challenges->start($user);

            AuditLog::record($user, 'admin_login_code_sent', $user);

            return response()->json([
                'message' => 'Enter the 6-digit code we sent to your email.',
                'two_factor_required' => true,
                'challenge_token' => $challenge['challenge_token'],
                'email_hint' => $this->challenges->emailHint($user->email),
                'expires_at' => $challenge['expires_at']->toIso8601String(),
            ]);
        }

        return $this->signIn($user);
    }

    /**
     * Second step of the two-step sign-in: the emailed code for a token.
     */
    public function verifyCode(AdminLoginCodeRequest $request): JsonResponse
    {
        $result = $this->challenges->verify($request->validated('challenge_token'), $request->validated('code'));

        if (! $result['user']) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $user = $result['user'];

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Contact a system administrator.',
            ], 403);
        }

        return $this->signIn($user, twoFactor: true);
    }

    public function resendCode(AdminLoginResendRequest $request): JsonResponse
    {
        $result = $this->challenges->resend($request->validated('challenge_token'));

        if (! $result['sent']) {
            $response = response()->json(['message' => $result['error']], $result['status']);

            return $result['retry_after'] ? $response->header('Retry-After', (string) $result['retry_after']) : $response;
        }

        return response()->json(['message' => 'We sent a new code to your email.']);
    }

    private function signIn(User $user, bool $twoFactor = false): JsonResponse
    {
        $abilities = ['admin'];
        if ($user->admin_role) {
            $abilities[] = $user->admin_role->value;
        }

        $token = $user->createToken('admin-auth', $abilities)->plainTextToken;

        AuditLog::record($user, 'admin_logged_in', $user, null, ['two_factor' => $twoFactor]);

        return response()->json([
            'message' => 'Logged in successfully.',
            'two_factor_required' => false,
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = request()->user();
        $user->currentAccessToken()->delete();

        AuditLog::record($user, 'admin_logged_out');

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Change the signed-in admin's password. Every other session is signed
     * out and any pending reset link is voided; this one stays signed in.
     */
    public function changePassword(AdminChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->type !== UserType::Admin) {
            return response()->json(['message' => 'This action is for admin accounts only.'], 403);
        }

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            AuditLog::record($user, 'admin_password_change_failed', $user);

            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update(['password' => $request->validated('password')]);

        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->where('id', '!=', $current->id))
            ->delete();

        Password::broker()->deleteToken($user);

        AuditLog::record($user, 'admin_password_changed', $user);

        $user->notify(new AdminPasswordChangedNotification);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => new UserResource(request()->user()),
        ]);
    }
}
