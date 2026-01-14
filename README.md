# Two-Way Database Synchronization POC

## 🎯 Overview

A **production-ready** Proof of Concept for **two-way database synchronization** between **two MySQL databases** with different schemas, using **Kafka**, **Debezium CDC**, and **Laravel**.

### **Key Features:**
- ✅ **Dual MySQL Setup** - Both Legacy and Revamp databases are MySQL
- ✅ **Different Schemas** - Automatic schema transformation between databases
- ✅ **Two-Way Sync** - Changes flow in both directions
- ✅ **Loop Prevention** - Intelligent `source` tracking prevents infinite loops
- ✅ **AUTO_INCREMENT Auto-Fix** - Database triggers prevent duplicate key errors
- ✅ **Idempotent Writes** - Safe to replay events
- ✅ **Event Streaming** - Kafka for reliable, scalable messaging
- ✅ **Change Data Capture** - Debezium captures all database changes
- ✅ **Transformation Layer** - DTOs and mappers handle schema differences

---

## 🏗️ Architecture

```
┌─────────────────┐         ┌─────────────────┐
│  MySQL Legacy   │         │  MySQL Revamp   │
│  (Port 3307)    │         │  (Port 3306)    │
│                 │         │                 │
│ legacy_users    │         │ revamp_users    │
│ legacy_posts    │         │ revamp_posts    │
│ legacy_likes    │         │ revamp_likes    │
└───────┬─────────┘         └───────┬─────────┘
        │                           │
        │ CDC                       │ CDC
        ▼                           ▼
    ┌────────────────────────────────────┐
    │         Kafka + Debezium           │
    │  • Auto-capture all changes        │
    │  • Event streaming                 │
    └────────────┬───────────────────────┘
                 │
                 ▼
         ┌───────────────┐
         │ Laravel Sync  │
         │   Service     │
         │               │
         │ • Schema Transform │
         │ • Loop Prevention  │
         │ • Idempotent Write │
         └───────────────┘
```

---

## 🚀 Quick Start

### **Prerequisites:**
- Docker Desktop (running)
- PHP 8.1+ with Composer
- Windows PowerShell or CMD

### **Step 1: Start Infrastructure**
```powershell
.\start-dual-mysql.bat
```

This will:
1. Start both MySQL databases
2. Start Kafka + Zookeeper
3. Start Debezium Connect
4. Register both MySQL connectors
5. Display service status

### **Step 2: Setup Laravel**
```powershell
cd sync-service
composer install
cp env.example .env
php artisan key:generate
php artisan migrate
```

### **Step 3: Start Consumers**

**Terminal 1:**
```powershell
cd sync-service
php artisan consume:legacy-events
```

**Terminal 2:**
```powershell
cd sync-service
php artisan consume:revamp-events
```

### **Step 4: Test the Sync**
```powershell
.\test-dual-mysql-sync.bat
```

---

## 📊 Database Configuration

### **Legacy MySQL (Port 3307)**

**Tables:**
- `legacy_users` (username, email, full_name, phone_number, status)
- `legacy_posts` (user_id, post_title, post_content, post_status, view_count)
- `legacy_likes` (user_id, post_id, like_type)

**Location:** `databases/mysql-legacy/schema.sql`

### **Revamp MySQL (Port 3306)**

**Tables:**
- `revamp_users` (user_name, email_address, display_name, mobile, account_status)
- `revamp_posts` (author_id, title, content, status, views)
- `revamp_likes` (user_id, post_id, reaction_type)

**Location:** `databases/mysql-revamp/schema.sql`

### **Schema Transformation**

| Legacy Column | Revamp Column |
|---------------|---------------|
| `username` | `user_name` |
| `email` | `email_address` |
| `full_name` | `display_name` |
| `phone_number` | `mobile` |
| `status` | `account_status` |
| `user_id` | `author_id` |
| `post_title` | `title` |
| `post_content` | `content` |
| `post_status` | `status` |
| `view_count` | `views` |
| `like_type` | `reaction_type` |

---

## 🔄 How It Works

### **Data Flow: Legacy → Revamp**

```
1. User inserts record in Legacy MySQL
   ↓
2. Debezium captures INSERT via binlog
   ↓
3. Event published to Kafka topic: legacy.legacy_db.legacy_users
   ↓
4. Laravel LegacyEventConsumer receives event
   ↓
5. RevampToLegacyMapper transforms schema
   ↓
6. IdempotentRevampWriter writes to Revamp MySQL
   ↓
7. Record has source='sync_service' (prevents loop)
```

### **Loop Prevention**

Every record has a `source` column with 3 possible values:
- `legacy` - Originated in Legacy database
- `revamp` - Originated in Revamp database
- `sync_service` - Synced by Laravel (DO NOT RE-SYNC)

**Consumer Logic:**
```php
if ($event->source === 'sync_service') {
    Log::info('Skipping sync_service record to prevent loop');
    return; // Skip!
}
```

---

## 🧪 Testing

### **Manual Testing**

