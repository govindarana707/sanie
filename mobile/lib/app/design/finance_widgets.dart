import 'package:flutter/material.dart';

import 'sanie_theme.dart';

enum FinanceKind { income, expense, transfer }

extension FinanceKindStyle on FinanceKind {
  Color color(BuildContext context) {
    final colors = Theme.of(context).extension<FinanceColors>()!;
    return switch (this) {
      FinanceKind.income => colors.income,
      FinanceKind.expense => colors.expense,
      FinanceKind.transfer => colors.transfer,
    };
  }

  Color surface(BuildContext context) {
    final colors = Theme.of(context).extension<FinanceColors>()!;
    return switch (this) {
      FinanceKind.income => colors.incomeSurface,
      FinanceKind.expense => colors.expenseSurface,
      FinanceKind.transfer => colors.transferSurface,
    };
  }

  IconData get icon => switch (this) {
    FinanceKind.income => Icons.south_west_rounded,
    FinanceKind.expense => Icons.north_east_rounded,
    FinanceKind.transfer => Icons.swap_horiz_rounded,
  };
}

class FinanceScreen extends StatelessWidget {
  const FinanceScreen({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => SafeArea(
    child: LayoutBuilder(
      builder: (context, constraints) => ListView(
        padding: EdgeInsets.symmetric(
          horizontal: constraints.maxWidth < 360
              ? SanieSpace.md
              : SanieSpace.lg,
          vertical: SanieSpace.lg,
        ),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(
                maxWidth: SanieShape.contentWidth,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children,
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class FinanceCard extends StatelessWidget {
  const FinanceCard({super.key, required this.child, this.padding});

  final Widget child;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: padding ?? const EdgeInsets.all(SanieSpace.lg),
      child: child,
    ),
  );
}

class FinanceSectionHeader extends StatelessWidget {
  const FinanceSectionHeader({super.key, required this.title, this.action});

  final String title;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: Text(title, style: Theme.of(context).textTheme.titleLarge),
      ),
      ?action,
    ],
  );
}

class FinanceAmountText extends StatelessWidget {
  const FinanceAmountText({
    super.key,
    required this.amount,
    this.kind,
    this.style,
  });

  final String amount;
  final FinanceKind? kind;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) => Text(
    amount,
    softWrap: true,
    style: (style ?? Theme.of(context).textTheme.headlineMedium)?.copyWith(
      color: kind?.color(context),
      fontFeatures: const [FontFeature.tabularFigures()],
    ),
  );
}

class FinancePrimaryButton extends StatelessWidget {
  const FinancePrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => icon == null
      ? FilledButton(onPressed: onPressed, child: Text(label))
      : FilledButton.icon(
          onPressed: onPressed,
          icon: Icon(icon),
          label: Text(label),
        );
}

class FinanceListTile extends StatelessWidget {
  const FinanceListTile({
    super.key,
    required this.title,
    required this.amount,
    required this.kind,
    this.subtitle,
    this.onTap,
  });

  final String title;
  final String amount;
  final FinanceKind kind;
  final String? subtitle;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => FinanceCard(
    padding: EdgeInsets.zero,
    child: ListTile(
      minVerticalPadding: SanieSpace.md,
      contentPadding: const EdgeInsets.symmetric(horizontal: SanieSpace.md),
      onTap: onTap,
      leading: CircleAvatar(
        backgroundColor: kind.surface(context),
        foregroundColor: kind.color(context),
        child: Icon(kind.icon),
      ),
      title: Text(title, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: subtitle == null ? null : Text(subtitle!),
      trailing: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 144),
        child: FinanceAmountText(
          amount: amount,
          kind: kind,
          style: Theme.of(context).textTheme.titleMedium,
        ),
      ),
    ),
  );
}

enum FinanceViewState { empty, loading, error }

class FinanceStateView extends StatelessWidget {
  const FinanceStateView({
    super.key,
    required this.state,
    required this.message,
    this.onRetry,
  });

  final FinanceViewState state;
  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) => FinanceCard(
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: SanieSpace.lg),
      child: Column(
        children: [
          if (state == FinanceViewState.loading)
            const CircularProgressIndicator()
          else
            Icon(
              state == FinanceViewState.error
                  ? Icons.error_outline_rounded
                  : Icons.inbox_outlined,
              size: 36,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          const SizedBox(height: SanieSpace.md),
          Text(message, textAlign: TextAlign.center),
          if (state == FinanceViewState.error && onRetry != null) ...[
            const SizedBox(height: SanieSpace.md),
            TextButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ],
      ),
    ),
  );
}
