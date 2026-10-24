<?php

namespace Database\Seeders;

use App\Enums\LostItemCategory;
use App\Enums\LostItemClosureReason;
use App\Enums\LostItemStatus;
use App\Enums\RideStatus;
use App\Models\LostItem;
use App\Models\LostItemMessage;
use App\Models\Ride;
use App\Models\User;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Lost & Found reports in every status, each with the conversation the
 * passenger, driver and support had about it: just reported, waiting on the
 * driver, found and being returned, returned, resolved by support, not
 * found, escalated, closed unclaimed, and cancelled because it turned up.
 *
 * Rows are written directly (LostItemService texts the driver), on trips
 * with @ops-demo.etigo.com drivers.
 *
 * php artisan db:seed --class=LostAndFoundDemoSeeder
 */
class LostAndFoundDemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping LostAndFoundDemoSeeder in production.');

            return;
        }

        $rides = Ride::where('status', RideStatus::Completed)
            ->whereHas('driver', fn ($q) => $q->where('email', 'like', '%@ops-demo.etigo.com'))
            ->whereDoesntHave('disputes')
            ->whereNotIn('id', LostItem::select('ride_id'))
            ->with('passenger', 'driver')
            ->orderByDesc('completed_at')
            ->get();

        if (LostItem::whereIn('ride_id', Ride::whereHas('driver', fn ($q) => $q->where('email', 'like', '%@ops-demo.etigo.com'))->select('id'))->exists()) {
            return;
        }

        $support = User::where('email', 'support@etigo.com')->first();

        if ($rides->count() < 9 || ! $support) {
            $this->command?->warn('Run AdminSeeder and CityActivityDemoSeeder first.');

            return;
        }

        $reports = [
            [LostItemCategory::Phone, 'Black iPhone 13 in a clear case with a Davido sticker. I think it fell between the back seat and the door.', LostItemStatus::Reported, 1, [
                ['passenger', 'Please, the phone has my work line. I can come to wherever is convenient.'],
            ]],
            [LostItemCategory::Bag, 'Small brown leather laptop bag, Dell charger and a blue notebook inside. Left in the boot.', LostItemStatus::DriverNotified, 5, [
                ['passenger', 'I put it in the boot myself at Ikeja City Mall.'],
            ]],
            [LostItemCategory::WalletOrPurse, 'Black wallet with my driver\'s licence and two ATM cards.', LostItemStatus::DriverConfirmed, 20, [
                ['passenger', 'Is it there?'],
                ['driver', 'Yes, I found it under the front passenger seat. I can drop it at the Ikeja office tomorrow morning or meet you at Allen.'],
                ['passenger', 'Allen is fine, thank you so much. 10am?'],
                ['driver', 'Okay 10am at the Allen roundabout.'],
            ], 'Found it under the front seat.'],
            [LostItemCategory::Keys, 'House keys on a red Arsenal keyring, three keys.', LostItemStatus::Returned, 52, [
                ['driver', 'I have the keys. I will be around Yaba by 6pm.'],
                ['passenger', 'Perfect, I will wait at Sabo bus stop.'],
                ['driver', 'Handed over. Have a good evening.'],
            ], 'Keys were on the back seat.'],
            [LostItemCategory::Glasses, 'Prescription glasses in a grey case.', LostItemStatus::Resolved, 96, [
                ['driver', 'Found the glasses case.'],
                ['support', 'Driver and passenger confirmed the return. Closing this report.'],
            ], 'Glasses case was in the door pocket.'],
            [LostItemCategory::Clothing, 'Navy blue agbada, folded in a nylon bag.', LostItemStatus::DriverDenied, 30, [
                ['driver', 'I have checked the car twice, there is no nylon bag. I had two trips after that one.'],
                ['passenger', 'I am sure I left it there.'],
            ], 'Checked the boot and seats, nothing.'],
            [LostItemCategory::Electronics, 'JBL Bluetooth speaker, black.', LostItemStatus::DriverNotified, 60, [
                ['passenger', 'Driver has not replied since yesterday.'],
                ['support', 'We have called the driver and are waiting for him to check the car.'],
            ], null, 'The driver has not replied in 48 hours.'],
            [LostItemCategory::IdOrDocuments, 'Brown envelope with my NYSC certificate and transcripts.', LostItemStatus::Unresolved, 200, [
                ['driver', 'Nothing like that in the car.'],
                ['support', 'We could not trace the envelope. Closed as not found; the passenger can reopen with support if it turns up.'],
            ], 'Not in the car.'],
            [LostItemCategory::Jewellery, 'Gold bracelet with a small heart charm.', LostItemStatus::Cancelled, 40, [
                ['passenger', 'Sorry, I found it in my bag. Please cancel.'],
            ]],
        ];

        foreach ($reports as $report) {
            [$category, $description, $status, $hoursAgo, $messages] = $report;

            // The trip that ended just before the report: passengers notice
            // within an hour or two.
            $reportedBefore = now()->subHours($hoursAgo);
            $ride = $rides->first(fn (Ride $ride) => $ride->completed_at->lte($reportedBefore->copy()->subMinutes(90))) ?? $rides->last();
            $rides = $rides->reject(fn (Ride $r) => $r->id === $ride->id)->values();

            $this->report($ride, $category, $description, $status, $ride->completed_at->copy()->addMinutes(90), $messages, $report[5] ?? null, $report[6] ?? null, $support);
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $messages
     */
    private function report(Ride $ride, LostItemCategory $category, string $description, LostItemStatus $status, Carbon $reportedAt, array $messages, ?string $driverNote, ?string $escalation, User $support): void
    {
        $notified = $reportedAt->copy()->addMinutes(1);
        $responded = $reportedAt->copy()->addMinutes(45);
        $closedAt = $reportedAt->copy()->addHours(30);

        $answered = in_array($status, [LostItemStatus::DriverConfirmed, LostItemStatus::DriverDenied, LostItemStatus::Returned, LostItemStatus::Resolved, LostItemStatus::Unresolved], true);

        $item = $this->at($reportedAt, fn () => LostItem::create([
            'ride_id' => $ride->id,
            'passenger_id' => $ride->passenger_id,
            'driver_id' => $ride->driver_id,
            'reported_by' => LostItem::REPORTED_BY_PASSENGER,
            'reported_by_user_id' => $ride->passenger_id,
            'category' => $category,
            'description' => $description,
            'photos' => [],
            'contact_phone' => $ride->passenger->phone,
            'status' => $status,
            'driver_response' => $answered ? $driverNote : null,
            'driver_notified_at' => $status === LostItemStatus::Reported ? null : $notified,
            'driver_responded_at' => $answered ? $responded : null,
            'returned_at' => in_array($status, [LostItemStatus::Returned, LostItemStatus::Resolved], true) ? $closedAt->copy()->subHours(6) : null,
            'escalated_at' => $escalation ? $reportedAt->copy()->addHours(48) : null,
            'escalation_reason' => $escalation,
            'escalation_source' => $escalation ? 'system' : null,
            'resolved_at' => $status === LostItemStatus::Resolved ? $closedAt : null,
            'resolved_by_admin_id' => in_array($status, [LostItemStatus::Resolved, LostItemStatus::Unresolved], true) ? $support->id : null,
            'resolution_notes' => $status === LostItemStatus::Resolved ? 'Returned to the passenger in person.' : null,
            'closed_at' => $status === LostItemStatus::Unresolved ? $closedAt : null,
            'closure_reason' => $status === LostItemStatus::Unresolved ? LostItemClosureReason::NotFound : null,
            'cancelled_at' => $status === LostItemStatus::Cancelled ? $reportedAt->copy()->addHours(2) : null,
        ]));

        foreach ($messages as $n => [$role, $body]) {
            $sender = match ($role) {
                'driver' => $ride->driver_id,
                'support' => $support->id,
                default => $ride->passenger_id,
            };
            $at = $reportedAt->copy()->addMinutes(20 + $n * 35);

            LostItemMessage::create([
                'lost_item_id' => $item->id,
                'sender_user_id' => $sender,
                'sender_role' => $role === 'support' ? LostItemMessage::ROLE_SUPPORT : ($role === 'driver' ? LostItemMessage::ROLE_DRIVER : LostItemMessage::ROLE_PASSENGER),
                'body' => $body,
                'is_internal' => false,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }
    }
}
