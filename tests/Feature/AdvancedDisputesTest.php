<?php

use App\Enums\AdminRole;
use App\Enums\DisputeCategory;
use App\Enums\DisputeQueue;
use App\Enums\DisputeStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Jobs\ProcessDisputeTargetsJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Notifications\DisputeAlertNotification;
use App\Services\DisputeService;
use App\Services\WalletRefundService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function disputeAdmin(AdminRole $role, array $attributes = []): User
{
    return User::factory()->create(['type' => UserType::Admin, 'admin_role' => $role, 'is_active' => true] + $attributes);
}

function paidRide(User $passenger, User $driver, float $amount = 20000.00, PaymentMethod $method = PaymentMethod::Wallet): Ride
{
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $passenger->id,
        'driver_id' => $driver->id,
        'final_fare_amount' => $amount,
    ]);

    Payment::create([
        'ride_id' => $ride->id,
        'amount' => $amount,
        'currency' => 'NGN',
        'method' => $method->value,
        'tip_amount' => 0,
        'status' => PaymentStatus::Captured,
    ]);

    return $ride;
}

beforeEach(function () {
    Storage::fake(config('filesystems.uploads'));
    Notification::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->ride = paidRide($this->passenger, $this->driverUser);

    $this->agent = disputeAdmin(AdminRole::Support);
    $this->agent2 = disputeAdmin(AdminRole::Support);
    $this->lead = disputeAdmin(AdminRole::SupportLead);
    $this->lead2 = disputeAdmin(AdminRole::SupportLead);
    $this->safety = disputeAdmin(AdminRole::SafetyOperator);
    $this->superAdmin = disputeAdmin(AdminRole::SuperAdmin);
    $this->finance = disputeAdmin(AdminRole::Finance);
});

function caseFor(Ride $ride, User $reporter, array $attributes = []): Dispute
{
    return Dispute::factory()->create(['ride_id' => $ride->id, 'reported_by_user_id' => $reporter->id] + $attributes);
}

describe('filing and routing', function () {
    it('starts a normal dispute at support tier 1 with response targets', function () {
        $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$this->ride->id}/dispute", [
            'category' => 'fare_dispute',
            'description' => 'The fare was double the estimate shown.',
        ])->assertCreated()
            ->assertJsonPath('dispute.stage', 'review')
            ->assertJsonPath('dispute.stage_label', 'With our support team')
            ->assertJsonMissingPath('dispute.queue')
            ->assertJsonMissingPath('dispute.assigned_admin');

        $dispute = Dispute::firstOrFail();

        expect($dispute->queue)->toBe(DisputeQueue::Support)
            ->and($dispute->escalation_tier)->toBe(1)
            ->and($dispute->first_response_due_at->diffInHours($dispute->created_at, true))->toEqual(24)
            ->and($dispute->resolution_due_at->diffInHours($dispute->created_at, true))->toEqual(72);
    });

    it('sends a safety concern straight to the Safety team, linked to the SOS incident', function () {
        $incident = SosIncident::factory()->create(['ride_id' => $this->ride->id, 'triggered_by_user_id' => $this->passenger->id]);

        $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$this->ride->id}/dispute", [
            'category' => 'safety_concern',
            'description' => 'The driver was driving dangerously and shouting.',
        ])->assertCreated()
            ->assertJsonPath('dispute.stage_label', 'With our safety team');

        $dispute = Dispute::firstOrFail();

        expect($dispute->queue)->toBe(DisputeQueue::Safety)
            ->and($dispute->escalation_tier)->toBe(2)
            ->and($dispute->sos_incident_id)->toBe($incident->id)
            ->and((int) round($dispute->created_at->diffInMinutes($dispute->first_response_due_at)))->toBe(60);

        Notification::assertSentTo($this->safety, DisputeAlertNotification::class);
        Notification::assertNotSentTo($this->superAdmin, DisputeAlertNotification::class);
    });

    it('stores filing evidence as evidence rows and keeps the old photo field', function () {
        $this->actingAs($this->passenger)->post("/api/v1/rides/{$this->ride->id}/dispute", [
            'category' => 'vehicle_condition',
            'description' => 'The seats were torn and dirty throughout.',
            'photos' => [UploadedFile::fake()->create('seat.jpg', 120, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonCount(1, 'dispute.evidence')
            ->assertJsonCount(1, 'dispute.evidence_photos');

        expect(DisputeEvidence::count())->toBe(1)
            ->and(DisputeEvidence::first()->type->value)->toBe('photo');
    });

    it('links the passenger and driver disputes on one trip and gives them one owner and routing', function () {
        $passengerCase = caseFor($this->ride, $this->passenger, ['category' => DisputeCategory::FareDispute]);
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$passengerCase->id}/escalate", [
            'reason' => 'Repeat complaint about this driver.',
        ])->assertOk();
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$passengerCase->id}/assign")->assertOk();

        $this->actingAs($this->driverUser)->postJson("/api/v1/rides/{$this->ride->id}/dispute", [
            'category' => 'other',
            'description' => 'The passenger refused to pay the toll fee.',
        ])->assertCreated();

        $driverCase = Dispute::where('reported_by_user_id', $this->driverUser->id)->firstOrFail();

        expect($driverCase->linked_dispute_id)->toBe($passengerCase->id)
            ->and($passengerCase->fresh()->linked_dispute_id)->toBe($driverCase->id)
            ->and($driverCase->escalation_tier)->toBe(2)
            ->and($driverCase->assigned_admin_id)->toBe($this->lead->id);
    });
});

