import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import 'outbox_command.dart';
import 'outbox_store.dart';

/// Keeps the transaction working copy and durable command in one Drift commit.
/// Account balances remain server-derived; push and pull perform reconciliation.
class TransactionMutationService {
  TransactionMutationService({
    required this.database,
    required this.outbox,
    required this.authenticatedUserId,
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());

  final AppDatabase database;
  final OutboxStore outbox;
  final String? Function() authenticatedUserId;
  final DateTime Function() _clock;

  Future<String> edit({
    required String id,
    required String accountId,
    required String categoryId,
    String? subcategoryId,
    required double amount,
    required DateTime date,
    required String description,
  }) async {
    if (!amount.isFinite ||
        amount <= 0 ||
        (amount * 100 - (amount * 100).round()).abs() > 0.000001) {
      throw const FormatException(
        'Enter a positive amount with at most two decimals.',
      );
    }
    final day = DateTime(date.year, date.month, date.day);
    if (day.year != date.year ||
        day.month != date.month ||
        day.day != date.day) {
      throw const FormatException('Choose a valid date.');
    }
    final dateText =
        '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';
    final userId = _userId();
    return database.transaction(() async {
      final row = await _editable(userId, id);
      await _noUnresolved(userId, id);
      final generation = await _generation(userId);
      final account =
          await (database.select(database.accounts)..where(
                (a) =>
                    a.id.equals(accountId) &
                    a.userId.equals(userId) &
                    a.isActive.equals(true) &
                    a.deletedAt.isNull() &
                    a.currency.equals('NPR'),
              ))
              .getSingleOrNull();
      if (account == null) throw StateError('Choose an active NPR account.');
      final category =
          await (database.select(database.categories)..where(
                (c) =>
                    c.id.equals(categoryId) &
                    c.categoryType.equals(row.transactionType) &
                    (c.userId.equals(userId) |
                        (c.userId.isNull() & c.isSystem.equals(true))) &
                    c.status.equals('active') &
                    c.deletedAt.isNull(),
              ))
              .getSingleOrNull();
      if (category == null) throw StateError('Choose an active category.');
      if (subcategoryId != null) {
        final subcategory =
            await (database.select(database.subcategories)..where(
                  (s) =>
                      s.id.equals(subcategoryId) &
                      s.categoryId.equals(categoryId) &
                      (s.userId.equals(userId) | s.userId.isNull()) &
                      s.status.equals('active') &
                      s.deletedAt.isNull(),
                ))
                .getSingleOrNull();
        if (subcategory == null) {
          throw StateError('Choose a subcategory in this category.');
        }
      }
      final now = _clock();
      final command = OutboxCommandEnvelope.transactionMutation(
        type: OutboxCommandType.updateTransaction,
        userId: userId,
        transactionId: id,
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
        payload: {
          'p_account': accountId,
          'p_category': categoryId,
          'p_subcategory': subcategoryId,
          'p_amount': amount,
          'p_date': dateText,
          'p_description': description.trim(),
          'local_before': _before(row),
        },
      );
      await (database.update(
        database.transactions,
      )..where((t) => t.id.equals(id))).write(
        TransactionsCompanion(
          accountId: Value(accountId),
          fromAccountId: Value(
            row.transactionType == 'expense' ? accountId : null,
          ),
          toAccountId: Value(
            row.transactionType == 'income' ? accountId : null,
          ),
          categoryId: Value(categoryId),
          subcategoryId: Value(subcategoryId),
          amount: Value(amount),
          transactionDate: Value(dateText),
          description: Value(description.trim()),
          updatedAt: Value(now),
        ),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> delete(String id) async {
    final userId = _userId();
    return database.transaction(() async {
      final row = await _editable(userId, id);
      await _noUnresolved(userId, id);
      final generation = await _generation(userId);
      final now = _clock();
      final command = OutboxCommandEnvelope.transactionMutation(
        type: OutboxCommandType.deleteTransaction,
        userId: userId,
        transactionId: id,
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
        payload: {'local_before': _before(row)},
      );
      await (database.update(
        database.transactions,
      )..where((t) => t.id.equals(id))).write(
        TransactionsCompanion(deletedAt: Value(now), updatedAt: Value(now)),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  String _userId() {
    final id = authenticatedUserId();
    if (id == null || id.isEmpty) {
      throw StateError('Authentication is required.');
    }
    return id;
  }

  Future<int> _generation(String userId) async {
    final state = await database.syncStateForUser(userId);
    if (state == null) throw StateError('Offline changes are not ready.');
    return state.dataGeneration;
  }

  Future<Transaction> _editable(String userId, String id) async {
    final row =
        await (database.select(database.transactions)..where(
              (t) =>
                  t.id.equals(id) &
                  t.userId.equals(userId) &
                  t.deletedAt.isNull(),
            ))
            .getSingleOrNull();
    if (row == null ||
        !const {'income', 'expense'}.contains(row.transactionType) ||
        row.karobarTransactionId != null ||
        row.recurringDefinitionId != null) {
      throw StateError('Transaction cannot be edited or deleted.');
    }
    return row;
  }

  Future<void> _noUnresolved(String userId, String id) async {
    final commands =
        await (database.select(database.outboxCommands)..where(
              (c) =>
                  c.userId.equals(userId) &
                  c.commandType.isIn(const [
                    'create_income',
                    'create_expense',
                    'update_transaction',
                    'delete_transaction',
                  ]) &
                  c.status.isIn(const ['pending', 'processing', 'retry']),
            ))
            .get();
    for (final command in commands) {
      final target = command.commandType.startsWith('create_')
          ? command.id
          : (jsonDecode(command.payloadJson) as Map<String, dynamic>)['p_id'];
      if (target == id) {
        throw StateError(
          'Transaction has an unresolved mutation. Sync it first.',
        );
      }
    }
  }

  Map<String, dynamic> _before(Transaction row) => {
    'account_id': row.accountId,
    'from_account_id': row.fromAccountId,
    'to_account_id': row.toAccountId,
    'category_id': row.categoryId,
    'subcategory_id': row.subcategoryId,
    'amount': row.amount,
    'transaction_date': row.transactionDate,
    'description': row.description,
    'updated_at': row.updatedAt.toUtc().toIso8601String(),
    'deleted_at': row.deletedAt?.toUtc().toIso8601String(),
  };
}
