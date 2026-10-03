import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import 'outbox_command.dart';
import 'outbox_store.dart';

/// Category working-copy changes and their commands commit together.
class CategoryMutationService {
  CategoryMutationService({
    required this.database,
    required this.outbox,
    required this.authenticatedUserId,
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());

  final AppDatabase database;
  final OutboxStore outbox;
  final String? Function() authenticatedUserId;
  final DateTime Function() _clock;

  Future<String> createCategory({
    required String name,
    required String categoryType,
    String? icon,
    String? description,
  }) async {
    final user = _user();
    final generation = await _generation(user);
    final clean = _name(name);
    _type(categoryType);
    final now = _clock();
    final command = OutboxCommandEnvelope.categoryMutation(
      type: OutboxCommandType.createCategory,
      userId: user,
      entityId: database.newId(),
      name: clean,
      categoryType: categoryType,
      icon: _optional(icon, 100),
      description: _optional(description, 1000),
      dataGeneration: generation,
      createdAt: now,
    );
    await database.transaction(() async {
      await database
          .into(database.categories)
          .insert(
            CategoriesCompanion.insert(
              id: command.id,
              userId: Value(user),
              name: clean,
              categoryType: categoryType,
              icon: Value(_optional(icon, 100)),
              description: Value(_optional(description, 1000)),
              createdAt: now,
              updatedAt: now,
            ),
          );
      await outbox.enqueue(command);
    });
    return command.id;
  }

  Future<String> updateCategory({
    required String id,
    required String name,
    required String categoryType,
    String? icon,
    String? description,
  }) async {
    final user = _user();
    final generation = await _generation(user);
    final clean = _name(name);
    _type(categoryType);
    return database.transaction(() async {
      final row = await _category(user, id);
      await _noPending(user, id);
      if (categoryType != row.categoryType) {
        throw const FormatException('Category type cannot be changed.');
      }
      final now = _clock();
      final command = OutboxCommandEnvelope.categoryMutation(
        type: OutboxCommandType.updateCategory,
        userId: user,
        entityId: id,
        name: clean,
        categoryType: categoryType,
        icon: _optional(icon, 100),
        description: _optional(description, 1000),
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
      );
      await (database.update(
        database.categories,
      )..where((c) => c.id.equals(id))).write(
        CategoriesCompanion(
          name: Value(clean),
          categoryType: Value(categoryType),
          icon: Value(_optional(icon, 100)),
          description: Value(_optional(description, 1000)),
          version: Value(row.version + 1),
          updatedAt: Value(now),
        ),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> archiveCategory(String id) async {
    final user = _user();
    final generation = await _generation(user);
    return database.transaction(() async {
      final row = await _category(user, id);
      await _noPending(user, id);
      final now = _clock();
      final command = OutboxCommandEnvelope.categoryMutation(
        type: OutboxCommandType.archiveCategory,
        userId: user,
        entityId: id,
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
      );
      await (database.update(
        database.categories,
      )..where((c) => c.id.equals(id))).write(
        CategoriesCompanion(
          status: const Value('archived'),
          version: Value(row.version + 1),
          updatedAt: Value(now),
        ),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> createSubcategory({
    required String categoryId,
    required String name,
    String? icon,
    String? description,
  }) async {
    final user = _user();
    final generation = await _generation(user);
    final clean = _name(name);
    final now = _clock();
    return database.transaction(() async {
      await _visibleParent(user, categoryId);
      final parentCreate = await _pendingParentCreate(user, categoryId);
      await _noPending(user, categoryId, allowPendingCreate: true);
      final createdAt = parentCreate != null && !now.isAfter(parentCreate)
          ? parentCreate.add(const Duration(milliseconds: 1))
          : now;
      final command = OutboxCommandEnvelope.categoryMutation(
        type: OutboxCommandType.createSubcategory,
        userId: user,
        entityId: database.newId(),
        categoryId: categoryId,
        name: clean,
        icon: _optional(icon, 100),
        description: _optional(description, 1000),
        dataGeneration: generation,
        createdAt: createdAt,
      );
      await database
          .into(database.subcategories)
          .insert(
            SubcategoriesCompanion.insert(
              id: command.id,
              userId: Value(user),
              categoryId: categoryId,
              name: clean,
              icon: Value(_optional(icon, 100)),
              description: Value(_optional(description, 1000)),
              createdAt: createdAt,
              updatedAt: createdAt,
            ),
          );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> updateSubcategory({
    required String id,
    required String name,
    String? icon,
    String? description,
  }) async {
    final user = _user();
    final generation = await _generation(user);
    final clean = _name(name);
    return database.transaction(() async {
      final row = await _subcategory(user, id);
      await _visibleParent(user, row.categoryId);
      await _noPending(user, id);
      final now = _clock();
      final command = OutboxCommandEnvelope.categoryMutation(
        type: OutboxCommandType.updateSubcategory,
        userId: user,
        entityId: id,
        categoryId: row.categoryId,
        name: clean,
        icon: _optional(icon, 100),
        description: _optional(description, 1000),
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
      );
      await (database.update(
        database.subcategories,
      )..where((s) => s.id.equals(id))).write(
        SubcategoriesCompanion(
          name: Value(clean),
          icon: Value(_optional(icon, 100)),
          description: Value(_optional(description, 1000)),
          version: Value(row.version + 1),
          updatedAt: Value(now),
        ),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> archiveSubcategory(String id) async {
    final user = _user();
    final generation = await _generation(user);
    return database.transaction(() async {
      final row = await _subcategory(user, id);
      await _visibleParent(user, row.categoryId);
      await _noPending(user, id);
      final now = _clock();
      final command = OutboxCommandEnvelope.categoryMutation(
        type: OutboxCommandType.archiveSubcategory,
        userId: user,
        entityId: id,
        categoryId: row.categoryId,
        baseVersion: row.version,
        dataGeneration: generation,
        createdAt: now,
      );
      await (database.update(
        database.subcategories,
      )..where((s) => s.id.equals(id))).write(
        SubcategoriesCompanion(
          status: const Value('archived'),
          version: Value(row.version + 1),
          updatedAt: Value(now),
        ),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<Category> _visibleParent(String user, String id) async {
    final row = await (database.select(
      database.categories,
    )..where((c) => c.id.equals(id))).getSingleOrNull();
    if (row == null ||
        (row.userId != user && !row.isSystem) ||
        row.status != 'active' ||
        row.deletedAt != null) {
      throw StateError('Parent category is unavailable.');
    }
    return row;
  }

  Future<Category> _category(String user, String id) async {
    final row = await _visibleParent(user, id);
    if (row.isSystem || row.userId != user) {
      throw StateError('System category is protected.');
    }
    return row;
  }

  Future<Subcategory> _subcategory(String user, String id) async {
    final row = await (database.select(
      database.subcategories,
    )..where((s) => s.id.equals(id))).getSingleOrNull();
    if (row == null ||
        row.userId != user ||
        row.status != 'active' ||
        row.deletedAt != null) {
      throw StateError('Subcategory is unavailable for this user.');
    }
    return row;
  }

  Future<DateTime?> _pendingParentCreate(String user, String id) async {
    final command = await database.outboxCommandForUser(user, id);
    if (command == null || command.commandType != 'create_category') {
      return null;
    }
    if (command.status == 'failed') {
      throw StateError('Parent category creation failed.');
    }
    return command.status == 'completed' ? null : command.createdAt;
  }

  Future<void> _noPending(
    String user,
    String id, {
    bool allowPendingCreate = false,
  }) async {
    final commands =
        await (database.select(database.outboxCommands)..where(
              (c) =>
                  c.userId.equals(user) &
                  c.commandType.isIn(const [
                    'create_category',
                    'update_category',
                    'archive_category',
                    'create_subcategory',
                    'update_subcategory',
                    'archive_subcategory',
                  ]) &
                  c.status.isIn(const [
                    'pending',
                    'processing',
                    'retry',
                    'failed',
                  ]),
            ))
            .get();
    for (final command in commands) {
      final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
      if (payload['p_id'] == id) {
        if (allowPendingCreate &&
            command.commandType == 'create_category' &&
            command.status != 'failed') {
          continue;
        }
        throw StateError('Sync the pending category change first.');
      }
    }
  }

  String _user() {
    final user = authenticatedUserId();
    if (user == null || user.isEmpty) {
      throw StateError('Authentication is required.');
    }
    return user;
  }

  Future<int> _generation(String user) async {
    final state = await database.syncStateForUser(user);
    if (state == null) throw StateError('Sync generation is unavailable.');
    return state.dataGeneration;
  }

  String _name(String value) {
    final clean = value.trim();
    if (clean.isEmpty || clean.length > 100) {
      throw const FormatException('Name must be 1–100 characters.');
    }
    return clean;
  }

  void _type(String value) {
    if (value != 'income' && value != 'expense') {
      throw const FormatException('Invalid category type.');
    }
  }

  String? _optional(String? value, int max) {
    final clean = value?.trim();
    if (clean != null && clean.length > max) {
      throw const FormatException('Field is too long.');
    }
    return clean == null || clean.isEmpty ? null : clean;
  }
}
