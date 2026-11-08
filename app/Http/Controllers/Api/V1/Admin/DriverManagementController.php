<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Enums\VehicleOwnershipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteDriverOnboardingRequest;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\ReviewDriverRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\RideResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Notifications\DriverApprovedNotification;
use App\Services\DriverDocumentComplianceService;
use App\Services\DriverHistoryService;
use App\Services\DriverLicenceCheckService;
use App\Services\DriverLocationService;
use App\Services\KycVerificationService;
use App\Services\NotificationService;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DriverManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DriverStatus::class)],
            'kyc_status' => ['sometimes', 'nullable', Rule::enum(KycStatus::class)],
            'city_id' => ['sometimes', 'nullable', 'uuid'],
            'vehicle_class_id' => ['sometimes', 'nullable', 'uuid'],
            // Query strings: true/false as well as 1/0.
            'documents_blocked' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $query = Driver::with(['user', 'documents', 'vehicle.vehicleClass', 'city', 'primaryBankAccount']);

        // Off the road on an expired or withdrawn driving licence.
        if ($request->has('documents_blocked')) {
            $request->boolean('documents_blocked') ? $query->whereNotNull('documents_blocked_at') : $query->whereNull('documents_blocked_at');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('kyc_status')) {
            $query->where('kyc_status', $request->input('kyc_status'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->filled('vehicle_class_id')) {
            $query->whereHas('vehicle', fn ($q) => $q->where('vehicle_class_id', $request->input('vehicle_class_id')));
        }

        $request->validate(['fuel_type' => ['nullable', Rule::in([...array_column(FuelType::cases(), 'value'), 'none'])]]);

        if ($request->filled('fuel_type')) {
            $fuelType = $request->input('fuel_type');
            $query->whereHas('vehicle', fn ($q) => $fuelType === 'none' ? $q->whereNull('fuel_type') : $q->where('fuel_type', $fuelType));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $drivers = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'drivers' => DriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }

    public function show(Driver $driver, DriverHistoryService $history): JsonResponse
    {
        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications', 'primaryBankAccount']);

        $completedRides = Ride::where('driver_id', $driver->user_id)->where('status', 'completed');
        $totalRides = Ride::where('driver_id', $driver->user_id);
        $cancelledRides = Ride::where('driver_id', $driver->user_id)->where('status', 'cancelled');

        $totalCount = $totalRides->count();
        $completedCount = $completedRides->count();
        $cancelledCount = $cancelledRides->count();

        $accepted = AuditLog::where('actor_id', $driver->user_id)
            ->where('event', 'ride_accepted')
            ->count();
        $rejected = AuditLog::where('actor_id', $driver->user_id)
            ->where('event', 'ride_rejected')
            ->count();
        $totalOffers = $accepted + $rejected;

        $performance = [
            'rating' => $driver->user?->averageRating(),
            'total_trips' => $totalCount,
            'completed_trips' => $completedCount,
            'cancelled_trips' => $cancelledCount,
            'cancellation_rate' => $totalCount > 0 ? round(($cancelledCount / $totalCount) * 100, 1) : 0,
            'acceptance_rate' => $totalOffers > 0 ? round(($accepted / $totalOffers) * 100, 1) : null,
            'total_earnings' => (float) Ride::where('driver_id', $driver->user_id)
                ->where('status', 'completed')
                ->sum('final_fare_amount'),
        ];

        return response()->json([
            'driver' => new DriverResource($driver),
            'performance' => $performance,
            // Expiry state per paper, renewals waiting for review, and the licence block.
            'document_compliance' => app(DriverDocumentComplianceService::class)->summary($driver),
            'history' => $history->for($driver),
        ]);
    }

    public function review(ReviewDriverRequest $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::PendingReview) {
            return response()->json([
                'message' => 'Driver can only be reviewed when in pending review status.',
            ], 422);
        }

        $validated = $request->validated();
        $admin = $request->user();
        $oldStatus = $driver->status;

        if ($validated['action'] === 'approve') {
            if (! $driver->isKycVerified()) {
                return response()->json([
                    'message' => 'Cannot approve driver. KYC verification is not complete.',
                    'kyc_status' => $driver->kyc_status,
                ], 422);
            }

            // Approving the application approves its pending papers, so they
            // meet the same rules as a single approval: a licence (and the
            // papers that decide whether a car may be driven) needs its expiry
            // date, and nothing already expired.
            $today = app(DriverDocumentComplianceService::class)->today()->toDateString();

            // A licence with no date takes FRSC's, when its records have one.
            $driver->documents()->where('status', DocumentStatus::Pending)->where('type', DocumentType::DrivingLicence)->whereNull('expires_at')->get()
                ->each(function (DriverDocument $doc) {
                    if (($frsc = app(DriverLicenceCheckService::class)->frscExpiry($doc)) !== null) {
                        $doc->update(['expires_at' => $frsc]);
                    }
                });

            $problems = $driver->documents()->where('status', DocumentStatus::Pending)->get()
                ->map(fn (DriverDocument $doc) => match (true) {
                    $doc->expires_at === null && ($doc->type === DocumentType::DrivingLicence || $doc->type->requiresExpiry()) => "{$doc->type->label()}: enter its expiry date (approve the document with expires_at).",
                    $doc->expires_at !== null && $doc->expires_at->toDateString() < $today => "{$doc->type->label()}: expired on {$doc->expires_at->format('j M Y')}; reject it.",
                    default => null,
                })
                ->filter()
                ->values();

            if ($problems->isNotEmpty()) {
                return response()->json([
                    'message' => 'Review these documents before approving the driver.',
                    'errors' => ['documents' => $problems->all()],
                ], 422);
            }

            DB::transaction(function () use ($driver, $admin, $oldStatus) {
                $driver->update([
                    'status' => DriverStatus::Approved,
                    'rejection_reason' => null,
                    'approved_at' => now(),
                ]);

                $driver->documents()
                    ->where('status', DocumentStatus::Pending)
                    ->update([
                        'status' => DocumentStatus::Approved,
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                    ]);

                AuditLog::record($driver, 'driver_approved', $admin, ['status' => $oldStatus->value], ['status' => 'approved']);
            });

            $freshDriver = $driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city']);

            try {
                if ($freshDriver->user?->email) {
                    $freshDriver->user->notify(new DriverApprovedNotification($freshDriver));
                }
            } catch (\Throwable $e) {
                Log::warning('Driver approval email failed', ['driver_id' => $freshDriver->id, 'error' => $e->getMessage()]);
            }

            return response()->json([
                'message' => 'Driver approved successfully.',
                'driver' => new DriverResource($freshDriver),
            ]);
        }

        DB::transaction(function () use ($driver, $admin, $oldStatus, $validated) {
            $driver->update([
                'status' => DriverStatus::Rejected,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            AuditLog::record($driver, 'driver_rejected', $admin, ['status' => $oldStatus->value], [
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'],
            ]);
        });

        return response()->json([
            'message' => 'Driver rejected.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    /**
     * Approve or reject one pending paper. Approving a renewal replaces the earlier paper and can lift a block; every review rechecks the driver's licence block.
     */
    public function reviewDocument(ReviewDocumentRequest $request, Driver $driver, DriverDocument $document, VehicleMaintenanceService $maintenance, DriverDocumentComplianceService $compliance): JsonResponse
    {
        if ($document->driver_id !== $driver->id) {
            return response()->json(['message' => 'Document does not belong to this driver.'], 404);
        }

        if ($document->status !== DocumentStatus::Pending) {
            return response()->json([
                'message' => 'Only pending documents can be reviewed.',
            ], 422);
        }

        $validated = $request->validated();
        $admin = $request->user();

        $newStatus = $validated['action'] === 'approve'
            ? DocumentStatus::Approved
            : DocumentStatus::Rejected;

        if ($newStatus === DocumentStatus::Approved) {
            $expiresAt = array_key_exists('expires_at', $validated) ? $validated['expires_at'] : $document->expires_at?->toDateString();

            // No date on the paper or in the request: FRSC's date, when its
            // records have one.
            if ($expiresAt === null && ($frsc = app(DriverLicenceCheckService::class)->frscExpiry($document)) !== null) {
                $expiresAt = $validated['expires_at'] = $frsc;
            }

            // Renewals go by the date on the paper, so a licence (and the
            // papers that decide whether a car may be driven) needs one.
            if ($expiresAt === null && ($document->type === DocumentType::DrivingLicence || $document->type->requiresExpiry())) {
                return response()->json([
                    'message' => "Enter the expiry date printed on the {$this->lowerLabel($document)} before approving it.",
                    'errors' => ['expires_at' => ['The expiry date is required to approve this document.']],
                ], 422);
            }

            if ($expiresAt !== null && $compliance->today()->toDateString() > Carbon::parse($expiresAt)->toDateString()) {
                return response()->json([
                    'message' => "This {$this->lowerLabel($document)} has already expired. Reject it and ask the driver for the renewed one.",
                    'errors' => ['expires_at' => ['An expired document cannot be approved.']],
                ], 422);
            }

            // A renewed licence must run past the one on file, or it would sit
            // approved next to it without replacing it. (An FRSC temporary
            // licence is uploaded once the old one has run out, so it does.)
            $onFile = $document->type === DocumentType::DrivingLicence ? $compliance->currentDocument($driver, DocumentType::DrivingLicence) : null;

            if ($onFile?->expires_at !== null && $expiresAt !== null && Carbon::parse($expiresAt)->toDateString() <= $onFile->expires_at->toDateString()) {
                return response()->json([
                    'message' => "The licence on file is valid until {$onFile->expires_at->format('j M Y')}. Check the date: a renewed licence expires later.",
                    'errors' => ['expires_at' => ['The renewed licence must expire after the licence on file.']],
                ], 422);
            }
        }

        $replacedPaths = [];

        $conflict = DB::transaction(function () use ($document, $driver, $admin, $validated, $newStatus, $compliance, &$replacedPaths) {
            // One review or correction of this driver's papers at a time.
            Driver::lockForUpdate()->find($driver->id);

            if (DriverDocument::lockForUpdate()->find($document->id)?->status !== DocumentStatus::Pending) {
                return 'This document was reviewed by someone else. Reload it.';
            }

            $document->update([
                'status' => $newStatus,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                ...(array_key_exists('expires_at', $validated) && $newStatus === DocumentStatus::Approved ? ['expires_at' => $validated['expires_at']] : []),
                ...(array_key_exists('document_number', $validated) ? ['document_number' => $validated['document_number']] : []),
            ]);

            $action = $validated['action'] === 'approve' ? 'document_approved' : 'document_rejected';
            AuditLog::record($document, $action, $admin, ['status' => DocumentStatus::Pending->value], [
                'status' => $newStatus->value,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                // What FRSC records said when the reviewer decided.
                'frsc_check' => app(DriverLicenceCheckService::class)->record($document->fresh())['status'] ?? null,
                'type' => $document->type->value,
                // The driver too, since an approved renewal deletes the paper it replaces.
                'driver_id' => $driver->id,
            ]);

            // An approved renewal replaces the car's earlier paper of that type,
            // but never one that runs longer (an admin may have uploaded a
            // newer paper while the renewal waited for review).
            if ($newStatus === DocumentStatus::Approved && $document->vehicle_id !== null) {
                $expiresAt = $document->expires_at;

                $replaced = DriverDocument::where('vehicle_id', $document->vehicle_id)
                    ->where('type', $document->type)
                    ->where('id', '!=', $document->id)
                    ->where('status', DocumentStatus::Approved)
                    ->when($expiresAt !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<=', $expiresAt)))
                    ->get();

                $replacedPaths = $replaced->pluck('file_path')->all();
                $replaced->each->delete();
            }

            // The driver's own papers likewise: an approved renewal replaces
            // the earlier licence or ID (never one that runs longer). The
            // audit entry keeps what it replaced.
            if ($newStatus === DocumentStatus::Approved && $document->vehicle_id === null) {
                $expiresAt = $document->expires_at;

                $replaced = DriverDocument::where('driver_id', $driver->id)
                    ->whereNull('vehicle_id')
                    ->where('type', $document->type)
                    ->where('id', '!=', $document->id)
                    ->where('status', DocumentStatus::Approved)
                    ->when($expiresAt !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<=', $expiresAt)))
                    ->get();

                foreach ($replaced as $old) {
                    AuditLog::record($document, 'document_replaced', $admin, [
                        'document_id' => $old->id,
                        'expires_at' => $old->expires_at?->toDateString(),
                        'document_number_last4' => $old->document_number ? mb_substr($old->document_number, -4) : null,
                    ], [
                        'expires_at' => $expiresAt?->toDateString(),
                        'type' => $document->type->value,
                        'driver_id' => $driver->id,
                    ]);
                }

                $replacedPaths = [...$replacedPaths, ...$replaced->pluck('file_path')->all()];
                $replaced->each->delete();

                // A renewed licence may carry a new number. The driver's number
                // follows the licence in force (the longest-running one), and
                // the old one stays in the audit log (last 4).
                $inForce = $document->type === DocumentType::DrivingLicence ? $compliance->currentDocument($driver, DocumentType::DrivingLicence) : null;

                if ($inForce?->document_number && $inForce->document_number !== $driver->licence_number) {
                    $previous = $driver->licence_number;
                    $driver->update(['licence_number' => $inForce->document_number]);
                    AuditLog::record($driver, 'licence_number_changed', $admin,
                        ['licence_number_last4' => $previous ? mb_substr($previous, -4) : null],
                        ['licence_number_last4' => mb_substr($inForce->document_number, -4), 'document_id' => $inForce->id],
                    );
                }
            }

            $lockedDriver = Driver::lockForUpdate()->find($driver->id);

            $hasRejectedDocs = $lockedDriver->documents()
                ->where('status', DocumentStatus::Rejected)
                ->exists();

            if ($hasRejectedDocs && $lockedDriver->status === DriverStatus::PendingReview) {
                $allReviewed = ! $lockedDriver->documents()
                    ->where('status', DocumentStatus::Pending)
                    ->exists();

                if ($allReviewed) {
                    $reason = 'One or more documents were rejected. Please re-upload and resubmit.';

                    $lockedDriver->update([
                        'status' => DriverStatus::Rejected,
                        'rejection_reason' => $reason,
                    ]);

                    AuditLog::record($lockedDriver, 'driver_rejected', $admin, ['status' => DriverStatus::PendingReview->value], [
                        'status' => DriverStatus::Rejected->value,
                        'rejection_reason' => $reason,
                    ]);
                }
            }

            return null;
        });

        if ($conflict !== null) {
            return response()->json(['message' => $conflict], 409);
        }

        if ($replacedPaths !== []) {
            Storage::disk(config('filesystems.uploads'))->delete($replacedPaths);
        }

        // Vehicle papers decide whether the car may be driven (OQ-19), the
        // driving licence whether the driver may.
        if ($document->vehicle_id !== null && ($vehicle = Vehicle::find($document->vehicle_id)) !== null) {
            $maintenance->refresh($vehicle);
        }

        // Always recheck: a rejected renewal can start a block as well as an
        // approval can lift one.
        $wasBlocked = $driver->isDocumentsBlocked();
        $refreshed = $compliance->refresh($driver->fresh());
        $unblocked = $wasBlocked && ! $refreshed->isDocumentsBlocked();

        // Onboarding papers are covered by the application decision; a
        // renewal from a working driver gets its own answer (unless lifting
        // the block has just told them).
        if (! $unblocked && $driver->status !== DriverStatus::Onboarding && $driver->status !== DriverStatus::PendingReview) {
            $this->notifyDocumentReviewed($driver, $document->fresh());
        }

        return response()->json([
            'message' => "Document {$validated['action']}d successfully.",
            'document' => new DriverDocumentResource($document->fresh()),
            'compliance' => $compliance->summary($driver->fresh()),
        ]);
    }

    /**
     * Withdraw an approved paper found to be invalid after approval (a
     * suspended or revoked licence, a forged paper). The driver is told why;
     * a withdrawn driving licence keeps them off the road until a renewed
     * one is approved. A trip in progress is not ended.
     */
    public function revokeDocument(Request $request, Driver $driver, DriverDocument $document, DriverDocumentComplianceService $compliance): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($document->driver_id !== $driver->id) {
            return response()->json(['message' => 'Document does not belong to this driver.'], 404);
        }

        if ($document->status !== DocumentStatus::Approved || $document->revoked_at !== null) {
            return response()->json(['message' => 'Only approved documents can be withdrawn.'], 422);
        }

        // A car's papers decide whether it may be driven through vehicle
        // maintenance, where a missing paper only reminds: withdrawing an
        // expired one would lift its block. Upload or approve the replacement
        // for the car instead.
        if ($document->type->isVehicleDocument()) {
            return response()->json([
                'message' => "Vehicle papers can't be withdrawn. Upload the replacement for the car (POST /admin/vehicles/{vehicle}/documents) or reject the driver's next upload.",
            ], 422);
        }

        $conflict = DB::transaction(function () use ($document, $request, $validated, $driver) {
            Driver::lockForUpdate()->find($driver->id);
            $locked = DriverDocument::lockForUpdate()->find($document->id);

            if ($locked === null || $locked->status !== DocumentStatus::Approved || $locked->revoked_at !== null) {
                return 'This document changed while you were looking at it. Reload it.';
            }

            $document->update([
                'status' => DocumentStatus::Rejected,
                'rejection_reason' => $validated['reason'],
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
            ]);

            AuditLog::record($document, 'document_revoked', $request->user(), ['status' => DocumentStatus::Approved->value], [
                'status' => DocumentStatus::Rejected->value,
                'reason' => $validated['reason'],
                'type' => $document->type->value,
                'driver_id' => $driver->id,
            ]);

            return null;
        });

        if ($conflict !== null) {
            return response()->json(['message' => $conflict], 409);
        }

        $compliance->refresh($driver->fresh());
        $this->notifyDocumentReviewed($driver, $document->fresh());

        return response()->json([
            'message' => 'Document withdrawn.',
            'document' => new DriverDocumentResource($document->fresh()),
            'compliance' => $compliance->summary($driver->fresh()),
        ]);
    }

    /**
     * Correct the expiry date or number on an approved paper, mostly to add
     * the expiry date to licences approved before it was required. A new
     * date restarts the reminders and rechecks the block.
     */
    public function updateDocument(Request $request, Driver $driver, DriverDocument $document, VehicleMaintenanceService $maintenance, DriverDocumentComplianceService $compliance): JsonResponse
    {
        $validated = $request->validate([
            'expires_at' => ['sometimes', 'required', 'date'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        if ($document->driver_id !== $driver->id) {
            return response()->json(['message' => 'Document does not belong to this driver.'], 404);
        }

        if ($document->status !== DocumentStatus::Approved || $document->revoked_at !== null) {
            return response()->json(['message' => 'Only approved documents can be corrected. Review pending ones instead.'], 422);
        }

        if ($validated === []) {
            return response()->json(['message' => 'Send expires_at or document_number.', 'errors' => ['expires_at' => ['Nothing to change.']]], 422);
        }

        $old = ['expires_at' => $document->expires_at?->toDateString(), 'document_number_last4' => $document->document_number ? mb_substr($document->document_number, -4) : null];

        $conflict = DB::transaction(function () use ($document, $validated, $request, $old, $driver, $compliance) {
            // Serialised with reviews: a renewal approved meanwhile may have
            // replaced this paper.
            Driver::lockForUpdate()->find($driver->id);
            $locked = DriverDocument::lockForUpdate()->find($document->id);

            if ($locked === null || $locked->status !== DocumentStatus::Approved || $locked->revoked_at !== null) {
                return 'This document changed while you were looking at it. Reload it.';
            }

            $changesDate = array_key_exists('expires_at', $validated) && Carbon::parse($validated['expires_at'])->toDateString() !== $old['expires_at'];

            $document->update([
                ...$validated,
                ...($changesDate ? ['expiry_reminder_stage' => null, 'expiry_reminder_sent_at' => null] : []),
            ]);

            // The driver's number follows the licence in force only.
            $inForce = $document->type === DocumentType::DrivingLicence ? $compliance->currentDocument($driver, DocumentType::DrivingLicence) : null;

            if ($inForce?->id === $document->id && $document->document_number && $document->document_number !== $driver->licence_number) {
                $driver->update(['licence_number' => $document->document_number]);
            }

            AuditLog::record($document, 'document_details_updated', $request->user(), $old, [
                'expires_at' => $document->expires_at?->toDateString(),
                'document_number_last4' => $document->document_number ? mb_substr($document->document_number, -4) : null,
                'driver_id' => $driver->id,
            ]);

            return null;
        });

        if ($conflict !== null) {
            return response()->json(['message' => $conflict], 409);
        }

        if ($document->vehicle_id !== null && ($vehicle = Vehicle::find($document->vehicle_id)) !== null) {
            $maintenance->refresh($vehicle);
        }

        $compliance->refresh($driver->fresh());

        return response()->json([
            'message' => 'Document updated.',
            'document' => new DriverDocumentResource($document->fresh()),
            'compliance' => $compliance->summary($driver->fresh()),
        ]);
    }

    /**
     * Tell a working driver what happened to their paper (approved, rejected or withdrawn).
     */
    /**
     * Run the FRSC lookup again, for example when the provider was down at
     * upload. Driving licences waiting for review only (a paid lookup).
     */
    public function checkLicence(Driver $driver, DriverDocument $document, DriverLicenceCheckService $checks): JsonResponse
    {
        if ($document->driver_id !== $driver->id) {
            return response()->json(['message' => 'Document does not belong to this driver.'], 404);
        }

        if ($document->type !== DocumentType::DrivingLicence || $document->status !== DocumentStatus::Pending) {
            return response()->json(['message' => 'Only a driving licence waiting for review can be checked.'], 422);
        }

        $checks->check($document);

        return response()->json([
            'message' => 'Licence checked against FRSC records.',
            'document' => new DriverDocumentResource($document->fresh()),
        ]);
    }

    private function notifyDocumentReviewed(Driver $driver, DriverDocument $document): void
    {
        $label = $document->type->label();

        $body = match (true) {
            $document->revoked_at !== null => "E-tiGo withdrew your {$this->lowerLabel($document)}: {$document->rejection_reason} Upload a valid one in the app.",
            $document->status === DocumentStatus::Approved => "Your {$this->lowerLabel($document)} is approved".($document->expires_at ? ' (valid until '.$document->expires_at->format('j M Y').').' : '.'),
            default => "Your {$this->lowerLabel($document)} wasn't accepted: {$document->rejection_reason} Upload it again in the app.",
        };

        try {
            app(NotificationService::class)->send(
                userId: $driver->user_id,
                type: NotificationType::DocumentReviewed,
                title: $document->status === DocumentStatus::Approved ? "{$label} Approved" : NotificationType::DocumentReviewed->label(),
                body: $body,
                data: [
                    'document_id' => (string) $document->id,
                    'document_type' => $document->type->value,
                    'status' => $document->status->value,
                    'revoked' => $document->revoked_at !== null ? 'true' : 'false',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Document review notification failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The document's label inside a sentence.
     */
    private function lowerLabel(DriverDocument $document): string
    {
        return mb_strtolower($document->type->label());
    }

    public function suspend(Request $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::Approved) {
            return response()->json([
                'message' => 'Only approved drivers can be suspended.',
            ], 422);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $admin = $request->user();
        $reason = $request->input('reason');

        DB::transaction(function () use ($driver, $admin, $reason) {
            $driver->update([
                'status' => DriverStatus::Suspended,
                'is_online' => false,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            AuditLog::record($driver, 'driver_suspended', $admin, null, [
                'reason' => $reason,
            ]);
        });

        try {
            app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove suspended driver from the live location index', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Driver suspended successfully.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function reactivate(Request $request, Driver $driver): JsonResponse
    {
        if ($driver->status !== DriverStatus::Suspended) {
            return response()->json([
                'message' => 'Only suspended drivers can be reactivated.',
            ], 422);
        }

        $admin = $request->user();

        DB::transaction(function () use ($driver, $admin) {
            $driver->update([
                'status' => DriverStatus::Approved,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            AuditLog::record($driver, 'driver_reactivated', $admin);
        });

        return response()->json([
            'message' => 'Driver reactivated successfully.',
            'driver' => new DriverResource($driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city'])),
        ]);
    }

    public function toggleFleetVehicle(Request $request, Driver $driver, KycVerificationService $kycService): JsonResponse
    {
        if (! $driver->vehicle) {
            return response()->json(['message' => 'Driver has no registered vehicle.'], 422);
        }

        $vehicle = $driver->vehicle;
        $wasFleet = $vehicle->is_fleet;

        DB::transaction(function () use ($driver, $vehicle, $wasFleet, $request, $kycService) {
            $vehicle->update(['is_fleet' => ! $wasFleet]);

            // The driver too, since shared fleet cars pass between drivers.
            AuditLog::record(
                $vehicle,
                $wasFleet ? 'vehicle_unmarked_fleet' : 'vehicle_marked_fleet',
                $request->user(),
                ['is_fleet' => $wasFleet],
                ['is_fleet' => ! $wasFleet, 'driver_id' => $driver->id],
            );

            $kycService->recalculateDriverKycStatus($driver);
        });

        return response()->json([
            'message' => $wasFleet
                ? 'Vehicle unmarked as fleet. Plate verification now required.'
                : 'Vehicle marked as fleet. Plate verification skipped.',
            'vehicle' => new VehicleResource($vehicle->fresh()),
        ]);
    }

    /**
     * Confirm or change the vehicle class a driver operates in. Drivers choose
     * a preferred class during onboarding; after that only E-tiGo may change it.
     */
    public function updateVehicleClass(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_class_id' => ['required_without:fuel_type', 'uuid', Rule::exists('vehicle_classes', 'id')->where('is_active', true)],
            'fuel_type' => ['required_without:vehicle_class_id', Rule::enum(FuelType::class)],
        ]);

        $vehicle = $driver->vehicle;

        if (! $vehicle) {
            return response()->json(['message' => 'Driver has no registered vehicle.'], 422);
        }

        // The class decides the fares of the ride in progress; fuel type does not.
        $hasActiveRide = isset($validated['vehicle_class_id']) && Ride::where('driver_id', $driver->user_id)
            ->whereIn('status', RideStatus::activeStatuses())
            ->exists();

        if ($hasActiveRide) {
            return response()->json([
                'message' => 'The vehicle class cannot be changed while the driver is on an active ride.',
            ], 409);
        }

        $changes = array_filter([
            'vehicle_class_id' => $validated['vehicle_class_id'] ?? null,
            'fuel_type' => $validated['fuel_type'] ?? null,
        ]);

        DB::transaction(function () use ($vehicle, $changes, $request, $driver) {
            $old = ['vehicle_class_id' => $vehicle->vehicle_class_id, 'fuel_type' => $vehicle->fuel_type?->value];
            $vehicle->update($changes);

            // The driver too, since shared fleet cars pass between drivers.
            AuditLog::record(
                $vehicle,
                isset($changes['vehicle_class_id']) ? 'vehicle_class_changed' : 'vehicle_fuel_type_changed',
                $request->user(),
                array_intersect_key($old, $changes),
                [...$changes, 'driver_id' => $driver->id],
            );
        });

        $vehicle = $vehicle->fresh('vehicleClass');

        // Saved anyway, as when moving a driver to a city without their class.
        $warnings = [];
        $city = $driver->city;
        if (isset($changes['vehicle_class_id']) && $city && ! $city->vehicleClasses()->wherePivot('is_active', true)->whereKey($changes['vehicle_class_id'])->exists()) {
            $warnings[] = "{$city->name} does not offer {$vehicle->vehicleClass?->name}, so the driver will not get trips until it is offered there or their class changes.";
        }

        return response()->json([
            'message' => isset($changes['vehicle_class_id']) ? 'Vehicle class updated.' : 'Vehicle fuel type updated.',
            'vehicle' => new VehicleResource($vehicle),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Move a driver to another city (Phase 2 multi-city, OQ-22). Refused
     * while they are on a trip or hold upcoming reservations, which belong
     * to the old city. An online driver is taken offline, since their
     * location is in the old city's matching pool.
     */
    public function updateCity(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['required', 'uuid', Rule::exists('cities', 'id')],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $city = City::findOrFail($validated['city_id']);
        $admin = $request->user();

        // Checked under the driver's row lock, which ride assignment and
        // reservations also take, so neither can slip in during the move.
        $outcome = DB::transaction(function () use ($driver, $city, $admin, $validated) {
            $locked = Driver::lockForUpdate()->findOrFail($driver->id);

            if ($locked->city_id === $city->id) {
                return ['error' => response()->json(['message' => 'The driver is already in this city.'], 422)];
            }

            $hasActiveRide = Ride::where('driver_id', $locked->user_id)
                ->whereIn('status', RideStatus::activeStatuses())
                ->exists();

            if ($hasActiveRide) {
                return ['error' => response()->json([
                    'message' => 'The driver cannot change city during an active ride.',
                ], 409)];
            }

            $reservations = ScheduledRide::upcoming()
                ->where('reserved_driver_id', $locked->user_id)
                ->count();

            if ($reservations > 0) {
                return ['error' => response()->json([
                    'message' => "The driver has {$reservations} upcoming reserved bookings. Release them before changing city.",
                    'upcoming_reservations' => $reservations,
                ], 409)];
            }

            // Shared fleet cars stay in their city, so the driver's shifts
            // on them must be cancelled or given to someone else first.
            $shifts = $locked->shifts()->workable()->where('ends_at', '>', now())->count();

            if ($shifts > 0) {
                return ['error' => response()->json([
                    'message' => "The driver has {$shifts} upcoming fleet shifts. Cancel or reassign them before changing city.",
                    'upcoming_shifts' => $shifts,
                ], 409)];
            }

            $wasOnline = $locked->is_online;
            $oldCityId = $locked->city_id;

            $locked->update([
                'city_id' => $city->id,
                'is_online' => false,
            ]);

            // Plans for the old city would count there as cover that will
            // not come; the driver plans again for the new city.
            $plansRemoved = $locked->availabilityPlans()
                ->where('city_id', $oldCityId)
                ->where('ends_at', '>', now())
                ->delete();

            AuditLog::record(
                $locked,
                'driver_city_changed',
                $admin,
                ['city_id' => $oldCityId],
                ['city_id' => $city->id, 'reason' => $validated['reason'], 'availability_plans_removed' => $plansRemoved],
            );

            return ['was_online' => $wasOnline, 'plans_removed' => $plansRemoved];
        });

        if (isset($outcome['error'])) {
            return $outcome['error'];
        }

        $wasOnline = $outcome['was_online'];
        $driver->refresh();

        if ($wasOnline) {
            try {
                app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to remove moved driver from the live location index', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        app(NotificationService::class)->send(
            userId: $driver->user_id,
            type: NotificationType::DriverCityChanged,
            title: 'Your City Changed',
            body: match (true) {
                $city->launch_status->allowsDriversOnline() => "You now drive in {$city->name}. You will get trips there from now on.",
                $city->wasClosed() => "You now drive in {$city->name}. E-tiGo is not running there for now; you can go online when it reopens.",
                default => "You now drive in {$city->name}. E-tiGo has not launched there yet; you can go online from launch day.",
            }
            .($wasOnline ? ' You have been taken offline; go online again when you are in the city.' : ''),
            data: ['city_id' => (string) $city->id],
        );

        $driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city']);

        $warnings = [];
        $vehicleClassId = $driver->vehicle?->vehicle_class_id;
        if ($vehicleClassId && ! $city->vehicleClasses()->wherePivot('is_active', true)->whereKey($vehicleClassId)->exists()) {
            $warnings[] = "{$city->name} does not offer the driver's vehicle class ({$driver->vehicle->vehicleClass?->name}), so they will not get trips until it is offered or their class changes.";
        }
        if (! $city->launch_status->allowsDriversOnline()) {
            $warnings[] = $city->wasClosed()
                ? "{$city->name} is closed, so the driver cannot go online until it relaunches."
                : "{$city->name} has not launched yet, so the driver cannot go online until it is live.";
        }
        if ($outcome['plans_removed'] > 0) {
            $warnings[] = "The driver's {$outcome['plans_removed']} upcoming availability plan(s) in their old city were removed.";
        }

        return response()->json([
            'message' => "Driver moved to {$city->name}.",
            'driver' => new DriverResource($driver),
            'warnings' => $warnings,
        ]);
    }

    public function pendingReview(): JsonResponse
    {
        $drivers = Driver::with(['user', 'documents', 'vehicle.vehicleClass', 'city'])
            ->where('status', DriverStatus::PendingReview)
            ->latest()
            ->paginate(20);

        return response()->json([
            'drivers' => DriverResource::collection($drivers),
            'meta' => [
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
                'per_page' => $drivers->perPage(),
                'total' => $drivers->total(),
            ],
        ]);
    }

    /**
     * Complete driver onboarding and approve KYC without external provider checks.
     * Note: Testing/QA only. Remove before production go-live.
     */
    public function completeOnboarding(CompleteDriverOnboardingRequest $request, Driver $driver): JsonResponse
    {
        if (app()->isProduction()) {
            return response()->json([
                'message' => 'Manual onboarding and KYC bypass is disabled in production.',
            ], 403);
        }

        if ($driver->isSuspended()) {
            return response()->json([
                'message' => 'Driver is currently suspended. Please reactivate the driver instead.',
            ], 422);
        }

        if ($driver->isApproved() && $driver->isKycVerified()) {
            return response()->json([
                'message' => 'Driver onboarding and KYC are already completed and approved.',
                'driver' => new DriverResource($driver->load(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications'])),
            ]);
        }

        $missingRequirements = [];

        $ownershipType = $request->input('vehicle_ownership_type')
            ? VehicleOwnershipType::tryFrom($request->input('vehicle_ownership_type'))
            : ($driver->vehicle_ownership_type ?? ($driver->vehicle ? ($driver->vehicle->is_fleet ? VehicleOwnershipType::FleetVehicle : VehicleOwnershipType::OwnVehicle) : null));

        if (! $ownershipType) {
            $missingRequirements[] = 'Vehicle ownership type must be selected.';
        }

        if (! $driver->licence_number) {
            $missingRequirements[] = 'Licence number must be provided.';
        }

        if (! $driver->city_id) {
            $missingRequirements[] = 'City must be selected.';
        }

        $isOwnVehicle = $ownershipType === VehicleOwnershipType::OwnVehicle;
        $isFleetVehicle = $ownershipType === VehicleOwnershipType::FleetVehicle;

        if ($isOwnVehicle && ! $driver->vehicle) {
            $missingRequirements[] = 'Vehicle details must be provided for own-vehicle drivers.';
        }

        $requiredDocTypes = ['driving_licence', 'government_id'];
        if (! $isFleetVehicle) {
            $requiredDocTypes[] = 'vehicle_registration';
            $requiredDocTypes[] = 'insurance_certificate';
        }

        $uploadedDocTypes = $driver->documents()
            ->where('status', '!=', DocumentStatus::Rejected)
            ->pluck('type')
            ->map(fn ($t) => is_object($t) ? $t->value : (string) $t)
            ->toArray();

        $missingDocs = array_diff($requiredDocTypes, $uploadedDocTypes);
        if (! empty($missingDocs)) {
            $missingRequirements[] = 'Missing required documents: '.implode(', ', $missingDocs).'.';
        }

        if (! empty($missingRequirements)) {
            return response()->json([
                'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
                'errors' => $missingRequirements,
            ], 422);
        }

        $admin = $request->user();
        $oldStatus = $driver->status;
        $oldKycStatus = $driver->kyc_status;
        $notes = $request->input('notes', 'Admin manual onboarding and KYC bypass');
        $providedNin = $request->input('nin');

        DB::transaction(function () use ($driver, $admin, $oldStatus, $oldKycStatus, $notes, $providedNin, $ownershipType, $isFleetVehicle) {
            $driver->documents()
                ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Rejected])
                ->update([
                    'status' => DocumentStatus::Approved,
                    'rejection_reason' => null,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

            if ($driver->vehicle && ! $driver->vehicle->vehicle_class_id) {
                $defaultClass = VehicleClass::where('is_active', true)->first();
                if ($defaultClass) {
                    $driver->vehicle->update(['vehicle_class_id' => $defaultClass->id]);
                }
            }

            $requiredKycTypes = [
                KycVerificationType::Nin,
                KycVerificationType::DriversLicense,
            ];
            if (! $isFleetVehicle && $driver->vehicle && ! $driver->vehicle->is_fleet) {
                $requiredKycTypes[] = KycVerificationType::VehiclePlate;
            }

            foreach ($requiredKycTypes as $type) {
                $existing = $driver->kycVerifications()->where('type', $type)->latest()->first();

                $idNumber = match ($type) {
                    KycVerificationType::Nin => $providedNin ?? $existing?->id_number ?? 'ADMIN_BYPASS_NIN',
                    KycVerificationType::DriversLicense => $driver->licence_number,
                    KycVerificationType::VehiclePlate => $driver->vehicle?->plate_number ?? 'ADMIN_BYPASS_PLATE',
                    default => 'ADMIN_BYPASS',
                };

                if ($existing) {
                    $existing->update([
                        'id_number' => $idNumber,
                        'status' => KycVerificationStatus::Verified,
                        'provider_reference' => $existing->provider_reference ?? 'admin_bypass',
                        'match_data' => array_merge($existing->match_data ?? [], [
                            'admin_bypass' => true,
                            'bypassed_by' => $admin->id,
                            'bypassed_at' => now()->toIso8601String(),
                            'notes' => $notes,
                        ]),
                        'failure_reason' => null,
                        'verified_at' => now(),
                    ]);
                } else {
                    $driver->kycVerifications()->create([
                        'type' => $type,
                        'id_number' => $idNumber,
                        'provider_reference' => 'admin_bypass',
                        'status' => KycVerificationStatus::Verified,
                        'match_data' => [
                            'admin_bypass' => true,
                            'bypassed_by' => $admin->id,
                            'bypassed_at' => now()->toIso8601String(),
                            'notes' => $notes,
                        ],
                        'verified_at' => now(),
                    ]);
                }
            }

            $driver->update([
                'vehicle_ownership_type' => $ownershipType,
                'status' => DriverStatus::Approved,
                'kyc_status' => KycStatus::Verified,
                'kyc_verified_at' => now(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            AuditLog::record(
                $driver,
                'driver_onboarding_and_kyc_completed_by_admin',
                $admin,
                [
                    'status' => $oldStatus->value,
                    'kyc_status' => $oldKycStatus->value,
                ],
                [
                    'status' => DriverStatus::Approved->value,
                    'kyc_status' => KycStatus::Verified->value,
                    'notes' => $notes,
                ],
            );
        });

        $freshDriver = $driver->fresh(['user', 'documents', 'vehicle.vehicleClass', 'city', 'kycVerifications']);

        try {
            if ($freshDriver->user?->email) {
                $freshDriver->user->notify(new DriverApprovedNotification($freshDriver));
            }
        } catch (\Throwable $e) {
            Log::warning('Driver approval email failed', ['driver_id' => $freshDriver->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Driver onboarding and KYC marked as completed successfully.',
            'driver' => new DriverResource($freshDriver),
        ]);
    }

    public function tripHistory(Request $request, Driver $driver): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = Ride::where('driver_id', $driver->user_id)
            ->with(['passenger', 'city', 'vehicleClass']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $rides = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }
}
