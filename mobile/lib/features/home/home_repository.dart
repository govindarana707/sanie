import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../core/database/app_database.dart';
import '../accounts/accounts_repository.dart';

class HomeIdentity {
  const HomeIdentity({
    required this.userId,
    required this.email,
    required this.displayName,
  });
  final String userId;
  final String email;
  final String displayName;
}

final homeIdentityProvider = Provider<HomeIdentity?>((ref) {
  ref.watch(accountUserProvider);
  final user = Supabase.instance.client.auth.currentUser;
  if (user == null) return null;
  final metadata = user.userMetadata ?? const <String, dynamic>{};
  final rawName =
      metadata['full_name'] ?? metadata['name'] ?? metadata['first_name'];
  final name = rawName is String && rawName.trim().isNotEmpty
      ? rawName.trim()
      : (user.email?.split('@').first ?? 'Friend');
  return HomeIdentity(
    userId: user.id,
    email: user.email ?? '',
    displayName: name,
  );
});

final homeRepositoryProvider = Provider<HomeRepository>(
  (ref) => HomeRepository(ref.watch(accountsRepositoryProvider).database),
);

class HomeSnapshot {
  const HomeSnapshot({
    required this.balance,
    required this.hasAccounts,
    required this.income,
    required this.expense,
    required this.budgetSpent,
    required this.budgetTotal,
    required this.recent,
    this.profileName,
  });

  final double balance;
  final bool hasAccounts;
  final double income;
  final double expense;
  final double budgetSpent;
  final double budgetTotal;
  final List<HomeTransaction> recent;
  final String? profileName;
}

class HomeTransaction {
  const HomeTransaction({required this.title, required this.row});
  final String title;
  final Transaction row;
}

class HomeRepository {
  const HomeRepository(this.database);
  final AppDatabase database;

  Future<HomeSnapshot> load(String userId, DateTime month) async {
    final monthPrefix =
        '${month.year.toString().padLeft(4, '0')}-${month.month.toString().padLeft(2, '0')}';
    final firstDay = '$monthPrefix-01';
    final lastDay = DateTime(month.year, month.month + 1, 0);
    final lastDate = '$monthPrefix-${lastDay.day.toString().padLeft(2, '0')}';
    final accounts = await database.accountsForUser(userId);
    final transactions = await database.transactionsForUser(userId);
    final budgets =
        await (database.select(database.budgets)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.isActive.equals(true) &
                  row.deletedAt.isNull(),
            ))
            .get();
    final categories = await (database.select(
      database.categories,
    )..where((row) => row.userId.equals(userId) | row.userId.isNull())).get();
    final profile = await database.profileById(userId);
    final monthRows = transactions
        .where((row) => row.transactionDate.startsWith(monthPrefix))
        .toList();
    final expenses = monthRows
        .where((row) => row.transactionType == 'expense')
        .toList();
    final activeBudgets = budgets
        .where(
          (row) =>
              row.startDate.compareTo(lastDate) <= 0 &&
              row.endDate.compareTo(firstDay) >= 0,
        )
        .toList();
    final budgetSpent = activeBudgets.fold<double>(
      0,
      (sum, budget) =>
          sum +
          expenses
              .where((row) {
                final date = row.transactionDate.length >= 10
                    ? row.transactionDate.substring(0, 10)
                    : row.transactionDate;
                if (date.compareTo(budget.startDate) < 0 ||
                    date.compareTo(budget.endDate) > 0) {
                  return false;
                }
                if (budget.categoryId != null &&
                    row.categoryId != budget.categoryId) {
                  return false;
                }
                if (budget.subcategoryId != null &&
                    row.subcategoryId != budget.subcategoryId) {
                  return false;
                }
                return true;
              })
              .fold<double>(0, (spent, row) => spent + row.amount),
    );
    final categoryNames = {
      for (final category in categories) category.id: category.name,
    };
    return HomeSnapshot(
      hasAccounts: accounts.any(
        (row) =>
            row.currency == 'NPR' && row.isActive && row.includeInNetBalance,
      ),
      balance: accounts
          .where(
            (row) =>
                row.currency == 'NPR' &&
                row.isActive &&
                row.includeInNetBalance,
          )
          .fold<double>(0, (sum, row) => sum + row.balance),
      income: monthRows
          .where((row) => row.transactionType == 'income')
          .fold<double>(0, (sum, row) => sum + row.amount),
      expense: expenses.fold<double>(0, (sum, row) => sum + row.amount),
      budgetSpent: budgetSpent,
      budgetTotal: activeBudgets.fold<double>(
        0,
        (sum, row) => sum + row.amount,
      ),
      recent: [
        for (final row in transactions.take(4))
          HomeTransaction(
            title: row.description?.trim().isNotEmpty == true
                ? row.description!.trim()
                : categoryNames[row.categoryId] ??
                      _typeTitle(row.transactionType),
            row: row,
          ),
      ],
      profileName: profile?.firstName.trim().isNotEmpty == true
          ? profile!.firstName.trim()
          : null,
    );
  }
}

String _typeTitle(String type) => switch (type) {
  'income' => 'Income',
  'expense' => 'Expense',
  'transfer' => 'Transfer',
  _ => 'Transaction',
};
