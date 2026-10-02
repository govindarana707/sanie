import 'package:drift/drift.dart' hide isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';

void main() {
  late AppDatabase database;
  final timestamp = DateTime.utc(2026, 10, 2, 12);
  const userA = '11111111-1111-4111-8111-111111111111';
  const userB = '22222222-2222-4222-8222-222222222222';

  setUp(() => database = AppDatabase.forTesting(NativeDatabase.memory()));
  tearDown(() => database.close());

  AccountsCompanion account({
    required String id,
    required String userId,
    int version = 1,
  }) => AccountsCompanion.insert(
    id: id,
    userId: userId,
    name: 'Cash',
    accountType: 'cash',
    version: Value(version),
    createdAt: timestamp,
    updatedAt: timestamp,
  );

  test(
    'creates from zero and preserves UUID, timestamps, and version',
    () async {
      final id = database.newId();
      await database.upsertProfile(
        ProfilesCompanion.insert(
          id: userA,
          firstName: 'Asha',
          createdAt: timestamp,
          updatedAt: timestamp,
        ),
      );
      await database.upsertAccount(account(id: id, userId: userA, version: 7));

      final profile = await database.profileById(userA);
      final saved = await database.accountById(id);

      expect(id, matches(RegExp(r'^[0-9a-f-]{36}$')));
      expect(profile?.dataGeneration, 1);
      expect(saved?.id, id);
      expect(saved?.version, 7);
      expect(saved?.createdAt.isAtSameMomentAs(timestamp), isTrue);
      expect(saved?.updatedAt.isAtSameMomentAs(timestamp), isTrue);
    },
  );

  test('keeps user-scoped rows isolated and represents tombstones', () async {
    const transactionId = '33333333-3333-4333-8333-333333333333';
    await database.upsertAccount(account(id: 'account-a', userId: userA));
    await database.upsertAccount(account(id: 'account-b', userId: userB));
    await database.upsertTransaction(
      TransactionsCompanion.insert(
        id: transactionId,
        userId: userA,
        amount: 25,
        transactionType: 'income',
        transactionDate: '2026-10-02',
        createdAt: timestamp,
        updatedAt: timestamp,
      ),
    );

    expect((await database.accountsForUser(userA)).map((row) => row.id), [
      'account-a',
    ]);
    expect((await database.accountsForUser(userB)).map((row) => row.id), [
      'account-b',
    ]);
    expect(await database.transactionsForUser(userA), hasLength(1));

    await database.markTransactionTombstone(
      id: transactionId,
      version: 2,
      deletedAt: timestamp,
      updatedAt: timestamp,
    );

    expect(
      (await database.transactionById(transactionId))?.deletedAt
          ?.isAtSameMomentAs(timestamp),
      isTrue,
    );
    expect((await database.transactionById(transactionId))?.version, 2);
    expect(await database.transactionsForUser(userA), isEmpty);
  });

  test('persists sync state and clears only the selected user cache', () async {
    await database.upsertAccount(account(id: 'account-a', userId: userA));
    await database.upsertAccount(account(id: 'account-b', userId: userB));
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userA,
        lastCursor: const Value(42),
        dataGeneration: const Value(3),
        lastSuccessfulSyncAt: Value(timestamp),
      ),
    );

    final syncState = await database.syncStateForUser(userA);
    expect(syncState?.lastCursor, 42);
    expect(syncState?.dataGeneration, 3);
    expect(
      syncState?.lastSuccessfulSyncAt?.isAtSameMomentAs(timestamp),
      isTrue,
    );

    await database.clearUserCache(userA);

    expect(await database.accountsForUser(userA), isEmpty);
    expect(await database.syncStateForUser(userA), isNull);
    expect((await database.accountsForUser(userB)).map((row) => row.id), [
      'account-b',
    ]);
  });
}
