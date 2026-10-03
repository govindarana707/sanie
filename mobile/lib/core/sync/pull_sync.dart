import 'package:drift/drift.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../database/app_database.dart';

class PullChange {
  const PullChange({
    required this.sequence,
    required this.entityType,
    required this.entityId,
    required this.operation,
    required this.entityVersion,
    required this.dataGeneration,
    required this.changedAt,
    required this.nextCursor,
  });

  factory PullChange.fromJson(Map<String, dynamic> json) => PullChange(
    sequence: (json['sequence'] as num).toInt(),
    entityType: json['entity_type'] as String,
    entityId: json['entity_id'] as String,
    operation: json['operation'] as String,
    entityVersion: (json['entity_version'] as num?)?.toInt(),
    dataGeneration: (json['data_generation'] as num).toInt(),
    changedAt: DateTime.parse(json['changed_at'] as String).toUtc(),
    nextCursor: (json['next_cursor'] as num).toInt(),
  );

  final int sequence;
  final String entityType;
  final String entityId;
  final String operation;
  final int? entityVersion;
  final int dataGeneration;
  final DateTime changedAt;
  final int nextCursor;
}

abstract interface class PullSyncTransport {
  String? get authenticatedUserId;
  Future<Map<String, dynamic>?> profile(String userId);
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  });
  Future<Map<String, dynamic>?> entity(String table, String id);
}

abstract interface class SystemCategoryTransport {
  String? get authenticatedUserId;
  Future<List<Map<String, dynamic>>> systemCategories();
  Future<List<Map<String, dynamic>>> systemSubcategories();
}

class SupabaseSystemCategoryTransport implements SystemCategoryTransport {
  SupabaseSystemCategoryTransport(this._client);
  final SupabaseClient _client;

  @override
  String? get authenticatedUserId => _client.auth.currentSession?.user.id;

  @override
  Future<List<Map<String, dynamic>>> systemCategories() async {
    final rows = <Map<String, dynamic>>[];
    for (var offset = 0; ; offset += 500) {
      final page = await _client
          .from('categories')
          .select()
          .eq('is_system', true)
          .order('id')
          .range(offset, offset + 499);
      rows.addAll(page.map((row) => Map<String, dynamic>.from(row)));
      if (page.length < 500) return rows;
    }
  }

  @override
  Future<List<Map<String, dynamic>>> systemSubcategories() async {
    final rows = <Map<String, dynamic>>[];
    for (var offset = 0; ; offset += 500) {
      final page = await _client
          .from('subcategories')
          .select()
          .isFilter('user_id', null)
          .order('id')
          .range(offset, offset + 499);
      rows.addAll(page.map((row) => Map<String, dynamic>.from(row)));
      if (page.length < 500) return rows;
    }
  }
}

class SupabasePullSyncTransport implements PullSyncTransport {
  SupabasePullSyncTransport(this._client);
  final SupabaseClient _client;

  @override
  String? get authenticatedUserId => _client.auth.currentSession?.user.id;

  @override
  Future<Map<String, dynamic>?> profile(String userId) =>
      _single('profiles', userId);

  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async {
    final rows = await _client.rpc(
      'pull_changes',
      params: {
        'p_after_cursor': afterCursor,
        'p_limit': limit,
        'p_generation': generation,
      },
    ) as List<dynamic>;
    return rows
        .map(
          (row) => PullChange.fromJson(Map<String, dynamic>.from(row as Map)),
        )
        .toList(growable: false);
  }

  @override
  Future<Map<String, dynamic>?> entity(String table, String id) =>
      _single(table, id);

  Future<Map<String, dynamic>?> _single(String table, String id) async {
    final row = await _client.from(table).select().eq('id', id).maybeSingle();
    return row == null ? null : Map<String, dynamic>.from(row);
  }
}

class PullSyncResult {
  const PullSyncResult({
    this.pages = 0,
    this.changes = 0,
    this.unauthenticated = false,
    this.generationMismatch = false,
  });
  final int pages;
  final int changes;
  final bool unauthenticated;
  final bool generationMismatch;
}

