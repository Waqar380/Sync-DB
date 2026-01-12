-- ============================================
-- PostgreSQL (Legacy Platform) Schema
-- ============================================
-- This schema represents the LEGACY system
-- Different naming conventions and structure
-- from the revamped MySQL schema
-- ============================================

-- Drop existing tables if they exist
DROP TABLE IF EXISTS legacy_likes CASCADE;
DROP TABLE IF EXISTS legacy_posts CASCADE;
DROP TABLE IF EXISTS legacy_users CASCADE;

-- ============================================
-- USERS TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(100),
    phone_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'legacy',
    -- Values: 'legacy', 'revamp', 'sync_service'
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Constraints
    CONSTRAINT chk_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_status CHECK (status IN ('active', 'inactive', 'suspended'))
);

-- Index for frequent queries
CREATE INDEX idx_legacy_users_username ON legacy_users(username);
CREATE INDEX idx_legacy_users_email ON legacy_users(email);
CREATE INDEX idx_legacy_users_source ON legacy_users(source);

-- ============================================
-- POSTS TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_posts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    post_title VARCHAR(200) NOT NULL,
    post_content TEXT,
    post_status VARCHAR(20) DEFAULT 'published',
    view_count INTEGER DEFAULT 0,
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'legacy',
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    CONSTRAINT fk_legacy_posts_user 
        FOREIGN KEY (user_id) 
        REFERENCES legacy_users(id) 
        ON DELETE CASCADE,
    
    -- Constraints
    CONSTRAINT chk_posts_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_posts_status CHECK (post_status IN ('draft', 'published', 'archived'))
);

-- Indexes
CREATE INDEX idx_legacy_posts_user_id ON legacy_posts(user_id);
CREATE INDEX idx_legacy_posts_source ON legacy_posts(source);
CREATE INDEX idx_legacy_posts_status ON legacy_posts(post_status);

-- ============================================
-- LIKES TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_likes (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    post_id INTEGER NOT NULL,
    like_type VARCHAR(20) DEFAULT 'like',
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'legacy',
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    CONSTRAINT fk_legacy_likes_user 
        FOREIGN KEY (user_id) 
        REFERENCES legacy_users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_legacy_likes_post 
        FOREIGN KEY (post_id) 
        REFERENCES legacy_posts(id) 
        ON DELETE CASCADE,
    
    -- Constraints
    CONSTRAINT chk_likes_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_like_type CHECK (like_type IN ('like', 'love', 'wow', 'sad', 'angry')),
    
    -- Unique constraint: one user can like a post only once
    CONSTRAINT uq_legacy_user_post_like UNIQUE (user_id, post_id)
);

-- Indexes
CREATE INDEX idx_legacy_likes_user_id ON legacy_likes(user_id);
CREATE INDEX idx_legacy_likes_post_id ON legacy_likes(post_id);
CREATE INDEX idx_legacy_likes_source ON legacy_likes(source);

-- ============================================
-- UPDATED_AT TRIGGER FUNCTION
-- ============================================
-- Function to automatically update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Apply trigger to users table
CREATE TRIGGER update_legacy_users_updated_at 
    BEFORE UPDATE ON legacy_users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- Apply trigger to posts table
CREATE TRIGGER update_legacy_posts_updated_at 
    BEFORE UPDATE ON legacy_posts
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================
-- AUTO-SYNC SEQUENCE TRIGGERS
-- ============================================
-- These triggers automatically update sequences after INSERT
-- to prevent duplicate key errors, even for direct inserts.

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
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply trigger to legacy_users table
CREATE TRIGGER sync_legacy_users_sequence
    AFTER INSERT ON legacy_users
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- Apply trigger to legacy_posts table
CREATE TRIGGER sync_legacy_posts_sequence
    AFTER INSERT ON legacy_posts
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- Apply trigger to legacy_likes table
CREATE TRIGGER sync_legacy_likes_sequence
    AFTER INSERT ON legacy_likes
    FOR EACH STATEMENT
    EXECUTE FUNCTION sync_sequence_after_insert();

-- ============================================
-- COMMENTS AND NOTES
-- ============================================
COMMENT ON TABLE legacy_users IS 'Legacy platform user accounts';
COMMENT ON COLUMN legacy_users.source IS 'Tracks where the record originated: legacy|revamp|sync_service';
COMMENT ON FUNCTION sync_sequence_after_insert() IS 'Automatically syncs sequence values after INSERT to prevent duplicate key errors';

COMMENT ON TABLE legacy_posts IS 'Legacy platform user posts';
COMMENT ON COLUMN legacy_posts.source IS 'Tracks where the record originated: legacy|revamp|sync_service';

COMMENT ON TABLE legacy_likes IS 'Legacy platform post likes';
COMMENT ON COLUMN legacy_likes.source IS 'Tracks where the record originated: legacy|revamp|sync_service';

-- ============================================
-- SEED DATA (For Testing)
-- ============================================
-- Insert sample users (with source='legacy')
INSERT INTO legacy_users (username, email, full_name, phone_number, source) VALUES
('john_doe', 'john.doe@legacy.com', 'John Doe', '+1234567890', 'legacy'),
('jane_smith', 'jane.smith@legacy.com', 'Jane Smith', '+1234567891', 'legacy'),
('bob_wilson', 'bob.wilson@legacy.com', 'Bob Wilson', '+1234567892', 'legacy');

-- Insert sample posts (with source='legacy')
INSERT INTO legacy_posts (user_id, post_title, post_content, source) VALUES
(1, 'My First Post on Legacy', 'This is my first post on the legacy platform.', 'legacy'),
(1, 'Learning PostgreSQL', 'PostgreSQL is a powerful database system.', 'legacy'),
(2, 'Jane Introduction', 'Hello everyone! I am Jane.', 'legacy'),
(3, 'Bob Tech Tips', 'Here are some tech tips for developers.', 'legacy');

-- Insert sample likes (with source='legacy')
INSERT INTO legacy_likes (user_id, post_id, like_type, source) VALUES
(2, 1, 'like', 'legacy'),
(3, 1, 'love', 'legacy'),
(1, 3, 'like', 'legacy'),
(3, 2, 'wow', 'legacy');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================
-- Count records per source
-- SELECT source, COUNT(*) FROM legacy_users GROUP BY source;
-- SELECT source, COUNT(*) FROM legacy_posts GROUP BY source;
-- SELECT source, COUNT(*) FROM legacy_likes GROUP BY source;


