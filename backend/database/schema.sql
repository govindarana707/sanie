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
    notification_preferences JSON,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts Table
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('cash', 'bank', 'esewa', 'khalti', 'ime_pay', 'wallet', 'credit_card', 'savings', 'current') NOT NULL,
    account_number VARCHAR(100),
    balance DECIMAL(15, 2) DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'NPR',
    color VARCHAR(7),
    icon VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_sort_order (sort_order)
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    current_amount DECIMAL(15, 2) DEFAULT 0.00,
    deadline DATE,
    icon VARCHAR(50),
    color VARCHAR(7),
    description TEXT,
    status ENUM('active', 'completed', 'paused') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_next_occurrence (next_occurrence),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    category_id INT NOT NULL,
    subcategory_id INT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    type ENUM('income', 'expense', 'transfer') NOT NULL,
    date DATE NOT NULL,
    time TIME,
    description TEXT,
    notes TEXT,
    tags JSON,
    is_recurring BOOLEAN DEFAULT FALSE,
    recurring_transaction_id INT NULL,
    is_favorite BOOLEAN DEFAULT FALSE,
    location_lat DECIMAL(10, 8),
    location_lng DECIMAL(11, 8),
    location_address VARCHAR(255),
    receipt_path VARCHAR(255),
    voice_note_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (recurring_transaction_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_account_id (account_id),
    INDEX idx_category_id (category_id),
    INDEX idx_date (date),
    INDEX idx_type (type),
    INDEX idx_amount (amount)
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_period (period)
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications Table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('budget_exceeded', 'salary_reminder', 'recurring_transaction', 'goal_reminder', 'bill_reminder', 'monthly_report', 'weekly_summary', 'ai_recommendation', 'system') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    data JSON,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Analysis History Table
CREATE TABLE ai_analysis_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    analysis_type ENUM('daily', 'weekly', 'monthly', 'yearly', 'custom') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    insights JSON,
    predictions JSON,
    recommendations JSON,
    financial_health_score INT,
    budget_score INT,
    savings_score INT,
    investment_score INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_analysis_type (analysis_type),
    INDEX idx_start_date (start_date),
    INDEX idx_end_date (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports Table
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('monthly', 'yearly', 'custom') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    data JSON,
    file_path VARCHAR(255),
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password Reset Tokens Table
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
('Savings', 'expense', 'piggy-bank', '#EF4444', TRUE, 12),
('Family', 'expense', 'users', '#EF4444', TRUE, 13),
('Donation', 'expense', 'hand-heart', '#EF4444', TRUE, 14),
('Taxes', 'expense', 'landmark', '#EF4444', TRUE, 15),
('Pets', 'expense', 'paw-print', '#EF4444', TRUE, 16),
('Others', 'expense', 'more-horizontal', '#EF4444', TRUE, 17);

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
