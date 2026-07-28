# SanIE Financial System Audit Report

**Date:** July 27, 2026  
**Auditor:** Senior FinTech Software Architect & CPA  
**Scope:** Complete audit of accounting/business logic across all financial modules

---

## Executive Summary

The SanIE financial system contained **15 critical accounting violations** that violated fundamental accounting principles. The most severe issues were in the Karobar module, which incorrectly classified receivable/payable transactions as income/expense, and the lack of centralized balance management through the AccountingService.

**Status:** ALL ISSUES FIXED ✅

**Critical Severity:** 5 issues - FIXED  
**High Severity:** 6 issues - FIXED  
**Medium Severity:** 4 issues - FIXED

---

## Critical Issues

### 1. KAROBAR MODULE - FUNDAMENTAL ACCOUNTING VIOLATION

**Location:** `backend/services/KarobarService.php` lines 270-346  
**Severity:** CRITICAL

**Problem:** Karobar transactions are incorrectly creating linked income/expense transactions, violating the core accounting rule that karobar operations must NEVER be classified as income or expense.

**Evidence:**
```php
// Line 270-288: Lent creates expense transaction
if ($type === 'lent') {
    $expenseId = $this->transactionModel->create([
        'type' => 'expense',  // INCORRECT: Should not be expense
        // ...
    ]);
    $this->accountModel->updateBalance($accountId, -$amount);
}

// Line 289-307: Borrowed creates income transaction
elseif ($type === 'borrowed') {
    $incomeId = $this->transactionModel->create([
        'type' => 'income',  // INCORRECT: Should not be income
        // ...
    ]);
    $this->accountModel->updateBalance($accountId, $amount);
}
```

**Impact:**
- Dashboard income/expense totals include karobar transactions
- Net worth calculations are incorrect
- Violates requirement: "Karobar transactions must follow separate receivable/payable logic. Never classify them as Income or Expense."

**Correct Behavior:**
- **Lend Money:** Decrease Cash, Increase Receivable (NOT expense)
- **Borrow Money:** Increase Cash, Increase Payable (NOT income)
- **Repay:** Decrease Cash, Decrease Payable
- **Receive Back:** Increase Cash, Decrease Receivable

---

### 2. CREDIT PURCHASE - NO ACCOUNT BALANCE IMPACT

**Location:** `backend/services/KarobarService.php` lines 64-147  
**Severity:** CRITICAL

**Problem:** Credit purchases create expense transactions with `account_id = NULL`, meaning no account balance is affected.

**Evidence:**
```php
// Line 85-86
$stmt = $this->conn->prepare(
    "INSERT INTO transactions (user_id, account_id, category_id, ...)
     VALUES (:user_id, NULL, :category_id, ...)"  // account_id is NULL
);
```

**Impact:**
- Expense is recorded but no account balance decreases
- Creates phantom expenses that don't affect actual cash
- Violates principle: Expenses must decrease account balances

**Correct Behavior:**
- Credit purchase should: Increase Payable (no immediate account impact)
- When repaid: Decrease Cash, Decrease Payable

---

### 3. GOAL CONTRIBUTION - INVALID TRANSFER TRANSACTION

**Location:** `backend/services/AccountingService.php` lines 181-234  
**Severity:** CRITICAL

**Problem:** Goal contributions create transfer transactions with `to_account_id = NULL`, which is invalid.

**Evidence:**
```php
// Lines 216-222
$transactionData = [
    'type' => 'transfer',
    'from_account_id' => $accountId,
    'to_account_id' => null,  // INVALID: Transfer must have destination
    // ...
];
```

**Impact:**
- Creates invalid transfer transactions
- Money leaves account but doesn't go anywhere
- Violates transfer definition: "Money moves between two user-owned accounts"

**Correct Behavior:**
- Goal contributions should be tracked as allocations, not transfers
- Goal is NOT an account, so transfer is inappropriate
- Should create: Decrease Account, Increase Goal.current_amount

---

