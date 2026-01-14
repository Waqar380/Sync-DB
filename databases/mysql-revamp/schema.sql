-- ============================================
-- MySQL (Revamped Platform) Schema
-- ============================================
-- This schema represents the REVAMPED system
-- Different naming conventions and structure
-- from the legacy MySQL schema
-- ============================================

-- Set MySQL settings
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables if they exist
DROP TABLE IF EXISTS revamp_likes;
DROP TABLE IF EXISTS revamp_posts;
DROP TABLE IF EXISTS revamp_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- USERS TABLE (Revamped Schema)
-- ============================================
CREATE TABLE revamp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(50) NOT NULL UNIQUE,
    email_address VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100),
    mobile VARCHAR(20),
    account_status VARCHAR(20) DEFAULT 'Active',
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'revamp',
    -- Values: 'legacy', 'revamp', 'sync_service'
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_user_name (user_name),
    INDEX idx_email_address (email_address),
    INDEX idx_source (source),
    
    -- Constraints
    CONSTRAINT chk_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_account_status CHECK (account_status IN ('Active', 'Inactive', 'Suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- POSTS TABLE (Revamped Schema)
-- ============================================
CREATE TABLE revamp_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    status VARCHAR(20) DEFAULT 'Published',
    views INT DEFAULT 0,
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'revamp',
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Key
    CONSTRAINT fk_revamp_posts_author
        FOREIGN KEY (author_id)
        REFERENCES revamp_users(id)
        ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_author_id (author_id),
    INDEX idx_source (source),
    INDEX idx_status (status),
    
    -- Constraints
    CONSTRAINT chk_posts_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_posts_status CHECK (status IN ('Draft', 'Published', 'Archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LIKES TABLE (Revamped Schema)
-- ============================================
CREATE TABLE revamp_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    reaction_type VARCHAR(20) DEFAULT 'Like',
    
    -- Loop Prevention Column
    source VARCHAR(20) NOT NULL DEFAULT 'revamp',
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    CONSTRAINT fk_revamp_likes_user
        FOREIGN KEY (user_id)
        REFERENCES revamp_users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_revamp_likes_post
        FOREIGN KEY (post_id)
        REFERENCES revamp_posts(id)
        ON DELETE CASCADE,
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_post_id (post_id),
    INDEX idx_source (source),
    
    -- Constraints
    CONSTRAINT chk_likes_source CHECK (source IN ('legacy', 'revamp', 'sync_service')),
    CONSTRAINT chk_reaction_type CHECK (reaction_type IN ('Like', 'Love', 'Wow', 'Sad', 'Angry')),
    
    -- Unique constraint: one user can like a post only once
    CONSTRAINT uq_revamp_user_post_like UNIQUE (user_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUTO-SYNC AUTO_INCREMENT TRIGGERS
-- ============================================
-- These triggers automatically update AUTO_INCREMENT after INSERT
-- to prevent duplicate entry errors, even for direct inserts.

DELIMITER $$

DROP TRIGGER IF EXISTS sync_revamp_users_autoincrement$$
CREATE TRIGGER sync_revamp_users_autoincrement
AFTER INSERT ON revamp_users
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    -- Get the maximum ID from the table
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM revamp_users;
    
    -- Calculate next ID
    SET next_id = max_id + 1;
    
    -- Update AUTO_INCREMENT value using prepared statement
    SET @alter_sql = CONCAT('ALTER TABLE revamp_users AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DROP TRIGGER IF EXISTS sync_revamp_posts_autoincrement$$
CREATE TRIGGER sync_revamp_posts_autoincrement
AFTER INSERT ON revamp_posts
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM revamp_posts;
    SET next_id = max_id + 1;
    
    SET @alter_sql = CONCAT('ALTER TABLE revamp_posts AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DROP TRIGGER IF EXISTS sync_revamp_likes_autoincrement$$
CREATE TRIGGER sync_revamp_likes_autoincrement
AFTER INSERT ON revamp_likes
FOR EACH ROW
BEGIN
    DECLARE max_id INT;
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) INTO max_id FROM revamp_likes;
    SET next_id = max_id + 1;
    
    SET @alter_sql = CONCAT('ALTER TABLE revamp_likes AUTO_INCREMENT = ', next_id);
    PREPARE stmt FROM @alter_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

DELIMITER ;

-- ============================================
-- TABLE COMMENTS
-- ============================================
ALTER TABLE revamp_users COMMENT = 'Revamped platform user accounts';
ALTER TABLE revamp_posts COMMENT = 'Revamped platform user posts';
ALTER TABLE revamp_likes COMMENT = 'Revamped platform post reactions';

-- ============================================
-- SEED DATA (For Testing)
-- ============================================
-- Insert sample users (with source='revamp')
INSERT INTO revamp_users (user_name, email_address, display_name, mobile, source) VALUES
('alice_wonder', 'alice@revamp.com', 'Alice Wonder', '+9876543210', 'revamp'),
('charlie_brown', 'charlie@revamp.com', 'Charlie Brown', '+9876543211', 'revamp'),
('diana_prince', 'diana@revamp.com', 'Diana Prince', '+9876543212', 'revamp');

-- Insert sample posts (with source='revamp')
INSERT INTO revamp_posts (author_id, title, content, source) VALUES
(1, 'Welcome to Revamp Platform', 'This is my first post on the new revamped platform!', 'revamp'),
(1, 'MySQL Best Practices', 'Here are some MySQL tips and tricks.', 'revamp'),
(2, 'Charlie Journey', 'My journey as a developer has been amazing!', 'revamp'),
(3, 'Diana Tech Blog', 'Technology is advancing rapidly.', 'revamp');

-- Insert sample likes (with source='revamp')
INSERT INTO revamp_likes (user_id, post_id, reaction_type, source) VALUES
(2, 1, 'Love', 'revamp'),
(3, 1, 'Like', 'revamp'),
(1, 3, 'Wow', 'revamp'),
(3, 2, 'Like', 'revamp');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================
-- Count records per source
-- SELECT source, COUNT(*) FROM revamp_users GROUP BY source;
-- SELECT source, COUNT(*) FROM revamp_posts GROUP BY source;
-- SELECT source, COUNT(*) FROM revamp_likes GROUP BY source;

-- ============================================
-- SCHEMA DIFFERENCES FROM LEGACY
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