describe('visibility and tier permissions', function () {
    it('hides safety cases from support and shows them to the Safety team', function () {
        $safetyCase = caseFor($this->ride, $this->passenger, [])->fill(['category' => DisputeCategory::SafetyConcern, 'queue' => DisputeQueue::Safety, 'escalation_tier' => 2]);
        $safetyCase->save();

        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$safetyCase->id}")->assertForbidden();
        $this->actingAs($this->lead)->getJson('/api/v1/admin/disputes')->assertJsonCount(0, 'disputes');
        $this->actingAs($this->safety)->getJson('/api/v1/admin/disputes')
            ->assertJsonCount(1, 'disputes')
            ->assertJsonPath('summary.safety', 1);
        $this->actingAs($this->safety)->getJson("/api/v1/admin/disputes/{$safetyCase->id}/notes")->assertOk();
        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$safetyCase->id}/notes")->assertForbidden();
    });

    it('lets a tier work its own and lower tiers, never higher ones', function () {
        $tier2 = caseFor($this->ride, $this->passenger, ['escalation_tier' => 2]);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$tier2->id}/assign")->assertForbidden();
        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$tier2->id}")
            ->assertOk()
            ->assertJsonPath('dispute.available_actions', []);
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$tier2->id}/assign")->assertOk();

        $tier3 = caseFor(paidRide($this->passenger, $this->driverUser), $this->passenger, ['escalation_tier' => 3]);
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$tier3->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Not enough information to act.',
        ])->assertForbidden();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/disputes/{$tier3->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Not enough information to act.',
        ])->assertOk();
    });

    it('validates the queue filters', function () {
        $this->actingAs($this->agent)->getJson('/api/v1/admin/disputes?assigned=nobody')->assertUnprocessable();
        $this->actingAs($this->agent)->getJson('/api/v1/admin/disputes?ride_id=123')->assertUnprocessable();
        $this->actingAs($this->agent)->getJson('/api/v1/admin/disputes?assigned=unassigned')->assertOk();
    });

    it('rejects admins outside the dispute roles', function () {
        $this->actingAs($this->finance)->getJson('/api/v1/admin/disputes')->assertForbidden();
        $this->actingAs(disputeAdmin(AdminRole::Operations))->getJson('/api/v1/admin/disputes')->assertForbidden();
    });
});

describe('taking and assigning cases', function () {
    it('counts taking a case as the first response and tells the reporter', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign")
            ->assertOk()
            ->assertJsonPath('dispute.status', 'under_review')
            ->assertJsonPath('dispute.is_mine', true);

        expect($dispute->fresh()->first_responded_at)->not->toBeNull();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->passenger->id, 'type' => 'dispute_update']);
    });

    it('lets only leads hand cases to others, and only to admins who can work them', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign", ['admin_id' => $this->agent2->id])
            ->assertUnprocessable();
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign", ['admin_id' => $this->agent2->id])
            ->assertOk()
            ->assertJsonPath('dispute.assigned_admin_id', $this->agent2->id);
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign", ['admin_id' => $this->finance->id])
            ->assertUnprocessable();
        $this->actingAs($this->lead)->deleteJson("/api/v1/admin/disputes/{$dispute->id}/assign")
            ->assertOk()
            ->assertJsonPath('dispute.assigned_admin_id', null);
    });
});

