<?php

namespace App\Services\Writers;

use App\DTOs\SyncEvent;
use App\Models\EntityMapping;
use App\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent Writer for Revamped (MySQL) Database
 * 
 * Writes data to MySQL with idempotency guarantees
 * using UPSERT (INSERT ... ON DUPLICATE KEY UPDATE).
 */
class IdempotentRevampWriter implements IdempotentWriterInterface
{
    private string $connection = 'revamp';

    /**
     * Write data to Revamped database with idempotency
     */
    public function write(string $entityType, array $data, SyncEvent $event): bool
    {
        try {
            DB::connection($this->connection)->beginTransaction();

            $tableName = $this->getTableName($entityType);
            $primaryKey = $data['id'];

            Log::info('Writing to Revamp database', [
                'entity_type' => $entityType,
                'table' => $tableName,
                'primary_key' => $primaryKey,
                'operation' => $event->operation,
            ]);

            if ($event->isDelete()) {
                // Handle DELETE operation
                $deleted = DB::connection($this->connection)
                    ->table($tableName)
                    ->where('id', $primaryKey)
                    ->delete();

                Log::info('Deleted from Revamp database', [
                    'entity_type' => $entityType,
                    'primary_key' => $primaryKey,
                    'rows_affected' => $deleted,
                ]);
            } else {
                // Handle CREATE/UPDATE with UPSERT
                $columns = array_keys($data);
                $updateData = array_filter($data, fn($key) => $key !== 'id', ARRAY_FILTER_USE_KEY);

                $sql = sprintf(
                    "INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s, updated_at = CURRENT_TIMESTAMP",
                    $tableName,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($columns), '?')),
                    implode(', ', array_map(fn($col) => "{$col} = VALUES({$col})", array_keys($updateData)))
                );

                try {
                    $result = DB::connection($this->connection)->insert($sql, array_values($data));

                    Log::info('Upserted to Revamp database', [
                        'entity_type' => $entityType,
                        'primary_key' => $primaryKey,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    // Check if it's an auto-increment error
                    if ($this->isAutoIncrementError($e)) {
                        Log::warning('Auto-increment out of sync detected, attempting to fix', [
                            'entity_type' => $entityType,
                            'table' => $tableName,
                            'error' => $e->getMessage(),
                        ]);

                        // Fix the auto-increment and retry
                        $this->fixAutoIncrement($tableName);
                        
                        // Retry the insert
                        $result = DB::connection($this->connection)->insert($sql, array_values($data));

                        Log::info('Upserted to Revamp database after auto-increment fix', [
                            'entity_type' => $entityType,
                            'primary_key' => $primaryKey,
                            'result' => $result,
                        ]);
                    } else {
                        // Re-throw if it's not an auto-increment error
                        throw $e;
                    }
                }
            }

            // Update entity mapping
            if ($event->source === SyncEvent::SOURCE_LEGACY) {
                EntityMapping::createOrUpdateMapping($entityType, $event->primaryKey, $primaryKey);
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
            
            Log::error('Failed to write to Revamp database', [
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
        return "revamp_{$entityType}";
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
     * Check if the exception is an auto-increment/duplicate key error
     */
    private function isAutoIncrementError(\Exception $e): bool
    {
        $message = $e->getMessage();
        
        // MySQL duplicate key error patterns
        return str_contains($message, 'Duplicate entry') ||
               str_contains($message, 'for key') ||
               str_contains($message, 'PRIMARY');
    }

    /**
     * Fix MySQL auto-increment for a table
     */
    private function fixAutoIncrement(string $tableName): void
    {
        try {
            // Get max ID from table
            $result = DB::connection($this->connection)
                ->table($tableName)
                ->selectRaw('COALESCE(MAX(id), 0) as max_id')
                ->first();

            $maxId = $result->max_id ?? 0;
            $nextId = $maxId + 1;

            // Reset auto-increment
            DB::connection($this->connection)
                ->statement("ALTER TABLE {$tableName} AUTO_INCREMENT = {$nextId}");
            
            Log::info('Fixed MySQL auto-increment', [
                'table' => $tableName,
                'max_id' => $maxId,
                'next_auto_increment' => $nextId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fix MySQL auto-increment', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - let the original error propagate
        }
    }
}


