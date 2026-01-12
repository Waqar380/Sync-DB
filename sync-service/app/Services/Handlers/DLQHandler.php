<?php

namespace App\Services\Handlers;

use App\DTOs\SyncEvent;
use Illuminate\Support\Facades\Log;
use RdKafka\Producer;
use RdKafka\Conf;

/**
 * Dead Letter Queue Handler
 * 
 * Sends failed events to a Dead Letter Queue (DLQ) topic
 * for later analysis and manual reprocessing.
 */
class DLQHandler
{
    private ?Producer $producer = null;
    private string $dlqTopic;
    private bool $enabled;

    public function __construct()
    {
        $this->dlqTopic = config('kafka.dlq.topic', 'sync.dlq');
        $this->enabled = config('kafka.dlq.enabled', true);
    }

    /**
     * Send a failed event to the Dead Letter Queue
     * 
     * @param SyncEvent $event The original event that failed
     * @param \Exception $exception The exception that caused the failure
     * @param int $retryCount Number of times the event was retried
     * @param array $additionalContext Additional context information
     */
    public function send(
        SyncEvent $event,
        \Exception $exception,
        int $retryCount = 0,
        array $additionalContext = []
    ): void {
        if (!$this->enabled) {
            Log::warning('DLQ is disabled, not sending failed event', [
                'event_id' => $event->eventId,
            ]);
            return;
        }

        try {
            $dlqMessage = $this->createDLQMessage($event, $exception, $retryCount, $additionalContext);

            Log::warning('Sending event to DLQ', [
                'event_id' => $event->eventId,
                'entity_type' => $event->entityType,
                'operation' => $event->operation,
                'error' => $exception->getMessage(),
                'retry_count' => $retryCount,
            ]);

            $this->publishToDLQ($dlqMessage);

            Log::info('Successfully sent event to DLQ', [
                'event_id' => $event->eventId,
                'dlq_topic' => $this->dlqTopic,
            ]);
        } catch (\Exception $e) {
            // If we can't send to DLQ, log the error but don't fail
            Log::critical('Failed to send event to DLQ', [
                'event_id' => $event->eventId,
                'error' => $e->getMessage(),
                'original_error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Create a DLQ message with metadata
     */
    private function createDLQMessage(
        SyncEvent $event,
        \Exception $exception,
        int $retryCount,
        array $additionalContext
    ): array {
        return [
            'dlq_metadata' => [
                'failed_at' => now()->toIso8601String(),
                'retry_count' => $retryCount,
                'error_message' => $exception->getMessage(),
                'error_class' => get_class($exception),
                'stack_trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'additional_context' => $additionalContext,
            ],
            'original_event' => $event->toArray(),
        ];
    }

    /**
     * Publish message to DLQ topic
     */
    private function publishToDLQ(array $message): void
    {
        $producer = $this->getProducer();
        $topic = $producer->newTopic($this->dlqTopic);

        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Produce message
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload);

        // Flush
        $producer->flush(5000); // 5 second timeout
    }

    /**
     * Get or create Kafka producer
     */
    private function getProducer(): Producer
    {
        if ($this->producer === null) {
            $conf = new Conf();
            $conf->set('metadata.broker.list', config('kafka.brokers'));
            $conf->set('compression.type', config('kafka.producer.compression_type', 'snappy'));

            $this->producer = new Producer($conf);
        }

        return $this->producer;
    }

    /**
     * Get DLQ configuration
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'topic' => $this->dlqTopic,
        ];
    }

    /**
     * Destructor to ensure producer is properly flushed
     */
    public function __destruct()
    {
        if ($this->producer !== null) {
            $this->producer->flush(1000);
        }
    }
}


