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
import 'package:sanie/features/categories/categories_pages.dart';
import 'package:sanie/features/categories/categories_repository.dart';

const userId = '11111111-1111-4111-8111-111111111111';
const otherUserId = '22222222-2222-4222-8222-222222222222';

void main() {
  late AppDatabase database;
  late AccountsRepository accounts;
  late CategoriesRepository categories;

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
    await database.upsertSyncState(
      SyncStatesCompanion.insert(
        userId: userId,
        dataGeneration: const Value(1),
      ),
    );
  });
  tearDown(() async => database.close());

  Future<String> seedCategory({
    String name = 'Groceries',
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
            name: name,
            categoryType: 'expense',
            isSystem: Value(system),
            createdAt: now,
            updatedAt: now,
          ),
        );
    return id;
  }

  Future<String> seedSubcategory(String parentId, {bool system = false}) async {
    final id = database.newId();
    final now = DateTime.utc(2026, 10, 3);
    await database
        .into(database.subcategories)
        .insert(
          SubcategoriesCompanion.insert(
            id: id,
            userId: Value(system ? null : userId),
            categoryId: parentId,
            name: system ? 'Standard room' : 'Fresh produce',
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
          GoRoute(path: '/more', builder: (_, _) => const MorePage()),
          GoRoute(path: '/accounts', builder: (_, _) => const AccountsPage()),
          GoRoute(
            path: '/categories',
            builder: (_, _) => const CategoriesPage(),
          ),
          GoRoute(
            path: '/categories/add',
            builder: (_, _) => const CategoryFormPage(),
          ),
          GoRoute(
            path: '/categories/:id',
            builder: (_, state) =>
                CategoryDetailsPage(id: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/categories/:id/edit',
            builder: (_, state) =>
                CategoryFormPage(id: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/categories/:id/subcategories/add',
            builder: (_, state) =>
                SubcategoryFormPage(parentId: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/categories/:id/subcategories/:subId/edit',
            builder: (_, state) => SubcategoryFormPage(
              parentId: state.pathParameters['id']!,
              id: state.pathParameters['subId']!,
            ),
          ),
        ],
      ),
    ],
  );

  Future<GoRouter> mount(
    WidgetTester tester,
    String route, {
    ThemeData? theme,
  }) async {
    final router = makeRouter(route);
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          accountUserProvider.overrideWith((ref) => Stream.value(userId)),
          accountsRepositoryProvider.overrideWithValue(accounts),
          categoriesRepositoryProvider.overrideWithValue(categories),
          categoriesBootstrapProvider(userId).overrideWith((ref) async {}),
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

  testWidgets('More opens empty Categories and Accounts still works', (
    tester,
  ) async {
    final router = await mount(tester, '/more');
    await tester.tap(find.text('Categories'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/categories');
    expect(find.textContaining('No categories yet'), findsOneWidget);
    expect(find.text('Add category'), findsOneWidget);
    router.go('/more');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Accounts'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/accounts');
    await finish(tester);
  });

  testWidgets(
    'Drift rows show system and custom; system controls stay protected',
    (tester) async {
      final system = await seedCategory(name: 'Room Expense', system: true);
      await seedSubcategory(system, system: true);
      final custom = await seedCategory(name: 'Groceries');
      await seedCategory(name: 'Other user', owner: otherUserId);
      final router = await mount(tester, '/categories');
      expect(find.text('Room Expense'), findsOneWidget);
      expect(find.text('Groceries'), findsOneWidget);
      expect(find.text('Other user'), findsNothing);
      expect(find.textContaining('System'), findsOneWidget);
      await tester.tap(find.text('Room Expense'));
      await tester.pumpAndSettle();
      expect(
        router.routeInformationProvider.value.uri.path,
        '/categories/$system',
      );
      expect(find.text('Standard room'), findsOneWidget);
      expect(find.byKey(const Key('edit-category')), findsNothing);
      expect(find.byKey(const Key('archive-category')), findsNothing);
      router.go('/categories/$custom');
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('edit-category')), findsOneWidget);
      await finish(tester);
    },
  );

  testWidgets('Long category names fit narrow light and dark screens', (
    tester,
  ) async {
    final name = 'A very long expense category name for a narrow mobile screen';
    await seedCategory(name: name);
    tester.view.physicalSize = const Size(320, 700);
    tester.view.devicePixelRatio = 1;
    for (final theme in [SanieTheme.light(), SanieTheme.dark()]) {
      await mount(tester, '/categories', theme: theme);
      expect(find.text(name), findsOneWidget);
      expect(tester.takeException(), isNull);
      await finish(tester);
    }
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });

  testWidgets('Category form validates and creates a trimmed offline row', (
    tester,
  ) async {
    final router = await mount(tester, '/categories/add');
    await tester.tap(find.text('Add category').last);
    await tester.pumpAndSettle();
    expect(find.text('Enter a category name.'), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('category-name')),
      '  Travel  ',
    );
    await tester.tap(find.text('Add category').last);
    await tester.pumpAndSettle();
    final rows = await database.select(database.categories).get();
    expect(rows, hasLength(1));
    expect(rows.single.name, 'Travel');
    expect(
      router.routeInformationProvider.value.uri.path,
      '/categories/${rows.single.id}',
    );
    final commands = await database.select(database.outboxCommands).get();
    expect(commands.single.commandType, 'create_category');
    expect(commands.single.status, 'pending');
    expect(find.textContaining('pending sync'), findsOneWidget);
    await finish(tester);
  });

  testWidgets('Custom edit and archive require confirmation', (tester) async {
    final id = await seedCategory();
    final router = await mount(tester, '/categories/$id');
    await tester.tap(find.text('Edit category'));
    await tester.pumpAndSettle();
    expect(
      router.routeInformationProvider.value.uri.path,
      '/categories/$id/edit',
    );
    expect(find.byKey(const Key('category-type')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('category-name')),
      '  Market  ',
    );
    await tester.ensureVisible(find.text('Save category'));
    await tester.tap(find.text('Save category'));
    await tester.pumpAndSettle();
    expect(
      (await database.select(database.categories).get()).single.name,
      'Market',
    );
    expect(
      (await database.select(database.outboxCommands).get()).single.commandType,
      'update_category',
    );
    // The domain contract blocks a second mutation while an earlier one is pending.
    expect(
      tester
          .widget<OutlinedButton>(find.byKey(const Key('archive-category')))
          .onPressed,
      isNull,
    );
    await finish(tester);
  });

  testWidgets('Archive cancel is safe; confirm queues a soft archive', (
    tester,
  ) async {
    final id = await seedCategory();
    await mount(tester, '/categories/$id');
    await tester.ensureVisible(find.text('Archive category'));
    await tester.tap(find.text('Archive category'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(await database.select(database.outboxCommands).get(), isEmpty);
    await tester.tap(find.text('Archive category'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Archive').last);
    await tester.pumpAndSettle();
    final row = (await database.select(database.categories).get()).single;
    expect(row.status, 'archived');
    expect(row.deletedAt, isNull);
    expect(
      (await database.select(database.outboxCommands).get()).single.commandType,
      'archive_category',
    );
    await finish(tester);
  });

  testWidgets('Subcategories retain their parent and use local mutations', (
    tester,
  ) async {
    final parent = await seedCategory();
    final other = await seedCategory(name: 'Other');
    final existing = await seedSubcategory(parent);
    await seedSubcategory(other);
    final router = await mount(tester, '/categories/$parent');
    expect(find.text('Fresh produce'), findsOneWidget);
    expect(find.text('Other'), findsNothing);
    await tester.tap(find.byKey(const Key('add-subcategory')));
    await tester.pumpAndSettle();
    expect(
      router.routeInformationProvider.value.uri.path,
      '/categories/$parent/subcategories/add',
    );
    await tester.enterText(
      find.byKey(const Key('subcategory-name')),
      '  Bakery  ',
    );
    await tester.ensureVisible(find.text('Add subcategory').last);
    await tester.tap(find.text('Add subcategory').last);
    await tester.pumpAndSettle();
    final rows = await database.select(database.subcategories).get();
    final bakery = rows.singleWhere((row) => row.name == 'Bakery');
    expect(bakery.categoryId, parent);
    expect(
      (await database.select(database.outboxCommands).get()).single.commandType,
      'create_subcategory',
    );
    router.go('/categories/$parent/subcategories/$existing/edit');
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('subcategory-name')),
      '  Produce  ',
    );
    await tester.ensureVisible(find.text('Save subcategory').last);
    await tester.tap(find.text('Save subcategory').last);
    await tester.pumpAndSettle();
    expect(
      (await database.select(database.subcategories).get())
          .singleWhere((row) => row.id == existing)
          .name,
      'Produce',
    );
    await finish(tester);
  });

  testWidgets('Subcategory archive confirms and preserves the row', (
    tester,
  ) async {
    final parent = await seedCategory();
    final sub = await seedSubcategory(parent);
    await mount(tester, '/categories/$parent');
    await tester.tap(find.byTooltip('Subcategory actions'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Archive').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(await database.select(database.outboxCommands).get(), isEmpty);
    await tester.tap(find.byTooltip('Subcategory actions'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Archive').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Archive').last);
    await tester.pumpAndSettle();
    final row = (await database.select(database.subcategories).get())
        .singleWhere((row) => row.id == sub);
    expect(row.status, 'archived');
    expect(row.deletedAt, isNull);
    expect(
      (await database.select(database.outboxCommands).get()).single.commandType,
      'archive_subcategory',
    );
    await finish(tester);
  });
}
