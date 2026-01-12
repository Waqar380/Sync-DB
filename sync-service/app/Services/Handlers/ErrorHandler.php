<?php

namespace App\Services\Handlers;

use App\DTOs\SyncEvent;
use Illuminate\Support\Facades\Log;

/**
 * Centralized Error Handler for Sync Service
 * 
 * Handles errors during event processing, determines retry strategy,
 * and sends to DLQ when necessary.
 */
class ErrorHandler
{
    public function __construct(
        private RetryHandler $retryHandler,
        private DLQHandler $dlqHandler
    ) {}

    /**
     * Handle an error during event processing
     * 
     * @param SyncEvent $event The event being processed
     * @param \Exception $exception The exception that occurred
     * @param int $attemptNumber Current attempt number
     * @param array $context Additional context
     * @return bool Whether to continue processing (false = send to DLQ)
     */
    public function handle(
        SyncEvent $event,
        \Exception $exception,
        int $attemptNumber = 1,
        array $context = []
    ): bool {
        Log::error('Error during event processing', [
            'event_id' => $event->eventId,
            'entity_type' => $event->entityType,
            'operation' => $event->operation,
            'attempt' => $attemptNumber,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'context' => $context,
        ]);

        // Check if the exception is retryable
        if (!$this->retryHandler->isRetryable($exception)) {
            Log::warning('Exception is not retryable, sending to DLQ', [
                'event_id' => $event->eventId,
                'error_class' => get_class($exception),
            ]);

            $this->sendToDLQ($event, $exception, $attemptNumber, $context);
            return false;
        }

        // Check if we've exceeded max retry attempts
        $maxAttempts = config('kafka.retry.max_attempts', 3);
        if ($attemptNumber >= $maxAttempts) {
            Log::warning('Max retry attempts exceeded, sending to DLQ', [
                'event_id' => $event->eventId,
                'attempts' => $attemptNumber,
                'max_attempts' => $maxAttempts,
            ]);

            $this->sendToDLQ($event, $exception, $attemptNumber, $context);
            return false;
        }

        // Continue retrying
        return true;
    }

    /**
     * Send event to Dead Letter Queue
     */
    private function sendToDLQ(
        SyncEvent $event,
        \Exception $exception,
        int $retryCount,
        array $context
    ): void {
        try {
            $this->dlqHandler->send($event, $exception, $retryCount, $context);
        } catch (\Exception $e) {
            // Log but don't throw - we don't want to crash if DLQ fails
            Log::critical('Failed to send to DLQ', [
                'event_id' => $event->eventId,
                'dlq_error' => $e->getMessage(),
                'original_error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Classify error severity
     * 
     * @param \Exception $exception
     * @return string 'critical'|'high'|'medium'|'low'
     */
    public function classifySeverity(\Exception $exception): string
    {
        // Database connection errors are critical
        if ($exception instanceof \PDOException) {
            return 'critical';
        }

        // Validation errors are low severity
        if ($exception instanceof \InvalidArgumentException) {
            return 'low';
        }

        // Kafka errors are high severity
        if (str_contains(get_class($exception), 'Kafka')) {
            return 'high';
        }

        // Default to medium
        return 'medium';
    }

    /**
     * Determine if error should trigger an alert
     */
    public function shouldAlert(\Exception $exception): bool
    {
        $severity = $this->classifySeverity($exception);
        
        return in_array($severity, ['critical', 'high']);
    }

    /**
     * Get error statistics
     */
    public function getStatistics(): array
    {
        // This could be expanded to track errors in a database or cache
        return [
            'retry_config' => $this->retryHandler->getConfig(),
            'dlq_config' => $this->dlqHandler->getConfig(),
        ];
    }
}


