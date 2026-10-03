import 'package:drift/drift.dart';

import '../database/app_database.dart';
import '../outbox/push_sync.dart';
import 'pull_sync.dart';

enum SyncNowStatus {
  success,
  partial,
  failed,
  unauthenticated,
  userMismatch,
  reconciliationRequired,
  busy,
}

class SyncNowResult {
  const SyncNowResult({
    required this.status,
    this.pushed = 0,
    this.pulled = 0,
    this.retrying = 0,
    this.failedCommands = 0,
  });
  final SyncNowStatus status;
  final int pushed;
  final int pulled;
  final int retrying;
  final int failedCommands;
}

class SyncOrchestrator {
  SyncOrchestrator({
    required this.push,
    required this.pull,
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());
  final PushSyncService push;
  final PullSyncService pull;
  final DateTime Function() _clock;
  static bool _running = false;

  Future<SyncNowResult> syncNow() async {
    if (_running) return const SyncNowResult(status: SyncNowStatus.busy);
    final pushUser = push.transport.authenticatedUserId;
    final pullUser = pull.transport.authenticatedUserId;
    if (pushUser == null ||
        pullUser == null ||
        pushUser.isEmpty ||
        pullUser.isEmpty) {
      return const SyncNowResult(status: SyncNowStatus.unauthenticated);
    }
    if (pushUser != pullUser) {
      return const SyncNowResult(status: SyncNowStatus.userMismatch);
    }
    _running = true;
    final priorState = await pull.database.syncStateForUser(pushUser);
    final priorSuccess = priorState?.lastSuccessfulSyncAt;
    try {
      final pushed = await push.pushForAuthenticatedUser();
      if (pushed.unauthenticated) {
        return const SyncNowResult(status: SyncNowStatus.unauthenticated);
      }
      if (!_sameUser(pushUser)) {
        await _restoreSuccess(pushUser, priorSuccess);
        return const SyncNowResult(status: SyncNowStatus.userMismatch);
      }
      try {
        final pulled = await pull.pullForAuthenticatedUser();
        if (pulled.unauthenticated) {
          return const SyncNowResult(status: SyncNowStatus.unauthenticated);
        }
        if (!_sameUser(pushUser)) {
          await _restoreSuccess(pushUser, priorSuccess);
          return const SyncNowResult(status: SyncNowStatus.userMismatch);
        }
        if (pulled.generationMismatch) {
          await _restoreSuccess(pushUser, priorSuccess);
          return SyncNowResult(
            status: SyncNowStatus.reconciliationRequired,
            pushed: pushed.completed,
            retrying: pushed.retried + pushed.deferred,
            failedCommands: pushed.failed,
          );
        }
        await push.outbox.reconcileTransactionMutations(pushUser);
        final status =
            pushed.retried > 0 || pushed.failed > 0 || pushed.deferred > 0
            ? SyncNowStatus.partial
            : SyncNowStatus.success;
        if (status == SyncNowStatus.success) {
          await _setSuccess(pushUser, _clock());
        } else {
          await _restoreSuccess(pushUser, priorSuccess);
        }
        return SyncNowResult(
          status: status,
          pushed: pushed.completed,
          pulled: pulled.changes,
          retrying: pushed.retried + pushed.deferred,
          failedCommands: pushed.failed,
        );
      } catch (_) {
        await _restoreSuccess(pushUser, priorSuccess);
        return SyncNowResult(
          status: SyncNowStatus.partial,
          pushed: pushed.completed,
          retrying: pushed.retried + pushed.deferred,
          failedCommands: pushed.failed,
        );
      }
    } finally {
      _running = false;
    }
  }

  bool _sameUser(String userId) =>
      push.transport.authenticatedUserId == userId &&
      pull.transport.authenticatedUserId == userId;

  Future<void> _setSuccess(String userId, DateTime? value) async {
    final state = await pull.database.syncStateForUser(userId);
    if (state == null) return;
    await pull.database.upsertSyncState(
      SyncStatesCompanion(
        userId: Value(userId),
        lastCursor: Value(state.lastCursor),
        dataGeneration: Value(state.dataGeneration),
        lastSuccessfulSyncAt: Value(value),
      ),
    );
  }

  Future<void> _restoreSuccess(String userId, DateTime? value) =>
      _setSuccess(userId, value);
}
