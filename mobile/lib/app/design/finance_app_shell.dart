import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'finance_widgets.dart';
import 'sanie_theme.dart';

class FinanceAppShell extends StatelessWidget {
  const FinanceAppShell({
    super.key,
    required this.child,
    required this.location,
  });

  final Widget child;
  final String location;

  int get _selectedIndex => switch (location) {
    '/transactions' => 1,
    '/budget' => 3,
    '/more' || '/design-preview' => 4,
    _ when location.startsWith('/accounts') => 4,
    _ => 0,
  };

  void _select(BuildContext context, int index) {
    if (index == 2) {
      showFinanceActionSheet(context);
      return;
    }
    context.go(switch (index) {
      0 => '/',
      1 => '/transactions',
      3 => '/budget',
      _ => '/more',
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: child,
    bottomNavigationBar: NavigationBar(
      selectedIndex: _selectedIndex,
      onDestinationSelected: (index) => _select(context, index),
      destinations: const [
        NavigationDestination(icon: Icon(Icons.home_outlined), label: 'Home'),
        NavigationDestination(
          icon: Icon(Icons.receipt_long_outlined),
          label: 'Transactions',
        ),
        NavigationDestination(
          icon: Icon(Icons.add_circle_outline_rounded),
          label: 'Add',
        ),
        NavigationDestination(
          icon: Icon(Icons.pie_chart_outline_rounded),
          label: 'Budget',
        ),
        NavigationDestination(icon: Icon(Icons.more_horiz), label: 'More'),
      ],
    ),
  );
}

Future<void> showFinanceActionSheet(
  BuildContext context,
) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  builder: (sheetContext) => SafeArea(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        SanieSpace.lg,
        SanieSpace.sm,
        SanieSpace.lg,
        MediaQuery.viewInsetsOf(sheetContext).bottom + SanieSpace.lg,
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: SanieShape.contentWidth),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Add transaction',
              style: Theme.of(sheetContext).textTheme.titleLarge,
            ),
            const SizedBox(height: SanieSpace.sm),
            Text(
              'Preview only · transaction forms will arrive in a later phase.',
              style: Theme.of(sheetContext).textTheme.bodySmall,
            ),
            const SizedBox(height: SanieSpace.md),
            for (final (label, kind) in [
              ('Income', FinanceKind.income),
              ('Expense', FinanceKind.expense),
              ('Transfer', FinanceKind.transfer),
            ])
              ListTile(
                enabled: false,
                leading: Icon(kind.icon, color: kind.color(sheetContext)),
                title: Text(label),
              ),
          ],
        ),
      ),
    ),
  ),
);
