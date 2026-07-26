-- Migration for Category & Subcategory Management Module
-- Run this to update existing database schema

USE sanie_db;

-- Add new columns to categories table
ALTER TABLE categories 
ADD COLUMN IF NOT EXISTS status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
ADD COLUMN IF NOT EXISTS sort_order INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;

-- Update existing categories with default values
UPDATE categories SET status = 'active' WHERE status IS NULL;
UPDATE categories SET sort_order = 0 WHERE sort_order IS NULL;

-- Create subcategories table
CREATE TABLE IF NOT EXISTS subcategories (
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

-- Insert Default Income Subcategories
INSERT IGNORE INTO subcategories (category_id, name, icon, sort_order) VALUES
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
INSERT IGNORE INTO subcategories (category_id, name, icon, sort_order) VALUES
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
