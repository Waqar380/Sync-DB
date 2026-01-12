<?php

namespace App\Console\Commands;

use App\Services\Consumers\RevampEventConsumer;
use App\Services\Handlers\ErrorHandler;
use App\Services\Handlers\RetryHandler;
use App\Services\Handlers\DLQHandler;
use App\Services\Writers\IdempotentLegacyWriter;
use Illuminate\Console\Command;

/**
 * Artisan Command to Start Revamp Event Consumer
 * 
 * Usage: php artisan consume:revamp-events
 */
class ConsumeRevampEvents extends Command
{
    protected $signature = 'consume:revamp-events
                            {--timeout=0 : Stop after N seconds (0 = run forever)}';

    protected $description = 'Consume events from Revamped platform and sync to Legacy platform';

    public function handle(): int
    {
        $this->info('Starting Revamp Event Consumer...');
        $this->info('Consuming events from Revamped (MySQL) → Syncing to Legacy (PostgreSQL)');
        $this->info('Press Ctrl+C to stop gracefully');
        $this->newLine();

        try {
            // Create dependencies
            $retryHandler = new RetryHandler();
            $dlqHandler = new DLQHandler();
            $errorHandler = new ErrorHandler($retryHandler, $dlqHandler);
            $writer = new IdempotentLegacyWriter();

            // Create and start consumer
            $consumer = new RevampEventConsumer($retryHandler, $errorHandler, $writer);

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
                ['Consumer Group', config('kafka.consumer.revamp.group_id')],
                ['Topics', implode(', ', config('kafka.consumer.revamp.topics'))],
                ['Max Retries', config('kafka.retry.max_attempts')],
                ['DLQ Topic', config('kafka.dlq.topic')],
                ['Idempotency', config('kafka.idempotency.enabled') ? 'Enabled' : 'Disabled'],
            ]
        );
        $this->newLine();
    }
}


