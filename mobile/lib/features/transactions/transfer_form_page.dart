import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';
import '../../core/sync/sync_providers.dart';
import '../accounts/accounts_repository.dart';
import 'transaction_repository.dart';
import 'transfer_repository.dart';

class TransferFormPage extends ConsumerStatefulWidget {
  const TransferFormPage({
    super.key,
    this.initialAmount,
    this.initialTo,
    this.initialFee,
    this.initialFeeCategory,
    this.initialDate,
    this.initialNote,
    this.replacingFailedId,
  });

  final String? initialAmount;
  final String? initialTo;
  final String? initialFee;
  final String? initialFeeCategory;
  final String? initialDate;
  final String? initialNote;
  final String? replacingFailedId;

  @override
  ConsumerState<TransferFormPage> createState() => _TransferFormPageState();
}

class _TransferFormPageState extends ConsumerState<TransferFormPage> {
  final _form = GlobalKey<FormState>();
  final _sourceKey = GlobalKey<FormFieldState<String>>();
  final _amount = TextEditingController();
  final _fee = TextEditingController(text: '0');
  final _note = TextEditingController();
  String? _sourceId;
  String? _destinationId;
  String? _feeCategoryId;
  late DateTime _date;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _amount.text = widget.initialAmount ?? '';
    _fee.text = widget.initialFee ?? '0';
    _note.text = widget.initialNote ?? '';
    _destinationId = widget.initialTo;
    _feeCategoryId = widget.initialFeeCategory;
    _date = DateTime.tryParse(widget.initialDate ?? '') ?? DateTime.now();
  }

  @override
  void dispose() {
    _amount.dispose();
    _fee.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _chooseDate() async {
    final selected = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(1900),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (selected != null && mounted) setState(() => _date = selected);
  }

  Future<void> _changeSource() async {
    final choice = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Insufficient funds'),
        content: const Text(
          'The source account needs enough for the transfer amount and fee. Choose another source account or cancel.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Change source account'),
          ),
        ],
      ),
    );
    if (choice == true && mounted) {
      setState(() {
        _sourceId = null;
        _error = null;
      });
      _sourceKey.currentState?.reset();
      _sourceKey.currentState?.validate();
      final fieldContext = _sourceKey.currentContext;
      if (fieldContext != null && fieldContext.mounted) {
        await Scrollable.ensureVisible(
          fieldContext,
          duration: const Duration(milliseconds: 250),
        );
      }
    }
  }

  bool _validMoney(String? raw, {required bool positive}) {
    final text = raw?.trim() ?? '';
    if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(text)) return false;
    final value = double.tryParse(text);
    return value != null &&
        value.isFinite &&
        (positive ? value > 0 : value >= 0);
  }

  Future<void> _submit(TransferRepository repository, String userId) async {
    if (_submitting || !_form.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      if (repository.authenticatedUserId() != userId) {
        throw StateError('Your session changed. Reopen the form.');
      }
      await repository.create(
        fromAccountId: _sourceId!,
        toAccountId: _destinationId!,
        amount: double.parse(_amount.text.trim()),
        fee: double.parse(_fee.text.trim()),
        feeCategoryId: _feeCategoryId,
        date: _date,
        description: _note.text,
        replacingFailedId: widget.replacingFailedId,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Transfer saved on this device. Pending sync.'),
        ),
      );
      context.go('/');
    } on InsufficientFundsException {
      if (mounted) {
        await _changeSource();
      }
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = error is FormatException
              ? error.message
              : error is StateError
              ? error.message
              : 'Could not save on this device. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => ref
      .watch(accountUserProvider)
      .when(
        data: (userId) {
          if (userId == null) {
            return const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.error,
                  message: 'Sign in to add a transfer.',
                ),
              ],
            );
          }
          final repository = ref.watch(transferRepositoryProvider);
          if (repository.authenticatedUserId() != userId) {
            return const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.loading,
                  message: 'Switching account…',
                ),
              ],
            );
          }
          final sync = ref.watch(userSyncStateProvider(userId));
          if (sync.value == null) {
            final initialization = ref.watch(syncInitializerProvider(userId));
            return FinanceScreen(
              children: [
                _header(context),
                const SizedBox(height: SanieSpace.lg),
                FinanceStateView(
                  state: initialization.hasError
                      ? FinanceViewState.error
                      : FinanceViewState.loading,
                  message: initialization.hasError
                      ? 'Offline changes could not be prepared. Check your connection and retry.'
                      : 'Preparing offline changes…',
                  onRetry: () =>
                      ref.invalidate(syncInitializerProvider(userId)),
                ),
              ],
            );
          }
          final reads = ref.watch(transactionRepositoryProvider);
          return StreamBuilder<List<Account>>(
            stream: reads.watchAccounts(userId),
            builder: (context, accountSnapshot) => StreamBuilder<List<Category>>(
              stream: reads.watchCategories(userId, 'expense'),
              builder: (context, categorySnapshot) {
                if (accountSnapshot.hasError || categorySnapshot.hasError) {
                  return FinanceScreen(
                    children: [
                      _header(context),
                      const FinanceStateView(
                        state: FinanceViewState.error,
                        message: 'Local accounts or fee categories could not be loaded.',
                      ),
                    ],
                  );
                }
                final accounts = accountSnapshot.data;
                final categories = categorySnapshot.data;
                if (accounts == null || categories == null) {
                  return FinanceScreen(
                    children: [
                      _header(context),
                      const FinanceStateView(
                        state: FinanceViewState.loading,
                        message: 'Loading local accounts…',
                      ),
                    ],
                  );
                }
                if (accounts.length < 2) {
                  return FinanceScreen(
                    children: [
                      _header(context),
                      const SizedBox(height: SanieSpace.lg),
                      const FinanceStateView(
                        state: FinanceViewState.empty,
                        message: 'Transfers need two active NPR accounts.',
                      ),
                      const SizedBox(height: SanieSpace.md),
                      FinancePrimaryButton(
                        label: 'View accounts',
                        onPressed: () => context.go('/accounts'),
                      ),
                    ],
                  );
                }
                final sourceId = accounts.any((row) => row.id == _sourceId)
                    ? _sourceId
                    : null;
                final destinationId =
                    accounts.any((row) => row.id == _destinationId)
                    ? _destinationId
                    : null;
                final feeValue = double.tryParse(_fee.text.trim()) ?? 0;
                final amountValue = double.tryParse(_amount.text.trim()) ?? 0;
                final total = amountValue + feeValue;
                return FinanceScreen(
                  children: [
                    _header(context),
                    const SizedBox(height: SanieSpace.sm),
                    Text(
                      'Saved locally first, then synced when available.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: SanieSpace.lg),
                    Form(
                      key: _form,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          TextFormField(
                            key: const Key('transfer-amount'),
                            controller: _amount,
                            enabled: !_submitting,
                            autofocus: true,
                            keyboardType: const TextInputType.numberWithOptions(
                              decimal: true,
                            ),
                            inputFormatters: [
                              FilteringTextInputFormatter.allow(
                                RegExp(r'[0-9.]'),
                              ),
                            ],
                            decoration: const InputDecoration(
                              labelText: 'Transfer amount',
                              prefixText: 'NPR ',
                            ),
                            style: Theme.of(context).textTheme.headlineMedium,
                            onChanged: (_) => setState(() {}),
                            validator: (raw) =>
                                _validMoney(raw, positive: true) ? null : 'Enter an amount above zero with up to two decimals.',
                          ),
                          const SizedBox(height: SanieSpace.md),
                          DropdownButtonFormField<String>(
                            key: _sourceKey,
                            initialValue: sourceId,
                            isExpanded: true,
                            decoration: const InputDecoration(
                              labelText: 'From account',
                            ),
                            hint: const Text('Select source account'),
                            items: [
                              for (final account in accounts)
                                DropdownMenuItem(
                                  value: account.id,
                                  child: Text(
                                    '${account.name} · ${formatNpr(account.balance)}',
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                            ],
                            onChanged: _submitting
                                ? null
                                : (value) => setState(() => _sourceId = value),
                            validator: (value) => value == null
                                ? 'Choose a source account.'
                                : null,
                          ),
                          const SizedBox(height: SanieSpace.md),
                          DropdownButtonFormField<String>(
                            key: const Key('transfer-destination'),
                            initialValue: destinationId,
                            isExpanded: true,
                            decoration: const InputDecoration(
                              labelText: 'To account',
                            ),
                            hint: const Text('Select destination account'),
                            items: [
                              for (final account in accounts)
                                DropdownMenuItem(
                                  value: account.id,
                                  child: Text(
                                    '${account.name} · ${formatNpr(account.balance)}',
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                            ],
                            onChanged: _submitting
                                ? null
                                : (value) =>
                                      setState(() => _destinationId = value),
                            validator: (value) => value == null
                                ? 'Choose a destination account.'
                                : value == _sourceId
                                ? 'Choose a different destination account.'
                                : null,
                          ),
                          const SizedBox(height: SanieSpace.md),
                          TextFormField(
                            key: const Key('transfer-fee'),
                            controller: _fee,
                            enabled: !_submitting,
                            keyboardType: const TextInputType.numberWithOptions(
                              decimal: true,
                            ),
                            inputFormatters: [
                              FilteringTextInputFormatter.allow(
                                RegExp(r'[0-9.]'),
                              ),
                            ],
                            decoration: const InputDecoration(
                              labelText: 'Transfer fee',
                              prefixText: 'NPR ',
                            ),
                            onChanged: (_) => setState(() {}),
                            validator: (raw) =>
                                _validMoney(raw, positive: false) ? null : 'Enter a fee of zero or more with up to two decimals.',
                          ),
                          if (feeValue > 0) ...[
                            const SizedBox(height: SanieSpace.md),
                            DropdownButtonFormField<String>(
                              key: const Key('transfer-fee-category'),
                              initialValue:
                                  categories.any(
                                    (row) => row.id == _feeCategoryId,
                                  )
                                  ? _feeCategoryId
                                  : null,
                              isExpanded: true,
                              decoration: const InputDecoration(
                                labelText: 'Fee expense category',
                              ),
                              hint: const Text('Select expense category'),
                              items: [
                                for (final category in categories)
                                  DropdownMenuItem(
                                    value: category.id,
                                    child: Text(
                                      category.name,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                              ],
                              onChanged: _submitting
                                  ? null
                                  : (value) =>
                                        setState(() => _feeCategoryId = value),
                              validator: (value) => value == null
                                  ? 'Choose an expense category for the fee.'
                                  : null,
                            ),
                            if (categories.isEmpty)
                              Text(
                                'No active expense category is available for the fee.',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                          ],
                          const SizedBox(height: SanieSpace.md),
                          FinanceCard(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Transfer summary',
                                  style: Theme.of(context).textTheme.titleSmall,
                                ),
                                Text('Amount: ${formatNpr(amountValue)}'),
                                Text('Fee: ${formatNpr(feeValue)}'),
                                Text('Total deducted: ${formatNpr(total)}'),
                              ],
                            ),
                          ),
                          const SizedBox(height: SanieSpace.md),
                          OutlinedButton.icon(
                            key: const Key('transfer-date'),
                            onPressed: _submitting ? null : _chooseDate,
                            icon: const Icon(Icons.calendar_today_outlined),
                            label: Text(
                              'Date: ${_date.year}-${_date.month.toString().padLeft(2, '0')}-${_date.day.toString().padLeft(2, '0')}',
                            ),
                          ),
                          const SizedBox(height: SanieSpace.md),
                          TextFormField(
                            key: const Key('transfer-note'),
                            controller: _note,
                            enabled: !_submitting,
                            maxLines: 3,
                            decoration: const InputDecoration(
                              labelText: 'Note (optional)',
                            ),
                          ),
                          if (_error != null) ...[
                            const SizedBox(height: SanieSpace.sm),
                            Text(
                              _error!,
                              key: const Key('transfer-error'),
                              style: TextStyle(
                                color: Theme.of(context).colorScheme.error,
                              ),
                            ),
                          ],
                          const SizedBox(height: SanieSpace.lg),
                          FinancePrimaryButton(
                            label: 'Save transfer',
                            onPressed: _submitting
                                ? null
                                : () => _submit(repository, userId),
                          ),
                        ],
                      ),
                    ),
                  ],
                );
              },
            ),
          );
        },
        loading: () => const FinanceScreen(
          children: [
            FinanceStateView(
              state: FinanceViewState.loading,
              message: 'Loading session…',
            ),
          ],
        ),
        error: (_, _) => const FinanceScreen(
          children: [
            FinanceStateView(
              state: FinanceViewState.error,
              message: 'Could not load session.',
            ),
          ],
        ),
      );

  Widget _header(BuildContext context) => Row(
    children: [
      IconButton(
        tooltip: 'Back',
        onPressed: () => context.go('/'),
        icon: const Icon(Icons.arrow_back_rounded),
      ),
      const SizedBox(width: SanieSpace.xs),
      Expanded(
        child: Text(
          'Add Transfer',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
      ),
    ],
  );
}
