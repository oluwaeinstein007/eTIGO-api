<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\StoreDisputeFormRequest;
use App\Http\Resources\DisputeResource;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RideDisputeController extends Controller
{
    public function store(StoreDisputeFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            $disk = config('filesystems.uploads');
            foreach ($request->file('photos') as $photo) {
                $photoPaths[] = $photo->store("disputes/{$ride->id}", $disk);
            }
        }

        try {
            $dispute = DB::transaction(function () use ($ride, $user, $request, $photoPaths) {
                $dispute = $ride->disputes()->create([
                    'reported_by_user_id' => $user->id,
                    'category' => $request->validated('category'),
                    'description' => $request->validated('description'),
                    'evidence_photos' => $photoPaths ?: null,
                    'status' => DisputeStatus::Open,
                ]);

                AuditLog::record($ride, 'dispute_filed', $user, null, [
                    'dispute_id' => $dispute->id,
                    'category' => $request->validated('category'),
                ]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            if ($photoPaths) {
                $disk = config('filesystems.uploads');
                foreach ($photoPaths as $path) {
                    Storage::disk($disk)->delete($path);
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['ride' => ['You have already filed a dispute for this ride.']],
            ], 422);
        }

        $dispute->load('reportedBy');

        return response()->json([
            'message' => 'Dispute filed successfully. Our operations team will review it and get back to you within 24 hours.',
            'dispute' => new DisputeResource($dispute),
        ], 201);
    }
}
