# 🪟 Windows Quick Start Guide

## The Simplest Way to Run This Project on Windows

### 🎯 What You'll Do

1. Check prerequisites
2. Start Docker services (automated)
3. Setup Laravel service (automated)
4. Start two consumers (manual - 2 terminals)
5. Test synchronization (automated)

**Total Time: ~10 minutes**

---

## 📋 Step-by-Step Instructions

### Step 1: Check Prerequisites (1 minute)

Double-click or run:
```bash
CHECK-PREREQUISITES.bat
```

**What it checks**:
- ✅ Docker
- ✅ Docker Compose
- ✅ PHP 8.1+
- ✅ Composer
- ✅ rdkafka extension

**If anything is missing**:
- See the error messages for installation links
- Check [EXECUTION-GUIDE.md](EXECUTION-GUIDE.md) for detailed instructions
- Most common missing: **rdkafka extension**

#### Installing rdkafka (Most Important!)

If `CHECK-PREREQUISITES.bat` says rdkafka is missing:

1. **Download**:
   - Go to: https://pecl.php.net/package/rdkafka
   - Download Windows DLL for PHP 8.2 (Thread Safe)

2. **Install**:
   ```bash
   # Copy files
   copy php_rdkafka.dll C:\xampp\php\ext\
   copy librdkafka.dll C:\xampp\php\
   ```

3. **Enable**:
   ```bash
   # Edit php.ini
   notepad C:\xampp\php\php.ini
   
   # Add this line:
   extension=rdkafka
   
   # Save and close
   ```

4. **Verify**:
   ```bash
   # Open NEW terminal
   php -m | findstr rdkafka
   # Should show: rdkafka
   ```

---

### Step 2: Start Docker Infrastructure (3 minutes)

Double-click or run:
```bash
start.bat
```

**What it does**:
1. Starts 6 Docker containers (Postgres, MySQL, Kafka, etc.)
2. Waits for services to initialize
3. Registers Debezium connectors for CDC
4. Verifies everything is running

**Expected output**:
```
[1/6] Starting Docker services...
[2/6] Waiting 30 seconds for services to initialize...
[3/6] Checking service health...
[4/6] Waiting for Kafka Connect to be ready...
[5/6] Registering Debezium connectors...
[6/6] Verifying connectors...

Docker Infrastructure Started Successfully!
```

**If it fails**:
- Make sure Docker Desktop is running
- Check no other services are using ports 5432, 3306, 29092

---

### Step 3: Setup Laravel Sync Service (2 minutes)

Double-click or run:
```bash
setup-laravel.bat
```

**What it does**:
1. Checks PHP and Composer
2. Installs Laravel dependencies
3. Creates `.env` file
4. Generates application key
5. Runs database migrations

**Expected output**:
```
[1/5] Checking PHP...
[2/5] Checking Composer...
[3/5] Installing dependencies...
[4/5] Setting up environment...
[5/5] Running migrations...

Laravel Setup Complete!
```

---

### Step 4: Start Consumers (Manual - 2 Terminals)

You need **TWO separate terminal windows**:

#### Terminal 1: Legacy Consumer

```bash
cd c:\xampp\htdocs\sync-DB\sync-service
php artisan consume:legacy-events
```

**Expected**:
```
Starting Legacy Event Consumer...
Consuming events from Legacy (PostgreSQL) → Syncing to Revamped (MySQL)
Press Ctrl+C to stop gracefully

╔════════════════════════════════════╗
║ Configuration                      ║
╚════════════════════════════════════╝
```

**Leave this running!**

#### Terminal 2: Revamp Consumer

```bash
cd c:\xampp\htdocs\sync-DB\sync-service
php artisan consume:revamp-events
```

**Expected**:
```
Starting Revamp Event Consumer...
Consuming events from Revamped (MySQL) → Syncing to Legacy (PostgreSQL)
Press Ctrl+C to stop gracefully
```

**Leave this running too!**

---

### Step 5: Test Synchronization (2 minutes)

Open a **THIRD terminal** and run:
```bash
test-sync.bat
```

**What it tests**:
1. **Legacy → Revamp**: Insert in PostgreSQL → Check MySQL
2. **Revamp → Legacy**: Insert in MySQL → Check PostgreSQL
3. **Loop Prevention**: Insert with source='sync_service' → Should NOT sync

**Expected output**:
```
[Test 1] Legacy -> Revamp Sync
✓ Record should appear in MySQL with source='sync_service'

[Test 2] Revamp -> Legacy Sync
✓ Record should appear in PostgreSQL with source='sync_service'

[Test 3] Loop Prevention Test
✓ Count should be 0 (loop prevented)

Test Complete!
```

