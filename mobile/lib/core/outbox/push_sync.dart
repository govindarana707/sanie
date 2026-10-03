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
      'update_transaction' => PushSyncRpcCall(
        name: 'update_transaction',
        parameters: {
          'p_id': _required(payload, 'p_id'),
          'p_base_version': _version(command),
          'p_account': _required(payload, 'p_account'),
          'p_category': _required(payload, 'p_category'),
          'p_subcategory': payload['p_subcategory'],
          'p_amount': _required(payload, 'p_amount'),
          'p_date': _required(payload, 'p_date'),
          'p_description': _required(payload, 'p_description'),
          'p_generation': command.dataGeneration,
        },
      ),
      'delete_transaction' => PushSyncRpcCall(
        name: 'delete_transaction',
        parameters: {
          'p_id': _required(payload, 'p_id'),
          'p_base_version': _version(command),
          'p_generation': command.dataGeneration,
        },
      ),
      'create_account' => PushSyncRpcCall(
        name: 'create_account',
        parameters: {
          'p_id': command.id,
          'p_name': _required(payload, 'p_name'),
          'p_type': _required(payload, 'p_type'),
          'p_opening': _required(payload, 'p_opening'),
          'p_request': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'update_account' => PushSyncRpcCall(
        name: 'update_account',
        parameters: {
          'p_id': _required(payload, 'p_id'),
          'p_name': _required(payload, 'p_name'),
          'p_type': _required(payload, 'p_type'),
          'p_account_number': payload['p_account_number'],
          'p_base_version': _version(command),
          'p_request': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'archive_account' => PushSyncRpcCall(
        name: 'archive_account',
        parameters: {
          'p_id': _required(payload, 'p_id'),
          'p_base_version': _version(command),
          'p_request': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'update_account_settings' => PushSyncRpcCall(
        name: 'update_account_settings',
        parameters: {
          'p_id': _required(payload, 'p_id'),
          'p_is_default': _required(payload, 'p_is_default'),
          'p_include_in_net_balance': _required(
            payload,
            'p_include_in_net_balance',
          ),
          'p_include_in_savings': _required(payload, 'p_include_in_savings'),
          'p_is_active': _required(payload, 'p_is_active'),
          'p_base_version': _version(command),
          'p_request': command.clientRequestId,
          'p_generation': command.dataGeneration,
        },
      ),
      'create_category' ||
      'update_category' ||
      'archive_category' ||
      'create_subcategory' ||
      'update_subcategory' ||
      'archive_subcategory' => _categoryCall(command, payload),
      _ => throw const FormatException('Unsupported outbox command type.'),
    };
  }

  static PushSyncRpcCall _categoryCall(
    OutboxCommand command,
    Map<String, dynamic> payload,
  ) {
    final name = command.commandType;
    final create = name.startsWith('create_');
    final archive = name.startsWith('archive_');
    final subcategory = name.endsWith('subcategory');
    return PushSyncRpcCall(
      name: name,
      parameters: {
        'p_id': _required(payload, 'p_id'),
        if (subcategory) 'p_category': _required(payload, 'p_category'),
        if (!archive) 'p_name': _required(payload, 'p_name'),
        if (!archive && !subcategory) 'p_type': _required(payload, 'p_type'),
        if (!archive) 'p_icon': payload['p_icon'],
        if (!archive) 'p_description': payload['p_description'],
        if (!create) 'p_base_version': _version(command),
        'p_request': command.clientRequestId,
        'p_generation': command.dataGeneration,
      },
    );
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

  static int _version(OutboxCommand command) =>
      command.expectedVersion ??
      (throw const FormatException(
        'Outbox command is missing a base version.',
      ));
}

class PushSyncResult {
  const PushSyncResult({
    this.completed = 0,
    this.retried = 0,
    this.failed = 0,
    this.recovered = 0,
    this.deferred = 0,
    this.unauthenticated = false,
    this.skippedConcurrentRun = false,
  });

  final int completed;
  final int retried;
  final int failed;
  final int recovered;
  final int deferred;
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
      var deferred = 0;
      for (final command in due) {
        try {
          final parentStatus = await _parentCreateStatus(command, userId);
          if (parentStatus == 'failed') {
            await outbox.markProcessing(
              userId: userId,
              commandId: command.id,
              now: _clock(),
            );
            await outbox.markPermanentFailure(
              userId: userId,
              commandId: command.id,
              now: _clock(),
              errorCode: 'INVALID_STATE',
              errorMessage: command.commandType == 'update_account_settings'
                  ? 'Account creation failed.'
                  : 'Parent category creation failed.',
            );
            failed++;
            continue;
          }
          if (parentStatus != null && parentStatus != 'completed') {
            deferred++;
            continue;
          }
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
        deferred: deferred,
      );
    } finally {
      _isPushing = false;
    }
  }

  Future<String?> _parentCreateStatus(
    OutboxCommand command,
    String userId,
  ) async {
    if (command.commandType == 'update_account_settings') {
      final payload = PushSyncRpcMapper._payload(command);
      final accountId = payload['p_id'];
      if (accountId is! String) return null;
      final parent = await outbox.commandForUser(userId, accountId);
      return parent?.commandType == 'create_account' ? parent!.status : null;
    }
    if (command.commandType != 'create_subcategory') return null;
    final payload = PushSyncRpcMapper._payload(command);
    final parentId = payload['p_category'];
    if (parentId is! String) return null;
    final parent = await outbox.commandForUser(userId, parentId);
    return parent?.commandType == 'create_category' ? parent!.status : null;
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
