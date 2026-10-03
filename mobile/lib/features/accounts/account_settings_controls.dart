import 'package:flutter/material.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';

class AccountSettingsDraft {
  const AccountSettingsDraft({
    required this.isDefault,
    required this.includeInNetBalance,
    required this.includeInSavings,
    required this.isActive,
  });

  factory AccountSettingsDraft.fromAccount(Account account) =>
      AccountSettingsDraft(
        isDefault: account.isDefault,
        includeInNetBalance: account.includeInNetBalance,
        includeInSavings: account.includeInSavings,
        isActive: account.isActive,
      );

  const AccountSettingsDraft.initial()
    : isDefault = false,
      includeInNetBalance = true,
      includeInSavings = false,
      isActive = true;

  final bool isDefault;
  final bool includeInNetBalance;
  final bool includeInSavings;
  final bool isActive;

  AccountSettingsDraft copyWith({
    bool? isDefault,
    bool? includeInNetBalance,
    bool? includeInSavings,
    bool? isActive,
  }) => AccountSettingsDraft(
    isDefault: isDefault ?? this.isDefault,
    includeInNetBalance: includeInNetBalance ?? this.includeInNetBalance,
    includeInSavings: includeInSavings ?? this.includeInSavings,
    isActive: isActive ?? this.isActive,
  );

  bool matches(Account account) =>
      isDefault == account.isDefault &&
      includeInNetBalance == account.includeInNetBalance &&
      includeInSavings == account.includeInSavings &&
      isActive == account.isActive;
}

class AccountSettingsControls extends StatelessWidget {
  const AccountSettingsControls({
    super.key,
    required this.value,
    this.onChanged,
    this.canUnsetDefault = true,
  });

  final AccountSettingsDraft value;
  final ValueChanged<AccountSettingsDraft>? onChanged;
  final bool canUnsetDefault;

  @override
  Widget build(BuildContext context) => FinanceCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Account settings',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: SanieSpace.xs),
        SwitchListTile.adaptive(
          key: const Key('account-setting-default'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Set as default account'),
          subtitle: const Text('Preselected for new income and expenses'),
          value: value.isDefault,
          onChanged:
              onChanged == null ||
                  !value.isActive ||
                  (value.isDefault && !canUnsetDefault)
              ? null
              : (selected) => onChanged!(value.copyWith(isDefault: selected)),
        ),
        SwitchListTile.adaptive(
          key: const Key('account-setting-net-balance'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Include in Net Balance'),
          value: value.includeInNetBalance,
          onChanged: onChanged == null
              ? null
              : (selected) =>
                    onChanged!(value.copyWith(includeInNetBalance: selected)),
        ),
        SwitchListTile.adaptive(
          key: const Key('account-setting-savings'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Count this account as savings'),
          value: value.includeInSavings,
          onChanged: onChanged == null
              ? null
              : (selected) =>
                    onChanged!(value.copyWith(includeInSavings: selected)),
        ),
        SwitchListTile.adaptive(
          key: const Key('account-setting-active'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Active'),
          subtitle: const Text('Inactive accounts stay in history'),
          value: value.isActive,
          onChanged: onChanged == null
              ? null
              : (selected) => onChanged!(
                  value.copyWith(
                    isActive: selected,
                    isDefault: selected ? value.isDefault : false,
                  ),
                ),
        ),
      ],
    ),
  );
}
