import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../features/auth/presentation/login_page.dart';
import '../features/accounts/accounts_pages.dart';
import '../features/categories/categories_pages.dart';
import '../features/transactions/transaction_form_page.dart';
import '../features/design_preview/design_preview_page.dart';
import '../features/home/presentation/home_page.dart';
import 'design/finance_app_shell.dart';
import 'design/finance_widgets.dart';
import 'design/sanie_theme.dart';

final routerProvider = Provider<GoRouter>((ref) {
  final client = Supabase.instance.client;
  final authRefresh = AuthRefreshNotifier(client);
  ref.onDispose(authRefresh.dispose);
  final router = buildSanieRouter(client, authRefresh);
  ref.onDispose(router.dispose);
  return router;
});

GoRouter buildSanieRouter(
  SupabaseClient client,
  AuthRefreshNotifier authRefresh,
) => GoRouter(
  initialLocation: '/',
  refreshListenable: authRefresh,
  redirect: (context, state) {
    final signedIn = client.auth.currentSession != null;
    final onLogin = state.matchedLocation == '/login';

    if (!signedIn) return onLogin ? null : '/login';
    if (onLogin) return '/';
    return null;
  },
  routes: [
    GoRoute(path: '/login', builder: (context, state) => const LoginPage()),
    ShellRoute(
      builder: (context, state, child) =>
          FinanceAppShell(location: state.uri.path, child: child),
      routes: [
        GoRoute(path: '/', builder: (context, state) => const HomePage()),
        GoRoute(
          path: '/transactions',
          builder: (context, state) => const _PlaceholderPage(
            title: 'Transactions',
            message: 'Your transactions will appear here in a later phase.',
          ),
        ),
        GoRoute(
          path: '/budget',
          builder: (context, state) => const _PlaceholderPage(
            title: 'Budget',
            message: 'Budget screens will arrive in a later phase.',
          ),
        ),
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
        GoRoute(
          path: '/categories',
          builder: (context, state) => const CategoriesPage(),
        ),
        GoRoute(
          path: '/add/income',
          builder: (context, state) =>
              const TransactionFormPage(type: 'income'),
        ),
        GoRoute(
          path: '/add/expense',
          builder: (context, state) => TransactionFormPage(
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
          path: '/categories/add',
          builder: (context, state) => const CategoryFormPage(),
        ),
        GoRoute(
          path: '/categories/:id',
          builder: (context, state) =>
              CategoryDetailsPage(id: state.pathParameters['id']!),
        ),
        GoRoute(
          path: '/categories/:id/edit',
          builder: (context, state) =>
              CategoryFormPage(id: state.pathParameters['id']!),
        ),
        GoRoute(
          path: '/categories/:id/subcategories/add',
          builder: (context, state) =>
              SubcategoryFormPage(parentId: state.pathParameters['id']!),
        ),
        GoRoute(
          path: '/categories/:id/subcategories/:subId/edit',
          builder: (context, state) => SubcategoryFormPage(
            parentId: state.pathParameters['id']!,
            id: state.pathParameters['subId']!,
          ),
        ),
        GoRoute(
          path: '/design-preview',
          builder: (context, state) => const DesignPreviewPage(),
        ),
      ],
    ),
  ],
);

class AuthRefreshNotifier extends ChangeNotifier {
  AuthRefreshNotifier(SupabaseClient client) {
    _subscription = client.auth.onAuthStateChange.listen(
      (_) => notifyListeners(),
    );
  }

  late final StreamSubscription<AuthState> _subscription;

  @override
  void dispose() {
    _subscription.cancel();
    super.dispose();
  }
}

class _PlaceholderPage extends StatelessWidget {
  const _PlaceholderPage({required this.title, required this.message});

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) => FinanceScreen(
    children: [
      Text(title, style: Theme.of(context).textTheme.headlineMedium),
      const SizedBox(height: SanieSpace.lg),
      FinanceStateView(state: FinanceViewState.empty, message: message),
    ],
  );
}

class MorePage extends StatelessWidget {
  const MorePage({super.key});

  @override
  Widget build(BuildContext context) => FinanceScreen(
    children: [
      Text('More', style: Theme.of(context).textTheme.headlineMedium),
      const SizedBox(height: SanieSpace.lg),
      FinanceCard(
        padding: EdgeInsets.zero,
        child: ListTile(
          title: const Text('Accounts'),
          subtitle: const Text('Balances and account settings'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.go('/accounts'),
        ),
      ),
      const SizedBox(height: SanieSpace.sm),
      FinanceCard(
        padding: EdgeInsets.zero,
        child: ListTile(
          title: const Text('Categories'),
          subtitle: const Text('Income, expenses and subcategories'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.go('/categories'),
        ),
      ),
      const SizedBox(height: SanieSpace.sm),
      FinanceCard(
        padding: EdgeInsets.zero,
        child: ListTile(
          title: const Text('Design preview'),
          subtitle: const Text('Explore UI components and colors'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.go('/design-preview'),
        ),
      ),
    ],
  );
}
