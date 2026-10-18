<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Evidence files on a dispute (BE2-DISP-01). Only the reporter, the
 * assigned reviewer, higher tiers and the Safety team see them (NF-12);
 * every admin view is audit-logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type'); // photo, audio, document
            $table->string('file_path');
            $table->string('file_type')->nullable(); // MIME type
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('original_name')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index(['dispute_id', 'uploaded_at']);
        });

        // Files sent with the dispute before this table existed.
        DB::table('disputes')
            ->where(fn ($q) => $q->whereNotNull('evidence_photos')->orWhereNotNull('evidence_audio'))
            ->orderBy('id')
            ->each(function (object $dispute) {
                $rows = [];

                foreach (json_decode((string) $dispute->evidence_photos, true) ?: [] as $path) {
                    $rows[] = ['type' => 'photo', 'file_path' => $path];
                }

                if ($dispute->evidence_audio) {
                    $rows[] = ['type' => 'audio', 'file_path' => $dispute->evidence_audio];
                }

                foreach ($rows as $row) {
                    DB::table('dispute_evidence')->insert($row + [
                        'id' => (string) Str::uuid7(),
                        'dispute_id' => $dispute->id,
                        'uploaded_by_user_id' => $dispute->reported_by_user_id,
                        'uploaded_at' => $dispute->created_at,
                        'created_at' => $dispute->created_at,
                        'updated_at' => $dispute->created_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidence');
    }
};
