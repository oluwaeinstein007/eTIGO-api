<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DisputeCategory;
use App\Enums\DisputeQueue;
use App\Http\Controllers\Concerns\StoresDisputeEvidence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\StoreDisputeFormRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Ride;
use App\Services\DisputeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class RideDisputeController extends Controller
{
    use StoresDisputeEvidence;

    public function __construct(private DisputeService $disputes) {}

    public function store(StoreDisputeFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        $files = array_map(fn ($photo) => ['file' => $photo], $request->file('photos', []));

        if ($request->hasFile('audio')) {
            $files[] = ['file' => $request->file('audio')];
        }

        $evidence = $this->storeEvidenceFiles($ride->id, $files);

        if ($evidence === null) {
            return response()->json([
                'message' => 'Failed to upload the evidence. Please try again.',
            ], 500);
        }

        try {
            $dispute = $this->disputes->file(
                $ride,
                $user,
                DisputeCategory::from($request->validated('category')),
                $request->validated('description'),
                $evidence,
            );
        } catch (UniqueConstraintViolationException) {
            $this->deleteEvidenceFiles($evidence);

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['ride' => ['You have already filed a dispute for this ride.']],
            ], 422);
        } catch (\Throwable $e) {
            $this->deleteEvidenceFiles($evidence);

            throw $e;
        }

        $dispute->load(['reportedBy', 'evidence']);

        $message = $dispute->queue === DisputeQueue::Safety
            ? 'Report received. Our safety team has been alerted and will contact you shortly.'
            : 'Dispute filed successfully. Our operations team will review it and get back to you within 24 hours.';

        return response()->json([
            'message' => $message,
            'dispute' => new DisputeResource($dispute),
        ], 201);
    }
}
