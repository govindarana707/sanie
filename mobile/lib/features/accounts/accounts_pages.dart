import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';
import '../../core/sync/sync_providers.dart';
import 'account_settings_controls.dart';
import 'accounts_repository.dart';

const _accountTypes = [
  'cash',
  'bank',
  'esewa',
  'khalti',
  'ime_pay',
  'wallet',
  'credit_card',
  'savings',
  'current',
];

Widget _authView(AsyncValue<String?> user, Widget Function(String) content) =>
    user.when(
      data: (id) => id == null
          ? const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.error,
                  message: 'Sign in to view your accounts.',
                ),
              ],
            )
          : content(id),
      loading: () => const FinanceScreen(
        children: [
          FinanceStateView(
            state: FinanceViewState.loading,
            message: 'Loading accounts…',
          ),
        ],
      ),
      error: (_, _) => const FinanceScreen(
        children: [
          FinanceStateView(
            state: FinanceViewState.error,
            message: 'Could not check your account session.',
          ),
        ],
      ),
    );

class AccountsPage extends ConsumerWidget {
  const AccountsPage({super.key});

  @override
  Widget build(
    BuildContext context,
    WidgetRef ref,
  ) => _authView(ref.watch(accountUserProvider), (userId) {
    final repository = ref.watch(accountsRepositoryProvider);
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
    ref.watch(syncInitializerProvider(userId));
    return StreamBuilder<List<Account>>(
      key: ValueKey(userId),
      stream: repository.watchAccounts(userId),
      builder: (context, snapshot) {
        final accounts = snapshot.data;
        return FinanceScreen(
          children: [
            _AccountHeading(title: 'Accounts', backTo: '/more'),
            const SizedBox(height: SanieSpace.md),
            Text(
              'Your local working copy',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: SanieSpace.lg),
            if (snapshot.hasError)
              const FinanceStateView(
                state: FinanceViewState.error,
                message: 'Accounts could not be loaded.',
              )
            else if (accounts == null)
              const FinanceStateView(
                state: FinanceViewState.loading,
                message: 'Loading accounts…',
              )
            else ...[
              if (accounts.isNotEmpty) ...[
                FinanceCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Total included balance · NPR',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: SanieSpace.sm),
                      FinanceAmountText(
                        amount: formatNpr(
                          accounts
                              .where(
                                (a) =>
                                    a.currency == 'NPR' &&
                                    a.isActive &&
                                    a.includeInNetBalance,
                              )
                              .fold<double>(0, (sum, a) => sum + a.balance),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: SanieSpace.lg),
                const FinanceSectionHeader(title: 'Your accounts'),
                const SizedBox(height: SanieSpace.md),
                StreamBuilder(
                  stream: repository.watchAccountCommands(userId),
                  builder: (context, commands) => Column(
                    children: [
                      for (final account in accounts) ...[
                        _AccountCard(
                          account: account,
                          queue: accountQueueState(
                            commands.data ?? [],
                            account.id,
                          ),
                        ),
                        const SizedBox(height: SanieSpace.sm),
                      ],
                    ],
                  ),
                ),
              ] else ...[
                const FinanceStateView(
                  state: FinanceViewState.empty,
                  message:
                      'No accounts yet. Add one to start tracking your money.',
                ),
                const SizedBox(height: SanieSpace.lg),
              ],
              FinancePrimaryButton(
                label: 'Add account',
                icon: Icons.add_rounded,
                onPressed: () => context.go('/accounts/add'),
              ),
            ],
          ],
        );
      },
    );
  });
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.account, required this.queue});
  final Account account;
  final AccountQueueState queue;

  @override
  Widget build(BuildContext context) => FinanceCard(
    child: InkWell(
      onTap: () => context.go('/accounts/${Uri.encodeComponent(account.id)}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  account.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              const Icon(Icons.chevron_right_rounded),
            ],
          ),
          const SizedBox(height: SanieSpace.xs),
          Text(
            '${accountTypeLabel(account.accountType)} · ${account.isDefault ? 'Default · ' : ''}${account.isActive ? 'Active' : 'Inactive'}${queue == AccountQueueState.pending
                ? ' · Pending sync'
                : queue == AccountQueueState.failed
                ? ' · Needs attention'
                : ''}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: SanieSpace.md),
          FinanceAmountText(
            amount: account.currency == 'NPR'
                ? formatNpr(account.balance)
                : '${account.currency} ${account.balance.toStringAsFixed(2)}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
        ],
      ),
    ),
  );
}

