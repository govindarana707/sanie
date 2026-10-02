import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../../app/design/finance_widgets.dart';
import '../../../app/design/sanie_theme.dart';
import '../../accounts/accounts_repository.dart';
import '../home_repository.dart';

class HomePage extends ConsumerStatefulWidget {
  const HomePage({super.key, this.clock});
  final DateTime Function()? clock;

  @override
  ConsumerState<HomePage> createState() => _HomePageState();
}

class _HomePageState extends ConsumerState<HomePage> {
  int _monthOffset = 0;
  bool _balanceVisible = true;
  bool _signingOut = false;
  String? _loadKey;
  Future<HomeSnapshot>? _snapshot;

  DateTime get _today => widget.clock?.call() ?? DateTime.now();
  DateTime get _month => DateTime(_today.year, _today.month + _monthOffset);

  Future<void> _signOut() async {
    if (_signingOut) return;
    setState(() => _signingOut = true);
    try {
      await Supabase.instance.client.auth.signOut();
    } finally {
      if (mounted) setState(() => _signingOut = false);
    }
  }

  Future<void> _refresh(HomeRepository repository, String userId) async {
    final next = repository.load(userId, _month);
    setState(() => _snapshot = next);
    try {
      await next;
    } catch (_) {
      // FutureBuilder shows the local read error while refresh completes safely.
    }
  }

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    final auth = ref.watch(accountUserProvider);
    final identity = ref.watch(homeIdentityProvider);
    final repository = ref.watch(homeRepositoryProvider);
    return auth.when(
      data: (userId) {
        if (userId == null || identity == null || identity.userId != userId) {
          return const Center(child: Text('Sign in to view your home.'));
        }
        final key = '$userId:${_month.year}-${_month.month}';
        if (_loadKey != key) {
          _loadKey = key;
          _snapshot = repository.load(userId, _month);
        }
        return SafeArea(
          child: Stack(
            children: [
              Positioned(
                top: 20,
                right: -90,
                width: 280,
                height: 310,
                child: IgnorePointer(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: RadialGradient(
                        colors: [
                          palette.mint.withValues(alpha: .72),
                          palette.background.withValues(alpha: 0),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              RefreshIndicator(
                onRefresh: () => _refresh(repository, userId),
                child: LayoutBuilder(
                  builder: (context, constraints) => ListView(
                    padding: EdgeInsets.fromLTRB(
                      constraints.maxWidth < 360
                          ? SanieSpace.md
                          : SanieSpace.lg,
                      SanieSpace.md,
                      constraints.maxWidth < 360
                          ? SanieSpace.md
                          : SanieSpace.lg,
                      SanieSpace.xl,
                    ),
                    children: [
                      Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(
                            maxWidth: SanieShape.contentWidth,
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _Header(
                                identity: identity,
                                signingOut: _signingOut,
                                onSignOut: _signOut,
                              ),
                              const SizedBox(height: SanieSpace.xl),
                              FutureBuilder<HomeSnapshot>(
                                future: _snapshot,
                                builder: (context, snapshot) => _Greeting(
                                  identity: identity,
                                  displayName:
                                      snapshot.data?.profileName ??
                                      identity.displayName,
                                  today: _today,
                                  monthOffset: _monthOffset,
                                  onMonthChanged: (offset) =>
                                      setState(() => _monthOffset = offset),
                                ),
                              ),
                              const SizedBox(height: SanieSpace.lg),
                              FutureBuilder<HomeSnapshot>(
                                future: _snapshot,
                                builder: (context, snapshot) {
                                  if (snapshot.hasError) {
                                    return const FinanceStateView(
                                      state: FinanceViewState.error,
                                      message: 'Local overview could not be loaded. Pull down to retry.',
                                    );
                                  }
                                  final data = snapshot.data;
                                  return Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.stretch,
                                    children: [
                                      if (data == null)
                                        const LinearProgressIndicator(),
                                      _BalanceHero(
                                        balance: data?.balance ?? 0,
                                        hasAccounts: data?.hasAccounts ?? false,
                                        visible: _balanceVisible,
                                        onToggle: () => setState(
                                          () => _balanceVisible =
                                              !_balanceVisible,
                                        ),
                                      ),
                                      const SizedBox(height: SanieSpace.md),
                                      Row(
                                        children: [
                                          Expanded(
                                            child: _MetricCard(
                                              title: 'Total Income',
                                              amount: data?.income ?? 0,
                                              kind: FinanceKind.income,
                                              period: _monthOffset == 0
                                                  ? 'This month'
                                                  : 'Last month',
                                            ),
                                          ),
                                          const SizedBox(width: SanieSpace.sm),
                                          Expanded(
                                            child: _MetricCard(
                                              title: 'Total Expense',
                                              amount: data?.expense ?? 0,
                                              kind: FinanceKind.expense,
                                              period: _monthOffset == 0
                                                  ? 'This month'
                                                  : 'Last month',
                                            ),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: SanieSpace.md),
                                      _BudgetCard(
                                        spent: data?.budgetSpent ?? 0,
                                        total: data?.budgetTotal ?? 0,
                                      ),
                                      const SizedBox(height: SanieSpace.md),
                                      _RecentCard(
                                        rows: data?.recent ?? const [],
                                        today: _today,
                                      ),
                                    ],
                                  );
                                },
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (_, _) =>
          const Center(child: Text('Could not load your session.')),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.identity,
    required this.signingOut,
    required this.onSignOut,
  });
  final HomeIdentity identity;
  final bool signingOut;
  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return Row(
      children: [
        const SizedBox(
          width: 38,
          height: 48,
          child: CustomPaint(painter: _LeafLogo()),
        ),
        const SizedBox(width: SanieSpace.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'SanIE',
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  letterSpacing: -.8,
                  color: palette.primaryText,
                ),
              ),
              Text(
                'Personal Finance Manager',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall
                    ?.copyWith(color: palette.mutedText, fontSize: 11),
              ),
            ],
          ),
        ),
        IconButton(
          tooltip: 'Search',
          icon: const Icon(Icons.search_rounded),
          onPressed: () => _notReady(context, 'Search'),
        ),
        IconButton(
          tooltip: 'Notifications',
          icon: const Icon(Icons.notifications_none_rounded),
          onPressed: () => _notReady(context, 'Notifications'),
        ),
        const SizedBox(width: SanieSpace.xs),
        PopupMenuButton<String>(
          tooltip: 'Profile',
          enabled: !signingOut,
          onSelected: (value) {
            if (value == 'sign-out') onSignOut();
          },
          itemBuilder: (context) => const [
            PopupMenuItem(value: 'sign-out', child: Text('Sign out')),
          ],
          child: CircleAvatar(
            radius: 22,
            backgroundColor: palette.mint,
            child: Text(
              identity.displayName.isEmpty
                  ? 'U'
                  : identity.displayName[0].toUpperCase(),
              style: TextStyle(
                color: palette.primaryText,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

void _notReady(BuildContext context, String feature) =>
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text('$feature is coming soon.')));

class _LeafLogo extends CustomPainter {
  const _LeafLogo();
  @override
  void paint(Canvas canvas, Size size) {
    final stem = Paint()
      ..color = const Color(0xFF086B4A)
      ..strokeWidth = 2.5
      ..style = PaintingStyle.stroke;
    canvas.drawLine(
      Offset(size.width * .52, size.height * .96),
      Offset(size.width * .52, size.height * .25),
      stem,
    );
    void leaf(List<Offset> p, Color color) {
      final path = Path()
        ..moveTo(p[0].dx, p[0].dy)
        ..quadraticBezierTo(p[1].dx, p[1].dy, p[2].dx, p[2].dy)
        ..quadraticBezierTo(p[3].dx, p[3].dy, p[0].dx, p[0].dy);
      canvas.drawPath(path, Paint()..color = color);
    }

    leaf([
      Offset(size.width * .52, size.height * .5),
      Offset(size.width * .03, size.height * .29),
      Offset(size.width * .5, size.height * .08),
      Offset(size.width * .8, size.height * .28),
    ], const Color(0xFF37D68A));
    leaf([
      Offset(size.width * .5, size.height * .78),
      Offset(size.width * .03, size.height * .49),
      Offset(size.width * .05, size.height * .83),
      Offset(size.width * .25, size.height * .9),
    ], const Color(0xFF08764B));
    leaf([
      Offset(size.width * .53, size.height * .74),
      Offset(size.width * .84, size.height * .38),
      Offset(size.width * .96, size.height * .51),
      Offset(size.width * .91, size.height * .78),
    ], const Color(0xFF1CBD75));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _Greeting extends StatelessWidget {
  const _Greeting({
    required this.identity,
    required this.displayName,
    required this.today,
    required this.monthOffset,
    required this.onMonthChanged,
  });
  final HomeIdentity identity;
  final String displayName;
  final DateTime today;
  final int monthOffset;
  final ValueChanged<int> onMonthChanged;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Good to see you',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: palette.mutedText,
                  fontWeight: FontWeight.w400,
                ),
              ),
              const SizedBox(height: SanieSpace.xs),
              Text(
                '$displayName 👋',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  fontSize: 30,
                  fontWeight: FontWeight.w800,
                  color: palette.primaryText,
                ),
              ),
              Text(
                identity.email,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodyMedium
                    ?.copyWith(color: palette.mutedText),
              ),
            ],
          ),
        ),
        const SizedBox(width: SanieSpace.sm),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              _dateLabel(today),
              style: Theme.of(context).textTheme.bodySmall
                  ?.copyWith(color: palette.mutedText),
            ),
            const SizedBox(height: SanieSpace.sm),
            PopupMenuButton<int>(
              tooltip: 'Select period',
              onSelected: onMonthChanged,
              itemBuilder: (context) => const [
                PopupMenuItem(value: 0, child: Text('This Month')),
                PopupMenuItem(value: -1, child: Text('Last Month')),
              ],
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: palette.surface,
                  borderRadius: BorderRadius.circular(100),
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: SanieSpace.md,
                    vertical: SanieSpace.sm,
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        monthOffset == 0 ? 'This Month' : 'Last Month',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(width: SanieSpace.sm),
                      const Icon(Icons.keyboard_arrow_down_rounded, size: 20),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

String _dateLabel(DateTime date) {
  const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];
  return '${weekdays[date.weekday - 1]}, ${months[date.month - 1]} ${date.day}, ${date.year}';
}

String _rupees(double amount, {bool whole = false}) {
  final value = formatNpr(amount).replaceFirst('NPR ', 'Rs ');
  return whole && value.endsWith('.00')
      ? value.substring(0, value.length - 3)
      : value;
}

class _HomeCard extends StatelessWidget {
  const _HomeCard({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: palette.surface,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: palette.border),
        boxShadow: [
          BoxShadow(
            color: palette.shadow,
            blurRadius: 24,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(SanieSpace.md),
        child: child,
      ),
    );
  }
}

class _BalanceHero extends StatelessWidget {
  const _BalanceHero({
    required this.balance,
    required this.hasAccounts,
    required this.visible,
    required this.onToggle,
  });
  final double balance;
  final bool hasAccounts;
  final bool visible;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.centerLeft,
            end: Alignment.topRight,
            colors: [palette.heroStart, palette.heroEnd],
          ),
        ),
        child: Stack(
          children: [
            Positioned(
              right: 18,
              bottom: 0,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  for (final height in [30.0, 44.0, 60.0, 78.0]) ...[
                    Container(
                      width: 13,
                      height: height,
                      decoration: BoxDecoration(
                        color: palette.heroAccent.withValues(
                          alpha: .18 + height / 240,
                        ),
                        borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(10),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                  ],
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(18),
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 102),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(
                          Icons.account_balance_wallet_outlined,
                          color: palette.heroAccent,
                          size: 24,
                        ),
                        const SizedBox(width: SanieSpace.sm),
                        const Flexible(
                          child: Text(
                            'Total Balance',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 15,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        IconButton(
                          tooltip: visible ? 'Hide balance' : 'Show balance',
                          onPressed: onToggle,
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(
                            minWidth: 32,
                            minHeight: 32,
                          ),
                          icon: Icon(
                            visible
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                            color: Colors.white,
                            size: 20,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: SanieSpace.xs),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 310),
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: Text(
                            visible ? _rupees(balance) : 'Rs ••••••',
                            maxLines: 1,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 30,
                              fontWeight: FontWeight.w800,
                              letterSpacing: -.7,
                            ),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: SanieSpace.xs),
                    Text(
                      hasAccounts
                          ? 'Across your active accounts'
                          : 'Your balance will appear here',
                      style: TextStyle(
                        color: palette.heroAccent,
                        fontSize: 13,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({
    required this.title,
    required this.amount,
    required this.kind,
    required this.period,
  });
  final String title;
  final double amount;
  final FinanceKind kind;
  final String period;

  @override
  Widget build(BuildContext context) => _HomeCard(
    child: LayoutBuilder(
      builder: (context, constraints) {
        final icon = CircleAvatar(
          radius: 20,
          backgroundColor: kind.surface(context),
          child: Icon(
            kind == FinanceKind.income
                ? Icons.arrow_downward_rounded
                : Icons.arrow_upward_rounded,
            color: kind.color(context),
            size: 22,
          ),
        );
        final words = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: SanieSpace.xs),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(
                _rupees(amount),
                maxLines: 1,
                style: Theme.of(context).textTheme.titleLarge
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
            ),
            Text(
              period,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).extension<SaniePalette>()!.mutedText,
              ),
            ),
          ],
        );
        return constraints.maxWidth < 126
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  icon,
                  const SizedBox(height: SanieSpace.sm),
                  words,
                ],
              )
            : Row(
                children: [
                  icon,
                  const SizedBox(width: SanieSpace.sm),
                  Expanded(child: words),
                ],
              );
      },
    ),
  );
}

class _BudgetCard extends StatelessWidget {
  const _BudgetCard({required this.spent, required this.total});
  final double spent;
  final double total;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    final progress = total > 0 ? (spent / total).clamp(0.0, 1.0) : 0.0;
    return _HomeCard(
      child: Column(
        children: [
          Row(
            children: [
              const Icon(Icons.pie_chart_outline_rounded, size: 23),
              const SizedBox(width: SanieSpace.sm),
              Expanded(
                child: Text(
                  'Budget Progress',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              TextButton(
                onPressed: () => context.go('/budget'),
                child: const Text('View All'),
              ),
              const Icon(Icons.chevron_right_rounded, size: 20),
            ],
          ),
          const SizedBox(height: SanieSpace.sm),
          Row(
            children: [
              SizedBox(
                width: 72,
                height: 72,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    SizedBox.expand(
                      child: CircularProgressIndicator(
                        value: progress,
                        strokeWidth: 7,
                        backgroundColor: palette.border,
                        color: palette.emerald,
                        strokeCap: StrokeCap.round,
                      ),
                    ),
                    Text(
                      '${(progress * 100).round()}%',
                      style: Theme.of(context).textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: SanieSpace.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        '${_rupees(spent, whole: true)} spent',
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                    ),
                    Text(
                      'of ${_rupees(total, whole: true)} budget',
                      style: Theme.of(context).textTheme.bodyMedium
                          ?.copyWith(color: palette.mutedText),
                    ),
                    const SizedBox(height: SanieSpace.sm),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(20),
                      child: LinearProgressIndicator(
                        value: progress,
                        minHeight: 7,
                        backgroundColor: palette.border,
                        valueColor: AlwaysStoppedAnimation(palette.emerald),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (total == 0) ...[
            const SizedBox(height: SanieSpace.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'No active budgets this month',
                style: Theme.of(context).textTheme.bodySmall
                    ?.copyWith(color: palette.mutedText),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _RecentCard extends StatelessWidget {
  const _RecentCard({required this.rows, required this.today});
  final List<HomeTransaction> rows;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return _HomeCard(
      child: Column(
        children: [
          Row(
            children: [
              const Icon(Icons.receipt_long_outlined, size: 23),
              const SizedBox(width: SanieSpace.sm),
              Expanded(
                child: Text(
                  'Recent Transactions',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              TextButton(
                onPressed: () => context.go('/transactions'),
                child: const Text('See All'),
              ),
              const Icon(Icons.chevron_right_rounded, size: 20),
            ],
          ),
          if (rows.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: SanieSpace.xl),
              child: Column(
                children: [
                  Icon(
                    Icons.receipt_long_outlined,
                    color: palette.mutedText,
                    size: 32,
                  ),
                  const SizedBox(height: SanieSpace.sm),
                  Text(
                    'No recent transactions yet',
                    style: Theme.of(context).textTheme.bodyMedium
                        ?.copyWith(color: palette.mutedText),
                  ),
                ],
              ),
            )
          else
            for (final (index, item) in rows.indexed) ...[
              if (index > 0) Divider(color: palette.border, height: 1),
              _RecentRow(item: item, today: today),
            ],
        ],
      ),
    );
  }
}

class _RecentRow extends StatelessWidget {
  const _RecentRow({required this.item, required this.today});
  final HomeTransaction item;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    final row = item.row;
    final kind = row.transactionType == 'income'
        ? FinanceKind.income
        : row.transactionType == 'expense'
        ? FinanceKind.expense
        : FinanceKind.transfer;
    final sign = kind == FinanceKind.income
        ? '+'
        : kind == FinanceKind.expense
        ? '-'
        : '';
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: SanieSpace.sm),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: kind.surface(context),
            child: Icon(
              kind == FinanceKind.income
                  ? Icons.work_outline_rounded
                  : kind == FinanceKind.expense
                  ? Icons.shopping_cart_outlined
                  : Icons.swap_horiz_rounded,
              color: kind.color(context),
              size: 21,
            ),
          ),
          const SizedBox(width: SanieSpace.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                Text(
                  _transactionDate(row.transactionDate, today),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodySmall
                      ?.copyWith(color: palette.mutedText),
                ),
              ],
            ),
          ),
          const SizedBox(width: SanieSpace.sm),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 132),
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Text(
                '$sign${_rupees(row.amount.abs())}',
                maxLines: 1,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: kind.color(context),
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

String _transactionDate(String raw, DateTime today) {
  final date = DateTime.tryParse(raw);
  if (date == null) return raw;
  if (date.year == today.year &&
      date.month == today.month &&
      date.day == today.day) {
    return 'Today';
  }
  final label = _dateLabel(date);
  return label.substring(label.indexOf(',') + 2);
}
