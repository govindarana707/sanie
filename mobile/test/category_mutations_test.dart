import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/category_mutations.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';

const user = '11111111-1111-4111-8111-111111111111';
const other = '22222222-2222-4222-8222-222222222222';
const systemId = '33333333-3333-4333-8333-333333333333';
const systemSubId = '44444444-4444-4444-8444-444444444444';

class _Pull implements PullSyncTransport {
  String? userId = user;
  @override
  String? get authenticatedUserId => userId;
  @override
  Future<Map<String, dynamic>?> profile(String id) async => null;
  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async => [];
  @override
  Future<Map<String, dynamic>?> entity(String table, String id) async => null;
}

class _Systems implements SystemCategoryTransport {
  String? userId = user;
  bool removed = false;
  @override
  String? get authenticatedUserId => userId;
  @override
  Future<List<Map<String, dynamic>>> systemCategories() async =>
      removed ? [] : [_categoryRow()];
  @override
  Future<List<Map<String, dynamic>>> systemSubcategories() async =>
      removed ? [] : [_subRow()];
}

class _Push implements PushSyncTransport {
  final calls = <String>[];
  bool failParent = false;
  bool rejectParent = false;
  @override
  String? get authenticatedUserId => user;
  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {
    calls.add(name);
    if (failParent && name == 'create_category') {
      throw const PushSyncRpcException(safeMessage: 'Offline');
    }
    if (rejectParent && name == 'create_category') {
      throw const PushSyncRpcException(code: 'VALIDATION_ERROR');
    }
  }
}

Map<String, dynamic> _categoryRow() => {
  'id': systemId,
  'user_id': null,
  'name': 'Room Expense',
  'category_type': 'expense',
  'icon': null,
  'color': null,
  'description': null,
  'is_system': true,
  'status': 'active',
  'is_pinned': false,
  'sort_order': 1,
  'version': 1,
  'created_at': '2026-10-01T00:00:00Z',
  'updated_at': '2026-10-01T00:00:00Z',
  'deleted_at': null,
};

Map<String, dynamic> _subRow() => {
  'id': systemSubId,
  'user_id': null,
  'category_id': systemId,
  'name': 'Rent',
  'icon': null,
  'description': null,
  'status': 'active',
  'sort_order': 0,
  'version': 1,
  'created_at': '2026-10-01T00:00:00Z',
  'updated_at': '2026-10-01T00:00:00Z',
  'deleted_at': null,
};

