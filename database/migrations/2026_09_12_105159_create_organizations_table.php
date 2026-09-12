<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('source_url');
            $table->text('canonical_url');
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->string('sync_status')->default('idle');
            $table->unsignedTinyInteger('sync_progress')->default(0);
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_finished_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('source_hash')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
