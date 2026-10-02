import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../core/database/app_database.dart';
import '../../core/outbox/account_mutations.dart';
import '../../core/outbox/outbox_store.dart';

final accountUserProvider = StreamProvider<String?>((ref) async* {
  final auth = Supabase.instance.client.auth;
  yield auth.currentSession?.user.id;
  yield* auth.onAuthStateChange.map((event) => event.session?.user.id);
});

final accountsRepositoryProvider = Provider<AccountsRepository>((ref) {
  final database = AppDatabase();
  ref.onDispose(database.close);
  return AccountsRepository(
    database: database,
    authenticatedUserId: () =>
        Supabase.instance.client.auth.currentSession?.user.id,
  );
});

class AccountsRepository {
  AccountsRepository({
    required this.database,
    required this.authenticatedUserId,
  }) : mutations = AccountMutationService(
         database: database,
         outbox: OutboxStore(database),
         authenticatedUserId: authenticatedUserId,
       );

  final AppDatabase database;
  final String? Function() authenticatedUserId;
  final AccountMutationService mutations;

  Stream<List<Account>> watchAccounts(String userId) =>
      (database.select(database.accounts)
            ..where((row) => row.userId.equals(userId) & row.deletedAt.isNull())
            ..orderBy([(row) => OrderingTerm.asc(row.name)]))
          .watch();

  Stream<Account?> watchAccount(String userId, String id) =>
      (database.select(database.accounts)..where(
            (row) =>
                row.userId.equals(userId) &
                row.id.equals(id) &
                row.deletedAt.isNull(),
          ))
          .watchSingleOrNull();

  Stream<List<OutboxCommand>> watchAccountCommands(String userId) =>
      (database.select(database.outboxCommands)..where(
            (row) =>
                row.userId.equals(userId) &
                row.commandType.isIn(const [
                  'create_account',
                  'update_account',
                  'archive_account',
                ]),
          ))
          .watch();

  Future<String> create({
    required String name,
    required String type,
    required double openingBalance,
  }) => mutations.createAccount(
    name: name,
    accountType: type,
    openingBalance: openingBalance,
  );

  Future<String> update({
    required String id,
    required String name,
    required String type,
    String? accountNumber,
  }) => mutations.updateAccount(
    accountId: id,
    name: name,
    accountType: type,
    accountNumber: accountNumber,
  );

  Future<String> archive(String id) => mutations.archiveAccount(id);
}

enum AccountQueueState { none, pending, failed }

AccountQueueState accountQueueState(List<OutboxCommand> commands, String id) {
  var state = AccountQueueState.none;
  for (final command in commands) {
    if (command.status == 'completed') continue;
    if (command.commandType == 'create_account' && command.id != id) continue;
    if (command.commandType != 'create_account') {
      final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
      if (payload['p_id'] != id) continue;
    }
    if (command.status == 'failed') return AccountQueueState.failed;
    state = AccountQueueState.pending;
  }
  return state;
}

String accountTypeLabel(String type) => switch (type) {
  'cash' => 'Cash',
  'bank' => 'Bank',
  'esewa' => 'eSewa',
  'khalti' => 'Khalti',
  'ime_pay' => 'IME Pay',
  'wallet' => 'Wallet',
  'credit_card' => 'Credit card',
  'savings' => 'Savings',
  'current' => 'Current',
  _ => type,
};

String formatNpr(double value) {
  final fixed = value.abs().toStringAsFixed(2).split('.');
  final grouped = fixed[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );
  return 'NPR ${value < 0 ? '-' : ''}$grouped.${fixed[1]}';
}
