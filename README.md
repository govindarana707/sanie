# SanIE - AI-Powered Personal Finance Management System

**Track Every Rupee. Master Every Month.**

SanIE is a comprehensive, production-ready personal finance management web application that helps users track income, expenses, budgets, and savings goals with AI-powered financial insights and recommendations.

## 🚀 Features

### Core Features
- **Dashboard**: Real-time financial overview with statistics, health score, and recent activity
- **Transactions**: Full CRUD operations with advanced filtering and search
- **Budgets**: Create and manage budgets with progress tracking and alerts
- **Goals**: Set savings goals with progress tracking and contribution management
- **Categories**: Default and custom categories with subcategories
- **Accounts**: Multiple account types (cash, bank, eSewa, Khalti, etc.)

### AI-Powered Features
- **Financial Health Score**: Comprehensive scoring based on savings rate, budget discipline, and goal progress
- **Smart Insights**: AI-generated insights about spending patterns
- **Predictions**: Forecast future expenses and savings
- **Recommendations**: Personalized financial improvement suggestions

### Security & Performance
- JWT-based authentication
- SQL injection prevention (PDO prepared statements)
- XSS prevention (input sanitization)
- CORS configuration
- Responsive design with dark/light theme
- Mobile-friendly interface

## 📁 Project Structure

```
sani/
├── backend/                    # PHP REST API
│   ├── api/                   # API entry point
│   │   └── index.php         # Main router
│   ├── config/               # Configuration files
│   │   ├── config.php       # App configuration
│   │   └── database.php     # Database connection
│   ├── controllers/          # API controllers
│   │   ├── AuthController.php
│   │   ├── TransactionController.php
│   │   ├── AccountController.php
│   │   ├── CategoryController.php
│   │   ├── BudgetController.php
│   │   ├── GoalController.php
│   │   └── DashboardController.php
│   ├── models/              # Data models
│   │   ├── User.php
│   │   ├── Transaction.php
│   │   ├── Account.php
│   │   ├── Category.php
│   │   ├── Budget.php
│   │   └── Goal.php
│   ├── includes/            # Shared utilities
│   │   ├── cors.php
│   │   ├── jwt.php
│   │   ├── response.php
│   │   └── middleware.php
│   ├── database/            # Database schema
│   │   └── schema.sql
│   ├── uploads/             # File uploads directory
│   ├── .htaccess           # URL rewriting
│   └── README.md           # Backend documentation
│
├── frontend/                # Vanilla JavaScript Frontend
│   ├── index.html          # Main HTML file
│   ├── assets/
│   │   ├── css/
│   │   │   └── styles.css  # Main stylesheet
│   │   └── js/
│   │       ├── api.js      # API client
│   │       ├── auth.js     # Authentication module
│   │       ├── dashboard.js # Dashboard module
│   │       ├── transactions.js # Transactions module
│   │       ├── budgets.js  # Budgets module
│   │       ├── goals.js    # Goals module
│   │       └── app.js      # Main application logic
│   └── README.md           # Frontend documentation
│
└── README.md               # This file
```

## 🛠️ Technology Stack

### Backend
- **PHP 8.4+**
- **MySQL** database
- **REST API** architecture
- **JWT** authentication
- **PDO** for database operations

### Frontend
- **Vanilla JavaScript** (ES6+)
- **HTML5**
- **CSS3** with CSS Variables
- **Font Awesome** icons
- **Responsive design**

## 📋 Installation

### Prerequisites
- PHP 8.4 or higher
- MySQL 5.7 or higher
- Composer
- Apache web server (XAMPP/WAMP recommended)
- Modern web browser

### Backend Setup

1. **Clone or download the project**
   ```bash
   cd c:\xampp\htdocs\sani
   ```

2. **Import the database schema**
   ```bash
   mysql -u root -p < backend/database/schema.sql
   ```
   Or use phpMyAdmin to import `backend/database/schema.sql`

3. **Install PHP dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. **Configure application settings**
   Copy `.env.example` to `.env` and provide deployment-specific database,
   URL, JWT, origin, and mail values. Production password resets require an
   HTTPS `FRONTEND_URL` plus the documented `MAIL_*` SMTP settings. SMTP
   username/password must be supplied together when authentication is used.
   Development uses `PASSWORD_RESET_DEV_LOG` and never sends SMTP mail.

5. **Configure web server**
   - Point Apache to the `backend` directory
   - Ensure mod_rewrite is enabled
   - The `.htaccess` file handles URL rewriting

### Frontend Setup

1. **Configure API endpoint**
   Edit `frontend/assets/js/api.js`:
   ```javascript
   const API_BASE_URL = 'http://localhost/sanie/backend/api';
   ```

2. **Access the application**
   Open your browser and navigate to:
   ```
   http://localhost/sanie/frontend
   ```

## 🎯 Usage

### First Time Setup

1. **Register a new account**
   - Navigate to the frontend
   - Click "Register" tab
   - Fill in your details
   - Submit the form

