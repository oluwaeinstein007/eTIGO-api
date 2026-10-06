<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcceptInvitationRequest;
use App\Http\Requests\Admin\AdminForgotPasswordRequest;
use App\Http\Requests\Admin\AdminResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\AdminInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use App\Notifications\AdminWelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class AdminInvitationController extends Controller
{
    public function verifyToken(string $token): JsonResponse
    {
        $invitation = AdminInvitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invalid invitation token.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 422);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 422);
        }

        return response()->json([
            'message' => 'Valid invitation.',
            'invitation' => [
                'email' => $invitation->email,
                'admin_role' => $invitation->admin_role,
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated) {
            $invitation = AdminInvitation::where('token', $validated['token'])
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return ['error' => 'Invalid, expired, or already accepted invitation.', 'status' => 422];
            }

            if (User::where('email', $invitation->email)->exists()) {
                return ['error' => 'A user with this email already exists.', 'status' => 409];
            }

            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $invitation->email,
                'type' => UserType::Admin,
                'admin_role' => $invitation->admin_role,
                'password' => $validated['password'],
                'phone_verified_at' => now(),
            ]);

            $invitation->update(['accepted_at' => now()]);

            return ['user' => $user];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $user = $result['user'];

        $abilities = ['admin', $user->admin_role->value];
        $token = $user->createToken('admin-auth', $abilities)->plainTextToken;

        AuditLog::record($user, 'admin_invitation_accepted');

        $user->notify(new AdminWelcomeNotification);

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function forgotPassword(AdminForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))
            ->where('type', UserType::Admin)
            ->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            $user->notify(new AdminPasswordResetNotification($token));
        }

        return response()->json([
            'message' => 'If the email exists in our system, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(AdminResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])
            ->where('type', UserType::Admin)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid reset request.'], 422);
        }

        if (! Password::broker()->tokenExists($user, $validated['token'])) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();

        Password::broker()->deleteToken($user);

        AuditLog::record($user, 'admin_password_reset');

        return response()->json(['message' => 'Password has been reset successfully.']);
    }
}
