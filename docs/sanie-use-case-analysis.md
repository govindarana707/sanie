# SanIE Use Case Analysis

This analysis is based on the implemented project rather than the feature list in the root README. The inspected scope included the complete file inventory, the backend API router, every backend controller and model/service surface, the canonical database schema, all registered frontend routes and feature modules, and the backend/frontend integrity tests.

## Actors

- **Guest** — can register, log in, request a password reset, validate the reset token, and set a new password.
- **Authenticated User** — the only implemented application role. All finance, planning, task, notification, and settings records are scoped to this user's ID. The diagram repeats this actor on the right solely to reduce association-line crossings; it does not represent a second role.
- **Email Delivery Service** — the production password-reset implementation sends a reset link through a configured SMTP transport. In development, the same delivery abstraction writes the reset record to a protected development log.

No administrator, staff, auditor, or approver role exists in the authorization middleware, JWT payload, API router, or user schema.

## Main implemented use cases

- Register Account; Log In; Recover Password
- View Financial Dashboard
- Manage Transactions, including income, expense, account transfer, linked credit purchase, filtering, bulk delete, CSV import/export, and printable reports
- Manage Recurring Transactions, including activation, due processing, review, and reconciliation
- Manage Accounts and Statements
- Manage Categories and Subcategories
- Manage Budgets, including progress, suggestions, bulk creation, and month copying
- Manage Savings Goals and Contributions
- Review Savings Overview
- Analyze Financial Health and Trends
- View and Export Financial Reports
- Manage Karobar People and Credit/Debt records, repayments, receiving, and ledgers
- View Karobar Reports and Analysis
- Manage Tasks and the Board Study Plan, including reminders, completion, soft deletion, and restore
- Manage Notifications
- Manage Profile, Security, and Preferences, including avatar, password, theme, account settings, and offline-data controls

## UML relationships

- `Recover Password` **<<include>>** `Send Password Reset Link`: reset-link delivery is a required part of the implemented password-recovery request.
- No **<<extend>>** relationship is used. Optional operations such as CSV import/export, budget copying, goal contributions, and report printing are intentionally folded into their meaningful high-level management use cases rather than modeled as extension fragments.

## Intentionally excluded

- **Google OAuth, two-factor authentication, email verification workflow, receipt uploads, voice notes, location tracking, multi-currency, and Nepali localization**: mentioned only as future ideas or represented by unused schema support; there is no complete accessible route/UI flow.
- **Generic “AI predictions”**: the current analysis modules calculate rule-based scores, trends, insights, and recommendations from stored finance data. The diagram names the implemented analysis behavior and does not imply an external AI provider or forecasting service.
- **Attachments, reports history, AI-analysis history, and activity-log tables**: schema presence alone is not treated as a user-facing use case because no complete controller/frontend workflow exposes them.
- **PWA installation, caching, retry logic, offline queueing/synchronization, responsive layout, theme rendering, security middleware, migrations, deployment checks, and backups**: these are platform qualities, technical mechanisms, or operator/developer concerns rather than the user's business goals. Theme and offline-data controls remain covered under preferences.
- **Global search**: implemented as navigation/filter assistance across existing modules, so it is treated as supporting behavior rather than a separate business use case.

## Verification

- Every actor is outside the system boundary.
- Every use case ellipse is inside the single boundary labeled **SanIE – Personal Finance Management System**.
- Multiple actors are distributed on the left and right. The Authenticated User is repeated outside the boundary to reduce crossings, following common UML readability practice.
- All registered user-facing modules are represented, while internal infrastructure and unimplemented roadmap items are excluded.
- The single include relationship uses a dashed dependency arrow toward the included use case; no decorative or flowchart arrows are used.
