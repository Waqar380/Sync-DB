<?php

namespace App\Services\Consumers;

use App\DTOs\SyncEvent;
use App\Models\ProcessedEvent;
use App\Services\Handlers\ErrorHandler;
use App\Services\Handlers\RetryHandler;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;

/**
 * Base Kafka Consumer
 * 
 * Provides common functionality for all Kafka consumers
 * including event parsing, loop prevention, and error handling.
 */
abstract class BaseConsumer
{
    protected KafkaConsumer $consumer;
    protected RetryHandler $retryHandler;
    protected ErrorHandler $errorHandler;
    protected bool $running = true;

    public function __construct(
        RetryHandler $retryHandler,
        ErrorHandler $errorHandler
    ) {
        $this->retryHandler = $retryHandler;
        $this->errorHandler = $errorHandler;
    }

    /**
     * Start consuming messages
     */
    public function consume(): void
    {
        $this->initializeConsumer();

        Log::info('Starting Kafka consumer', [
            'consumer_class' => get_class($this),
            'topics' => $this->getTopics(),
            'group_id' => $this->getGroupId(),
        ]);

        // Subscribe to topics
        $this->consumer->subscribe($this->getTopics());

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();

        // Main consumption loop
        while ($this->running) {
            $this->consumeMessage();
        }

        Log::info('Kafka consumer stopped gracefully');
    }

    /**
     * Consume a single message
     */
    protected function consumeMessage(): void
    {
        try {
            $message = $this->consumer->consume(config('kafka.timeouts.consume_timeout_ms', 1000));

            if ($message === null) {
                return; // Timeout, continue
            }

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->handleMessage($message);
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    Log::debug('Reached end of partition');
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Timeout is normal, just continue
                    break;

                default:
                    Log::error('Kafka consumer error', [
                        'error_code' => $message->err,
                        'error_message' => $message->errstr(),
                    ]);
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Error in consumption loop', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Sleep briefly to avoid tight error loop
            sleep(1);
        }
    }

    /**
     * Handle a Kafka message
     */
    protected function handleMessage(Message $message): void
    {
        $startTime = microtime(true);

        try {
            Log::debug('Received Kafka message', [
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);

            // Parse message payload
            $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);

            // Create SyncEvent from Debezium message
            $event = SyncEvent::fromDebeziumEvent($payload, $message->topic_name);

            Log::info('Processing event', [
                'event' => (string)$event,
                'topic' => $message->topic_name,
            ]);

            // Check for loop prevention: Skip events from sync_service
            if ($event->shouldSkip()) {
                Log::info('Skipping event (loop prevention)', [
                    'event_id' => $event->eventId,
                    'source' => $event->source,
                ]);

                $this->commitOffset($message);
                return;
            }

            // Check if event was already processed (idempotency)
            if ($this->isAlreadyProcessed($event)) {
                Log::info('Skipping already processed event', [
                    'event_id' => $event->eventId,
                ]);

                $this->commitOffset($message);
                return;
            }

            // Process the event with retry logic
            $this->retryHandler->execute(
                fn() => $this->processEvent($event),
                "Processing event {$event->eventId}"
            );

            // Commit offset after successful processing
            $this->commitOffset($message);

            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::info('Event processed successfully', [
                'event_id' => $event->eventId,
                'processing_time_ms' => round($processingTime, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process message', [
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
                'error' => $e->getMessage(),
            ]);

            // Try to parse event for DLQ (if possible)
            try {
                $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
                $event = SyncEvent::fromDebeziumEvent($payload, $message->topic_name);
                
                $this->errorHandler->handle($event, $e, context: [
                    'topic' => $message->topic_name,
                    'partition' => $message->partition,
                    'offset' => $message->offset,
                ]);
            } catch (\Exception $parseError) {
                Log::critical('Failed to parse message for DLQ', [
                    'parse_error' => $parseError->getMessage(),
                    'original_error' => $e->getMessage(),
                ]);
            }

            // Commit offset even on failure to avoid getting stuck
            $this->commitOffset($message);
        }
    }

    /**
     * Check if event was already processed
     */
    protected function isAlreadyProcessed(SyncEvent $event): bool
    {
        if (!config('kafka.idempotency.enabled', true)) {
            return false;
        }

        return ProcessedEvent::isProcessed($event->eventId);
    }

    /**
     * Commit message offset
     */
    protected function commitOffset(Message $message): void
    {
        try {
            $this->consumer->commit($message);
            
            Log::debug('Committed offset', [
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to commit offset', [
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize Kafka consumer
     */
    protected function initializeConsumer(): void
    {
        $conf = new Conf();
        
        // Set broker list
        $conf->set('metadata.broker.list', config('kafka.brokers'));
        
        // Set consumer group
        $conf->set('group.id', $this->getGroupId());
        
        // Set auto offset reset
        $conf->set('auto.offset.reset', $this->getConfig()['auto_offset_reset']);
        
        // Disable auto commit (we commit manually)
        $conf->set('enable.auto.commit', $this->getConfig()['enable_auto_commit'] ? 'true' : 'false');
        
        // Session timeout
        $conf->set('session.timeout.ms', (string)$this->getConfig()['session_timeout_ms']);
        
        // Create consumer
        $this->consumer = new KafkaConsumer($conf);
    }

    /**
     * Setup signal handlers for graceful shutdown
     * Note: pcntl extension is not available on Windows
     */
    protected function setupSignalHandlers(): void
    {
        // Check if pcntl extension is available (Unix/Linux only)
        if (!function_exists('pcntl_async_signals')) {
            Log::debug('pcntl extension not available (Windows system). Graceful shutdown via Ctrl+C.');
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            Log::info('Received SIGTERM signal, shutting down gracefully...');
            $this->running = false;
        });

        pcntl_signal(SIGINT, function () {
            Log::info('Received SIGINT signal, shutting down gracefully...');
            $this->running = false;
        });
    }

    /**
     * Get topics to subscribe to
     */
    abstract protected function getTopics(): array;

    /**
     * Get consumer group ID
     */
    abstract protected function getGroupId(): string;

    /**
     * Get consumer configuration
     */
    abstract protected function getConfig(): array;

    /**
     * Process a sync event
     */
    abstract protected function processEvent(SyncEvent $event): void;
}


