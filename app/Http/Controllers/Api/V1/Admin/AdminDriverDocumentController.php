<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverDocumentResource;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Services\DriverDocumentComplianceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Driver papers across all drivers, for the renewals queue: renewals from
 * working drivers waiting for review, papers expiring soon or expired, and
 * approved licences still missing their expiry date. Review a paper with
 * POST /admin/drivers/{driver}/documents/{document}/review.
 */
class AdminDriverDocumentController extends Controller
{
    /**
     * Papers across drivers, filtered for the renewals queue, with a summary of counts for the tab badges.
     */
    public function index(Request $request, DriverDocumentComplianceService $compliance): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'type' => ['sometimes', 'nullable', Rule::enum(DocumentType::class)],
            'expiry' => ['sometimes', 'nullable', Rule::in(['expiring_soon', 'expired', 'no_expiry'])],
            'renewals_only' => ['sometimes', 'in:true,false,1,0'],
            'city_id' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $settings = AppSetting::driverDocuments();
        $today = $compliance->today();
        $soon = $today->copy()->addDays(max($settings['reminder_days'] ?: [0]))->toDateString();
        $expiry = $validated['expiry'] ?? null;

        $query = DriverDocument::query()
            ->with(['driver.user', 'driver.city', 'vehicle:id,plate_number,is_fleet'])
            ->when($validated['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            // Expiry filters are about papers in force.
            ->when($expiry !== null, fn (Builder $q) => $q->where('status', DocumentStatus::Approved)->whereNull('revoked_at'))
            ->when($validated['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($expiry === 'expiring_soon', fn (Builder $q) => $q->whereDate('expires_at', '>=', $today->toDateString())->whereDate('expires_at', '<=', $soon))
            ->when($expiry === 'expired', fn (Builder $q) => $q->whereDate('expires_at', '<', $today->toDateString()))
            ->when($expiry === 'no_expiry', fn (Builder $q) => $q->whereNull('expires_at'))
            // Renewals: uploads from drivers already on the road.
            ->when($request->boolean('renewals_only'), fn (Builder $q) => $q->whereHas('driver', fn (Builder $d) => $d->whereNotIn('status', [DriverStatus::Onboarding, DriverStatus::PendingReview])))
            ->when($validated['city_id'] ?? null, fn (Builder $q, string $city) => $q->whereHas('driver', fn (Builder $d) => $d->where('city_id', $city)))
            ->when($validated['search'] ?? null, fn (Builder $q, string $search) => $q->whereHas('driver', fn (Builder $d) => $d->where('reference_number', 'ilike', "%{$search}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%"))));

        // Pending: oldest first (a queue). Expiry views: soonest expiry first.
        $expiry !== null
            ? $query->orderBy('expires_at')
            : $query->orderBy(($validated['status'] ?? null) === DocumentStatus::Pending->value ? 'created_at' : 'updated_at', ($validated['status'] ?? null) === DocumentStatus::Pending->value ? 'asc' : 'desc');

        $documents = $query->orderBy('id')->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'documents' => $documents->getCollection()->map(fn (DriverDocument $document) => [
                ...(new DriverDocumentResource($document))->toArray($request),
                'vehicle' => $document->vehicle ? ['id' => $document->vehicle->id, 'plate_number' => $document->vehicle->plate_number, 'is_fleet' => (bool) $document->vehicle->is_fleet] : null,
                'expiry_status' => $compliance->expiryStatus($document, $settings),
                'days_until_expiry' => $document->expires_at !== null ? $compliance->daysUntilExpiry($document) : null,
                'is_renewal' => $document->driver !== null && ! in_array($document->driver->status, [DriverStatus::Onboarding, DriverStatus::PendingReview], true),
                'driver' => $document->driver ? [
                    'id' => $document->driver->id,
                    'reference_number' => $document->driver->reference_number,
                    'name' => trim(($document->driver->user?->first_name ?? '').' '.($document->driver->user?->last_name ?? '')),
                    'phone' => $document->driver->user?->phone,
                    'status' => $document->driver->status->value,
                    'city' => $document->driver->city ? ['id' => $document->driver->city->id, 'name' => $document->driver->city->name] : null,
                    'documents_blocked' => $document->driver->isDocumentsBlocked(),
                ] : null,
            ])->values(),
            'summary' => [
                'pending_renewals' => DriverDocument::where('status', DocumentStatus::Pending)
                    ->whereHas('driver', fn (Builder $d) => $d->whereNotIn('status', [DriverStatus::Onboarding, DriverStatus::PendingReview]))
                    ->count(),
                'expiring_soon' => DriverDocument::where('status', DocumentStatus::Approved)->whereNull('revoked_at')
                    ->whereDate('expires_at', '>=', $today->toDateString())->whereDate('expires_at', '<=', $soon)->count(),
                'expired' => DriverDocument::where('status', DocumentStatus::Approved)->whereNull('revoked_at')
                    ->whereDate('expires_at', '<', $today->toDateString())->count(),
                'licences_missing_expiry' => DriverDocument::where('status', DocumentStatus::Approved)->whereNull('revoked_at')
                    ->where('type', DocumentType::DrivingLicence)->whereNull('expires_at')->count(),
                'blocked_drivers' => Driver::whereNotNull('documents_blocked_at')->count(),
            ],
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }
}
