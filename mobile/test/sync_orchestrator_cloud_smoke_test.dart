import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_command.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';
import 'package:sanie/core/sync/sync_orchestrator.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'package:uuid/uuid.dart';

void main() {
  const url = String.fromEnvironment('SANIE_SMOKE_URL');
  const key = String.fromEnvironment('SANIE_SMOKE_PUBLISHABLE_KEY');
  final email = Platform.environment['SANIE_SMOKE_EMAIL'];
  final password = Platform.environment['SANIE_SMOKE_PASSWORD'];
  final configured =
      url.isNotEmpty && key.isNotEmpty && email != null && password != null;

  test(
    'actual syncNow converges income and expense without duplication',
    () async {
      final client = SupabaseClient(url, key);
      final database = AppDatabase.forTesting(NativeDatabase.memory());
      final outbox = OutboxStore(database);
      const uuid = Uuid();
      final accountId = uuid.v4(),
          incomeCategoryId = uuid.v4(),
          expenseCategoryId = uuid.v4();
      final incomeId = uuid.v4(),
          incomeRequest = uuid.v4(),
          expenseId = uuid.v4(),
          expenseRequest = uuid.v4();
      try {
        final auth = await client.auth.signInWithPassword(
          email: email!,
          password: password!,
        );
        final userId = auth.user!.id;
        final profile = await client
            .from('profiles')
            .select('data_generation')
            .eq('id', userId)
            .single();
        final generation = (profile['data_generation'] as num).toInt();
        final day = DateTime.now().toUtc().toIso8601String().substring(0, 10);
        await client.from('accounts').insert({
          'id': accountId,
          'user_id': userId,
          'name': 'Phase 2.6 disposable',
          'account_type': 'cash',
          'opening_balance': 100,
          'currency': 'NPR',
        });
        await client.from('categories').insert([
          {
            'id': incomeCategoryId,
            'user_id': userId,
            'name': 'Phase 2.6 income',
            'category_type': 'income',
          },
          {
            'id': expenseCategoryId,
            'user_id': userId,
            'name': 'Phase 2.6 expense',
            'category_type': 'expense',
          },
        ]);
        final sync = SyncOrchestrator(
          push: PushSyncService(
            outbox: outbox,
            transport: SupabasePushSyncTransport(client),
          ),
          pull: PullSyncService(
            database: database,
            transport: SupabasePullSyncTransport(client),
          ),
        );
        await outbox.enqueue(
          OutboxCommandEnvelope.createIncome(
            userId: userId,
            id: incomeId,
            clientRequestId: incomeRequest,
            dataGeneration: generation,
            payload: {
              'p_account': accountId,
              'p_category': incomeCategoryId,
              'p_subcategory': null,
              'p_amount': 25,
              'p_date': day,
              'p_description': 'Phase 2.6 income',
              'p_payment_method': 'cash',
            },
          ),
        );
        expect((await sync.syncNow()).status, SyncNowStatus.success);
        expect(
          (await database.outboxCommandById(incomeId))?.status,
          'completed',
        );
        await _expectServer(client, accountId, incomeRequest, 125);
        expect((await database.transactionById(incomeId))?.amount, 25);
        expect((await database.accountById(accountId))?.balance, 125);
        final incomeCursor = (await database.syncStateForUser(userId))!
            .lastCursor;
        expect(incomeCursor, greaterThan(0));

        await outbox.enqueue(
          OutboxCommandEnvelope.createExpense(
            userId: userId,
            id: expenseId,
            clientRequestId: expenseRequest,
            dataGeneration: generation,
            payload: {
              'p_account': accountId,
              'p_category': expenseCategoryId,
              'p_subcategory': null,
              'p_amount': 10,
              'p_date': day,
              'p_description': 'Phase 2.6 expense',
              'p_payment_method': 'cash',
            },
          ),
        );
        expect((await sync.syncNow()).status, SyncNowStatus.success);
        expect(
          (await database.outboxCommandById(expenseId))?.status,
          'completed',
        );
        await _expectServer(client, accountId, expenseRequest, 115);
        expect((await database.transactionById(expenseId))?.amount, 10);
        expect((await database.accountById(accountId))?.balance, 115);
        final finalCursor = (await database.syncStateForUser(userId))!
            .lastCursor;

        expect((await sync.syncNow()).status, SyncNowStatus.success);
        await _expectServer(client, accountId, incomeRequest, 115);
        await _expectServer(client, accountId, expenseRequest, 115);
        expect(
          (await database.syncStateForUser(userId))!.lastCursor,
          finalCursor,
        );

        await _deleteTransaction(client, incomeId, generation);
        await _deleteTransaction(client, expenseId, generation);
        await client
            .from('accounts')
            .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
            .eq('id', accountId);
        await client
            .from('categories')
            .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
            .inFilter('id', [incomeCategoryId, expenseCategoryId]);
      } finally {
        await database.close();
        await client.auth.signOut();
      }
    },
    skip: !configured,
  );
}

Future<void> _expectServer(
  SupabaseClient client,
  String accountId,
  String requestId,
  num balance,
) async {
  final account = await client
      .from('accounts')
      .select('balance')
      .eq('id', accountId)
      .single();
  final transactions = await client
      .from('transactions')
      .select('id')
      .eq('client_request_id', requestId);
  expect((account['balance'] as num).toDouble(), balance.toDouble());
  expect(transactions, hasLength(1));
}

Future<void> _deleteTransaction(
  SupabaseClient client,
  String id,
  int generation,
) async {
  final row = await client
      .from('transactions')
      .select('version')
      .eq('id', id)
      .single();
  await client.rpc(
    'delete_transaction',
    params: {
      'p_id': id,
      'p_base_version': row['version'],
      'p_generation': generation,
    },
  );
}
