import 'dart:convert';

import 'package:drift/drift.dart';

import '../../core/database/app_database.dart';

Map<String, double> pendingAccountDeltas(List<OutboxCommand> commands) {
  final deltas = <String, double>{};
  void add(String? id, double amount) {
    if (id == null) return;
    deltas.update(id, (value) => value + amount, ifAbsent: () => amount);
  }

  for (final command in commands) {
    if (command.status == 'completed' || command.status == 'failed') continue;
    final payload = jsonDecode(command.payloadJson) as Map<String, dynamic>;
    final amount = (payload['p_amount'] as num).toDouble();
    switch (command.commandType) {
      case 'create_income':
        add(payload['p_account'] as String?, amount);
      case 'create_expense':
        add(payload['p_account'] as String?, -amount);
      case 'create_transfer':
        final fee = (payload['p_fee'] as num?)?.toDouble() ?? 0;
        add(payload['p_from'] as String?, -amount - fee);
        add(payload['p_to'] as String?, amount);
    }
  }
  return deltas;
}

Stream<List<Account>> watchProjectedAccounts(
  AppDatabase database,
  String userId, {
  bool activeOnly = false,
  bool nprOnly = false,
}) {
  final accounts =
      (database.select(database.accounts)
            ..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.deletedAt.isNull() &
                  (activeOnly
                      ? row.isActive.equals(true)
                      : const Constant(true)) &
                  (nprOnly ? row.currency.equals('NPR') : const Constant(true)),
            )
            ..orderBy([(row) => OrderingTerm.asc(row.name)]))
          .watch();
  return accounts.asyncMap((rows) async {
    final queued =
        await (database.select(database.outboxCommands)..where(
              (row) =>
                  row.userId.equals(userId) &
                  row.commandType.isIn(const [
                    'create_income',
                    'create_expense',
                    'create_transfer',
                  ]),
            ))
            .get();
    final deltas = pendingAccountDeltas(queued);
    return [
      for (final row in rows)
        row.copyWith(balance: row.balance + (deltas[row.id] ?? 0)),
    ];
  });
}
