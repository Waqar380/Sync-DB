# 🚀 Step-by-Step Execution Guide

## Prerequisites Check

### ✅ What You Have
- ✅ Docker installed
- ✅ Project files in `c:\xampp\htdocs\sync-DB`

### 📦 What You Need to Install

#### 1. **PHP 8.2 or higher** (for Laravel Sync Service)

**Check if you have PHP**:
```bash
php -v
```

**For Windows (XAMPP)**:
You likely already have PHP with XAMPP. Check:
```bash
C:\xampp\php\php.exe -v
```

If not installed, download from: https://www.php.net/downloads

#### 2. **Composer** (PHP Dependency Manager)

**Check if you have Composer**:
```bash
composer --version
```

**Install Composer** (if not installed):
- Download from: https://getcomposer.org/download/
- For Windows: Use the Windows installer
- Follow installation wizard

#### 3. **rdkafka PHP Extension** (for Kafka communication)

This is the **most important** additional requirement.

**For Windows (XAMPP)**:

1. **Download php_rdkafka.dll**:
   - Go to: https://pecl.php.net/package/rdkafka
   - Download the DLL for your PHP version (8.2, Thread Safe)
   - Or direct link: https://windows.php.net/downloads/pecl/releases/rdkafka/

2. **Install the extension**:
   ```bash
   # Copy the DLL to PHP extensions folder
   copy php_rdkafka.dll C:\xampp\php\ext\
   
   # Also need librdkafka.dll (comes with the package)
   copy librdkafka.dll C:\xampp\php\
   ```

3. **Enable in php.ini**:
   ```bash
   # Open php.ini
   notepad C:\xampp\php\php.ini
   
   # Add this line (under extensions section):
   extension=rdkafka
   
   # Save and close
   ```

4. **Verify installation**:
   ```bash
   php -m | findstr rdkafka
   # Should output: rdkafka
   ```

**Alternative for Windows**: Use pre-compiled binaries from:
- https://github.com/arnaud-lb/php-rdkafka/releases

---

## 🎯 Execution Steps

### Step 1: Verify Prerequisites (2 minutes)

```bash
# Check Docker
docker --version
# Expected: Docker version 20.x or higher

# Check Docker Compose
docker-compose --version
# Expected: Docker Compose version v2.x or higher

# Check PHP
php -v
# Expected: PHP 8.2.x or higher

# Check Composer
composer --version
# Expected: Composer version 2.x

# Check rdkafka extension
php -m | findstr rdkafka
# Expected: rdkafka
```

If any of these fail, refer to "What You Need to Install" section above.

---

### Step 2: Start Infrastructure with Docker (3 minutes)

```bash
# Navigate to project directory
cd c:\xampp\htdocs\sync-DB

# Start all Docker services
docker-compose up -d

# Expected output:
# Creating network "sync-db_sync-network" ... done
# Creating sync-zookeeper ... done
# Creating sync-postgres  ... done
# Creating sync-mysql     ... done
# Creating sync-kafka     ... done
# Creating sync-connect   ... done
# Creating sync-kafka-ui  ... done
```

**Wait 30-60 seconds** for services to fully initialize.

**Verify all services are running**:
```bash
docker-compose ps

# Expected output (all should show "Up"):
# NAME              STATUS
# sync-postgres     Up (healthy)
# sync-mysql        Up (healthy)
# sync-zookeeper    Up (healthy)
# sync-kafka        Up (healthy)
# sync-connect      Up (healthy)
# sync-kafka-ui     Up
```

---

### Step 3: Verify Databases are Initialized (1 minute)

**Check PostgreSQL**:
```bash
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "\dt"

# Expected: Should list tables:
# legacy_users
# legacy_posts
# legacy_likes
```

**Check MySQL**:
```bash
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "SHOW TABLES;"

# Expected: Should list tables:
# revamp_users
# revamp_posts
# revamp_likes
```

---

### Step 4: Register Debezium Connectors (2 minutes)

```bash
# Navigate to debezium folder
cd debezium

# Wait for Kafka Connect to be ready
# Check status:
curl http://localhost:8083/
# Should return JSON with version info

# Register connectors (Windows - using Git Bash or WSL):
bash register-connectors.sh

# OR manually (if bash not available):
# Register PostgreSQL connector
curl -X POST -H "Content-Type: application/json" --data @postgres-connector.json http://localhost:8083/connectors

# Register MySQL connector
curl -X POST -H "Content-Type: application/json" --data @mysql-connector.json http://localhost:8083/connectors
```

**Verify connectors**:
```bash
curl http://localhost:8083/connectors/

# Expected output:
# ["legacy-postgres-connector","revamp-mysql-connector"]
```

