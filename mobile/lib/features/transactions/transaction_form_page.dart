import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';
import '../../core/sync/sync_providers.dart';
import '../accounts/accounts_repository.dart';
import '../categories/categories_repository.dart';
import 'transaction_repository.dart';

class TransactionFormPage extends ConsumerStatefulWidget {
  const TransactionFormPage({
    super.key,
    required this.type,
    this.initialAmount,
    this.initialCategoryId,
    this.initialSubcategoryId,
    this.initialDate,
    this.initialNote,
    this.replacingFailedId,
    this.initialAccountId,
    this.editId,
  });
  final String type;
  final String? initialAmount;
  final String? initialCategoryId;
  final String? initialSubcategoryId;
  final String? initialDate;
  final String? initialNote;
  final String? replacingFailedId;
  final String? initialAccountId;
  final String? editId;

  @override
  ConsumerState<TransactionFormPage> createState() =>
      _TransactionFormPageState();
}

class _TransactionFormPageState extends ConsumerState<TransactionFormPage> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  GlobalKey<FormFieldState<String>> _accountKey =
      GlobalKey<FormFieldState<String>>();
  String? _accountId;
  bool _accountChoiceTouched = false;
  String? _categoryId;
  String? _subcategoryId;
  late DateTime _date;
  bool _submitting = false;
  String? _error;

  String get _label => widget.type == 'income' ? 'Income' : 'Expense';

  @override
  void initState() {
    super.initState();
    _date = DateTime.tryParse(widget.initialDate ?? '') ?? DateTime.now();
    _amount.text = widget.initialAmount ?? '';
    _note.text = widget.initialNote ?? '';
    _categoryId = widget.initialCategoryId;
    _subcategoryId = widget.initialSubcategoryId;
    _accountId = widget.initialAccountId;
  }

  @override
  void dispose() {
    _amount.dispose();
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

  Future<void> _replaceAccount() async {
    final choice = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Insufficient funds'),
        content: const Text(
          'This account does not have enough available balance. Choose another payment account or cancel.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Replace payment account'),
          ),
        ],
      ),
    );
    if (choice == true && mounted) {
      setState(() {
        _accountId = null;
        _accountChoiceTouched = true;
        _error = null;
      });
      _accountKey.currentState?.reset();
      _accountKey.currentState?.validate();
      final context = _accountKey.currentContext;
      if (context != null && context.mounted) {
        await Scrollable.ensureVisible(
          context,
          duration: const Duration(milliseconds: 250),
        );
      }
    }
  }

  Future<void> _submit(TransactionRepository repository, String userId) async {
    if (_submitting || !_form.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      if (repository.authenticatedUserId() != userId) {
        throw StateError('Your session changed. Reopen the form.');
      }
      final amount = double.parse(_amount.text.trim());
      if (widget.editId case final editId?) {
        await repository.mutations.edit(
          id: editId,
          accountId: _accountId!,
          categoryId: _categoryId!,
          subcategoryId: _subcategoryId,
          amount: amount,
          date: _date,
          description: _note.text,
        );
      } else {
        await repository.create(
          type: widget.type,
          accountId: _accountId!,
          categoryId: _categoryId!,
          subcategoryId: _subcategoryId,
          amount: amount,
          date: _date,
          description: _note.text,
          replacingFailedId: widget.replacingFailedId,
        );
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$_label saved on this device. Pending sync.')),
      );
      if (context.canPop()) {
        context.pop();
      } else {
        context.go(
          widget.editId == null
              ? '/'
              : '/transactions/${Uri.encodeComponent(widget.editId!)}',
        );
      }
    } on InsufficientFundsException {
      if (mounted) await _replaceAccount();
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
  Widget build(BuildContext context) {
    final auth = ref.watch(accountUserProvider);
    return auth.when(
      data: (userId) {
        if (userId == null) {
          return const FinanceScreen(
            children: [
              FinanceStateView(
                state: FinanceViewState.error,
                message: 'Sign in to add a transaction.',
              ),
            ],
          );
        }
        final repository = ref.watch(transactionRepositoryProvider);
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
        ref.watch(categoriesBootstrapProvider(userId));
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
                onRetry: () => ref.invalidate(syncInitializerProvider(userId)),
              ),
            ],
          );
        }
        return StreamBuilder<List<Account>>(
          stream: repository.watchAccounts(userId),
          builder: (context, accountSnapshot) => StreamBuilder<List<Category>>(
            stream: repository.watchCategories(userId, widget.type),
            builder: (context, categorySnapshot) {
              final accounts = accountSnapshot.data;
              final categories = categorySnapshot.data;
              if (accountSnapshot.hasError || categorySnapshot.hasError) {
                return FinanceScreen(
                  children: [
                    _header(context),
                    const FinanceStateView(
                      state: FinanceViewState.error,
                      message: 'Accounts or categories could not be loaded from this device.',
                    ),
                  ],
                );
              }
              if (accounts == null || categories == null) {
                return FinanceScreen(
                  children: [
                    _header(context),
                    const FinanceStateView(
                      state: FinanceViewState.loading,
                      message: 'Loading local accounts and categories…',
                    ),
                  ],
                );
              }
              if (_accountId != null &&
                  !accounts.any((row) => row.id == _accountId)) {
                _accountId = null;
                _accountChoiceTouched = true;
                _accountKey = GlobalKey<FormFieldState<String>>();
              }
              if (_accountId == null &&
                  !_accountChoiceTouched &&
                  widget.editId == null) {
                for (final account in accounts) {
                  if (account.isDefault) {
                    _accountId = account.id;
                    break;
                  }
                }
              }
              final accountId = _accountId;
              final categoryId = categories.any((row) => row.id == _categoryId)
                  ? _categoryId
                  : null;
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
                          key: const Key('transaction-amount'),
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
                            labelText: 'Amount',
                            prefixText: 'NPR ',
                          ),
                          style: Theme.of(context).textTheme.headlineMedium,
                          validator: (raw) {
                            final value = raw?.trim() ?? '';
                            if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(value) ||
                                (double.tryParse(value) ?? 0) <= 0) {
                              return 'Enter an amount above zero with up to two decimals.';
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: SanieSpace.md),
                        DropdownButtonFormField<String>(
                          key: _accountKey,
                          initialValue: accountId,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'Account',
                          ),
                          hint: const Text('Select account'),
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
                              : (value) => setState(() {
                                  _accountId = value;
                                  _accountChoiceTouched = true;
                                }),
                          validator: (value) =>
                              value == null ? 'Choose an account.' : null,
                        ),
                        if (accounts.isEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: SanieSpace.xs),
                            child: Text(
                              'Add an active NPR account before saving $_label.',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ),
                        const SizedBox(height: SanieSpace.md),
                        DropdownButtonFormField<String>(
                          key: ValueKey('transaction-category:$categoryId'),
                          initialValue: categoryId,
                          isExpanded: true,
                          decoration: const InputDecoration(
                            labelText: 'Category',
                          ),
                          hint: const Text('Select category'),
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
                              : (value) => setState(() {
                                  if (_categoryId != value) {
                                    _subcategoryId = null;
                                  }
                                  _categoryId = value;
                                }),
                          validator: (value) =>
                              value == null ? 'Choose a category.' : null,
                        ),
                        if (categories.isEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: SanieSpace.xs),
                            child: Text(
                              'No active $_label categories are saved on this device.',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ),
                        if (categoryId != null) ...[
                          const SizedBox(height: SanieSpace.md),
                          StreamBuilder<List<Subcategory>>(
                            key: ValueKey('subcategories:$categoryId'),
                            stream: repository.watchSubcategories(
                              userId,
                              categoryId,
                            ),
                            builder: (context, snapshot) {
                              final rows = snapshot.data ?? [];
                              if (snapshot.hasError || rows.isEmpty) {
                                return const SizedBox.shrink();
                              }
                              final selected =
                                  rows.any((row) => row.id == _subcategoryId)
                                  ? _subcategoryId
                                  : null;
                              return DropdownButtonFormField<String>(
                                key: const Key('transaction-subcategory'),
                                initialValue: selected,
                                isExpanded: true,
                                decoration: const InputDecoration(
                                  labelText: 'Subcategory (optional)',
                                ),
                                items: [
                                  const DropdownMenuItem(
                                    value: '',
                                    child: Text('None'),
                                  ),
                                  for (final row in rows)
                                    DropdownMenuItem(
                                      value: row.id,
                                      child: Text(
                                        row.name,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                ],
                                onChanged: _submitting
                                    ? null
                                    : (value) => setState(
                                        () => _subcategoryId = value == ''
                                            ? null
                                            : value,
                                      ),
                              );
                            },
                          ),
                        ],
                        const SizedBox(height: SanieSpace.md),
                        OutlinedButton.icon(
                          key: const Key('transaction-date'),
                          onPressed: _submitting ? null : _chooseDate,
                          icon: const Icon(Icons.calendar_today_outlined),
                          label: Text(
                            'Date: ${_date.year}-${_date.month.toString().padLeft(2, '0')}-${_date.day.toString().padLeft(2, '0')}',
                          ),
                        ),
                        const SizedBox(height: SanieSpace.md),
                        TextFormField(
                          key: const Key('transaction-note'),
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
                            key: const Key('transaction-error'),
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.error,
                            ),
                          ),
                        ],
                        const SizedBox(height: SanieSpace.lg),
                        FinancePrimaryButton(
                          label: widget.editId == null
                              ? 'Save $_label'
                              : 'Save changes',
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
  }

  Widget _header(BuildContext context) => Row(
    children: [
      IconButton(
        tooltip: 'Back',
        onPressed: () => context.canPop()
            ? context.pop()
            : context.go(
                widget.editId == null
                    ? '/'
                    : '/transactions/${Uri.encodeComponent(widget.editId!)}',
              ),
        icon: const Icon(Icons.arrow_back_rounded),
      ),
      const SizedBox(width: SanieSpace.xs),
      Expanded(
        child: Text(
          '${widget.editId == null ? 'Add' : 'Edit'} $_label',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
      ),
    ],
  );
}
