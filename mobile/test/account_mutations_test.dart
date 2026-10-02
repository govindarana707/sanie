import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/account_mutations.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';

const userA = '11111111-1111-4111-8111-111111111111';
const userB = '22222222-2222-4222-8222-222222222222';

class AccountPushFake implements PushSyncTransport {
  AccountPushFake(this.authenticatedUserId, {this.handler});
  @override
  String? authenticatedUserId;
  Future<void> Function(String, Map<String, dynamic>)? handler;
  final calls = <PushSyncRpcCall>[];
  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {
    calls.add(PushSyncRpcCall(name: name, parameters: parameters));
    await handler?.call(name, parameters);
  }
}

class AccountPullFake implements PullSyncTransport {
  AccountPullFake(this.userId, this.accountId, this.account);
  final String userId;
  final String accountId;
  final Map<String, dynamic> account;
  @override
  String? get authenticatedUserId => userId;
  @override
  Future<Map<String, dynamic>?> profile(String id) async => null;
  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async => afterCursor == 0
      ? [
          PullChange(
            sequence: 1,
            entityType: 'accounts',
            entityId: accountId,
            operation: 'update',
            entityVersion: 2,
            dataGeneration: 1,
            changedAt: DateTime.utc(2026, 10, 2),
            nextCursor: 1,
          ),
        ]
      : [];
  @override
  Future<Map<String, dynamic>?> entity(String table, String id) async =>
      account;
}

