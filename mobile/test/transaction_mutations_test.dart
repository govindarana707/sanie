import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/outbox/transaction_mutations.dart';
import 'package:sanie/features/transactions/transaction_repository.dart';

const userId = '11111111-1111-4111-8111-111111111111';
final now = DateTime.utc(2026, 10, 3, 10);

void main() {
  late AppDatabase database;
  late OutboxStore outbox;
  late TransactionMutationService mutations;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(database);
    mutations = TransactionMutationService(
      database: database,
      outbox: outbox,
      authenticatedUserId: () => userId,
      clock: () => now,
    );
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userId,
        dataGeneration: const Value(7),
      ),
    );
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: 'account',
        userId: userId,
        name: 'Cash',
        accountType: 'cash',
        balance: const Value(1000),
        createdAt: now,
        updatedAt: now,
      ),
    );
    for (final type in ['income', 'expense']) {
      await database
          .into(database.categories)
          .insert(
            CategoriesCompanion.insert(
              id: '$type-category',
              userId: const Value(userId),
              name: type,
              categoryType: type,
              createdAt: now,
              updatedAt: now,
            ),
          );
    }
  });
  tearDown(() => database.close());

  Future<void> seed(String type, {String id = 'transaction'}) =>
      database.upsertTransaction(
        TransactionsCompanion.insert(
          id: id,
          userId: userId,
          accountId: const Value('account'),
          categoryId: Value('$type-category'),
          amount: 20,
          transactionType: type,
          transactionDate: '2026-10-02',
          description: const Value('before'),
          createdAt: now,
          updatedAt: now,
        ),
      );

  Future<String> edit(String id, String type) => mutations.edit(
    id: id,
    accountId: 'account',
    categoryId: '$type-category',
    amount: 25.5,
    date: DateTime(2026, 10, 3),
    description: 'after',
  );

  for (final type in ['income', 'expense']) {
    test(
      '$type edit persists local row and exact versioned RPC command',
      () async {
        await seed(type);
        final id = await edit('transaction', type);
        final row = await database.transactionById('transaction');
        final command = await database.outboxCommandById(id);
        final call = PushSyncRpcMapper.map(command!);
        expect(row?.amount, 25.5);
        expect(row?.description, 'after');
        expect(row?.version, 1);
        expect((await database.accountById('account'))?.balance, 1000);
        expect(command.status, 'pending');
        expect(command.expectedVersion, 1);
        expect(command.dataGeneration, 7);
        expect(command.clientRequestId, isNotEmpty);
        expect(call.name, 'update_transaction');
        expect(call.parameters, {
          'p_id': 'transaction',
          'p_base_version': 1,
          'p_account': 'account',
          'p_category': '$type-category',
          'p_subcategory': null,
          'p_amount': 25.5,
          'p_date': '2026-10-03',
          'p_description': 'after',
          'p_generation': 7,
        });
        await expectLater(edit('transaction', type), throwsStateError);
      },
    );
  }

  test('delete uses a local tombstone and exact server contract', () async {
    await seed('expense');
    final id = await mutations.delete('transaction');
    final row = await database.transactionById('transaction');
    final command = await database.outboxCommandById(id);
    expect(row?.deletedAt?.toUtc(), now);
    expect(row?.version, 1);
    expect((await database.accountsForUser(userId)).single.balance, 1000);
    expect(PushSyncRpcMapper.map(command!).parameters, {
      'p_id': 'transaction',
      'p_base_version': 1,
      'p_generation': 7,
    });
    expect(PushSyncRpcMapper.map(command).name, 'delete_transaction');
    expect((await outbox.dueCommandsForUser(userId, now: now)).single.id, id);
  });

  test('permanent failure restores the prior working copy safely', () async {
    await seed('expense');
    final id = await edit('transaction', 'expense');
    await outbox.markProcessing(userId: userId, commandId: id, now: now);
    await outbox.markPermanentFailure(
      userId: userId,
      commandId: id,
      now: now,
      errorCode: 'INSUFFICIENT_FUNDS',
    );
    final row = await database.transactionById('transaction');
    expect(row?.amount, 20);
    expect(row?.description, 'before');
    expect((await database.outboxCommandById(id))?.status, 'failed');
    final deleteId = await mutations.delete('transaction');
    await outbox.markProcessing(userId: userId, commandId: deleteId, now: now);
    await outbox.markPermanentFailure(
      userId: userId,
      commandId: deleteId,
      now: now,
      errorCode: 'CONFLICT',
    );
    expect((await database.transactionById('transaction'))?.deletedAt, isNull);
  });

  test('ambiguous retry reconciles after authoritative pull', () async {
    await seed('income');
    final id = await edit('transaction', 'income');
    await outbox.markProcessing(userId: userId, commandId: id, now: now);
    await outbox.markRetryableFailure(userId: userId, commandId: id, now: now);
    await database.upsertTransaction(
      TransactionsCompanion.insert(
        id: 'transaction',
        userId: userId,
        accountId: const Value('account'),
        categoryId: const Value('income-category'),
        amount: 25.5,
        transactionType: 'income',
        transactionDate: '2026-10-03',
        description: const Value('after'),
        version: const Value(2),
        createdAt: now,
        updatedAt: now,
      ),
    );
    await outbox.reconcileTransactionMutations(userId);
    expect((await database.outboxCommandById(id))?.status, 'completed');
    expect(
      await outbox.dueCommandsForUser(
        userId,
        now: now.add(const Duration(minutes: 10)),
      ),
      isEmpty,
    );
  });

  test('offline edit and outbox survive database reopen', () async {
    final directory = await Directory.systemTemp.createTemp('sanie-mutation-');
    final file = File('${directory.path}${Platform.pathSeparator}local.sqlite');
    var disk = AppDatabase.forTesting(NativeDatabase(file));
    try {
      await disk.upsertSyncState(
        SyncStatesCompanion.insert(
          userId: userId,
          dataGeneration: const Value(7),
        ),
      );
      await disk.upsertAccount(
        AccountsCompanion.insert(
          id: 'account',
          userId: userId,
          name: 'Cash',
          accountType: 'cash',
          createdAt: now,
          updatedAt: now,
        ),
      );
      await disk
          .into(disk.categories)
          .insert(
            CategoriesCompanion.insert(
              id: 'income-category',
              userId: const Value(userId),
              name: 'Income',
              categoryType: 'income',
              createdAt: now,
              updatedAt: now,
            ),
          );
      await disk.upsertTransaction(
        TransactionsCompanion.insert(
          id: 'transaction',
          userId: userId,
          accountId: const Value('account'),
          categoryId: const Value('income-category'),
          amount: 20,
          transactionType: 'income',
          transactionDate: '2026-10-02',
          createdAt: now,
          updatedAt: now,
        ),
      );
      final service = TransactionMutationService(
        database: disk,
        outbox: OutboxStore(disk),
        authenticatedUserId: () => userId,
        clock: () => now,
      );
      final commandId = await service.edit(
        id: 'transaction',
        accountId: 'account',
        categoryId: 'income-category',
        amount: 25.5,
        date: DateTime(2026, 10, 3),
        description: 'offline',
      );
      await disk.close();
      disk = AppDatabase.forTesting(NativeDatabase(file));
      expect(
        (await disk.transactionById('transaction'))?.description,
        'offline',
      );
      expect((await disk.outboxCommandById(commandId))?.status, 'pending');
      expect(
        (await OutboxStore(
          disk,
        ).dueCommandsForUser(userId, now: now)).single.id,
        commandId,
      );
    } finally {
      await disk.close();
      await directory.delete(recursive: true);
    }
  });

  test(
    'transfer edit is rejected and existing creation still queues',
    () async {
      await seed('transfer');
      await expectLater(edit('transaction', 'income'), throwsStateError);
      final repository = TransactionRepository(
        database: database,
        authenticatedUserId: () => userId,
      );
      final id = await repository.create(
        type: 'income',
        accountId: 'account',
        categoryId: 'income-category',
        amount: 10,
        date: DateTime(2026, 10, 3),
        description: 'new income',
      );
      expect((await database.transactionById(id))?.amount, 10);
      expect(
        (await database.outboxCommandById(id))?.commandType,
        'create_income',
      );
    },
  );
}