describe('evidence (NF-12)', function () {
    beforeEach(function () {
        $this->dispute = caseFor($this->ride, $this->passenger);
        $this->dispute->evidence()->create([
            'uploaded_by_user_id' => $this->passenger->id,
            'type' => 'photo',
            'file_path' => 'disputes/x/photo.jpg',
            'uploaded_at' => now(),
        ]);
    });

    it('hides evidence from a tier 1 agent until they take the case, then logs each view', function () {
        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$this->dispute->id}")
            ->assertOk()
            ->assertJsonPath('dispute.evidence_locked', true)
            ->assertJsonMissingPath('dispute.evidence');

        expect(AuditLog::where('event', 'dispute_evidence_viewed')->count())->toBe(0);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$this->dispute->id}/assign")->assertOk();
        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$this->dispute->id}")
            ->assertJsonPath('dispute.evidence_locked', false)
            ->assertJsonCount(1, 'dispute.evidence');

        expect(AuditLog::where('event', 'dispute_evidence_viewed')->where('actor_id', $this->agent->id)->count())->toBe(1);
    });

    it('shows evidence to higher tiers and the Safety team, never in the list', function () {
        $this->actingAs($this->lead)->getJson("/api/v1/admin/disputes/{$this->dispute->id}")
            ->assertJsonCount(1, 'dispute.evidence');
        $this->actingAs($this->safety)->getJson("/api/v1/admin/disputes/{$this->dispute->id}")
            ->assertJsonCount(1, 'dispute.evidence');
        $this->actingAs($this->lead)->getJson('/api/v1/admin/disputes')
            ->assertJsonMissingPath('disputes.0.evidence')
            ->assertJsonPath('disputes.0.evidence_count', 1);
    });

    it('lets the reporter add evidence while the case is open, up to the limit', function () {
        AppSetting::setValue('disputes.max_evidence_files', 2, 'disputes');

        $this->actingAs($this->passenger)->post("/api/v1/disputes/{$this->dispute->id}/evidence", [
            'files' => [UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')],
            'captions' => ['Bank receipt'],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonCount(2, 'dispute.evidence')
            ->assertJsonPath('dispute.evidence.1.type', 'document')
            ->assertJsonPath('dispute.evidence.1.caption', 'Bank receipt')
            ->assertJsonPath('dispute.can_add_evidence', false);

        $this->actingAs($this->passenger)->post("/api/v1/disputes/{$this->dispute->id}/evidence", [
            'files' => [UploadedFile::fake()->create('more.jpg', 120, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($this->driverUser)->post("/api/v1/disputes/{$this->dispute->id}/evidence", [
            'files' => [UploadedFile::fake()->create('mine.jpg', 120, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertForbidden();
    });

    it('refuses evidence on a closed case', function () {
        $this->dispute->update(['status' => DisputeStatus::Resolved, 'resolved_at' => now()]);

        $this->actingAs($this->passenger)->post("/api/v1/disputes/{$this->dispute->id}/evidence", [
            'files' => [UploadedFile::fake()->create('late.jpg', 120, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    });
});

describe('escalation', function () {
    it('moves a case up a tier into the shared queue with new targets', function () {
        $dispute = caseFor($this->ride, $this->passenger);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign")->assertOk();

        $this->travel(2)->hours();

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/escalate", [
            'reason' => 'Passenger asks for a refund above my limit.',
        ])->assertOk()
            ->assertJsonPath('dispute.escalation_tier', 2)
            ->assertJsonPath('dispute.assigned_admin_id', null)
            ->assertJsonPath('dispute.escalation.source', 'admin');

        $fresh = $dispute->fresh();
        expect($fresh->first_responded_at)->toBeNull()
            ->and((int) round($fresh->resolution_due_at->diffInHours(now(), true)))->toBe(120);

        $this->actingAs($this->passenger)->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertJsonPath('dispute.stage_label', 'With our senior team')
            ->assertJsonMissingPath('dispute.escalation');

        $note = App\Models\Notification::where('user_id', $this->passenger->id)->latest()->first();
        expect($note->title)->toBe('Your case is with our senior team')
            ->and($note->body)->not->toContain($this->agent->first_name);
    });

    it('can send a support case to the Safety team', function () {
        $dispute = caseFor($this->ride, $this->passenger, ['category' => DisputeCategory::DriverBehaviour]);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/escalate", [
            'reason' => 'Passenger says the driver threatened them.',
            'to_safety' => true,
        ])->assertOk()
            ->assertJsonPath('dispute.queue', 'safety');

        Notification::assertSentTo($this->safety, DisputeAlertNotification::class);
        $this->actingAs($this->agent)->getJson("/api/v1/admin/disputes/{$dispute->id}")->assertForbidden();
    });

    it('refuses to escalate above tier 3 or a closed case', function () {
        $tier3 = caseFor($this->ride, $this->passenger, ['escalation_tier' => 3]);
        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/disputes/{$tier3->id}/escalate", [
            'reason' => 'Trying to go higher still.',
        ])->assertUnprocessable();

        $closed = Dispute::factory()->resolved()->create(['ride_id' => paidRide($this->passenger, $this->driverUser)->id, 'reported_by_user_id' => $this->passenger->id]);
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$closed->id}/escalate", [
            'reason' => 'Should reopen through appeal.',
        ])->assertUnprocessable();
    });

    it('sends a case back down with a required note kept on the case', function () {
        $dispute = caseFor($this->ride, $this->passenger, ['escalation_tier' => 2]);

        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/deescalate", [])
            ->assertUnprocessable();
        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/deescalate", [
            'note' => 'Check the trip route and refund the toll only.',
        ])->assertOk()
            ->assertJsonPath('dispute.escalation_tier', 1);

        expect($dispute->notes()->first()->content)->toContain('Check the trip route');

        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/deescalate", [
            'note' => 'Already at the bottom tier now.',
        ])->assertUnprocessable();
    });

    it('returns a misrouted safety case to support tier 1', function () {
        $dispute = caseFor($this->ride, $this->passenger, ['queue' => DisputeQueue::Safety, 'escalation_tier' => 2]);

        $this->actingAs($this->safety)->postJson("/api/v1/admin/disputes/{$dispute->id}/deescalate", [
            'note' => 'Not a safety matter: the fare was wrong.',
        ])->assertOk()
            ->assertJsonPath('dispute.queue', 'support')
            ->assertJsonPath('dispute.escalation_tier', 1);
    });
});

