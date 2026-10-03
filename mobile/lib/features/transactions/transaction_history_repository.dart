import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/database/app_database.dart';
import '../accounts/accounts_repository.dart';

final transactionHistoryRepositoryProvider =
    Provider<TransactionHistoryRepository>(
      (ref) => TransactionHistoryRepository(
        ref.watch(accountsRepositoryProvider).database,
      ),
    );

final transactionHistoryProvider =
    StreamProvider.family<HistorySnapshot, String>(
      (ref, userId) =>
          ref.watch(transactionHistoryRepositoryProvider).watch(userId),
    );

class HistorySnapshot {
  const HistorySnapshot({
    required this.transactions,
    required this.accounts,
    required this.categories,
    required this.subcategories,
    required this.commands,
  });

  final List<Transaction> transactions;
  final Map<String, Account> accounts;
  final Map<String, Category> categories;
  final Map<String, Subcategory> subcategories;
  final Map<String, OutboxCommand> commands;

  String title(Transaction row) {
    final category = categories[row.categoryId]?.name;
    final subcategory = subcategories[row.subcategoryId]?.name;
    if (row.transactionType == 'transfer') return 'Transfer';
    if (subcategory != null) return subcategory;
    if (category != null) return category;
    final note = row.description?.trim();
    return note == null || note.isEmpty ? typeLabel(row) : note;
  }

  String accountContext(Transaction row) {
    String name(String? id) => accounts[id]?.name ?? 'Unknown account';
    if (row.transactionType == 'transfer') {
      return '${name(row.fromAccountId)} → ${name(row.toAccountId)}';
    }
    return name(row.accountId);
  }

  String? syncState(Transaction row) {
    final status = commands[row.id]?.status;
    if (status == 'failed') return 'Needs attention';
    if (status != null && status != 'completed') return 'Pending sync';
    return null;
  }
}

String typeLabel(Transaction row) => switch (row.transactionType) {
  'income' => 'Income',
  'expense' => 'Expense',
  'transfer' => 'Transfer',
  _ => row.transactionType,
};

class TransactionHistoryRepository {
  const TransactionHistoryRepository(this.database);

  final AppDatabase database;

  Stream<HistorySnapshot> watch(String userId) => database
      .customSelect(
        'SELECT 1',
        readsFrom: {
          database.transactions,
          database.accounts,
          database.categories,
          database.subcategories,
          database.outboxCommands,
        },
      )
      .watch()
      .asyncMap((_) async {
        final rows =
            await (database.select(database.transactions)
                  ..where((t) => t.userId.equals(userId) & t.deletedAt.isNull())
                  ..orderBy([
                    (t) => OrderingTerm.desc(t.transactionDate),
                    (t) => OrderingTerm.desc(t.createdAt),
                    (t) => OrderingTerm.desc(t.id),
                  ]))
                .get();
        final accounts = await (database.select(
          database.accounts,
        )..where((a) => a.userId.equals(userId))).get();
        final categories =
            await (database.select(database.categories)..where(
                  (c) =>
                      c.userId.equals(userId) |
                      (c.userId.isNull() & c.isSystem.equals(true)),
                ))
                .get();
        final subcategories = await (database.select(
          database.subcategories,
        )..where((s) => s.userId.equals(userId) | s.userId.isNull())).get();
        final commands = await (database.select(
          database.outboxCommands,
        )..where((c) => c.userId.equals(userId))).get();
        return HistorySnapshot(
          transactions: rows,
          accounts: {for (final row in accounts) row.id: row},
          categories: {for (final row in categories) row.id: row},
          subcategories: {for (final row in subcategories) row.id: row},
          commands: {for (final row in commands) row.id: row},
        );
      });
}
