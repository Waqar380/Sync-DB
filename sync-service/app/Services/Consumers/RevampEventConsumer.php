<?php

namespace App\Services\Consumers;

use App\DTOs\SyncEvent;
use App\Services\Transformers\TransformerFactory;
use App\Services\Writers\IdempotentLegacyWriter;
use Illuminate\Support\Facades\Log;

/**
 * Revamp Event Consumer
 * 
 * Consumes events from the Revamped (MySQL) platform
 * and syncs them to the Legacy (PostgreSQL) platform.
 * 
 * Flow: Revamp DB → Debezium → Kafka → This Consumer → Legacy DB
 */
class RevampEventConsumer extends BaseConsumer
{
    private IdempotentLegacyWriter $writer;

    public function __construct(
        \App\Services\Handlers\RetryHandler $retryHandler,
        \App\Services\Handlers\ErrorHandler $errorHandler,
        IdempotentLegacyWriter $writer
    ) {
        parent::__construct($retryHandler, $errorHandler);
        $this->writer = $writer;
    }

    /**
     * Get topics to subscribe to
     */
    protected function getTopics(): array
    {
        return config('kafka.consumer.revamp.topics');
    }

    /**
     * Get consumer group ID
     */
    protected function getGroupId(): string
    {
        return config('kafka.consumer.revamp.group_id');
    }

    /**
     * Get consumer configuration
     */
    protected function getConfig(): array
    {
        return config('kafka.consumer.revamp');
    }

    /**
     * Process a sync event from Revamp platform
     * 
     * This method:
     * 1. Validates the event
     * 2. Transforms Revamp schema to Legacy schema
     * 3. Writes to Legacy database with source='sync_service'
     */
    protected function processEvent(SyncEvent $event): void
    {
        Log::info('Processing Revamp event', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'operation' => $event->operation,
            'primary_key' => $event->primaryKey,
            'payload' => $event->payload, // DEBUG: Log full payload
        ]);

        // Additional validation: ensure source is 'revamp'
        if ($event->source !== SyncEvent::SOURCE_REVAMP) {
            Log::warning('Unexpected source in Revamp consumer', [
                'event_id' => $event->eventId,
                'expected_source' => SyncEvent::SOURCE_REVAMP,
                'actual_source' => $event->source,
            ]);
            // Continue processing anyway, but log the anomaly
        }

        // Get transformer for Revamp → Legacy
        $transformer = TransformerFactory::forRevampEvents();

        // Check if transformer can handle this entity
        if (!$transformer->canHandle($event->entityType)) {
            throw new \InvalidArgumentException(
                "No transformer available for entity type: {$event->entityType}"
            );
        }

        // Transform data from Revamp to Legacy schema
        $transformedData = $transformer->transform($event);

        Log::debug('Transformed Revamp event to Legacy format', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'transformed_fields' => array_keys($transformedData),
        ]);

        // Write to Legacy database
        // The writer will:
        // - Set source='sync_service' (already done in DTO)
        // - Use UPSERT for idempotency
        // - Update entity mappings
        // - Mark event as processed
        $success = $this->writer->write($event->entityType, $transformedData, $event);

        if (!$success) {
            throw new \RuntimeException("Failed to write event to Legacy database");
        }

        Log::info('Successfully synced Revamp event to Legacy', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'primary_key' => $event->primaryKey,
        ]);
    }
}


