import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../features/accounts/accounts_repository.dart';
import '../database/app_database.dart';
import '../outbox/outbox_store.dart';
import '../outbox/push_sync.dart';
import 'pull_sync.dart';
import 'sync_orchestrator.dart';

final pullSyncTransportProvider = Provider<PullSyncTransport>((ref) {
  return SupabasePullSyncTransport(Supabase.instance.client);
});

final pushSyncTransportProvider = Provider<PushSyncTransport>((ref) {
  return SupabasePushSyncTransport(Supabase.instance.client);
});

final pullSyncServiceProvider = Provider<PullSyncService>((ref) {
  final db = ref.watch(accountsRepositoryProvider).database;
  final transport = ref.watch(pullSyncTransportProvider);
  return PullSyncService(
    database: db,
    transport: transport,
    systemTransport: transport is SupabasePullSyncTransport
        ? SupabaseSystemCategoryTransport(Supabase.instance.client)
        : null,
  );
});

final pushSyncServiceProvider = Provider<PushSyncService>((ref) {
  final db = ref.watch(accountsRepositoryProvider).database;
  final transport = ref.watch(pushSyncTransportProvider);
  return PushSyncService(outbox: OutboxStore(db), transport: transport);
});

final syncOrchestratorProvider = Provider<SyncOrchestrator>((ref) {
  return SyncOrchestrator(
    push: ref.watch(pushSyncServiceProvider),
    pull: ref.watch(pullSyncServiceProvider),
  );
});

final userSyncStateProvider = StreamProvider.family<SyncState?, String>((
  ref,
  userId,
) {
  final db = ref.watch(accountsRepositoryProvider).database;
  return db.watchSyncStateForUser(userId);
});

final syncInitializerProvider = FutureProvider.family<void, String>((
  ref,
  userId,
) async {
  final db = ref.watch(accountsRepositoryProvider).database;
  final existing = await db.syncStateForUser(userId);
  if (existing != null) return;

  final pullService = ref.watch(pullSyncServiceProvider);
  final result = await pullService.pullForAuthenticatedUser();

  if (result.unauthenticated) {
    throw StateError('Sign in to initialize sync state.');
  }

  final updated = await db.syncStateForUser(userId);
  if (updated == null) {
    if (result.generationMismatch) {
      throw StateError('Sync generation mismatch. Reconciliation required.');
    }
    throw StateError('Could not initialize sync state. Please try again.');
  }
});