void main() {
  late AppDatabase db;
  late OutboxStore outbox;
  late CategoryMutationService mutations;

  setUp(() async {
    db = AppDatabase.forTesting(NativeDatabase.memory());
    outbox = OutboxStore(db);
    await db.upsertSyncState(
      SyncStatesCompanion.insert(userId: user, dataGeneration: const Value(3)),
    );
    mutations = CategoryMutationService(
      database: db,
      outbox: outbox,
      authenticatedUserId: () => user,
    );
  });
  tearDown(() async => db.close());

  Future<void> complete(String id) async {
    final now = DateTime.now().toUtc();
    await outbox.markProcessing(userId: user, commandId: id, now: now);
    await outbox.markCompleted(userId: user, commandId: id, now: now);
  }

  test(
    'offline category and subcategory changes use durable typed commands',
    () async {
      final categoryId = await mutations.createCategory(
        name: '  Living  ',
        categoryType: 'expense',
      );
      expect((await db.outboxCommandById(categoryId))?.dataGeneration, 3);
      expect((await db.select(db.categories).getSingle()).name, 'Living');
      expect(
        () => mutations.updateCategory(
          id: categoryId,
          name: 'Changed',
          categoryType: 'expense',
        ),
        throwsStateError,
      );
      await complete(categoryId);
      expect(
        () => mutations.updateCategory(
          id: categoryId,
          name: 'Retyped',
          categoryType: 'income',
        ),
        throwsFormatException,
      );
      final editId = await mutations.updateCategory(
        id: categoryId,
        name: 'Housing',
        categoryType: 'expense',
      );
      expect((await db.select(db.categories).getSingle()).version, 2);
      expect(
        PushSyncRpcMapper.map((await db.outboxCommandById(editId))!)
            .parameters['p_base_version'],
        1,
      );
      await complete(editId);

      final subId = await mutations.createSubcategory(
        categoryId: categoryId,
        name: ' Rent ',
      );
      expect(
        (await db.select(db.subcategories).getSingle()).categoryId,
        categoryId,
      );
      expect(
        PushSyncRpcMapper.map((await db.outboxCommandById(subId))!)
            .parameters['p_category'],
        categoryId,
      );
      await complete(subId);
      final subEdit = await mutations.updateSubcategory(
        id: subId,
        name: 'Monthly rent',
      );
      await complete(subEdit);
      final subArchive = await mutations.archiveSubcategory(subId);
      expect(
        (await db.select(db.subcategories).getSingle()).status,
        'archived',
      );
      expect(
        PushSyncRpcMapper.map((await db.outboxCommandById(subArchive))!)
            .parameters['p_base_version'],
        2,
      );
      await complete(subArchive);
      final archive = await mutations.archiveCategory(categoryId);
      expect((await db.select(db.categories).getSingle()).status, 'archived');
      expect(
        PushSyncRpcMapper.map((await db.outboxCommandById(archive))!)
            .parameters['p_base_version'],
        2,
      );
    },
  );

  test('a newly created offline parent pushes before its child', () async {
    final categoryId = await mutations.createCategory(
      name: 'New parent',
      categoryType: 'expense',
    );
    final subId = await mutations.createSubcategory(
      categoryId: categoryId,
      name: 'New child',
    );
    final transport = _Push();
    final result = await PushSyncService(
      outbox: outbox,
      transport: transport,
    ).pushForAuthenticatedUser();
    expect(result.completed, 2);
    expect(transport.calls, ['create_category', 'create_subcategory']);
    expect((await db.outboxCommandById(subId))?.status, 'completed');
  });

  test('child stays queued while offline parent creation retries', () async {
    final categoryId = await mutations.createCategory(
      name: 'New parent',
      categoryType: 'expense',
    );
    final subId = await mutations.createSubcategory(
      categoryId: categoryId,
      name: 'New child',
    );
    final transport = _Push()..failParent = true;
    final result = await PushSyncService(
      outbox: outbox,
      transport: transport,
    ).pushForAuthenticatedUser();
    expect(result.retried, 1);
    expect(result.deferred, 1);
    expect(transport.calls, ['create_category']);
    expect((await db.outboxCommandById(subId))?.status, 'pending');
  });

  test('permanent parent failure also fails its dependent child', () async {
    final categoryId = await mutations.createCategory(
      name: 'New parent',
      categoryType: 'expense',
    );
    final subId = await mutations.createSubcategory(
      categoryId: categoryId,
      name: 'New child',
    );
    final transport = _Push()..rejectParent = true;
    final result = await PushSyncService(
      outbox: outbox,
      transport: transport,
    ).pushForAuthenticatedUser();
    expect(result.failed, 2);
    expect(transport.calls, ['create_category']);
    expect((await db.outboxCommandById(subId))?.lastErrorCode, 'INVALID_STATE');
  });

  test(
    'system bootstrap preserves null ownership and reconciles removals',
    () async {
      final pull = _Pull();
      final systems = _Systems();
      final service = PullSyncService(
        database: db,
        transport: pull,
        systemTransport: systems,
      );
      await service.bootstrapSystemRows(user);
      await service.bootstrapSystemRows(user);
      expect((await db.select(db.categories).get()).length, 1);
      expect((await db.select(db.subcategories).get()).single.userId, isNull);
      expect(
        () => mutations.updateCategory(
          id: systemId,
          name: 'Wrong',
          categoryType: 'expense',
        ),
        throwsStateError,
      );
      systems.removed = true;
      await service.bootstrapSystemRows(user);
      expect((await db.select(db.categories).get()), isEmpty);
      expect((await db.select(db.subcategories).get()), isEmpty);
      systems.removed = false;
      await service.bootstrapSystemRows(user);
      final userSub = await mutations.createSubcategory(
        categoryId: systemId,
        name: 'Utilities',
      );
      final customId = await mutations.createCategory(
        name: 'Personal',
        categoryType: 'expense',
      );
      await service.bootstrapSystemRows(user);
      expect((await db.select(db.categories).get()).length, 2);
      expect((await db.select(db.subcategories).get()).length, 2);
      expect((await db.outboxCommandById(userSub))?.userId, user);
      await db.upsertSyncState(
        SyncStatesCompanion.insert(
          userId: other,
          dataGeneration: const Value(3),
        ),
      );
      final otherMutations = CategoryMutationService(
        database: db,
        outbox: outbox,
        authenticatedUserId: () => other,
      );
      expect(() => otherMutations.archiveCategory(customId), throwsStateError);
      await db.clearUserCache(user);
      expect((await db.select(db.categories).get()).single.id, systemId);
      expect((await db.select(db.subcategories).get()).single.id, systemSubId);
      systems.userId = other;
      expect(() => service.bootstrapSystemRows(user), throwsStateError);
    },
  );

  test('version two cache preserves rows when system ownership becomes nullable', () async {
    await db.close();
    db = AppDatabase.forTesting(
      NativeDatabase.memory(
        setup: (sqlite) {
          sqlite.execute('PRAGMA user_version = 2;');
          sqlite.execute('''
        CREATE TABLE subcategories (
          id TEXT NOT NULL PRIMARY KEY, user_id TEXT NOT NULL,
          category_id TEXT NOT NULL, name TEXT NOT NULL, icon TEXT,
          description TEXT, status TEXT NOT NULL DEFAULT 'active',
          sort_order INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1,
          created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, deleted_at INTEGER
        );
      ''');
          sqlite.execute(
            "INSERT INTO subcategories (id,user_id,category_id,name,created_at,updated_at) VALUES ('legacy','$user','parent','Preserved',0,0)",
          );
        },
      ),
    );
    expect((await db.select(db.subcategories).get()).single.name, 'Preserved');
    await db.customStatement(
      "INSERT INTO subcategories (id,user_id,category_id,name,created_at,updated_at) VALUES ('system',NULL,'parent','System child',0,0)",
    );
    expect(
      (await db.select(db.subcategories).get())
          .where((row) => row.userId == null)
          .length,
      1,
    );
  });
}