class AccountDetailsPage extends ConsumerStatefulWidget {
  const AccountDetailsPage({super.key, required this.id});
  final String id;

  @override
  ConsumerState<AccountDetailsPage> createState() => _AccountDetailsPageState();
}

class _AccountDetailsPageState extends ConsumerState<AccountDetailsPage> {
  bool _archiving = false;
  String? _error;

  Future<void> _archive(AccountsRepository repository) async {
    if (_archiving) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Archive account?'),
        content: const Text(
          'This queues an archive for sync. Financial history is not deleted.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Archive'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted || _archiving) return;
    setState(() {
      _archiving = true;
      _error = null;
    });
    try {
      await repository.archive(widget.id);
    } catch (error) {
      if (mounted) setState(() => _error = _accountError(error));
    } finally {
      if (mounted) setState(() => _archiving = false);
    }
  }

  @override
  Widget build(
    BuildContext context,
  ) => _authView(ref.watch(accountUserProvider), (userId) {
    final repository = ref.watch(accountsRepositoryProvider);
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
    return StreamBuilder<Account?>(
      key: ValueKey('$userId:${widget.id}'),
      stream: repository.watchAccount(userId, widget.id),
      builder: (context, snapshot) {
        final account = snapshot.data;
        return FinanceScreen(
          children: [
            _AccountHeading(title: 'Account details', backTo: '/accounts'),
            const SizedBox(height: SanieSpace.lg),
            if (snapshot.hasError)
              const FinanceStateView(
                state: FinanceViewState.error,
                message: 'Account could not be loaded.',
              )
            else if (snapshot.connectionState == ConnectionState.waiting)
              const FinanceStateView(
                state: FinanceViewState.loading,
                message: 'Loading account…',
              )
            else if (account == null)
              const FinanceStateView(
                state: FinanceViewState.empty,
                message: 'Account unavailable.',
              )
            else
              StreamBuilder(
                stream: repository.watchAccountCommands(userId),
                builder: (context, commands) {
                  final queue = accountQueueState(
                    commands.data ?? [],
                    account.id,
                  );
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      FinanceCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              account.name,
                              style: Theme.of(context).textTheme.headlineMedium,
                            ),
                            const SizedBox(height: SanieSpace.sm),
                            Text(accountTypeLabel(account.accountType)),
                            const SizedBox(height: SanieSpace.lg),
                            Text(
                              'Current balance',
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                            const SizedBox(height: SanieSpace.sm),
                            FinanceAmountText(
                              amount: account.currency == 'NPR'
                                  ? formatNpr(account.balance)
                                  : '${account.currency} ${account.balance.toStringAsFixed(2)}',
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: SanieSpace.md),
                      FinanceCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Status: ${account.isActive ? 'Active' : 'Inactive'}',
                            ),
                            Text('Currency: ${account.currency}'),
                            if (account.accountNumber != null &&
                                account.accountNumber!.isNotEmpty)
                              Text('Account number: ${account.accountNumber}'),
                            Text('Version: ${account.version}'),
                            if (queue != AccountQueueState.none) ...[
                              const SizedBox(height: SanieSpace.sm),
                              Text(
                                queue == AccountQueueState.failed
                                    ? 'Account change needs attention before retry.'
                                    : 'Account change pending sync. Current details stay unchanged until the server confirms it.',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(height: SanieSpace.md),
                      AccountSettingsControls(
                        value: AccountSettingsDraft.fromAccount(account),
                      ),
                      Padding(
                        padding: const EdgeInsets.only(top: SanieSpace.xs),
                        child: Text(
                          'Use Edit account to change these settings.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: SanieSpace.md),
                        Text(
                          _error!,
                          key: const Key('archive-error'),
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ],
                      const SizedBox(height: SanieSpace.lg),
                      FinancePrimaryButton(
                        label: 'Edit account',
                        onPressed:
                            queue == AccountQueueState.none && !_archiving
                            ? () => context.go(
                                '/accounts/${Uri.encodeComponent(widget.id)}/edit',
                              )
                            : null,
                      ),
                      const SizedBox(height: SanieSpace.sm),
                      OutlinedButton(
                        onPressed:
                            queue == AccountQueueState.none && !_archiving
                            ? () => _archive(repository)
                            : null,
                        child: const Text('Archive account'),
                      ),
                    ],
                  );
                },
              ),
          ],
        );
      },
    );
  });
}

class AccountFormPage extends ConsumerWidget {
  const AccountFormPage({super.key, this.id});
  final String? id;

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      _authView(ref.watch(accountUserProvider), (userId) {
        final repository = ref.watch(accountsRepositoryProvider);
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
        final syncStateAsync = ref.watch(userSyncStateProvider(userId));
        return syncStateAsync.when(
          data: (syncState) {
            if (syncState == null) {
              final syncInitAsync = ref.watch(syncInitializerProvider(userId));
              return syncInitAsync.when(
                data: (_) => const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.loading,
                      message: 'Preparing sync state…',
                    ),
                  ],
                ),
                loading: () => const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.loading,
                      message: 'Preparing sync state…',
                    ),
                  ],
                ),
                error: (error, _) => FinanceScreen(
                  children: [
                    _AccountHeading(
                      title: id == null ? 'Add account' : 'Edit account',
                      backTo: '/accounts',
                    ),
                    const SizedBox(height: SanieSpace.lg),
                    const FinanceStateView(
                      state: FinanceViewState.error,
                      message: 'Could not initialize sync state. Please check your connection and try again.',
                    ),
                    const SizedBox(height: SanieSpace.md),
                    FinancePrimaryButton(
                      label: 'Retry',
                      onPressed: () =>
                          ref.invalidate(syncInitializerProvider(userId)),
                    ),
                  ],
                ),
              );
            }
            if (id == null) {
              return _AccountEditor(repository: repository, userId: userId);
            }
            return StreamBuilder<Account?>(
              key: ValueKey('$userId:$id'),
              stream: repository.watchAccount(userId, id!),
              builder: (context, snapshot) {
                if (snapshot.hasError) {
                  return const FinanceScreen(
                    children: [
                      FinanceStateView(
                        state: FinanceViewState.error,
                        message: 'Account could not be loaded.',
                      ),
                    ],
                  );
                }
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const FinanceScreen(
                    children: [
                      FinanceStateView(
                        state: FinanceViewState.loading,
                        message: 'Loading account…',
                      ),
                    ],
                  );
                }
                final account = snapshot.data;
                if (account == null) {
                  return const FinanceScreen(
                    children: [
                      FinanceStateView(
                        state: FinanceViewState.empty,
                        message: 'Account unavailable.',
                      ),
                    ],
                  );
                }
                return _AccountEditor(
                  key: ValueKey('$userId:${account.id}'),
                  repository: repository,
                  userId: userId,
                  account: account,
                );
              },
            );
          },
          loading: () {
            ref.watch(syncInitializerProvider(userId));
            return const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.loading,
                  message: 'Preparing sync state…',
                ),
              ],
            );
          },
          error: (error, _) => FinanceScreen(
            children: [
              _AccountHeading(
                title: id == null ? 'Add account' : 'Edit account',
                backTo: '/accounts',
              ),
              const SizedBox(height: SanieSpace.lg),
              const FinanceStateView(
                state: FinanceViewState.error,
                message: 'Could not initialize sync state. Please check your connection and try again.',
              ),
              const SizedBox(height: SanieSpace.md),
              FinancePrimaryButton(
                label: 'Retry',
                onPressed: () =>
                    ref.invalidate(syncInitializerProvider(userId)),
              ),
            ],
          ),
        );
      });
}

