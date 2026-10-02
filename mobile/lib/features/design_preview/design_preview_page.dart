import 'package:flutter/material.dart';

import '../../app/design/finance_app_shell.dart';
import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';

class DesignPreviewPage extends StatelessWidget {
  const DesignPreviewPage({super.key});

  @override
  Widget build(BuildContext context) => FinanceScreen(
    children: [
      Text('Design preview', style: Theme.of(context).textTheme.headlineMedium),
      const SizedBox(height: SanieSpace.sm),
      Text(
        'Isolated sample data · follows your device light or dark setting.',
        style: Theme.of(context).textTheme.bodyMedium,
      ),
      const SizedBox(height: SanieSpace.lg),
      FinanceCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Sample balance',
              style: Theme.of(context).textTheme.labelLarge,
            ),
            const SizedBox(height: SanieSpace.sm),
            const FinanceAmountText(amount: 'NPR 1,234,567.89'),
          ],
        ),
      ),
      const SizedBox(height: SanieSpace.lg),
      const FinanceSectionHeader(title: 'Activity styles'),
      const SizedBox(height: SanieSpace.md),
      const FinanceListTile(
        title: 'Income',
        subtitle: 'Sample entry',
        amount: '+ NPR 25,000',
        kind: FinanceKind.income,
      ),
      const SizedBox(height: SanieSpace.sm),
      const FinanceListTile(
        title: 'Expense',
        subtitle: 'Sample entry',
        amount: '− NPR 2,450',
        kind: FinanceKind.expense,
      ),
      const SizedBox(height: SanieSpace.sm),
      const FinanceListTile(
        title: 'Transfer',
        subtitle: 'Sample entry',
        amount: 'NPR 10,000',
        kind: FinanceKind.transfer,
      ),
      const SizedBox(height: SanieSpace.lg),
      FinancePrimaryButton(
        label: 'Preview actions',
        icon: Icons.add_rounded,
        onPressed: () => showFinanceActionSheet(context),
      ),
      const SizedBox(height: SanieSpace.md),
      const TextField(
        readOnly: true,
        decoration: InputDecoration(labelText: 'Sample form field'),
      ),
      const SizedBox(height: SanieSpace.lg),
      const FinanceStateView(
        state: FinanceViewState.empty,
        message: 'Nothing here yet',
      ),
      const SizedBox(height: SanieSpace.sm),
      const FinanceStateView(
        state: FinanceViewState.loading,
        message: 'Loading sample',
      ),
      const SizedBox(height: SanieSpace.sm),
      const FinanceStateView(
        state: FinanceViewState.error,
        message: 'Could not load sample',
      ),
    ],
  );
}
