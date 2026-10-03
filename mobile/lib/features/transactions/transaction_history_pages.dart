import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';
import '../../core/sync/sync_providers.dart';
import '../accounts/accounts_repository.dart';
import 'transaction_history_repository.dart';
import 'transaction_form_page.dart';
import 'transaction_repository.dart';

FinanceKind _kind(Transaction row) => switch (row.transactionType) {
  'income' => FinanceKind.income,
  'transfer' => FinanceKind.transfer,
  _ => FinanceKind.expense,
};

String _date(String value) {
  final date = DateTime.tryParse(value);
  if (date == null) return value;
  return '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}';
}

String _recorded(DateTime value) {
  final local = value.toLocal();
  return '${_date(local.toIso8601String())} ${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}

String _recordedTime(DateTime value) {
  final local = value.toLocal();
  return '${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}

Widget _historyState(
  FinanceViewState state,
  String message, {
  VoidCallback? retry,
}) => FinanceScreen(
  children: [FinanceStateView(state: state, message: message, onRetry: retry)],
);

Widget _withHistory(
  WidgetRef ref,
  Widget Function(String userId, HistorySnapshot data) content,
) => ref
    .watch(accountUserProvider)
    .when(
      loading: () =>
          _historyState(FinanceViewState.loading, 'Loading transactions…'),
      error: (_, _) => _historyState(
        FinanceViewState.error,
        'Could not check your session.',
      ),
      data: (userId) {
        if (userId == null) {
          return _historyState(
            FinanceViewState.error,
            'Sign in to view transactions.',
          );
        }
        final repository = ref.watch(accountsRepositoryProvider);
        if (repository.authenticatedUserId() != userId) {
          return _historyState(
            FinanceViewState.loading,
            'Loading transactions…',
          );
        }
        ref.watch(syncInitializerProvider(userId));
        return ref
            .watch(transactionHistoryProvider(userId))
            .when(
              loading: () => _historyState(
                FinanceViewState.loading,
                'Loading transactions…',
              ),
              error: (_, _) => _historyState(
                FinanceViewState.error,
                'Could not load transactions.',
                retry: () => ref.invalidate(transactionHistoryProvider(userId)),
              ),
              data: (data) => content(userId, data),
            );
      },
    );

class TransactionHistoryPage extends ConsumerStatefulWidget {
  const TransactionHistoryPage({super.key});

  @override
  ConsumerState<TransactionHistoryPage> createState() =>
      _TransactionHistoryPageState();
}

class _TransactionHistoryPageState
    extends ConsumerState<TransactionHistoryPage> {
  String filter = 'All';
  String search = '';

  @override
  Widget build(BuildContext context) => _withHistory(ref, (userId, data) {
    final query = search.trim().toLowerCase();
    final visible = data.transactions.where((row) {
      if (filter != 'All' && typeLabel(row) != filter) return false;
      if (query.isEmpty) return true;
      return [
        data.title(row),
        row.description ?? '',
        data.categories[row.categoryId]?.name ?? '',
        data.accountContext(row),
        typeLabel(row),
        row.amount.toStringAsFixed(2),
      ].any((value) => value.toLowerCase().contains(query));
    }).toList();
    return FinanceScreen(
      children: [
        Text('Transactions', style: Theme.of(context).textTheme.headlineMedium),
        const SizedBox(height: SanieSpace.sm),
        Text(
          'Your activity, newest first',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: SanieSpace.lg),
        TextField(
          key: const Key('history-search'),
          decoration: const InputDecoration(
            labelText: 'Search transactions',
            prefixIcon: Icon(Icons.search_rounded),
          ),
          onChanged: (value) => setState(() => search = value),
        ),
        const SizedBox(height: SanieSpace.md),
        Wrap(
          spacing: SanieSpace.sm,
          runSpacing: SanieSpace.sm,
          children: [
            for (final value in const ['All', 'Income', 'Expense', 'Transfer'])
              ChoiceChip(
                key: Key('history-filter-${value.toLowerCase()}'),
                label: Text(value),
                selected: filter == value,
                onSelected: (_) => setState(() => filter = value),
              ),
          ],
        ),
        const SizedBox(height: SanieSpace.lg),
        if (visible.isEmpty)
          FinanceStateView(
            state: FinanceViewState.empty,
            message: data.transactions.isEmpty
                ? 'No transactions yet. Add income, an expense, or a transfer to get started.'
                : 'No transactions match your search or filter.',
          )
        else
          for (final row in visible) ...[
            _HistoryTile(row: row, data: data),
            const SizedBox(height: SanieSpace.sm),
          ],
      ],
    );
  });
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile({required this.row, required this.data});

  final Transaction row;
  final HistorySnapshot data;

  @override
  Widget build(BuildContext context) {
    final state = data.syncState(row);
    return FinanceCard(
      padding: EdgeInsets.zero,
      child: ListTile(
        key: Key('history-row-${row.id}'),
        minVerticalPadding: SanieSpace.md,
        contentPadding: const EdgeInsets.symmetric(horizontal: SanieSpace.md),
        onTap: () => context.go('/transactions/${Uri.encodeComponent(row.id)}'),
        leading: CircleAvatar(
          backgroundColor: _kind(row).surface(context),
          foregroundColor: _kind(row).color(context),
          child: Icon(_kind(row).icon),
        ),
        title: Text(
          data.title(row),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        subtitle: Text(
          '${typeLabel(row)} · ${data.accountContext(row)}\n${_date(row.transactionDate)} · Recorded ${_recordedTime(row.createdAt)}${state == null ? '' : ' · $state'}',
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: SizedBox(
          width: 120,
          child: Text(
            formatNpr(row.amount),
            textAlign: TextAlign.end,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleSmall
                ?.copyWith(color: _kind(row).color(context)),
          ),
        ),
      ),
    );
  }
}

class TransactionDetailsPage extends ConsumerStatefulWidget {
  const TransactionDetailsPage({super.key, required this.id});

  final String id;

  @override
  ConsumerState<TransactionDetailsPage> createState() =>
      _TransactionDetailsPageState();
}

class _TransactionDetailsPageState
    extends ConsumerState<TransactionDetailsPage> {
  bool _deleting = false;
  String? _error;

  Future<void> _delete(String userId) async {
    if (_deleting) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Delete transaction?'),
        content: const Text(
          'This transaction will leave your active history now. The change will sync when available.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('confirm-transaction-delete'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      _deleting = true;
      _error = null;
    });
    try {
      final repository = ref.read(transactionRepositoryProvider);
      if (repository.authenticatedUserId() != userId) {
        throw StateError('Your session changed. Reopen the transaction.');
      }
      await repository.mutations.delete(widget.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Transaction deleted on this device. Pending sync.'),
        ),
      );
      context.go('/transactions');
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = error is StateError
              ? error.message
              : 'Could not delete on this device. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _deleting = false);
    }
  }

  @override
  Widget build(BuildContext context) => _withHistory(ref, (userId, data) {
    Transaction? row;
    for (final transaction in data.transactions) {
      if (transaction.id == widget.id) {
        row = transaction;
        break;
      }
    }
    if (row == null) {
      return _historyState(FinanceViewState.empty, 'Transaction not found.');
    }
    final item = row;
    return FinanceScreen(
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => context.go('/transactions'),
            icon: const Icon(Icons.arrow_back_rounded),
            label: const Text('Transactions'),
          ),
        ),
        const SizedBox(height: SanieSpace.md),
        Text(
          'Transaction details',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        const SizedBox(height: SanieSpace.lg),
        FinanceCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                typeLabel(item),
                style: Theme.of(context).textTheme.titleMedium
                    ?.copyWith(color: _kind(item).color(context)),
              ),
              const SizedBox(height: SanieSpace.sm),
              Text(
                data.title(item),
                softWrap: true,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: SanieSpace.md),
              FinanceAmountText(
                amount: formatNpr(item.amount),
                kind: _kind(item),
              ),
            ],
          ),
        ),
        const SizedBox(height: SanieSpace.md),
        FinanceCard(
          child: Column(
            children: [
              _detail('Account', data.accountContext(item)),
              _detail('Transaction date', _date(item.transactionDate)),
              _detail('Recorded', _recorded(item.createdAt)),
              if (data.categories[item.categoryId] case final category?)
                _detail('Category', category.name),
              if (data.subcategories[item.subcategoryId]
                  case final subcategory?)
                _detail('Subcategory', subcategory.name),
              if (item.description?.trim() case final note?
                  when note.isNotEmpty)
                _detail('Note', note),
              if (data.syncState(item) case final state?)
                _detail('Sync', state),
            ],
          ),
        ),
        if (data.canMutate(item)) ...[
          const SizedBox(height: SanieSpace.lg),
          Wrap(
            spacing: SanieSpace.sm,
            runSpacing: SanieSpace.sm,
            children: [
              OutlinedButton.icon(
                key: const Key('transaction-edit-action'),
                onPressed: _deleting
                    ? null
                    : () => context.go(
                        '/transactions/${Uri.encodeComponent(widget.id)}/edit',
                      ),
                icon: const Icon(Icons.edit_outlined),
                label: const Text('Edit'),
              ),
              TextButton.icon(
                key: const Key('transaction-delete-action'),
                onPressed: _deleting ? null : () => _delete(userId),
                icon: const Icon(Icons.delete_outline_rounded),
                label: Text(_deleting ? 'Deleting…' : 'Delete'),
              ),
            ],
          ),
        ],
        if (_error != null) ...[
          const SizedBox(height: SanieSpace.sm),
          Text(
            _error!,
            key: const Key('transaction-delete-error'),
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        ],
      ],
    );
  });
}

