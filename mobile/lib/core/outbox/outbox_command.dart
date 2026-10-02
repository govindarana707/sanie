import 'dart:collection';
import 'dart:convert';

import 'package:uuid/uuid.dart';

enum OutboxCommandType {
  createIncome('create_income'),
  createExpense('create_expense'),
  createTransfer('create_transfer');

  const OutboxCommandType(this.rpcName);

  final String rpcName;
}

enum OutboxStatus {
  pending('pending'),
  processing('processing'),
  retry('retry'),
  failed('failed'),
  completed('completed');

  const OutboxStatus(this.storageValue);

  final String storageValue;
}

class OutboxCommandEnvelope {
  OutboxCommandEnvelope._({
    required this.id,
    required this.userId,
    required this.clientRequestId,
    required this.type,
    required Map<String, dynamic> payload,
    required this.dataGeneration,
    required this.expectedVersion,
    required this.createdAt,
  }) : payload = UnmodifiableMapView(Map<String, dynamic>.from(payload));

  factory OutboxCommandEnvelope.createIncome({
    required String userId,
    required Map<String, dynamic> payload,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.createIncome,
    userId: userId,
    payload: payload,
    dataGeneration: dataGeneration,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.createExpense({
    required String userId,
    required Map<String, dynamic> payload,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.createExpense,
    userId: userId,
    payload: payload,
    dataGeneration: dataGeneration,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.createTransfer({
    required String userId,
    required Map<String, dynamic> payload,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.createTransfer,
    userId: userId,
    payload: payload,
    dataGeneration: dataGeneration,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope._new({
    required OutboxCommandType type,
    required String userId,
    required Map<String, dynamic> payload,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) {
    const uuid = Uuid();
    return OutboxCommandEnvelope._(
      id: id ?? uuid.v4(),
      userId: userId,
      clientRequestId: clientRequestId ?? uuid.v4(),
      type: type,
      payload: payload,
      dataGeneration: dataGeneration,
      expectedVersion: null,
      createdAt: createdAt ?? DateTime.now().toUtc(),
    );
  }

  final String id;
  final String userId;
  final String clientRequestId;
  final OutboxCommandType type;
  final Map<String, dynamic> payload;
  final int dataGeneration;
  final int? expectedVersion;
  final DateTime createdAt;

  String get payloadJson => jsonEncode(payload);
}
