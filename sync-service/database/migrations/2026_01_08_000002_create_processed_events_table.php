<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores processed event IDs to ensure idempotent processing
     * and prevent duplicate event handling.
     */
    public function up(): void
    {
        Schema::connection('revamp')->create('processed_events', function (Blueprint $table) {
            $table->string('event_id', 36)->primary()->comment('UUID of the processed event');
            $table->string('entity_type', 50)->comment('Type of entity');
            $table->string('operation', 20)->comment('CREATE, UPDATE, DELETE');
            $table->string('source', 20)->comment('legacy, revamp, sync_service');
            $table->unsignedBigInteger('primary_key')->comment('Entity primary key');
            $table->text('payload')->nullable()->comment('Event payload (JSON)');
            $table->timestamp('processed_at')->useCurrent();
            
            // Indexes
            $table->index('processed_at', 'idx_processed_at');
            $table->index(['entity_type', 'primary_key'], 'idx_entity_pk');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('revamp')->dropIfExists('processed_events');
    }
};