describe('refund limits and approvals', function () {
    it('pays a refund within the agent limit straight to the wallet', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Overcharge confirmed; partial refund.',
            'refund_amount' => 3000,
        ])->assertOk()
            ->assertJsonPath('dispute.status', 'resolved')
            ->assertJsonPath('dispute.refund_method', 'wallet')
            ->assertJsonPath('dispute.decision_tier', 1);
    });

    it('holds a refund above the agent limit for a higher tier, never self-approved', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $response = $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Driver took a much longer route.',
            'refund_amount' => 8000,
        ])->assertStatus(202)
            ->assertJsonPath('dispute.status', 'under_review')
            ->assertJsonPath('refund_request.status', 'pending');

        $requestId = $response->json('refund_request.id');

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Changing my mind on this one.',
        ])->assertUnprocessable();

        $this->actingAs($this->agent)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")->assertForbidden();
        $this->actingAs($this->agent2)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")->assertForbidden();

        $this->actingAs($this->lead)->getJson('/api/v1/admin/dispute-refund-requests')
            ->assertJsonPath('refund_requests.0.can_approve', true);

        $this->actingAs($this->lead)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('dispute.status', 'resolved')
            ->assertJsonPath('dispute.refund_amount', '8000.00');

        $fresh = $dispute->fresh();
        expect($fresh->resolved_by_admin_id)->toBe($this->agent->id)
            ->and(app(WalletRefundService::class)->refundedAmountKobo($this->ride))->toBe(800000);
    });

    it('needs a super admin above the tier 2 limit', function () {
        $ride = paidRide($this->passenger, $this->driverUser, 90000);
        $dispute = caseFor($ride, $this->passenger, ['escalation_tier' => 2]);

        $requestId = $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Full refund for the airport trip.',
            'refund_amount' => 60000,
        ])->assertStatus(202)->json('refund_request.id');

        $this->actingAs($this->lead2)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")->assertForbidden();
        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")->assertOk();
    });

    it('lets the approver reject and the requester withdraw, leaving the case open', function () {
        $dispute = caseFor($this->ride, $this->passenger);
        $first = $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Refund the whole fare please.',
            'refund_amount' => 9000,
        ])->json('refund_request.id');

        $this->actingAs($this->lead)->postJson("/api/v1/admin/dispute-refund-requests/{$first}/reject", [
            'notes' => 'Route was fine; refund the toll only.',
        ])->assertOk()->assertJsonPath('refund_request.status', 'rejected');

        $second = $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Refund the toll and waiting time.',
            'refund_amount' => 6000,
        ])->json('refund_request.id');

        $this->actingAs($this->agent)->postJson("/api/v1/admin/dispute-refund-requests/{$second}/reject", [
            'notes' => 'Withdrawing; will refund the toll only.',
        ])->assertOk()->assertJsonPath('refund_request.status', 'cancelled');

        expect($dispute->fresh()->isOpen())->toBeTrue();
    });

    it('keeps refund approvals within the case\'s own team', function () {
        $dispute = caseFor($this->ride, $this->passenger);
        $requestId = $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Refund above the agent limit.',
            'refund_amount' => 7000,
        ])->assertStatus(202)->json('refund_request.id');

        $this->actingAs($this->safety)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'Your role cannot approve refunds on this case.');
        $this->actingAs($this->finance)->postJson("/api/v1/admin/dispute-refund-requests/{$requestId}/approve")->assertOk();
    });

    it('counts earlier refunds on the case towards the limit', function () {
        $dispute = caseFor($this->ride, $this->passenger, ['refund_amount' => 4000]);

        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Another small refund for the delay.',
            'refund_amount' => 2000,
        ])->assertStatus(202);
    });
});