**Check connector status**:
```bash
curl http://localhost:8083/connectors/legacy-postgres-connector/status

# Expected: "state": "RUNNING"
```

---

### Step 5: Install Laravel Sync Service Dependencies (3 minutes)

```bash
# Navigate to sync-service folder
cd ..\sync-service

# Install PHP dependencies
composer install

# This will download all required packages
# Expected: Installing dependencies from lock file
```

**If you encounter issues**:
```bash
# Update composer (if needed)
composer self-update

# Clear cache and retry
composer clear-cache
composer install
```

---

### Step 6: Configure Laravel Environment (1 minute)

```bash
# Copy environment file
copy env.example .env

# Generate application key
php artisan key:generate

# Output: Application key set successfully.
```

**Edit .env file** (if needed):
```bash
# Open .env in notepad
notepad .env

# Verify these settings (should work with defaults):
# DB_LEGACY_HOST=127.0.0.1
# DB_REVAMP_HOST=127.0.0.1
# KAFKA_BROKERS=localhost:29092
```

---

### Step 7: Run Database Migrations (1 minute)

```bash
# Create entity_mappings and processed_events tables
php artisan migrate --database=revamp

# Expected output:
# Migration table created successfully.
# Migrating: 2026_01_08_000001_create_entity_mappings_table
# Migrated:  2026_01_08_000001_create_entity_mappings_table
# Migrating: 2026_01_08_000002_create_processed_events_table
# Migrated:  2026_01_08_000002_create_processed_events_table
```

---

### Step 8: Start Sync Service Consumers (2 terminals required)

You need to open **TWO separate terminal windows/tabs**:

**Terminal 1 - Legacy Consumer**:
```bash
cd c:\xampp\htdocs\sync-DB\sync-service

php artisan consume:legacy-events

# Expected output:
# Starting Legacy Event Consumer...
# Consuming events from Legacy (PostgreSQL) → Syncing to Revamped (MySQL)
# Press Ctrl+C to stop gracefully
# 
# ╔════════════════════════════════════╗
# ║ Configuration Table                ║
# ╚════════════════════════════════════╝
```

**Terminal 2 - Revamp Consumer**:
```bash
cd c:\xampp\htdocs\sync-DB\sync-service

php artisan consume:revamp-events

# Expected output:
# Starting Revamp Event Consumer...
# Consuming events from Revamped (MySQL) → Syncing to Legacy (PostgreSQL)
# Press Ctrl+C to stop gracefully
```

✅ **Both consumers should now be running!**

---

### Step 9: Test the Synchronization (5 minutes)

Open a **third terminal** for testing:

#### Test 1: Legacy → Revamp Sync

```bash
# Insert a record in PostgreSQL (Legacy)
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
INSERT INTO legacy_users (username, email, full_name, phone_number, source)
VALUES ('testuser1', 'test1@example.com', 'Test User One', '+1234567890', 'legacy');
"

# Wait 5 seconds
timeout /t 5

# Verify it appears in MySQL (Revamp)
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT id, user_name, email_address, display_name, source 
FROM revamp_users 
WHERE user_name = 'testuser1';
"

# Expected result:
# Should show the record with source='sync_service'
```

**Check consumer logs** in Terminal 1:
- You should see: "Processing event...", "Event processed successfully"

#### Test 2: Revamp → Legacy Sync

```bash
# Insert a record in MySQL (Revamp)
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
INSERT INTO revamp_users (user_name, email_address, display_name, mobile, source)
VALUES ('testuser2', 'test2@example.com', 'Test User Two', '+9876543210', 'revamp');
"

# Wait 5 seconds
timeout /t 5

# Verify it appears in PostgreSQL (Legacy)
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
SELECT id, username, email, full_name, source 
FROM legacy_users 
WHERE username = 'testuser2';
"

# Expected result:
# Should show the record with source='sync_service'
```

**Check consumer logs** in Terminal 2:
- You should see: "Processing event...", "Event processed successfully"

#### Test 3: Loop Prevention

```bash
# Insert with source='sync_service' (should NOT sync)
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('looptest', 'loop@test.com', 'Loop Test', 'sync_service');
"

# Wait 5 seconds
timeout /t 5

# Verify it does NOT appear in MySQL
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT COUNT(*) as count FROM revamp_users WHERE user_name = 'looptest';
"

# Expected result: count = 0 (record should NOT exist)
# This proves loop prevention is working!
```

---

### Step 10: Run Automated Tests (Optional but Recommended)

If you have Git Bash or WSL:

```bash
cd ..\testing\scripts

# Make scripts executable
chmod +x *.sh

# Run tests
./test-legacy-to-revamp.sh
./test-revamp-to-legacy.sh
./test-loop-prevention.sh

# Each should output: "Test: PASSED ✓"
```