void main() {
  late AppDatabase database;
  late OutboxStore outbox;
  late String? userId;
  Directory? temporaryFolder;
  var now = DateTime.utc(2026, 10, 2, 12);

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(database);
    userId = userA;
    temporaryFolder = null;
    now = DateTime.utc(2026, 10, 2, 12);
    await database.upsertSyncState(
      SyncStatesCompanion.insert(userId: userA, dataGeneration: const Value(1)),
    );
  });
  tearDown(() async {
    await database.close();
    await temporaryFolder?.delete(recursive: true);
  });

  AccountMutationService service() => AccountMutationService(
    database: database,
    outbox: outbox,
    authenticatedUserId: () => userId,
    clock: () => now,
  );
  Future<String> create() => service().createAccount(
    name: '  Daily cash  ',
    accountType: 'cash',
    openingBalance: 100.25,
  );
  PushSyncService push(AccountPushFake transport) =>
      PushSyncService(outbox: outbox, transport: transport, clock: () => now);

  test(
    'offline create commits account and command, then survives restart',
    () async {
      final folder = await Directory.systemTemp.createTemp(
        'sanie_account_test_',
      );
      temporaryFolder = folder;
      await database.close();
      database = AppDatabase.forTesting(
        NativeDatabase(
          File('${folder.path}${Platform.pathSeparator}local.sqlite'),
        ),
      );
      outbox = OutboxStore(database);
      await database.upsertSyncState(
        SyncStatesCompanion.insert(
          userId: userA,
          dataGeneration: const Value(1),
        ),
      );
      final id = await create();
      final queued = await database.outboxCommandById(id);
      expect((await database.accountById(id))?.balance, 100.25);
      expect((await database.accountById(id))?.name, 'Daily cash');
      expect(queued?.status, 'pending');
      expect(queued?.dataGeneration, 1);
      await database.close();
      database = AppDatabase.forTesting(
        NativeDatabase(
          File('${folder.path}${Platform.pathSeparator}local.sqlite'),
        ),
      );
      outbox = OutboxStore(database);
      expect((await database.accountById(id))?.balance, 100.25);
      expect(
        (await database.outboxCommandById(id))?.clientRequestId,
        queued?.clientRequestId,
      );
    },
  );

  test(
    'create push reuses request after lost response and pull converges',
    () async {
      final id = await create();
      final effects = <Object?>{};
      var first = true;
      final transport = AccountPushFake(
        userA,
        handler: (name, params) async {
          expect(name, 'create_account');
          effects.add(params['p_request']);
          if (first) {
            first = false;
            throw const PushSyncRpcException();
          }
        },
      );
      expect((await push(transport).pushForAuthenticatedUser()).retried, 1);
      now = now.add(const Duration(seconds: 2));
      expect((await push(transport).pushForAuthenticatedUser()).completed, 1);
      expect(effects, hasLength(1));
      expect(
        transport.calls.map((call) => call.parameters['p_request']).toSet(),
        hasLength(1),
      );
      expect((await database.outboxCommandById(id))?.status, 'completed');
      final authoritative = {
        'id': id,
        'user_id': userA,
        'name': 'Daily cash',
        'account_type': 'cash',
        'account_number': null,
        'opening_balance': 100.25,
        'balance': 120.25,
        'currency': 'NPR',
        'color': null,
        'icon': null,
        'is_active': true,
        'is_default': false,
        'include_in_savings': false,
        'include_in_net_balance': true,
        'version': 2,
        'created_at': now.toIso8601String(),
        'updated_at': now.toIso8601String(),
        'deleted_at': null,
      };
      final pulled = await PullSyncService(
        database: database,
        transport: AccountPullFake(userA, id, authoritative),
      ).pullForAuthenticatedUser();
      expect(pulled.changes, 1);
      expect((await database.accountById(id))?.balance, 120.25);
      expect((await database.syncStateForUser(userA))?.lastCursor, 1);
    },
  );

  test(
    'edit and archive queue exact versioned RPCs without changing balance',
    () async {
      final id = await create();
      await expectLater(service().archiveAccount(id), throwsStateError);
      expect(
        (await push(
          AccountPushFake(userA),
        ).pushForAuthenticatedUser()).completed,
        1,
      );
      final editId = await service().updateAccount(
        accountId: id,
        name: '  Savings  ',
        accountType: 'savings',
        accountNumber: ' 1234 ',
      );
      await expectLater(service().archiveAccount(id), throwsStateError);
      expect(
        (await push(
          AccountPushFake(userA),
        ).pushForAuthenticatedUser()).completed,
        1,
      );
      final current = (await database.accountById(id))!;
      await database
          .update(database.accounts)
          .replace(current.copyWith(name: 'Savings', version: 2));
      final archiveId = await service().archiveAccount(id);
      final edit = PushSyncRpcMapper.map(
        (await database.outboxCommandById(editId))!,
      );
      final archive = PushSyncRpcMapper.map(
        (await database.outboxCommandById(archiveId))!,
      );
      expect(edit.name, 'update_account');
      expect(edit.parameters['p_name'], 'Savings');
      expect(edit.parameters['p_account_number'], '1234');
      expect(edit.parameters['p_base_version'], 1);
      expect(archive.name, 'archive_account');
      expect(archive.parameters['p_base_version'], 2);
      expect((await database.accountById(id))?.balance, 100.25);
      expect((await database.accountById(id))?.deletedAt, isNull);
    },
  );

  test(
    'conflicts and generation mismatch fail permanently without cache damage',
    () async {
      final id = await create();
      expect(
        (await push(
          AccountPushFake(userA),
        ).pushForAuthenticatedUser()).completed,
        1,
      );
      final editId = await service().updateAccount(
        accountId: id,
        name: 'New name',
        accountType: 'bank',
      );
      final conflictTransport = AccountPushFake(
        userA,
        handler: (_, _) async =>
            throw const PushSyncRpcException(code: 'CONFLICT'),
      );
      expect(
        (await push(conflictTransport).pushForAuthenticatedUser()).failed,
        1,
      );
      final secondId = await create();
      final generationTransport = AccountPushFake(
        userA,
        handler: (_, _) async =>
            throw const PushSyncRpcException(code: 'DATA_GENERATION_MISMATCH'),
      );
      expect(
        (await push(generationTransport).pushForAuthenticatedUser()).failed,
        1,
      );
      expect(
        (await database.outboxCommandById(editId))?.lastErrorCode,
        'CONFLICT',
      );
      expect(
        (await database.outboxCommandById(secondId))?.lastErrorCode,
        'DATA_GENERATION_MISMATCH',
      );
      expect((await database.accountById(id))?.balance, 100.25);
    },
  );

  test(
    'user isolation rejects foreign edits and does not push other queue',
    () async {
      final id = await create();
      userId = userB;
      await database.upsertSyncState(
        SyncStatesCompanion.insert(
          userId: userB,
          dataGeneration: const Value(1),
        ),
      );
      await expectLater(
        service().updateAccount(
          accountId: id,
          name: 'Foreign',
          accountType: 'cash',
        ),
        throwsStateError,
      );
      await expectLater(service().archiveAccount(id), throwsStateError);
      final transport = AccountPushFake(userB);
      expect((await push(transport).pushForAuthenticatedUser()).completed, 0);
      expect(transport.calls, isEmpty);
      expect((await database.outboxCommandById(id))?.status, 'pending');
    },
  );

  test(
    'create validation and missing generation leave no account or command',
    () async {
      await expectLater(
        service().createAccount(
          name: ' ',
          accountType: 'cash',
          openingBalance: 0,
        ),
        throwsFormatException,
      );
      await expectLater(
        service().createAccount(
          name: 'Okay',
          accountType: 'cash',
          openingBalance: 0.001,
        ),
        throwsFormatException,
      );
      expect(await database.accountsForUser(userA), isEmpty);
      expect(await outbox.dueCommandsForUser(userA, now: now), isEmpty);
      userId = userB;
      await expectLater(
        service().createAccount(
          name: 'Okay',
          accountType: 'cash',
          openingBalance: 0,
        ),
        throwsStateError,
      );
    },
  );
}