describe('response targets sweep', function () {
    it('moves a tier 1 case that missed its first response to tier 2', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $this->travel(25)->hours();
        (new ProcessDisputeTargetsJob)->handle(app(DisputeService::class));

        $fresh = $dispute->fresh();
        expect($fresh->escalation_tier)->toBe(2)
            ->and($fresh->escalation_source)->toBe('system')
            ->and($fresh->first_response_breached_at)->toBeNull();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $dispute->id, 'event' => 'dispute_auto_escalated']);
    });

    it('alerts super admins once when a tier 2 or Safety case misses a target', function () {
        $tier2 = caseFor($this->ride, $this->passenger, ['escalation_tier' => 2, 'first_responded_at' => now(), 'resolution_due_at' => now()->addHour()]);
        $safety = caseFor(paidRide($this->passenger, $this->driverUser), $this->passenger, ['queue' => DisputeQueue::Safety, 'escalation_tier' => 2, 'first_response_due_at' => now()->addMinutes(30)]);

        $this->travel(2)->hours();
        $service = app(DisputeService::class);
        $first = $service->processTargets();
        $second = $service->processTargets();

        expect($first['alerted'])->toBe(2)
            ->and($second['alerted'])->toBe(0)
            ->and($tier2->fresh()->resolution_breached_at)->not->toBeNull()
            ->and($safety->fresh()->first_response_breached_at)->not->toBeNull();
        Notification::assertSentToTimes($this->superAdmin, DisputeAlertNotification::class, 2);
    });

    it('only flags tier 1 misses when auto-escalation is off', function () {
        AppSetting::setValue('disputes.auto_escalate_tier_1', 'false', 'disputes');
        $dispute = caseFor($this->ride, $this->passenger);

        $this->travel(25)->hours();
        $counts = app(DisputeService::class)->processTargets();

        expect($counts['flagged'])->toBe(1)
            ->and($dispute->fresh()->escalation_tier)->toBe(1);
        $this->actingAs($this->agent)->getJson('/api/v1/admin/disputes?sla=breached')
            ->assertJsonCount(1, 'disputes')
            ->assertJsonPath('disputes.0.sla.status', 'breached');
    });

    it('reports cases close to their target as at risk', function () {
        $dispute = caseFor($this->ride, $this->passenger);

        $this->travel(20)->hours();

        $this->actingAs($this->agent)->getJson('/api/v1/admin/disputes?sla=at_risk')
            ->assertJsonCount(1, 'disputes')
            ->assertJsonPath('disputes.0.sla.status', 'at_risk');
    });
});

