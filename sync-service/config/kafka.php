<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kafka Brokers
    |--------------------------------------------------------------------------
    |
    | The list of Kafka broker addresses. Can be comma-separated for
    | multiple brokers.
    |
    */
    'brokers' => env('KAFKA_BROKERS', 'localhost:9092'),

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kafka consumers that will read events from topics.
    |
    */
    'consumer' => [
        'legacy' => [
            'group_id' => env('KAFKA_LEGACY_CONSUMER_GROUP', 'sync-service-legacy'),
            'topics' => explode(',', env('KAFKA_LEGACY_TOPIC', '')),
            'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
            'enable_auto_commit' => env('KAFKA_ENABLE_AUTO_COMMIT', false),
            'session_timeout_ms' => env('KAFKA_SESSION_TIMEOUT_MS', 30000),
            'max_poll_records' => env('KAFKA_MAX_POLL_RECORDS', 100),
        ],
        
        'revamp' => [
            'group_id' => env('KAFKA_REVAMP_CONSUMER_GROUP', 'sync-service-revamp'),
            'topics' => explode(',', env('KAFKA_REVAMP_TOPIC', '')),
            'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
            'enable_auto_commit' => env('KAFKA_ENABLE_AUTO_COMMIT', false),
            'session_timeout_ms' => env('KAFKA_SESSION_TIMEOUT_MS', 30000),
            'max_poll_records' => env('KAFKA_MAX_POLL_RECORDS', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Producer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kafka producers (for DLQ messages).
    |
    */
    'producer' => [
        'compression_type' => 'snappy',
        'acks' => 'all',
        'max_in_flight_requests_per_connection' => 5,
        'retries' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queue
    |--------------------------------------------------------------------------
    |
    | Topic for failed messages that couldn't be processed after retries.
    |
    */
    'dlq' => [
        'topic' => env('KAFKA_DLQ_TOPIC', 'sync.dlq'),
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for retry mechanism when processing fails.
    |
    */
    'retry' => [
        'max_attempts' => env('SYNC_MAX_RETRY_ATTEMPTS', 3),
        'initial_delay' => env('SYNC_RETRY_INITIAL_DELAY', 1000), // milliseconds
        'max_delay' => env('SYNC_RETRY_MAX_DELAY', 30000), // milliseconds
        'multiplier' => env('SYNC_RETRY_MULTIPLIER', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for ensuring idempotent message processing.
    |
    */
    'idempotency' => [
        'enabled' => env('SYNC_ENABLE_IDEMPOTENCY', true),
        'deduplication_enabled' => env('SYNC_EVENT_DEDUPLICATION', true),
        'deduplication_window_hours' => env('SYNC_DEDUPLICATION_WINDOW_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for logging and metrics.
    |
    */
    'observability' => [
        'enable_metrics' => env('SYNC_ENABLE_METRICS', true),
        'log_level' => env('SYNC_LOG_LEVEL', 'debug'),
        'log_events' => env('SYNC_LOG_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout Configuration
    |--------------------------------------------------------------------------
    |
    | Timeouts for various operations.
    |
    */
    'timeouts' => [
        'consume_timeout_ms' => 1000,
        'commit_timeout_ms' => 5000,
    ],
];


