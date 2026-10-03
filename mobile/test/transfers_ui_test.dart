import 'dart:convert';

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
import 'package:sanie/features/accounts/accounts_pages.dart';
import 'package:sanie/features/home/home_repository.dart';
import 'package:sanie/features/home/presentation/home_page.dart';
import 'package:sanie/features/transactions/transaction_form_page.dart';
import 'package:sanie/features/transactions/transaction_repository.dart';
import 'package:sanie/features/transactions/transfer_form_page.dart';
import 'package:sanie/features/transactions/transfer_repository.dart';

const userId = '11111111-1111-4111-8111-111111111111';
const otherUser = '22222222-2222-4222-8222-222222222222';

void main() {
  late AppDatabase database;
  late AccountsRepository accounts;
  late TransactionRepository reads;
  late TransferRepository transfers;

  setUp(() async {
    database = AppDatabase.forTesting(NativeDatabase.memory());
    accounts = AccountsRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    reads = TransactionRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    transfers = TransferRepository(
      database: database,
      authenticatedUserId: () => userId,
    );
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userId,
        dataGeneration: const Value(9),
      ),
    );
  });
  tearDown(() async => database.close());

  Future<String> seedAccount(
    String name,
    double balance, {
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

  Future<String> seedExpenseCategory() async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 3);
    await database
        .into(database.categories)
        .insert(
          CategoriesCompanion.insert(
            id: id,
            userId: const Value(userId),
            name: 'Bank fees',
            categoryType: 'expense',
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
          GoRoute(path: '/accounts', builder: (_, _) => const AccountsPage()),
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
            builder: (_, state) => TransferFormPage(
              initialAmount: state.uri.queryParameters['amount'],
              initialTo: state.uri.queryParameters['to'],
              initialFee: state.uri.queryParameters['fee'],
              initialFeeCategory: state.uri.queryParameters['feeCategory'],
              initialDate: state.uri.queryParameters['date'],
              initialNote: state.uri.queryParameters['note'],
              replacingFailedId: state.uri.queryParameters['replace'],
            ),
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
    final router = makeRouter(location);
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(accounts),
          transactionRepositoryProvider.overrideWithValue(reads),
          transferRepositoryProvider.overrideWithValue(transfers),
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

  Future<void> choose(WidgetTester tester, Finder dropdown, String name) async {
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
    await tester.ensureVisible(dropdown);
    await tester.pumpAndSettle();
    await tester.tap(dropdown);
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining(name).last);
    await tester.pumpAndSettle();
  }

  Future<void> tapSave(WidgetTester tester) async {
    tester.testTextInput.hide();
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView), const Offset(0, -500));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save transfer'));
    await tester.pumpAndSettle();
  }

  testWidgets(
    'Center Add opens Transfer; Income and Expense remain available',
    (tester) async {
      await seedAccount('Cash', 100);
      await seedAccount('Bank', 50);
      final router = await mount(tester, '/');
      await tester.tap(find.text('Add'));
      await tester.pumpAndSettle();
      expect(find.text('Income'), findsOneWidget);
      expect(find.text('Expense'), findsOneWidget);
      await tester.tap(find.byKey(const Key('add-Transfer')));
      await tester.pumpAndSettle();
      expect(router.routeInformationProvider.value.uri.path, '/add/transfer');
      expect(find.byKey(const Key('transfer-amount')), findsOneWidget);
      expect(find.byKey(const Key('transfer-fee')), findsOneWidget);
      await finish(tester);
    },
  );

  testWidgets('Two accounts required with route to Accounts', (tester) async {
    await seedAccount('Cash', 100);
    final router = await mount(tester, '/add/transfer');
    expect(find.textContaining('two active NPR accounts'), findsOneWidget);
    await tester.tap(find.text('View accounts'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/accounts');
    await finish(tester);
  });

  testWidgets('Form validates amount, account choice and same-account', (
    tester,
  ) async {
    await seedAccount('Cash', 100);
    await seedAccount('Bank', 100);
    await mount(tester, '/add/transfer');
    await tapSave(tester);
    expect(find.textContaining('above zero'), findsOneWidget);
    expect(find.text('Choose a source account.'), findsOneWidget);
    expect(find.text('Choose a destination account.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('transfer-amount')), '10');
    await choose(
      tester,
      find.byType(DropdownButtonFormField<String>).first,
      'Cash',
    );
    await choose(tester, find.byKey(const Key('transfer-destination')), 'Cash');
    await tapSave(tester);
    expect(
      find.text('Choose a different destination account.'),
      findsOneWidget,
    );
    expect(await database.select(database.outboxCommands).get(), isEmpty);
    await finish(tester);
  });

  testWidgets('Fee requires an expense category and shows total deducted', (
    tester,
  ) async {
    await seedAccount('Cash', 100);
    await seedAccount('Bank', 100);
    await seedExpenseCategory();
    await mount(tester, '/add/transfer');
    await tester.enterText(find.byKey(const Key('transfer-amount')), '20');
    await tester.enterText(find.byKey(const Key('transfer-fee')), '2.50');
    await tester.pumpAndSettle();
    expect(find.text('Total deducted: NPR 22.50'), findsOneWidget);
    expect(find.byKey(const Key('transfer-fee-category')), findsOneWidget);
    await finish(tester);
  });

  test(
    'Offline transfer queues exact command and projects fee accounting',
    () async {
      final from = await seedAccount('Cash', 100);
      final to = await seedAccount('Bank', 25);
      final feeCategory = await seedExpenseCategory();
      final id = await transfers.create(
        fromAccountId: from,
        toAccountId: to,
        amount: 30,
        fee: 2,
        feeCategoryId: feeCategory,
        date: DateTime(2026, 10, 3),
        description: 'Move money',
      );
      final row = (await database.transactionsForUser(userId)).single;
      expect(row.id, id);
      expect(row.transactionType, 'transfer');
      expect(row.fromAccountId, from);
      expect(row.toAccountId, to);
      final command =
          (await database.select(database.outboxCommands).get()).single;
      expect(command.commandType, 'create_transfer');
      expect(command.dataGeneration, 9);
      expect(command.clientRequestId, isNotEmpty);
      expect(command.status, 'pending');
      final payload = jsonDecode(command.payloadJson);
      expect(payload['p_from'], from);
      expect(payload['p_to'], to);
      expect(payload['p_amount'], 30);
      expect(payload['p_fee'], 2);
      expect(payload['p_fee_category'], feeCategory);
      expect(payload['p_date'], '2026-10-03');
      expect((await database.accountById(from))!.balance, 100);
      expect((await database.accountById(to))!.balance, 25);
      final projected = await accounts.watchAccounts(userId).first;
      expect(projected.singleWhere((row) => row.id == from).balance, 68);
      expect(projected.singleWhere((row) => row.id == to).balance, 55);
      final home = await HomeRepository(database)
          .load(userId, DateTime(2026, 10, 3));
      expect(home.balance, 123); // 100 + 25 - fee 2
      expect(home.income, 0);
      expect(
        home.expense,
        2,
      ); // The verified RPC records the fee as an expense.
      expect(home.recent.single.title, 'Transfer · Move money');
    },
  );

  test(
    'Same account, foreign account and insufficient funds never queue',
    () async {
      final from = await seedAccount('Cash', 10);
      final to = await seedAccount('Bank', 20);
      final foreign = await seedAccount('Foreign', 50, owner: otherUser);
      final feeCategory = await seedExpenseCategory();
      Future<String> create(
        String source,
        String destination,
        double amount,
        double fee,
      ) => transfers.create(
        fromAccountId: source,
        toAccountId: destination,
        amount: amount,
        fee: fee,
        feeCategoryId: fee > 0 ? feeCategory : null,
        date: DateTime(2026, 10, 3),
        description: 'x',
      );
      await expectLater(create(from, from, 1, 0), throwsFormatException);
      await expectLater(create(from, foreign, 1, 0), throwsStateError);
      await expectLater(
        create(from, to, 10, 1),
        throwsA(isA<InsufficientFundsException>()),
      );
      expect(await database.select(database.outboxCommands).get(), isEmpty);
    },
  );

  testWidgets('Accounts screen shows pending source and destination effects', (
    tester,
  ) async {
    final from = await seedAccount('Cash', 100);
    final to = await seedAccount('Bank', 25);
    await transfers.create(
      fromAccountId: from,
      toAccountId: to,
      amount: 30,
      fee: 0,
      date: DateTime.now(),
      description: 'Move',
    );
    await mount(tester, '/accounts');
    expect(find.text('NPR 70.00'), findsOneWidget);
    expect(find.text('NPR 55.00'), findsOneWidget);
    await finish(tester);
  });

  testWidgets(
    'Insufficient funds offers source change without clearing details',
    (tester) async {
      await seedAccount('Small cash', 5);
      await seedAccount('Bank', 100);
      await seedAccount('Savings', 50);
      await mount(tester, '/add/transfer');
      await tester.enterText(find.byKey(const Key('transfer-amount')), '20');
      await tester.enterText(
        find.byKey(const Key('transfer-note')),
        'Rent move',
      );
      await choose(
        tester,
        find.byType(DropdownButtonFormField<String>).first,
        'Small cash',
      );
      await choose(
        tester,
        find.byKey(const Key('transfer-destination')),
        'Bank',
      );
      await tapSave(tester);
      expect(find.text('Insufficient funds'), findsOneWidget);
      await tester.tap(find.text('Change source account'));
      await tester.pumpAndSettle();
      expect(find.text('Rent move'), findsOneWidget);
      expect(find.text('20'), findsOneWidget);
      expect(await database.select(database.outboxCommands).get(), isEmpty);
      await finish(tester);
    },
  );

  testWidgets(
    'Valid transfer saves once and returns Home with neutral recent row',
    (tester) async {
      await seedAccount('Cash', 100);
      await seedAccount('Bank', 25);
      final router = await mount(tester, '/add/transfer');
      await tester.enterText(find.byKey(const Key('transfer-amount')), '20');
      await choose(
        tester,
        find.byType(DropdownButtonFormField<String>).first,
        'Cash',
      );
      await choose(
        tester,
        find.byKey(const Key('transfer-destination')),
        'Bank',
      );
      tester.testTextInput.hide();
      await tester.pumpAndSettle();
      await tester.drag(find.byType(ListView), const Offset(0, -500));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save transfer'));
      await tester.tap(find.text('Save transfer'), warnIfMissed: false);
      await tester.pumpAndSettle();
      expect(router.routeInformationProvider.value.uri.path, '/');
      expect(
        await database.select(database.outboxCommands).get(),
        hasLength(1),
      );
      expect(find.text('Transfer'), findsWidgets);
      await finish(tester);
    },
  );

  testWidgets('Failed remote transfer recovers with a new source', (
    tester,
  ) async {
    final from = await seedAccount('Cash', 100);
    final to = await seedAccount('Bank', 25);
    await seedAccount('Savings', 90);
    final id = await transfers.create(
      fromAccountId: from,
      toAccountId: to,
      amount: 20,
      fee: 0,
      date: DateTime.now(),
      description: 'Move rent',
    );
    final now = DateTime.now().toUtc();
    await transfers.outbox.markProcessing(
      userId: userId,
      commandId: id,
      now: now,
    );
    await transfers.outbox.markPermanentFailure(
      userId: userId,
      commandId: id,
      now: now,
      errorCode: 'INSUFFICIENT_FUNDS',
      errorMessage: 'INSUFFICIENT_FUNDS',
    );
    final router = await mount(tester, '/');
    expect(find.textContaining('Transfer could not sync'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, -300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Change source account'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/add/transfer');
    expect(find.text('Move rent'), findsOneWidget);
    expect(find.text('20.0'), findsOneWidget);
    expect(find.textContaining('Select source account'), findsOneWidget);
    await choose(
      tester,
      find.byType(DropdownButtonFormField<String>).first,
      'Savings',
    );
    await tapSave(tester);
    expect(router.routeInformationProvider.value.uri.path, '/');
    expect((await database.outboxCommandById(id))!.lastErrorCode, 'REPLACED');
    expect(find.textContaining('Transfer could not sync'), findsNothing);
    await finish(tester);
  });
}