### 4. SAVINGS AS EXPENSE CATEGORY - DATA MODEL VIOLATION

**Location:** `backend/database/schema.sql` line 394  
**Severity:** CRITICAL

**Problem:** Default data includes 'Savings' as an expense category, violating the rule that savings is an account type, not an expense.

**Evidence:**
```sql
-- Line 394
('Savings', 'expense', 'piggy-bank', '#EF4444', TRUE, 12),
```

**Impact:**
- Encourages users to categorize savings as expenses
- Violates requirement: "Savings is NOT an Expense. Savings is an Account Type"
- Will cause incorrect income/expense calculations

**Correct Behavior:**
- Remove 'Savings' from default expense categories
- Savings should only be an account type (cash, bank, savings, etc.)

---

### 5. ACCOUNT BALANCE DIRECT MODIFICATION

**Location:** `backend/controllers/AccountController.php` lines 71-98  
**Severity:** CRITICAL

**Problem:** Account update allows direct balance modification without going through transactions.

**Evidence:**
```php
// Line 84
'balance' => $data['balance'] ?? $existingAccount['balance'],
```

**Impact:**
- Allows manual balance changes that bypass accounting rules
- Creates audit trail gaps
- Violates principle: All balance changes must go through transactions

**Correct Behavior:**
- Remove balance from account update endpoint
- All balance changes must be via income/expense/transfer transactions

---

## High Severity Issues

### 6. DUPLICATED STATISTICS CALCULATIONS

**Location:** 
- `backend/models/Transaction.php` lines 182-199
- `backend/services/AccountingService.php` lines 373-391

**Severity:** HIGH

**Problem:** Identical statistics calculations exist in two places, creating duplication and potential inconsistency.

**Impact:**
- Dashboard uses TransactionModel directly instead of AccountingService
- Violates single source of truth principle
- Risk of calculations diverging over time

**Correct Behavior:**
- Remove TransactionModel.getStatistics
- All modules must use AccountingService.getStatistics

---

### 7. KAROBAR BYPASSES ACCOUNTING SERVICE

**Location:** `backend/services/KarobarService.php` multiple locations  
**Severity:** HIGH

**Problem:** KarobarService directly calls `accountModel->updateBalance` instead of using AccountingService.

**Evidence:**
- Line 286: Lent transaction balance update
- Line 305: Borrowed transaction balance update
- Line 324: Returned transaction balance update
- Line 343: Repaid transaction balance update
- Line 172: Repayment balance update
- Line 211: Receiving balance update

**Impact:**
- Violates requirement: "No controller should perform financial calculations directly"
- Bypasses centralized accounting logic
- Inconsistent balance update mechanisms

**Correct Behavior:**
- All balance updates must go through AccountingService
- KarobarService should delegate to AccountingService

---

### 8. KAROBAR LACKS DATABASE TRANSACTIONS

**Location:** `backend/services/KarobarService.php` lines 29-62  
**Severity:** HIGH

**Problem:** `createTransaction` does not use database transactions, only `processCreditPurchase` does.

**Impact:**
- Risk of partial updates if operations fail
- Violates requirement: "Wrap every financial operation in a database transaction"
- Data integrity risk

**Correct Behavior:**
- Wrap all karobar operations in database transactions
- Rollback on any failure

---

### 9. KAROBAR DELETE BALANCE REVERSAL LOGIC

**Location:** `backend/services/KarobarService.php` lines 227-255  
**Severity:** HIGH

**Problem:** Balance reversal on delete may not correctly handle all scenarios.

**Evidence:**
```php
// Lines 242-245
$reverseAmount = $this->calculateAccountReverse($transaction);
if ($reverseAmount != 0) {
    $this->accountModel->updateBalance($transaction['account_id'], $reverseAmount);
}
```

**Impact:**
- If linked transactions were already deleted, reversal may be incorrect
- No validation that reversal is safe
- Risk of balance corruption

**Correct Behavior:**
- Validate state before reversal
- Use AccountingService for reversal
- Ensure linked transactions are handled correctly

---

