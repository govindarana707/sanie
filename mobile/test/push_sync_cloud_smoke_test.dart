import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/outbox_command.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'package:uuid/uuid.dart';

void main() {
  const url = String.fromEnvironment('SANIE_SMOKE_URL');
  const publishableKey = String.fromEnvironment('SANIE_SMOKE_PUBLISHABLE_KEY');
  final email = Platform.environment['SANIE_SMOKE_EMAIL'];
  final password = Platform.environment['SANIE_SMOKE_PASSWORD'];
  final configured =
      url.isNotEmpty &&
      publishableKey.isNotEmpty &&
      email != null &&
      email.isNotEmpty &&
      password != null &&
      password.isNotEmpty;

  test(
    'authenticated sanie-dev push sync completes and safely cleans fixtures',
    () async {
      final client = SupabaseClient(url, publishableKey);
      final database = AppDatabase.forTesting(NativeDatabase.memory());
      final outbox = OutboxStore(database);
      const uuid = Uuid();
      final accountId = uuid.v4();
      final incomeCategoryId = uuid.v4();
      final expenseCategoryId = uuid.v4();

      try {
        final auth = await client.auth.signInWithPassword(
          email: email!,
          password: password!,
        );
        final userId = auth.user?.id;
        expect(userId, isNotNull);

        final profile = await client
            .from('profiles')
            .select('id,data_generation')
            .eq('id', userId!)
            .single();
        final generation = (profile['data_generation'] as num).toInt();
        await _cleanupExistingFixtures(client, userId, generation);

        await client.from('accounts').insert({
          'id': accountId,
          'user_id': userId,
          'name': 'Phase 2.4 disposable account',
          'account_type': 'cash',
          'opening_balance': 100,
          'currency': 'NPR',
        });
        await client.from('categories').insert([
          {
            'id': incomeCategoryId,
            'user_id': userId,
            'name': 'Phase 2.4 disposable income',
            'category_type': 'income',
          },
          {
            'id': expenseCategoryId,
            'user_id': userId,
            'name': 'Phase 2.4 disposable expense',
            'category_type': 'expense',
          },
        ]);

        final day = DateTime.now().toUtc().toIso8601String().substring(0, 10);
        final income = OutboxCommandEnvelope.createIncome(
          userId: userId,
          dataGeneration: generation,
          payload: {
            'p_account': accountId,
            'p_category': incomeCategoryId,
            'p_subcategory': null,
            'p_amount': 25,
            'p_date': day,
            'p_description': 'Phase 2.4 disposable income',
            'p_payment_method': 'cash',
          },
        );
        await outbox.enqueue(income);
        final sync = PushSyncService(
          outbox: outbox,
          transport: SupabasePushSyncTransport(client),
        );

        expect((await sync.pushForAuthenticatedUser()).completed, 1);
        expect(
          (await database.outboxCommandById(income.id))?.status,
          'completed',
        );
        await _expectBalance(client, accountId, 125);

        final expense = OutboxCommandEnvelope.createExpense(
          userId: userId,
          dataGeneration: generation,
          payload: {
            'p_account': accountId,
            'p_category': expenseCategoryId,
            'p_subcategory': null,
            'p_amount': 10,
            'p_date': day,
            'p_description': 'Phase 2.4 disposable expense',
            'p_payment_method': 'cash',
          },
        );
        await outbox.enqueue(expense);
        expect((await sync.pushForAuthenticatedUser()).completed, 1);
        expect(
          (await database.outboxCommandById(expense.id))?.status,
          'completed',
        );
        await _expectBalance(client, accountId, 115);

        final replay = PushSyncRpcMapper.map(
          (await database.outboxCommandById(income.id))!,
        );
        await SupabasePushSyncTransport(client)
            .invokeRpc(replay.name, replay.parameters);
        await _expectBalance(client, accountId, 115);

        final insufficient = OutboxCommandEnvelope.createExpense(
          userId: userId,
          dataGeneration: generation,
          payload: {
            'p_account': accountId,
            'p_category': expenseCategoryId,
            'p_subcategory': null,
            'p_amount': 1000,
            'p_date': day,
            'p_description': 'Phase 2.4 insufficient funds',
            'p_payment_method': 'cash',
          },
        );
        await outbox.enqueue(insufficient);
        expect((await sync.pushForAuthenticatedUser()).failed, 1);
        final failed = await database.outboxCommandById(insufficient.id);
        expect(failed?.status, 'failed');
        expect(failed?.lastErrorCode, 'INSUFFICIENT_FUNDS');
        await _expectBalance(client, accountId, 115);

        await _tombstoneTransaction(client, income.id, generation);
        await _tombstoneTransaction(client, expense.id, generation);
        await _expectBalance(client, accountId, 100);
        await client
            .from('accounts')
            .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
            .eq('id', accountId);
        await _tombstoneCategories(client, userId);
      } finally {
        await database.close();
        await client.auth.signOut();
      }
    },
    skip: !configured,
  );
}

Future<void> _expectBalance(
  SupabaseClient client,
  String accountId,
  num expected,
) async {
  final account = await client
      .from('accounts')
      .select('balance')
      .eq('id', accountId)
      .single();
  expect((account['balance'] as num).toDouble(), expected.toDouble());
}

Future<void> _tombstoneTransaction(
  SupabaseClient client,
  String transactionId,
  int generation,
) async {
  final transaction = await client
      .from('transactions')
      .select('version')
      .eq('id', transactionId)
      .single();
  await client.rpc(
    'delete_transaction',
    params: {
      'p_id': transactionId,
      'p_base_version': transaction['version'],
      'p_generation': generation,
    },
  );
}

Future<void> _cleanupExistingFixtures(
  SupabaseClient client,
  String userId,
  int generation,
) async {
  final accounts = await client
      .from('accounts')
      .select('id')
      .eq('user_id', userId)
      .eq('name', 'Phase 2.4 disposable account')
      .isFilter('deleted_at', null);
  for (final account in accounts) {
    final transactions = await client
        .from('transactions')
        .select('id,version')
        .eq('account_id', account['id'])
        .isFilter('deleted_at', null);
    for (final transaction in transactions) {
      await client.rpc(
        'delete_transaction',
        params: {
          'p_id': transaction['id'],
          'p_base_version': transaction['version'],
          'p_generation': generation,
        },
      );
    }
    await client
        .from('accounts')
        .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
        .eq('id', account['id']);
  }
}

Future<void> _tombstoneCategories(SupabaseClient client, String userId) =>
    client
        .from('categories')
        .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
        .eq('user_id', userId)
        .like('name', 'Phase 2.4 disposable%');
