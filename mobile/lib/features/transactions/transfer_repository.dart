import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/database/app_database.dart';
import '../../core/outbox/outbox_command.dart';
import '../../core/outbox/outbox_store.dart';
import '../accounts/accounts_repository.dart';
import 'transaction_repository.dart';
import 'local_balance_projection.dart';

final transferRepositoryProvider = Provider<TransferRepository>((ref) {
  final accounts = ref.watch(accountsRepositoryProvider);
  return TransferRepository(
    database: accounts.database,
    authenticatedUserId: accounts.authenticatedUserId,
  );
});

class TransferRepository {
  TransferRepository({
    required this.database,
    required this.authenticatedUserId,
  }) : outbox = OutboxStore(database);

  final AppDatabase database;
  final String? Function() authenticatedUserId;
  final OutboxStore outbox;

  Future<String> create({
    required String fromAccountId,
    required String toAccountId,
    required double amount,
    required double fee,
    String? feeCategoryId,
    required DateTime date,
    required String description,
    String? replacingFailedId,
  }) async {
    final userId = authenticatedUserId();
    if (userId == null || userId.isEmpty) {
      throw StateError('Sign in to save a transfer.');
    }
    if (fromAccountId == toAccountId) {
      throw const FormatException('Choose two different accounts.');
    }
    if (!_validMoney(amount) || amount <= 0) {
      throw const FormatException(
        'Enter an amount above zero with up to two decimals.',
      );
    }
    if (!_validMoney(fee) || fee < 0) {
      throw const FormatException(
        'Enter a fee of zero or more with up to two decimals.',
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
      Future<Account?> ownedAccount(String id) =>
          (database.select(database.accounts)..where(
                (row) =>
                    row.id.equals(id) &
                    row.userId.equals(userId) &
                    row.isActive.equals(true) &
                    row.deletedAt.isNull() &
                    row.currency.equals('NPR'),
              ))
              .getSingleOrNull();
      final source = await ownedAccount(fromAccountId);
      final destination = await ownedAccount(toAccountId);
      if (source == null || destination == null) {
        throw StateError('Choose two active NPR accounts.');
      }
      if (fee > 0) {
        if (feeCategoryId == null) {
          throw const FormatException(
            'Choose an expense category for the fee.',
          );
        }
        final category =
            await (database.select(database.categories)..where(
                  (row) =>
                      row.id.equals(feeCategoryId) &
                      (row.userId.equals(userId) | row.isSystem.equals(true)) &
                      row.categoryType.equals('expense') &
                      row.status.equals('active') &
                      row.deletedAt.isNull(),
                ))
                .getSingleOrNull();
        if (category == null) {
          throw StateError('Choose an active expense category for the fee.');
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
          source.balance + (pendingAccountDeltas(commands)[fromAccountId] ?? 0);
      if (available < amount + fee) throw const InsufficientFundsException();
      final now = DateTime.now().toUtc();
      final command = OutboxCommandEnvelope.createTransfer(
        userId: userId,
        dataGeneration: state.dataGeneration,
        createdAt: now,
        payload: {
          'p_from': fromAccountId,
          'p_to': toAccountId,
          'p_amount': amount,
          'p_fee': fee,
          'p_fee_category': fee > 0 ? feeCategoryId : null,
          'p_date': dateText,
          'p_description': description.trim(),
        },
      );
      await database
          .into(database.transactions)
          .insert(
            TransactionsCompanion.insert(
              id: command.id,
              userId: userId,
              accountId: Value(fromAccountId),
              fromAccountId: Value(fromAccountId),
              toAccountId: Value(toAccountId),
              amount: amount,
              transactionType: 'transfer',
              paymentMethod: const Value('transfer'),
              clientRequestId: Value(command.clientRequestId),
              transactionDate: dateText,
              description: Value(description.trim()),
              createdAt: now,
              updatedAt: now,
            ),
          );
      await outbox.enqueue(command);
      if (replacingFailedId != null) {
        final failed = await database.outboxCommandForUser(
          userId,
          replacingFailedId,
        );
        if (failed != null &&
            failed.commandType == 'create_transfer' &&
            failed.status == 'failed' &&
            failed.lastErrorCode == 'INSUFFICIENT_FUNDS') {
          await (database.update(
            database.outboxCommands,
          )..where((row) => row.id.equals(replacingFailedId))).write(
            const OutboxCommandsCompanion(
              lastErrorCode: Value('REPLACED'),
              lastErrorMessage: Value('Replaced with another source account.'),
            ),
          );
        }
      }
      return command.id;
    });
  }

  bool _validMoney(double value) =>
      value.isFinite && (value * 100 - (value * 100).round()).abs() <= 0.000001;
}
