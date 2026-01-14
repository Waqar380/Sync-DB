-- ============================================
-- MySQL (Legacy Platform) Schema
-- ============================================
-- This schema represents the LEGACY system
-- Different naming conventions and structure
-- from the revamped MySQL schema
-- ============================================

-- Set MySQL settings
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist
DROP TABLE IF EXISTS legacy_likes;
DROP TABLE IF EXISTS legacy_posts;
DROP TABLE IF EXISTS legacy_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- USERS TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_source (source),
    
    -- Constraints
    CONSTRAINT chk_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_status CHECK (status IN ('active', 'inactive', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- POSTS TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_title VARCHAR(200) NOT NULL,
    post_content TEXT,
    post_status VARCHAR(20) DEFAULT 'published',
    view_count INT DEFAULT 0,
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'legacy',
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_legacy_posts_user
        FOREIGN KEY (user_id)
        REFERENCES legacy_users(id)
        ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_source (source),
    INDEX idx_post_status (post_status),
    
    -- Constraints
    CONSTRAINT chk_posts_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_posts_status CHECK (post_status IN ('draft', 'published', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LIKES TABLE (Legacy Schema)
-- ============================================
CREATE TABLE legacy_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
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
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_post_id (post_id),
    INDEX idx_source (source),
    
    -- Constraints
    CONSTRAINT chk_likes_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_like_type CHECK (like_type IN ('like', 'love', 'wow', 'sad', 'angry')),
    
    -- Unique constraint: one user can like a post only once
    CONSTRAINT uq_legacy_user_post_like UNIQUE (user_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUTO-SYNC AUTO_INCREMENT TRIGGERS
-- ============================================
-- These triggers automatically update AUTO_INCREMENT after INSERT
-- to prevent duplicate entry errors, even for direct inserts.

DELIMITER $$

DROP TRIGGER IF EXISTS sync_legacy_users_autoincrement$$
CREATE TRIGGER sync_legacy_users_autoincrement
AFTER INSERT ON legacy_users
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    -- Get the maximum ID from the table
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM legacy_users;
    
    -- Calculate next ID
    SET next_id = max_id + 1;
    
    -- Update AUTO_INCREMENT value using prepared statement
    SET @alter_sql = CONCAT('ALTER TABLE legacy_users AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DROP TRIGGER IF EXISTS sync_legacy_posts_autoincrement$$
CREATE TRIGGER sync_legacy_posts_autoincrement
AFTER INSERT ON legacy_posts
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM legacy_posts;
    SET next_id = max_id + 1;
    
    SET @alter_sql = CONCAT('ALTER TABLE legacy_posts AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DROP TRIGGER IF EXISTS sync_legacy_likes_autoincrement$$
CREATE TRIGGER sync_legacy_likes_autoincrement
AFTER INSERT ON legacy_likes
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM legacy_likes;
    SET next_id = max_id + 1;
    
    SET @alter_sql = CONCAT('ALTER TABLE legacy_likes AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DELIMITER ;

-- ============================================
-- TABLE COMMENTS
-- ============================================
ALTER TABLE legacy_users COMMENT = 'Legacy platform user accounts';
ALTER TABLE legacy_posts COMMENT = 'Legacy platform user posts';
ALTER TABLE legacy_likes COMMENT = 'Legacy platform post likes';

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
(1, 'Learning MySQL', 'MySQL is a popular database system.', 'legacy'),
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

-- ============================================
-- SCHEMA DIFFERENCES FROM REVAMPED
-- ============================================
-- Legacy (MySQL)             | Revamped (MySQL)
-- ---------------------------|---------------------------
-- legacy_users               | revamp_users
-- username                   | user_name
-- email                      | email_address
-- full_name                  | display_name
-- phone_number               | mobile
-- status                     | account_status
-- ---------------------------|---------------------------
-- legacy_posts               | revamp_posts
-- user_id                    | author_id
-- post_title                 | title
-- post_content               | content
-- post_status                | status
-- view_count                 | views
-- ---------------------------|---------------------------
-- legacy_likes               | revamp_likes
-- like_type                  | reaction_type
-- ---------------------------|---------------------------

