import 'dart:convert';

import 'package:supabase_flutter/supabase_flutter.dart';

import '../database/app_database.dart';
import 'outbox_store.dart';

/// A normal authenticated-client boundary. It deliberately exposes no admin
/// credentials or database access.
abstract interface class PushSyncTransport {
  String? get authenticatedUserId;

  Future<void> invokeRpc(String name, Map<String, dynamic> parameters);
}

class SupabasePushSyncTransport implements PushSyncTransport {
  SupabasePushSyncTransport(this._client);

  final SupabaseClient _client;

  @override
  String? get authenticatedUserId => _client.auth.currentSession?.user.id;

  @override
  Future<void> invokeRpc(String name, Map<String, dynamic> parameters) async {
    try {
      await _client.rpc(name, params: parameters);
    } on AuthException catch (error) {
      throw PushSyncRpcException(
        code: 'UNAUTHENTICATED',
        safeMessage: error.message,
      );
    } on PostgrestException catch (error) {
      throw PushSyncRpcException(
        code: PushSyncRpcException.stableCodeFrom(error.message),
        safeMessage: error.message,
      );
    }
  }
}

class PushSyncRpcException implements Exception {
  const PushSyncRpcException({this.code, this.safeMessage});

  static const stableCodes = {
    'VALIDATION_ERROR',
    'FORBIDDEN_REFERENCE',
    'NOT_FOUND',
    'CONFLICT',
    'IDEMPOTENCY_MISMATCH',
    'DATA_GENERATION_MISMATCH',
    'BUDGET_SCOPE_OVERLAP',
    'RECURRING_REVIEW_REQUIRED',
    'INVALID_STATE',
    'INSUFFICIENT_FUNDS',
    'UNAUTHENTICATED',
  };

  final String? code;
  final String? safeMessage;

  static String? stableCodeFrom(String message) {
    for (final code in stableCodes) {
      if (message.contains(code)) return code;
    }
    return null;
  }
}

class PushSyncRpcCall {
  const PushSyncRpcCall({required this.name, required this.parameters});

  final String name;
  final Map<String, dynamic> parameters;
}

class PushSyncRpcMapper {
  const PushSyncRpcMapper._();

  static PushSyncRpcCall map(OutboxCommand command) {
    final payload = _payload(command);
    return switch (command.commandType) {
      'create_income' => PushSyncRpcCall(
        name: 'create_income',
        parameters: {
          'p_id': command.id,
          'p_account': _required(payload, 'p_account'),
          'p_category': _required(payload, 'p_category'),
          'p_subcategory': payload['p_subcategory'],
          'p_amount': _required(payload, 'p_amount'),
          'p_date': _required(payload, 'p_date'),
          'p_description': _required(payload, 'p_description'),
          'p_payment_method': _required(payload, 'p_payment_method'),
          'p_client_request_id': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'create_expense' => PushSyncRpcCall(
        name: 'create_expense',
        parameters: {
          'p_id': command.id,
          'p_account': _required(payload, 'p_account'),
          'p_category': _required(payload, 'p_category'),
          'p_subcategory': payload['p_subcategory'],
          'p_amount': _required(payload, 'p_amount'),
          'p_date': _required(payload, 'p_date'),
          'p_description': _required(payload, 'p_description'),
          'p_payment_method': _required(payload, 'p_payment_method'),
          'p_client_request_id': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'create_transfer' => PushSyncRpcCall(
        name: 'create_transfer',
        parameters: {
          'p_id': command.id,
          'p_from': _required(payload, 'p_from'),
          'p_to': _required(payload, 'p_to'),
          'p_amount': _required(payload, 'p_amount'),
          'p_fee': _required(payload, 'p_fee'),
          'p_fee_category': payload['p_fee_category'],
          'p_date': _required(payload, 'p_date'),
          'p_description': _required(payload, 'p_description'),
          'p_request': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      _ => throw const FormatException('Unsupported outbox command type.'),
    };
  }

  static Map<String, dynamic> _payload(OutboxCommand command) {
    final decoded = jsonDecode(command.payloadJson);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('Outbox payload must be a JSON object.');
    }
    return decoded;
  }

  static dynamic _required(Map<String, dynamic> payload, String key) {
    if (!payload.containsKey(key) || payload[key] == null) {
      throw FormatException('Outbox payload is missing $key.');
    }
    return payload[key];
  }
}

class PushSyncResult {
  const PushSyncResult({
    this.completed = 0,
    this.retried = 0,
    this.failed = 0,
    this.recovered = 0,
    this.unauthenticated = false,
    this.skippedConcurrentRun = false,
  });

  final int completed;
  final int retried;
  final int failed;
  final int recovered;
  final bool unauthenticated;
  final bool skippedConcurrentRun;
}

class PushSyncService {
  PushSyncService({
    required this.outbox,
    required this.transport,
    this.maxBatchSize = 20,
    this.staleProcessingAfter = const Duration(minutes: 5),
    DateTime Function()? clock,
  }) : _clock = clock ?? (() => DateTime.now().toUtc());

  final OutboxStore outbox;
  final PushSyncTransport transport;
  final int maxBatchSize;
  final Duration staleProcessingAfter;
  final DateTime Function() _clock;
  static bool _isPushing = false;

  Future<PushSyncResult> pushForAuthenticatedUser() async {
    if (_isPushing) return const PushSyncResult(skippedConcurrentRun: true);

    final userId = transport.authenticatedUserId;
    if (userId == null || userId.isEmpty) {
      return const PushSyncResult(unauthenticated: true);
    }

    _isPushing = true;
    try {
      final now = _clock();
      final recovered = await outbox.recoverStaleProcessing(
        userId: userId,
        staleBefore: now.subtract(staleProcessingAfter),
        now: now,
      );
      final due = (await outbox.dueCommandsForUser(
        userId,
        now: now,
      )).take(maxBatchSize).toList(growable: false);

      var completed = 0;
      var retried = 0;
      var failed = 0;
      for (final command in due) {
        try {
          final processing = await outbox.markProcessing(
            userId: userId,
            commandId: command.id,
            now: _clock(),
          );
          final call = PushSyncRpcMapper.map(processing);
          await transport.invokeRpc(call.name, call.parameters);
          await outbox.markCompleted(
            userId: userId,
            commandId: command.id,
            now: _clock(),
          );
          completed++;
        } catch (error) {
          final failure = _failureFrom(error);
          if (OutboxErrorClassifier.classify(failure.code) ==
              OutboxErrorDisposition.permanent) {
            await outbox.markPermanentFailure(
              userId: userId,
              commandId: command.id,
              now: _clock(),
              errorCode: failure.code ?? 'VALIDATION_ERROR',
              errorMessage: failure.safeMessage,
            );
            failed++;
          } else {
            await outbox.markRetryableFailure(
              userId: userId,
              commandId: command.id,
              now: _clock(),
              errorCode: failure.code,
              errorMessage: failure.safeMessage,
            );
            retried++;
          }
        }
      }
      return PushSyncResult(
        completed: completed,
        retried: retried,
        failed: failed,
        recovered: recovered,
      );
    } finally {
      _isPushing = false;
    }
  }

  PushSyncRpcException _failureFrom(Object error) {
    if (error is PushSyncRpcException) return error;
    if (error is FormatException) {
      return PushSyncRpcException(
        code: 'VALIDATION_ERROR',
        safeMessage: error.message,
      );
    }
    return const PushSyncRpcException(safeMessage: 'RPC request failed.');
  }
}