---

## 🎉 Success Indicators

### ✅ System is Working If:

1. **All Docker containers are running**:
   ```bash
   docker-compose ps
   # All show "Up" status
   ```

2. **Both consumers are running** (in their terminals):
   - No error messages
   - Showing "Waiting for messages..." or similar

3. **Data syncs between databases**:
   - Insert in Legacy → appears in Revamp
   - Insert in Revamp → appears in Legacy
   - Records have correct `source` values

4. **Loop prevention works**:
   - Records with source='sync_service' don't create new events

5. **Consumer logs show processing**:
   - "Processing event..."
   - "Event processed successfully"
   - No error messages

---

## 🌐 Access Points

Once everything is running:

- **Kafka UI**: http://localhost:8080
  - View topics, messages, consumer groups
  
- **Debezium Connect**: http://localhost:8083
  - Check connector status

- **PostgreSQL**: localhost:5432
  - Database: legacy_db
  - User: postgres
  - Password: postgres

- **MySQL**: localhost:3306
  - Database: revamp_db
  - User: root
  - Password: root

---

## 🐛 Troubleshooting

### Issue: "rdkafka extension not found"

```bash
# Verify PHP can see the extension
php -m | findstr rdkafka

# If not found:
# 1. Check php.ini has: extension=rdkafka
# 2. Check php_rdkafka.dll is in C:\xampp\php\ext\
# 3. Check librdkafka.dll is in C:\xampp\php\
# 4. Restart terminal/command prompt
```

### Issue: "Port already in use"

```bash
# Check what's using the ports
netstat -ano | findstr ":5432"  # PostgreSQL
netstat -ano | findstr ":3306"  # MySQL
netstat -ano | findstr ":29092" # Kafka

# Stop conflicting services or change ports in docker-compose.yml
```

### Issue: "Debezium connector failed"

```bash
# Check connector logs
docker logs sync-connect

# Restart connector
curl -X POST http://localhost:8083/connectors/legacy-postgres-connector/restart
```

### Issue: "Consumer not receiving events"

```bash
# Check Kafka topics exist
docker exec -it sync-kafka kafka-topics --bootstrap-server localhost:9092 --list

# Should show:
# legacy.public.legacy_users
# revamp.revamp_db.revamp_users
# etc.

# Check if events are in Kafka
docker exec -it sync-kafka kafka-console-consumer \
  --bootstrap-server localhost:9092 \
  --topic legacy.public.legacy_users \
  --from-beginning \
  --max-messages 1
```

---

## 🛑 Stopping the System

```bash
# Stop consumers: Press Ctrl+C in each terminal

# Stop Docker services
cd c:\xampp\htdocs\sync-DB
docker-compose down

# To also remove data volumes (fresh start)
docker-compose down -v
```

---

## 📊 Monitoring Commands

```bash
# View consumer logs in real-time
# (In the consumer terminals, they show live)

# Check consumer lag
docker exec -it sync-kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --describe --group sync-service-legacy

# View database records
# PostgreSQL:
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
SELECT source, COUNT(*) FROM legacy_users GROUP BY source;
"

# MySQL:
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT source, COUNT(*) FROM revamp_users GROUP BY source;
"

# Check processed events
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT entity_type, operation, source, COUNT(*) as count 
FROM processed_events 
GROUP BY entity_type, operation, source;
"
```

---

## 📝 Quick Command Reference

```bash
# Start everything
docker-compose up -d
cd debezium && bash register-connectors.sh
cd ../sync-service && composer install && php artisan key:generate
php artisan migrate --database=revamp
php artisan consume:legacy-events   # Terminal 1
php artisan consume:revamp-events   # Terminal 2

# Test insert
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('quicktest', 'quick@test.com', 'Quick Test', 'legacy');"

docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT * FROM revamp_users WHERE user_name = 'quicktest';"

# Stop everything
# Ctrl+C in consumer terminals
docker-compose down
```

---

## 🎓 Next Steps

1. **Experiment**: Try inserting posts, likes, updates, deletes
2. **Monitor**: Watch the consumer logs to see events flowing
3. **Explore**: Open Kafka UI (http://localhost:8080) to see messages
4. **Read**: Check ARCHITECTURE.md to understand how it works
5. **Modify**: Try adding a new entity type

---

## 💡 Tips

- Keep consumer terminals visible to monitor activity
- Use Kafka UI for visual monitoring
- Check QUICK-REFERENCE.md for more commands
- See test-scenarios.md for comprehensive test cases

---

**You're ready to go! 🚀**

Follow these steps in order, and your two-way sync system will be running!


