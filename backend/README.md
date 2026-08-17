# SanIE Backend API

AI-Powered Personal Finance Management System - REST API

## Setup Instructions

1. **Import Database Schema**
   ```bash
   mysql -u root -p < database/schema.sql
   ```

   This canonical schema is complete; do not replay historical migration SQL
   files after a fresh import. To upgrade an existing August 10, 2026-or-newer
   installation, back it up and run:

   ```bash
   php database/migrate.php
   ```

   See `database/MIGRATIONS.md` for the supported baseline and safety checks.
   See `../docs/database-backups.md` for the approved backup location, naming,
   restore procedure, retention guidance, and source-control policy.

2. **Configure Database**
   Edit `config/database.php` if needed:
   - Host: localhost
   - Database: sanie_db
   - Username: root
   - Password: (empty by default)

3. **Configure Application**
   Edit `config/config.php`:
   - Change JWT_SECRET to a secure random string
   - Update ALLOWED_ORIGINS for CORS

4. **Web Server Configuration**
   - Point your web server to the `backend` directory
   - Ensure mod_rewrite is enabled for clean URLs

## API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login user
- `GET /api/auth/me` - Get current user
- `PUT /api/auth/update` - Update profile
- `POST /api/auth/change-password` - Change password

### Transactions
- `GET /api/transactions` - List all transactions
- `GET /api/transactions/{id}` - Get single transaction
- `POST /api/transactions` - Create transaction
- `PUT /api/transactions/{id}` - Update transaction
- `DELETE /api/transactions/{id}` - Delete transaction
- `GET /api/transactions/statistics` - Get statistics
- `GET /api/transactions/category-breakdown` - Get category breakdown

### Accounts
- `GET /api/accounts` - List all accounts
- `GET /api/accounts/{id}` - Get single account
- `POST /api/accounts` - Create account
- `PUT /api/accounts/{id}` - Update account
- `DELETE /api/accounts/{id}` - Delete account
- `GET /api/accounts/total-balance` - Get total balance

### Categories
- `GET /api/categories` - List all categories
- `GET /api/categories/{id}` - Get single category
- `POST /api/categories` - Create category
- `PUT /api/categories/{id}` - Update category
- `DELETE /api/categories/{id}` - Delete category
- `GET /api/categories/{id}/subcategories` - Get subcategories

### Budgets
- `GET /api/budgets` - List all budgets
- `GET /api/budgets/{id}` - Get single budget
- `POST /api/budgets` - Create budget
- `PUT /api/budgets/{id}` - Update budget
- `DELETE /api/budgets/{id}` - Delete budget
- `GET /api/budgets/{id}/progress` - Get budget progress

### Goals
- `GET /api/goals` - List all goals
- `GET /api/goals/{id}` - Get single goal
- `POST /api/goals` - Create goal
- `PUT /api/goals/{id}` - Update goal
- `DELETE /api/goals/{id}` - Delete goal
- `GET /api/goals/{id}/progress` - Get goal progress
- `POST /api/goals/{id}/contribute` - Add contribution to goal

### Dashboard
- `GET /api/dashboard` - Get dashboard data
- `GET /api/dashboard/quick-stats` - Get quick statistics

## Authentication

All protected endpoints require a Bearer token in the Authorization header:

```
Authorization: Bearer {jwt_token}
```

## Response Format

Success:
```json
{
  "success": true,
  "message": "Success message",
  "data": {}
}
```

Error:
```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

## Security Features

- JWT Authentication
- Password hashing (bcrypt)
- SQL injection prevention (PDO prepared statements)
- XSS prevention (input sanitization)
- CORS configuration
- CSRF protection (to be implemented)

## Currency Format

All amounts are stored as DECIMAL(15, 2) and should be formatted as:
- Rs 12,500.00
