<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('onboarding');
            $table->string('licence_number')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
            $table->index('is_online');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
