<?php

namespace App\Services\Writers;

use App\DTOs\SyncEvent;
use App\Models\EntityMapping;
use App\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent Writer for Legacy (PostgreSQL) Database
 * 
 * Writes data to PostgreSQL with idempotency guarantees
 * using UPSERT (INSERT ... ON CONFLICT DO UPDATE).
 */
class IdempotentLegacyWriter implements IdempotentWriterInterface
{
    private string $connection = 'legacy';

    /**
     * Write data to Legacy database with idempotency
     */
    public function write(string $entityType, array $data, SyncEvent $event): bool
    {
        try {
            DB::connection($this->connection)->beginTransaction();

            $tableName = $this->getTableName($entityType);
            $primaryKey = $data['id'];

            Log::info('Writing to Legacy database', [
                'entity_type' => $entityType,
                'table' => $tableName,
                'primary_key' => $primaryKey,
                'operation' => $event->operation,
                'data' => $data, // DEBUG: Log the data being written
            ]);

            if ($event->isDelete()) {
                // Handle DELETE operation
                $deleted = DB::connection($this->connection)
                    ->table($tableName)
                    ->where('id', $primaryKey)
                    ->delete();

                Log::info('Deleted from Legacy database', [
                    'entity_type' => $entityType,
                    'primary_key' => $primaryKey,
                    'rows_affected' => $deleted,
                ]);
            } else {
                // Handle CREATE/UPDATE with UPSERT
                $columns = array_keys($data);
                $values = array_values($data);
                $placeholders = array_fill(0, count($values), '?');
                
                $updateSet = array_map(
                    fn($col) => "{$col} = EXCLUDED.{$col}",
                    array_filter($columns, fn($col) => $col !== 'id')
                );

                $sql = sprintf(
                    "INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (id) DO UPDATE SET %s, updated_at = CURRENT_TIMESTAMP",
                    $tableName,
                    implode(', ', $columns),
                    implode(', ', $placeholders),
                    implode(', ', $updateSet)
                );

                try {
                    $result = DB::connection($this->connection)->insert($sql, $values);

                    Log::info('Upserted to Legacy database', [
                        'entity_type' => $entityType,
                        'primary_key' => $primaryKey,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    // Check if it's a sequence/duplicate key error
                    if ($this->isSequenceError($e)) {
                        Log::warning('Sequence out of sync detected, attempting to fix', [
                            'entity_type' => $entityType,
                            'table' => $tableName,
                            'error' => $e->getMessage(),
                        ]);

                        // Fix the sequence and retry
                        $this->fixSequence($tableName);
                        
                        // Retry the insert
                        $result = DB::connection($this->connection)->insert($sql, $values);

                        Log::info('Upserted to Legacy database after sequence fix', [
                            'entity_type' => $entityType,
                            'primary_key' => $primaryKey,
                            'result' => $result,
                        ]);
                    } else {
                        // Re-throw if it's not a sequence error
                        throw $e;
                    }
                }
            }

            // Update entity mapping
            if ($event->source === SyncEvent::SOURCE_REVAMP) {
                EntityMapping::createOrUpdateMapping($entityType, $primaryKey, $event->primaryKey);
            }

            // Mark event as processed
            ProcessedEvent::markAsProcessed(
                $event->eventId,
                $event->entityType,
                $event->operation,
                $event->source,
                $primaryKey,
                $event->payload
            );

            DB::connection($this->connection)->commit();

            return true;
        } catch (\Exception $e) {
            DB::connection($this->connection)->rollBack();
            
            Log::error('Failed to write to Legacy database', [
                'entity_type' => $entityType,
                'primary_key' => $data['id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get table name for entity type
     */
    public function getTableName(string $entityType): string
    {
        return "legacy_{$entityType}";
    }

    /**
     * Check if entity exists
     */
    public function exists(string $entityType, int $id): bool
    {
        $tableName = $this->getTableName($entityType);
        
        return DB::connection($this->connection)
            ->table($tableName)
            ->where('id', $id)
            ->exists();
    }

    /**
     * Check if the exception is a sequence/duplicate key error
     */
    private function isSequenceError(\Exception $e): bool
    {
        $message = $e->getMessage();
        
        // PostgreSQL duplicate key error patterns
        return str_contains($message, 'duplicate key value violates unique constraint') ||
               str_contains($message, '_pkey') ||
               str_contains($message, 'Key (id)=');
    }

    /**
     * Fix PostgreSQL sequence for a table
     */
    private function fixSequence(string $tableName): void
    {
        try {
            // Extract sequence name from table name
            // Table: legacy_users -> Sequence: legacy_users_id_seq
            $sequenceName = "{$tableName}_id_seq";

            // Reset sequence to max ID
            $sql = "SELECT setval('{$sequenceName}', (SELECT COALESCE(MAX(id), 1) FROM {$tableName}))";
            
            $result = DB::connection($this->connection)->select($sql);
            
            Log::info('Fixed PostgreSQL sequence', [
                'table' => $tableName,
                'sequence' => $sequenceName,
                'new_value' => $result[0]->setval ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fix PostgreSQL sequence', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - let the original error propagate
        }
    }
}


