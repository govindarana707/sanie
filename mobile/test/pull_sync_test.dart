import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/sync/pull_sync.dart';

const userA = '11111111-1111-4111-8111-111111111111';
const userB = '22222222-2222-4222-8222-222222222222';
final now = DateTime.utc(2026, 10, 2, 12);

class FakePullTransport implements PullSyncTransport {
  FakePullTransport({
    required this.authenticatedUserId,
    required this.pages,
    required this.rows,
    this.fail = false,
  });
  @override
  final String? authenticatedUserId;
  final Map<int, List<PullChange>> pages;
  final Map<String, Map<String, dynamic>> rows;
  final bool fail;
  @override
  Future<Map<String, dynamic>?> profile(String id) async =>
      rows['profiles/$id'];
  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async {
    if (fail) throw StateError('transport failure');
    return pages[afterCursor] ?? const [];
  }

  @override
  Future<Map<String, dynamic>?> entity(String table, String id) async =>
      rows['$table/$id'];
}

PullChange event(
  int sequence,
  String id, {
  int version = 1,
  int generation = 1,
  String operation = 'insert',
  int? next,
}) => PullChange(
  sequence: sequence,
  entityType: 'accounts',
  entityId: id,
  operation: operation,
  entityVersion: version,
  dataGeneration: generation,
  changedAt: now,
  nextCursor: next ?? sequence,
);
Map<String, dynamic> profile(String id) => {
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
  'created_at': now.toIso8601String(),
  'updated_at': now.toIso8601String(),
};
Map<String, dynamic> account(
  String id,
  String user, {
  int version = 1,
  String name = 'Cash',
}) => {
  'id': id,
  'user_id': user,
  'name': name,
  'account_type': 'cash',
  'account_number': null,
  'opening_balance': 0,
  'balance': 0,
  'currency': 'NPR',
  'color': null,
  'icon': null,
  'is_active': true,
  'is_default': false,
  'include_in_savings': false,
  'include_in_net_balance': true,
  'version': version,
  'created_at': now.toIso8601String(),
  'updated_at': now.toIso8601String(),
  'deleted_at': null,
};

void main() {
  late AppDatabase db;
  setUp(() => db = AppDatabase.forTesting(NativeDatabase.memory()));
  tearDown(() => db.close());
  PullSyncService service(FakePullTransport t) =>
      PullSyncService(database: db, transport: t, clock: () => now);
  FakePullTransport fake(
    Map<int, List<PullChange>> pages,
    Map<String, Map<String, dynamic>> rows, {
    String user = userA,
    bool fail = false,
  }) => FakePullTransport(
    authenticatedUserId: user,
    pages: pages,
    rows: rows,
    fail: fail,
  );

  test('atomic failed page rolls back rows and cursor', () async {
    const good = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const bad = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    final t = fake(
      {
        0: [event(1, good, next: 2), event(2, bad, next: 2)],
      },
      {
        'profiles/$userA': profile(userA),
        'accounts/$good': account(good, userA),
        'accounts/$bad': {'id': bad, 'user_id': userA},
      },
    );
    await expectLater(service(t).pullForAuthenticatedUser(), throwsA(anything));
    expect(await db.accountById(good), isNull);
    expect((await db.syncStateForUser(userA))?.lastCursor, 0);
  });

  test('replay is idempotent and version cannot regress', () async {
    const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    final rows = {
      'profiles/$userA': profile(userA),
      'accounts/$id': account(id, userA, version: 2, name: 'New'),
    };
    final t = fake({
      0: [event(1, id, version: 2)],
    }, rows);
    await service(t).pullForAuthenticatedUser();
    await service(t).pullForAuthenticatedUser();
    expect((await db.accountById(id))?.name, 'New');
    rows['accounts/$id'] = account(id, userA, version: 1, name: 'Old');
    t.pages[1] = [event(2, id, version: 1)];
    await service(t).pullForAuthenticatedUser();
    expect((await db.accountById(id))?.name, 'New');
    expect((await db.syncStateForUser(userA))?.lastCursor, 2);
  });

  test('pagination and restart resume from persisted cursor', () async {
    const a = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const b = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    final t = fake(
      {
        0: [event(1, a)],
        1: [event(2, b)],
      },
      {
        'profiles/$userA': profile(userA),
        'accounts/$a': account(a, userA),
        'accounts/$b': account(b, userA),
      },
    );
    final first = PullSyncService(
      database: db,
      transport: t,
      maxPages: 1,
      clock: () => now,
    );
    expect((await first.pullForAuthenticatedUser()).changes, 1);
    expect((await db.syncStateForUser(userA))?.lastCursor, 1);
    expect((await service(t).pullForAuthenticatedUser()).changes, 1);
    expect(await db.accountById(b), isNotNull);
    expect((await db.syncStateForUser(userA))?.lastCursor, 2);
  });

  test('user isolation, transport, malformed, and generation failures preserve cursor', () async {
    const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    final base = {
      'profiles/$userA': profile(userA),
      'accounts/$id': account(id, userB),
    };
    await expectLater(
      service(
        fake({
          0: [event(1, id)],
        }, base),
      ).pullForAuthenticatedUser(),
      throwsStateError,
    );
    expect((await db.syncStateForUser(userA))?.lastCursor, 0);
    await expectLater(
      service(fake({}, {'profiles/$userA': profile(userA)}, fail: true))
          .pullForAuthenticatedUser(),
      throwsStateError,
    );
    final malformed = await service(
      fake(
        {
          0: [event(1, id, operation: 'delete')],
        },
        {'profiles/$userA': profile(userA)},
      ),
    ).pullForAuthenticatedUser();
    expect(malformed.generationMismatch, isTrue);
    expect((await db.syncStateForUser(userA))?.lastCursor, 0);
    final generation = await service(
      fake(
        {
          0: [event(1, id, generation: 2)],
        },
        {'profiles/$userA': profile(userA)},
      ),
    ).pullForAuthenticatedUser();
    expect(generation.generationMismatch, isTrue);
    expect((await db.syncStateForUser(userA))?.lastCursor, 0);
  });
}