**Watch the consumer terminals** - you should see:
- "Processing event..."
- "Event processed successfully"

---

### Step 6: Stop Everything

When done testing, run:
```bash
stop.bat
```

Or press `Ctrl+C` in each consumer terminal, then run `stop.bat`.

---

## 🎉 Success Indicators

### ✅ Everything is Working If:

1. **`CHECK-PREREQUISITES.bat` shows**: "All prerequisites met! ✓"

2. **`start.bat` shows**: "Docker Infrastructure Started Successfully!"

3. **Both consumers are running** without errors

4. **`test-sync.bat` shows**:
   - Test 1: Record appears with source='sync_service'
   - Test 2: Record appears with source='sync_service'
   - Test 3: Count = 0 (no loop)

5. **Consumer logs show**: "Event processed successfully"

---

## 🎮 Manual Testing

Want to test manually? Open a terminal:

### Insert in Legacy (Postgres)
```bash
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('mytest', 'my@test.com', 'My Test', 'legacy');"
```

### Check in Revamp (MySQL) - Wait 5 seconds first!
```bash
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "
SELECT * FROM revamp_users WHERE user_name = 'mytest';"
```

**Should show** the record with `source='sync_service'` ✓

---

## 🌐 Access Web Interfaces

While system is running:

- **Kafka UI**: http://localhost:8080
  - View topics, messages, consumer lag
  - Username/Password: (none - open access)

- **Database Access**:
  - PostgreSQL: localhost:5432 (user: postgres, password: postgres)
  - MySQL: localhost:3306 (user: root, password: root)

---

## 🐛 Common Issues

### Issue: "rdkafka extension not found"

**Solution**:
1. Close all terminals
2. Install rdkafka (see Step 1 above)
3. Open NEW terminal
4. Run `CHECK-PREREQUISITES.bat` again

### Issue: "Port already in use"

**Solution**:
```bash
# Stop conflicting services
# Check what's using the port:
netstat -ano | findstr ":5432"
netstat -ano | findstr ":3306"

# Or change ports in docker-compose.yml
```

### Issue: "Docker not running"

**Solution**:
1. Open Docker Desktop
2. Wait for it to start fully
3. Run `start.bat` again

### Issue: "Composer install failed"

**Solution**:
```bash
cd sync-service
composer clear-cache
composer install --no-cache
```

### Issue: "Consumer shows errors"

**Check**:
1. Did `start.bat` complete successfully?
2. Are Debezium connectors registered?
   ```bash
   curl http://localhost:8083/connectors/
   # Should show: ["legacy-postgres-connector","revamp-mysql-connector"]
   ```
3. Check consumer logs for specific error

---

## 📁 File Organization

```
Your Directory
├── CHECK-PREREQUISITES.bat  ← Run first
├── start.bat                ← Start Docker
├── setup-laravel.bat        ← Setup Laravel
├── test-sync.bat            ← Test system
├── stop.bat                 ← Stop everything
├── EXECUTION-GUIDE.md       ← Detailed guide
└── sync-service/
    └── (Laravel application)
```

---

## 🎯 Quick Command Summary

```bash
# Complete setup
CHECK-PREREQUISITES.bat
start.bat
setup-laravel.bat

# Start consumers (2 terminals)
cd sync-service
php artisan consume:legacy-events    # Terminal 1
php artisan consume:revamp-events    # Terminal 2

# Test (Terminal 3)
test-sync.bat

# Stop
stop.bat
```

---

## 📚 Need More Help?

1. **Detailed Instructions**: [EXECUTION-GUIDE.md](EXECUTION-GUIDE.md)
2. **Architecture**: [ARCHITECTURE.md](ARCHITECTURE.md)
3. **Commands Reference**: [QUICK-REFERENCE.md](QUICK-REFERENCE.md)
4. **Test Scenarios**: [testing/test-scenarios.md](testing/test-scenarios.md)

---

## 💡 Tips

- Keep consumer terminals visible to see events flow
- Open Kafka UI (http://localhost:8080) to visualize
- Use `test-sync.bat` repeatedly - it's safe!
- Check [QUICK-REFERENCE.md](QUICK-REFERENCE.md) for more commands

---

**That's it! You're running a production-grade two-way DB sync system! 🚀**

If you see "Event processed successfully" in the consumer logs after inserting data, **congratulations - it's working!** ✅


