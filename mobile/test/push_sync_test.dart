import 'dart:async';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_command.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';

class FakePushSyncTransport implements PushSyncTransport {
  FakePushSyncTransport(this.authenticatedUserId, {this.onInvoke});

  @override
  final String? authenticatedUserId;
  final Future<void> Function(String name, Map<String, dynamic> parameters)?
  onInvoke;
  final calls = <PushSyncRpcCall>[];

  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {
    calls.add(PushSyncRpcCall(name: name, parameters: parameters));
    await onInvoke?.call(name, parameters);
  }
}

void main() {
  late AppDatabase database;
  late OutboxStore outbox;
  late DateTime now;
  const userA = '11111111-1111-4111-8111-111111111111';
  const userB = '22222222-2222-4222-8222-222222222222';

  setUp(() {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(database);
    now = DateTime.utc(2026, 10, 2, 12);
  });
  tearDown(() => database.close());

  OutboxCommandEnvelope income({
    String userId = userA,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope.createIncome(
    userId: userId,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt ?? now,
    dataGeneration: 7,
    payload: const {
      'p_account': 'account-id',
      'p_category': 'income-category-id',
      'p_subcategory': null,
      'p_amount': 25,
      'p_date': '2026-10-02',
      'p_description': 'income',
      'p_payment_method': 'cash',
    },
  );

  OutboxCommandEnvelope expense() => OutboxCommandEnvelope.createExpense(
    userId: userA,
    id: '33333333-3333-4333-8333-333333333333',
    clientRequestId: '44444444-4444-4444-8444-444444444444',
    createdAt: now,
    dataGeneration: 7,
    payload: const {
      'p_account': 'account-id',
      'p_category': 'expense-category-id',
      'p_subcategory': 'subcategory-id',
      'p_amount': 10,
      'p_date': '2026-10-02',
      'p_description': 'expense',
      'p_payment_method': 'cash',
    },
  );

  OutboxCommandEnvelope transfer() => OutboxCommandEnvelope.createTransfer(
    userId: userA,
    id: '55555555-5555-4555-8555-555555555555',
    clientRequestId: '66666666-6666-4666-8666-666666666666',
    createdAt: now,
    dataGeneration: 7,
    payload: const {
      'p_from': 'from-account-id',
      'p_to': 'to-account-id',
      'p_amount': 10,
      'p_fee': 1,
      'p_fee_category': 'fee-category-id',
      'p_date': '2026-10-02',
      'p_description': 'transfer',
    },
  );

  PushSyncService service(
    FakePushSyncTransport transport, {
    int maxBatch = 20,
  }) => PushSyncService(
    outbox: outbox,
    transport: transport,
    maxBatchSize: maxBatch,
    clock: () => now,
  );

  test(
    'income, expense, and transfer map to exact Phase 1 RPC contracts',
    () async {
      final savedIncome = await outbox.enqueue(
        income(
          id: '11111111-1111-4111-8111-111111111112',
          clientRequestId: '22222222-2222-4222-8222-222222222223',
        ),
      );
      final savedExpense = await outbox.enqueue(expense());
      final savedTransfer = await outbox.enqueue(transfer());

      final incomeCall = PushSyncRpcMapper.map(savedIncome);
      final expenseCall = PushSyncRpcMapper.map(savedExpense);
      final transferCall = PushSyncRpcMapper.map(savedTransfer);

      expect(incomeCall.name, 'create_income');
      expect(incomeCall.parameters, {
        'p_id': savedIncome.id,
        'p_account': 'account-id',
        'p_category': 'income-category-id',
        'p_subcategory': null,
        'p_amount': 25,
        'p_date': '2026-10-02',
        'p_description': 'income',
        'p_payment_method': 'cash',
        'p_client_request_id': savedIncome.clientRequestId,
        'p_generation': 7,
      });
      expect(expenseCall.name, 'create_expense');
      expect(
        expenseCall.parameters['p_client_request_id'],
        savedExpense.clientRequestId,
      );
      expect(expenseCall.parameters['p_generation'], 7);
      expect(transferCall.name, 'create_transfer');
      expect(transferCall.parameters, {
        'p_id': savedTransfer.id,
        'p_from': 'from-account-id',
        'p_to': 'to-account-id',
        'p_amount': 10,
        'p_fee': 1,
        'p_fee_category': 'fee-category-id',
        'p_date': '2026-10-02',
        'p_description': 'transfer',
        'p_request': savedTransfer.clientRequestId,
        'p_generation': 7,
      });
    },
  );

  test(
    'successful push completes without locally applying financial effects',
    () async {
      final command = await outbox.enqueue(income());
      final transport = FakePushSyncTransport(userA);

      final result = await service(transport).pushForAuthenticatedUser();

      expect(result.completed, 1);
      expect(
        transport.calls.single.parameters['p_client_request_id'],
        command.clientRequestId,
      );
      expect(
        (await database.outboxCommandById(command.id))?.status,
        OutboxStatus.completed.storageValue,
      );
    },
  );

  test(
    'transient failure retries with the unchanged payload and idempotency key',
    () async {
      final command = await outbox.enqueue(income());
      final transport = FakePushSyncTransport(
        userA,
        onInvoke: (_, _) => throw const PushSyncRpcException(),
      );

      final result = await service(transport).pushForAuthenticatedUser();
      final saved = await database.outboxCommandById(command.id);

      expect(result.retried, 1);
      expect(saved?.status, OutboxStatus.retry.storageValue);
      expect(saved?.payloadJson, command.payloadJson);
      expect(saved?.clientRequestId, command.clientRequestId);
    },
  );

  test(
    'permanent financial errors including insufficient funds do not retry',
    () async {
      final validation = await outbox.enqueue(income());
      final funds = await outbox.enqueue(
        income(
          id: '77777777-7777-4777-8777-777777777777',
          clientRequestId: '88888888-8888-4888-8888-888888888888',
          createdAt: now.add(const Duration(seconds: 1)),
        ),
      );
      var invocation = 0;
      final transport = FakePushSyncTransport(
        userA,
        onInvoke: (_, _) {
          invocation++;
          throw PushSyncRpcException(
            code: invocation == 1 ? 'VALIDATION_ERROR' : 'INSUFFICIENT_FUNDS',
          );
        },
      );

      final result = await service(transport).pushForAuthenticatedUser();

      expect(result.failed, 2);
      expect(
        (await database.outboxCommandById(validation.id))?.status,
        'failed',
      );
      expect((await database.outboxCommandById(funds.id))?.status, 'failed');
      expect(
        OutboxErrorClassifier.classify('DATA_GENERATION_MISMATCH'),
        OutboxErrorDisposition.permanent,
      );
      expect(
        OutboxErrorClassifier.classify('INSUFFICIENT_FUNDS'),
        OutboxErrorDisposition.permanent,
      );
    },
  );

  test('unauthenticated and cross-user queues are never sent', () async {
    final command = await outbox.enqueue(income());
    final unauthenticated = FakePushSyncTransport(null);
    final mismatched = FakePushSyncTransport(userB);

    final unauthenticatedResult = await service(unauthenticated)
        .pushForAuthenticatedUser();
    final mismatchResult = await service(mismatched).pushForAuthenticatedUser();

    expect(unauthenticatedResult.unauthenticated, isTrue);
    expect(unauthenticated.calls, isEmpty);
    expect(mismatchResult.completed, 0);
    expect(mismatched.calls, isEmpty);
    expect((await database.outboxCommandById(command.id))?.status, 'pending');
  });

  test(
    'stale processing is recovered before the bounded sequential batch',
    () async {
      final first = await outbox.enqueue(income());
      final second = await outbox.enqueue(
        income(
          id: '99999999-9999-4999-8999-999999999999',
          clientRequestId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
          createdAt: now.add(const Duration(seconds: 1)),
        ),
      );
      await outbox.markProcessing(
        userId: userA,
        commandId: first.id,
        now: now.subtract(const Duration(minutes: 6)),
      );
      final transport = FakePushSyncTransport(userA);

      final result = await service(
        transport,
        maxBatch: 1,
      ).pushForAuthenticatedUser();

      expect(result.recovered, 1);
      expect(result.completed, 1);
      expect(transport.calls.single.parameters['p_id'], second.id);
      expect((await database.outboxCommandById(first.id))?.status, 'retry');
      expect(
        (await database.outboxCommandById(second.id))?.status,
        'completed',
      );
    },
  );

  test(
    'a failed command does not corrupt later commands in the batch',
    () async {
      final failed = await outbox.enqueue(income());
      final successful = await outbox.enqueue(
        income(
          id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
          clientRequestId: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
          createdAt: now.add(const Duration(seconds: 1)),
        ),
      );
      var calls = 0;
      final transport = FakePushSyncTransport(
        userA,
        onInvoke: (_, _) {
          calls++;
          if (calls == 1) {
            throw const PushSyncRpcException(code: 'VALIDATION_ERROR');
          }
          return Future.value();
        },
      );

      final result = await service(transport).pushForAuthenticatedUser();

      expect(result.failed, 1);
      expect(result.completed, 1);
      expect((await database.outboxCommandById(failed.id))?.status, 'failed');
      expect(
        (await database.outboxCommandById(successful.id))?.status,
        'completed',
      );
    },
  );

  test('concurrent push invocations are guarded and a lost response reuses the key', () async {
    final command = await outbox.enqueue(income());
    final entered = Completer<void>();
    final release = Completer<void>();
    var calls = 0;
    final transport = FakePushSyncTransport(
      userA,
      onInvoke: (_, _) async {
        calls++;
        if (calls == 1) {
          entered.complete();
          await release.future;
          throw const PushSyncRpcException();
        }
      },
    );
    final sync = service(transport);

    final firstRun = sync.pushForAuthenticatedUser();
    await entered.future;
    final concurrent = await sync.pushForAuthenticatedUser();
    release.complete();
    await firstRun;

    now = now.add(const Duration(seconds: 2));
    final replay = await sync.pushForAuthenticatedUser();

    expect(concurrent.skippedConcurrentRun, isTrue);
    expect(replay.completed, 1);
    expect(transport.calls, hasLength(2));
    expect(
      transport.calls[0].parameters['p_client_request_id'],
      command.clientRequestId,
    );
    expect(
      transport.calls[1].parameters['p_client_request_id'],
      command.clientRequestId,
    );
    expect((await database.outboxCommandById(command.id))?.status, 'completed');
  });
}
