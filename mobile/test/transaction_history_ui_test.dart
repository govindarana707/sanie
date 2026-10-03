import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:sanie/app/design/finance_app_shell.dart';
import 'package:sanie/app/design/sanie_theme.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/features/accounts/accounts_repository.dart';
import 'package:sanie/features/categories/categories_repository.dart';
import 'package:sanie/features/transactions/transaction_history_pages.dart';

const userId = '11111111-1111-4111-8111-111111111111';

void main() {
  late AppDatabase database;
  late AccountsRepository accounts;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    accounts = AccountsRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userId,
        dataGeneration: const Value(1),
      ),
    );
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: 'cash',
        userId: userId,
        name: 'Cash wallet',
        accountType: 'cash',
        createdAt: DateTime.utc(2026, 10, 1),
        updatedAt: DateTime.utc(2026, 10, 1),
      ),
    );
    await database.upsertAccount(
      AccountsCompanion.insert(
        id: 'bank',
        userId: userId,
        name: 'Bank account',
        accountType: 'bank',
        createdAt: DateTime.utc(2026, 10, 1),
        updatedAt: DateTime.utc(2026, 10, 1),
      ),
    );
  });
  tearDown(() async => database.close());

  Future<void> seed(
    String id,
    String type,
    String date,
    String note,
    int minute,
  ) => database.upsertTransaction(
    TransactionsCompanion.insert(
      id: id,
      userId: userId,
      amount: 125.5,
      transactionType: type,
      transactionDate: date,
      accountId: type == 'transfer' ? const Value(null) : const Value('cash'),
      fromAccountId: type == 'transfer'
          ? const Value('cash')
          : const Value(null),
      toAccountId: type == 'transfer' ? const Value('bank') : const Value(null),
      description: Value(note),
      createdAt: DateTime.utc(2026, 10, 2, 12, minute),
      updatedAt: DateTime.utc(2026, 10, 2, 12, minute),
    ),
  );

  Future<void> seedEditable(String id, String type) async {
    final categoryId = '$type-category';
    final recorded = DateTime.utc(2026, 10, 2, 12);
    await database
        .into(database.categories)
        .insert(
          CategoriesCompanion.insert(
            id: categoryId,
            userId: const Value(userId),
            name: '${type == 'income' ? 'Income' : 'Expense'} category',
            categoryType: type,
            createdAt: recorded,
            updatedAt: recorded,
          ),
        );
    await database.upsertTransaction(
      TransactionsCompanion.insert(
        id: id,
        userId: userId,
        accountId: const Value('cash'),
        categoryId: Value(categoryId),
        amount: 125.5,
        transactionType: type,
        transactionDate: '2026-10-02',
        description: const Value('Original note'),
        createdAt: recorded,
        updatedAt: recorded,
      ),
    );
  }

  Future<void> mount(
    WidgetTester tester, {
    String location = '/transactions',
  }) async {
    final router = GoRouter(
      initialLocation: location,
      routes: [
        ShellRoute(
          builder: (context, state, child) =>
              FinanceAppShell(location: state.uri.path, child: child),
          routes: [
            GoRoute(
              path: '/',
              builder: (_, _) => const Center(child: Text('Home')),
            ),
            GoRoute(
              path: '/transactions',
              builder: (_, _) => const TransactionHistoryPage(),
            ),
            GoRoute(
              path: '/transactions/:id',
              builder: (_, state) =>
                  TransactionDetailsPage(id: state.pathParameters['id']!),
            ),
            GoRoute(
              path: '/transactions/:id/edit',
              builder: (_, state) =>
                  TransactionEditPage(id: state.pathParameters['id']!),
            ),
          ],
        ),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(accounts),
          categoriesBootstrapProvider(userId).overrideWith((ref) async {}),
        ],
        child: MaterialApp.router(
          theme: SanieTheme.light(),
          routerConfig: router,
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> finish(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 1));
  }

  testWidgets(
    'newest first, type and account context, details and navigation',
    (tester) async {
      await seed('old', 'expense', '2026-10-01', 'Groceries', 1);
      await seed('new', 'income', '2026-10-02', 'Salary', 2);
      await seed('transfer', 'transfer', '2026-10-02', 'Move funds', 1);
      await database
          .into(database.outboxCommands)
          .insert(
            OutboxCommandsCompanion.insert(
              id: 'new',
              userId: userId,
              clientRequestId: 'new-request',
              commandType: 'create_income',
              payloadJson: '{}',
              dataGeneration: 1,
              createdAt: DateTime.utc(2026, 10, 2, 12, 2),
              updatedAt: DateTime.utc(2026, 10, 2, 12, 2),
            ),
          );
      await mount(tester, location: '/');
      await tester.tap(find.text('Transactions').last);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('history-row-new')), findsOneWidget);
      expect(find.byKey(const Key('history-row-transfer')), findsOneWidget);
      expect(find.textContaining('Pending sync'), findsOneWidget);
      final newY = tester
          .getTopLeft(find.byKey(const Key('history-row-new')))
          .dy;
      final transferY = tester
          .getTopLeft(find.byKey(const Key('history-row-transfer')))
          .dy;
      final oldY = tester
          .getTopLeft(find.byKey(const Key('history-row-old')))
          .dy;
      expect(newY, lessThan(transferY));
      expect(transferY, lessThan(oldY));
      expect(find.textContaining('Cash wallet → Bank account'), findsOneWidget);
      await tester.tap(find.byKey(const Key('history-row-new')));
      await tester.pumpAndSettle();
      expect(find.text('Transaction details'), findsOneWidget);
      expect(find.text('Salary'), findsWidgets);
      expect(find.text('Recorded'), findsOneWidget);
      expect(find.text('NPR 125.50'), findsOneWidget);
      await tester.tap(find.text('Transactions').first);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('history-row-new')), findsOneWidget);
      await finish(tester);
    },
  );

  testWidgets('type filters and search', (tester) async {
    await seed('income', 'income', '2026-10-02', 'Paycheck', 1);
    await seed('expense', 'expense', '2026-10-02', 'Market', 2);
    await seed('transfer', 'transfer', '2026-10-02', 'Move', 3);
    await mount(tester);
    for (final type in ['income', 'expense', 'transfer']) {
      await tester.tap(find.byKey(Key('history-filter-$type')));
      await tester.pumpAndSettle();
      expect(find.byKey(Key('history-row-$type')), findsOneWidget);
      for (final other in [
        'income',
        'expense',
        'transfer',
      ].where((x) => x != type)) {
        expect(find.byKey(Key('history-row-$other')), findsNothing);
      }
    }
    await tester.tap(find.byKey(const Key('history-filter-all')));
    await tester.enterText(find.byKey(const Key('history-search')), 'market');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('history-row-expense')), findsOneWidget);
    expect(find.byKey(const Key('history-row-income')), findsNothing);
    expect(find.byKey(const Key('history-row-transfer')), findsNothing);
    await finish(tester);
  });

  testWidgets('empty state and unmatched details', (tester) async {
    await mount(tester);
    expect(find.textContaining('No transactions yet'), findsOneWidget);
    await finish(tester);
    await mount(tester, location: '/transactions/missing');
    expect(find.text('Transaction not found.'), findsOneWidget);
    await finish(tester);
  });

  for (final type in ['income', 'expense']) {
    testWidgets('$type edit pre-fills and refreshes History', (tester) async {
      final id = '$type-edit';
      await seedEditable(id, type);
      final beforeBalance = (await database.accountById('cash'))!.balance;
      await mount(tester, location: '/transactions/$id');
      await tester.drag(find.byType(ListView).first, const Offset(0, -300));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('transaction-edit-action')));
      await tester.pumpAndSettle();
      expect(
        find.text('Edit ${type == 'income' ? 'Income' : 'Expense'}'),
        findsOneWidget,
      );
      expect(
        tester
            .widget<TextFormField>(find.byKey(const Key('transaction-amount')))
            .controller
            ?.text,
        '125.50',
      );
      expect(
        tester
            .widget<TextFormField>(find.byKey(const Key('transaction-note')))
            .controller
            ?.text,
        'Original note',
      );
      expect(find.textContaining('Date: 2026-10-02'), findsOneWidget);
      expect(find.textContaining('Cash wallet'), findsWidgets);
      expect(
        find.text('${type == 'income' ? 'Income' : 'Expense'} category'),
        findsWidgets,
      );
      await tester.enterText(
        find.byKey(const Key('transaction-amount')),
        '150.25',
      );
      await tester.enterText(
        find.byKey(const Key('transaction-note')),
        'Edited note',
      );
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      await tester.drag(find.byType(ListView).first, const Offset(0, -450));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();
      expect(find.byKey(Key('history-row-$id')), findsOneWidget);
      expect((await database.transactionById(id))?.amount, 150.25);
      expect((await database.accountById('cash'))?.balance, beforeBalance);
      await tester.tap(find.byKey(Key('history-row-$id')));
      await tester.pumpAndSettle();
      expect(find.text('NPR 150.25'), findsOneWidget);
      expect(find.text('Edited note'), findsOneWidget);
      expect(find.text('Pending sync'), findsOneWidget);
      expect(find.byKey(const Key('transaction-edit-action')), findsNothing);
      await finish(tester);
    });
  }

  testWidgets(
    'delete confirmation tombstones and removes active history item',
    (tester) async {
      await seedEditable('delete-me', 'expense');
      final beforeBalance = (await database.accountById('cash'))!.balance;
      await mount(tester, location: '/transactions/delete-me');
      await tester.drag(find.byType(ListView).first, const Offset(0, -300));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('transaction-delete-action')));
      await tester.pumpAndSettle();
      expect(find.text('Delete transaction?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect((await database.transactionById('delete-me'))?.deletedAt, isNull);
      await tester.tap(find.byKey(const Key('transaction-delete-action')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('confirm-transaction-delete')));
      await tester.pumpAndSettle();
      expect(
        (await database.transactionById('delete-me'))?.deletedAt,
        isNotNull,
      );
      expect((await database.accountById('cash'))?.balance, beforeBalance);
      expect(find.byKey(const Key('history-row-delete-me')), findsNothing);
      expect(find.textContaining('No transactions yet'), findsOneWidget);
      await finish(tester);
    },
  );

  testWidgets('Transfer details have no mutation actions', (tester) async {
    await seed('transfer-only', 'transfer', '2026-10-02', 'Move', 1);
    await mount(tester, location: '/transactions/transfer-only');
    expect(find.text('Transfer'), findsWidgets);
    expect(find.byKey(const Key('transaction-edit-action')), findsNothing);
    expect(find.byKey(const Key('transaction-delete-action')), findsNothing);
    await finish(tester);
  });
}
