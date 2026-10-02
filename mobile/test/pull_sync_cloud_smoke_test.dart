import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/sync/pull_sync.dart';
import 'package:supabase_flutter/supabase_flutter.dart';
import 'package:uuid/uuid.dart';

void main() {
  const url = String.fromEnvironment('SANIE_SMOKE_URL');
  const key = String.fromEnvironment('SANIE_SMOKE_PUBLISHABLE_KEY');
  final email = Platform.environment['SANIE_SMOKE_EMAIL'];
  final password = Platform.environment['SANIE_SMOKE_PASSWORD'];
  final configured =
      url.isNotEmpty && key.isNotEmpty && email != null && password != null;

  test('authenticated change feed pull applies update and tombstone', () async {
    final client = SupabaseClient(url, key);
    final database = AppDatabase.forTesting(NativeDatabase.memory());
    const uuid = Uuid();
    final accountId = uuid.v4();
    final categoryId = uuid.v4();
    final transactionId = uuid.v4();
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
        'name': 'Phase 2.5 disposable',
        'account_type': 'cash',
        'opening_balance': 100,
        'currency': 'NPR',
      });
      await client.from('categories').insert({
        'id': categoryId,
        'user_id': userId,
        'name': 'Phase 2.5 disposable',
        'category_type': 'income',
      });
      await client.rpc(
        'create_income',
        params: {
          'p_id': transactionId,
          'p_account': accountId,
          'p_category': categoryId,
          'p_subcategory': null,
          'p_amount': 25,
          'p_date': day,
          'p_description': 'Phase 2.5',
          'p_payment_method': 'cash',
          'p_client_request_id': uuid.v4(),
          'p_generation': generation,
        },
      );
      final pull = PullSyncService(
        database: database,
        transport: SupabasePullSyncTransport(client),
      );
      expect((await pull.pullForAuthenticatedUser()).changes, greaterThan(0));
      expect((await database.transactionById(transactionId))?.amount, 25);
      final remote = await client
          .from('transactions')
          .select('version')
          .eq('id', transactionId)
          .single();
      await client.rpc(
        'update_transaction',
        params: {
          'p_id': transactionId,
          'p_base_version': remote['version'],
          'p_amount': 30,
          'p_date': day,
          'p_description': 'Phase 2.5 updated',
          'p_generation': generation,
        },
      );
      await pull.pullForAuthenticatedUser();
      expect((await database.transactionById(transactionId))?.amount, 30);
      final updated = await client
          .from('transactions')
          .select('version')
          .eq('id', transactionId)
          .single();
      await client.rpc(
        'delete_transaction',
        params: {
          'p_id': transactionId,
          'p_base_version': updated['version'],
          'p_generation': generation,
        },
      );
      await pull.pullForAuthenticatedUser();
      expect(
        (await database.transactionById(transactionId))?.deletedAt,
        isNotNull,
      );
      await client
          .from('accounts')
          .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
          .eq('id', accountId);
      await client
          .from('categories')
          .update({'deleted_at': DateTime.now().toUtc().toIso8601String()})
          .eq('id', categoryId);
    } finally {
      await database.close();
      await client.auth.signOut();
    }
  }, skip: !configured);
}
