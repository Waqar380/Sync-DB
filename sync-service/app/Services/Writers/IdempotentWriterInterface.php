<?php

namespace App\Services\Writers;

use App\DTOs\SyncEvent;

/**
 * Interface for Idempotent Database Writers
 * 
 * All writers must implement this interface to ensure
 * consistent idempotent write behavior.
 */
interface IdempotentWriterInterface
{
    /**
     * Write data to database with idempotency guarantees
     * 
     * @param string $entityType
     * @param array $data Transformed data ready for insert/update
     * @param SyncEvent $event Original event for tracking
     * @return bool Success status
     */
    public function write(string $entityType, array $data, SyncEvent $event): bool;

    /**
     * Get the table name for an entity type
     * 
     * @param string $entityType
     * @return string
     */
    public function getTableName(string $entityType): string;

    /**
     * Check if an entity exists
     * 
     * @param string $entityType
     * @param int $id
     * @return bool
     */
    public function exists(string $entityType, int $id): bool;
}


