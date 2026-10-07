<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\AdminInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AdminInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class AdminManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = User::where('type', UserType::Admin)
            ->latest()
            ->paginate(20);

        return response()->json([
            'admins' => UserResource::collection($admins),
            'meta' => [
                'current_page' => $admins->currentPage(),
                'last_page' => $admins->lastPage(),
                'total' => $admins->total(),
            ],
        ]);
    }

    public function show(User $admin): JsonResponse
    {
        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 404);
        }

        return response()->json([
            'admin' => new UserResource($admin),
        ]);
    }

    public function invite(InviteAdminRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $existingInvite = AdminInvitation::where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvite) {
            return response()->json([
                'message' => 'A pending invitation already exists for this email.',
            ], 409);
        }

        $invitation = AdminInvitation::create([
            'email' => $validated['email'],
            'admin_role' => $validated['admin_role'],
            'token' => Str::random(64),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new AdminInvitationNotification($invitation));

        AuditLog::record($invitation, 'admin_invited', $request->user(), newValues: [
            'email' => $invitation->email,
            'admin_role' => $invitation->admin_role->value,
        ]);

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'admin_role' => $invitation->admin_role,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    public function resendInvite(AdminInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 422);
        }

        $invitation->update([
            'token' => Str::random(64),
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new AdminInvitationNotification($invitation));

        AuditLog::record($invitation, 'admin_invite_resent', request()->user());

        return response()->json(['message' => 'Invitation resent successfully.']);
    }

    public function revokeInvite(AdminInvitation $invitation): JsonResponse
    {
        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 422);
        }

        $invitation->update(['expires_at' => now()]);

        AuditLog::record($invitation, 'admin_invite_revoked', request()->user());

        return response()->json(['message' => 'Invitation revoked successfully.']);
    }

    public function invitations(): JsonResponse
    {
        $invitations = AdminInvitation::with('inviter:id,first_name,last_name')
            ->latest()
            ->paginate(20);

        $items = collect($invitations->items())->map(function ($invitation) {
            return collect($invitation->toArray())->except('token');
        });

        return response()->json([
            'invitations' => $items,
            'meta' => [
                'current_page' => $invitations->currentPage(),
                'last_page' => $invitations->lastPage(),
                'total' => $invitations->total(),
            ],
        ]);
    }

    public function update(UpdateAdminRequest $request, User $admin): JsonResponse
    {
        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 404);
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return response()->json(['message' => 'Cannot modify a super admin account.'], 403);
        }

        $oldValues = ['admin_role' => $admin->admin_role->value];

        $admin->update($request->validated());

        AuditLog::record($admin, 'admin_role_updated', $request->user(), $oldValues, [
            'admin_role' => $admin->fresh()->admin_role->value,
        ]);

        return response()->json([
            'message' => 'Admin updated successfully.',
            'admin' => new UserResource($admin->fresh()),
        ]);
    }

    public function deactivate(User $admin): JsonResponse
    {
        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 404);
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return response()->json(['message' => 'Cannot deactivate a super admin account.'], 403);
        }

        if ($admin->id === request()->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 403);
        }

        $admin->update(['is_active' => false]);
        $admin->tokens()->delete();

        AuditLog::record($admin, 'admin_deactivated', request()->user());

        return response()->json(['message' => 'Admin deactivated successfully.']);
    }

    public function reactivate(User $admin): JsonResponse
    {
        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 404);
        }

        $admin->update(['is_active' => true]);

        AuditLog::record($admin, 'admin_reactivated', request()->user());

        return response()->json(['message' => 'Admin reactivated successfully.']);
    }

    public function destroy(User $admin): JsonResponse
    {
        if (! $admin->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 404);
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return response()->json(['message' => 'Cannot delete a super admin account.'], 403);
        }

        if ($admin->id === request()->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        AuditLog::record($admin, 'admin_deleted', request()->user());

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully.']);
    }
}
