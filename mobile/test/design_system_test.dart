import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:sanie/app/router.dart';
import 'package:sanie/app/design/finance_app_shell.dart';
import 'package:sanie/app/design/finance_widgets.dart';
import 'package:sanie/app/design/sanie_theme.dart';
import 'package:sanie/features/design_preview/design_preview_page.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

void main() {
  test('light and dark themes expose readable semantic finance colors', () {
    for (final theme in [SanieTheme.light(), SanieTheme.dark()]) {
      final colors = theme.extension<FinanceColors>()!;
      expect(theme.useMaterial3, isTrue);
      expect(colors.income, isNot(colors.expense));
      expect(colors.expense, isNot(colors.transfer));
      expect(
        colors.income.computeLuminance(),
        isNot(colors.incomeSurface.computeLuminance()),
      );
    }
    expect(SanieTheme.light().brightness, Brightness.light);
    expect(SanieTheme.dark().brightness, Brightness.dark);
  });

  testWidgets('reusable components and preview work in both modes', (
    tester,
  ) async {
    for (final theme in [SanieTheme.light(), SanieTheme.dark()]) {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          home: const Scaffold(body: DesignPreviewPage()),
        ),
      );
      expect(find.text('Sample balance'), findsOneWidget);
      expect(find.text('NPR 1,234,567.89'), findsOneWidget);
      expect(find.byType(FinanceListTile), findsNWidgets(3));
      expect(find.byType(FinancePrimaryButton), findsOneWidget);
      await tester.ensureVisible(find.text('Could not load sample'));
      expect(find.byType(FinanceStateView), findsNWidgets(3));
      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('shell navigates and Add opens visual choices', (tester) async {
    final router = GoRouter(
      routes: [
        ShellRoute(
          builder: (context, state, child) =>
              FinanceAppShell(location: state.uri.path, child: child),
          routes: [
            for (final (path, title) in [
              ('/', 'Home content'),
              ('/transactions', 'Transactions content'),
              ('/budget', 'Budget content'),
              ('/more', 'More content'),
            ])
              GoRoute(
                path: path,
                builder: (context, state) => Center(child: Text(title)),
              ),
          ],
        ),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(
      MaterialApp.router(theme: SanieTheme.light(), routerConfig: router),
    );
    expect(find.text('Home content'), findsOneWidget);
    await tester.tap(find.text('Transactions'));
    await tester.pumpAndSettle();
    expect(find.text('Transactions content'), findsOneWidget);
    await tester.tap(find.text('Add'));
    await tester.pumpAndSettle();
    expect(find.text('Add transaction'), findsOneWidget);
    expect(find.text('Income'), findsOneWidget);
    expect(find.text('Expense'), findsOneWidget);
    expect(find.text('Transfer'), findsOneWidget);
    expect(router.routeInformationProvider.value.uri.path, '/transactions');
    Navigator.of(tester.element(find.text('Add transaction'))).pop();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Budget'));
    await tester.pumpAndSettle();
    expect(find.text('Budget content'), findsOneWidget);
  });

  testWidgets('small phone and larger text keep preview usable', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: SanieTheme.dark(),
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context)
              .copyWith(textScaler: const TextScaler.linear(1.8)),
          child: child!,
        ),
        home: const Scaffold(body: DesignPreviewPage()),
      ),
    );
    expect(find.text('NPR 1,234,567.89'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('existing auth router still redirects unsigned users to login', (
    tester,
  ) async {
    final client = SupabaseClient(
      'https://example.supabase.co',
      'publishable-test-value',
      authOptions: const AuthClientOptions(autoRefreshToken: false),
    );
    final refresh = AuthRefreshNotifier(client);
    final router = buildSanieRouter(client, refresh);
    addTearDown(router.dispose);
    addTearDown(refresh.dispose);
    await tester.pumpWidget(
      MaterialApp.router(theme: SanieTheme.light(), routerConfig: router),
    );
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/login');
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.byType(FinanceAppShell), findsNothing);
  });
}