2. **Create accounts**
   - Go to Settings or use the quick add
   - Add your bank accounts, cash, eSewa, Khalti, etc.

3. **Set up budgets**
   - Go to Budgets page
   - Create monthly budgets for different categories
   - Set alert thresholds

4. **Create savings goals**
   - Go to Goals page
   - Set target amounts and deadlines
   - Track your progress

### Daily Usage

1. **Add transactions**
   - Use the "Quick Add" button
   - Select type (income/expense)
   - Choose category and account
   - Enter amount and description

2. **Monitor dashboard**
   - Check your financial health score
   - Review income vs expense
   - Track budget progress
   - Monitor goal completion

3. **Analyze spending**
   - Use filters to view transactions by category
   - Check budget progress
   - Review AI insights (coming soon)

## 🔐 Security Features

- **Password Hashing**: Bcrypt for secure password storage
- **JWT Authentication**: Token-based authentication with expiration
- **SQL Injection Prevention**: PDO prepared statements
- **XSS Prevention**: Input sanitization and output encoding
- **CORS Configuration**: Controlled cross-origin requests
- **Session Management**: Secure token handling

## 🌐 API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login user
- `GET /api/auth/me` - Get current user
- `PUT /api/auth/update` - Update profile
- `POST /api/auth/change-password` - Change password

### Transactions
- `GET /api/transactions` - List all transactions
- `POST /api/transactions` - Create transaction
- `PUT /api/transactions/{id}` - Update transaction
- `DELETE /api/transactions/{id}` - Delete transaction
- `GET /api/transactions/statistics` - Get statistics

### Accounts
- `GET /api/accounts` - List all accounts
- `POST /api/accounts` - Create account
- `PUT /api/accounts/{id}` - Update account
- `DELETE /api/accounts/{id}` - Delete account

### Categories
- `GET /api/categories` - List all categories
- `POST /api/categories` - Create category
- `PUT /api/categories/{id}` - Update category
- `DELETE /api/categories/{id}` - Delete category

### Budgets
- `GET /api/budgets` - List all budgets
- `POST /api/budgets` - Create budget
- `PUT /api/budgets/{id}` - Update budget
- `DELETE /api/budgets/{id}` - Delete budget
- `GET /api/budgets/{id}/progress` - Get budget progress

### Goals
- `GET /api/goals` - List all goals
- `POST /api/goals` - Create goal
- `PUT /api/goals/{id}` - Update goal
- `DELETE /api/goals/{id}` - Delete goal
- `GET /api/goals/{id}/progress` - Get goal progress
- `POST /api/goals/{id}/contribute` - Add contribution

### Dashboard
- `GET /api/dashboard` - Get dashboard data
- `GET /api/dashboard/quick-stats` - Get quick statistics

## 🎨 UI Features

- **Modern Design**: Clean, professional interface inspired by CRED, Revolut, and Notion
- **Responsive**: Works on desktop, tablet, and mobile devices
- **Dark/Light Theme**: Toggle between themes
- **Smooth Animations**: Fluid transitions and interactions
- **Financial Health Score**: Visual representation of financial health
- **Progress Indicators**: Budget and goal progress bars
- **Toast Notifications**: Real-time feedback for user actions

## 📊 Currency Format

Default currency: **NPR (Nepalese Rupee)**

Format: `Rs 12,500.00`

This can be changed in user settings.

## 🚧 Future Enhancements

- [ ] Google OAuth integration
- [ ] Email verification
- [ ] Two-factor authentication
- [ ] Password reset functionality
- [ ] Import/Export transactions (CSV, Excel)
- [ ] Advanced AI analysis and predictions
- [ ] Monthly financial reports (PDF export)
- [ ] Recurring transactions
- [ ] Bill reminders
- [ ] Receipt uploads
- [ ] Voice notes for transactions
- [ ] Location tracking
- [ ] Advanced charts and visualizations
- [ ] Multi-currency support
- [ ] Multi-language support (Nepali)
- [ ] Backup and restore functionality

## 📝 Development

### Running Locally

1. Start XAMPP/Apache
2. Ensure MySQL is running
3. Navigate to `http://localhost/sanie/frontend`

### Code Structure

- **Backend**: Follows MVC pattern with controllers and models
- **Frontend**: Modular JavaScript with separate files for each feature
- **API**: RESTful design with consistent response format

### Adding New Features

1. **Backend**: Create model, controller, and add routes in `api/index.php`
2. **Frontend**: Create module file, add API client methods, update UI

## 🤝 Contributing

This is a personal finance application designed for production use. Contributions are welcome in the form of:
- Bug fixes
- Feature additions
- UI improvements
- Documentation updates

## 📄 License

This project is created for educational and personal use.

## 👤 Author

Built as a comprehensive personal finance management system with AI-powered insights.

## 🙏 Acknowledgments

- Inspired by modern fintech applications like CRED, Revolut, and Notion
- Built with vanilla PHP and JavaScript for maximum compatibility
- Uses Font Awesome for icons
- Designed with Material Design 3 principles

---

**SanIE** - Track Every Rupee. Master Every Month.
