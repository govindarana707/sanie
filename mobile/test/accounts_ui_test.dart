import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:sanie/app/design/finance_app_shell.dart';
import 'package:sanie/app/design/sanie_theme.dart';
import 'package:sanie/app/router.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/features/accounts/accounts_pages.dart';
import 'package:sanie/features/accounts/accounts_repository.dart';

const userA = '11111111-1111-4111-8111-111111111111';
const userB = '22222222-2222-4222-8222-222222222222';

void main() {
  late AppDatabase database;
  late String? currentUser;
  late AccountsRepository repository;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    currentUser = userA;
    repository = AccountsRepository(
      database: database,
      authenticatedUserId: () => currentUser,
    );
    await database.upsertSyncState(
      SyncStatesCompanion.insert(userId: userA, dataGeneration: const Value(1)),
    );
  });
  tearDown(() async => database.close());

  Future<String> seed({
    String name = 'Daily cash',
    double balance = 1234.5,
    String userId = userA,
  }) async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 2);
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: id,
        userId: userId,
        name: name,
        accountType: 'cash',
        balance: Value(balance),
        createdAt: now,
        updatedAt: now,
      ),
    );
    return id;
  }

  GoRouter makeRouter(String initialLocation) => GoRouter(
    initialLocation: initialLocation,
    routes: [
      ShellRoute(
        builder: (context, state, child) =>
            FinanceAppShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(path: '/more', builder: (context, state) => const MorePage()),
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
          GoRoute(
            path: '/accounts/:id/edit',
            builder: (context, state) =>
                AccountFormPage(id: state.pathParameters['id']!),
          ),
        ],
      ),
    ],
  );

  Future<GoRouter> mount(
    WidgetTester tester,
    String route, {
    String userId = userA,
    ThemeData? theme,
  }) async {
    final router = makeRouter(route);
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(repository),
        ],
        child: MaterialApp.router(
          theme: theme ?? SanieTheme.light(),
          routerConfig: router,
        ),
      ),
    );
    await tester.pumpAndSettle();
    return router;
  }

  Future<void> finish(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 1));
  }

  Future<void> tapVisible(WidgetTester tester, Finder finder) async {
    for (var attempt = 0; attempt < 8; attempt++) {
      final y = tester.getCenter(finder).dy;
      if (y >= 100 && y <= 300) break;
      await tester.drag(
        find.byType(ListView).first,
        Offset(0, y > 300 ? -230 : 230),
      );
      await tester.pumpAndSettle();
    }
    await tester.tap(finder);
  }

  testWidgets('More leads to empty Accounts and Add', (tester) async {
    final router = await mount(tester, '/more');
    await tester.tap(find.text('Accounts'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/accounts');
    expect(find.textContaining('No accounts yet'), findsOneWidget);
    expect(find.text('Add account'), findsOneWidget);
    expect(find.text('More'), findsOneWidget); // selected shell destination
    await finish(tester);
  });

  testWidgets('Drift list, large NPR amount and long name render in both modes', (
    tester,
  ) async {
    final longName =
        'A very long account name that must remain readable on a narrow mobile display';
    await seed(name: longName, balance: 9999999999.99);
    for (final theme in [SanieTheme.light(), SanieTheme.dark()]) {
      tester.view.physicalSize = const Size(320, 700);
      tester.view.devicePixelRatio = 1;
      await mount(tester, '/accounts', theme: theme);
      expect(find.text('NPR 9,999,999,999.99'), findsNWidgets(2));
      expect(find.text(longName), findsOneWidget);
      expect(tester.takeException(), isNull);
    }
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
    await finish(tester);
  });

  testWidgets(
    'Add validates, trims, queues offline and prevents double submit',
    (tester) async {
      final router = await mount(tester, '/accounts/add');
      await tapVisible(tester, find.text('Add account').last);
      await tester.pumpAndSettle();
      expect(find.text('Enter an account name.'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('account-name')),
        '  Travel cash  ',
      );
      await tester.enterText(
        find.byKey(const Key('account-opening')),
        '12.345',
      );
      await tapVisible(tester, find.text('Add account').last);
      await tester.pumpAndSettle();
      expect(
        find.text('Enter an amount with up to two decimals.'),
        findsOneWidget,
      );
      await tester.enterText(find.byKey(const Key('account-opening')), '42.50');
      await tapVisible(tester, find.text('Add account').last);
      await tester.tap(find.text('Add account').last);
      await tester.pumpAndSettle();
      final accounts = await database.accountsForUser(userA);
      expect(accounts, hasLength(1));
      expect(accounts.single.name, 'Travel cash');
      expect(accounts.single.balance, 42.5);
      expect(
        (await database.outboxCommandById(accounts.single.id))?.status,
        'pending',
      );
      expect(
        router.routeInformationProvider.value.uri.path,
        '/accounts/${accounts.single.id}',
      );
      expect(find.textContaining('pending sync'), findsOneWidget);
      await finish(tester);
    },
  );

  testWidgets('Details and edit queue metadata; balance is read-only', (
    tester,
  ) async {
    final id = await seed();
    final router = await mount(tester, '/accounts');
    await tester.tap(find.text('Daily cash'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/accounts/$id');
    expect(find.text('NPR 1,234.50'), findsOneWidget);
    await tapVisible(tester, find.text('Edit account'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('account-opening')), findsNothing);
    expect(find.textContaining('Balance changes only'), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('account-name')),
      '  Updated cash  ',
    );
    await tapVisible(tester, find.text('Save changes'));
    await tester.pumpAndSettle();
    expect((await database.accountById(id))?.balance, 1234.5);
    expect((await database.accountById(id))?.name, 'Daily cash');
    final commands = await database.select(database.outboxCommands).get();
    expect(commands, hasLength(1));
    expect(commands.single.commandType, 'update_account');
    expect(commands.single.expectedVersion, 1);
    expect(find.textContaining('pending sync'), findsOneWidget);
    await finish(tester);
  });

  testWidgets('Archive requires confirmation, queues without hard deletion', (
    tester,
  ) async {
    final id = await seed();
    await mount(tester, '/accounts/$id');
    await tapVisible(tester, find.text('Archive account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(await database.select(database.outboxCommands).get(), isEmpty);
    await tapVisible(tester, find.text('Archive account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Archive').last);
    await tester.pumpAndSettle();
    expect((await database.accountById(id))?.deletedAt, isNull);
    final commands = await database.select(database.outboxCommands).get();
    expect(commands.single.commandType, 'archive_account');
    expect(find.textContaining('pending sync'), findsOneWidget);
    await finish(tester);
  });

  testWidgets('User B cannot read User A account or total', (tester) async {
    final id = await seed(name: 'Private savings', balance: 500000);
    currentUser = userB;
    await database.upsertSyncState(
      SyncStatesCompanion.insert(userId: userB, dataGeneration: const Value(1)),
    );
    await mount(tester, '/accounts', userId: userB);
    expect(find.text('Private savings'), findsNothing);
    expect(find.textContaining('No accounts yet'), findsOneWidget);
    final router = makeRouter('/accounts/$id');
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userB)),
          accountsRepositoryProvider.overrideWithValue(repository),
        ],
        child: MaterialApp.router(
          theme: SanieTheme.light(),
          routerConfig: router,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Account unavailable.'), findsOneWidget);
    expect(find.text('NPR 500,000.00'), findsNothing);
    await finish(tester);
  });
}