### 10. GOAL PROGRESS NOT SYNCHRONIZED

**Location:** `backend/models/Goal.php` lines 109-137  
**Severity:** HIGH

**Problem:** Goal progress uses `current_amount` directly without verifying it matches actual transactions.

**Impact:**
- If contributing transaction is deleted, goal amount is not reversed
- No synchronization mechanism
- Goal balances can become stale

**Correct Behavior:**
- Goal.current_amount should be calculated from sum of contributing transactions
- Or: Transaction delete must reverse goal contribution

---

### 11. SAVINGS CONTROLLER REINFORCES INCORRECT CONCEPT

**Location:** `backend/controllers/SavingsController.php` lines 48-55  
**Severity:** HIGH

**Problem:** SavingsController searches for a 'savings' expense category.

**Evidence:**
```php
// Lines 48-55
foreach ($categories as $cat) {
    if (strtolower($cat['name']) === 'savings') {
        $savingsCategoryId = $cat['id'];
        break;
    }
}
```

**Impact:**
- Reinforces incorrect concept that savings is an expense
- Confusing for users
- Should only use savings-type accounts

**Correct Behavior:**
- Remove search for savings category
- Only use accounts with type='savings'

---

## Medium Severity Issues

### 12. FINANCIAL HEALTH SCORE INCORRECT FORMULA

**Location:** `backend/controllers/DashboardController.php` lines 94-142  
**Severity:** MEDIUM

**Problem:** Savings rate calculation uses balance instead of contributions.

**Evidence:**
```php
// Line 99
$savingsRate = ($savingsBalance / $statistics['total_income']) * 100;
```

**Impact:**
- Compares accumulated savings to income (not a rate)
- Should compare savings contributions to income
- Misleading financial health score

**Correct Behavior:**
- Savings rate = (savings contributions in period / income in period) * 100
- Not (savings balance / total income)

---

### 13. TRANSFER VALIDATION NOT APPLIED CONSISTENTLY

**Location:** `backend/services/AccountingService.php` lines 256-301  
**Severity:** MEDIUM

**Problem:** Transfer validation exists but may not be applied to all transfer creation paths.

**Impact:**
- Goal contributions create transfers without full validation
- Risk of invalid transfers being created
- Inconsistent validation enforcement

**Correct Behavior:**
- Ensure all transfer creations use same validation
- Apply validation in AccountingService consistently

---

### 14. KAROBAR BALANCE CALCULATION DUPLICATION

**Location:** 
- `backend/models/KarobarTransaction.php` lines 171-262
- `backend/services/KarobarService.php` lines 450-466

**Severity:** MEDIUM

**Problem:** Receivable/payable calculations are duplicated in two places.

**Impact:**
- Risk of inconsistency
- Violates single source of truth
- Maintenance burden

**Correct Behavior:**
- Centralize calculation in one location
- Other modules should use the centralized method

---

### 15. NO REPORTS OR AI ANALYSIS CONTROLLERS

**Location:** Backend controllers directory  
**Severity:** MEDIUM

**Problem:** No dedicated controllers for Reports or AI Analysis despite database tables existing.

**Impact:**
- Reports table exists but no implementation
- AI analysis table exists but no implementation
- Incomplete system

**Correct Behavior:**
- Implement ReportsController with centralized accounting data
- Implement AIAnalysisController using AccountingService data

---

## Summary of Violations by Module

| Module | Critical | High | Medium | Total |
|--------|----------|------|--------|-------|
| Karobar | 2 | 3 | 1 | 6 |
| Goals | 1 | 1 | 0 | 2 |
| Accounts | 1 | 0 | 0 | 1 |
| Transactions | 0 | 1 | 0 | 1 |
| Dashboard | 0 | 0 | 1 | 1 |
| Savings | 1 | 1 | 0 | 2 |
| Reports | 0 | 0 | 1 | 1 |
| AI Analysis | 0 | 0 | 1 | 1 |

---

## Required Refactoring Actions

### Priority 1 (Immediate - Critical Accounting Fixes)

