import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import 'tables/core_tables.dart';

part 'app_database.g.dart';

@DriftDatabase(
  tables: [
    Profiles,
    Accounts,
    Categories,
    Subcategories,
    Transactions,
    Budgets,
    Goals,
    People,
    KarobarTransactions,
    RecurringTransactions,
    SyncStates,
    OutboxCommands,
  ],
)
class AppDatabase extends _$AppDatabase {
  AppDatabase() : super(_openConnection());

  AppDatabase.forTesting(super.executor);

  @override
  int get schemaVersion => 3;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (migrator) => migrator.createAll(),
    onUpgrade: (migrator, from, to) async {
      if (from < 1) await migrator.createAll();
      if (from < 2) await migrator.createTable(outboxCommands);
      if (from < 3) {
        final existing = await customSelect(
          "select name from sqlite_master where type='table' and name='subcategories'",
        ).getSingleOrNull();
        if (existing != null) {
          await migrator.alterTable(TableMigration(subcategories));
        }
      }
    },
  );

  String newId() => const Uuid().v4();

  Future<void> upsertProfile(ProfilesCompanion row) =>
      into(profiles).insertOnConflictUpdate(row);

  Future<Profile?> profileById(String userId) => (select(
    profiles,
  )..where((row) => row.id.equals(userId))).getSingleOrNull();

  Future<void> upsertAccount(AccountsCompanion row) =>
      into(accounts).insertOnConflictUpdate(row);

  Future<Account?> accountById(String id) =>
      (select(accounts)..where((row) => row.id.equals(id))).getSingleOrNull();

  Future<List<Account>> accountsForUser(String userId) =>
      (select(accounts)
            ..where((row) => row.userId.equals(userId) & row.deletedAt.isNull())
            ..orderBy([(row) => OrderingTerm.asc(row.name)]))
          .get();

  Future<void> upsertTransaction(TransactionsCompanion row) =>
      into(transactions).insertOnConflictUpdate(row);

  Future<Transaction?> transactionById(String id) => (select(
    transactions,
  )..where((row) => row.id.equals(id))).getSingleOrNull();

  Future<List<Transaction>> transactionsForUser(String userId) =>
      (select(transactions)
            ..where((row) => row.userId.equals(userId) & row.deletedAt.isNull())
            ..orderBy([(row) => OrderingTerm.desc(row.transactionDate)]))
          .get();

  Future<void> markTransactionTombstone({
    required String id,
    required int version,
    required DateTime deletedAt,
    required DateTime updatedAt,
  }) => (update(transactions)..where((row) => row.id.equals(id))).write(
    TransactionsCompanion(
      version: Value(version),
      deletedAt: Value(deletedAt),
      updatedAt: Value(updatedAt),
    ),
  );

  Future<void> upsertSyncState(SyncStatesCompanion row) =>
      into(syncStates).insertOnConflictUpdate(row);

  Future<SyncState?> syncStateForUser(String userId) => (select(
    syncStates,
  )..where((row) => row.userId.equals(userId))).getSingleOrNull();

  Stream<SyncState?> watchSyncStateForUser(String userId) => (select(
    syncStates,
  )..where((row) => row.userId.equals(userId))).watchSingleOrNull();

  Future<OutboxCommand?> outboxCommandById(String id) => (select(
    outboxCommands,
  )..where((row) => row.id.equals(id))).getSingleOrNull();

  Future<OutboxCommand?> outboxCommandForUser(String userId, String id) =>
      (select(outboxCommands)
            ..where((row) => row.userId.equals(userId) & row.id.equals(id)))
          .getSingleOrNull();

  Future<void> clearUserCache(String userId) async {
    await transaction(() async {
      await (delete(accounts)..where((row) => row.userId.equals(userId))).go();
      await (delete(
        categories,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(
        subcategories,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(
        transactions,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(budgets)..where((row) => row.userId.equals(userId))).go();
      await (delete(goals)..where((row) => row.userId.equals(userId))).go();
      await (delete(people)..where((row) => row.userId.equals(userId))).go();
      await (delete(
        karobarTransactions,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(
        recurringTransactions,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(
        syncStates,
      )..where((row) => row.userId.equals(userId))).go();
      await (delete(profiles)..where((row) => row.id.equals(userId))).go();
    });
  }
}

LazyDatabase _openConnection() {
  return LazyDatabase(() async {
    final directory = await getApplicationSupportDirectory();
    return NativeDatabase(File(path.join(directory.path, 'sanie.sqlite')));
  });
}
