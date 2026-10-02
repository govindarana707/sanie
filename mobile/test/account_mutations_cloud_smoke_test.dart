import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/database/app_database.dart';
import 'package:sanie/core/outbox/account_mutations.dart';
import 'package:sanie/core/outbox/outbox_store.dart';
import 'package:sanie/core/outbox/push_sync.dart';
import 'package:sanie/core/sync/pull_sync.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

void main() {
  const url = String.fromEnvironment('SANIE_SMOKE_URL');
  const key = String.fromEnvironment('SANIE_SMOKE_PUBLISHABLE_KEY');
  final email = Platform.environment['SANIE_SMOKE_EMAIL'];
  final password = Platform.environment['SANIE_SMOKE_PASSWORD'];
  final configured =
      url.isNotEmpty && key.isNotEmpty && email != null && password != null;

  test(
    'normal-auth account create, edit, archive, replay and pull converge',
    () async {
      final client = SupabaseClient(url, key);
      final database = AppDatabase.forTesting(NativeDatabase.memory());
      final outbox = OutboxStore(database);
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
        await database.upsertSyncState(
          SyncStatesCompanion.insert(
            userId: userId,
            dataGeneration: Value(generation),
          ),
        );
        final mutations = AccountMutationService(
          database: database,
          outbox: outbox,
          authenticatedUserId: () => client.auth.currentSession?.user.id,
        );
        final push = PushSyncService(
          outbox: outbox,
          transport: SupabasePushSyncTransport(client),
        );
        final pull = PullSyncService(
          database: database,
          transport: SupabasePullSyncTransport(client),
        );

        final accountId = await mutations.createAccount(
          name: 'Phase 3.1A disposable',
          accountType: 'cash',
          openingBalance: 125.50,
        );
        final createCommand = (await database.outboxCommandById(accountId))!;
        expect((await push.pushForAuthenticatedUser()).completed, 1);
        expect(
          (await database.outboxCommandById(accountId))?.status,
          'completed',
        );
        expect((await pull.pullForAuthenticatedUser()).changes, greaterThan(0));
        expect((await database.accountById(accountId))?.balance, 125.50);
        final created = await client
            .from('accounts')
            .select()
            .eq('id', accountId)
            .single();
        expect((created['balance'] as num).toDouble(), 125.50);
        expect(created['user_id'], userId);

        final editId = await mutations.updateAccount(
          accountId: accountId,
          name: 'Phase 3.1A edited',
          accountType: 'wallet',
          accountNumber: 'demo-only',
        );
        final editCommand = (await database.outboxCommandById(editId))!;
        expect((await push.pushForAuthenticatedUser()).completed, 1);
        expect((await pull.pullForAuthenticatedUser()).changes, greaterThan(0));
        expect(
          (await database.accountById(accountId))?.name,
          'Phase 3.1A edited',
        );
        final edited = await client
            .from('accounts')
            .select()
            .eq('id', accountId)
            .single();
        expect((edited['balance'] as num).toDouble(), 125.50);
        expect(edited['account_type'], 'wallet');

        final archiveId = await mutations.archiveAccount(accountId);
        final archiveCommand = (await database.outboxCommandById(archiveId))!;
        expect((await push.pushForAuthenticatedUser()).completed, 1);
        expect((await pull.pullForAuthenticatedUser()).changes, greaterThan(0));
        expect((await database.accountById(accountId))?.deletedAt, isNotNull);
        expect(await database.accountsForUser(userId), isEmpty);
        final archived = await client
            .from('accounts')
            .select()
            .eq('id', accountId)
            .single();
        expect(archived['deleted_at'], isNotNull);
        expect((archived['balance'] as num).toDouble(), 125.50);

        final feedBefore = await client.rpc(
          'pull_changes',
          params: {
            'p_after_cursor': 0,
            'p_limit': 500,
            'p_generation': generation,
          },
        ) as List<dynamic>;
        final replayCreate = await client.rpc(
          'create_account',
          params: {
            'p_id': accountId,
            'p_name': 'Phase 3.1A disposable',
            'p_type': 'cash',
            'p_opening': 125.50,
            'p_request': createCommand.clientRequestId,
            'p_generation': generation,
          },
        ) as Map<String, dynamic>;
        final replayEdit = await client.rpc(
          'update_account',
          params: {
            'p_id': accountId,
            'p_name': 'Phase 3.1A edited',
            'p_type': 'wallet',
            'p_account_number': 'demo-only',
            'p_base_version': editCommand.expectedVersion,
            'p_request': editCommand.clientRequestId,
            'p_generation': generation,
          },
        ) as Map<String, dynamic>;
        final replayArchive = await client.rpc(
          'archive_account',
          params: {
            'p_id': accountId,
            'p_base_version': archiveCommand.expectedVersion,
            'p_request': archiveCommand.clientRequestId,
            'p_generation': generation,
          },
        ) as Map<String, dynamic>;
        expect(replayCreate['replayed'], isTrue);
        expect(replayEdit['replayed'], isTrue);
        expect(replayArchive['replayed'], isTrue);
        final feedAfter = await client.rpc(
          'pull_changes',
          params: {
            'p_after_cursor': 0,
            'p_limit': 500,
            'p_generation': generation,
          },
        ) as List<dynamic>;
        expect(feedAfter.length, feedBefore.length);
        expect(
          (await client
              .from('accounts')
              .select('version')
              .eq('id', accountId)
              .single())['version'],
          archived['version'],
        );
      } finally {
        await database.close();
        await client.auth.signOut();
      }
    },
    skip: !configured,
  );
}
