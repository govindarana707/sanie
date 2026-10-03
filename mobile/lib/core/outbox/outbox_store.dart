import 'dart:convert';
import 'dart:math';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import 'outbox_command.dart';

class DuplicateClientRequestIdException implements Exception {
  const DuplicateClientRequestIdException();
}

enum OutboxErrorDisposition { retryable, permanent }

class OutboxErrorClassifier {
  static const _permanentCodes = {
    'VALIDATION_ERROR',
    'FORBIDDEN_REFERENCE',
    'NOT_FOUND',
    'CONFLICT',
    'IDEMPOTENCY_MISMATCH',
    'DATA_GENERATION_MISMATCH',
    'BUDGET_SCOPE_OVERLAP',
    'RECURRING_REVIEW_REQUIRED',
    'INVALID_STATE',
    'INSUFFICIENT_FUNDS',
  };

  static OutboxErrorDisposition classify(String? code) =>
      _permanentCodes.contains(code)
      ? OutboxErrorDisposition.permanent
      : OutboxErrorDisposition.retryable;
}

class OutboxRetrySchedule {
  static const maxDelay = Duration(minutes: 5);

  static Duration delayForAttempt(int attemptCount) {
    final exponent = max(0, min(attemptCount - 1, 9));
    return Duration(seconds: min(1 << exponent, maxDelay.inSeconds));
  }

  static DateTime nextAttemptAt({
    required DateTime now,
    required int attemptCount,
  }) => now.add(delayForAttempt(attemptCount));
}

class OutboxStore {
  OutboxStore(this._database);

  final AppDatabase _database;

  Future<OutboxCommand?> commandForUser(String userId, String id) =>
      _database.outboxCommandForUser(userId, id);

  Future<OutboxCommand> enqueue(OutboxCommandEnvelope command) async {
    return _database.transaction(() async {
      final duplicate =
          await (_database.select(_database.outboxCommands)..where(
                (row) => row.clientRequestId.equals(command.clientRequestId),
              ))
              .getSingleOrNull();
      if (duplicate != null) throw const DuplicateClientRequestIdException();

      await _database
          .into(_database.outboxCommands)
          .insert(
            OutboxCommandsCompanion.insert(
              id: command.id,
              userId: command.userId,
              clientRequestId: command.clientRequestId,
              commandType: command.type.rpcName,
              payloadJson: command.payloadJson,
              dataGeneration: command.dataGeneration,
              expectedVersion: Value(command.expectedVersion),
              createdAt: command.createdAt,
              updatedAt: command.createdAt,
            ),
          );
      return (await _database.outboxCommandById(command.id))!;
    });
  }

  Future<List<OutboxCommand>> dueCommandsForUser(
    String userId, {
    required DateTime now,
  }) =>
      (_database.select(_database.outboxCommands)
            ..where(
              (row) =>
                  row.userId.equals(userId) &
                  (row.status.equals(OutboxStatus.pending.storageValue) |
                      (row.status.equals(OutboxStatus.retry.storageValue) &
                          (row.nextAttemptAt.isNull() |
                              row.nextAttemptAt.isSmallerOrEqualValue(now)))),
            )
            ..orderBy([
              (row) => OrderingTerm.asc(row.nextAttemptAt),
              (row) => OrderingTerm.asc(row.createdAt),
            ]))
          .get();

  Future<OutboxCommand> markProcessing({
    required String userId,
    required String commandId,
    required DateTime now,
  }) => _transition(
    userId: userId,
    commandId: commandId,
    allowed: {OutboxStatus.pending, OutboxStatus.retry},
    nextStatus: OutboxStatus.processing,
    now: now,
    incrementAttempts: true,
  );

  Future<OutboxCommand> markRetryableFailure({
    required String userId,
    required String commandId,
    required DateTime now,
    String? errorCode,
    String? errorMessage,
  }) => _transition(
    userId: userId,
    commandId: commandId,
    allowed: {OutboxStatus.processing},
    nextStatus: OutboxStatus.retry,
    now: now,
    errorCode: errorCode,
    errorMessage: errorMessage,
  );

  Future<OutboxCommand> markPermanentFailure({
    required String userId,
    required String commandId,
    required DateTime now,
    required String errorCode,
    String? errorMessage,
  }) => _database.transaction(() async {
    final command = await _transition(
      userId: userId,
      commandId: commandId,
      allowed: {OutboxStatus.processing},
      nextStatus: OutboxStatus.failed,
      now: now,
      errorCode: errorCode,
      errorMessage: errorMessage,
    );
    await _rollbackTransactionWorkingCopy(command);
    return command;
  });