class PullSyncService {
  PullSyncService({
    required this.database,
    required this.transport,
    this.systemTransport,
    this.pageSize = 100,
    this.maxPages = 10,
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());

  final AppDatabase database;
  final PullSyncTransport transport;
  final SystemCategoryTransport? systemTransport;
  final int pageSize;
  final int maxPages;
  final DateTime Function() _clock;
  static bool _running = false;

  Future<PullSyncResult> pullForAuthenticatedUser() async {
    if (_running) return const PullSyncResult();
    final userId = transport.authenticatedUserId;
    if (userId == null || userId.isEmpty) {
      return const PullSyncResult(unauthenticated: true);
    }
    _running = true;
    try {
      var state = await database.syncStateForUser(userId);
      if (state == null) {
        final profile = await transport.profile(userId);
        if (profile == null || profile['id'] != userId) {
          throw StateError('Authenticated profile is unavailable.');
        }
        final generation = (profile['data_generation'] as num).toInt();
        await database.transaction(() async {
          await _applyRow('profiles', profile, userId);
          await database.upsertSyncState(
            SyncStatesCompanion.insert(
              userId: userId,
              dataGeneration: Value(generation),
            ),
          );
        });
        state = await database.syncStateForUser(userId);
      }

      var cursor = state!.lastCursor;
      var pages = 0;
      var changes = 0;
      while (pages < maxPages) {
        final page = await transport.pull(
          afterCursor: cursor,
          limit: pageSize,
          generation: state.dataGeneration,
        );
        if (page.isEmpty) {
          break;
        }
        final valid = _validate(page, userId, state.dataGeneration, cursor);
        if (!valid) {
          return PullSyncResult(
            pages: pages,
            changes: changes,
            generationMismatch: true,
          );
        }
        final rows = <({PullChange change, Map<String, dynamic> row})>[];
        for (final change in page) {
          final row = await transport.entity(
            change.entityType,
            change.entityId,
          );
          if (row == null || !_owns(row, change.entityType, userId)) {
            throw StateError('Change feed ownership validation failed.');
          }
          rows.add((change: change, row: row));
        }
        final next = page.last.nextCursor;
        await database.transaction(() async {
          for (final item in rows) {
            await _applyRow(item.change.entityType, item.row, userId);
          }
          await database.upsertSyncState(
            SyncStatesCompanion(
              userId: Value(userId),
              lastCursor: Value(next),
              dataGeneration: Value(state!.dataGeneration),
              lastSuccessfulSyncAt: Value(_clock()),
            ),
          );
        });
        cursor = next;
        pages++;
        changes += page.length;
      }
      if (systemTransport != null) await bootstrapSystemRows(userId);
      return PullSyncResult(pages: pages, changes: changes);
    } finally {
      _running = false;
    }
  }

  Future<void> bootstrapSystemRows(String userId) async {
    final reader = systemTransport;
    if (reader == null ||
        reader.authenticatedUserId != userId ||
        transport.authenticatedUserId != userId) {
      throw StateError(
        'System category read requires the same authenticated user.',
      );
    }
    final categories = await reader.systemCategories();
    final subcategories = await reader.systemSubcategories();
    if (reader.authenticatedUserId != userId ||
        transport.authenticatedUserId != userId ||
        categories.any(
          (row) => row['is_system'] != true || row['user_id'] != null,
        ) ||
        subcategories.any((row) => row['user_id'] != null)) {
      throw StateError('Invalid system category snapshot.');
    }
    final categoryIds = categories.map((row) => row['id']).toSet();
    if (subcategories.any((row) => !categoryIds.contains(row['category_id']))) {
      throw StateError('System subcategory has an unknown parent.');
    }
    await database.transaction(() async {
      for (final row in categories) {
        await _applyRow('categories', row, userId);
      }
      for (final row in subcategories) {
        await _applyRow('subcategories', row, userId);
      }
      final ids = categories.map((row) => row['id'] as String).toList();
      final subIds = subcategories.map((row) => row['id'] as String).toList();
      await (database.delete(
        database.categories,
      )..where((row) => row.isSystem.equals(true) & row.id.isNotIn(ids))).go();
      await (database.delete(
        database.subcategories,
      )..where((row) => row.userId.isNull() & row.id.isNotIn(subIds))).go();
    });
  }

  bool _validate(
    List<PullChange> page,
    String userId,
    int generation,
    int cursor,
  ) {
    var previous = cursor;
    for (final change in page) {
      if (!_specs.containsKey(change.entityType) ||
          change.operation != 'insert' &&
              change.operation != 'update' &&
              change.operation != 'tombstone' ||
          change.dataGeneration != generation ||
          change.sequence <= previous ||
          change.nextCursor < change.sequence) {
        return false;
      }
      previous = change.sequence;
    }
    return page.last.nextCursor >= previous;
  }

  bool _owns(Map<String, dynamic> row, String table, String userId) =>
      table == 'profiles' ? row['id'] == userId : row['user_id'] == userId;

  Future<void> _applyRow(
    String table,
    Map<String, dynamic> row,
    String userId,
  ) async {
    final spec = _specs[table]!;
    final values = spec.columns
        .map((column) => _value(row[column], column))
        .toList();
    final placeholders = List.filled(spec.columns.length, '?').join(',');
    final assignments = spec.columns
        .where((column) => column != 'id')
        .map((column) => '$column=excluded.$column')
        .join(',');
    final orderGuard = spec.versioned
        ? ' where excluded.version >= $table.version'
        : ' where excluded.updated_at >= $table.updated_at';
    await database.customStatement(
      'insert into $table (${spec.columns.join(',')}) values ($placeholders) '
      'on conflict(id) do update set $assignments$orderGuard',
      values,
    );
  }

  Object? _value(dynamic value, String column) {
    if (value == null) {
      return null;
    }
    if (column.endsWith('_at')) {
      return DateTime.parse(value as String).toUtc().millisecondsSinceEpoch;
    }
    if (value is bool) return value;
    if (value is num &&
        (column == 'version' ||
            column == 'data_generation' ||
            column == 'sort_order' ||
            column.startsWith('day_'))) {
      return value.toInt();
    }
    if (value is num) {
      return value.toDouble();
    }
    return value.toString();
  }
}

class _Spec {
  const _Spec(this.columns, {this.versioned = true});
  final List<String> columns;
  final bool versioned;
}

const _common = ['id', 'user_id'];
const _audited = ['version', 'created_at', 'updated_at', 'deleted_at'];
const _specs = <String, _Spec>{
  'profiles': _Spec([
    'id',
    'first_name',
    'last_name',
    'phone',
    'avatar_path',
    'currency',
    'language',
    'theme',
    'notification_preferences',
    'settings',
    'data_generation',
    'created_at',
    'updated_at',
  ], versioned: false),
  'accounts': _Spec([
    ..._common,
    'name',
    'account_type',
    'account_number',
    'opening_balance',
    'balance',
    'currency',
    'color',
    'icon',
    'is_active',
    'is_default',
    'include_in_savings',
    'include_in_net_balance',
    ..._audited,
  ]),
  'categories': _Spec([
    ..._common,
    'name',
    'category_type',
    'icon',
    'color',
    'description',
    'is_system',
    'status',
    'is_pinned',
    'sort_order',
    ..._audited,
  ]),
  'subcategories': _Spec([
    ..._common,
    'category_id',
    'name',
    'icon',
    'description',
    'status',
    'sort_order',
    ..._audited,
  ]),
  'transactions': _Spec([
    ..._common,
    'account_id',
    'from_account_id',
    'to_account_id',
    'category_id',
    'subcategory_id',
    'amount',
    'transaction_type',
    'payment_method',
    'karobar_transaction_id',
    'client_request_id',
    'transfer_parent_id',
    'goal_id',
    'recurring_definition_id',
    'recurring_occurrence_date',
    ..._audited,
    'transaction_date',
    'description',
  ]),
  'budgets': _Spec([
    ..._common,
    'category_id',
    'subcategory_id',
    'name',
    'amount',
    'period',
    'start_date',
    'end_date',
    'alert_threshold',
    'is_active',
    ..._audited,
  ]),
  'goals': _Spec([
    ..._common,
    'name',
    'target_amount',
    'initial_amount',
    'current_amount',
    'deadline',
    'icon',
    'color',
    'description',
    'status',
    ..._audited,
  ]),
  'people': _Spec([
    ..._common,
    'name',
    'person_type',
    'phone',
    'email',
    'address',
    'photo_path',
    'notes',
    'status',
    ..._audited,
  ]),
  'karobar_transactions': _Spec([
    ..._common,
    'person_id',
    'transaction_type',
    'amount',
    'account_id',
    'expense_transaction_id',
    'income_transaction_id',
    'payment_method',
    'client_request_id',
    'description',
    'transaction_date',
    'due_date',
    ..._audited,
  ]),
  'recurring_transactions': _Spec([
    ..._common,
    'account_id',
    'category_id',
    'subcategory_id',
    'amount',
    'transaction_type',
    'frequency',
    'day_of_month',
    'day_of_week',
    'start_date',
    'end_date',
    'next_occurrence',
    'description',
    'notes',
    'is_active',
    ..._audited,
  ]),
};
