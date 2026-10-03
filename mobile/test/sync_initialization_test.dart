import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:sanie/app/design/finance_app_shell.dart';
import 'package:sanie/app/design/sanie_theme.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';
import 'package:sanie/core/sync/sync_providers.dart';
import 'package:sanie/features/accounts/accounts_pages.dart';
import 'package:sanie/features/accounts/accounts_repository.dart';

const userA = '11111111-1111-4111-8111-111111111111';
const userB = '22222222-2222-4222-8222-222222222222';

class TestPullTransport implements PullSyncTransport {
  TestPullTransport(this.userId, {this.dataGeneration = 5});
  String? userId;
  int dataGeneration;
  bool shouldFailProfile = false;
  int profileFetchCount = 0;

  @override
  String? get authenticatedUserId => userId;

  @override
  Future<Map<String, dynamic>?> profile(String id) async {
    profileFetchCount++;
    if (shouldFailProfile) throw StateError('Network error fetching profile');
    return {
      'id': id,
      'first_name': 'Test',
      'last_name': 'User',
      'phone': null,
      'avatar_path': null,
      'currency': 'NPR',
      'language': 'en',
      'theme': 'light',
      'notification_preferences': '{}',
      'settings': '{}',
      'data_generation': dataGeneration,
      'created_at': '2026-10-02T00:00:00Z',
      'updated_at': '2026-10-02T00:00:00Z',
    };
  }

  @override
  Future<List<PullChange>> pull({
    required int afterCursor,
    required int limit,
    required int generation,
  }) async {
    return const [];
  }

  @override
  Future<Map<String, dynamic>?> entity(String table, String id) async => null;
}

class TestPushTransport implements PushSyncTransport {
  TestPushTransport(this.userId);
  String? userId;

  @override
  String? get authenticatedUserId => userId;

  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {}
}

void main() {
  late AppDatabase database;
  late AccountsRepository repository;
  late TestPullTransport pullTransport;
  late TestPushTransport pushTransport;

  setUp(() {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    pullTransport = TestPullTransport(userA, dataGeneration: 5);
    pushTransport = TestPushTransport(userA);
    repository = AccountsRepository(
      database: database,
      authenticatedUserId: () => pullTransport.authenticatedUserId,
    );
  });

  tearDown(() async {
    await database.close();
  });

  GoRouter makeRouter(String initialLocation) => GoRouter(
    initialLocation: initialLocation,
    routes: [
      ShellRoute(
        builder: (context, state, child) =>
            FinanceAppShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(
            path: '/accounts',
            builder: (context, state) => const AccountsPage(),
          ),
          GoRoute(
            path: '/accounts/add',
            builder: (context, state) => const AccountFormPage(),
          ),
          GoRoute(
            path: '/accounts/:id',
            builder: (context, state) =>
                AccountDetailsPage(id: state.pathParameters['id']!),
          ),
        ],
      ),
    ],
  );

  overridesFor(String currentUserId) => [
    accountUserProvider.overrideWith((ref) => Stream.value(currentUserId)),
    accountsRepositoryProvider.overrideWithValue(repository),
    pullSyncTransportProvider.overrideWithValue(pullTransport),
    pushSyncTransportProvider.overrideWithValue(pushTransport),
  ];

  test('fresh authenticated user initializes generation via pull', () async {
    expect(await database.syncStateForUser(userA), isNull);

    final container = ProviderContainer(overrides: overridesFor(userA));
    addTearDown(container.dispose);

    await container.read(syncInitializerProvider(userA).future);

    final syncState = await database.syncStateForUser(userA);
    expect(syncState, isNotNull);
    expect(syncState!.dataGeneration, 5);
    expect(pullTransport.profileFetchCount, 1);
  });

  test('cached valid generation survives restart as designed', () async {
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userA,
        dataGeneration: const Value(42),
      ),
    );

    final container = ProviderContainer(overrides: overridesFor(userA));
    addTearDown(container.dispose);

    await container.read(syncInitializerProvider(userA).future);

    final syncState = await database.syncStateForUser(userA);
    expect(syncState!.dataGeneration, 42);
    expect(pullTransport.profileFetchCount, 0);
  });

  test('logout/login user isolation maintains distinct generation', () async {
    pullTransport.dataGeneration = 10;
    final containerA = ProviderContainer(overrides: overridesFor(userA));
    addTearDown(containerA.dispose);
    await containerA.read(syncInitializerProvider(userA).future);

    pullTransport.userId = userB;
    pushTransport.userId = userB;
    pullTransport.dataGeneration = 20;

    final containerB = ProviderContainer(overrides: overridesFor(userB));
    addTearDown(containerB.dispose);
    await containerB.read(syncInitializerProvider(userB).future);

    final stateA = await database.syncStateForUser(userA);
    final stateB = await database.syncStateForUser(userB);

    expect(stateA!.dataGeneration, 10);
    expect(stateB!.dataGeneration, 20);
  });

  testWidgets(
    'account creation form becomes available after initialization and queues outbox command',
    (tester) async {
      pullTransport.dataGeneration = 7;
      final router = makeRouter('/accounts/add');
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: overridesFor(userA),
          child: MaterialApp.router(
            theme: SanieTheme.light(),
            routerConfig: router,
          ),
        ),
      );

      await tester.pumpAndSettle();

      expect(find.byKey(const Key('account-name')), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('account-name')),
        'Main Savings',
      );
      await tester.enterText(
        find.byKey(const Key('account-opening')),
        '500.00',
      );

      final save = find.text('Add account').last;
      for (
        var attempt = 0;
        attempt < 8 && tester.getCenter(save).dy > 300;
        attempt++
      ) {
        await tester.drag(find.byType(ListView).first, const Offset(0, -230));
        await tester.pumpAndSettle();
      }
      await tester.tap(save);
      await tester.pumpAndSettle();

      final accounts = await database.accountsForUser(userA);
      expect(accounts, hasLength(1));
      expect(accounts.single.name, 'Main Savings');

      final command = await database.outboxCommandById(accounts.single.id);
      expect(command, isNotNull);
      expect(command!.dataGeneration, 7);
      expect(command.commandType, 'create_account');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 1));
    },
  );

  testWidgets(
    'initialization failure shows retry state and does not queue unsafe command',
    (tester) async {
      pullTransport.shouldFailProfile = true;
      final router = makeRouter('/accounts/add');
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: overridesFor(userA),
          child: MaterialApp.router(
            theme: SanieTheme.light(),
            routerConfig: router,
          ),
        ),
      );

      await tester.pumpAndSettle();

      expect(
        find.textContaining('Could not initialize sync state'),
        findsOneWidget,
      );
      expect(find.text('Retry'), findsOneWidget);
      expect(find.byKey(const Key('account-name')), findsNothing);

      final commands = await database.select(database.outboxCommands).get();
      expect(commands, isEmpty);

      pullTransport.shouldFailProfile = false;
      pullTransport.dataGeneration = 12;

      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('account-name')), findsOneWidget);
      final syncState = await database.syncStateForUser(userA);
      expect(syncState!.dataGeneration, 12);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 1));
    },
  );
}
