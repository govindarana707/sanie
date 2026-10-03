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
}
