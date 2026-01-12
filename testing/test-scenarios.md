# Test Scenarios for Two-Way DB Sync POC

## Overview

This document describes comprehensive test scenarios to validate the two-way database synchronization system with loop prevention.

## Prerequisites

Before running tests, ensure:
- [x] Docker containers are running
- [x] Debezium connectors are registered
- [x] Laravel sync service consumers are running
- [x] Both databases are initialized with seed data

## Test Categories

### 1. Basic Synchronization Tests
### 2. Loop Prevention Tests
### 3. Idempotency Tests
### 4. Schema Transformation Tests
### 5. Error Handling Tests
### 6. Concurrent Operations Tests

---

## Test Category 1: Basic Synchronization

### Test 1.1: Legacy → Revamp (User Create)

**Objective**: Verify that a new user created in Legacy DB appears in Revamp DB

**Steps**:
```sql
-- Execute in PostgreSQL (Legacy DB)
INSERT INTO legacy_users (username, email, full_name, phone_number, source)
VALUES ('test_user_1', 'test1@example.com', 'Test User One', '+1234567890', 'legacy');
```

**Expected Results**:
1. Debezium captures the change
2. Event published to `legacy.public.legacy_users` Kafka topic
3. Laravel Sync Service consumes the event
4. Transformer converts Legacy → Revamp schema
5. Record inserted into MySQL with `source='sync_service'`:
```sql
-- Verify in MySQL (Revamp DB)
SELECT * FROM revamp_users WHERE user_name = 'test_user_1';
-- Should return: user_name='test_user_1', email_address='test1@example.com', source='sync_service'
```
6. No event published from MySQL (loop prevented)

**Validation Queries**:
```sql
-- Check mapping table
SELECT * FROM entity_mappings WHERE entity_type = 'users' AND legacy_id = (SELECT id FROM legacy_users WHERE username = 'test_user_1');

-- Check processed events
SELECT * FROM processed_events WHERE entity_type = 'users' ORDER BY processed_at DESC LIMIT 1;
```

---

### Test 1.2: Revamp → Legacy (User Create)

**Objective**: Verify that a new user created in Revamp DB appears in Legacy DB

**Steps**:
```sql
-- Execute in MySQL (Revamp DB)
INSERT INTO revamp_users (user_name, email_address, display_name, mobile, source)
VALUES ('test_user_2', 'test2@example.com', 'Test User Two', '+9876543210', 'revamp');
```

**Expected Results**:
1. Debezium captures the change
2. Event published to `revamp.revamp_db.revamp_users` Kafka topic
3. Laravel Sync Service consumes the event
4. Transformer converts Revamp → Legacy schema
5. Record inserted into PostgreSQL with `source='sync_service'`:
```sql
-- Verify in PostgreSQL (Legacy DB)
SELECT * FROM legacy_users WHERE username = 'test_user_2';
-- Should return: username='test_user_2', email='test2@example.com', source='sync_service'
```
6. No event published from PostgreSQL (loop prevented)

---

### Test 1.3: Legacy → Revamp (Post Create with Foreign Key)

**Objective**: Verify post synchronization with foreign key mapping

**Steps**:
```sql
-- Execute in PostgreSQL (Legacy DB)
INSERT INTO legacy_posts (user_id, post_title, post_content, source)
VALUES (1, 'Test Post from Legacy', 'This is a test post content.', 'legacy');
```

**Expected Results**:
1. Post synced to Revamp DB
2. Foreign key `author_id` correctly mapped
3. Verification:
```sql
-- MySQL (Revamp DB)
SELECT * FROM revamp_posts WHERE title = 'Test Post from Legacy';
-- Should have correct author_id and source='sync_service'
```

---

### Test 1.4: Update Synchronization

**Objective**: Verify that updates propagate correctly

**Steps**:
```sql
-- Execute in PostgreSQL (Legacy DB)
UPDATE legacy_users
SET full_name = 'Updated Name', source = 'legacy'
WHERE username = 'john_doe';
```

**Expected Results**:
1. Update captured by Debezium
2. Synced to Revamp DB:
```sql
-- MySQL (Revamp DB)
SELECT * FROM revamp_users WHERE user_name = 'john_doe';
-- Should show: display_name='Updated Name', source='sync_service'
```

---

## Test Category 2: Loop Prevention

### Test 2.1: Sync Service Insert Should NOT Loop

**Objective**: Verify that records with `source='sync_service'` are NOT re-synced

**Steps**:
```sql
-- Execute in PostgreSQL (Legacy DB)
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('loop_test_1', 'loop1@example.com', 'Loop Test', 'sync_service');
```

