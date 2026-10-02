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
  }) async {
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
              createdAt: now,
              updatedAt: now,
            ),
          );
      await outbox.enqueue(command);
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

  Future<void> _ensureNoPendingMutation(String userId, String accountId) async {
    final unresolved =
        await (database.select(database.outboxCommands)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.commandType.isIn(const [
                    'create_account',
                    'update_account',
                    'archive_account',
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
