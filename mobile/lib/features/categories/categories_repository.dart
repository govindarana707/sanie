import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/database/app_database.dart';
import '../../core/outbox/category_mutations.dart';
import '../../core/outbox/outbox_store.dart';
import '../../core/sync/sync_providers.dart';
import '../accounts/accounts_repository.dart';

final categoriesRepositoryProvider = Provider<CategoriesRepository>((ref) {
  final accounts = ref.watch(accountsRepositoryProvider);
  return CategoriesRepository(
    database: accounts.database,
    authenticatedUserId: accounts.authenticatedUserId,
  );
});

/// Refreshes the read-only system snapshot when this area opens. Cached rows
/// remain usable if the device is offline.
final categoriesBootstrapProvider = FutureProvider.family<void, String>((
  ref,
  userId,
) async {
  await ref.watch(syncInitializerProvider(userId).future);
  await ref.watch(pullSyncServiceProvider).bootstrapSystemRows(userId);
});

class CategoriesRepository {
  CategoriesRepository({
    required this.database,
    required this.authenticatedUserId,
  }) : mutations = CategoryMutationService(
         database: database,
         outbox: OutboxStore(database),
         authenticatedUserId: authenticatedUserId,
       );

  final AppDatabase database;
  final String? Function() authenticatedUserId;
  final CategoryMutationService mutations;

  Stream<List<Category>> watchCategories(String userId) =>
      (database.select(database.categories)
            ..where(
              (row) =>
                  (row.userId.equals(userId) | row.isSystem.equals(true)) &
                  row.deletedAt.isNull(),
            )
            ..orderBy([
              (row) => OrderingTerm.asc(row.categoryType),
              (row) => OrderingTerm.asc(row.sortOrder),
              (row) => OrderingTerm.asc(row.name),
            ]))
          .watch();

  Stream<Category?> watchCategory(String userId, String id) =>
      (database.select(database.categories)..where(
            (row) =>
                row.id.equals(id) &
                (row.userId.equals(userId) | row.isSystem.equals(true)) &
                row.deletedAt.isNull(),
          ))
          .watchSingleOrNull();

  Stream<List<Subcategory>> watchSubcategories(
    String userId,
    String parentId,
  ) =>
      (database.select(database.subcategories)
            ..where(
              (row) =>
                  row.categoryId.equals(parentId) &
                  (row.userId.equals(userId) | row.userId.isNull()) &
                  row.deletedAt.isNull(),
            )
            ..orderBy([
              (row) => OrderingTerm.asc(row.sortOrder),
              (row) => OrderingTerm.asc(row.name),
            ]))
          .watch();

  Stream<Subcategory?> watchSubcategory(
    String userId,
    String parentId,
    String id,
  ) =>
      (database.select(database.subcategories)..where(
            (row) =>
                row.id.equals(id) &
                row.categoryId.equals(parentId) &
                (row.userId.equals(userId) | row.userId.isNull()) &
                row.deletedAt.isNull(),
          ))
          .watchSingleOrNull();

  Stream<List<OutboxCommand>> watchCommands(String userId) =>
      (database.select(database.outboxCommands)..where(
            (row) =>
                row.userId.equals(userId) &
                row.commandType.isIn(const [
                  'create_category',
                  'update_category',
                  'archive_category',
                  'create_subcategory',
                  'update_subcategory',
                  'archive_subcategory',
                ]),
          ))
          .watch();
}

enum CategoryQueueState { none, pending, failed }

CategoryQueueState categoryQueueState(List<OutboxCommand> commands, String id) {
  var result = CategoryQueueState.none;
  for (final command in commands) {
    if (command.status == 'completed') continue;
    final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
    if (payload['p_id'] != id) continue;
    if (command.status == 'failed') return CategoryQueueState.failed;
    result = CategoryQueueState.pending;
  }
  return result;
}