describe('appeals', function () {
    beforeEach(function () {
        $this->dispute = caseFor($this->ride, $this->passenger);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$this->dispute->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Trip data shows the normal route.',
        ])->assertOk();
    });

    it('reopens the case one tier above the decision, for a different reviewer', function () {
        $this->actingAs($this->passenger)->getJson("/api/v1/disputes/{$this->dispute->id}")
            ->assertJsonPath('dispute.appeal.can_appeal', true);

        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'The app showed the route through traffic on purpose.',
        ])->assertOk()
            ->assertJsonPath('dispute.status', 'open')
            ->assertJsonPath('dispute.stage_label', 'With our senior team')
            ->assertJsonPath('dispute.appeal.can_appeal', false);

        $fresh = $this->dispute->fresh();
        expect($fresh->escalation_tier)->toBe(2)
            ->and($fresh->appeal_of_resolver_id)->toBe($this->agent->id)
            ->and($fresh->original_decision['status'])->toBe('dismissed');

        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$fresh->id}/assign")->assertOk();
    });

    it('keeps the original decider off the appeal', function () {
        $this->dispute->update(['escalation_tier' => 1]);
        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'I want someone else to look at it.',
        ])->assertOk();

        $this->dispute->fresh()->update(['escalation_tier' => 1]);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$this->dispute->id}/assign")
            ->assertForbidden()
            ->assertJsonPath('message', 'You made the original decision on this case, so the appeal goes to a different reviewer.');
    });

    it('allows one appeal, within the window, by the reporter only', function () {
        $this->actingAs($this->driverUser)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'I am not the reporter but I appeal.',
        ])->assertForbidden();

        $this->travel(8)->days();
        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'Late, but I still disagree.',
        ])->assertUnprocessable();

        $this->travelBack();
        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'First appeal within the window.',
        ])->assertOk();

        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$this->dispute->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Reviewed again; decision stands.',
        ])->assertOk();

        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'Second appeal should not work.',
        ])->assertUnprocessable();
    });

    it('does not send an appeal back down to the tier that decided it', function () {
        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$this->dispute->id}/appeal", [
            'reason' => 'Please have someone senior look again.',
        ])->assertOk();

        $this->actingAs($this->lead)->postJson("/api/v1/admin/disputes/{$this->dispute->id}/deescalate", [
            'note' => 'Sending back to the agent to redo it.',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'An appeal must stay above the tier that made the original decision.');
    });

    it('treats a tier 3 decision as final', function () {
        $case = caseFor(paidRide($this->passenger, $this->driverUser), $this->passenger);
        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/disputes/{$case->id}/resolve", [
            'status' => 'dismissed',
            'resolution_notes' => 'Decided by management.',
        ])->assertOk();

        $this->actingAs($this->passenger)->postJson("/api/v1/disputes/{$case->id}/appeal", [
            'reason' => 'I disagree with management.',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This decision was made by our management team and is final.');
    });
});

describe('admin changes and reporter views', function () {
    it('returns a deactivated admin\'s open cases to the queue', function () {
        $dispute = caseFor($this->ride, $this->passenger);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign")->assertOk();

        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/admins/{$this->agent->id}/deactivate")->assertOk();

        expect($dispute->fresh()->assigned_admin_id)->toBeNull();
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $dispute->id, 'event' => 'dispute_returned_to_queue']);
    });

    it('releases cases a new role cannot work', function () {
        $dispute = caseFor($this->ride, $this->passenger);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/assign")->assertOk();

        $this->actingAs($this->superAdmin)->putJson("/api/v1/admin/admins/{$this->agent->id}", ['admin_role' => 'support_lead'])->assertOk();
        expect($dispute->fresh()->assigned_admin_id)->toBe($this->agent->id);

        $this->actingAs($this->superAdmin)->putJson("/api/v1/admin/admins/{$this->agent->id}", ['admin_role' => 'finance'])->assertOk();
        expect($dispute->fresh()->assigned_admin_id)->toBeNull();
    });

    it('lists a driver\'s own disputes and never shows an admin to the reporter', function () {
        $dispute = caseFor($this->ride, $this->driverUser);
        $this->actingAs($this->agent)->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Passenger warned about conduct.',
        ])->assertOk();

        $this->actingAs($this->driverUser)->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'disputes')
            ->assertJsonMissingPath('disputes.0.resolved_by')
            ->assertJsonMissingPath('disputes.0.assigned_admin_id')
            ->assertJsonPath('disputes.0.stage', 'decided');
    });
});

describe('settings', function () {
    it('lets only super admins change dispute settings', function () {
        $this->actingAs($this->lead)->putJson('/api/v1/admin/settings/disputes', ['tier_1_refund_limit' => 100000])->assertForbidden();

        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/settings/disputes', [
            'tier_1_refund_limit' => 10000,
            'appeal_window_days' => 14,
        ])->assertOk()
            ->assertJsonPath('settings.tier_1_refund_limit', 10000)
            ->assertJsonPath('settings.appeal_window_days', 14);

        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/settings/disputes', ['tier_2_refund_limit' => 5000])
            ->assertUnprocessable();
    });
});