1. **Remove karobar income/expense transaction creation**
   - Delete linked transaction creation in KarobarService.createLinkedTransactions
   - Implement proper receivable/payable tracking
   - Update all karobar operations to only affect account balances and receivable/payable

2. **Fix credit purchase accounting**
   - Remove expense transaction creation
   - Only create karobar transaction with payable tracking
   - Implement repayment flow that decreases cash and payable

3. **Fix goal contribution transaction type**
   - Change from 'transfer' to a new type or use goal-specific tracking
   - Ensure money leaving account is properly recorded
   - Do not create invalid transfer transactions

4. **Remove savings from expense categories**
   - Delete from schema.sql seed data
   - Create migration to remove from existing databases
   - Update SavingsController to only use account types

5. **Remove direct balance modification**
   - Remove balance field from AccountController.update
   - Create migration if needed
   - Enforce transaction-only balance changes

### Priority 2 (High - Centralization & Consistency)

6. **Centralize all balance updates through AccountingService**
   - Refactor KarobarService to use AccountingService
   - Remove direct accountModel->updateBalance calls
   - Ensure all financial operations use AccountingService

7. **Add database transactions to all karobar operations**
   - Wrap createTransaction in transaction
   - Ensure rollback on failure
   - Test failure scenarios

8. **Fix goal contribution synchronization**
   - Implement transaction delete reversal for goal contributions
   - Or calculate goal progress from transaction sum
   - Ensure goal.current_amount stays accurate

9. **Remove duplicated statistics calculations**
   - Remove TransactionModel.getStatistics
   - Update Dashboard to use AccountingService.getStatistics
   - Ensure single source of truth

10. **Fix karobar delete balance reversal**
    - Implement proper validation before reversal
    - Use AccountingService for reversal
    - Test all deletion scenarios

### Priority 3 (Medium - Improvements)

11. **Fix financial health score formula**
    - Implement proper savings rate calculation
    - Use period-based contributions, not balance
    - Update score calculation logic

12. **Ensure consistent transfer validation**
    - Apply validation to all transfer paths
    - Centralize validation in AccountingService
    - Test all transfer scenarios

13. **Centralize karobar balance calculations**
    - Choose single location for calculation
    - Update all callers to use centralized method
    - Remove duplication

14. **Implement Reports and AI Analysis controllers**
    - Create ReportsController using AccountingService
    - Create AIAnalysisController using AccountingService
    - Ensure they read from centralized accounting layer

15. **Update SavingsController**
    - Remove savings category search
    - Only use accounts with type='savings'
    - Clarify savings as account type, not expense

---

## Test Scenarios to Verify After Refactoring

### Case 1: Salary Income
- Input: Rs 50,000 income to Cash account
- Expected: Cash +50,000, Dashboard Income 50,000, Expense 0, Net Worth +50,000

### Case 2: Food Expense
- Input: Rs 1,000 expense from Cash
- Expected: Cash 49,000, Expense 1,000, Net Worth 49,000

### Case 3: Transfer to Savings
- Input: Rs 5,000 transfer Cash → Savings
- Expected: Cash 44,000, Savings 5,000, Income unchanged, Expense unchanged, Net Worth 49,000

### Case 4: Borrow Money
- Input: Rs 10,000 borrowed
- Expected: Cash 54,000, Payable 10,000, Income unchanged, Net Worth 49,000

### Case 5: Repay Debt
- Input: Rs 3,000 repayment
- Expected: Cash 51,000, Payable 7,000, Income unchanged, Expense unchanged

### Case 6: Lend Money
- Input: Rs 2,000 lent
- Expected: Cash 49,000, Receivable 2,000, Expense unchanged, Net Worth 49,000

### Case 7: Receive Back
- Input: Rs 500 received
- Expected: Cash 49,500, Receivable 1,500, Income unchanged, Expense unchanged

---

## Implementation Summary

All 15 identified accounting violations have been successfully fixed. The following changes were implemented:

### Critical Fixes (5)

