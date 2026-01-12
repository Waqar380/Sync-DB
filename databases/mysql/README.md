# MySQL (Revamped Platform) Database

## Overview

This directory contains the MySQL schema for the **Revamped Platform** in our two-way sync POC.

## Schema Design

### Naming Conventions
- Table names: `revamp_{entity}` (e.g., `revamp_users`)
- Columns: snake_case but DIFFERENT from legacy (e.g., `display_name` vs `full_name`)
- Primary keys: `id` (AUTO_INCREMENT)

### Entities

#### 1. revamp_users
User accounts in the revamped system.

| Column | Type | Description | Legacy Equivalent |
|--------|------|-------------|-------------------|
| id | INT | Primary key | id |
| user_name | VARCHAR(50) | Unique username | username |
| email_address | VARCHAR(100) | Unique email | email |
| display_name | VARCHAR(100) | User's display name | full_name |
| mobile | VARCHAR(20) | Contact number | phone_number |
| account_status | VARCHAR(20) | Active/Inactive/Suspended | status |
| **source** | VARCHAR(20) | **Loop prevention flag** | source |
| created_at | TIMESTAMP | Creation timestamp | created_at |
| updated_at | TIMESTAMP | Last update timestamp | updated_at |

#### 2. revamp_posts
User-generated posts.

| Column | Type | Description | Legacy Equivalent |
|--------|------|-------------|-------------------|
| id | INT | Primary key | id |
| author_id | INT | Foreign key to revamp_users | user_id |
| title | VARCHAR(200) | Post title | post_title |
| content | TEXT | Post body | post_content |
| status | VARCHAR(20) | Draft/Published/Archived | post_status |
| views | INT | Number of views | view_count |
| **source** | VARCHAR(20) | **Loop prevention flag** | source |
| created_at | TIMESTAMP | Creation timestamp | created_at |
| updated_at | TIMESTAMP | Last update timestamp | updated_at |

#### 3. revamp_likes
Post engagement (reactions).

| Column | Type | Description | Legacy Equivalent |
|--------|------|-------------|-------------------|
| id | INT | Primary key | id |
| user_id | INT | Foreign key to revamp_users | user_id |
| post_id | INT | Foreign key to revamp_posts | post_id |
| reaction_type | VARCHAR(20) | Like/Love/Wow/Sad/Angry | like_type |
| **source** | VARCHAR(20) | **Loop prevention flag** | source |
| created_at | TIMESTAMP | Creation timestamp | created_at |

## Schema Differences from Legacy

This demonstrates the real-world scenario where schemas differ between platforms:

### Users Table
```
Legacy (PostgreSQL)      →  Revamped (MySQL)
-------------------------    -------------------------
legacy_users                 revamp_users
  username                     user_name
  email                        email_address
  full_name                    display_name
  phone_number                 mobile
  status                       account_status
  source                       source ✓
```

### Posts Table
```
Legacy (PostgreSQL)      →  Revamped (MySQL)
-------------------------    -------------------------
legacy_posts                 revamp_posts
  user_id                      author_id
  post_title                   title
  post_content                 content
  post_status                  status
  view_count                   views
  source                       source ✓
```

### Likes Table
```
Legacy (PostgreSQL)      →  Revamped (MySQL)
-------------------------    -------------------------
legacy_likes                 revamp_likes
  like_type                    reaction_type
  source                       source ✓
```

### Loop Prevention Mechanism

Every table includes a `source` column with three possible values:

- **`legacy`**: Record synced from PostgreSQL
- **`revamp`**: Record created/updated directly in MySQL (by app/user)
- **`sync_service`**: Record written by Laravel Sync Service

**Critical Rule**: Records with `source = 'sync_service'` MUST NOT be published to Kafka by Debezium.

## Setup Instructions

### 1. Create Database
```bash
# Connect to MySQL
mysql -u root -p

# Create database
CREATE DATABASE revamp_db;

# Use the database
USE revamp_db;
```

### 2. Run Schema
```bash
mysql -u root -p revamp_db < schema.sql
```

### 3. Verify Setup
```sql
-- Check tables
SHOW TABLES;

-- Verify data
SELECT * FROM revamp_users;
SELECT * FROM revamp_posts;
SELECT * FROM revamp_likes;

-- Check source distribution
SELECT source, COUNT(*) FROM revamp_users GROUP BY source;
```

## Debezium Connector Configuration

To set up CDC on this database:

