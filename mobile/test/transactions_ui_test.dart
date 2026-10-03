import 'dart:convert';

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
import 'package:sanie/features/accounts/accounts_repository.dart';
import 'package:sanie/features/categories/categories_repository.dart';
import 'package:sanie/features/home/home_repository.dart';
import 'package:sanie/features/home/presentation/home_page.dart';
import 'package:sanie/features/transactions/transaction_form_page.dart';
import 'package:sanie/features/transactions/transaction_repository.dart';

const userId = '11111111-1111-4111-8111-111111111111';
const otherUser = '22222222-2222-4222-8222-222222222222';

void main() {
  late AppDatabase database;
  late AccountsRepository accounts;
  late CategoriesRepository categories;
  late TransactionRepository transactions;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    accounts = AccountsRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    categories = CategoriesRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    transactions = TransactionRepository(
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
  tearDown(() async => database.close());

  Future<String> seedAccount({
    String name = 'Cash',
    double balance = 100,
    String owner = userId,
  }) async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 3);
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: id,
        userId: owner,
        name: name,
        accountType: 'cash',
        balance: Value(balance),
        createdAt: now,
        updatedAt: now,
      ),
    );
    return id;
  }

  Future<String> seedCategory(
    String type, {
    String? name,
    bool system = false,
    String owner = userId,
  }) async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 3);
    await database
        .into(database.categories)
        .insert(
          CategoriesCompanion.insert(
            id: id,
            userId: Value(system ? null : owner),
            name: name ?? type,
            categoryType: type,
            isSystem: Value(system),
            createdAt: now,
            updatedAt: now,
          ),
        );
    return id;
  }

  Future<String> seedSubcategory(String parent, String name) async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 3);
    await database
        .into(database.subcategories)
        .insert(
          SubcategoriesCompanion.insert(
            id: id,
            userId: const Value(userId),
            categoryId: parent,
            name: name,
            createdAt: now,
            updatedAt: now,
          ),
        );
    return id;
  }

  GoRouter makeRouter(String location) => GoRouter(
    initialLocation: location,
    routes: [
      ShellRoute(
        builder: (_, state, child) =>
            FinanceAppShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(path: '/', builder: (_, _) => const HomePage()),
          GoRoute(
            path: '/add/income',
            builder: (_, _) => const TransactionFormPage(type: 'income'),
          ),
          GoRoute(
            path: '/add/expense',
            builder: (_, state) => TransactionFormPage(
              type: 'expense',
              initialAmount: state.uri.queryParameters['amount'],
              initialCategoryId: state.uri.queryParameters['category'],
              initialSubcategoryId: state.uri.queryParameters['subcategory'],
              initialDate: state.uri.queryParameters['date'],
              initialNote: state.uri.queryParameters['note'],
              replacingFailedId: state.uri.queryParameters['replace'],
            ),
          ),
          GoRoute(
            path: '/transactions',
            builder: (_, _) => const Scaffold(body: Text('Transactions')),
          ),
          GoRoute(path: '/more', builder: (_, _) => const MorePage()),
        ],
      ),
    ],
  );

  Future<GoRouter> mount(WidgetTester tester, String location) async {
    final router = makeRouter(location);
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(accounts),
          categoriesRepositoryProvider.overrideWithValue(categories),
          transactionRepositoryProvider.overrideWithValue(transactions),
          categoriesBootstrapProvider(userId).overrideWith((ref) async {}),
          homeIdentityProvider.overrideWithValue(
            const HomeIdentity(
              userId: userId,
              email: 'test@example.com',
              displayName: 'Test',
            ),
          ),
          homeRepositoryProvider.overrideWithValue(HomeRepository(database)),
        ],
        child: MaterialApp.router(
          theme: SanieTheme.light(),
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

  testWidgets('Center Add chooser routes Income and Expense', (tester) async {
    await seedAccount();
    await seedCategory('income');
    await seedCategory('expense');
    final router = await mount(tester, '/');
    await tester.tap(find.text('Add'));
    await tester.pumpAndSettle();
    expect(find.text('Income'), findsOneWidget);
    expect(find.text('Expense'), findsOneWidget);
    expect(find.text('Transfer'), findsOneWidget);
    await tester.tap(find.byKey(const Key('add-Income')));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/add/income');
    router.go('/');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('add-Expense')));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/add/expense');
    await finish(tester);
  });

  testWidgets('Forms validate positive amount, account and category', (
    tester,
  ) async {
    await seedAccount();
    await seedCategory('expense');
    await mount(tester, '/add/expense');
    await tester.drag(find.byType(ListView), const Offset(0, -350));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -150));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save Expense'));
    await tester.pumpAndSettle();
    expect(find.textContaining('above zero'), findsOneWidget);
    expect(find.text('Choose an account.'), findsOneWidget);
    expect(find.text('Choose a category.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('transaction-amount')), '0');
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -350));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save Expense'));
    await tester.pumpAndSettle();
    expect(find.textContaining('above zero'), findsOneWidget);
    await finish(tester);
  });

  testWidgets('Category type filters and subcategory resets on parent change', (
    tester,
  ) async {
    await seedAccount();
    final first = await seedCategory('expense', name: 'Food', system: true);
    await seedCategory('expense', name: 'Travel');
    await seedCategory('income', name: 'Salary');
    await seedSubcategory(first, 'Bakery');
    await mount(tester, '/add/expense');
    await tester.tap(find.byKey(const ValueKey('transaction-category:null')));
    await tester.pumpAndSettle();
    expect(find.text('Food'), findsWidgets);
    expect(find.text('Travel'), findsWidgets);
    expect(find.text('Salary'), findsNothing);
    await tester.tap(find.text('Food').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('transaction-subcategory')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bakery').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(ValueKey('transaction-category:$first')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Travel').last);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('transaction-subcategory')), findsNothing);
    await finish(tester);
  });

  testWidgets('Income saves once, returns Home and shows local data', (
    tester,
  ) async {
    await seedAccount(balance: 100);
    await seedCategory('income', name: 'Salary');
    final router = await mount(tester, '/add/income');
    await tester.enterText(
      find.byKey(const Key('transaction-amount')),
      '12.50',
    );
    await tester.enterText(find.byKey(const Key('transaction-note')), 'Payday');
    await tester.tap(find.byType(DropdownButtonFormField<String>).first);
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Cash').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Salary').last);
    await tester.pumpAndSettle();
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -350));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save Income'));
    await tester.tap(find.text('Save Income'), warnIfMissed: false);
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/');
    expect(await database.select(database.outboxCommands).get(), hasLength(1));
    expect(find.text('Payday'), findsOneWidget);
    expect(find.textContaining('112.50'), findsWidgets);
    await finish(tester);
  });

  testWidgets('Failed remote expense offers account replacement with details', (
    tester,
  ) async {
    final account = await seedAccount(balance: 100);
    await seedAccount(name: 'Bank', balance: 100);
    final category = await seedCategory('expense', name: 'Food');
    final id = await transactions.create(
      type: 'expense',
      accountId: account,
      categoryId: category,
      amount: 15,
      date: DateTime.now(),
      description: 'Dinner',
    );
    final now = DateTime.now().toUtc();
    await transactions.outbox.markProcessing(
      userId: userId,
      commandId: id,
      now: now,
    );
    await transactions.outbox.markPermanentFailure(
      userId: userId,
      commandId: id,
      now: now,
      errorCode: 'INSUFFICIENT_FUNDS',
      errorMessage: 'INSUFFICIENT_FUNDS',
    );
    final router = await mount(tester, '/');
    expect(find.textContaining('Expense could not sync'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, -250));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Replace payment account'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/add/expense');
    expect(find.text('Dinner'), findsOneWidget);
    expect(find.text('15.0'), findsOneWidget);
    expect(find.textContaining('Select account'), findsOneWidget);
    await tester.tap(find.byType(DropdownButtonFormField<String>).first);
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Bank').last);
    await tester.pumpAndSettle();
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -350));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save Expense'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/');
    expect((await database.outboxCommandById(id))!.lastErrorCode, 'REPLACED');
    expect(find.textContaining('Expense could not sync'), findsNothing);
    await finish(tester);
  });

  test(
    'Offline Income and Expense create durable commands and local rows',
    () async {
      final account = await seedAccount(balance: 100);
      final income = await seedCategory('income', system: true);
      final expense = await seedCategory('expense');
      final sub = await seedSubcategory(expense, 'Taxi');
      final firstId = await transactions.create(
        type: 'income',
        accountId: account,
        categoryId: income,
        amount: 20.29,
        date: DateTime(2026, 10, 3),
        description: 'Pay',
      );
      final secondId = await transactions.create(
        type: 'expense',
        accountId: account,
        categoryId: expense,
        subcategoryId: sub,
        amount: 5.50,
        date: DateTime(2026, 10, 3),
        description: 'Taxi',
      );
      final rows = await database.transactionsForUser(userId);
      expect(rows.map((row) => row.id), containsAll([firstId, secondId]));
      expect(rows.singleWhere((row) => row.id == secondId).subcategoryId, sub);
      final commands = await database.select(database.outboxCommands).get();
      expect(
        commands.map((row) => row.commandType),
        containsAll(['create_income', 'create_expense']),
      );
      expect(
        commands.every(
          (row) => row.status == 'pending' && row.dataGeneration == 7,
        ),
        isTrue,
      );
      expect(commands.every((row) => row.clientRequestId.isNotEmpty), isTrue);
      final expensePayload = jsonDecode(
        commands.singleWhere((row) => row.id == secondId).payloadJson,
      );
      expect(expensePayload['p_account'], account);
      expect(expensePayload['p_category'], expense);
      expect(expensePayload['p_subcategory'], sub);
      expect(expensePayload['p_date'], '2026-10-03');
      expect((await database.accountById(account))!.balance, 100);
    },
  );

  testWidgets(
    'Insufficient funds offers replacement without losing form values',
    (tester) async {
      final first = await seedAccount(name: 'Small cash', balance: 5);
      await seedAccount(name: 'Bank', balance: 100);
      await seedCategory('expense', name: 'Food');
      await mount(tester, '/add/expense');
      await tester.enterText(find.byKey(const Key('transaction-amount')), '20');
      await tester.enterText(
        find.byKey(const Key('transaction-note')),
        'Dinner',
      );
      await tester.tap(find.byKey(const Key('transaction-amount')));
      await tester.tap(find.byKey(const Key('transaction-date')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).first);
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('Small cash').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Food').last);
      await tester.pumpAndSettle();
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      await tester.drag(find.byType(ListView), const Offset(0, -350));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save Expense'));
      await tester.pumpAndSettle();
      expect(find.text('Insufficient funds'), findsOneWidget);
      await tester.tap(find.text('Replace payment account'));
      await tester.pumpAndSettle();
      expect(find.text('Dinner'), findsOneWidget);
      expect(find.text('20'), findsOneWidget);
      expect(await database.select(database.outboxCommands).get(), isEmpty);
      expect(first, isNotEmpty);
      await finish(tester);
    },
  );

  test(
    'Home totals use local working rows, pending balance and user isolation',
    () async {
      final account = await seedAccount(balance: 100);
      final income = await seedCategory('income');
      final expense = await seedCategory('expense');
      await seedAccount(name: 'Other account', balance: 999, owner: otherUser);
      await transactions.create(
        type: 'income',
        accountId: account,
        categoryId: income,
        amount: 20,
        date: DateTime(2026, 10, 3),
        description: 'Wages',
      );
      await transactions.create(
        type: 'expense',
        accountId: account,
        categoryId: expense,
        amount: 5,
        date: DateTime(2026, 10, 3),
        description: 'Snack',
      );
      final snapshot = await HomeRepository(database)
          .load(userId, DateTime(2026, 10, 3));
      expect(snapshot.balance, 115);
      expect(snapshot.income, 20);
      expect(snapshot.expense, 5);
      expect(
        snapshot.recent.map((row) => row.title),
        containsAll(['Wages', 'Snack']),
      );
      expect(snapshot.recent.first.title, 'Snack');
    },
  );
}
