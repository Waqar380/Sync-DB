<?php

namespace App\Services\Consumers;

use App\DTOs\SyncEvent;
use App\Services\Transformers\TransformerFactory;
use App\Services\Writers\IdempotentRevampWriter;
use Illuminate\Support\Facades\Log;

/**
 * Legacy Event Consumer
 * 
 * Consumes events from the Legacy (PostgreSQL) platform
 * and syncs them to the Revamped (MySQL) platform.
 * 
 * Flow: Legacy DB → Debezium → Kafka → This Consumer → Revamp DB
 */
class LegacyEventConsumer extends BaseConsumer
{
    private IdempotentRevampWriter $writer;

    public function __construct(
        \App\Services\Handlers\RetryHandler $retryHandler,
        \App\Services\Handlers\ErrorHandler $errorHandler,
        IdempotentRevampWriter $writer
    ) {
        parent::__construct($retryHandler, $errorHandler);
        $this->writer = $writer;
    }

    /**
     * Get topics to subscribe to
     */
    protected function getTopics(): array
    {
        return config('kafka.consumer.legacy.topics');
    }

    /**
     * Get consumer group ID
     */
    protected function getGroupId(): string
    {
        return config('kafka.consumer.legacy.group_id');
    }

    /**
     * Get consumer configuration
     */
    protected function getConfig(): array
    {
        return config('kafka.consumer.legacy');
    }

    /**
     * Process a sync event from Legacy platform
     * 
     * This method:
     * 1. Validates the event
     * 2. Transforms Legacy schema to Revamp schema
     * 3. Writes to Revamp database with source='sync_service'
     */
    protected function processEvent(SyncEvent $event): void
    {
        Log::info('Processing Legacy event', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'operation' => $event->operation,
            'primary_key' => $event->primaryKey,
        ]);

        // Additional validation: ensure source is 'legacy'
        if ($event->source !== SyncEvent::SOURCE_LEGACY) {
            Log::warning('Unexpected source in Legacy consumer', [
                'event_id' => $event->eventId,
                'expected_source' => SyncEvent::SOURCE_LEGACY,
                'actual_source' => $event->source,
            ]);
            // Continue processing anyway, but log the anomaly
        }

        // Get transformer for Legacy → Revamp
        $transformer = TransformerFactory::forLegacyEvents();

        // Check if transformer can handle this entity
        if (!$transformer->canHandle($event->entityType)) {
            throw new \InvalidArgumentException(
                "No transformer available for entity type: {$event->entityType}"
            );
        }

        // Transform data from Legacy to Revamp schema
        $transformedData = $transformer->transform($event);

        Log::debug('Transformed Legacy event to Revamp format', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'transformed_fields' => array_keys($transformedData),
        ]);

        // Write to Revamp database
        // The writer will:
        // - Set source='sync_service' (already done in DTO)
        // - Use UPSERT for idempotency
        // - Update entity mappings
        // - Mark event as processed
        $success = $this->writer->write($event->entityType, $transformedData, $event);

        if (!$success) {
            throw new \RuntimeException("Failed to write event to Revamp database");
        }

        Log::info('Successfully synced Legacy event to Revamp', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'primary_key' => $event->primaryKey,
        ]);
    }
}


