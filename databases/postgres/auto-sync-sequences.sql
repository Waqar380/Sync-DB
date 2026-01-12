-- ================================================
-- PostgreSQL Trigger: Auto-Fix Sequences (Generic Version)
-- ================================================
-- This trigger automatically updates the sequence after any INSERT
-- to prevent "duplicate key" errors, even for direct inserts.

-- Generic function to sync sequence after INSERT
CREATE OR REPLACE FUNCTION sync_sequence_after_insert()
RETURNS TRIGGER AS $$
DECLARE
    sequence_name TEXT;
    max_id BIGINT;
    current_val BIGINT;
BEGIN
    -- Get the sequence name for this table's id column
    sequence_name := pg_get_serial_sequence(TG_TABLE_NAME::regclass::text, 'id');
    
    IF sequence_name IS NOT NULL THEN
        -- Get the maximum ID from the table
        EXECUTE format('SELECT COALESCE(MAX(id), 1) FROM %I', TG_TABLE_NAME) INTO max_id;
        
        -- Get current sequence value (or 1 if never used)
        BEGIN
            current_val := currval(sequence_name);
        EXCEPTION WHEN OTHERS THEN
            current_val := 1;
        END;
        
        -- Set sequence to the greater of max_id or current_val
        IF max_id > current_val THEN
            PERFORM setval(sequence_name, max_id);
            RAISE NOTICE 'Updated sequence % to %', sequence_name, max_id;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply trigger to legacy_users table
DROP TRIGGER IF EXISTS sync_legacy_users_sequence ON legacy_users;
CREATE TRIGGER sync_legacy_users_sequence
    AFTER INSERT ON legacy_users
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- Apply trigger to legacy_posts table
DROP TRIGGER IF EXISTS sync_legacy_posts_sequence ON legacy_posts;
CREATE TRIGGER sync_legacy_posts_sequence
    AFTER INSERT ON legacy_posts
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- Apply trigger to legacy_likes table
DROP TRIGGER IF EXISTS sync_legacy_likes_sequence ON legacy_likes;
CREATE TRIGGER sync_legacy_likes_sequence
    AFTER INSERT ON legacy_likes
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- Verify triggers are created
SELECT 
    tgname AS trigger_name,
    tgrelid::regclass AS table_name,
    tgenabled AS enabled
FROM pg_trigger
WHERE tgname LIKE 'sync_%_sequence'
ORDER BY table_name;