**Expected Results**:
1. Record inserted successfully
2. NO event published to Kafka (filtered by Debezium)
3. Record stays ONLY in Legacy DB:
```sql
-- MySQL (Revamp DB) - Should NOT exist
SELECT * FROM revamp_users WHERE user_name = 'loop_test_1';
-- Should return: 0 rows
```

**Validation**:
- Check Kafka topic: `kafka-console-consumer --topic legacy.public.legacy_users`
- Should NOT see the event with `source='sync_service'`

---

### Test 2.2: Bidirectional Update Loop Prevention

**Objective**: Ensure no infinite loop when syncing updates

**Scenario**:
1. Update user in Legacy (source='legacy')
2. Sync to Revamp (source='sync_service')
3. Verify NO event from Revamp back to Legacy

**Steps**:
```sql
-- PostgreSQL (Legacy DB)
UPDATE legacy_users SET full_name = 'No Loop Test', source = 'legacy' WHERE id = 1;
```

**Monitor**:
```bash
# Watch Kafka topics
kafka-console-consumer --bootstrap-server localhost:29092 --topic legacy.public.legacy_users
kafka-console-consumer --bootstrap-server localhost:29092 --topic revamp.revamp_db.revamp_users
```

**Expected**:
- See event on `legacy.public.legacy_users`
- Should NOT see corresponding event on `revamp.revamp_db.revamp_users`

---

## Test Category 3: Idempotency

### Test 3.1: Duplicate Event Handling

**Objective**: Verify that processing the same event twice has no adverse effects

**Steps**:
1. Create a user in Legacy
2. Wait for sync to complete
3. Manually replay the Kafka message
4. Verify no duplicate in Revamp DB

**Manual Replay**:
```bash
# Get the last message
kafka-console-consumer --bootstrap-server localhost:29092 \
  --topic legacy.public.legacy_users --from-beginning --max-messages 1

# Produce it again (simulating duplicate)
# The consumer should skip it via event_id deduplication
```

**Validation**:
```sql
-- Should have only ONE record
SELECT COUNT(*) FROM revamp_users WHERE user_name = 'test_user_1';
-- Expected: 1
```

---

### Test 3.2: Concurrent Updates

**Objective**: Verify UPSERT behavior with concurrent updates

**Steps**:
```sql
-- Execute simultaneously in different sessions
-- Session 1 (Legacy)
UPDATE legacy_users SET full_name = 'Concurrent Test 1', source = 'legacy' WHERE id = 1;

-- Session 2 (Legacy) - immediately after
UPDATE legacy_users SET phone_number = '+0000000000', source = 'legacy' WHERE id = 1;
```

**Expected**:
- Both updates eventually sync to Revamp
- Final state in Revamp reflects the latest update
- No constraint violations

---

## Test Category 4: Schema Transformation

### Test 4.1: Field Name Mapping

**Objective**: Verify correct field name transformation

**Mapping Verification**:

| Legacy (Postgres) | Revamp (MySQL) |
|-------------------|----------------|
| username          | user_name      |
| email             | email_address  |
| full_name         | display_name   |
| phone_number      | mobile         |
| status            | account_status |

**Test**:
```sql
-- PostgreSQL
INSERT INTO legacy_users (username, email, full_name, phone_number, status, source)
VALUES ('mapping_test', 'map@test.com', 'Mapping Test', '+1111111111', 'active', 'legacy');
```

**Verify**:
```sql
-- MySQL
SELECT user_name, email_address, display_name, mobile, account_status
FROM revamp_users WHERE user_name = 'mapping_test';
-- All fields should be correctly mapped
```

---

### Test 4.2: Case Sensitivity Transformation

**Objective**: Verify case transformations (lowercase ↔ Title Case)

**Test**:
```sql
-- MySQL - status values are Title Case
INSERT INTO revamp_posts (author_id, title, content, status, source)
VALUES (1, 'Case Test', 'Testing case', 'Published', 'revamp');
```

**Verify**:
```sql
-- PostgreSQL - status values are lowercase
SELECT post_status FROM legacy_posts WHERE post_title = 'Case Test';
-- Expected: 'published' (lowercase)
```

---

## Test Category 5: Error Handling

### Test 5.1: Foreign Key Violation

**Objective**: Verify error handling when foreign key doesn't exist

**Test**:
```sql
-- PostgreSQL - Insert post with non-existent user_id
INSERT INTO legacy_posts (user_id, post_title, post_content, source)
VALUES (99999, 'Orphan Post', 'This should fail', 'legacy');
```

**Expected**:
1. Sync service attempts to sync
2. Foreign key constraint violation in Revamp DB
3. Event sent to DLQ after retries
4. Check DLQ:
```bash
kafka-console-consumer --bootstrap-server localhost:29092 --topic sync.dlq
```

---

### Test 5.2: Retry on Transient Failure

