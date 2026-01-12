# Architecture Documentation

## 🏛️ System Architecture

### Design Principles

1. **Event-Driven Architecture**: All changes flow through Kafka
2. **Clean Architecture**: Separation of concerns (DTOs, Services, Repositories)
3. **SOLID Principles**: Single responsibility, dependency inversion
4. **Idempotency**: All operations can be safely retried
5. **Eventual Consistency**: Acceptable for this use case

### Core Components

#### 1. Change Data Capture (CDC)

**Purpose**: Capture all INSERT/UPDATE/DELETE operations from databases

**Technology**: Debezium

**Configuration**:
```json
{
  "transforms": "filter",
  "transforms.filter.type": "io.debezium.transforms.Filter",
  "transforms.filter.language": "jsr223.groovy",
  "transforms.filter.condition": "value.source != 'sync_service'"
}
```

This ensures records with `source='sync_service'` are never published to Kafka.

#### 2. Kafka Topics

| Topic | Description | Partitions | Retention |
|-------|-------------|------------|-----------|
| `legacy.events` | PostgreSQL changes | 3 | 7 days |
| `revamp.events` | MySQL changes | 3 | 7 days |
| `sync.dlq` | Failed events | 1 | 30 days |

**Partitioning Strategy**: By entity primary key
- Ensures ordering per entity
- Allows parallel processing

#### 3. Laravel Sync Service

**Responsibilities**:
- Consume events from Kafka
- Validate event structure
- Transform schema between platforms
- Write to target database
- Handle failures and retries

**Components**:

```
Laravel Sync Service
├── Consumers
│   ├── LegacyEventConsumer (reads from legacy.events)
│   └── RevampEventConsumer (reads from revamp.events)
├── Transformers
│   ├── LegacyToRevampMapper
│   └── RevampToLegacyMapper
├── Writers
│   ├── IdempotentLegacyWriter
│   └── IdempotentRevampWriter
├── Handlers
│   ├── RetryHandler
│   ├── DLQHandler
│   └── ErrorHandler
└── Repositories
    ├── LegacyRepository
    ├── RevampRepository
    └── MappingRepository
```

## 🔄 Data Flow Diagrams

### Flow 1: Legacy → Revamped

```
┌────────────────────────────────────────────────────────────────┐
│ Step 1: User/App inserts data in PostgreSQL                   │
└────────────────────────────────────────────────────────────────┘
                              ↓
    INSERT INTO legacy_users (username, email, source)
    VALUES ('john_doe', 'john@example.com', 'legacy')
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 2: Debezium CDC captures change                          │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check: source == 'sync_service'? → NO
    Transform to Debezium event format
    Publish to Kafka topic: legacy.events
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 3: Laravel LegacyEventConsumer reads event               │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Parse event JSON
    Validate event schema
    Extract payload
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 4: LegacyToRevampMapper transforms schema                │
└────────────────────────────────────────────────────────────────┘
                              ↓
    username → user_name
    email → email_address
    Add default fields
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 5: IdempotentRevampWriter writes to MySQL                │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check mapping table for existing record
    UPSERT with source='sync_service'
                              ↓
    INSERT INTO revamp_users (user_name, email_address, source)
    VALUES ('john_doe', 'john@example.com', 'sync_service')
    ON DUPLICATE KEY UPDATE ...
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 6: MySQL CDC sees change but FILTERS IT OUT              │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check: source == 'sync_service'? → YES
    → DO NOT publish to Kafka
    → LOOP PREVENTED ✅
```

### Flow 2: Revamped → Legacy

```
┌────────────────────────────────────────────────────────────────┐
│ Step 1: User/App inserts data in MySQL                        │
└────────────────────────────────────────────────────────────────┘
                              ↓
    INSERT INTO revamp_posts (title, content, author_id, source)
    VALUES ('My Post', 'Content...', 1, 'revamp')
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 2: Debezium CDC captures change                          │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check: source == 'sync_service'? → NO
    Transform to Debezium event format
    Publish to Kafka topic: revamp.events
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 3: Laravel RevampEventConsumer reads event               │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Parse event JSON
    Validate event schema
    Extract payload
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 4: RevampToLegacyMapper transforms schema                │
└────────────────────────────────────────────────────────────────┘
                              ↓
    title → post_title
    content → post_content
    author_id → user_id
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 5: IdempotentLegacyWriter writes to PostgreSQL           │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check mapping table for existing record
    UPSERT with source='sync_service'
                              ↓
    INSERT INTO legacy_posts (post_title, post_content, user_id, source)
    VALUES ('My Post', 'Content...', 1, 'sync_service')
    ON CONFLICT (id) DO UPDATE ...
                              ↓
┌────────────────────────────────────────────────────────────────┐
│ Step 6: Postgres CDC sees change but FILTERS IT OUT           │
└────────────────────────────────────────────────────────────────┘
                              ↓
    Check: source == 'sync_service'? → YES
    → DO NOT publish to Kafka
    → LOOP PREVENTED ✅
```

## 🛡️ Loop Prevention Mechanism

### Problem: Infinite Replication Loop

Without loop prevention:
```
Legacy DB → Kafka → Sync Service → Revamp DB → Kafka → Sync Service → Legacy DB → ...
```

### Solution: Source Flag

