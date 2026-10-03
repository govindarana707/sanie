import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../core/database/app_database.dart';
import '../accounts/accounts_repository.dart';
import '../transactions/local_balance_projection.dart';

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
      : presentationNameFromEmail(user.email);
  return HomeIdentity(
    userId: user.id,
    email: user.email ?? '',
    displayName: name,
  );
});

String presentationNameFromEmail(String? email) {
  final localPart = email?.split('@').first.trim() ?? '';
  if (localPart.isEmpty) return 'Friend';
  return '${localPart[0].toUpperCase()}${localPart.substring(1)}';
}

final homeRepositoryProvider = Provider<HomeRepository>(
  (ref) => HomeRepository(ref.watch(accountsRepositoryProvider).database),
);

final homeAccountChangesProvider = StreamProvider.family<List<Account>, String>(
  (ref, userId) {
    final database = ref.watch(homeRepositoryProvider).database;
    return (database.select(
      database.accounts,
    )..where((row) => row.userId.equals(userId))).watch();
  },
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
    this.failedExpense,
    this.failedTransfer,
    this.profileName,
  });

  final double balance;
  final bool hasAccounts;
  final double income;
  final double expense;
  final double budgetSpent;
  final double budgetTotal;
  final List<HomeTransaction> recent;
  final FailedExpense? failedExpense;
  final FailedTransfer? failedTransfer;
  final String? profileName;
}

class FailedExpense {
  const FailedExpense({
    required this.id,
    required this.amount,
    required this.categoryId,
    required this.date,
    required this.description,
    this.subcategoryId,
  });
  final String id;
  final String amount;
  final String categoryId;
  final String? subcategoryId;
  final String date;
  final String description;
}

class FailedTransfer {
  const FailedTransfer({
    required this.id,
    required this.toAccountId,
    required this.amount,
    required this.fee,
    required this.date,
    required this.description,
    this.feeCategoryId,
  });
  final String id;
  final String toAccountId;
  final String amount;
  final String fee;
  final String? feeCategoryId;
  final String date;
  final String description;
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
    final allTransactions = await database.transactionsForUser(userId);
    final financeCommands =
        await (database.select(database.outboxCommands)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.commandType.isIn(const [
                    'create_income',
                    'create_expense',
                    'create_transfer',
                  ]),
            ))
            .get();
    FailedExpense? failedExpense;
    FailedTransfer? failedTransfer;
    for (final command in financeCommands.reversed) {
      if (failedTransfer == null &&
          command.commandType == 'create_transfer' &&
          command.status == 'failed' &&
          command.lastErrorCode == 'INSUFFICIENT_FUNDS') {
        final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
        final to = payload['p_to'];
        final date = payload['p_date'];
        if (to is String && date is String) {
          failedTransfer = FailedTransfer(
            id: command.id,
            toAccountId: to,
            amount: '${payload['p_amount']}',
            fee: '${payload['p_fee'] ?? 0}',
            feeCategoryId: payload['p_fee_category'] as String?,
            date: date,
            description: '${payload['p_description'] ?? ''}',
          );
        }
      }
      if (failedExpense != null ||
          command.commandType != 'create_expense' ||
          command.status != 'failed' ||
          command.lastErrorCode != 'INSUFFICIENT_FUNDS') {
        continue;
      }
      final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
      final categoryId = payload['p_category'];
      final date = payload['p_date'];
      if (categoryId is! String || date is! String) {
        continue;
      }
      failedExpense = FailedExpense(
        id: command.id,
        amount: '${payload['p_amount']}',
        categoryId: categoryId,
        subcategoryId: payload['p_subcategory'] as String?,
        date: date,
        description: '${payload['p_description'] ?? ''}',
      );
    }
    final failedIds = {
      for (final command in financeCommands)
        if (command.status == 'failed') command.id,
    };
    final transactions = allTransactions
        .where((row) => !failedIds.contains(row.id))
        .toList();
    final sourceOrder = {
      for (final (index, row) in allTransactions.indexed) row.id: index,
    };
    transactions.sort((a, b) {
      final byDate = b.transactionDate.compareTo(a.transactionDate);
      if (byDate != 0) return byDate;
      final byCreated = b.createdAt.compareTo(a.createdAt);
      return byCreated != 0
          ? byCreated
          : sourceOrder[b.id]!.compareTo(sourceOrder[a.id]!);
    });
    final pendingByAccount = pendingAccountDeltas(financeCommands);
    var pendingTransferFees = 0.0;
    for (final command in financeCommands) {
      if (command.commandType != 'create_transfer' ||
          command.status == 'failed' ||
          command.status == 'completed') {
        continue;
      }
      final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
      if ('${payload['p_date']}'.startsWith(monthPrefix)) {
        pendingTransferFees += (payload['p_fee'] as num?)?.toDouble() ?? 0;
      }
    }
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
          .fold<double>(
            0,
            (sum, row) => sum + row.balance + (pendingByAccount[row.id] ?? 0),
          ),
      income: monthRows
          .where((row) => row.transactionType == 'income')
          .fold<double>(0, (sum, row) => sum + row.amount),
      expense:
          expenses.fold<double>(0, (sum, row) => sum + row.amount) +
          pendingTransferFees,
      budgetSpent: budgetSpent,
      budgetTotal: activeBudgets.fold<double>(
        0,
        (sum, row) => sum + row.amount,
      ),
      recent: [
        for (final row in transactions.take(4))
          HomeTransaction(
            title: row.transactionType == 'transfer'
                ? 'Transfer${row.description?.trim().isNotEmpty == true ? ' · ${row.description!.trim()}' : ''}'
                : row.description?.trim().isNotEmpty == true
                ? row.description!.trim()
                : categoryNames[row.categoryId] ??
                      _typeTitle(row.transactionType),
            row: row,
          ),
      ],
      failedExpense: failedExpense,
      failedTransfer: failedTransfer,
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
