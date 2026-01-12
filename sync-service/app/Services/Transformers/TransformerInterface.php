<?php

namespace App\Services\Transformers;

use App\DTOs\SyncEvent;

/**
 * Interface for Entity Transformers
 * 
 * All transformers must implement this interface to ensure
 * consistent transformation behavior across entities.
 */
interface TransformerInterface
{
    /**
     * Transform an event from source to target format
     * 
     * @param SyncEvent $event
     * @return array Transformed data ready for database insert/update
     */
    public function transform(SyncEvent $event): array;

    /**
     * Check if this transformer can handle the given entity type
     * 
     * @param string $entityType
     * @return bool
     */
    public function canHandle(string $entityType): bool;
}


