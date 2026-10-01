-- SanIE Database Schema
-- AI-Powered Personal Finance Management System

CREATE DATABASE IF NOT EXISTS sanie_db;
USE sanie_db;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    avatar VARCHAR(255),
    google_id VARCHAR(255),
    email_verified_at TIMESTAMP NULL,
    two_factor_secret VARCHAR(255),
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    currency VARCHAR(3) DEFAULT 'NPR',
    language VARCHAR(10) DEFAULT 'en',
    theme VARCHAR(10) DEFAULT 'light',
    notification_preferences LONGTEXT,
    settings LONGTEXT,
    token_version INT NOT NULL DEFAULT 1,
    data_generation INT NOT NULL DEFAULT 1,
    password_changed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applied database migrations. Fresh installations start at the current
-- canonical structure; upgrade tooling records reconciliations here.
CREATE TABLE schema_migrations (
    migration_id VARCHAR(100) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts Table
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('cash', 'bank', 'esewa', 'khalti', 'ime_pay', 'wallet', 'credit_card', 'savings', 'current') NOT NULL,
    account_number VARCHAR(100),
    balance DECIMAL(15, 2) DEFAULT 0.00,
    opening_balance DECIMAL(15, 2) DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'NPR',
    color VARCHAR(7),
    icon VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,
    include_in_savings BOOLEAN NOT NULL DEFAULT FALSE,
    include_in_net_balance BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories Table
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    icon VARCHAR(50),
    color VARCHAR(7),
    description TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    parent_id INT NULL,
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 999,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_parent_id (parent_id),
    INDEX idx_status (status),
    INDEX idx_sort_order (sort_order),
    INDEX idx_category_priority (user_id, type, status, is_pinned, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subcategories Table
CREATE TABLE subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50),
    description TEXT,
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category_id (category_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goals Table
CREATE TABLE goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    target_amount DECIMAL(15, 2) NOT NULL,
    initial_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    current_amount DECIMAL(15, 2) DEFAULT 0.00,
    version INT NOT NULL DEFAULT 1,
    deadline DATE,
    icon VARCHAR(50),
    color VARCHAR(7),
    description TEXT,
    status ENUM('active', 'completed', 'paused') DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_deadline (deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring Transactions Table
CREATE TABLE recurring_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    category_id INT NOT NULL,
    subcategory_id INT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    frequency ENUM('daily', 'weekly', 'bi_weekly', 'monthly', 'quarterly', 'yearly') NOT NULL,
    day_of_month INT NULL,
    day_of_week INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    next_occurrence DATE,
    description TEXT,
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_next_occurrence (next_occurrence),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NULL,
    from_account_id INT NULL,
    to_account_id INT NULL,
    category_id INT NULL,
    subcategory_id INT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    type ENUM('income', 'expense', 'transfer', 'goal_contribution') NOT NULL,
    payment_method VARCHAR(30) DEFAULT NULL,
    karobar_transaction_id INT NULL,
    client_request_id VARCHAR(64) NULL,
    transfer_parent_id INT NULL,
    goal_id INT NULL,
    recurring_definition_id INT NULL,
    recurring_occurrence_date DATE NULL,
    version INT NOT NULL DEFAULT 1,
    date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_parent_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_recurring_definition FOREIGN KEY (recurring_definition_id) REFERENCES recurring_transactions(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id),
    INDEX idx_account_id (account_id),
    INDEX idx_category_id (category_id),
    INDEX idx_date (date),
    INDEX idx_transactions_user_date_created (user_id, date, created_at),
    INDEX idx_type (type),
    INDEX idx_amount (amount),
    INDEX idx_transactions_category_usage (user_id, type, category_id),
    INDEX idx_from_account_id (from_account_id),
    INDEX idx_to_account_id (to_account_id),
    INDEX idx_goal_id (goal_id),
    UNIQUE KEY uq_transactions_recurring_occurrence (user_id, recurring_definition_id, recurring_occurrence_date),
    UNIQUE KEY uq_transactions_transfer_fee (user_id, transfer_parent_id),
    UNIQUE KEY uq_transactions_user_client_request (user_id, client_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budgets Table
CREATE TABLE budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NULL,
    subcategory_id INT NULL,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    period ENUM('daily', 'weekly', 'monthly', 'yearly') DEFAULT 'monthly',
    start_date DATE,
    end_date DATE,
    alert_threshold DECIMAL(5, 2) DEFAULT 80.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_budgets_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE RESTRICT,
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_period (period),
    INDEX idx_budgets_user_scope_dates (user_id, category_id, subcategory_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments Table
CREATE TABLE attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_id INT NULL,
    goal_id INT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT,
    file_type VARCHAR(100),
    mime_type VARCHAR(100),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks Table
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_type ENUM('general', 'board_study') NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    content TEXT,
    category VARCHAR(80) NULL,
    due_date DATE NULL,
    display_date_bs CHAR(10) NULL,
    subject VARCHAR(100) NULL,
    unit_label VARCHAR(50) NULL,
    status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    completed_at TIMESTAMP NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    reminder_at DATETIME NULL,
    summary_url VARCHAR(2048) NULL,
    seed_key VARCHAR(100) NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_tasks_user_seed (user_id, seed_key),
    INDEX idx_tasks_user_type_status (user_id, task_type, status),
    INDEX idx_tasks_user_due_date (user_id, due_date),
    INDEX idx_tasks_user_category (user_id, category),
    INDEX idx_tasks_user_subject (user_id, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications Table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    icon VARCHAR(50) DEFAULT 'fa-bell',
    color VARCHAR(20) DEFAULT '#6366f1',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable Notification Event Claims
CREATE TABLE notification_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    source_type VARCHAR(50) NULL,
    source_id INT NULL,
    occurred_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_notification_events_user_key (user_id, event_key),
    INDEX idx_notification_events_source (user_id, source_type, source_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Analysis History Table
CREATE TABLE ai_analysis_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    analysis_type ENUM('daily', 'weekly', 'monthly', 'yearly', 'custom') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    insights LONGTEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_analysis_type (analysis_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports Table
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('monthly', 'yearly', 'custom') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    data LONGTEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity Logs Table
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    metadata LONGTEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People Table (Karobar Module)
CREATE TABLE people (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('person','friend','family','shop','vendor','business','other') DEFAULT 'person',
    phone VARCHAR(30),
    email VARCHAR(255),
    address TEXT,
    photo VARCHAR(255),
    notes TEXT,
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_name (name),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Karobar Transactions Table
CREATE TABLE karobar_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    person_id INT NOT NULL,
    type ENUM('lent', 'borrowed', 'returned', 'repaid', 'adjustment') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    account_id INT NULL,
    expense_transaction_id INT NULL,
    income_transaction_id INT NULL,
    payment_method VARCHAR(30) NULL,
    client_request_id VARCHAR(64) NULL,
    version INT NOT NULL DEFAULT 1,
    description TEXT,
    transaction_date DATE NOT NULL,
    due_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_karobar_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (expense_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (income_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_person_id (person_id),
    INDEX idx_type (type),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_karobar_user_date_created (user_id, transaction_date, created_at),
    INDEX idx_due_date (due_date),
    UNIQUE KEY uq_karobar_user_client_request (user_id, client_request_id),
    UNIQUE KEY uq_karobar_user_expense_tx (user_id, expense_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resolve the intentional transaction/Karobar relationship only after both
-- tables exist, avoiding a circular creation-order failure on fresh imports.
ALTER TABLE transactions
    ADD CONSTRAINT fk_transactions_karobar
    FOREIGN KEY (karobar_transaction_id) REFERENCES karobar_transactions(id) ON DELETE SET NULL;

ALTER TABLE transactions
    ADD UNIQUE KEY uq_transactions_user_karobar (user_id, karobar_transaction_id);

-- Password Reset Tokens Table
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Verification Tokens Table
CREATE TABLE email_verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fresh Start reset intents and recoverable file cleanup work.
CREATE TABLE fresh_start_operations (
    operation_id CHAR(36) PRIMARY KEY,
    user_id INT NOT NULL,
    intent_hash CHAR(64) NOT NULL,
    confirmation_hash CHAR(64),
    status ENUM('prepared','verified','processing','cleanup_pending','completed','failed','cancelled') NOT NULL DEFAULT 'prepared',
    summary_json LONGTEXT,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME,
    completed_at DATETIME,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_fresh_start_user_status (user_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fresh_start_file_cleanup (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_id CHAR(36) NOT NULL,
    relative_path VARCHAR(500) NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_error_code VARCHAR(80),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (operation_id) REFERENCES fresh_start_operations(operation_id) ON DELETE CASCADE,
    UNIQUE INDEX uq_fresh_start_cleanup_path (operation_id,relative_path),
    INDEX idx_fresh_start_cleanup_status (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
Legacy global category seed reference (not executed).

Registration is the single source of truth for each user's default categories.
Keeping the old global inserts active here created duplicate category choices
immediately after registration. The reference remains for historical context;
use explicit seed scripts for optional global catalogs.

-- Insert Default Income Categories
INSERT INTO categories (name, type, icon, color, is_default, sort_order) VALUES
('Salary', 'income', 'briefcase', '#10B981', TRUE, 1),
('Freelancing', 'income', 'laptop', '#10B981', TRUE, 2),
('Business', 'income', 'building', '#10B981', TRUE, 3),
('Investment', 'income', 'trending-up', '#10B981', TRUE, 4),
('Bonus', 'income', 'gift', '#10B981', TRUE, 5),
('Gift', 'income', 'heart', '#10B981', TRUE, 6),
('Refund', 'income', 'refresh-cw', '#10B981', TRUE, 7),
('Interest', 'income', 'bank', '#10B981', TRUE, 8),
('Rental', 'income', 'home', '#10B981', TRUE, 9),
('Scholarship', 'income', 'graduation-cap', '#10B981', TRUE, 10),
('Dividend', 'income', 'percent', '#10B981', TRUE, 11),
('Others', 'income', 'more-horizontal', '#10B981', TRUE, 12);

-- Insert Default Expense Categories
INSERT INTO categories (name, type, icon, color, is_default, sort_order) VALUES
('Food', 'expense', 'utensils', '#EF4444', TRUE, 1),
('Transportation', 'expense', 'car', '#EF4444', TRUE, 2),
('Shopping', 'expense', 'shopping-bag', '#EF4444', TRUE, 3),
('Health', 'expense', 'heart-pulse', '#EF4444', TRUE, 4),
('Education', 'expense', 'book', '#EF4444', TRUE, 5),
('Entertainment', 'expense', 'film', '#EF4444', TRUE, 6),
('Bills', 'expense', 'file-text', '#EF4444', TRUE, 7),
('Travel', 'expense', 'plane', '#EF4444', TRUE, 8),
('Rent', 'expense', 'home', '#EF4444', TRUE, 9),
('Insurance', 'expense', 'shield', '#EF4444', TRUE, 10),
('EMI', 'expense', 'credit-card', '#EF4444', TRUE, 11),
('Family', 'expense', 'users', '#EF4444', TRUE, 12),
('Donation', 'expense', 'hand-heart', '#EF4444', TRUE, 13),
('Taxes', 'expense', 'landmark', '#EF4444', TRUE, 14),
('Pets', 'expense', 'paw-print', '#EF4444', TRUE, 15),
('Others', 'expense', 'more-horizontal', '#EF4444', TRUE, 16),
('Room Expense', 'expense', 'home', '#EF4444', TRUE, 17);

-- Insert Default Income Subcategories
INSERT INTO subcategories (category_id, name, icon, sort_order) VALUES
-- Salary subcategories
((SELECT id FROM categories WHERE name = 'Salary' AND type = 'income' LIMIT 1), 'Monthly Salary', 'calendar', 1),
((SELECT id FROM categories WHERE name = 'Salary' AND type = 'income' LIMIT 1), 'Overtime Pay', 'clock', 2),
((SELECT id FROM categories WHERE name = 'Salary' AND type = 'income' LIMIT 1), 'Bonus', 'gift', 3),
-- Freelancing subcategories
((SELECT id FROM categories WHERE name = 'Freelancing' AND type = 'income' LIMIT 1), 'Web Development', 'code', 1),
((SELECT id FROM categories WHERE name = 'Freelancing' AND type = 'income' LIMIT 1), 'Design', 'palette', 2),
((SELECT id FROM categories WHERE name = 'Freelancing' AND type = 'income' LIMIT 1), 'Writing', 'pen', 3),
((SELECT id FROM categories WHERE name = 'Freelancing' AND type = 'income' LIMIT 1), 'Consulting', 'lightbulb', 4),
-- Business subcategories
((SELECT id FROM categories WHERE name = 'Business' AND type = 'income' LIMIT 1), 'Product Sales', 'box', 1),
((SELECT id FROM categories WHERE name = 'Business' AND type = 'income' LIMIT 1), 'Services', 'cog', 2),
((SELECT id FROM categories WHERE name = 'Business' AND type = 'income' LIMIT 1), 'Commission', 'percent', 3),
-- Investment subcategories
((SELECT id FROM categories WHERE name = 'Investment' AND type = 'income' LIMIT 1), 'Stocks', 'graph-up', 1),
((SELECT id FROM categories WHERE name = 'Investment' AND type = 'income' LIMIT 1), 'Mutual Funds', 'bank', 2),
((SELECT id FROM categories WHERE name = 'Investment' AND type = 'income' LIMIT 1), 'Real Estate', 'house', 3),
((SELECT id FROM categories WHERE name = 'Investment' AND type = 'income' LIMIT 1), 'Cryptocurrency', 'currency-bitcoin', 4),
-- Rental subcategories
((SELECT id FROM categories WHERE name = 'Rental' AND type = 'income' LIMIT 1), 'Property Rent', 'building', 1),
((SELECT id FROM categories WHERE name = 'Rental' AND type = 'income' LIMIT 1), 'Vehicle Rent', 'car', 2);

-- Insert Default Expense Subcategories
INSERT INTO subcategories (category_id, name, icon, sort_order) VALUES
-- Food subcategories
((SELECT id FROM categories WHERE name = 'Food' AND type = 'expense' LIMIT 1), 'Groceries', 'cart', 1),
((SELECT id FROM categories WHERE name = 'Food' AND type = 'expense' LIMIT 1), 'Restaurant', 'utensils', 2),
((SELECT id FROM categories WHERE name = 'Food' AND type = 'expense' LIMIT 1), 'Fast Food', 'hamburger', 3),
((SELECT id FROM categories WHERE name = 'Food' AND type = 'expense' LIMIT 1), 'Coffee', 'cup-hot', 4),
-- Transportation subcategories
((SELECT id FROM categories WHERE name = 'Transportation' AND type = 'expense' LIMIT 1), 'Fuel', 'fuel-pump', 1),
((SELECT id FROM categories WHERE name = 'Transportation' AND type = 'expense' LIMIT 1), 'Public Transport', 'bus', 2),
((SELECT id FROM categories WHERE name = 'Transportation' AND type = 'expense' LIMIT 1), 'Taxi/Ride Share', 'car-front', 3),
((SELECT id FROM categories WHERE name = 'Transportation' AND type = 'expense' LIMIT 1), 'Parking', 'square-parking', 4),
((SELECT id FROM categories WHERE name = 'Transportation' AND type = 'expense' LIMIT 1), 'Vehicle Maintenance', 'wrench', 5),
-- Shopping subcategories
((SELECT id FROM categories WHERE name = 'Shopping' AND type = 'expense' LIMIT 1), 'Clothing', 't-shirt', 1),
((SELECT id FROM categories WHERE name = 'Shopping' AND type = 'expense' LIMIT 1), 'Electronics', 'laptop', 2),
((SELECT id FROM categories WHERE name = 'Shopping' AND type = 'expense' LIMIT 1), 'Home Goods', 'house', 3),
((SELECT id FROM categories WHERE name = 'Shopping' AND type = 'expense' LIMIT 1), 'Groceries', 'cart', 4),
-- Health subcategories
((SELECT id FROM categories WHERE name = 'Health' AND type = 'expense' LIMIT 1), 'Doctor Visit', 'person', 1),
((SELECT id FROM categories WHERE name = 'Health' AND type = 'expense' LIMIT 1), 'Medicine', 'capsule', 2),
((SELECT id FROM categories WHERE name = 'Health' AND type = 'expense' LIMIT 1), 'Insurance', 'shield', 3),
((SELECT id FROM categories WHERE name = 'Health' AND type = 'expense' LIMIT 1), 'Fitness', 'activity', 4),
-- Education subcategories
((SELECT id FROM categories WHERE name = 'Education' AND type = 'expense' LIMIT 1), 'Tuition', 'bank', 1),
((SELECT id FROM categories WHERE name = 'Education' AND type = 'expense' LIMIT 1), 'Books', 'book', 2),
((SELECT id FROM categories WHERE name = 'Education' AND type = 'expense' LIMIT 1), 'Courses', 'mortarboard', 3),
((SELECT id FROM categories WHERE name = 'Education' AND type = 'expense' LIMIT 1), 'Workshops', 'people', 4),
-- Entertainment subcategories
((SELECT id FROM categories WHERE name = 'Entertainment' AND type = 'expense' LIMIT 1), 'Movies', 'film', 1),
((SELECT id FROM categories WHERE name = 'Entertainment' AND type = 'expense' LIMIT 1), 'Games', 'controller', 2),
((SELECT id FROM categories WHERE name = 'Entertainment' AND type = 'expense' LIMIT 1), 'Music', 'music-note', 3),
((SELECT id FROM categories WHERE name = 'Entertainment' AND type = 'expense' LIMIT 1), 'Events', 'calendar-event', 4),
-- Bills subcategories
((SELECT id FROM categories WHERE name = 'Bills' AND type = 'expense' LIMIT 1), 'Electricity', 'lightning', 1),
((SELECT id FROM categories WHERE name = 'Bills' AND type = 'expense' LIMIT 1), 'Water', 'droplet', 2),
((SELECT id FROM categories WHERE name = 'Bills' AND type = 'expense' LIMIT 1), 'Internet', 'wifi', 3),
((SELECT id FROM categories WHERE name = 'Bills' AND type = 'expense' LIMIT 1), 'Phone', 'telephone', 4),
((SELECT id FROM categories WHERE name = 'Bills' AND type = 'expense' LIMIT 1), 'Subscription', 'recycle', 5),
-- Travel subcategories
((SELECT id FROM categories WHERE name = 'Travel' AND type = 'expense' LIMIT 1), 'Flight', 'airplane', 1),
((SELECT id FROM categories WHERE name = 'Travel' AND type = 'expense' LIMIT 1), 'Hotel', 'building', 2),
((SELECT id FROM categories WHERE name = 'Travel' AND type = 'expense' LIMIT 1), 'Transportation', 'car', 3),
((SELECT id FROM categories WHERE name = 'Travel' AND type = 'expense' LIMIT 1), 'Activities', 'camera', 4);

-- Room Expense subcategories
INSERT INTO subcategories (category_id, name, icon, sort_order) VALUES
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Grains', 'wheat', 1),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Pulses', 'leaf', 2),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Vegetables', 'carrot', 3),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Fruits', 'apple', 4),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Meat, Fish & Eggs', 'egg', 5),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Dairy', 'cup', 6),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Cooking Oil', 'droplet', 7),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Spices & Seasonings', 'pepper', 8),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Tea Products', 'cup-hot', 9),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Snacks', 'cookie', 10),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Cooking Gas', 'fire', 11),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Household Supplies', 'spray', 12),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Room Rent', 'home', 13),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Utilities', 'zap', 14),
((SELECT id FROM categories WHERE name = 'Room Expense' AND type = 'expense' LIMIT 1), 'Others', 'more-horizontal', 15);
*/
