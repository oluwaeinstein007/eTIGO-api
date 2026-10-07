<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->index();
            $table->string('admin_role');
            $table->string('token', 64)->unique();
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }
};