class _AccountEditor extends StatefulWidget {
  const _AccountEditor({
    super.key,
    required this.repository,
    required this.userId,
    this.account,
  });
  final AccountsRepository repository;
  final String userId;
  final Account? account;

  @override
  State<_AccountEditor> createState() => _AccountEditorState();
}

class _AccountEditorState extends State<_AccountEditor> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _opening;
  late final TextEditingController _number;
  late final Stream<List<Account>> _accounts;
  late String _type;
  late AccountSettingsDraft _settings;
  bool _submitting = false;
  bool _savingSettings = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.account?.name);
    _opening = TextEditingController(text: '0.00');
    _number = TextEditingController(text: widget.account?.accountNumber);
    _type = widget.account?.accountType ?? 'cash';
    _settings = widget.account == null
        ? const AccountSettingsDraft.initial()
        : AccountSettingsDraft.fromAccount(widget.account!);
    _accounts = widget.repository.watchAccounts(widget.userId);
  }

  @override
  void dispose() {
    _name.dispose();
    _opening.dispose();
    _number.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_submitting || !_form.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      if (widget.repository.authenticatedUserId() != widget.userId) {
        throw StateError(
          'Your account session changed. Please reopen this form.',
        );
      }
      late final String id;
      if (widget.account == null) {
        id = await widget.repository.create(
          name: _name.text.trim(),
          type: _type,
          openingBalance: double.parse(_opening.text.trim()),
          isDefault: _settings.isDefault,
          includeInNetBalance: _settings.includeInNetBalance,
          includeInSavings: _settings.includeInSavings,
          isActive: _settings.isActive,
        );
      } else {
        id = widget.account!.id;
        await widget.repository.update(
          id: id,
          name: _name.text.trim(),
          type: _type,
          accountNumber: _number.text.trim(),
        );
      }
      if (mounted && widget.repository.authenticatedUserId() == widget.userId) {
        context.go('/accounts/${Uri.encodeComponent(id)}');
      }
    } catch (error) {
      if (mounted) setState(() => _error = _accountError(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _saveSettings() async {
    final account = widget.account;
    if (account == null ||
        _savingSettings ||
        _submitting ||
        _settings.matches(account)) {
      return;
    }
    setState(() {
      _savingSettings = true;
      _error = null;
    });
    try {
      if (widget.repository.authenticatedUserId() != widget.userId) {
        throw StateError(
          'Your account session changed. Please reopen this form.',
        );
      }
      await widget.repository.updateSettings(
        id: account.id,
        isDefault: _settings.isDefault,
        includeInNetBalance: _settings.includeInNetBalance,
        includeInSavings: _settings.includeInSavings,
        isActive: _settings.isActive,
      );
      if (mounted) context.go('/accounts/${Uri.encodeComponent(account.id)}');
    } catch (error) {
      if (mounted) setState(() => _error = _accountError(error));
    } finally {
      if (mounted) setState(() => _savingSettings = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.account != null;
    return FinanceScreen(
      children: [
        _AccountHeading(
          title: editing ? 'Edit account' : 'Add account',
          backTo: editing
              ? '/accounts/${Uri.encodeComponent(widget.account!.id)}'
              : '/accounts',
        ),
        const SizedBox(height: SanieSpace.lg),
        Form(
          key: _form,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                key: const Key('account-name'),
                controller: _name,
                enabled: !_submitting,
                maxLength: 100,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(labelText: 'Account name'),
                validator: (value) => value == null || value.trim().isEmpty
                    ? 'Enter an account name.'
                    : null,
              ),
              const SizedBox(height: SanieSpace.md),
              DropdownButtonFormField<String>(
                key: const Key('account-type'),
                initialValue: _type,
                decoration: const InputDecoration(labelText: 'Account type'),
                items: [
                  for (final type in _accountTypes)
                    DropdownMenuItem(
                      value: type,
                      child: Text(accountTypeLabel(type)),
                    ),
                ],
                onChanged: _submitting
                    ? null
                    : (value) {
                        if (value != null) setState(() => _type = value);
                      },
              ),
              const SizedBox(height: SanieSpace.md),
              if (!editing)
                TextFormField(
                  key: const Key('account-opening'),
                  controller: _opening,
                  enabled: !_submitting,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                    signed: true,
                  ),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.\-]')),
                  ],
                  decoration: const InputDecoration(
                    labelText: 'Opening balance',
                    prefixText: 'NPR ',
                  ),
                  validator: (value) {
                    final text = value?.trim() ?? '';
                    if (!RegExp(r'^-?\d+(\.\d{1,2})?$').hasMatch(text)) {
                      return 'Enter an amount with up to two decimals.';
                    }
                    final amount = double.tryParse(text);
                    if (amount == null ||
                        !amount.isFinite ||
                        amount.abs() > 9999999999999.99) {
                      return 'Enter an amount within the supported range.';
                    }
                    return null;
                  },
                )
              else ...[
                TextFormField(
                  key: const Key('account-number'),
                  controller: _number,
                  enabled: !_submitting,
                  maxLength: 100,
                  decoration: const InputDecoration(
                    labelText: 'Account number (optional)',
                  ),
                ),
                const SizedBox(height: SanieSpace.sm),
                FinanceCard(
                  child: Text(
                    'Current balance: ${formatNpr(widget.account!.balance)}\nBalance changes only through financial transactions.',
                  ),
                ),
              ],
              const SizedBox(height: SanieSpace.md),
              StreamBuilder<List<Account>>(
                stream: _accounts,
                builder: (context, snapshot) {
                  final otherActive =
                      snapshot.data?.any(
                        (a) => a.id != widget.account?.id && a.isActive,
                      ) ??
                      false;
                  final shown = _settings.isActive && !otherActive
                      ? _settings.copyWith(isDefault: true)
                      : _settings;
                  return AccountSettingsControls(
                    value: shown,
                    canUnsetDefault: otherActive,
                    onChanged: _submitting || _savingSettings
                        ? null
                        : (next) => setState(() => _settings = next),
                  );
                },
              ),
              if (editing) ...[
                const SizedBox(height: SanieSpace.sm),
                OutlinedButton(
                  key: const Key('account-save-settings'),
                  onPressed:
                      _submitting ||
                          _savingSettings ||
                          _settings.matches(widget.account!)
                      ? null
                      : _saveSettings,
                  child: Text(
                    _savingSettings
                        ? 'Saving settings…'
                        : 'Save account settings',
                  ),
                ),
                Text(
                  'Account details and settings save separately.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: SanieSpace.md),
                Text(
                  _error!,
                  key: const Key('account-form-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: SanieSpace.lg),
              FinancePrimaryButton(
                label: _submitting
                    ? 'Saving…'
                    : editing
                    ? 'Save changes'
                    : 'Add account',
                onPressed: _submitting ? null : _submit,
              ),
              const SizedBox(height: SanieSpace.sm),
              Text(
                'Saved on this device first. Changes sync when you run sync.',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _AccountHeading extends StatelessWidget {
  const _AccountHeading({required this.title, required this.backTo});
  final String title;
  final String backTo;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      IconButton(
        tooltip: 'Back',
        onPressed: () => context.go(backTo),
        icon: const Icon(Icons.arrow_back_rounded),
      ),
      const SizedBox(width: SanieSpace.sm),
      Expanded(
        child: Text(title, style: Theme.of(context).textTheme.headlineMedium),
      ),
    ],
  );
}

String _accountError(Object error) {
  if (error is FormatException) return error.message;
  if (error is StateError) return error.message;
  return 'Could not save this account. Please try again.';
}
