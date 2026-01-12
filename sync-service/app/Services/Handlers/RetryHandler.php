<?php

namespace App\Services\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * Retry Handler with Exponential Backoff
 * 
 * Implements retry logic with exponential backoff for
 * transient failures during event processing.
 */
class RetryHandler
{
    private int $maxAttempts;
    private int $initialDelay;
    private int $maxDelay;
    private float $multiplier;

    public function __construct()
    {
        $this->maxAttempts = config('kafka.retry.max_attempts', 3);
        $this->initialDelay = config('kafka.retry.initial_delay', 1000); // milliseconds
        $this->maxDelay = config('kafka.retry.max_delay', 30000); // milliseconds
        $this->multiplier = config('kafka.retry.multiplier', 2);
    }

    /**
     * Execute a callable with retry logic
     * 
     * @param callable $operation The operation to execute
     * @param string $context Context for logging
     * @return mixed The result of the operation
     * @throws \Exception If all retry attempts fail
     */
    public function execute(callable $operation, string $context = 'operation')
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                Log::debug("Attempting {$context}", [
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                ]);

                $result = $operation();

                if ($attempt > 1) {
                    Log::info("Succeeded on retry", [
                        'context' => $context,
                        'attempt' => $attempt,
                    ]);
                }

                return $result;
            } catch (\Exception $e) {
                $lastException = $e;

                Log::warning("Attempt failed", [
                    'context' => $context,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);

                // Don't retry if it's the last attempt
                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                // Calculate delay with exponential backoff
                $delay = $this->calculateDelay($attempt);

                Log::debug("Waiting before retry", [
                    'context' => $context,
                    'delay_ms' => $delay,
                    'next_attempt' => $attempt + 1,
                ]);

                // Sleep for the calculated delay
                usleep($delay * 1000); // Convert milliseconds to microseconds
            }
        }

        // All attempts failed
        Log::error("All retry attempts failed", [
            'context' => $context,
            'total_attempts' => $attempt,
            'final_error' => $lastException->getMessage(),
        ]);

        throw new \Exception(
            "Failed after {$attempt} attempts: {$lastException->getMessage()}",
            0,
            $lastException
        );
    }

    /**
     * Calculate delay with exponential backoff
     * 
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in milliseconds
     */
    private function calculateDelay(int $attempt): int
    {
        // Exponential backoff: initialDelay * (multiplier ^ (attempt - 1))
        $delay = $this->initialDelay * pow($this->multiplier, $attempt - 1);

        // Cap at max delay
        return min((int)$delay, $this->maxDelay);
    }

    /**
     * Check if an exception is retryable
     * 
     * Some exceptions (like validation errors) shouldn't be retried.
     * 
     * @param \Exception $exception
     * @return bool
     */
    public function isRetryable(\Exception $exception): bool
    {
        // List of non-retryable exception types
        $nonRetryableExceptions = [
            \InvalidArgumentException::class,
            \BadMethodCallException::class,
        ];

        foreach ($nonRetryableExceptions as $exceptionClass) {
            if ($exception instanceof $exceptionClass) {
                return false;
            }
        }

        // By default, consider exceptions retryable
        return true;
    }

    /**
     * Get retry configuration
     */
    public function getConfig(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'initial_delay' => $this->initialDelay,
            'max_delay' => $this->maxDelay,
            'multiplier' => $this->multiplier,
        ];
    }
}


