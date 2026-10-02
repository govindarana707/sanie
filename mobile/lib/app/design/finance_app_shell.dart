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
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return Scaffold(
      body: child,
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(
          color: palette.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
          boxShadow: [
            BoxShadow(
              color: palette.shadow,
              blurRadius: 20,
              offset: const Offset(0, -5),
            ),
          ],
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              SanieSpace.xs,
              SanieSpace.sm,
              SanieSpace.xs,
              SanieSpace.sm,
            ),
            child: Row(
              children: [
                _NavItem(
                  label: 'Home',
                  icon: Icons.home_rounded,
                  selected: _selectedIndex == 0,
                  onTap: () => _select(context, 0),
                ),
                _NavItem(
                  label: 'Transactions',
                  icon: Icons.receipt_long_outlined,
                  selected: _selectedIndex == 1,
                  onTap: () => _select(context, 1),
                ),
                Expanded(
                  child: Semantics(
                    label: 'Add',
                    button: true,
                    child: InkWell(
                      onTap: () => _select(context, 2),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 52,
                            height: 52,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: palette.emerald,
                              boxShadow: [
                                BoxShadow(
                                  color: palette.emerald.withValues(alpha: .18),
                                  blurRadius: 8,
                                  offset: const Offset(0, 3),
                                ),
                              ],
                            ),
                            child: const Icon(
                              Icons.add_rounded,
                              color: Colors.white,
                              size: 31,
                            ),
                          ),
                          const SizedBox(height: SanieSpace.xs),
                          Text(
                            'Add',
                            maxLines: 1,
                            style: Theme.of(context).textTheme.labelSmall
                                ?.copyWith(color: palette.primaryText),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                _NavItem(
                  label: 'Budget',
                  icon: Icons.pie_chart_outline_rounded,
                  selected: _selectedIndex == 3,
                  onTap: () => _select(context, 3),
                ),
                _NavItem(
                  label: 'More',
                  icon: Icons.more_horiz_rounded,
                  selected: _selectedIndex == 4,
                  onTap: () => _select(context, 4),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    final color = selected ? palette.emerald : palette.mutedText;
    return Expanded(
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 2),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                height: 52,
                child: Center(
                  child: Container(
                    width: double.infinity,
                    height: 40,
                    decoration: BoxDecoration(
                      color: selected ? palette.mint : Colors.transparent,
                      borderRadius: BorderRadius.circular(24),
                    ),
                    child: Icon(icon, color: color, size: 26),
                  ),
                ),
              ),
              const SizedBox(height: SanieSpace.xs),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  label,
                  maxLines: 1,
                  softWrap: false,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: selected ? palette.primaryText : palette.mutedText,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                    fontSize: 11,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
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
