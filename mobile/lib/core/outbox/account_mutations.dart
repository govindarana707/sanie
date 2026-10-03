import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import 'outbox_command.dart';
import 'outbox_store.dart';

/// Stores the provisional account and its create command in one local commit.
/// Edit/archive leave the server-derived working copy intact until pull.
class AccountMutationService {
  AccountMutationService({
    required this.database,
    required this.outbox,
    required this.authenticatedUserId,
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());

  final AppDatabase database;
  final OutboxStore outbox;
  final String? Function() authenticatedUserId;
  final DateTime Function() _clock;

  static const supportedTypes = {
    'cash',
    'bank',
    'esewa',
    'khalti',
    'ime_pay',
    'wallet',
    'credit_card',
    'savings',
    'current',
  };

  Future<String> createAccount({
    required String name,
    required String accountType,
    required double openingBalance,
    bool? isDefault,
    bool includeInNetBalance = true,
    bool includeInSavings = false,
    bool isActive = true,
  }) async {
    if (isDefault == true && !isActive) {
      throw const FormatException('An inactive account cannot be default.');
    }
    final userId = _userId();
    final generation = await _generation(userId);
    final cleanName = _name(name);
    _type(accountType);
    if (!openingBalance.isFinite ||
        openingBalance.abs() > 9999999999999.99 ||
        ((openingBalance * 100).round() - openingBalance * 100).abs() >
            0.000001) {
      throw const FormatException(
        'Opening balance must have at most two decimals.',
      );
    }
    final now = _clock();
    final command = OutboxCommandEnvelope.createAccount(
      userId: userId,
      name: cleanName,
      accountType: accountType,
      openingBalance: openingBalance,
      dataGeneration: generation,
      createdAt: now,
    );
    await database.transaction(() async {
      final activeDefault =
          await (database.select(database.accounts)
                ..where(
                  (a) =>
                      a.userId.equals(userId) &
                      a.isDefault.equals(true) &
                      a.isActive.equals(true) &
                      a.deletedAt.isNull(),
                )
                ..limit(1))
              .getSingleOrNull();
      await database
          .into(database.accounts)
          .insert(
            AccountsCompanion.insert(
              id: command.id,
              userId: userId,
              name: cleanName,
              accountType: accountType,
              openingBalance: Value(openingBalance),
              balance: Value(openingBalance),
              isDefault: Value(activeDefault == null),
              createdAt: now,
              updatedAt: now,
            ),
          );
      await outbox.enqueue(command);
      final wantedDefault =
          isActive && (isDefault == true || activeDefault == null);
      if (wantedDefault != (activeDefault == null) ||
          !includeInNetBalance ||
          includeInSavings ||
          !isActive) {
        await _queueSettings(
          userId: userId,
          account: (await database.accountById(command.id))!,
          isDefault: wantedDefault,
          includeInNetBalance: includeInNetBalance,
          includeInSavings: includeInSavings,
          isActive: isActive,
          generation: generation,
          now: now.add(const Duration(microseconds: 1)),
          pendingCreateId: command.id,
        );
      }
    });
    return command.id;
  }

  Future<String> updateAccount({
    required String accountId,
    required String name,
    required String accountType,
    String? accountNumber,
  }) async {
    final userId = _userId();
    final generation = await _generation(userId);
    final cleanName = _name(name);
    _type(accountType);
    final cleanNumber = accountNumber?.trim();
    if (cleanNumber != null && cleanNumber.length > 100) {
      throw const FormatException('Account number is too long.');
    }
    return database.transaction(() async {
      final account = await _ownedAccount(userId, accountId);
      await _ensureNoPendingMutation(userId, accountId);
      final command = OutboxCommandEnvelope.updateAccount(
        userId: userId,
        accountId: accountId,
        name: cleanName,
        accountType: accountType,
        accountNumber: cleanNumber?.isEmpty == true ? null : cleanNumber,
        baseVersion: account.version,
        dataGeneration: generation,
        createdAt: _clock(),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> archiveAccount(String accountId) async {
    final userId = _userId();
    final generation = await _generation(userId);
    return database.transaction(() async {
      final account = await _ownedAccount(userId, accountId);
      await _ensureNoPendingMutation(userId, accountId);
      final command = OutboxCommandEnvelope.archiveAccount(
        userId: userId,
        accountId: accountId,
        baseVersion: account.version,
        dataGeneration: generation,
        createdAt: _clock(),
      );
      await outbox.enqueue(command);
      return command.id;
    });
  }

  Future<String> updateSettings({
    required String accountId,
    required bool isDefault,
    required bool includeInNetBalance,
    required bool includeInSavings,
    required bool isActive,
  }) async {
    if (isDefault && !isActive) {
      throw const FormatException('An inactive account cannot be default.');
    }
    final userId = _userId();
    return database.transaction(() async {
      final account = await _ownedAccount(userId, accountId);
      await _ensureNoPendingMutation(userId, accountId);
      final generation = await _generation(userId);
      return _queueSettings(
        userId: userId,
        account: account,
        isDefault: isDefault,
        includeInNetBalance: includeInNetBalance,
        includeInSavings: includeInSavings,
        isActive: isActive,
        generation: generation,
        now: _clock(),
      );
    });
  }

  Future<String> _queueSettings({
    required String userId,
    required Account account,
    required bool isDefault,
    required bool includeInNetBalance,
    required bool includeInSavings,
    required bool isActive,
    required int generation,
    required DateTime now,
    String? pendingCreateId,
  }) async {
    final accountId = account.id;
    final all =
        await (database.select(database.accounts)
              ..where((a) => a.userId.equals(userId) & a.deletedAt.isNull())
              ..orderBy([
                (a) => OrderingTerm.asc(a.createdAt),
                (a) => OrderingTerm.asc(a.id),
              ]))
            .get();
    final effectiveDefault =
        isDefault ||
        (isActive &&
            !account.isDefault &&
            !all.any((a) => a.id != accountId && a.isDefault && a.isActive));
    final handoff =
        effectiveDefault != account.isDefault ||
        (account.isDefault && !isActive);
    if (handoff) {
      await _ensureNoPendingAccountCommand(
        userId,
        excludingCommandId: pendingCreateId,
      );
    }
    final changed = <Account>[account];
    if (effectiveDefault) {
      changed.addAll(all.where((a) => a.id != accountId && a.isDefault));
    } else if (account.isDefault) {
      final alternatives = all.where((a) => a.id != accountId && a.isActive);
      if (alternatives.isEmpty && isActive) {
        throw StateError('The only active account must remain default.');
      }
      if (alternatives.isNotEmpty) changed.add(alternatives.first);
    }
    final command = OutboxCommandEnvelope.accountSettings(
      userId: userId,
      accountId: accountId,
      isDefault: effectiveDefault,
      includeInNetBalance: includeInNetBalance,
      includeInSavings: includeInSavings,
      isActive: isActive,
      baseVersion: account.version,
      dataGeneration: generation,
      localBefore: [for (final row in changed) _settingsBefore(row)],
      createdAt: now,
    );
    for (final row in changed.skip(1)) {
      await (database.update(
        database.accounts,
      )..where((a) => a.id.equals(row.id) & a.userId.equals(userId))).write(
        AccountsCompanion(
          isDefault: Value(!effectiveDefault),
          updatedAt: Value(now),
        ),
      );
    }
    await (database.update(
      database.accounts,
    )..where((a) => a.id.equals(accountId) & a.userId.equals(userId))).write(
      AccountsCompanion(
        isDefault: Value(effectiveDefault),
        includeInNetBalance: Value(includeInNetBalance),
        includeInSavings: Value(includeInSavings),
        isActive: Value(isActive),
        updatedAt: Value(now),
      ),
    );
    await outbox.enqueue(command);
    return command.id;
  }

  Map<String, dynamic> _settingsBefore(Account row) => {
    'id': row.id,
    'version': row.version,
    'is_default': row.isDefault,
    'include_in_net_balance': row.includeInNetBalance,
    'include_in_savings': row.includeInSavings,
    'is_active': row.isActive,
    'updated_at': row.updatedAt.toUtc().toIso8601String(),
  };

  Future<void> _ensureNoPendingAccountCommand(
    String userId, {
    String? excludingCommandId,
  }) async {
    final pending =
        await (database.select(database.outboxCommands)..where(
              (c) =>
                  c.userId.equals(userId) &
                  (excludingCommandId == null
                      ? const Constant(true)
                      : c.id.equals(excludingCommandId).not()) &
                  c.commandType.isIn(const [
                    'create_account',
                    'update_account',
                    'archive_account',
                    'update_account_settings',
                  ]) &
                  c.status.isIn(const ['pending', 'processing', 'retry']),
            ))
            .get();
    if (pending.isNotEmpty) {
      throw StateError('Sync account changes before changing the default.');
    }
  }

  Future<void> _ensureNoPendingMutation(String userId, String accountId) async {
    final unresolved =
        await (database.select(database.outboxCommands)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.commandType.isIn(const [
                    'create_account',
                    'update_account',
                    'archive_account',
                    'update_account_settings',
                  ]) &
                  (row.status.isIn(const ['pending', 'processing', 'retry']) |
                      (row.commandType.equals('create_account') &
                          row.status.equals('failed'))),
            ))
            .get();
    for (final command in unresolved) {
      final target = command.commandType == 'create_account'
          ? command.id
          : (jsonDecode(command.payloadJson) as Map<String, dynamic>)['p_id'];
      if (target == accountId) {
        throw StateError('Account has an unresolved mutation. Sync it first.');
      }
    }
  }

  String _userId() {
    final userId = authenticatedUserId();
    if (userId == null || userId.isEmpty) {
      throw StateError('Authentication is required.');
    }
    return userId;
  }

  Future<int> _generation(String userId) async {
    final state = await database.syncStateForUser(userId);
    if (state == null) throw StateError('Sync generation is unavailable.');
    return state.dataGeneration;
  }

  Future<Account> _ownedAccount(String userId, String accountId) async {
    final account = await database.accountById(accountId);
    if (account == null ||
        account.userId != userId ||
        account.deletedAt != null) {
      throw StateError('Account is unavailable for this user.');
    }
    return account;
  }

  String _name(String value) {
    final clean = value.trim();
    if (clean.isEmpty || clean.length > 100) {
      throw const FormatException('Account name must be 1–100 characters.');
    }
    return clean;
  }

  void _type(String value) {
    if (!supportedTypes.contains(value)) {
      throw const FormatException('Unsupported account type.');
    }
  }
}
