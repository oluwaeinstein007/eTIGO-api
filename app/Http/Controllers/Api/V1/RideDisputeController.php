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
        $audioPath = null;
        $disk = 'local';

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store("disputes/{$ride->id}", $disk);

                if ($path === false) {
                    foreach ($photoPaths as $stored) {
                        Storage::disk($disk)->delete($stored);
                    }

                    return response()->json([
                        'message' => 'Failed to upload evidence photos. Please try again.',
                    ], 500);
                }

                $photoPaths[] = $path;
            }
        }

        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store("disputes/{$ride->id}/audio", $disk);

            if ($audioPath === false) {
                foreach ($photoPaths as $stored) {
                    Storage::disk($disk)->delete($stored);
                }

                return response()->json([
                    'message' => 'Failed to upload audio recording. Please try again.',
                ], 500);
            }
        }

        try {
            $dispute = DB::transaction(function () use ($ride, $user, $request, $photoPaths, $audioPath) {
                $dispute = $ride->disputes()->create([
                    'reported_by_user_id' => $user->id,
                    'category' => $request->validated('category'),
                    'description' => $request->validated('description'),
                    'evidence_photos' => $photoPaths ?: null,
                    'evidence_audio' => $audioPath,
                    'status' => DisputeStatus::Open,
                ]);

                AuditLog::record($ride, 'dispute_filed', $user, null, [
                    'dispute_id' => $dispute->id,
                    'category' => $request->validated('category'),
                ]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            foreach ($photoPaths as $path) {
                Storage::disk($disk)->delete($path);
            }
            if ($audioPath) {
                Storage::disk($disk)->delete($audioPath);
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['ride' => ['You have already filed a dispute for this ride.']],
            ], 422);
        } catch (\Throwable $e) {
            foreach ($photoPaths as $path) {
                Storage::disk($disk)->delete($path);
            }
            if ($audioPath) {
                Storage::disk($disk)->delete($audioPath);
            }

            throw $e;
        }

        $dispute->load('reportedBy');

        return response()->json([
            'message' => 'Dispute filed successfully. Our operations team will review it and get back to you within 24 hours.',
            'dispute' => new DisputeResource($dispute),
        ], 201);
    }
}
