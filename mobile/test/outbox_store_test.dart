import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_command.dart';
import 'package:sanie/core/outbox/outbox_store.dart';

void main() {
  late AppDatabase database;
  late OutboxStore outbox;
  final now = DateTime.utc(2026, 10, 2, 12);
  const userA = '11111111-1111-4111-8111-111111111111';
  const userB = '22222222-2222-4222-8222-222222222222';

  setUp(() {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(database);
  });
  tearDown(() => database.close());

  OutboxCommandEnvelope income({
    required String userId,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope.createIncome(
    userId: userId,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt ?? now,
    dataGeneration: 3,
    payload: const {
      'p_account': 'account-id',
      'p_category': 'category-id',
      'p_amount': 25,
      'p_date': '2026-10-02',
    },
  );

  test(
    'enqueue persists UUID idempotency and generation exactly once',
    () async {
      final command = income(userId: userA);
      final saved = await outbox.enqueue(command);

      expect(command.id, matches(RegExp(r'^[0-9a-f-]{36}$')));
      expect(command.clientRequestId, matches(RegExp(r'^[0-9a-f-]{36}$')));
      expect(saved.clientRequestId, command.clientRequestId);
      expect(saved.payloadJson, command.payloadJson);
      expect(saved.dataGeneration, 3);
      expect(saved.status, OutboxStatus.pending.storageValue);

      await expectLater(
        outbox.enqueue(
          income(
            userId: userA,
            id: '33333333-3333-4333-8333-333333333333',
            clientRequestId: command.clientRequestId,
          ),
        ),
        throwsA(isA<DuplicateClientRequestIdException>()),
      );
    },
  );

  test('typed envelopes retain their financial command identities', () {
    final expense = OutboxCommandEnvelope.createExpense(
      userId: userA,
      dataGeneration: 3,
      payload: const {'p_amount': 10},
    );
    final transfer = OutboxCommandEnvelope.createTransfer(
      userId: userA,
      dataGeneration: 3,
      payload: const {'p_amount': 10},
    );

    expect(expense.type, OutboxCommandType.createExpense);
    expect(expense.type.rpcName, 'create_expense');
    expect(transfer.type, OutboxCommandType.createTransfer);
    expect(transfer.type.rpcName, 'create_transfer');
  });

  test('known stable financial errors are permanent', () {
    const permanentCodes = {
      'VALIDATION_ERROR',
      'FORBIDDEN_REFERENCE',
      'NOT_FOUND',
      'CONFLICT',
      'IDEMPOTENCY_MISMATCH',
      'DATA_GENERATION_MISMATCH',
      'BUDGET_SCOPE_OVERLAP',
      'RECURRING_REVIEW_REQUIRED',
      'INVALID_STATE',
    };

    for (final code in permanentCodes) {
      expect(
        OutboxErrorClassifier.classify(code),
        OutboxErrorDisposition.permanent,
      );
    }
    expect(
      OutboxErrorClassifier.classify('NETWORK_UNAVAILABLE'),
      OutboxErrorDisposition.retryable,
    );
  });

  test('retry transitions preserve payload and client request id', () async {
    final command = income(userId: userA);
    await outbox.enqueue(command);

    final processing = await outbox.markProcessing(
      userId: userA,
      commandId: command.id,
      now: now,
    );
    final retry = await outbox.markRetryableFailure(
      userId: userA,
      commandId: command.id,
      now: now,
      errorCode: 'NETWORK_UNAVAILABLE',
      errorMessage: 'temporarily unavailable',
    );

    expect(processing.attemptCount, 1);
    expect(retry.status, OutboxStatus.retry.storageValue);
    expect(retry.clientRequestId, command.clientRequestId);
    expect(retry.payloadJson, command.payloadJson);
    expect(
      retry.nextAttemptAt?.isAtSameMomentAs(
        now.add(const Duration(seconds: 1)),
      ),
      isTrue,
    );
    expect(
      OutboxRetrySchedule.delayForAttempt(20),
      OutboxRetrySchedule.maxDelay,
    );
  });

  test('due ordering and user scoping do not mix command queues', () async {
    final first = income(
      userId: userA,
      id: '33333333-3333-4333-8333-333333333331',
    );
    final second = income(
      userId: userA,
      id: '33333333-3333-4333-8333-333333333332',
      createdAt: now.add(const Duration(seconds: 1)),
    );
    final otherUser = income(userId: userB);
    await outbox.enqueue(first);
    await outbox.enqueue(second);
    await outbox.enqueue(otherUser);

    final dueForA = await outbox.dueCommandsForUser(userA, now: now);
    final dueForB = await outbox.dueCommandsForUser(userB, now: now);

    expect(dueForA.map((row) => row.id), [first.id, second.id]);
    expect(dueForB.map((row) => row.id), [otherUser.id]);
  });

  test(
    'permanent failures and stale processing recovery are explicit',
    () async {
      final failed = income(userId: userA);
      final stale = income(userId: userA);
      await outbox.enqueue(failed);
      await outbox.enqueue(stale);
      await outbox.markProcessing(
        userId: userA,
        commandId: failed.id,
        now: now,
      );
      await outbox.markProcessing(userId: userA, commandId: stale.id, now: now);

      final failedRow = await outbox.markPermanentFailure(
        userId: userA,
        commandId: failed.id,
        now: now,
        errorCode: 'VALIDATION_ERROR',
        errorMessage: 'invalid amount',
      );
      final recovered = await outbox.recoverStaleProcessing(
        userId: userA,
        staleBefore: now.add(const Duration(minutes: 1)),
        now: now.add(const Duration(minutes: 2)),
      );

      expect(failedRow.status, OutboxStatus.failed.storageValue);
      expect(
        OutboxErrorClassifier.classify('VALIDATION_ERROR'),
        OutboxErrorDisposition.permanent,
      );
      expect(recovered, 1);
      expect(
        (await database.outboxCommandForUser(userA, stale.id))?.status,
        OutboxStatus.retry.storageValue,
      );
    },
  );

  test(
    'schema version one upgrades without losing cached account data',
    () async {
      final executor = NativeDatabase.memory(
        setup: (sqlite) {
          sqlite.execute('PRAGMA user_version = 1;');
          sqlite.execute('''
        CREATE TABLE accounts (
          id TEXT NOT NULL PRIMARY KEY, user_id TEXT NOT NULL, name TEXT NOT NULL,
          account_type TEXT NOT NULL, account_number TEXT, opening_balance REAL NOT NULL DEFAULT 0,
          balance REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'NPR', color TEXT,
          icon TEXT, is_active INTEGER NOT NULL DEFAULT 1, is_default INTEGER NOT NULL DEFAULT 0,
          include_in_savings INTEGER NOT NULL DEFAULT 0,
          include_in_net_balance INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1,
          created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, deleted_at INTEGER
        );
      ''');
          sqlite.execute('''
        INSERT INTO accounts (id, user_id, name, account_type, created_at, updated_at)
        VALUES ('legacy-account', '$userA', 'Migrated cash', 'cash', 0, 0);
      ''');
        },
      );
      await database.close();
      database = AppDatabase.forTesting(executor);
      outbox = OutboxStore(database);

      final account = await database.accountById('legacy-account');
      await outbox.enqueue(income(userId: userA));

      expect(account?.name, 'Migrated cash');
      expect(
        await database.outboxCommandById(
          (await outbox.dueCommandsForUser(userA, now: now)).single.id,
        ),
        isNotNull,
      );
    },
  );
}
