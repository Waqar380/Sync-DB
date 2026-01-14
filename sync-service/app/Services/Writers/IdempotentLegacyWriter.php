<?php

namespace App\Services\Writers;

use App\DTOs\SyncEvent;
use App\Models\EntityMapping;
use App\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent Writer for Legacy (MySQL) Database
 * 
 * Writes data to MySQL with idempotency guarantees
 * using UPSERT (INSERT ... ON DUPLICATE KEY UPDATE).
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
                // Handle CREATE/UPDATE with UPSERT (MySQL syntax)
                $columns = array_keys($data);
                $values = array_values($data);
                $placeholders = array_fill(0, count($values), '?');
                
                $updateSet = array_map(
                    fn($col) => "{$col} = VALUES({$col})",
                    array_filter($columns, fn($col) => $col !== 'id')
                );

                $sql = sprintf(
                    "INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s, updated_at = CURRENT_TIMESTAMP",
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
                    // Check if it's an auto-increment/duplicate key error
                    if ($this->isAutoIncrementError($e)) {
                        Log::warning('AUTO_INCREMENT out of sync detected, attempting to fix', [
                            'entity_type' => $entityType,
                            'table' => $tableName,
                            'error' => $e->getMessage(),
                        ]);

                        // Fix the AUTO_INCREMENT and retry
                        $this->fixAutoIncrement($tableName);
                        
                        // Retry the insert
                        $result = DB::connection($this->connection)->insert($sql, $values);

                        Log::info('Upserted to Legacy database after AUTO_INCREMENT fix', [
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
     * Check if the exception is an AUTO_INCREMENT/duplicate key error
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
     * Fix MySQL AUTO_INCREMENT for a table
     */
    private function fixAutoIncrement(string $tableName): void
    {
        try {
            // Get max ID from table
            $maxId = DB::connection($this->connection)
                ->table($tableName)
                ->max('id') ?? 0;

            // Set AUTO_INCREMENT to max_id + 1
            $nextId = $maxId + 1;
            
            $sql = "ALTER TABLE {$tableName} AUTO_INCREMENT = {$nextId}";
            DB::connection($this->connection)->statement($sql);
            
            Log::info('Fixed MySQL AUTO_INCREMENT', [
                'table' => $tableName,
                'max_id' => $maxId,
                'next_auto_increment' => $nextId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fix MySQL AUTO_INCREMENT', [
                'table' => $tableName,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - let the original error propagate
        }
    }
}


