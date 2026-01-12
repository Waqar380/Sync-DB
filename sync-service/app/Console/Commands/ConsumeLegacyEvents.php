<?php

namespace App\Console\Commands;

use App\Services\Consumers\LegacyEventConsumer;
use App\Services\Handlers\ErrorHandler;
use App\Services\Handlers\RetryHandler;
use App\Services\Handlers\DLQHandler;
use App\Services\Writers\IdempotentRevampWriter;
use Illuminate\Console\Command;

/**
 * Artisan Command to Start Legacy Event Consumer
 * 
 * Usage: php artisan consume:legacy-events
 */
class ConsumeLegacyEvents extends Command
{
    protected $signature = 'consume:legacy-events
                            {--timeout=0 : Stop after N seconds (0 = run forever)}';

    protected $description = 'Consume events from Legacy platform and sync to Revamped platform';

    public function handle(): int
    {
        $this->info('Starting Legacy Event Consumer...');
        $this->info('Consuming events from Legacy (PostgreSQL) → Syncing to Revamped (MySQL)');
        $this->info('Press Ctrl+C to stop gracefully');
        $this->newLine();

        try {
            // Create dependencies
            $retryHandler = new RetryHandler();
            $dlqHandler = new DLQHandler();
            $errorHandler = new ErrorHandler($retryHandler, $dlqHandler);
            $writer = new IdempotentRevampWriter();

            // Create and start consumer
            $consumer = new LegacyEventConsumer($retryHandler, $errorHandler, $writer);

            // Display configuration
            $this->displayConfig();

            // Start consuming
            $consumer->consume();

            $this->info('Consumer stopped gracefully');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Consumer failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function displayConfig(): void
    {
        $this->table(
            ['Configuration', 'Value'],
            [
                ['Kafka Brokers', config('kafka.brokers')],
                ['Consumer Group', config('kafka.consumer.legacy.group_id')],
                ['Topics', implode(', ', config('kafka.consumer.legacy.topics'))],
                ['Max Retries', config('kafka.retry.max_attempts')],
                ['DLQ Topic', config('kafka.dlq.topic')],
                ['Idempotency', config('kafka.idempotency.enabled') ? 'Enabled' : 'Disabled'],
            ]
        );
        $this->newLine();
    }
}