1. **Direct Inserts**: Set `source = 'legacy'` or `source = 'revamp'`
2. **Sync Service Writes**: Set `source = 'sync_service'`
3. **CDC Filter**: Only publish events where `source != 'sync_service'`

### Implementation Options

#### Option A: CDC-Level Filter (Recommended)
Configure Debezium to filter events:
```json
"transforms.filter.condition": "value.source != 'sync_service'"
```

**Pros**: Events never reach Kafka, saves resources
**Cons**: Requires CDC configuration

#### Option B: Consumer-Level Filter
Skip in Laravel consumer:
```php
if ($event->payload['source'] === 'sync_service') {
    return; // Skip processing
}
```

**Pros**: More flexible, easier to debug
**Cons**: Events still flow through Kafka

**Best Practice**: Use both for defense in depth

## 🔐 Idempotency

### Challenge
Kafka provides "at least once" delivery, meaning:
- Events may be delivered multiple times
- Same change might be processed twice

### Solution: Idempotent Operations

#### 1. Mapping Table
```sql
CREATE TABLE entity_mappings (
    id SERIAL PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    legacy_id INTEGER NOT NULL,
    revamp_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entity_type, legacy_id),
    UNIQUE(entity_type, revamp_id)
);
```

#### 2. UPSERT Operations

**PostgreSQL**:
```sql
INSERT INTO legacy_users (id, username, email, source)
VALUES ($1, $2, $3, 'sync_service')
ON CONFLICT (id) DO UPDATE SET
    username = EXCLUDED.username,
    email = EXCLUDED.email,
    updated_at = CURRENT_TIMESTAMP;
```

**MySQL**:
```sql
INSERT INTO revamp_users (id, user_name, email_address, source)
VALUES (?, ?, ?, 'sync_service')
ON DUPLICATE KEY UPDATE
    user_name = VALUES(user_name),
    email_address = VALUES(email_address),
    updated_at = CURRENT_TIMESTAMP;
```

#### 3. Event Deduplication
Store processed event IDs:
```sql
CREATE TABLE processed_events (
    event_id VARCHAR(36) PRIMARY KEY,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 🚨 Error Handling

### Failure Scenarios

1. **Network Failure**: Retry with exponential backoff
2. **Schema Mismatch**: Log error, send to DLQ
3. **Database Constraint Violation**: Log, send to DLQ
4. **Transform Error**: Log, send to DLQ

### Retry Strategy

```php
$retryConfig = [
    'max_attempts' => 3,
    'initial_delay' => 1000, // ms
    'max_delay' => 30000,
    'multiplier' => 2
];
```

**Retry Schedule**:
- Attempt 1: Immediate
- Attempt 2: Wait 1s
- Attempt 3: Wait 2s
- Attempt 4: Wait 4s
- Failed → Send to DLQ

### Dead Letter Queue (DLQ)

Failed events go to `sync.dlq` topic with metadata:
```json
{
  "original_event": {...},
  "error_message": "Constraint violation",
  "stack_trace": "...",
  "retry_count": 3,
  "failed_at": "2026-01-08T12:00:00Z"
}
```

## 📊 Observability

### Logging

**Structured Logs**:
```php
Log::info('Event consumed', [
    'event_id' => $event->id,
    'entity_type' => $event->entityType,
    'operation' => $event->operation,
    'source' => $event->source,
    'processing_time_ms' => $duration
]);
```

### Metrics

Track:
- Events consumed per second
- Processing time (p50, p95, p99)
- Success/failure rates
- DLQ message count
- Consumer lag

### Consumer Lag Monitoring

```bash
kafka-consumer-groups --bootstrap-server localhost:9092 \
    --describe --group sync-service
```

## 🔒 Security

1. **Database Credentials**: Store in `.env`, never commit
2. **Kafka Authentication**: SASL/SCRAM or SSL
3. **Network Security**: VPC, private subnets
4. **Input Validation**: Validate all event payloads
5. **SQL Injection Prevention**: Use prepared statements

## 🚀 Performance Considerations

### Kafka Tuning
- **Partitions**: 3-5 per topic for parallelism
- **Batch Size**: 100-1000 messages
- **Compression**: Snappy or LZ4

### Database Tuning
- **Connection Pooling**: Reuse connections
- **Batch Writes**: Group multiple UPSERTs
- **Indexes**: On foreign keys and frequently queried columns

### Consumer Tuning
- **Commit Interval**: After successful write
- **Prefetch Count**: 10-100 messages
- **Parallel Consumers**: One per partition

## 🧪 Testing Strategy

### Unit Tests
- Transformer logic
- Validation rules
- Mapping functions

### Integration Tests
- End-to-end sync flow
- Error handling paths
- Idempotency verification

### Chaos Tests
- Kill consumers mid-processing
- Simulate network failures
- Database connection loss

## 📈 Scalability

### Horizontal Scaling
- Deploy multiple sync service instances
- Kafka automatically distributes partitions

### Vertical Scaling
- Increase consumer resources
- Optimize database queries

## 🎯 Production Readiness Checklist

- [ ] Health checks endpoint
- [ ] Graceful shutdown
- [ ] Circuit breakers
- [ ] Rate limiting
- [ ] Alerting (PagerDuty, etc.)
- [ ] Backup and recovery
- [ ] Documentation
- [ ] Runbooks
- [ ] Load testing
- [ ] Security audit

---

**This architecture ensures reliable, loop-free two-way database synchronization.**


