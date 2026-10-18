<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 driver scheduling (OQ-20) and vehicle maintenance (OQ-19):
 *
 * - Fleet cars in shift mode are shared by drivers on a timetable
 *   (driver_shifts); vehicles.driver_id is the driver holding the car now.
 * - Every driver can plan the hours they expect to drive
 *   (driver_availability_plans); voluntary, never enforced.
 * - Each vehicle tracks its maintenance items (service, tyres & brakes, legal
 *   papers, and battery health for plug-ins) and a record of the work done.
 * - Vehicle papers belong to the vehicle (driver_documents.vehicle_id), so
 *   they stay with a car that changes hands.
 */
return new class extends Migration
{
    private const VEHICLE_DOCUMENT_TYPES = ['vehicle_registration', 'insurance_certificate'];

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Shared between drivers on a shift timetable (fleet cars only).
            $table->boolean('shift_mode')->default(false)->after('is_fleet');
            // Last odometer reading, and trip distance E-tiGo has recorded since.
            $table->unsignedInteger('odometer_km')->nullable()->after('shift_mode');
            $table->timestamp('odometer_read_at')->nullable()->after('odometer_km');
            $table->decimal('trip_km_since_odometer', 10, 2)->default(0)->after('odometer_read_at');
            // Plug-in vehicles: charge level the driver gave when going online.
            $table->unsignedTinyInteger('battery_level_percent')->nullable()->after('trip_km_since_odometer');
            $table->timestamp('battery_level_at')->nullable()->after('battery_level_percent');
            // Set while expired papers keep the car off the road (after the grace period).
            $table->timestamp('blocked_at')->nullable()->after('battery_level_at');
            $table->string('block_reason', 500)->nullable()->after('blocked_at');

            $table->index('shift_mode');
            $table->index('blocked_at');
        });

        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            // Full date-times, so overnight shifts (22:00–06:00) work.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('missed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            // The driver was told the car may be late (previous driver still on a trip).
            $table->timestamp('handover_delay_notified_at')->nullable();
            // The driver was told new offers have stopped (shift about to end).
            $table->timestamp('end_warning_sent_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'starts_at']);
            $table->index(['driver_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('driver_availability_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->string('area_label', 60)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['driver_id', 'starts_at']);
            $table->index(['city_id', 'starts_at', 'ends_at']);
        });

        Schema::create('vehicle_maintenance_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('unknown');
            $table->date('due_at')->nullable();
            $table->unsignedInteger('due_odometer_km')->nullable();
            $table->date('last_done_at')->nullable();
            $table->unsignedInteger('last_odometer_km')->nullable();
            $table->boolean('blocks_driving')->default(false);
            $table->string('notes', 500)->nullable();
            // The status the driver was last told about, so each change is sent once.
            $table->string('notified_status')->nullable();
            $table->timestamp('notified_at')->nullable();
            // The fuel type no longer needs this item (e.g. battery health on a petrol car).
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'type']);
            $table->index(['status', 'archived_at']);
        });

        Schema::create('vehicle_maintenance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->date('performed_at');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->string('workshop', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime_type')->nullable();
            $table->foreignUuid('driver_repair_id')->nullable()->constrained('driver_repairs')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'item_type', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('driver_documents', function (Blueprint $table) {
            // Papers operations upload for a fleet car with no driver have no driver.
            $table->uuid('driver_id')->nullable()->change();
            $table->foreignUuid('vehicle_id')->nullable()->after('driver_id')->constrained()->nullOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->after('vehicle_id')->constrained('users')->nullOnDelete();

            $table->index(['vehicle_id', 'type', 'status']);
        });

        // Link existing vehicle papers to the driver's car. A driver with no
        // car, or (in old data) more than one, is left for operations to link
        // (GET /admin/vehicle-documents/unlinked) rather than guessed.
        DB::table('driver_documents')
            ->whereIn('type', self::VEHICLE_DOCUMENT_TYPES)
            ->whereNull('vehicle_id')
            ->orderBy('id')
            ->chunk(500, function ($documents) {
                $vehicles = DB::table('vehicles')
                    ->whereIn('driver_id', $documents->pluck('driver_id')->filter()->unique()->all())
                    ->get(['id', 'driver_id'])
                    ->groupBy('driver_id');

                foreach ($documents as $document) {
                    $owned = $vehicles->get($document->driver_id);

                    if ($owned !== null && $owned->count() === 1) {
                        DB::table('driver_documents')->where('id', $document->id)->update(['vehicle_id' => $owned->first()->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropIndex(['vehicle_id', 'type', 'status']);
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropConstrainedForeignId('vehicle_id');
        });

        // Papers with no driver cannot survive driver_id becoming required again.
        DB::table('driver_documents')->whereNull('driver_id')->delete();

        Schema::table('driver_documents', function (Blueprint $table) {
            $table->uuid('driver_id')->nullable(false)->change();
        });

        Schema::dropIfExists('vehicle_maintenance_records');
        Schema::dropIfExists('vehicle_maintenance_items');
        Schema::dropIfExists('driver_availability_plans');
        Schema::dropIfExists('driver_shifts');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['shift_mode']);
            $table->dropIndex(['blocked_at']);
            $table->dropColumn([
                'shift_mode', 'odometer_km', 'odometer_read_at', 'trip_km_since_odometer',
                'battery_level_percent', 'battery_level_at', 'blocked_at', 'block_reason',
            ]);
        });
    }
};
