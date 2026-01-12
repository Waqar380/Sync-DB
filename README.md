# Two-Way DB Sync POC using Kafka, Laravel & Source Flag

## 🎯 Project Overview

This is a Proof of Concept (POC) for **two-way database synchronization** between a Legacy platform (PostgreSQL) and a Revamped platform (MySQL) using:
- **Kafka** as the event transport layer
- **CDC (Change Data Capture)** for capturing database changes
- **Laravel Sync Service** as the intelligent consumer and writer
- **Source Flag** mechanism to prevent infinite replication loops

## 🏗️ Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         TWO-WAY SYNC FLOW                            │
└─────────────────────────────────────────────────────────────────────┘

[Legacy Platform - PostgreSQL]
         │
         │ CDC (Debezium)
         │ source='legacy'
         ▼
   [Kafka: legacy.events]
         │
         │ Consume
         ▼
   [Laravel Sync Service]
         │
         │ Transform & Write
         │ source='sync_service'
         ▼
[Revamped Platform - MySQL]
         │
         │ CDC (Debezium)
         │ source='revamp'
         ▼
   [Kafka: revamp.events]
         │
         │ Consume
         ▼
   [Laravel Sync Service]
         │
         │ Transform & Write
         │ source='sync_service'
         ▼
[Legacy Platform - PostgreSQL]

```

## 🔒 Loop Prevention Strategy

### The Source Column Mechanism

Every synced table in both databases includes a `source` column with three possible values:

| Value | Description |
|-------|-------------|
| `legacy` | Record created/updated directly in PostgreSQL |
| `revamp` | Record created/updated directly in MySQL |
| `sync_service` | Record created/updated by Laravel Sync Service |

### Hard Rule

**Any record with `source = 'sync_service'` MUST NOT be republished to Kafka.**

This is enforced at the CDC level (Debezium configuration) or within the Sync Service logic.

## 📋 System Components

### 1. Legacy Database (PostgreSQL)
- Dummy database for POC
- Different schema from revamped DB
- Includes `source` column on all synced tables

### 2. Revamped Database (MySQL)
- Dummy database for POC
- Different data model
- Includes `source` column on all synced tables

### 3. Kafka Topics
- `legacy.events` - Events from PostgreSQL CDC
- `revamp.events` - Events from MySQL CDC
- `sync.dlq` - Dead Letter Queue for failed events

### 4. Laravel Sync Service
- **Consumers**: `LegacyEventConsumer`, `RevampEventConsumer`
- **Transformers**: Bidirectional schema mappers
- **Writers**: Idempotent database writers
- **Handlers**: Retry, DLQ, and error handling

## 🗄️ Database Schemas

### Sample Entities (POC)
1. **Users** - User accounts
2. **Posts** - User-generated content
3. **Likes** - Post engagement

Each entity has:
- Different column names between Postgres and MySQL
- `source` column for loop prevention
- Timestamps (`created_at`, `updated_at`)
- Primary keys

## 🚀 Data Flow

### Legacy → Revamped Flow

```
1. INSERT/UPDATE in PostgreSQL (source='legacy')
2. Debezium captures change → Publishes to Kafka (legacy.events)
3. Laravel Sync Service consumes event
4. Service checks: source == 'sync_service'? → SKIP
5. Transform schema (Postgres → MySQL format)
6. UPSERT into MySQL (source='sync_service')
7. MySQL CDC sees change but FILTERS OUT (source='sync_service')
   → NO Kafka event published
```

### Revamped → Legacy Flow

```
1. INSERT/UPDATE in MySQL (source='revamp')
2. Debezium captures change → Publishes to Kafka (revamp.events)
3. Laravel Sync Service consumes event
4. Service checks: source == 'sync_service'? → SKIP
5. Transform schema (MySQL → Postgres format)
6. UPSERT into PostgreSQL (source='sync_service')
7. Postgres CDC sees change but FILTERS OUT (source='sync_service')
   → NO Kafka event published
