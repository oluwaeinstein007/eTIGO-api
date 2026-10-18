<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeNoteResource;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\DisputeNote;
use App\Services\DisputeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeNoteController extends Controller
{
    public function __construct(private DisputeAccess $access) {}

    public function index(Request $request, Dispute $dispute): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($request, $dispute)) {
            return $denied;
        }

        $notes = $dispute->notes()
            ->with('admin')
            ->latest()
            ->paginate(20);

        return response()->json([
            'notes' => DisputeNoteResource::collection($notes),
            'meta' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
            ],
        ]);
    }

    public function store(Request $request, Dispute $dispute): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($request, $dispute)) {
            return $denied;
        }

        $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $admin = $request->user();

        $note = $dispute->notes()->create([
            'admin_id' => $admin->id,
            'content' => $request->input('content'),
        ]);

        $note->load('admin');

        AuditLog::record($dispute, 'dispute_note_added', $admin);

        return response()->json([
            'message' => 'Note added successfully.',
            'note' => new DisputeNoteResource($note),
        ], 201);
    }

    public function update(Request $request, Dispute $dispute, DisputeNote $note): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($request, $dispute)) {
            return $denied;
        }

        if ($note->dispute_id !== $dispute->id) {
            return response()->json(['message' => 'Note does not belong to this dispute.'], 404);
        }

        $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $note->update(['content' => $request->input('content')]);
        $note->load('admin');

        return response()->json([
            'message' => 'Note updated successfully.',
            'note' => new DisputeNoteResource($note),
        ]);
    }

    public function destroy(Request $request, Dispute $dispute, DisputeNote $note): JsonResponse
    {
        if ($denied = $this->denyUnlessVisible($request, $dispute)) {
            return $denied;
        }

        if ($note->dispute_id !== $dispute->id) {
            return response()->json(['message' => 'Note does not belong to this dispute.'], 404);
        }

        $note->delete();

        return response()->json([
            'message' => 'Note deleted successfully.',
        ]);
    }

    /**
     * Safety cases' notes are for the Safety team (and super admins) only.
     */
    private function denyUnlessVisible(Request $request, Dispute $dispute): ?JsonResponse
    {
        return $this->access->canView($request->user(), $dispute)
            ? null
            : response()->json(['message' => 'Safety cases are only visible to the Safety team.'], 403);
    }
}
