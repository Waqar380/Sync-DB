<?php

namespace App\DTOs;

use Ramsey\Uuid\Uuid;

/**
 * Data Transfer Object for Sync Events
 * 
 * This represents a versioned event that flows through Kafka
 * between the Legacy and Revamped platforms.
 */
class SyncEvent
{
    public const OPERATION_CREATE = 'CREATE';
    public const OPERATION_UPDATE = 'UPDATE';
    public const OPERATION_DELETE = 'DELETE';

    public const SOURCE_LEGACY = 'legacy';
    public const SOURCE_REVAMP = 'revamp';
    public const SOURCE_SYNC_SERVICE = 'sync_service';

    public const VERSION = '1.0.0';

    public function __construct(
        public readonly string $eventId,
        public readonly string $entityType,
        public readonly string $operation,
        public readonly string|int $primaryKey,
        public readonly array $payload,
        public readonly string $source,
        public readonly string $eventVersion,
        public readonly string $timestamp,
        public readonly ?array $metadata = null
    ) {
        $this->validate();
    }

    /**
     * Create a new event from Debezium CDC payload
     */
    public static function fromDebeziumEvent(array $message, string $topic): self
    {
        // Extract table name from topic
        // Topic format: legacy.public.legacy_users or revamp.revamp_db.revamp_users
        $topicParts = explode('.', $topic);
        $tableName = end($topicParts);
        
        // Determine entity type from table name
        $entityType = self::extractEntityType($tableName);
        
        // Extract operation
        $operation = self::mapDebeziumOperation($message['__op'] ?? $message['op'] ?? 'r');
        
        // Extract payload based on operation
        // If using ExtractNewRecordState transform (unwrap), the message is already flattened
        // Otherwise, it will be in 'after' or 'before' envelope
        if (isset($message['after']) || isset($message['before'])) {
            // Standard Debezium format with envelope
            $payload = match($operation) {
                self::OPERATION_DELETE => $message['before'] ?? [],
                default => $message['after'] ?? $message['before'] ?? [],
            };
        } else {
            // Unwrapped format (ExtractNewRecordState transform applied)
            $payload = $message;
        }
        
        // Extract source
        $source = $payload['source'] ?? self::SOURCE_LEGACY;
        
        // Extract primary key
        $primaryKey = $payload['id'] ?? null;
        
        if ($primaryKey === null) {
            throw new \InvalidArgumentException('Primary key not found in payload');
        }
        
        return new self(
            eventId: Uuid::uuid4()->toString(),
            entityType: $entityType,
            operation: $operation,
            primaryKey: $primaryKey,
            payload: $payload,
            source: $source,
            eventVersion: self::VERSION,
            timestamp: $message['__source_ts_ms'] ?? $message['ts_ms'] ?? now()->toIso8601String(),
            metadata: [
                'topic' => $topic,
                'debezium_op' => $message['__op'] ?? $message['op'] ?? null,
                'source_ts_ms' => $message['__source_ts_ms'] ?? $message['source']['ts_ms'] ?? null,
            ]
        );
    }

    /**
     * Create event from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'] ?? Uuid::uuid4()->toString(),
            entityType: $data['entity_type'],
            operation: $data['operation'],
            primaryKey: $data['primary_key'],
            payload: $data['payload'],
            source: $data['source'],
            eventVersion: $data['event_version'] ?? self::VERSION,
            timestamp: $data['timestamp'] ?? now()->toIso8601String(),
            metadata: $data['metadata'] ?? null
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'entity_type' => $this->entityType,
            'operation' => $this->operation,
            'primary_key' => $this->primaryKey,
            'payload' => $this->payload,
            'source' => $this->source,
            'event_version' => $this->eventVersion,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Check if this event should be skipped (loop prevention)
     */
    public function shouldSkip(): bool
    {
        // Skip events from sync service to prevent infinite loops
        return $this->source === self::SOURCE_SYNC_SERVICE;
    }

    /**
     * Check if event is a create operation
     */
    public function isCreate(): bool
    {
        return $this->operation === self::OPERATION_CREATE;
    }

    /**
     * Check if event is an update operation
     */
    public function isUpdate(): bool
    {
        return $this->operation === self::OPERATION_UPDATE;
    }

    /**
     * Check if event is a delete operation
     */
    public function isDelete(): bool
    {
        return $this->operation === self::OPERATION_DELETE;
    }

    /**
     * Validate event data
     */
    private function validate(): void
    {
        if (!in_array($this->operation, [self::OPERATION_CREATE, self::OPERATION_UPDATE, self::OPERATION_DELETE])) {
            throw new \InvalidArgumentException("Invalid operation: {$this->operation}");
        }

        if (!in_array($this->source, [self::SOURCE_LEGACY, self::SOURCE_REVAMP, self::SOURCE_SYNC_SERVICE])) {
            throw new \InvalidArgumentException("Invalid source: {$this->source}");
        }

        if (empty($this->entityType)) {
            throw new \InvalidArgumentException('Entity type cannot be empty');
        }

        if (empty($this->primaryKey)) {
            throw new \InvalidArgumentException('Primary key cannot be empty');
        }
    }

    /**
     * Extract entity type from table name
     */
    private static function extractEntityType(string $tableName): string
    {
        // Remove prefix (legacy_ or revamp_)
        $entityType = preg_replace('/^(legacy_|revamp_)/', '', $tableName);
        
        return $entityType;
    }

    /**
     * Map Debezium operation to our operation enum
     */
    private static function mapDebeziumOperation(string $debeziumOp): string
    {
        return match($debeziumOp) {
            'c', 'r' => self::OPERATION_CREATE, // c=create, r=read (snapshot)
            'u' => self::OPERATION_UPDATE,
            'd' => self::OPERATION_DELETE,
            default => throw new \InvalidArgumentException("Unknown Debezium operation: {$debeziumOp}"),
        };
    }

    /**
     * Get a string representation for logging
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s %s/%s (source: %s)',
            $this->eventId,
            $this->operation,
            $this->entityType,
            $this->primaryKey,
            $this->source
        );
    }
}