**Test Legacy → Revamp:**
```powershell
docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "INSERT INTO legacy_users (username, email, full_name, source) VALUES ('test1', 'test1@example.com', 'Test One', 'legacy');"

# Wait 5 seconds
Start-Sleep -Seconds 5

# Verify sync
docker exec sync-mysql-revamp mysql -uroot -proot revamp_db -e "SELECT * FROM revamp_users WHERE user_name = 'test1';"
```

**Test Revamp → Legacy:**
```powershell
docker exec sync-mysql-revamp mysql -uroot -proot revamp_db -e "INSERT INTO revamp_users (user_name, email_address, display_name, source) VALUES ('test2', 'test2@example.com', 'Test Two', 'revamp');"

# Wait 5 seconds
Start-Sleep -Seconds 5

# Verify sync
docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "SELECT * FROM legacy_users WHERE username = 'test2';"
```

### **Automated Testing**
```powershell
.\test-dual-mysql-sync.bat
```

Runs 4 tests:
1. ✅ Legacy → Revamp sync
2. ✅ Revamp → Legacy sync
3. ✅ Loop prevention (source='sync_service' not synced)
4. ✅ View recent records

---

## 🔍 Monitoring

### **Kafka UI**
Open browser: http://localhost:8080

View:
- **Topics** - Message counts and contents
- **Consumers** - Consumer lag and offset positions
- **Connectors** - Debezium connector status

### **Check Connector Status**
```powershell
curl.exe http://localhost:8083/connectors/
curl.exe http://localhost:8083/connectors/legacy-mysql-connector/status
curl.exe http://localhost:8083/connectors/revamp-mysql-connector/status
```

### **View Laravel Logs**
```powershell
Get-Content sync-service/storage/logs/laravel.log -Tail 50 -Wait
```

### **Check Database Records**
```powershell
# Legacy database
docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "SELECT id, username, source FROM legacy_users ORDER BY id DESC LIMIT 10;"

# Revamp database
docker exec sync-mysql-revamp mysql -uroot -proot revamp_db -e "SELECT id, user_name, source FROM revamp_users ORDER BY id DESC LIMIT 10;"
```

---

## 🛠️ Troubleshooting

### **Duplicate Key Errors**

**Problem:** `Duplicate entry '10' for key 'PRIMARY'`

**Solution:** AUTO_INCREMENT triggers are already in place. If you still encounter issues:

```powershell
# Check current AUTO_INCREMENT values
docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA='legacy_db';"
```

### **Connector Not Running**

```powershell
# Re-register connector
curl.exe -X DELETE http://localhost:8083/connectors/legacy-mysql-connector
curl.exe -X POST http://localhost:8083/connectors -H "Content-Type: application/json" -d "@debezium/legacy-mysql-connector.json"
```

### **Consumer Not Processing**

```powershell
# Check if Kafka has messages
docker exec sync-kafka kafka-console-consumer --bootstrap-server localhost:9092 --topic legacy.legacy_db.legacy_users --from-beginning --max-messages 1

# Reset consumer offsets (stop consumers first!)
docker exec sync-kafka kafka-consumer-groups --bootstrap-server localhost:9092 --group sync-service-legacy --reset-offsets --to-earliest --all-topics --execute
```

---

## 📁 Project Structure

```
sync-DB/
├── databases/
│   ├── mysql-legacy/
│   │   ├── schema.sql        # Legacy MySQL schema
│   │   └── my.cnf            # MySQL config
│   └── mysql-revamp/
│       ├── schema.sql        # Revamp MySQL schema
│       └── my.cnf            # MySQL config
├── debezium/
│   ├── legacy-mysql-connector.json
│   └── mysql-connector.json
├── sync-service/
│   ├── app/
│   │   ├── DTOs/
│   │   │   ├── SyncEvent.php
│   │   │   └── UserDTO.php
│   │   ├── Services/
│   │   │   ├── Transformers/
│   │   │   │   ├── LegacyToRevampMapper.php
│   │   │   │   └── RevampToLegacyMapper.php
│   │   │   ├── Writers/
│   │   │   │   ├── IdempotentLegacyWriter.php
│   │   │   │   └── IdempotentRevampWriter.php
│   │   │   └── Consumers/
│   │   │       ├── LegacyEventConsumer.php
│   │   │       └── RevampEventConsumer.php
│   │   └── Console/Commands/
│   │       ├── ConsumeLegacyEvents.php
│   │       └── ConsumeRevampEvents.php
│   └── .env
├── docker-compose.yml
├── start-dual-mysql.bat       # Start all infrastructure
├── test-dual-mysql-sync.bat   # Run sync tests
├── README.md                  # This file
└── DUAL-MYSQL-SETUP.md        # Detailed setup guide
```

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| **README.md** | Quick start and overview (this file) |
| **DUAL-MYSQL-SETUP.md** | Comprehensive setup guide |
| **DATABASE-TRIGGER-SOLUTION.md** | AUTO_INCREMENT auto-fix documentation |
| **TECHNICAL-DOCUMENTATION.html** | Developer technical reference |

