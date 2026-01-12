# PostgreSQL (Legacy Platform) Database

## Overview

This directory contains the PostgreSQL schema for the **Legacy Platform** in our two-way sync POC.

## Schema Design

### Naming Conventions
- Table names: `legacy_{entity}` (e.g., `legacy_users`)
- Columns: snake_case (e.g., `full_name`, `post_title`)
- Primary keys: `id` (SERIAL)

### Entities

#### 1. legacy_users
User accounts in the legacy system.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL | Primary key |
| username | VARCHAR(50) | Unique username |
| email | VARCHAR(100) | Unique email |
| full_name | VARCHAR(100) | User's full name |
| phone_number | VARCHAR(20) | Contact number |
| status | VARCHAR(20) | active/inactive/suspended |
| **source** | VARCHAR(20) | **Loop prevention flag** |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

#### 2. legacy_posts
User-generated posts.

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL | Primary key |
| user_id | INTEGER | Foreign key to legacy_users |
| post_title | VARCHAR(200) | Post title |
| post_content | TEXT | Post body |
| post_status | VARCHAR(20) | draft/published/archived |
| view_count | INTEGER | Number of views |
| **source** | VARCHAR(20) | **Loop prevention flag** |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Last update timestamp |

#### 3. legacy_likes
Post engagement (likes).

| Column | Type | Description |
|--------|------|-------------|
| id | SERIAL | Primary key |
| user_id | INTEGER | Foreign key to legacy_users |
| post_id | INTEGER | Foreign key to legacy_posts |
| like_type | VARCHAR(20) | like/love/wow/sad/angry |
| **source** | VARCHAR(20) | **Loop prevention flag** |
| created_at | TIMESTAMP | Creation timestamp |

### Loop Prevention Mechanism

Every table includes a `source` column with three possible values:

- **`legacy`**: Record created/updated directly in PostgreSQL (by app/user)
- **`revamp`**: Record synced from MySQL (mapped back from revamp platform)
- **`sync_service`**: Record written by Laravel Sync Service

**Critical Rule**: Records with `source = 'sync_service'` MUST NOT be published to Kafka by Debezium.

## Setup Instructions

### 1. Create Database
```bash
# Connect to PostgreSQL
psql -U postgres

# Create database
CREATE DATABASE legacy_db;

# Connect to the database
\c legacy_db
```

### 2. Run Schema
```bash
psql -U postgres -d legacy_db -f schema.sql
```

### 3. Verify Setup
```sql
-- Check tables
\dt

-- Verify data
SELECT * FROM legacy_users;
SELECT * FROM legacy_posts;
SELECT * FROM legacy_likes;

-- Check source distribution
SELECT source, COUNT(*) FROM legacy_users GROUP BY source;
```

## Debezium Connector Configuration

To set up CDC on this database:

```json
{
  "name": "legacy-postgres-connector",
  "config": {
    "connector.class": "io.debezium.connector.postgresql.PostgresConnector",
    "database.hostname": "postgres",
    "database.port": "5432",
    "database.user": "postgres",
    "database.password": "postgres",
    "database.dbname": "legacy_db",
    "database.server.name": "legacy",
    "table.include.list": "public.legacy_users,public.legacy_posts,public.legacy_likes",
    "plugin.name": "pgoutput",
    "publication.autocreate.mode": "filtered",
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
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('test_user', 'test@example.com', 'Test User', 'legacy');
```
**Expected**: Event published to Kafka → Synced to MySQL

### Test 2: Sync Service Insert (Should NOT Sync)
```sql
INSERT INTO legacy_users (username, email, full_name, source)
VALUES ('synced_user', 'synced@example.com', 'Synced User', 'sync_service');
```
**Expected**: NO event published to Kafka → No loop created

### Test 3: Update Existing Record
```sql
UPDATE legacy_users
SET full_name = 'John Updated', source = 'legacy'
WHERE username = 'john_doe';
```
**Expected**: Event published to Kafka → Synced to MySQL

## Monitoring Queries

### Check Source Distribution
```sql
SELECT source, COUNT(*) as count
FROM legacy_users
GROUP BY source
ORDER BY count DESC;
```

### Find Recently Synced Records
```sql
SELECT id, username, email, source, updated_at
FROM legacy_users
WHERE source = 'sync_service'
ORDER BY updated_at DESC
LIMIT 10;
```

### Find Records Pending Sync
```sql
SELECT id, username, email, source, created_at
FROM legacy_users
WHERE source = 'legacy'
  AND created_at > NOW() - INTERVAL '1 hour';
```

## Troubleshooting

### Issue: Changes not appearing in Kafka
**Check**:
1. Verify Debezium connector is running
2. Check source column value
3. Review Debezium logs

### Issue: Duplicate records
**Check**:
1. Verify unique constraints
2. Check mapping table in sync service
3. Review sync service logs

## Schema Evolution

When adding new columns:
1. Add column with DEFAULT value
2. Update sync service transformers
3. Deploy changes in order: DB → Transformer → Consumer
4. Version your event schemas

## Security

- Never commit database credentials
- Use `.env` for sensitive data
- Restrict network access to database
- Use SSL/TLS connections in production

---

**Schema Version**: 1.0.0  
**Last Updated**: 2026-01-08