```json
{
  "name": "revamp-mysql-connector",
  "config": {
    "connector.class": "io.debezium.connector.mysql.MySqlConnector",
    "database.hostname": "mysql",
    "database.port": "3306",
    "database.user": "debezium",
    "database.password": "dbz",
    "database.server.id": "184054",
    "database.server.name": "revamp",
    "table.include.list": "revamp_db.revamp_users,revamp_db.revamp_posts,revamp_db.revamp_likes",
    "database.history.kafka.bootstrap.servers": "kafka:9092",
    "database.history.kafka.topic": "schema-changes.revamp",
    "transforms": "filter",
    "transforms.filter.type": "io.debezium.transforms.Filter",
    "transforms.filter.language": "jsr223.groovy",
    "transforms.filter.condition": "value.source != 'sync_service'"
  }
}
```

**Key Point**: The `transforms.filter.condition` ensures that only records with `source != 'sync_service'` are published to Kafka.

## Test Scenarios

### Test 1: Direct Insert (Should Sync)
```sql
INSERT INTO revamp_users (user_name, email_address, display_name, source)
VALUES ('test_revamp', 'test@revamp.com', 'Test Revamp', 'revamp');
```
**Expected**: Event published to Kafka → Synced to PostgreSQL

### Test 2: Sync Service Insert (Should NOT Sync)
```sql
INSERT INTO revamp_users (user_name, email_address, display_name, source)
VALUES ('synced_user', 'synced@revamp.com', 'Synced User', 'sync_service');
```
**Expected**: NO event published to Kafka → No loop created

### Test 3: Update Existing Record
```sql
UPDATE revamp_users
SET display_name = 'Alice Updated', source = 'revamp'
WHERE user_name = 'alice_wonder';
```
**Expected**: Event published to Kafka → Synced to PostgreSQL

## Monitoring Queries

### Check Source Distribution
```sql
SELECT source, COUNT(*) as count
FROM revamp_users
GROUP BY source
ORDER BY count DESC;
```

### Find Recently Synced Records
```sql
SELECT id, user_name, email_address, source, updated_at
FROM revamp_users
WHERE source = 'sync_service'
ORDER BY updated_at DESC
LIMIT 10;
```

### Find Records Pending Sync
```sql
SELECT id, user_name, email_address, source, created_at
FROM revamp_users
WHERE source = 'revamp'
  AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### Check Data Consistency
```sql
-- Count total users
SELECT COUNT(*) as total_users FROM revamp_users;

-- Count users by source
SELECT 
    source,
    COUNT(*) as count,
    MIN(created_at) as earliest,
    MAX(created_at) as latest
FROM revamp_users
GROUP BY source;
```

## Troubleshooting

### Issue: Changes not appearing in Kafka
**Check**:
1. Verify Debezium connector is running
2. Check source column value
3. Review MySQL binlog settings
4. Verify binlog format is ROW

### Issue: Binlog not enabled
```sql
-- Check binlog status
SHOW VARIABLES LIKE 'log_bin';

-- If disabled, enable in my.cnf:
-- [mysqld]
-- server-id=1
-- log_bin=mysql-bin
-- binlog_format=ROW
-- binlog_row_image=FULL
```

### Issue: Duplicate records
**Check**:
1. Verify unique constraints
2. Check mapping table in sync service
3. Review sync service logs

## MySQL Configuration for CDC

Required settings in `my.cnf`:

```ini
[mysqld]
# Server ID (must be unique)
server-id=1

# Binary logging
log_bin=mysql-bin
binlog_format=ROW
binlog_row_image=FULL
expire_logs_days=7

# GTID (optional but recommended)
gtid_mode=ON
enforce_gtid_consistency=ON
```

## Schema Evolution

When adding new columns:
1. Add column with DEFAULT value
2. Update sync service transformers
3. Deploy changes in order: DB → Transformer → Consumer
4. Version your event schemas

## Performance Optimization

### Indexes
All foreign keys and frequently queried columns are indexed:
- User lookups: `idx_user_name`, `idx_email_address`
- Post queries: `idx_author_id`, `idx_status`
- Like queries: `idx_user_id`, `idx_post_id`
- Sync filtering: `idx_source` on all tables

### Query Optimization
```sql
-- Use EXPLAIN to analyze queries
EXPLAIN SELECT * FROM revamp_users WHERE source = 'revamp';

-- Check index usage
SHOW INDEX FROM revamp_users;
```

## Security

- Never commit database credentials
- Use `.env` for sensitive data
- Restrict network access to database
- Use SSL/TLS connections in production
- Grant minimum required privileges to Debezium user

### Debezium User Permissions
```sql
-- Create debezium user with minimal privileges
CREATE USER 'debezium'@'%' IDENTIFIED BY 'dbz';
GRANT SELECT, RELOAD, SHOW DATABASES, REPLICATION SLAVE, REPLICATION CLIENT 
ON *.* TO 'debezium'@'%';
FLUSH PRIVILEGES;
```

---

**Schema Version**: 1.0.0  
**Last Updated**: 2026-01-08