---

## ✅ Success Criteria

Your setup is working if:

1. ✅ Both MySQL containers are running
2. ✅ Both Debezium connectors show `RUNNING` status
3. ✅ Laravel consumers process events
4. ✅ Data inserted in Legacy appears in Revamp
5. ✅ Data inserted in Revamp appears in Legacy
6. ✅ Records with `source='sync_service'` are NOT re-synced
7. ✅ No duplicate key errors occur
8. ✅ Schema transformations work correctly

---

## 🎉 Features Implemented

### **Core Functionality:**
- ✅ Two-way database synchronization
- ✅ Change Data Capture (CDC) using Debezium
- ✅ Event streaming via Kafka
- ✅ Schema transformation layer
- ✅ Loop prevention mechanism
- ✅ Idempotent writes

### **Reliability:**
- ✅ Automatic retry with exponential backoff
- ✅ Dead Letter Queue for failed messages
- ✅ Event deduplication
- ✅ Graceful shutdown handling

### **Data Integrity:**
- ✅ Database triggers for AUTO_INCREMENT auto-fix
- ✅ Transaction-based writes
- ✅ Entity mapping table
- ✅ Processed events tracking

### **Observability:**
- ✅ Structured logging
- ✅ Kafka UI for monitoring
- ✅ Consumer lag tracking
- ✅ Connector health checks

---

## 🔧 Configuration

### **Environment Variables**

**File:** `sync-service/.env`

```env
# Legacy Database
DB_LEGACY_CONNECTION=mysql
DB_LEGACY_HOST=127.0.0.1
DB_LEGACY_PORT=3307
DB_LEGACY_DATABASE=legacy_db

# Revamp Database
DB_REVAMP_CONNECTION=mysql
DB_REVAMP_HOST=127.0.0.1
DB_REVAMP_PORT=3306
DB_REVAMP_DATABASE=revamp_db

# Kafka
KAFKA_BROKERS=localhost:29092
KAFKA_LEGACY_TOPIC=legacy.legacy_db.legacy_users,legacy.legacy_db.legacy_posts,legacy.legacy_db.legacy_likes
KAFKA_REVAMP_TOPIC=revamp.revamp_db.revamp_users,revamp.revamp_db.revamp_posts,revamp.revamp_db.revamp_likes
```

---

## 📞 Support

For issues or questions:
1. Check the logs: `sync-service/storage/logs/laravel.log`
2. Review Kafka UI: http://localhost:8080
3. Check connector status: `curl http://localhost:8083/connectors/`
4. Consult `DUAL-MYSQL-SETUP.md` for detailed troubleshooting

---

## 📝 License

This is a Proof of Concept (POC) project. Use at your own discretion.

---

## 🚀 Next Steps

1. **Test with production-like data volumes**
2. **Add more entities** (tables) as needed
3. **Implement additional transformations**
4. **Set up alerting** for consumer lag
5. **Configure retention policies** for Kafka topics
6. **Implement schema evolution** handling
7. **Add integration tests**
8. **Deploy to staging/production**

---

**Built with:**
- 🐘 PHP 8.1+ & Laravel 10
- 🐬 MySQL 8.0
- 🎯 Apache Kafka 7.5
- 🔄 Debezium 2.5
- 🐳 Docker & Docker Compose

---

**Happy Syncing!** 🎉

# Testing/Running
## Run docker 

docker compose up -d


## Connect connectors

curl.exe http://localhost:8083/connectors/

curl.exe -X POST http://localhost:8083/connectors -H "Content-Type: application/json" -d "@debezium/legacy-mysql-connector.json"

curl.exe -X POST http://localhost:8083/connectors -H "Content-Type: application/json" -d "@debezium/mysql-connector.json"

curl.exe http://localhost:8083/connectors/legacy-mysql-connector/status

curl.exe http://localhost:8083/connectors/revamp-mysql-connector/status


## Run events

start cmd /k "php artisan consume:legacy-events"

start cmd /k "php artisan consume:revamp-events"


## legacy to revamp

docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "INSERT INTO legacy_users (username, email, full_name, phone_number, source) VALUES ('live_test1', 'live1@example.com', 'Live Test User1', '+1234567890', 'legacy');"

docker exec sync-mysql-revamp mysql -uroot -proot revamp_db -e "SELECT id, user_name, email_address, display_name, source FROM revamp_users WHERE user_name = 'live_test1';"


## revamp to legacy

docker exec sync-mysql-revamp mysql -uroot -proot revamp_db -e "INSERT INTO revamp_users (user_name, email_address, display_name, mobile, source) VALUES ('new_test_4', 'new4@example.com', 'New Test 4', '+9876543210', 'revamp');"

docker exec sync-mysql-legacy mysql -uroot -proot legacy_db -e "SELECT id, username, email, full_name, source FROM legacy_users WHERE username = 'new_test_4';"

