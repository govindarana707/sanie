import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/database/app_database.dart';
import '../../core/outbox/outbox_command.dart';
import '../../core/outbox/outbox_store.dart';
import '../accounts/accounts_repository.dart';
import 'local_balance_projection.dart';

final transactionRepositoryProvider = Provider<TransactionRepository>((ref) {
  final accounts = ref.watch(accountsRepositoryProvider);
  return TransactionRepository(
    database: accounts.database,
    authenticatedUserId: accounts.authenticatedUserId,
  );
});

class InsufficientFundsException implements Exception {
  const InsufficientFundsException();
}

class TransactionRepository {
  TransactionRepository({
    required this.database,
    required this.authenticatedUserId,
  }) : outbox = OutboxStore(database);

  final AppDatabase database;
  final String? Function() authenticatedUserId;
  final OutboxStore outbox;
  Stream<List<Account>> watchAccounts(String userId) =>
      watchProjectedAccounts(database, userId, activeOnly: true, nprOnly: true);

  Stream<List<Category>> watchCategories(String userId, String type) =>
      (database.select(database.categories)
            ..where(
              (row) =>
                  (row.userId.equals(userId) | row.isSystem.equals(true)) &
                  row.categoryType.equals(type) &
                  row.status.equals('active') &
                  row.deletedAt.isNull(),
            )
            ..orderBy([
              (row) => OrderingTerm.asc(row.sortOrder),
              (row) => OrderingTerm.asc(row.name),
            ]))
          .watch();

  Stream<List<Subcategory>> watchSubcategories(
    String userId,
    String categoryId,
  ) =>
      (database.select(database.subcategories)
            ..where(
              (row) =>
                  row.categoryId.equals(categoryId) &
                  (row.userId.equals(userId) | row.userId.isNull()) &
                  row.status.equals('active') &
                  row.deletedAt.isNull(),
            )
            ..orderBy([
              (row) => OrderingTerm.asc(row.sortOrder),
              (row) => OrderingTerm.asc(row.name),
            ]))
          .watch();

  Stream<List<OutboxCommand>> watchCommands(String userId) =>
      (database.select(database.outboxCommands)..where(
            (row) =>
                row.userId.equals(userId) &
                row.commandType.isIn(const ['create_income', 'create_expense']),
          ))
          .watch();

  Future<String> create({
    required String type,
    required String accountId,
    required String categoryId,
    String? subcategoryId,
    required double amount,
    required DateTime date,
    required String description,
    String? replacingFailedId,
  }) async {
    final userId = authenticatedUserId();
    if (userId == null || userId.isEmpty) {
      throw StateError('Sign in to save a transaction.');
    }
    if (type != 'income' && type != 'expense') {
      throw const FormatException('Invalid transaction type.');
    }
    if (!amount.isFinite ||
        amount <= 0 ||
        (amount * 100 - (amount * 100).round()).abs() > 0.000001) {
      throw const FormatException(
        'Enter an amount greater than zero with up to two decimals.',
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
    return database.transaction(() async {
      final state = await database.syncStateForUser(userId);
      if (state == null) throw StateError('Offline changes are not ready.');
      final account =
          await (database.select(database.accounts)..where(
                (row) =>
                    row.id.equals(accountId) &
                    row.userId.equals(userId) &
                    row.isActive.equals(true) &
                    row.deletedAt.isNull(),
              ))
              .getSingleOrNull();
      if (account == null || account.currency != 'NPR') {
        throw StateError('Choose an active NPR account.');
      }
      final category =
          await (database.select(database.categories)..where(
                (row) =>
                    row.id.equals(categoryId) &
                    (row.userId.equals(userId) | row.isSystem.equals(true)) &
                    row.categoryType.equals(type) &
                    row.status.equals('active') &
                    row.deletedAt.isNull(),
              ))
              .getSingleOrNull();
      if (category == null) {
        throw StateError('Choose an active $type category.');
      }
      if (subcategoryId != null) {
        final sub =
            await (database.select(database.subcategories)..where(
                  (row) =>
                      row.id.equals(subcategoryId) &
                      row.categoryId.equals(categoryId) &
                      (row.userId.equals(userId) | row.userId.isNull()) &
                      row.status.equals('active') &
                      row.deletedAt.isNull(),
                ))
                .getSingleOrNull();
        if (sub == null) {
          throw StateError('Choose a subcategory in this category.');
        }
      }
      final commands =
          await (database.select(database.outboxCommands)..where(
                (row) =>
                    row.userId.equals(userId) &
                    row.commandType.isIn(const [
                      'create_income',
                      'create_expense',
                      'create_transfer',
                    ]) &
                    row.status.isNotIn(const ['completed', 'failed']),
              ))
              .get();
      final available =
          account.balance + (pendingAccountDeltas(commands)[accountId] ?? 0);
      if (type == 'expense' && available < amount) {
        throw const InsufficientFundsException();
      }
      final now = DateTime.now().toUtc();
      final payload = {
        'p_account': accountId,
        'p_category': categoryId,
        'p_subcategory': subcategoryId,
        'p_amount': amount,
        'p_date': dateText,
        'p_description': description.trim(),
        'p_payment_method': account.accountType,
      };
      final command = type == 'income'
          ? OutboxCommandEnvelope.createIncome(
              userId: userId,
              payload: payload,
              dataGeneration: state.dataGeneration,
              createdAt: now,
            )
          : OutboxCommandEnvelope.createExpense(
              userId: userId,
              payload: payload,
              dataGeneration: state.dataGeneration,
              createdAt: now,
            );
      await database
          .into(database.transactions)
          .insert(
            TransactionsCompanion.insert(
              id: command.id,
              userId: userId,
              accountId: Value(accountId),
              fromAccountId: Value(type == 'expense' ? accountId : null),
              toAccountId: Value(type == 'income' ? accountId : null),
              categoryId: Value(categoryId),
              subcategoryId: Value(subcategoryId),
              amount: amount,
              transactionType: type,
              paymentMethod: Value(account.accountType),
              clientRequestId: Value(command.clientRequestId),
              transactionDate: dateText,
              description: Value(description.trim()),
              createdAt: now,
              updatedAt: now,
            ),
          );
      await outbox.enqueue(command);
      if (replacingFailedId != null && type == 'expense') {
        final failed = await database.outboxCommandForUser(
          userId,
          replacingFailedId,
        );
        if (failed != null &&
            failed.commandType == 'create_expense' &&
            failed.status == 'failed' &&
            failed.lastErrorCode == 'INSUFFICIENT_FUNDS') {
          await (database.update(
            database.outboxCommands,
          )..where((row) => row.id.equals(replacingFailedId))).write(
            const OutboxCommandsCompanion(
              lastErrorCode: Value('REPLACED'),
              lastErrorMessage: Value('Replaced with another payment account.'),
            ),
          );
        }
      }
      return command.id;
    });
  }
}
