# SanIE Frontend

AI-Powered Personal Finance Management System - Frontend

## Setup Instructions

1. **Backend Setup**
   - Ensure the PHP backend is running and accessible at `http://localhost/sanie/backend/api`
   - Import the database schema from `backend/database/schema.sql`
   - Configure the database connection in `backend/config/database.php`

2. **Frontend Setup**
   - The frontend is built with vanilla JavaScript, HTML, and CSS
   - No build process required - simply open `index.html` in a browser
   - For development, use a local web server (e.g., XAMPP, Apache, or Live Server)

3. **Configuration**
   - Update the API base URL in `assets/js/api.js` if your backend is at a different location
   - Current default: `http://localhost/sanie/backend/api`

## Features

### Authentication
- User registration and login
- JWT token-based authentication
- Session persistence with localStorage

### Dashboard
- Financial statistics (income, expense, savings, balance)
- Financial health score with visual indicator
- Recent transactions list
- Budget progress overview
- Savings goals progress

### Transactions
- Full CRUD operations
- Filtering by type, category, account, and date range
- Search functionality
- Transaction categories and accounts

### Budgets
- Create and manage budgets
- Set budget periods (daily, weekly, monthly, yearly)
- Track budget progress with visual indicators
- Alert thresholds for budget overruns

### Goals
- Create savings goals
- Track goal progress
- Add contributions to goals
- Set target amounts and deadlines

### UI Features
- Responsive design for mobile and desktop
- Dark/light theme toggle
- Toast notifications
- Modal dialogs
- Smooth animations and transitions

## File Structure

```
frontend/
├── index.html              # Main HTML file
├── assets/
│   ├── css/
│   │   └── styles.css      # Main stylesheet
│   └── js/
│       ├── api.js          # API client and endpoints
│       ├── auth.js         # Authentication module
│       ├── dashboard.js    # Dashboard module
│       ├── transactions.js # Transactions module
│       ├── budgets.js      # Budgets module
│       ├── goals.js        # Goals module
│       └── app.js          # Main application logic
└── README.md              # This file
```

## Browser Compatibility

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Development

To run the frontend locally:

1. Start your web server (XAMPP/Apache)
2. Navigate to `http://localhost/sanie/frontend`
3. The application will load automatically

## Future Enhancements

The frontend can be upgraded to React.js with:
- Vite for build tooling
- TypeScript for type safety
- Tailwind CSS for styling
- shadcn/ui for components
- Recharts for data visualization
- React Router for navigation
- TanStack Query for data fetching

## Security Notes

- JWT tokens are stored in localStorage (consider using httpOnly cookies for production)
- All API requests include the JWT token in the Authorization header
- Input sanitization is handled on the backend
- CORS is configured on the backend

## Currency Format

All amounts are displayed as:
- Rs 12,500.00 (NPR - Nepalese Rupee)

This can be changed in the settings or by modifying the formatCurrency function.
