# Laravel Sync Service

## Overview

This Laravel application serves as the intelligent synchronization service between Legacy (PostgreSQL) and Revamped (MySQL) platforms.

## Responsibilities

1. **Consume Events**: Read CDC events from Kafka topics
2. **Transform Data**: Convert between different database schemas
3. **Write Idempotently**: Ensure safe, repeatable writes
4. **Prevent Loops**: Filter out `sync_service` events
5. **Handle Errors**: Retry with backoff, send to DLQ
6. **Track State**: Maintain entity mappings and processed events

## Project Structure

```
sync-service/
├── app/
│   ├── Console/Commands/
│   │   ├── ConsumeLegacyEvents.php    # Legacy consumer command
│   │   └── ConsumeRevampEvents.php    # Revamp consumer command
│   ├── DTOs/
│   │   ├── SyncEvent.php              # Event contract
│   │   ├── UserDTO.php                # User entity DTO
│   │   ├── PostDTO.php                # Post entity DTO
│   │   └── LikeDTO.php                # Like entity DTO
│   ├── Models/
│   │   ├── EntityMapping.php          # ID mapping model
│   │   └── ProcessedEvent.php         # Event tracking model
│   ├── Services/
│   │   ├── Consumers/
│   │   │   ├── BaseConsumer.php       # Base consumer logic
│   │   │   ├── LegacyEventConsumer.php
│   │   │   └── RevampEventConsumer.php
│   │   ├── Transformers/
│   │   │   ├── TransformerInterface.php
│   │   │   ├── LegacyToRevampMapper.php
│   │   │   ├── RevampToLegacyMapper.php
│   │   │   └── TransformerFactory.php
│   │   ├── Writers/
│   │   │   ├── IdempotentWriterInterface.php
│   │   │   ├── IdempotentLegacyWriter.php
│   │   │   └── IdempotentRevampWriter.php
│   │   └── Handlers/
│   │       ├── RetryHandler.php
│   │       ├── DLQHandler.php
│   │       └── ErrorHandler.php
├── config/
│   ├── kafka.php                      # Kafka configuration
│   └── database.php                   # Multi-DB configuration
├── database/migrations/
│   ├── 2026_01_08_000001_create_entity_mappings_table.php
│   └── 2026_01_08_000002_create_processed_events_table.php
└── composer.json
```

## Key Components

### 1. Event DTOs

**SyncEvent**: Standardized event format
```php
$event = new SyncEvent(
    eventId: 'uuid',
    entityType: 'users',
    operation: 'CREATE',
    primaryKey: 123,
    payload: [...],
    source: 'legacy',
    eventVersion: '1.0.0',
    timestamp: '2026-01-08T10:00:00Z'
);
```

### 2. Transformers

**LegacyToRevampMapper**: Transforms PostgreSQL → MySQL schema
**RevampToLegacyMapper**: Transforms MySQL → PostgreSQL schema

Example transformation:
```
Legacy (Postgres)      →  Revamped (MySQL)
username                  user_name
email                     email_address
full_name                 display_name
phone_number              mobile
status                    account_status
```

### 3. Idempotent Writers

**IdempotentLegacyWriter**: Writes to PostgreSQL with UPSERT
**IdempotentRevampWriter**: Writes to MySQL with UPSERT

Features:
- UPSERT operations (no duplicates)
- Entity mapping updates
- Event deduplication tracking
- Transaction safety

### 4. Error Handling

**RetryHandler**: Exponential backoff retry
- Initial delay: 1s
- Max delay: 30s
- Multiplier: 2x
- Max attempts: 3

**DLQHandler**: Dead Letter Queue for failed events
- Sends unprocessable events to `sync.dlq` topic
- Includes error metadata and stack trace

**ErrorHandler**: Centralized error management
- Determines retry strategy
- Classifies error severity
- Triggers alerts for critical errors

## Commands

### Start Legacy Consumer

```bash
php artisan consume:legacy-events
```

Consumes events from:
- `legacy.public.legacy_users`
- `legacy.public.legacy_posts`
- `legacy.public.legacy_likes`

Writes to: Revamp (MySQL) database

### Start Revamp Consumer

```bash
php artisan consume:revamp-events
```

Consumes events from:
- `revamp.revamp_db.revamp_users`
- `revamp.revamp_db.revamp_posts`
- `revamp.revamp_db.revamp_likes`

Writes to: Legacy (PostgreSQL) database

## Configuration

### Kafka Configuration (`config/kafka.php`)

```php
'brokers' => env('KAFKA_BROKERS', 'localhost:29092'),
'consumer' => [
    'legacy' => [
        'group_id' => 'sync-service-legacy',
        'topics' => [...],
    ],
    'revamp' => [
        'group_id' => 'sync-service-revamp',
        'topics' => [...],
    ],
],
```

