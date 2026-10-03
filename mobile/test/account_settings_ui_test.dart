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
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/features/accounts/accounts_pages.dart';
import 'package:sanie/features/accounts/accounts_repository.dart';
import 'package:sanie/features/categories/categories_repository.dart';
import 'package:sanie/features/home/home_repository.dart';
import 'package:sanie/features/home/presentation/home_page.dart';
import 'package:sanie/features/transactions/transaction_form_page.dart';
import 'package:sanie/features/transactions/transaction_repository.dart';
import 'package:sanie/features/transactions/transfer_form_page.dart';
import 'package:sanie/features/transactions/transfer_repository.dart';

const userId = '11111111-1111-4111-8111-111111111111';
final now = DateTime.utc(2026, 10, 3);

class _SettingsPushFake implements PushSyncTransport {
  bool failCreate = true;
  final calls = <String>[];

  @override
  String? get authenticatedUserId => userId;

  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {
    calls.add(name);
    if (name == 'create_account' && failCreate) {
      throw const PushSyncRpcException();
    }
  }
}

void main() {
  late AppDatabase database;
  late AccountsRepository accounts;
  late TransactionRepository transactions;
  late TransferRepository transfers;
  late CategoriesRepository categories;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    accounts = AccountsRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    transactions = TransactionRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    transfers = TransferRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    categories = CategoriesRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userId,
        dataGeneration: const Value(7),
      ),
    );
  });
  tearDown(() => database.close());

  Future<void> seedAccount(
    String id, {
    bool isDefault = false,
    bool isActive = true,
    bool includeInNetBalance = true,
    bool includeInSavings = false,
    double balance = 0,
  }) => database.upsertAccount(
    AccountsCompanion.insert(
      id: id,
      userId: userId,
      name: id,
      accountType: 'cash',
      balance: Value(balance),
      isDefault: Value(isDefault),
      isActive: Value(isActive),
      includeInNetBalance: Value(includeInNetBalance),
      includeInSavings: Value(includeInSavings),
      createdAt: now,
      updatedAt: now,
    ),
  );

  GoRouter router(String initial) => GoRouter(
    initialLocation: initial,
    routes: [
      ShellRoute(
        builder: (_, state, child) =>
            FinanceAppShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => HomePage(clock: () => now),
          ),
          GoRoute(path: '/more', builder: (_, _) => const MorePage()),
          GoRoute(path: '/accounts', builder: (_, _) => const AccountsPage()),
          GoRoute(
            path: '/accounts/add',
            builder: (_, _) => const AccountFormPage(),
          ),
          GoRoute(
            path: '/accounts/:id',
            builder: (_, state) =>
                AccountDetailsPage(id: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/accounts/:id/edit',
            builder: (_, state) =>
                AccountFormPage(id: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/add/income',
            builder: (_, _) => const TransactionFormPage(type: 'income'),
          ),
          GoRoute(
            path: '/add/expense',
            builder: (_, _) => const TransactionFormPage(type: 'expense'),
          ),
          GoRoute(
            path: '/add/transfer',
            builder: (_, _) => const TransferFormPage(),
          ),
          GoRoute(
            path: '/transactions',
            builder: (_, _) => const Scaffold(body: Text('Transactions')),
          ),
        ],
      ),
    ],
  );

  Future<GoRouter> mount(WidgetTester tester, String location) async {
    final navigation = router(location);
    addTearDown(navigation.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(accounts),
          transactionRepositoryProvider.overrideWithValue(transactions),
          transferRepositoryProvider.overrideWithValue(transfers),
          categoriesRepositoryProvider.overrideWithValue(categories),
          categoriesBootstrapProvider(userId).overrideWith((ref) async {}),
          homeRepositoryProvider.overrideWithValue(HomeRepository(database)),
          homeIdentityProvider.overrideWithValue(
            const HomeIdentity(
              userId: userId,
              email: 'settings@example.test',
              displayName: 'Settings',
            ),
          ),
        ],
        child: MaterialApp.router(
          theme: SanieTheme.light(),
          routerConfig: navigation,
        ),
      ),
    );
    await tester.pumpAndSettle();
    return navigation;
  }

  Future<void> toggle(WidgetTester tester, String key) async {
    final finder = find.byKey(Key(key));
    await Scrollable.ensureVisible(
      tester.element(finder),
      alignment: 0.25,
      duration: Duration.zero,
    );
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  Future<void> tapSaveSettings(WidgetTester tester) async {
    final finder = find.byKey(const Key('account-save-settings'));
    await Scrollable.ensureVisible(
      tester.element(finder),
      alignment: 0.25,
      duration: Duration.zero,
    );
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  Future<void> finish(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 1));
  }

  testWidgets('Add shows four settings and queues a default switch', (
    tester,
  ) async {
    await seedAccount('A', isDefault: true, balance: 100);
    await mount(tester, '/accounts/add');
    for (final key in [
      'account-setting-default',
      'account-setting-net-balance',
      'account-setting-savings',
      'account-setting-active',
    ]) {
      expect(find.byKey(Key(key)), findsOneWidget);
    }
    await toggle(tester, 'account-setting-default');
    await toggle(tester, 'account-setting-net-balance');
    await toggle(tester, 'account-setting-savings');
    await tester.enterText(find.byKey(const Key('account-name')), 'New bank');
    await tester.ensureVisible(find.text('Add account').last);
    await tester.tap(find.text('Add account').last);
    await tester.pumpAndSettle();
    final rows = await database.accountsForUser(userId);
    final added = rows.singleWhere((row) => row.name == 'New bank');
    expect(added.isDefault, true);
    expect(added.includeInNetBalance, false);
    expect(added.includeInSavings, true);
    expect(added.isActive, true);
    expect((await database.accountById('A'))!.isDefault, false);
    final queued = await database.select(database.outboxCommands).get();
    expect(
      queued.map((row) => row.commandType),
      containsAll(['create_account', 'update_account_settings']),
    );
    expect(find.byKey(const Key('account-setting-savings')), findsOneWidget);
    await finish(tester);
  });

  testWidgets('Edit switches default, and Details reflects local settings', (
    tester,
  ) async {
    await seedAccount('A', isDefault: true);
    await seedAccount('B');
    await mount(tester, '/accounts/B/edit');
    await toggle(tester, 'account-setting-default');
    await toggle(tester, 'account-setting-savings');
    await tapSaveSettings(tester);
    expect((await database.accountById('A'))!.isDefault, false);
    final b = (await database.accountById('B'))!;
    expect(b.isDefault, true);
    expect(b.includeInSavings, true);
    expect(find.byKey(const Key('account-setting-default')), findsOneWidget);
    expect(
      tester
          .widget<SwitchListTile>(
            find.byKey(const Key('account-setting-default')),
          )
          .value,
      true,
    );
    await finish(tester);
  });

  testWidgets('Inactive account stays visible and can be reactivated', (
    tester,
  ) async {
    await seedAccount('A', isDefault: true);
    await seedAccount('B');
    await mount(tester, '/accounts/B/edit');
    await toggle(tester, 'account-setting-active');
    await tapSaveSettings(tester);
    expect((await database.accountById('B'))!.isActive, false);
    expect((await database.accountById('B'))!.deletedAt, isNull);
    expect(find.textContaining('Inactive'), findsWidgets);
    await finish(tester);
  });

  testWidgets('Inactive account can be reactivated without archive', (
    tester,
  ) async {
    await seedAccount('A', isDefault: true);
    await seedAccount('B', isActive: false);
    await mount(tester, '/accounts/B/edit');
    await toggle(tester, 'account-setting-active');
    await tapSaveSettings(tester);
    expect((await database.accountById('B'))!.isActive, true);
    expect((await database.accountById('B'))!.deletedAt, isNull);
    expect(find.textContaining('Status: Active'), findsOneWidget);
    await finish(tester);
  });

  testWidgets('Home total refreshes after net balance exclusion', (
    tester,
  ) async {
    await seedAccount('A', isDefault: true, balance: 100);
    await seedAccount('B', balance: 50);
    await mount(tester, '/');
    expect(find.textContaining('150'), findsWidgets);
    await accounts.updateSettings(
      id: 'B',
      isDefault: false,
      includeInNetBalance: false,
      includeInSavings: false,
      isActive: true,
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('100'), findsWidgets);
    expect(
      find.textContaining('Across included active accounts'),
      findsOneWidget,
    );
    await finish(tester);
  });

  test('net balance excludes and includes account locally', () async {
    await seedAccount('A', isDefault: true, balance: 100);
    await seedAccount('B', balance: 50);
    final home = HomeRepository(database);
    expect((await home.load(userId, now)).balance, 150);
    await accounts.updateSettings(
      id: 'B',
      isDefault: false,
      includeInNetBalance: false,
      includeInSavings: true,
      isActive: true,
    );
    expect((await home.load(userId, now)).balance, 100);
    expect((await database.accountById('B'))!.includeInSavings, true);
    await seedAccount('C', balance: 20, includeInNetBalance: false);
    await accounts.updateSettings(
      id: 'C',
      isDefault: false,
      includeInNetBalance: true,
      includeInSavings: false,
      isActive: true,
    );
    expect((await home.load(userId, now)).balance, 120);
  });

  test('Add settings waits for the account create command', () async {
    await seedAccount('A', isDefault: true);
    final id = await accounts.create(
      name: 'New',
      type: 'bank',
      openingBalance: 20,
      isDefault: true,
      includeInSavings: true,
    );
    final due = await OutboxStore(database)
        .dueCommandsForUser(userId, now: now);
    expect(due.map((row) => row.commandType), [
      'create_account',
      'update_account_settings',
    ]);
    expect(due.first.id, id);
    final fake = _SettingsPushFake();
    var clock = now;
    final push = PushSyncService(
      outbox: OutboxStore(database),
      transport: fake,
      clock: () => clock,
    );
    final first = await push.pushForAuthenticatedUser();
    expect(first.retried, 1);
    expect(first.deferred, 1);
    expect(fake.calls, ['create_account']);
    fake.failCreate = false;
    clock = now.add(const Duration(seconds: 2));
    final second = await push.pushForAuthenticatedUser();
    expect(second.completed, 1);
    expect(second.deferred, 1);
    final third = await push.pushForAuthenticatedUser();
    expect(third.completed, 1);
    expect(fake.calls, [
      'create_account',
      'create_account',
      'update_account_settings',
    ]);
  });

  testWidgets(
    'new Income and Expense preselect default; inactive accounts are absent',
    (tester) async {
      await seedAccount('A', isDefault: true);
      await seedAccount('B');
      await seedAccount('Inactive', isActive: false);
      final navigation = await mount(tester, '/add/income');
      FormFieldState<String> accountField() =>
          tester.state<FormFieldState<String>>(
            find.byType(DropdownButtonFormField<String>).first,
          );
      expect(accountField().value, 'A');
      expect(
        (await transactions.watchAccounts(userId).first).map(
          (account) => account.id,
        ),
        isNot(contains('Inactive')),
      );
      await accounts.updateSettings(
        id: 'B',
        isDefault: true,
        includeInNetBalance: true,
        includeInSavings: false,
        isActive: true,
      );
      navigation.go('/add/expense');
      await tester.pumpAndSettle();
      expect(accountField().value, 'B');
      navigation.go('/add/transfer');
      await tester.pumpAndSettle();
      expect(find.textContaining('Inactive'), findsNothing);
      await finish(tester);
    },
  );
}
