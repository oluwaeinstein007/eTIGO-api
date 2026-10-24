<?php

namespace Database\Seeders;

use App\Enums\DisputeCategory;
use App\Enums\DisputeEvidenceType;
use App\Enums\DisputeStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\Dispute;
use App\Models\DisputeNote;
use App\Models\Ride;
use App\Models\User;
use App\Services\DisputeService;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Disputes for the admin Disputes screens, each taken through DisputeService
 * at the time it happened, so queues, targets, audit history, refunds and
 * their ledger postings are what the app itself would have recorded:
 *
 * new and unanswered, past its first-response target, under review with a
 * note, escalated to tier 2, a safety report, resolved with a wallet refund,
 * a refund over the agent's limit waiting for approval, a refund turned
 * down, dismissed, appealed, and one filed by a driver.
 *
 * Only trips with @ops-demo.etigo.com drivers are used, so refunds and
 * clawbacks never touch accounts testers use.
 *
 * php artisan db:seed --class=DisputesDemoSeeder
 */
class DisputesDemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    private DisputeService $disputes;

    /** @var Collection<int, Ride> */
    private Collection $rides;

    public function run(DisputeService $disputes): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping DisputesDemoSeeder in production.');

            return;
        }

        $this->disputes = $disputes;

        $admins = User::whereIn('email', ['support@etigo.com', 'supportlead@etigo.com', 'safety@etigo.com'])->get()->keyBy('email');

        if ($admins->count() < 3) {
            $this->command?->warn('Run AdminSeeder first.');

            return;
        }

        if (Dispute::whereHas('ride.driver', fn ($q) => $q->where('email', 'like', '%@ops-demo.etigo.com'))->exists()) {
            return;
        }

        $this->rides = Ride::where('status', RideStatus::Completed)
            ->whereHas('driver', fn ($q) => $q->where('email', 'like', '%@ops-demo.etigo.com'))
            ->whereDoesntHave('disputes')
            ->with('passenger', 'driver', 'payment')
            ->orderByDesc('completed_at')
            ->get();

        if ($this->rides->count() < 11) {
            $this->command?->warn('Run CityActivityDemoSeeder first.');

            return;
        }

        // One transaction: a failure leaves nothing behind, so a rerun starts clean.
        DB::transaction(fn () => $this->seedCases($admins));
    }

    /**
     * @param  Collection<string, User>  $admins
     */
    private function seedCases(Collection $admins): void
    {
        $support = $admins['support@etigo.com'];
        $lead = $admins['supportlead@etigo.com'];
        $safety = $admins['safety@etigo.com'];
        $tierOneLimit = AppSetting::disputes()['tier_1_refund_limit'];

        // New: filed three hours ago, nobody has picked it up.
        $this->file(now()->subHours(3), DisputeCategory::FareDispute,
            'The app showed about ₦4,800 before I booked but I was charged more. Same route I take every day.', screenshot: true);

        // Past its first-response target: the job flags it.
        $this->file(now()->subHours(30), DisputeCategory::RouteDeviation,
            'Driver went through Ojuelegba instead of the bridge and the trip took 40 minutes longer.');

        // Under review, with an internal note.
        $dispute = $this->file(now()->subDay()->setTime(10, 5), DisputeCategory::DriverBehaviour,
            'Driver was rude when I asked him to turn down the music and drove off before I could take my bag out properly.');
        $this->act(now()->subDay()->setTime(11, 20), fn () => $this->disputes->assign($dispute, $support));
        $this->note($dispute, $support, now()->subDay()->setTime(11, 35), 'Called the passenger: confirms the bag was retrieved. Waiting on the driver\'s side.');

        // Escalated to tier 2.
        $dispute = $this->file(now()->subDays(2)->setTime(9, 40), DisputeCategory::VehicleCondition,
            'Back door would not open from inside and the AC was off the whole trip in this heat.');
        $this->act(now()->subDays(2)->setTime(10, 30), fn () => $this->disputes->assign($dispute, $support));
        $this->act(now()->subDays(2)->setTime(15, 0), fn () => $this->disputes->escalate($dispute, $support, 'Third complaint about this car in two weeks; needs a decision on the vehicle.'));

        // Safety report, in the safety queue.
        $dispute = $this->file(now()->subHours(6), DisputeCategory::SafetyConcern,
            'Driver was speeding and overtaking dangerously on the expressway even after I asked him to slow down.');
        $this->act(now()->subHours(6)->addMinutes(12), fn () => $this->disputes->assign($dispute, $safety));

        // Resolved with a wallet refund inside the agent's limit.
        $dispute = $this->file(now()->subDays(4)->setTime(20, 10), DisputeCategory::FareDispute,
            'Driver kept the meter running while we waited for him to buy fuel.', minFare: 3000);
        $this->act(now()->subDays(4)->setTime(21, 0), fn () => $this->disputes->assign($dispute, $support));
        $this->act(now()->subDays(3)->setTime(10, 15), fn () => $this->disputes->resolve($dispute, $support, DisputeStatus::Resolved,
            'Trip log shows an 11-minute stop at a filling station. Refunded the waiting time to the wallet.', min(1500, $tierOneLimit)));

        // A refund over the agent's limit, waiting for a support lead.
        $dispute = $this->file(now()->subDays(1)->setTime(8, 30), DisputeCategory::FareDispute,
            'Charged for a full trip but the driver ended it halfway and asked me to come down.', minFare: $tierOneLimit + 1000);
        $this->act(now()->subDays(1)->setTime(9, 15), fn () => $this->disputes->assign($dispute, $support));
        $this->act(now()->subDays(1)->setTime(12, 0), fn () => $this->disputes->resolve($dispute, $support, DisputeStatus::Resolved,
            'Trip ended 6 km before the destination. Full refund to the wallet.', $tierOneLimit + 500));

        // A refund the support lead turned down: the case stays open.
        $dispute = $this->file(now()->subDays(3)->setTime(17, 45), DisputeCategory::FareDispute,
            'The fare was much higher than usual for this route.', minFare: $tierOneLimit + 1000);
        $this->act(now()->subDays(3)->setTime(18, 30), fn () => $this->disputes->assign($dispute, $support));
        $result = $this->act(now()->subDays(2)->setTime(11, 0), fn () => $this->disputes->resolve($dispute, $support, DisputeStatus::Resolved,
            'Passenger says the fare was higher than usual. Proposing a full refund.', $tierOneLimit + 500));
        $this->act(now()->subDays(2)->setTime(16, 20), fn () => $this->disputes->rejectRefund($result['refund_request'], $lead,
            'Route and time match the quote and there was no surge. Explain the fare to the passenger instead of refunding.'));

        // Dismissed after checking the payment.
        $dispute = $this->file(now()->subDays(5)->setTime(13, 0), DisputeCategory::PaymentIssue,
            'I think my card was charged twice for this trip.', method: PaymentMethod::Card);
        $this->act(now()->subDays(5)->setTime(14, 10), fn () => $this->disputes->assign($dispute, $support));
        $this->act(now()->subDays(5)->setTime(15, 30), fn () => $this->disputes->resolve($dispute, $support, DisputeStatus::Dismissed,
            'Only one charge on the gateway; the second was a card authorisation that dropped off within 24 hours.'));

        // Resolved without a refund, then appealed.
        $dispute = $this->file(now()->subDays(4)->setTime(7, 50), DisputeCategory::DriverBehaviour,
            'Driver refused to use the AC and said it costs him fuel.');
        $this->act(now()->subDays(4)->setTime(9, 0), fn () => $this->disputes->assign($dispute, $support));
        $this->act(now()->subDays(3)->setTime(16, 0), fn () => $this->disputes->resolve($dispute, $support, DisputeStatus::Resolved,
            'Driver warned about AC use. No refund: the trip was completed as quoted.'));
        $this->act(now()->subDays(2)->setTime(9, 25), fn () => $this->disputes->appeal($dispute->fresh(), $dispute->reportedBy,
            'A warning does not help me. I paid for a comfort ride and sat in the heat for 40 minutes.'));

        // Filed by a driver: the passenger paid less cash than the fare.
        $this->file(now()->subHours(20), DisputeCategory::PaymentIssue,
            'Passenger paid ₦2,000 cash and said he would transfer the rest. Nothing has come since.', byDriver: true, method: PaymentMethod::Cash);

        // Flag what is past its targets, as the scheduled job does.
        $this->disputes->processTargets();
    }

    private function file(Carbon $at, DisputeCategory $category, string $description, bool $screenshot = false, bool $byDriver = false, float $minFare = 0, ?PaymentMethod $method = null): Dispute
    {
        $ride = $this->rides->first(fn (Ride $ride) => (float) $ride->final_fare_amount >= $minFare
            && ($method === null || $ride->payment_method === $method)
            && $ride->completed_at->lt($at));

        if ($ride === null) {
            throw new \RuntimeException("No demo trip fits the {$category->value} dispute (fare from ₦{$minFare}). Run CityActivityDemoSeeder first.");
        }

        $this->rides = $this->rides->reject(fn (Ride $r) => $r->id === $ride->id)->values();

        $reporter = $byDriver ? $ride->driver : $ride->passenger;
        $evidence = $screenshot ? [[
            'type' => DisputeEvidenceType::Photo,
            'path' => 'dispute-evidence/'.Str::uuid().'/fare-screenshot.jpg',
            'mime' => 'image/jpeg',
            'size' => 214_532,
            'name' => 'Screenshot_fare.jpg',
            'caption' => 'Fare shown before booking',
        ]] : [];

        return $this->at($at, fn () => $this->disputes->file($ride, $reporter, $category, $description, $evidence));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function act(Carbon $at, callable $callback): mixed
    {
        return $this->at($at, $callback);
    }

    private function note(Dispute $dispute, User $admin, Carbon $at, string $content): void
    {
        DisputeNote::create(['dispute_id' => $dispute->id, 'admin_id' => $admin->id, 'content' => $content])
            ->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }
}
