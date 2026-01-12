<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores the mapping between Legacy and Revamped entity IDs
     * to ensure consistent synchronization and prevent duplicates.
     */
    public function up(): void
    {
        Schema::connection('revamp')->create('entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50)->comment('Type of entity: users, posts, likes');
            $table->unsignedBigInteger('legacy_id')->comment('ID in Legacy PostgreSQL DB');
            $table->unsignedBigInteger('revamp_id')->comment('ID in Revamped MySQL DB');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Unique constraints
            $table->unique(['entity_type', 'legacy_id'], 'unique_entity_legacy');
            $table->unique(['entity_type', 'revamp_id'], 'unique_entity_revamp');
            
            // Indexes for lookups
            $table->index(['entity_type', 'legacy_id'], 'idx_entity_legacy');
            $table->index(['entity_type', 'revamp_id'], 'idx_entity_revamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('revamp')->dropIfExists('entity_mappings');
    }
};