### Database Configuration (`config/database.php`)

Two database connections:
- `legacy`: PostgreSQL connection
- `revamp`: MySQL connection

### Environment Variables (`.env`)

Key variables:
```env
KAFKA_BROKERS=localhost:29092
DB_LEGACY_HOST=127.0.0.1
DB_REVAMP_HOST=127.0.0.1
SYNC_MAX_RETRY_ATTEMPTS=3
```

## Loop Prevention

**Primary Mechanism**: Source column filtering

All events with `source = 'sync_service'` are:
1. Filtered at Debezium level (preferred)
2. Skipped by consumer (defense in depth)

```php
if ($event->shouldSkip()) {
    Log::info('Skipping event (loop prevention)');
    return;
}
```

## Idempotency

**Event Deduplication**: `processed_events` table
- Stores event_id of all processed events
- Prevents duplicate processing

**UPSERT Operations**: 
- PostgreSQL: `INSERT ... ON CONFLICT DO UPDATE`
- MySQL: `INSERT ... ON DUPLICATE KEY UPDATE`

**Entity Mappings**: `entity_mappings` table
- Maps Legacy ID ↔ Revamp ID
- Ensures consistent ID translation

## Monitoring

### Logs

```bash
tail -f storage/logs/laravel.log
```

Log levels:
- **DEBUG**: Detailed processing info
- **INFO**: Normal operations
- **WARNING**: Skipped/duplicate events
- **ERROR**: Failed processing
- **CRITICAL**: DLQ failures

### Metrics

Track in logs:
- Events processed per second
- Processing time (ms)
- Success/failure rates
- Consumer lag

### Consumer Lag

```bash
kafka-consumer-groups --bootstrap-server localhost:29092 \
  --describe --group sync-service-legacy
```

## Testing

### Unit Tests

```bash
php artisan test
```

### Integration Tests

See `../testing/test-scenarios.md`

### Manual Testing

```php
// Test transformer
$event = SyncEvent::fromArray([...]);
$transformer = TransformerFactory::forLegacyEvents();
$result = $transformer->transform($event);
```

## Troubleshooting

### Consumer Not Starting

Check:
1. rdkafka extension: `php -m | grep rdkafka`
2. Kafka connectivity: `telnet localhost 29092`
3. Configuration: `cat .env | grep KAFKA`

### Events Not Processing

Check:
1. Consumer logs for errors
2. Debezium connector status
3. Kafka topics exist
4. Database connectivity

### Performance Issues

Optimize:
1. Increase `max_poll_records`
2. Deploy multiple consumer instances
3. Optimize database queries
4. Add indexes on frequently queried columns

## Development

### Adding New Entity

1. Create DTO: `app/DTOs/NewEntityDTO.php`
2. Update transformers to handle new entity
3. Add table names to writers
4. Add Debezium connector configuration
5. Test thoroughly

### Changing Schema

1. Update DTO
2. Update transformer mapping
3. Version the event schema
4. Deploy transformer before changing DB
5. Monitor for errors

## Production Deployment

### Recommended Setup

1. **Deploy as systemd service** (Linux):
```ini
[Unit]
Description=Sync Service Legacy Consumer
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/sync-service
ExecStart=/usr/bin/php artisan consume:legacy-events
Restart=always

[Install]
WantedBy=multi-user.target
```

2. **Deploy as Docker container**:
```dockerfile
FROM php:8.2-cli
RUN pecl install rdkafka
COPY . /app
WORKDIR /app
CMD ["php", "artisan", "consume:legacy-events"]
```

3. **Deploy on Kubernetes**:
- Use separate deployments for each consumer
- Set replicas based on Kafka partitions
- Configure health checks and liveness probes

### Health Checks

Add endpoint:
```php
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'uptime' => app_uptime(),
        'consumers' => [
            'legacy' => consumer_status('legacy'),
            'revamp' => consumer_status('revamp'),
        ],
    ]);
});
```

### Monitoring Integration

- **Prometheus**: Export metrics
- **Grafana**: Visualize dashboards
- **PagerDuty**: Alert on critical errors
- **ELK Stack**: Centralized logging

## API Reference

### SyncEvent::fromDebeziumEvent()

Parses Debezium CDC event into standardized format.

### TransformerFactory::forLegacyEvents()

Returns transformer for Legacy → Revamp direction.

### IdempotentWriter::write()

Writes data with idempotency guarantees.

### RetryHandler::execute()

Executes operation with retry logic.

### DLQHandler::send()

Sends failed event to Dead Letter Queue.

## License

MIT

## Contributors

Senior Distributed Systems Architect & Laravel/Kafka Engineer

---

**For detailed architecture, see `../ARCHITECTURE.md`**


