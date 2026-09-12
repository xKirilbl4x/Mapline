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
        // sync_runs is created immediately before this table.
        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
