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
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('author');
            $table->string('author_avatar_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('body');
            $table->unsignedTinyInteger('rating');
            $table->text('source_url')->nullable();
            $table->string('content_hash', 40);
            $table->boolean('is_current')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
            $table->index(['organization_id', 'is_current']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