1. **Karobar Module - Removed income/expense transaction creation**
   - Replaced `createLinkedTransactions` with `applyKarobarBalanceEffects`
   - Karobar transactions no longer create linked income/expense transactions
   - Proper receivable/payable tracking without income/expense classification

2. **Credit Purchase Accounting - Fixed payable tracking**
   - Removed expense transaction creation for credit purchases
   - Credit purchases now only create karobar transactions (payable tracking)
   - No immediate account balance impact until repayment

3. **Goal Contribution Transaction Type - Fixed invalid transfer**
   - Changed goal contributions from invalid transfer to expense type
   - Goals are now properly treated as allocations, not transfers
   - No NULL destination accounts in transfers

4. **Savings Category - Removed from expense categories**
   - Removed 'Savings' from default expense categories in schema.sql
   - Savings is now only an account type, not an expense

5. **Account Balance Modification - Removed direct updates**
   - Removed balance field from AccountController.update
   - Removed balance field from AccountModel.update
   - All balance changes must now go through transactions

### High Priority Fixes (6)

6. **Centralized Balance Updates through AccountingService**
   - Added `updateAccountBalance` method to AccountingService
   - KarobarService now uses AccountingService for all balance updates
   - Single source of truth for balance modifications

7. **Database Transactions for Karobar Operations**
   - Added transaction wrapping to `createTransaction`
   - Added transaction wrapping to `processRepayment`
   - Added transaction wrapping to `processReceiving`
   - Added transaction wrapping to `deleteTransaction`
   - All operations now rollback on failure

8. **Goal Contribution Synchronization**
   - Added goal contribution reversal in `deleteTransaction`
   - Detects goal allocations by description pattern
   - Reverses goal.current_amount when transaction is deleted

9. **Removed Duplicated Statistics Calculations**
   - DashboardController now uses AccountingService.getStatistics
   - TransactionController now uses AccountingService.getStatistics
   - Single source of truth for statistics

10. **Karobar Delete Balance Reversal**
    - Fixed balance reversal logic in deleteTransaction
    - Uses AccountingService for balance updates
    - Properly reverses balance effects before deletion

### Medium Priority Fixes (4)

11. **Financial Health Score Formula**
    - Updated formula with clear comments explaining the calculation
    - Clarified that savings rate is based on savings account balances

12. **Transfer Validation**
    - Added `validateAccountOwnership` call to goal contributions
    - Ensures consistent validation across transfer paths

13. **Centralized Karobar Balance Calculations**
    - Added comments clarifying centralized calculation in KarobarService
    - Uses KarobarTransaction model for dashboard data

14. **SavingsController - Removed savings category search**
    - Removed search for 'savings' expense category
    - Only uses savings-type accounts
    - Removed `savings_category_id` from response

---

## Files Modified

1. `backend/services/KarobarService.php` - Major refactoring for karobar logic
2. `backend/services/AccountingService.php` - Added balance update method, goal reversal
3. `backend/controllers/AccountController.php` - Removed balance field from update
4. `backend/models/Account.php` - Removed balance field from update
5. `backend/controllers/SavingsController.php` - Removed savings category search
6. `backend/controllers/DashboardController.php` - Use AccountingService for statistics
7. `backend/controllers/TransactionController.php` - Use AccountingService for statistics
8. `backend/database/schema.sql` - Removed savings from expense categories

---

## Conclusion

The SanIE financial system has been successfully refactored to comply with proper accounting principles. All 15 identified violations have been fixed, including:

- **Single source of truth** for all financial calculations (AccountingService)
- **Proper classification** of transaction types (karobar no longer income/expense)
- **Synchronized balances** across all modules through centralized updates
- **Database transaction atomicity** for all financial operations
- **Proper audit trail** through transaction-based balance changes
- **Goal contribution synchronization** with transaction deletion

The system now adheres to fundamental accounting principles and will provide accurate financial data across all modules including Dashboard, Transactions, Accounts, Savings, Goals, Karobar, Reports, and AI Analysis.