```

## 📦 Project Structure

```
sync-DB/
├── README.md
├── ARCHITECTURE.md
├── docker-compose.yml
├── databases/
│   ├── postgres/
│   │   ├── migrations/
│   │   ├── seeds/
│   │   └── schema.sql
│   └── mysql/
│       ├── migrations/
│       ├── seeds/
│       └── schema.sql
├── debezium/
│   ├── postgres-connector.json
│   └── mysql-connector.json
├── sync-service/
│   ├── app/
│   │   ├── Console/
│   │   │   └── Commands/
│   │   │       ├── ConsumeLegacyEvents.php
│   │   │       └── ConsumeRevampEvents.php
│   │   ├── Services/
│   │   │   ├── Consumers/
│   │   │   ├── Transformers/
│   │   │   ├── Writers/
│   │   │   └── Handlers/
│   │   ├── DTOs/
│   │   ├── Models/
│   │   └── Repositories/
│   ├── config/
│   │   └── kafka.php
│   ├── database/
│   │   └── migrations/
│   ├── tests/
│   └── composer.json
└── testing/
    ├── test-scenarios.md
    └── scripts/
```

## 🔧 Technology Stack

- **Laravel**: 10.x
- **PHP**: 8.1+
- **PostgreSQL**: 15+
- **MySQL**: 8.0+
- **Kafka**: 3.x
- **Debezium**: 2.x
- **PHP Kafka Client**: rdkafka / php-rdkafka

## ✅ Success Criteria

The POC is successful if:

- ✅ Insert/update in Postgres reflects in MySQL
- ✅ Insert/update in MySQL reflects in Postgres
- ✅ No infinite Kafka loops occur
- ✅ Records with `source = 'sync_service'` are never re-synced
- ✅ Schema transformations work correctly
- ✅ Idempotency is maintained
- ✅ Failed events go to DLQ

## Setup (5 Minutes)

### Prerequisites
- ✅ Docker Desktop for Windows
- ✅ PHP 8.1+ (XAMPP works great!)
- ✅ Composer
- ✅ **rdkafka PHP extension** (most important!)


1. **Start Infrastructure**
```bash
docker-compose up -d
sleep 30  # Wait for services
```

2. **Register Debezium Connectors**
```bash
cd debezium
./register-connectors.sh
```

3. **Install & Configure Sync Service**
```bash
cd ../sync-service
composer install
cp env.example .env
php artisan key:generate
php artisan migrate --database=revamp
```

4. **Start Consumers (2 terminals)**
```bash
# Terminal 1
php artisan consume:legacy-events

# Terminal 2
php artisan consume:revamp-events
```

5. **Run Tests**
```bash
cd ../testing/scripts
chmod +x *.sh
./test-legacy-to-revamp.sh
./test-revamp-to-legacy.sh
./test-loop-prevention.sh
```

### Quick Test

```bash
# Insert in Legacy (PostgreSQL)
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('quicktest', 'quick@test.com', 'Quick Test', 'legacy');"

# Wait 5 seconds
sleep 5

# Verify in Revamp (MySQL)
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT * FROM revamp_users WHERE user_name = 'quicktest';"
```

**Expected**: Record appears in MySQL with `source='sync_service'` ✓

## 📊 Monitoring

- **Kafka Consumer Lag**: Check consumer group lag
- **DLQ Messages**: Monitor dead letter queue
- **Logs**: Structured logging in `storage/logs/`
- **Metrics**: Event processing times, success/failure rates

## 🔐 Security Considerations

- Database credentials stored in `.env`
- Kafka authentication configured
- SSL/TLS for database connections
- Input validation on all transformers

## 📝 Event Contract

```json
{
  "event_id": "uuid-v4",
  "entity_type": "users",
  "operation": "CREATE|UPDATE|DELETE",
  "primary_key": "123",
  "payload": {
    "id": 123,
    "name": "John Doe",
    "email": "john@example.com",
    "source": "legacy"
  },
  "source": "legacy",
  "event_version": "1.0.0",
  "timestamp": "2026-01-08T10:30:00Z"
}
```

## 🎓 Key Learnings

1. **Source flag** is critical for loop prevention
2. **Idempotency** ensures safe retries
3. **Schema evolution** requires versioned events
4. **DLQ** is essential for production readiness
5. **Monitoring** is mandatory for distributed systems

## 📚 Documentation

- [Architecture Details](./ARCHITECTURE.md)
- [Database Schemas](./databases/README.md)
- [Sync Service Guide](./sync-service/README.md)
- [Testing Guide](./testing/README.md)

## 🤝 Contributing

This is a POC project. For production use, consider:
- Schema version management
- Conflict resolution strategies
- Multi-region support
- Performance optimization
- Security hardening

## 📄 License

MIT License - POC Project

---

**Built with ❤️ for demonstrating two-way DB sync patterns**

