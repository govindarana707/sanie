import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/account_mutations.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';

const owner = '11111111-1111-4111-8111-111111111111';
const other = '22222222-2222-4222-8222-222222222222';
final now = DateTime.utc(2026, 10, 3);

void main() {
  late AppDatabase db;
  late OutboxStore outbox;
  late AccountMutationService service;
  Directory? folder;

  Future<void> open({bool disk = false}) async {
    db = AppDatabase.forTesting(
      disk
          ? NativeDatabase(
              File('${folder!.path}${Platform.pathSeparator}data.sqlite'),
            )
          : NativeDatabase.memory(),
    );
    outbox = OutboxStore(db);
    service = AccountMutationService(
      database: db,
      outbox: outbox,
      authenticatedUserId: () => owner,
      clock: () => now,
    );
  }

  setUp(() async {
    await open();
    await db.upsertSyncState(
      SyncStatesCompanion.insert(userId: owner, dataGeneration: const Value(7)),
    );
    for (final id in ['a', 'b']) {
      await db.upsertAccount(
        AccountsCompanion.insert(
          id: id,
          userId: owner,
          name: id,
          accountType: 'cash',
          balance: const Value(150),
          isDefault: Value(id == 'a'),
          createdAt: now,
          updatedAt: now,
        ),
      );
    }
    folder = null;
  });
  tearDown(() async {
    await db.close();
    await folder?.delete(recursive: true);
  });

  test('each flag, default switch, metadata, and rollback', () async {
    final commandId = await service.updateSettings(
      accountId: 'b',
      isDefault: true,
      includeInNetBalance: false,
      includeInSavings: true,
      isActive: true,
    );
    expect((await db.accountById('a'))!.isDefault, false);
    final b = (await db.accountById('b'))!;
    expect(b.isDefault, true);
    expect(b.includeInNetBalance, false);
    expect(b.includeInSavings, true);
    expect(b.balance, 150);
    final command = (await db.outboxCommandById(commandId))!;
    expect(command.status, 'pending');
    expect(command.expectedVersion, 1);
    expect(command.dataGeneration, 7);
    final call = PushSyncRpcMapper.map(command);
    expect(call.name, 'update_account_settings');
    expect(call.parameters['p_request'], command.clientRequestId);
    expect(call.parameters['p_base_version'], 1);
    expect(call.parameters['p_generation'], 7);
    expect(call.parameters.containsKey('local_before'), false);
    await outbox.markProcessing(userId: owner, commandId: commandId, now: now);
    await outbox.markPermanentFailure(
      userId: owner,
      commandId: commandId,
      now: now,
      errorCode: 'CONFLICT',
    );
    expect((await db.accountById('a'))!.isDefault, true);
    expect((await db.accountById('b'))!.isDefault, false);
    expect((await db.accountById('b'))!.includeInNetBalance, true);
    expect((await db.accountById('b'))!.includeInSavings, false);
  });

  test('active is reversible and cannot coexist with default', () async {
    final off = await service.updateSettings(
      accountId: 'b',
      isDefault: false,
      includeInNetBalance: true,
      includeInSavings: false,
      isActive: false,
    );
    expect((await db.accountById('b'))!.isActive, false);
    await outbox.markProcessing(userId: owner, commandId: off, now: now);
    await outbox.markCompleted(userId: owner, commandId: off, now: now);
    final on = await service.updateSettings(
      accountId: 'b',
      isDefault: false,
      includeInNetBalance: true,
      includeInSavings: false,
      isActive: true,
    );
    expect((await db.accountById('b'))!.isActive, true);
    expect((await db.outboxCommandById(on))!.status, 'pending');
    await expectLater(
      service.updateSettings(
        accountId: 'b',
        isDefault: true,
        includeInNetBalance: true,
        includeInSavings: false,
        isActive: false,
      ),
      throwsFormatException,
    );
  });

  test('ownership and unresolved mutation are rejected', () async {
    await db.upsertAccount(
      AccountsCompanion.insert(
        id: 'foreign',
        userId: other,
        name: 'foreign',
        accountType: 'cash',
        createdAt: now,
        updatedAt: now,
      ),
    );
    Future<String> update(String id) => service.updateSettings(
      accountId: id,
      isDefault: false,
      includeInNetBalance: true,
      includeInSavings: false,
      isActive: true,
    );
    await expectLater(update('foreign'), throwsStateError);
    await update('b');
    await expectLater(update('b'), throwsStateError);
  });

  test('offline command survives database reopen', () async {
    folder = await Directory.systemTemp.createTemp('sanie_settings_');
    await db.close();
    await open(disk: true);
    await db.upsertSyncState(
      SyncStatesCompanion.insert(userId: owner, dataGeneration: const Value(7)),
    );
    await db.upsertAccount(
      AccountsCompanion.insert(
        id: 'a',
        userId: owner,
        name: 'a',
        accountType: 'cash',
        isDefault: const Value(true),
        createdAt: now,
        updatedAt: now,
      ),
    );
    final id = await service.updateSettings(
      accountId: 'a',
      isDefault: true,
      includeInNetBalance: false,
      includeInSavings: true,
      isActive: true,
    );
    await db.close();
    await open(disk: true);
    final command = (await db.outboxCommandById(id))!;
    expect(command.status, 'pending');
    expect(
      (jsonDecode(command.payloadJson) as Map)['p_include_in_savings'],
      true,
    );
    expect((await db.accountById('a'))!.includeInSavings, true);
  });

  test(
    'first offline account is default and existing creation still queues',
    () async {
      await db.close();
      await open();
      await db.upsertSyncState(
        SyncStatesCompanion.insert(
          userId: owner,
          dataGeneration: const Value(7),
        ),
      );
      final first = await service.createAccount(
        name: 'Cash',
        accountType: 'cash',
        openingBalance: 25,
      );
      final second = await service.createAccount(
        name: 'Bank',
        accountType: 'bank',
        openingBalance: 10,
      );
      expect((await db.accountById(first))!.isDefault, true);
      expect((await db.accountById(second))!.isDefault, false);
      expect((await db.accountById(first))!.balance, 25);
      expect(
        (await db.outboxCommandById(first))!.commandType,
        'create_account',
      );
    },
  );

  test('authoritative pull resolves a lost response once', () async {
    final id = await service.updateSettings(
      accountId: 'b',
      isDefault: false,
      includeInNetBalance: false,
      includeInSavings: true,
      isActive: true,
    );
    await outbox.markProcessing(userId: owner, commandId: id, now: now);
    await outbox.markRetryableFailure(userId: owner, commandId: id, now: now);
    final local = (await db.accountById('b'))!;
    await db.upsertAccount(
      AccountsCompanion.insert(
        id: local.id,
        userId: owner,
        name: local.name,
        accountType: local.accountType,
        balance: const Value(150),
        isDefault: const Value(false),
        includeInNetBalance: const Value(false),
        includeInSavings: const Value(true),
        version: const Value(2),
        createdAt: now,
        updatedAt: now,
      ),
    );
    await outbox.reconcileAccountSettings(owner);
    expect((await db.outboxCommandById(id))!.status, 'completed');
  });

  test('reactivating the sole account restores its default', () async {
    await db.close();
    await open();
    await db.upsertSyncState(
      SyncStatesCompanion.insert(userId: owner, dataGeneration: const Value(7)),
    );
    await db.upsertAccount(
      AccountsCompanion.insert(
        id: 'only',
        userId: owner,
        name: 'Only',
        accountType: 'cash',
        isActive: const Value(false),
        createdAt: now,
        updatedAt: now,
      ),
    );
    final id = await service.updateSettings(
      accountId: 'only',
      isDefault: false,
      includeInNetBalance: true,
      includeInSavings: false,
      isActive: true,
    );
    expect((await db.accountById('only'))!.isDefault, true);
    expect(
      PushSyncRpcMapper.map((await db.outboxCommandById(id))!)
          .parameters['p_is_default'],
      true,
    );
  });
}