  /// A version increase in the pulled authoritative row resolves an ambiguous
  /// RPC outcome. Matching values mean the command took effect exactly once.
  Future<void> reconcileTransactionMutations(String userId) async {
    final commands =
        await (_database.select(_database.outboxCommands)..where(
              (c) =>
                  c.userId.equals(userId) &
                  c.commandType.isIn(const [
                    'update_transaction',
                    'delete_transaction',
                  ]) &
                  c.status.isIn(const ['retry', 'failed']),
            ))
            .get();
    for (final command in commands) {
      final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
      final id = payload['p_id'] as String;
      final row =
          await (_database.select(_database.transactions)
                ..where((t) => t.id.equals(id) & t.userId.equals(userId)))
              .getSingleOrNull();
      if (row == null || row.version <= (command.expectedVersion ?? 0)) {
        continue;
      }
      final matched = command.commandType == 'delete_transaction'
          ? row.deletedAt != null
          : row.deletedAt == null &&
                row.accountId == payload['p_account'] &&
                row.categoryId == payload['p_category'] &&
                row.subcategoryId == payload['p_subcategory'] &&
                row.amount == (payload['p_amount'] as num).toDouble() &&
                row.transactionDate == payload['p_date'] &&
                row.description == payload['p_description'];
      await (_database.update(
        _database.outboxCommands,
      )..where((c) => c.id.equals(command.id))).write(
        OutboxCommandsCompanion(
          status: Value(matched ? 'completed' : 'failed'),
          updatedAt: Value(DateTime.now().toUtc()),
          lastErrorCode: Value(matched ? null : 'CONFLICT'),
          lastErrorMessage: Value(
            matched ? null : 'Transaction changed on the server.',
          ),
        ),
      );
    }
  }

  Future<void> _rollbackTransactionWorkingCopy(OutboxCommand command) async {
    if (command.commandType != 'update_transaction' &&
        command.commandType != 'delete_transaction') {
      return;
    }
    final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
    final before = payload['local_before'] as Map<String, dynamic>?;
    if (before == null) return;
    final id = payload['p_id'] as String;
    final row =
        await (_database.select(_database.transactions)
              ..where((t) => t.id.equals(id) & t.userId.equals(command.userId)))
            .getSingleOrNull();
    if (row == null || row.version != command.expectedVersion) return;
    final stillOptimistic = command.commandType == 'delete_transaction'
        ? row.deletedAt != null
        : row.accountId == payload['p_account'] &&
              row.categoryId == payload['p_category'] &&
              row.subcategoryId == payload['p_subcategory'] &&
              row.amount == (payload['p_amount'] as num).toDouble() &&
              row.transactionDate == payload['p_date'] &&
              row.description == payload['p_description'];
    if (!stillOptimistic) return;
    await (_database.update(
      _database.transactions,
    )..where((t) => t.id.equals(id) & t.userId.equals(command.userId))).write(
      TransactionsCompanion(
        accountId: Value(before['account_id'] as String?),
        fromAccountId: Value(before['from_account_id'] as String?),
        toAccountId: Value(before['to_account_id'] as String?),
        categoryId: Value(before['category_id'] as String?),
        subcategoryId: Value(before['subcategory_id'] as String?),
        amount: Value((before['amount'] as num).toDouble()),
        transactionDate: Value(before['transaction_date'] as String),
        description: Value(before['description'] as String?),
        updatedAt: Value(DateTime.parse(before['updated_at'] as String)),
        deletedAt: Value(
          before['deleted_at'] == null
              ? null
              : DateTime.parse(before['deleted_at'] as String),
        ),
      ),
    );
  }

  Future<OutboxCommand> markCompleted({
    required String userId,
    required String commandId,
    required DateTime now,
  }) => _transition(
    userId: userId,
    commandId: commandId,
    allowed: {OutboxStatus.processing},
    nextStatus: OutboxStatus.completed,
    now: now,
  );

  Future<int> recoverStaleProcessing({
    required String userId,
    required DateTime staleBefore,
    required DateTime now,
  }) async {
    final stale =
        await (_database.select(_database.outboxCommands)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.status.equals(OutboxStatus.processing.storageValue) &
                  row.lastAttemptAt.isSmallerThanValue(staleBefore),
            ))
            .get();
    for (final command in stale) {
      await _database
          .update(_database.outboxCommands)
          .replace(
            command.copyWith(
              status: OutboxStatus.retry.storageValue,
              updatedAt: now,
              nextAttemptAt: Value(now),
            ),
          );
    }
    return stale.length;
  }

  Future<OutboxCommand> _transition({
    required String userId,
    required String commandId,
    required Set<OutboxStatus> allowed,
    required OutboxStatus nextStatus,
    required DateTime now,
    bool incrementAttempts = false,
    String? errorCode,
    String? errorMessage,
  }) async {
    return _database.transaction(() async {
      final command = await _database.outboxCommandForUser(userId, commandId);
      if (command == null ||
          !allowed.any((status) => status.storageValue == command.status)) {
        throw StateError('Invalid outbox command transition.');
      }

      final attempts = command.attemptCount + (incrementAttempts ? 1 : 0);
      final nextAttempt = nextStatus == OutboxStatus.retry
          ? OutboxRetrySchedule.nextAttemptAt(now: now, attemptCount: attempts)
          : command.nextAttemptAt;
      final updated = command.copyWith(
        status: nextStatus.storageValue,
        attemptCount: attempts,
        updatedAt: now,
        lastAttemptAt: Value(incrementAttempts ? now : command.lastAttemptAt),
        nextAttemptAt: Value(nextAttempt),
        lastErrorCode: Value(errorCode),
        lastErrorMessage: Value(_safeErrorMessage(errorMessage)),
      );
      await _database.update(_database.outboxCommands).replace(updated);
      return updated;
    });
  }

  String? _safeErrorMessage(String? value) {
    if (value == null) return null;
    return value.length <= 256 ? value : value.substring(0, 256);
  }
}
