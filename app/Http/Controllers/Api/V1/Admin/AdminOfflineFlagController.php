<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\PushNotificationGateway;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\OfflineFlagStatus;
use App\Enums\SanctionTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfflineFlag\ConfirmDeactivationFormRequest;
use App\Http\Requests\OfflineFlag\DeEscalateFlagFormRequest;
use App\Http\Requests\OfflineFlag\EscalateFlagFormRequest;
use App\Http\Requests\OfflineFlag\ReviewFlagFormRequest;
use App\Http\Requests\OfflineFlag\VerifyCashTakingFormRequest;
use App\Http\Resources\OfflineFlagResource;
use App\Models\AuditLog;
use App\Models\OfflineTripFlag;
use App\Services\DriverLocationService;
use App\Services\SanctionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminOfflineFlagController extends Controller
{
    /**
     * Audit events that make up a flag's history, with how each reads.
     */
    private const HISTORY_EVENTS = [
        'offline_trip_flagged' => 'Flagged by automatic detection',
        'offline_flag_disputed' => 'Disputed by the driver',
        'offline_flag_reviewed' => 'Reviewed',
        'offline_flag_escalated' => 'Escalated',
        'offline_flag_de_escalated' => 'De-escalated',
        'offline_flag_deactivation_confirmed' => 'Deactivation confirmed',
        'offline_flag_cash_taking_verified' => 'Cash-taking verified',
    ];

    /**
     * What the flag page and every action's response include.
     */
    private const DETAIL_RELATIONS = [
        'driver.driver.city',
        'passenger',
        'ride.city',
        'ride.vehicleClass',
        'resolvedBy',
        'deactivationConfirmedBy',
        'cashTakingVerifiedBy',
    ];

    public function __construct(
        private readonly SanctionService $sanctionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            // "active" (not yet resolved) is kept from before the derived status.
            'status' => ['nullable', Rule::in([...array_column(OfflineFlagStatus::cases(), 'value'), 'active'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $flags = OfflineTripFlag::query()
            ->with(['driver', 'passenger', 'ride', 'resolvedBy', 'deactivationConfirmedBy', 'cashTakingVerifiedBy'])
            ->when($request->query('sanction_tier'), function ($query, $tier) {
                $query->where('sanction_tier', $tier);
            })
            ->when($request->query('is_disputed'), function ($query, $disputed) {
                $query->where('is_disputed', filter_var($disputed, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->query('dispute_outcome'), function ($query, $outcome) {
                $query->where('dispute_outcome', $outcome);
            })
            ->when($request->query('driver_id'), function ($query, $driverId) {
                $query->where('driver_id', $driverId);
            })
            ->when($request->query('date_from'), function ($query, $date) {
                $query->where('flagged_at', '>=', $date);
            })
            ->when($request->query('date_to'), function ($query, $date) {
                $query->where('flagged_at', '<=', $date);
            })
            ->when($request->query('status'), function ($query, $status) {
                $status === 'active'
                    ? $query->active()
                    : $query->withStatus(OfflineFlagStatus::from($status));
            })
            ->when($request->query('search'), fn ($query, $search) => $query->search($search))
            ->orderBy('flagged_at', 'desc')
            ->paginate($this->perPage($request));

        return response()->json([
            'message' => 'Offline trip flags retrieved.',
            'flags' => OfflineFlagResource::collection($flags),
            'meta' => [
                'current_page' => $flags->currentPage(),
                'last_page' => $flags->lastPage(),
                'per_page' => $flags->perPage(),
                'total' => $flags->total(),
            ],
        ]);
    }

    public function show(OfflineTripFlag $flag): JsonResponse
    {
        $flag->load(self::DETAIL_RELATIONS);

        $lookbackDays = (int) config('offline_detection.sanction_lookback_days', 30);

        $previousFlags = OfflineTripFlag::forDriver($flag->driver_id)
            ->whereKeyNot($flag->id)
            ->where('flagged_at', '>=', $flag->flagged_at->copy()->subDays($lookbackDays))
            ->where('flagged_at', '<=', $flag->flagged_at)
            ->with('ride:id,reference_number')
            ->latest('flagged_at')
            ->get()
            ->map(fn (OfflineTripFlag $previous) => [
                'id' => $previous->id,
                'reference_number' => $previous->reference_number,
                'ride_reference_number' => $previous->ride?->reference_number,
                'flagged_at' => $previous->flagged_at?->toISOString(),
                'sanction_tier' => [
                    'value' => $previous->sanction_tier->value,
                    'label' => $previous->sanction_tier->label(),
                ],
                'status' => [
                    'value' => $previous->status()->value,
                    'label' => $previous->status()->label(),
                ],
            ]);

        return response()->json([
            'message' => 'Offline trip flag details retrieved.',
            'flag' => new OfflineFlagResource($flag),
            // The driver's other flags in the 30 days up to this one.
            'previous_flags' => $previousFlags,
            'previous_flags_window_days' => $lookbackDays,
            'route_map' => $flag->routeMap(),
            'history' => $this->history($flag),
        ]);
    }

    public function review(ReviewFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();
        $outcome = DisputeOutcome::from($request->validated('outcome'));
        $notes = $request->validated('notes');

        $oldValues = [
            'dispute_outcome' => $flag->dispute_outcome?->value,
        ];

        DB::transaction(function () use ($flag, $admin, $outcome, $notes, $oldValues) {
            $flag->update([
                'dispute_outcome' => $outcome,
                'review_notes' => $notes,
                'dispute_resolved_by_admin_id' => $admin->id,
                'resolved_at' => now(),
            ]);

            if ($outcome === DisputeOutcome::Overturned) {
                $this->sanctionService->reverseSanction($flag);
            }

            AuditLog::record($flag, 'offline_flag_reviewed', $admin, $oldValues, [
                'dispute_outcome' => $outcome->value,
                'resolution_notes' => $notes,
            ]);
        });

        $flag->load(self::DETAIL_RELATIONS);

        return response()->json([
            'message' => ($flag->is_disputed ? 'Dispute' : 'Flag')." {$outcome->label()}.",
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    public function escalate(EscalateFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();

        $oldTier = $flag->sanction_tier;
        $newTier = SanctionTier::from($oldTier->value + 1);

        DB::transaction(function () use ($request, $flag, $admin, $oldTier, $newTier) {
            $flag->update([
                'sanction_tier' => $newTier,
                'sanction_action' => $newTier->action(),
                ...($newTier === SanctionTier::Deactivation ? ['deactivation_response_due_at' => OfflineTripFlag::newResponseDeadline()] : []),
            ]);

            $this->sanctionService->enforceSanction($flag->driver_id, $newTier);

            AuditLog::record($flag, 'offline_flag_escalated', $admin, [
                'sanction_tier' => $oldTier->value,
            ], [
                'sanction_tier' => $newTier->value,
                'notes' => $request->validated('notes'),
            ]);
        });

        $flag->load(self::DETAIL_RELATIONS);

        return response()->json([
            'message' => "Sanction escalated to {$newTier->label()}.",
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    public function deEscalate(DeEscalateFlagFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();

        $oldTier = $flag->sanction_tier;
        $newTier = SanctionTier::from($oldTier->value - 1);

        DB::transaction(function () use ($request, $flag, $admin, $oldTier, $newTier) {
            $flag->update([
                'sanction_tier' => $newTier,
                'sanction_action' => $newTier->action(),
                'deactivation_response_due_at' => null,
            ]);

            $this->sanctionService->deEscalate($flag, $newTier);

            AuditLog::record($flag, 'offline_flag_de_escalated', $admin, [
                'sanction_tier' => $oldTier->value,
            ], [
                'sanction_tier' => $newTier->value,
                'notes' => $request->validated('notes'),
            ]);
        });

        $flag->load(self::DETAIL_RELATIONS);

        return response()->json([
            'message' => "Sanction reduced to {$newTier->label()}.",
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    /**
     * Permanently deactivate the driver on a Level 3 flag. A flag nobody has
     * reviewed yet is upheld in the same step, with the same reason.
     */
    public function confirmDeactivation(ConfirmDeactivationFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        if ($refusal = $flag->confirmDeactivationRefusal($request->user())) {
            return response()->json(['message' => $refusal], 422);
        }

        $driver = $flag->driverProfile();

        if (! $driver) {
            return response()->json(['message' => 'Driver not found.'], 404);
        }

        $admin = $request->user();
        $notes = $request->validated('notes');
        $upholds = ! $flag->hasReviewOutcome();
        // Only a super admin gets this far before the driver's deadline.
        $early = $flag->awaitsDriverResponse();

        DB::transaction(function () use ($flag, $driver, $admin, $notes, $upholds, $early) {
            // A second click must not deactivate twice.
            $this->lockAndRecheck($flag, fn (OfflineTripFlag $locked) => $locked->confirmDeactivationRefusal($admin));

            $driver->update([
                'status' => DriverStatus::Deactivated,
                'is_online' => false,
                'suspension_reason' => SanctionService::DEACTIVATED_REASON,
            ]);

            $flag->update([
                'sanction_action' => 'deactivated',
                'deactivation_confirmed_at' => now(),
                'deactivation_confirmed_by_admin_id' => $admin->id,
                'deactivation_notes' => $notes,
                ...($upholds ? [
                    'dispute_outcome' => DisputeOutcome::Upheld,
                    'review_notes' => $notes,
                    'dispute_resolved_by_admin_id' => $admin->id,
                    'resolved_at' => now(),
                ] : []),
            ]);

            AuditLog::record($flag, 'offline_flag_deactivation_confirmed', $admin, [
                'status' => 'suspended',
            ], [
                'status' => 'deactivated',
                'confirmed_by' => $admin->id,
                'notes' => $notes,
                ...($upholds ? ['dispute_outcome' => DisputeOutcome::Upheld->value] : []),
                ...($early ? ['before_response_deadline' => true] : []),
            ]);
        });

        try {
            app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove deactivated driver from the live location index', [
                'driver_id' => $driver->id,
                'error' => $e->getMessage(),
            ]);
        }

        rescue(fn () => app(PushNotificationGateway::class)->sendToUser($driver->user_id, [
            'title' => 'Account Deactivated',
            'body' => 'Your driver account has been permanently deactivated following a compliance review.',
            'data' => ['type' => 'compliance_warning', 'deactivated' => true],
        ]));

        $flag->load(self::DETAIL_RELATIONS);

        return response()->json([
            'message' => 'Driver permanently deactivated after admin review.',
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    /**
     * Record that an admin verified the driver took cash for the trip (say,
     * the passenger confirmed it). That moves the flag straight to Level 3,
     * whatever the driver's earlier flags; deactivation still needs
     * confirming.
     */
    public function verifyCashTaking(VerifyCashTakingFormRequest $request, OfflineTripFlag $flag): JsonResponse
    {
        $admin = $request->user();
        $oldTier = $flag->sanction_tier;
        $newTier = SanctionTier::Deactivation;

        DB::transaction(function () use ($request, $flag, $admin, $oldTier, $newTier) {
            $this->lockAndRecheck($flag, fn (OfflineTripFlag $locked) => $locked->cashTakingRefusal());

            $flag->update([
                'cash_taking_verified_at' => now(),
                'cash_taking_verified_by_admin_id' => $admin->id,
                'cash_taking_notes' => $request->validated('notes'),
                'sanction_tier' => $newTier,
                'sanction_action' => $newTier->action(),
                ...($oldTier !== $newTier ? ['deactivation_response_due_at' => OfflineTripFlag::newResponseDeadline()] : []),
            ]);

            if ($oldTier !== $newTier) {
                $this->sanctionService->enforceSanction($flag->driver_id, $newTier);
            }

            AuditLog::record($flag, 'offline_flag_cash_taking_verified', $admin, [
                'sanction_tier' => $oldTier->value,
            ], [
                'sanction_tier' => $newTier->value,
                'notes' => $request->validated('notes'),
            ]);
        });

        $flag->load(self::DETAIL_RELATIONS);

        return response()->json([
            'message' => $oldTier === $newTier
                ? 'Cash-taking verified.'
                : 'Cash-taking verified. The flag is now Level 3; confirm the deactivation to make it permanent.',
            'flag' => new OfflineFlagResource($flag),
        ]);
    }

    /**
     * Lock the flag's row and check the action is still allowed, in case
     * another request changed the flag since the first check.
     *
     * @param  callable(OfflineTripFlag): ?string  $refusal
     */
    private function lockAndRecheck(OfflineTripFlag $flag, callable $refusal): void
    {
        $locked = OfflineTripFlag::whereKey($flag->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('driver', $flag->driver);

        if ($message = $refusal($locked)) {
            throw new HttpResponseException(response()->json(['message' => $message], 422));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(OfflineTripFlag $flag): array
    {
        return AuditLog::query()
            ->where('auditable_type', $flag->getMorphClass())
            ->where('auditable_id', $flag->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->with('actor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (AuditLog $log) {
                $new = $log->new_values ?? [];
                $old = $log->old_values ?? [];

                $description = match (true) {
                    $log->event === 'offline_flag_reviewed' && isset($new['dispute_outcome']) => ucfirst($new['dispute_outcome'])
                        .(($old['dispute_outcome'] ?? null) === DisputeOutcome::Upheld->value ? ' on appeal' : ''),
                    $log->event === 'offline_flag_deactivation_confirmed' && isset($new['dispute_outcome']) => 'Upheld and deactivation confirmed',
                    default => self::HISTORY_EVENTS[$log->event],
                };
                if (! empty($new['before_response_deadline'])) {
                    $description .= " before the driver's response deadline";
                }
                if (isset($old['sanction_tier'], $new['sanction_tier']) && $old['sanction_tier'] !== $new['sanction_tier']) {
                    $description .= " from Level {$old['sanction_tier']} to Level {$new['sanction_tier']}";
                }

                return [
                    'event' => $log->event,
                    'description' => $description,
                    'notes' => $new['notes'] ?? $new['resolution_notes'] ?? $new['dispute_notes'] ?? null,
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => trim($log->actor->first_name.' '.$log->actor->last_name),
                        'role' => $log->actor->admin_role?->value ?? $log->actor->type?->value,
                    ] : null,
                    'created_at' => $log->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }
}
