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
   - The API base URL is derived from the current protocol, host, and project subfolder in `assets/js/config.js`
   - Do not hardcode a localhost or LAN address in production application files

## PWA Development and Deployment

- The deployed PWA identity is explicitly rooted at `/sanie/frontend/`: its manifest URL, `id`, `start_url`, manifest scope, service-worker URL, and worker scope all use that path. If the project is intentionally moved to another base path, update those values together before installing it.
- Bump `CACHE_VERSION` in `service-worker.js` whenever a release changes the precached app shell. The active shell stays consistent until the user accepts the fully cached replacement.
- Service workers require a secure context in production. `localhost` is treated as secure for development; a plain-HTTP LAN IP may not register a service worker or expose installation in some browsers. Use HTTPS for production and realistic device testing rather than weakening browser security.
- After changing the service worker, reload/reopen once and use the in-app **Update** action when the new worker is waiting. The action is deferred while a financial form is open or synchronization is active. Pending offline changes do not block a safely installed update and remain in IndexedDB with their original idempotency IDs. Do not clear IndexedDB as part of normal releases.
- If a development browser is still using stale assets, open Chrome DevTools → Application → Service Workers → **Unregister**, then Application → Storage → **Clear site data**. This is a manual development reset and removes local offline data for that origin.

## Features

### Authentication
- User registration and login
- JWT token-based authentication
- Bearer token scoped to the current browser tab with sessionStorage; the app removes legacy localStorage tokens

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

- JWT bearer tokens are kept in sessionStorage (with an in-memory fallback when storage is unavailable), never in IndexedDB or Cache Storage. This reduces persistence but does not protect a token from an XSS running in the same page; moving authentication to HttpOnly cookies would be a separate backend/authentication change.
- All API requests include the JWT token in the Authorization header
- Because authentication uses an Authorization header rather than an ambient cookie, conventional cookie-based CSRF does not apply to these API calls. The backend still validates the authenticated user, record ownership, allowed financial fields, and optimistic-lock versions.
- Cached dashboard, transaction, reference, metadata, and queued-action records are keyed or indexed by the authenticated user ID. Cached snapshots are cleared on logout; unsynced, failed, and conflicted financial changes are retained under their original user scope and hidden from other users.
- IndexedDB keeps one dashboard snapshot, up to 50 recent transactions, the latest scoped metadata values, and up to 50 disposable reference datasets per user. Pending, failed, and conflicted actions are never removed merely because of age.
- Settings -> Offline Data can clear disposable snapshots while always preserving queued financial changes. Browser storage usage is checked only when that settings view is active, and storage persistence can be requested by the user where supported.
- Cached datasets record `cachedAt`; successful queue synchronization records `lastSyncedAt`. Offline screens show the snapshot timestamp instead of presenting cached financial data as current.
- Cache Storage contains only the same-origin application shell and static assets. API responses, authentication endpoints, cross-origin resources, and non-GET requests are never cached by the service worker.
- Values read from IndexedDB are treated as untrusted and sanitized before display or synchronization; backend validation remains authoritative.
- CORS is configured on the backend
- Production must use HTTPS. The current CSP intentionally permits existing inline scripts/styles and approved CDNs for compatibility; removing `unsafe-inline` requires a separate nonce/hash migration.

## Currency Format

All amounts are displayed as:
- Rs 12,500.00 (NPR - Nepalese Rupee)

This can be changed in the settings or by modifying the formatCurrency function.
