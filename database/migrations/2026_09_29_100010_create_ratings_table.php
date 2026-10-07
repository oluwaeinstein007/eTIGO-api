<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ride_id');
            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreignUuid('rated_by_user_id')->constrained('users');
            $table->foreignUuid('rated_user_id')->constrained('users');
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('ride_id');
            $table->index('rated_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
