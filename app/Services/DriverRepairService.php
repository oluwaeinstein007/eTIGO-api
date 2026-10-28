<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairServiceType;
use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Models\AuditLog;
use App\Models\DriverRepair;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Repair and service requests drivers send from the app, and what E-tiGo
 * operations do with them: book a workshop visit, finish with a report, or
 * cancel. Each step updates the progress steps the driver app shows.
 */
class DriverRepairService
{
    use ResolvesUploadUrls;

    private const TIMEZONE = 'Africa/Lagos';

    public const SUPPORT_PHONE = '0812 5690 6790';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{type: string, title: string, details: string, preferred_date: string, preferred_time: string}  $data
     */
    public function submit(User $driver, array $data, ?UploadedFile $file): DriverRepair
    {
        $type = RepairServiceType::from($data['type']);
        $preferredTime = $data['preferred_time'];

        return DriverRepair::create([
            'driver_id' => $driver->id,
            'vehicle_id' => $driver->driver?->vehicle?->id,
            'reference_number' => DriverRepair::generateReferenceNumber(),
            'type' => $type,
            'status' => RepairRequestStatus::InProgress,
            'title' => $data['title'],
            'details' => $data['details'],
            'preferred_date' => $data['preferred_date'],
            'preferred_time' => $preferredTime,
            'summary_note' => "Request received · Workshop visit to be scheduled for {$preferredTime}",
            'workshop_visit' => [
                'scheduled_time' => $preferredTime,
                'instructions' => 'A technician is being assigned for your vehicle. Contact fleet support for workshop details.',
                'support_phone' => self::SUPPORT_PHONE,
            ],
            'progress_steps' => [
                $this->step('Request submitted', now()->format('j M Y')." · {$type->label()} received", 'completed'),
                $this->step('Technician assigned', 'Your request is being coordinated', 'in_progress'),
                $this->step('Workshop visit', "Scheduled for {$preferredTime}", 'upcoming'),
                $this->step('Inspection complete', 'Report will be available once completed', 'upcoming'),
            ],
            'attachments' => $file ? [$this->storeAttachment($file, $driver->id)] : [],
        ]);
    }