class TransactionEditPage extends ConsumerStatefulWidget {
  const TransactionEditPage({super.key, required this.id});

  final String id;

  @override
  ConsumerState<TransactionEditPage> createState() =>
      _TransactionEditPageState();
}

class _TransactionEditPageState extends ConsumerState<TransactionEditPage> {
  Transaction? _initial;
  String? _initialUser;

  @override
  Widget build(BuildContext context) => _withHistory(ref, (userId, data) {
    if (_initialUser != userId) {
      _initial = null;
      _initialUser = userId;
    }
    if (_initial == null) {
      for (final row in data.transactions) {
        if (row.id == widget.id && data.canMutate(row)) {
          _initial = row;
          break;
        }
      }
    }
    final item = _initial;
    if (item == null) {
      return _historyState(
        FinanceViewState.empty,
        'Transaction unavailable for editing.',
      );
    }
    return TransactionFormPage(
      key: ValueKey('edit:${widget.id}'),
      type: item.transactionType,
      editId: widget.id,
      initialAccountId: item.accountId,
      initialCategoryId: item.categoryId,
      initialSubcategoryId: item.subcategoryId,
      initialAmount: item.amount.toStringAsFixed(2),
      initialDate: item.transactionDate,
      initialNote: item.description,
    );
  });
}

Widget _detail(String label, String value) => Padding(
  padding: const EdgeInsets.symmetric(vertical: SanieSpace.sm),
  child: Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      SizedBox(width: 120, child: Text(label)),
      Expanded(child: Text(value, softWrap: true)),
    ],
  ),
);
