-- Seed accounts for existing users who don't have any accounts yet
USE sanie_db;

-- Insert default accounts for users without any accounts
INSERT INTO accounts (user_id, name, type, account_number, balance, currency, color, icon, is_active, is_default)
SELECT 
    u.id as user_id,
    'Cash' as name,
    'cash' as type,
    '' as account_number,
    0.00 as balance,
    'NPR' as currency,
    '#10B981' as color,
    'cash' as icon,
    TRUE as is_active,
    TRUE as is_default
FROM users u
WHERE NOT EXISTS (
    SELECT 1 FROM accounts a WHERE a.user_id = u.id
);

-- Insert Bank Account for users who now have Cash account but no Bank Account
INSERT INTO accounts (user_id, name, type, account_number, balance, currency, color, icon, is_active, is_default)
SELECT 
    u.id as user_id,
    'Bank Account' as name,
    'bank' as type,
    '' as account_number,
    0.00 as balance,
    'NPR' as currency,
    '#6366f1' as color,
    'bank' as icon,
    TRUE as is_active,
    FALSE as is_default
FROM users u
WHERE EXISTS (
    SELECT 1 FROM accounts a WHERE a.user_id = u.id AND a.name = 'Cash'
) AND NOT EXISTS (
    SELECT 1 FROM accounts a WHERE a.user_id = u.id AND a.name = 'Bank Account'
);

-- Insert eSewa for users who now have Cash account but no eSewa
INSERT INTO accounts (user_id, name, type, account_number, balance, currency, color, icon, is_active, is_default)
SELECT 
    u.id as user_id,
    'eSewa' as name,
    'esewa' as type,
    '' as account_number,
    0.00 as balance,
    'NPR' as currency,
    '#F59E0B' as color,
    'wallet' as icon,
    TRUE as is_active,
    FALSE as is_default
FROM users u
WHERE EXISTS (
    SELECT 1 FROM accounts a WHERE a.user_id = u.id AND a.name = 'Cash'
) AND NOT EXISTS (
    SELECT 1 FROM accounts a WHERE a.user_id = u.id AND a.name = 'eSewa'
);