**Objective**: Verify retry mechanism works

**Simulation**:
1. Stop MySQL container temporarily
2. Insert record in Legacy
3. Wait for retry attempts
4. Start MySQL container
5. Verify sync completes

**Steps**:
```bash
# Stop MySQL
docker stop sync-mysql

# Insert in Legacy
psql -U postgres -d legacy_db -c "INSERT INTO legacy_users (username, email, source) VALUES ('retry_test', 'retry@test.com', 'legacy');"

# Wait ~10 seconds (observe retry logs)

# Start MySQL
docker start sync-mysql

# Wait for sync to complete
# Verify
mysql -u root -proot revamp_db -e "SELECT * FROM revamp_users WHERE user_name = 'retry_test';"
```

---

## Test Category 6: Concurrent Operations

### Test 6.1: Simultaneous Bidirectional Creates

**Objective**: Create different records simultaneously in both DBs

**Steps**:
```bash
# Terminal 1 - PostgreSQL
psql -U postgres -d legacy_db -c "INSERT INTO legacy_users (username, email, source) VALUES ('concurrent_pg', 'pg@test.com', 'legacy');"

# Terminal 2 - MySQL (immediately)
mysql -u root -proot revamp_db -e "INSERT INTO revamp_users (user_name, email_address, source) VALUES ('concurrent_mysql', 'mysql@test.com', 'revamp');"
```

**Expected**:
- `concurrent_pg` appears in both databases
- `concurrent_mysql` appears in both databases
- No conflicts or deadlocks

---

## Test Category 7: Delete Operations

### Test 7.1: Delete Synchronization

**Objective**: Verify delete operations sync correctly

**Steps**:
```sql
-- PostgreSQL
DELETE FROM legacy_users WHERE username = 'test_user_1';
```

**Expected**:
1. Debezium captures delete event
2. Delete synced to Revamp:
```sql
-- MySQL - record should be deleted
SELECT * FROM revamp_users WHERE user_name = 'test_user_1';
-- Expected: 0 rows
```

---

## Performance Tests

### Test P.1: Bulk Insert Performance

**Objective**: Measure sync performance with bulk inserts

**Test**:
```sql
-- PostgreSQL - Insert 1000 users
DO $$
BEGIN
  FOR i IN 1..1000 LOOP
    INSERT INTO legacy_users (username, email, full_name, source)
    VALUES (
      'bulk_user_' || i,
      'bulk' || i || '@test.com',
      'Bulk User ' || i,
      'legacy'
    );
  END LOOP;
END $$;
```

**Measure**:
- Time to sync all 1000 records
- Check consumer lag
- Verify all records synced correctly

---

## Validation Scripts

### Check Sync Status
```sql
-- Count records by source in both DBs
-- PostgreSQL
SELECT source, COUNT(*) FROM legacy_users GROUP BY source;
SELECT source, COUNT(*) FROM legacy_posts GROUP BY source;

-- MySQL
SELECT source, COUNT(*) FROM revamp_users GROUP BY source;
SELECT source, COUNT(*) FROM revamp_posts GROUP BY source;
```

### Check Entity Mappings
```sql
-- MySQL
SELECT entity_type, COUNT(*) as mapping_count
FROM entity_mappings
GROUP BY entity_type;
```

### Check Processed Events
```sql
-- MySQL
SELECT
  entity_type,
  operation,
  source,
  COUNT(*) as count
FROM processed_events
GROUP BY entity_type, operation, source;
```

### Consumer Lag Check
```bash
kafka-consumer-groups --bootstrap-server localhost:29092 \
  --describe --group sync-service-legacy

kafka-consumer-groups --bootstrap-server localhost:29092 \
  --describe --group sync-service-revamp
```

---

## Success Criteria

The POC is considered successful if:

- [x] Test 1.1-1.4: All basic sync tests pass
- [x] Test 2.1-2.2: Loop prevention works correctly
- [x] Test 3.1-3.2: Idempotency is maintained
- [x] Test 4.1-4.2: Schema transformations are correct
- [x] Test 5.1-5.2: Errors are handled gracefully
- [x] Test 6.1: Concurrent operations work
- [x] Test 7.1: Delete operations sync
- [x] No infinite loops occur
- [x] Consumer lag remains low (<1000 messages)
- [x] DLQ contains only genuinely failed messages

---

## Troubleshooting

### Issue: Events not syncing
- Check Debezium connector status
- Verify Kafka topics exist
- Check consumer logs
- Verify source column value

### Issue: Duplicate records
- Check entity_mappings table
- Verify UPSERT logic
- Check processed_events for deduplication

### Issue: Foreign key errors
- Verify parent records exist
- Check entity_mappings for ID translation
- Review sync order

---

**Happy Testing! 🚀**


