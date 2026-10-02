import 'dart:async';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_command.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';
import 'package:sanie/core/sync/sync_orchestrator.dart';

const userA = '11111111-1111-4111-8111-111111111111',
    userB = '22222222-2222-4222-8222-222222222222',
    commandId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    requestId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

class PushFake implements PushSyncTransport {
  PushFake(this.authenticatedUserId, {this.handler});
  @override
  String? authenticatedUserId;
  Future<void> Function(String, Map<String, dynamic>)? handler;
  final calls = <Map<String, dynamic>>[];
  @override
  Future<void> invokeRpc(String n, Map<String, dynamic> p) async {
    calls.add(Map.of(p));
    await handler?.call(n, p);
  }
}

class PullFake implements PullSyncTransport {
  PullFake(this.authenticatedUserId);
  @override
  String? authenticatedUserId;
  final pages = <int, List<PullChange>>{},
      rows = <String, Map<String, dynamic>>{};
  bool fail = false;
  int calls = 0;
  @override
  Future<Map<String, dynamic>?> profile(String id) async => profileRow(id);
  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async {
    calls++;
    if (fail) throw StateError('pull failed');
    return pages[afterCursor] ?? const [];
  }

  @override
  Future<Map<String, dynamic>?> entity(String t, String id) async =>
      rows['$t/$id'];
}

