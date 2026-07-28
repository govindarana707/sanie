-- Migration: Seed "Room Expense" category with predefined subcategories
-- Safe to run multiple times (uses INSERT IGNORE to prevent duplicates)
-- Run: mysql -u root sanie_db < seed_room_expense.sql

-- 1. Insert the "Room Expense" expense category (global, user_id = NULL)
INSERT IGNORE INTO categories (user_id, name, type, icon, color, description, is_default, status, sort_order)
VALUES (NULL, 'Room Expense', 'expense', 'home', '#EF4444', 'Room and household expenses', TRUE, 'active', 18);

-- 2. Capture the category ID
SELECT id INTO @cat_id FROM categories WHERE name = 'Room Expense' AND type = 'expense' AND user_id IS NULL LIMIT 1;

-- 3. Insert predefined subcategories (skip if already exist)
INSERT IGNORE INTO subcategories (category_id, user_id, name, icon, description, status, sort_order)
VALUES
(@cat_id, NULL, 'Grains', 'wheat', 'Grains and cereals', 'active', 1),
(@cat_id, NULL, 'Pulses', 'leaf', 'Pulses and legumes', 'active', 2),
(@cat_id, NULL, 'Vegetables', 'carrot', 'Fresh vegetables', 'active', 3),
(@cat_id, NULL, 'Fruits', 'apple', 'Fresh fruits', 'active', 4),
(@cat_id, NULL, 'Meat, Fish & Eggs', 'egg', 'Meat, fish, and eggs', 'active', 5),
(@cat_id, NULL, 'Dairy', 'cup', 'Milk, cheese, and dairy products', 'active', 6),
(@cat_id, NULL, 'Cooking Oil', 'droplet', 'Cooking oils and fats', 'active', 7),
(@cat_id, NULL, 'Spices & Seasonings', 'pepper', 'Spices, herbs, and seasonings', 'active', 8),
(@cat_id, NULL, 'Tea Products', 'cup-hot', 'Tea, coffee, and beverages', 'active', 9),
(@cat_id, NULL, 'Snacks', 'cookie', 'Snacks and packaged foods', 'active', 10),
(@cat_id, NULL, 'Cooking Gas', 'fire', 'LPG and cooking gas', 'active', 11),
(@cat_id, NULL, 'Household Supplies', 'spray', 'Cleaning and household supplies', 'active', 12),
(@cat_id, NULL, 'Room Rent', 'home', 'Monthly room rent', 'active', 13),
(@cat_id, NULL, 'Utilities', 'zap', 'Electricity, water, and utilities', 'active', 14),
(@cat_id, NULL, 'Others', 'more-horizontal', 'Other room expenses', 'active', 15);