    /**
     * Book (or rebook) the workshop visit.
     *
     * @param  array{scheduled_at: string, workshop_name: string, workshop_address: string, technician_name?: ?string, contact_phone?: ?string, instructions?: ?string}  $data
     */
    public function schedule(DriverRepair $repair, User $admin, array $data): DriverRepair
    {
        $repair = DB::transaction(function () use ($repair, $admin, $data) {
            $repair = $this->lockOpen($repair, 'scheduled');
            $at = Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));
            $when = $this->describe($at);
            $technician = $data['technician_name'] ?? null;
            $phone = $data['contact_phone'] ?? null;
            $instructions = $data['instructions'] ?? null;
            $wasScheduled = $repair->status === RepairRequestStatus::Scheduled;

            $repair->update([
                'status' => RepairRequestStatus::Scheduled,
                'scheduled_at' => $at,
                'appointment' => [
                    'scheduled_at' => $at->toIso8601String(),
                    'date' => $at->copy()->setTimezone(self::TIMEZONE)->toDateString(),
                    'time' => $at->copy()->setTimezone(self::TIMEZONE)->format('h:i a'),
                    'workshop_name' => $data['workshop_name'],
                    'workshop_address' => $data['workshop_address'],
                    'technician_name' => $technician,
                    'contact_phone' => $phone,
                    'instructions' => $instructions,
                ],
                'workshop_visit' => [
                    'scheduled_time' => $when,
                    'workshop_name' => $data['workshop_name'],
                    'address' => $data['workshop_address'],
                    'instructions' => $instructions ?? 'Bring your vehicle to the workshop at the booked time.',
                    'support_phone' => $phone ?? self::SUPPORT_PHONE,
                ],
                'summary_note' => "Workshop visit booked for {$when} at {$data['workshop_name']}",
                'progress_steps' => [
                    $this->step('Request submitted', $repair->created_at->copy()->setTimezone(self::TIMEZONE)->format('j M Y')." · {$repair->type->label()} received", 'completed'),
                    $this->step('Technician assigned', $technician ?? 'E-tiGo fleet support', 'completed'),
                    $this->step('Workshop visit', "{$when} · {$data['workshop_name']}", 'in_progress'),
                    $this->step('Inspection complete', 'Report will be available once completed', 'upcoming'),
                ],
                'handled_by_admin_id' => $admin->id,
            ]);

            AuditLog::record($repair, $wasScheduled ? 'repair_request.rescheduled' : 'repair_request.scheduled', $admin, null, [
                'scheduled_at' => $at->toIso8601String(),
                'workshop_name' => $data['workshop_name'],
            ]);

            return $repair;
        });

        $this->notify(
            $repair,
            NotificationType::RepairRequestScheduled,
            "Your {$this->kind($repair)} request {$repair->reference_number} is booked for {$repair->workshop_visit['scheduled_time']} at {$repair->appointment['workshop_name']}, {$repair->appointment['workshop_address']}.",
        );

        return $repair;
    }

    public function complete(DriverRepair $repair, User $admin, string $summary, ?UploadedFile $report): DriverRepair
    {
        $attachment = $report ? $this->storeAttachment($report, $repair->driver_id, 'Workshop report', uploadedBy: 'operations') : null;

        try {
            $repair = DB::transaction(function () use ($repair, $admin, $summary, $attachment) {
                $repair = $this->lockOpen($repair, 'completed');
                $steps = collect($repair->progress_steps ?? [])
                    ->map(fn (array $step) => [...$step, 'state' => 'completed'])
                    ->all();

                // The last step carries the outcome the driver reads.
                if ($steps !== []) {
                    $steps[array_key_last($steps)]['subtitle'] = $attachment ? 'Report available below' : Str::limit($summary, 80);
                }

                $repair->update([
                    'status' => RepairRequestStatus::Completed,
                    'completed_at' => now(),
                    'completion_summary' => $summary,
                    'summary_note' => 'Completed on '.now(self::TIMEZONE)->format('j M Y'),
                    'progress_steps' => $steps,
                    'attachments' => $attachment ? [...($repair->attachments ?? []), $attachment] : $repair->attachments,
                    'handled_by_admin_id' => $admin->id,
                ]);

                AuditLog::record($repair, 'repair_request.completed', $admin, null, ['report_attached' => $attachment !== null]);

                return $repair;
            });
        } catch (\Throwable $e) {
            if ($attachment) {
                Storage::disk(config('filesystems.uploads'))->delete($attachment['path']);
            }

            throw $e;
        }

        $this->notify(
            $repair,
            NotificationType::RepairRequestCompleted,
            "Your {$this->kind($repair)} request {$repair->reference_number} is complete: ".rtrim(Str::limit($summary, 160), '.').'.',
        );

        return $repair;
    }

    /**
     * Cancel a request that is still open. Operations must give a reason,
     * which the driver sees; a driver cancelling their own may add one.
     */
    public function cancel(DriverRepair $repair, User $by, ?string $reason): DriverRepair
    {
        $byOperations = $by->isAdmin();

        $repair = DB::transaction(function () use ($repair, $by, $reason, $byOperations) {
            $repair = $this->lockOpen($repair, 'cancelled');

            $repair->update([
                'status' => RepairRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => $byOperations ? 'operations' : 'driver',
                'summary_note' => ($byOperations ? 'Cancelled by E-tiGo' : 'Cancelled by you').' on '.now(self::TIMEZONE)->format('j M Y'),
                'handled_by_admin_id' => $byOperations ? $by->id : $repair->handled_by_admin_id,
            ]);

            AuditLog::record($repair, 'repair_request.cancelled', $by, null, ['reason' => $reason, 'by' => $repair->cancelled_by]);

            return $repair;
        });

        if ($byOperations) {
            $this->notify(
                $repair,
                NotificationType::RepairRequestCancelled,
                "Your {$this->kind($repair)} request {$repair->reference_number} was cancelled: ".rtrim((string) $reason, '. ').'. Contact fleet support on '.self::SUPPORT_PHONE.' if you still need help.',
            );
        }

        return $repair;
    }

    /**
     * Store a photo or document for a request. Files operations add (a
     * workshop report, say) are marked so the driver cannot remove them.
     *
     * @return array<string, mixed>
     */
    public function storeAttachment(UploadedFile $file, string $driverId, ?string $customTitle = null, string $uploadedBy = 'driver'): array
    {
        $path = $file->store("repairs/{$driverId}", config('filesystems.uploads'));
        $extension = strtolower($file->getClientOriginalExtension());
        $isPdf = $extension === 'pdf';
        $fileType = strtoupper($extension ?: ($isPdf ? 'PDF' : 'JPG'));
        $originalName = $file->getClientOriginalName();
        $rawTitle = $isPdf ? (pathinfo($originalName, PATHINFO_FILENAME) ?: 'Document') : ($originalName ?: 'Photo attachment');
        $title = $customTitle ?: $this->shortenFileName($rawTitle);
        $subtitle = $isPdf ? 'PDF · Document attached' : "{$fileType} · Image attached";

        return [
            'id' => (string) Str::uuid(),
            'title' => $title,
            'file_type' => $fileType,
            'subtitle' => $subtitle,
            'path' => $path,
            'file_url' => $this->resolveUploadUrl($path),
            'uploaded_by' => $uploadedBy,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function lockOpen(DriverRepair $repair, string $action): DriverRepair
    {
        $repair = DriverRepair::lockForUpdate()->findOrFail($repair->id);

        if (! $repair->isOpen()) {
            throw new \RuntimeException("This request is already {$repair->status->value}, so it cannot be {$action}.");
        }

        return $repair;
    }

    /**
     * @return array{title: string, subtitle: string, state: string}
     */
    private function step(string $title, string $subtitle, string $state): array
    {
        return ['title' => $title, 'subtitle' => $subtitle, 'state' => $state];
    }

    private function describe(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::TIMEZONE)->format('D j M, h:i a');
    }

    private function kind(DriverRepair $repair): string
    {
        return mb_strtolower($repair->type->label());
    }

    private function notify(DriverRepair $repair, NotificationType $type, string $body): void
    {
        rescue(fn () => $this->notifications->send(
            userId: $repair->driver_id,
            type: $type,
            title: $type->label(),
            body: $body,
            data: [
                'repair_id' => (string) $repair->id,
                'reference_number' => (string) $repair->reference_number,
            ],
        ));
    }

    private function shortenFileName(string $name, int $maxLength = 24): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $dotExt = $extension ? '.'.$extension : '';
        $baseName = pathinfo($name, PATHINFO_FILENAME);

        if ($dotExt && mb_strlen($dotExt) < $maxLength - 3) {
            $available = $maxLength - mb_strlen($dotExt) - 3;
            $prefixLen = (int) ceil($available * 0.6);
            $suffixLen = $available - $prefixLen;

            $prefix = mb_substr($baseName, 0, $prefixLen);
            $suffix = $suffixLen > 0 ? mb_substr($baseName, -$suffixLen) : '';

            return "{$prefix}...{$suffix}{$dotExt}";
        }

        return mb_substr($name, 0, $maxLength - 3).'...';
    }
}
