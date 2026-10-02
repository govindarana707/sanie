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
import 'package:sanie/features/home/home_repository.dart';
import 'package:sanie/features/home/presentation/home_page.dart';

const userA = '11111111-1111-4111-8111-111111111111';
const userB = '22222222-2222-4222-8222-222222222222';
final today = DateTime(2026, 10, 2);

void main() {
  late AppDatabase database;
  late HomeRepository home;
  late AccountsRepository accounts;

  setUp(() {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    home = HomeRepository(database);
    accounts = AccountsRepository(
      database: database,
      authenticatedUserId: () => userA,
    );
  });
  tearDown(() async => database.close());

  Future<void> seedAccount({
    String userId = userA,
    double balance = 12450,
  }) async {
    final time = DateTime.utc(2026, 10, 2);
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: database.newId(),
        userId: userId,
        name: 'Cash',
        accountType: 'cash',
        balance: Value(balance),
        createdAt: time,
        updatedAt: time,
      ),
    );
  }

  Future<void> seedTransaction(
    String type,
    double amount,
    String description, {
    String userId = userA,
    String date = '2026-10-02',
  }) async {
    final time = DateTime.utc(2026, 10, 2);
    await database.upsertTransaction(
      TransactionsCompanion.insert(
        id: database.newId(),
        userId: userId,
        amount: amount,
        transactionType: type,
        transactionDate: date,
        description: Value(description),
        createdAt: time,
        updatedAt: time,
      ),
    );
  }

  GoRouter router(String initial) => GoRouter(
    initialLocation: initial,
    routes: [
      ShellRoute(
        builder: (context, state, child) =>
            FinanceAppShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(
            path: '/',
            builder: (context, state) => HomePage(clock: () => today),
          ),
          GoRoute(path: '/more', builder: (context, state) => const MorePage()),
          GoRoute(
            path: '/accounts',
            builder: (context, state) => const AccountsPage(),
          ),
          GoRoute(
            path: '/transactions',
            builder: (context, state) => const Text('Transactions page'),
          ),
          GoRoute(
            path: '/budget',
            builder: (context, state) => const Text('Budget page'),
          ),
        ],
      ),
    ],
  );

  Future<GoRouter> mount(
    WidgetTester tester, {
    ThemeData? theme,
    String initial = '/',
  }) async {
    final navigation = router(initial);
    addTearDown(navigation.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userA)),
          homeIdentityProvider.overrideWithValue(
            const HomeIdentity(
              userId: userA,
              email: 'person@example.test',
              displayName: 'Maya',
            ),
          ),
          homeRepositoryProvider.overrideWithValue(home),
          accountsRepositoryProvider.overrideWithValue(accounts),
        ],
        child: MaterialApp.router(
          theme: theme ?? SanieTheme.light(),
          routerConfig: navigation,
        ),
      ),
    );
    await tester.pumpAndSettle();
    return navigation;
  }

  Future<void> finish(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 1));
  }

  test('local dashboard snapshot uses only scoped real values', () async {
    final empty = await home.load(userA, today);
    expect(empty.hasAccounts, isFalse);
    expect(empty.balance, 0);
    expect(empty.income, 0);
    expect(empty.expense, 0);
    expect(empty.budgetTotal, 0);
    expect(empty.recent, isEmpty);

    await seedAccount();
    await seedAccount(userId: userB, balance: 999999);
    await seedTransaction('income', 18000, 'Salary');
    await seedTransaction('expense', 5550, 'Groceries');
    await seedTransaction('income', 123456, 'Other user', userId: userB);
    await seedTransaction('expense', 600, 'Last month', date: '2026-09-30');
    final time = DateTime.utc(2026, 10, 2);
    await database
        .into(database.budgets)
        .insert(
          BudgetsCompanion.insert(
            id: database.newId(),
            userId: userA,
            name: 'October',
            amount: 8000,
            startDate: '2026-10-01',
            endDate: '2026-10-31',
            createdAt: time,
            updatedAt: time,
          ),
        );
    final snapshot = await home.load(userA, today);
    expect(snapshot.hasAccounts, isTrue);
    expect(snapshot.balance, 12450);
    expect(snapshot.income, 18000);
    expect(snapshot.expense, 5550);
    expect(snapshot.budgetSpent, 5550);
    expect(snapshot.budgetTotal, 8000);
    expect(
      snapshot.recent.map((item) => item.title),
      isNot(contains('Other user')),
    );
  });

  testWidgets(
    'reference sections render authenticated identity and honest zero state',
    (tester) async {
      tester.view.physicalSize = const Size(393, 852);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await mount(tester);
      expect(find.text('SanIE'), findsOneWidget);
      expect(find.text('Maya 👋'), findsOneWidget);
      expect(find.text('person@example.test'), findsOneWidget);
      expect(find.text('Total Balance'), findsOneWidget);
      expect(find.text('Rs 0.00'), findsNWidgets(3));
      expect(find.text('Budget Progress'), findsOneWidget);
      await tester.ensureVisible(find.text('Recent Transactions'));
      expect(find.text('No recent transactions yet'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await finish(tester);
    },
  );

  testWidgets(
    'real local balance, income, expense and recent activity render',
    (tester) async {
      await seedAccount();
      await seedTransaction('income', 18000, 'Salary');
      await seedTransaction('expense', 5550, 'Groceries');
      await mount(tester);
      expect(find.text('Rs 12,450.00'), findsOneWidget);
      expect(find.text('Rs 18,000.00'), findsOneWidget);
      expect(find.text('Rs 5,550.00'), findsOneWidget);
      await tester.ensureVisible(find.text('Recent Transactions'));
      expect(find.text('Salary'), findsOneWidget);
      expect(find.text('Groceries'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await finish(tester);
    },
  );

  testWidgets(
    'bottom labels stay single-line and More still reaches Accounts',
    (tester) async {
      tester.view.physicalSize = const Size(320, 700);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final navigation = await mount(tester);
      for (final label in ['Home', 'Transactions', 'Add', 'Budget', 'More']) {
        expect(find.text(label), findsOneWidget);
        final text = tester.widget<Text>(find.text(label));
        expect(text.maxLines, 1);
      }
      expect(tester.takeException(), isNull);
      await tester.tap(find.text('More'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Accounts'));
      await tester.pumpAndSettle();
      expect(navigation.routeInformationProvider.value.uri.path, '/accounts');
      await finish(tester);
    },
  );

  testWidgets('light and dark Home render without overflow', (tester) async {
    tester.view.physicalSize = const Size(393, 852);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    for (final theme in [SanieTheme.light(), SanieTheme.dark()]) {
      await mount(tester, theme: theme);
      await tester.ensureVisible(find.text('Recent Transactions'));
      expect(tester.takeException(), isNull);
      await finish(tester);
    }
  });
}
