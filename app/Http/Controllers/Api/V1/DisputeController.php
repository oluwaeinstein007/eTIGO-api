<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccessDeniedException;
use App\Http\Controllers\Concerns\StoresDisputeEvidence;
use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The reporter's side of their disputes (passenger or driver): list, view,
 * add evidence while the case is open, and appeal a decision once.
 */
class DisputeController extends Controller
{
    use StoresDisputeEvidence;

    public function __construct(private DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'in:open,under_review,resolved,dismissed'],
            'ride_id' => ['sometimes', 'uuid'],
        ]);

        $disputes = Dispute::where('reported_by_user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('ride_id'), fn ($q) => $q->where('ride_id', $request->input('ride_id')))
            ->with(['ride', 'reportedBy', 'evidence'])
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

        return response()->json([
            'disputes' => DisputeResource::collection($disputes),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        if ($dispute->reported_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $dispute->load(['ride', 'reportedBy', 'evidence']);

        return response()->json([
            'dispute' => new DisputeResource($dispute),
        ]);
    }

    public function storeEvidence(Request $request, Dispute $dispute): JsonResponse
    {
        if ($dispute->reported_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,mp3,m4a,wav,ogg,webm,aac,pdf', 'max:10240'],
            'captions' => ['sometimes', 'array'],
            'captions.*' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $dispute->isOpen()) {
            return response()->json(['message' => 'Evidence can only be added while the case is open.'], 422);
        }

        $captions = $request->input('captions', []);
        $files = [];

        foreach (array_values($request->file('files')) as $i => $file) {
            // Photos and documents up to 5 MB; audio up to 10 MB.
            if (! str_starts_with((string) $file->getMimeType(), 'audio/') && ! str_starts_with((string) $file->getMimeType(), 'video/') && $file->getSize() > 5 * 1024 * 1024) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ["files.{$i}" => ['Photos and documents can be at most 5 MB.']],
                ], 422);
            }

            $files[] = ['file' => $file, 'caption' => $captions[$i] ?? null];
        }

        $evidence = $this->storeEvidenceFiles($dispute->ride_id, $files);

        if ($evidence === null) {
            return response()->json(['message' => 'Failed to upload the evidence. Please try again.'], 500);
        }

        try {
            $dispute = $this->disputes->addEvidence($dispute, $request->user(), $evidence);
        } catch (\DomainException $e) {
            $this->deleteEvidenceFiles($evidence);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->deleteEvidenceFiles($evidence);

            throw $e;
        }

        $dispute->load(['ride', 'reportedBy', 'evidence']);

        return response()->json([
            'message' => 'Evidence added to your report.',
            'dispute' => new DisputeResource($dispute),
        ], 201);
    }

    public function appeal(Request $request, Dispute $dispute): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $dispute = $this->disputes->appeal($dispute, $request->user(), $validated['reason']);
        } catch (AccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $dispute->load(['ride', 'reportedBy', 'evidence']);

        return response()->json([
            'message' => 'Appeal received. A senior member of our team will review your case.',
            'dispute' => new DisputeResource($dispute),
        ]);
    }
}