Map<String, dynamic> profileRow(String id) => {
  'id': id,
  'first_name': 'Test',
  'last_name': null,
  'phone': null,
  'avatar_path': null,
  'currency': 'NPR',
  'language': 'en',
  'theme': 'light',
  'notification_preferences': '{}',
  'settings': '{}',
  'data_generation': 1,
  'created_at': '2026-10-02T00:00:00Z',
  'updated_at': '2026-10-02T00:00:00Z',
};
Map<String, dynamic> transactionRow() => {
  'id': commandId,
  'user_id': userA,
  'account_id': 'account',
  'from_account_id': null,
  'to_account_id': null,
  'category_id': 'category',
  'subcategory_id': null,
  'amount': 25,
  'transaction_type': 'income',
  'payment_method': 'cash',
  'karobar_transaction_id': null,
  'client_request_id': requestId,
  'transfer_parent_id': null,
  'goal_id': null,
  'recurring_definition_id': null,
  'recurring_occurrence_date': null,
  'version': 1,
  'created_at': '2026-10-02T00:00:00Z',
  'updated_at': '2026-10-02T00:00:00Z',
  'deleted_at': null,
  'transaction_date': '2026-10-02',
  'description': 'income',
};
PullChange change({int generation = 1}) => PullChange(
  sequence: 1,
  entityType: 'transactions',
  entityId: commandId,
  operation: 'insert',
  entityVersion: 1,
  dataGeneration: generation,
  changedAt: DateTime.utc(2026, 10, 2),
  nextCursor: 1,
);
void main() {
  late AppDatabase db;
  late OutboxStore outbox;
  late PushFake pt;
  late PullFake lt;
  late DateTime now;
  setUp(() {
    db = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(db);
    pt = PushFake(userA);
    lt = PullFake(userA);
    now = DateTime.utc(2026, 10, 2, 12);
  });
  tearDown(() => db.close());
  OutboxCommandEnvelope command() => OutboxCommandEnvelope.createIncome(
    userId: userA,
    id: commandId,
    clientRequestId: requestId,
    createdAt: now,
    dataGeneration: 1,
    payload: const {
      'p_account': 'account',
      'p_category': 'category',
      'p_subcategory': null,
      'p_amount': 25,
      'p_date': '2026-10-02',
      'p_description': 'income',
      'p_payment_method': 'cash',
    },
  );
  SyncOrchestrator sync() => SyncOrchestrator(
    push: PushSyncService(outbox: outbox, transport: pt, clock: () => now),
    pull: PullSyncService(database: db, transport: lt, clock: () => now),
    clock: () => now,
  );
  void authoritative() {
    lt.pages[0] = [change()];
    lt.rows['transactions/$commandId'] = transactionRow();
  }

  test('push success pulls authoritative state and records success', () async {
    await outbox.enqueue(command());
    pt.handler = (_, _) async => authoritative();
    final r = await sync().syncNow();
    expect(r.status, SyncNowStatus.success);
    expect([r.pushed, r.pulled], [1, 1]);
    expect((await db.transactionById(commandId))?.amount, 25);
    expect(
      (await db.syncStateForUser(userA))!.lastSuccessfulSyncAt!
          .isAtSameMomentAs(now),
      isTrue,
    );
  });
  test('retryable and permanent push failures remain represented', () async {
    await outbox.enqueue(command());
    pt.handler = (_, _) => throw const PushSyncRpcException();
    var r = await sync().syncNow();
    expect(r.status, SyncNowStatus.partial);
    expect((await db.outboxCommandById(commandId))?.status, 'retry');
    await db.close();
    db = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(db);
    await outbox.enqueue(command());
    pt = PushFake(
      userA,
      handler: (_, _) =>
          throw const PushSyncRpcException(code: 'VALIDATION_ERROR'),
    );
    r = await sync().syncNow();
    expect(r.failedCommands, 1);
    expect((await db.outboxCommandById(commandId))?.status, 'failed');
  });
  test(
    'push completion survives pull failure and resumes without resend',
    () async {
      await outbox.enqueue(command());
      lt.fail = true;
      var r = await sync().syncNow();
      expect(r.status, SyncNowStatus.partial);
      expect((await db.outboxCommandById(commandId))?.status, 'completed');
      expect((await db.syncStateForUser(userA))?.lastCursor, 0);
      lt.fail = false;
      authoritative();
      r = await sync().syncNow();
      expect(r.status, SyncNowStatus.success);
      expect(pt.calls, hasLength(1));
      await sync().syncNow();
      expect(pt.calls, hasLength(1));
      expect((await db.syncStateForUser(userA))?.lastCursor, 1);
    },
  );
  test('overlapping sync is guarded', () async {
    await outbox.enqueue(command());
    final entered = Completer<void>(), release = Completer<void>();
    pt.handler = (_, _) async {
      entered.complete();
      await release.future;
    };
    final s = sync(), first = s.syncNow();
    await entered.future;
    expect((await s.syncNow()).status, SyncNowStatus.busy);
    release.complete();
    await first;
    expect(pt.calls, hasLength(1));
  });
  test('logout during push prevents pull and cursor mutation', () async {
    await outbox.enqueue(command());
    pt.handler = (_, _) async {
      pt.authenticatedUserId = null;
      lt.authenticatedUserId = null;
    };
    expect((await sync().syncNow()).status, SyncNowStatus.userMismatch);
    expect(lt.calls, 0);
    expect(await db.syncStateForUser(userA), isNull);
  });
  test('generation mismatch requires reconciliation', () async {
    lt.pages[0] = [change(generation: 2)];
    final r = await sync().syncNow();
    expect(r.status, SyncNowStatus.reconciliationRequired);
    final s = await db.syncStateForUser(userA);
    expect([s?.dataGeneration, s?.lastCursor], [1, 0]);
    expect(s?.lastSuccessfulSyncAt, isNull);
  });
  test('lost response retries same key and converges once', () async {
    await outbox.enqueue(command());
    var attempts = 0;
    final keys = <Object?>[];
    pt.handler = (_, p) async {
      attempts++;
      keys.add(p['p_client_request_id']);
      if (attempts == 1) throw const PushSyncRpcException();
      authoritative();
    };
    expect((await sync().syncNow()).status, SyncNowStatus.partial);
    now = now.add(const Duration(seconds: 2));
    expect((await sync().syncNow()).status, SyncNowStatus.success);
    expect(keys, [requestId, requestId]);
    expect((await db.transactionById(commandId))?.amount, 25);
  });
  test('unauthenticated and initial mismatch rejected', () async {
    pt.authenticatedUserId = null;
    lt.authenticatedUserId = null;
    expect((await sync().syncNow()).status, SyncNowStatus.unauthenticated);
    pt.authenticatedUserId = userA;
    lt.authenticatedUserId = userB;
    expect((await sync().syncNow()).status, SyncNowStatus.userMismatch);
  });
}
