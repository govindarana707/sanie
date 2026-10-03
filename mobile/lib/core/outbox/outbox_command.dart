import 'dart:collection';
import 'dart:convert';

import 'package:uuid/uuid.dart';

enum OutboxCommandType {
  createIncome('create_income'),
  createExpense('create_expense'),
  createTransfer('create_transfer'),
  updateTransaction('update_transaction'),
  deleteTransaction('delete_transaction'),
  createAccount('create_account'),
  updateAccount('update_account'),
  archiveAccount('archive_account'),
  updateAccountSettings('update_account_settings'),
  createCategory('create_category'),
  updateCategory('update_category'),
  archiveCategory('archive_category'),
  createSubcategory('create_subcategory'),
  updateSubcategory('update_subcategory'),
  archiveSubcategory('archive_subcategory');

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

  factory OutboxCommandEnvelope.transactionMutation({
    required OutboxCommandType type,
    required String userId,
    required String transactionId,
    required int baseVersion,
    required int dataGeneration,
    required Map<String, dynamic> payload,
    DateTime? createdAt,
  }) {
    if (type != OutboxCommandType.updateTransaction &&
        type != OutboxCommandType.deleteTransaction) {
      throw ArgumentError.value(type, 'type');
    }
    return OutboxCommandEnvelope._new(
      type: type,
      userId: userId,
      payload: {'p_id': transactionId, ...payload},
      expectedVersion: baseVersion,
      dataGeneration: dataGeneration,
      createdAt: createdAt,
    );
  }

  factory OutboxCommandEnvelope.createAccount({
    required String userId,
    required String name,
    required String accountType,
    required double openingBalance,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.createAccount,
    userId: userId,
    payload: {
      'p_name': name,
      'p_type': accountType,
      'p_opening': openingBalance,
    },
    dataGeneration: dataGeneration,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.updateAccount({
    required String userId,
    required String accountId,
    required String name,
    required String accountType,
    required int baseVersion,
    required int dataGeneration,
    String? accountNumber,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.updateAccount,
    userId: userId,
    payload: {
      'p_id': accountId,
      'p_name': name,
      'p_type': accountType,
      'p_account_number': accountNumber,
    },
    dataGeneration: dataGeneration,
    expectedVersion: baseVersion,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.archiveAccount({
    required String userId,
    required String accountId,
    required int baseVersion,
    required int dataGeneration,
    String? id,
    String? clientRequestId,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.archiveAccount,
    userId: userId,
    payload: {'p_id': accountId},
    dataGeneration: dataGeneration,
    expectedVersion: baseVersion,
    id: id,
    clientRequestId: clientRequestId,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.accountSettings({
    required String userId,
    required String accountId,
    required bool isDefault,
    required bool includeInNetBalance,
    required bool includeInSavings,
    required bool isActive,
    required int baseVersion,
    required int dataGeneration,
    required List<Map<String, dynamic>> localBefore,
    DateTime? createdAt,
  }) => OutboxCommandEnvelope._new(
    type: OutboxCommandType.updateAccountSettings,
    userId: userId,
    payload: {
      'p_id': accountId,
      'p_is_default': isDefault,
      'p_include_in_net_balance': includeInNetBalance,
      'p_include_in_savings': includeInSavings,
      'p_is_active': isActive,
      'local_before': localBefore,
    },
    expectedVersion: baseVersion,
    dataGeneration: dataGeneration,
    createdAt: createdAt,
  );

  factory OutboxCommandEnvelope.categoryMutation({
    required OutboxCommandType type,
    required String userId,
    required String entityId,
    required int dataGeneration,
    String? categoryId,
    String? name,
    String? categoryType,
    String? icon,
    String? description,
    int? baseVersion,
    String? clientRequestId,
    DateTime? createdAt,
  }) {
    if (!const {
      OutboxCommandType.createCategory,
      OutboxCommandType.updateCategory,
      OutboxCommandType.archiveCategory,
      OutboxCommandType.createSubcategory,
      OutboxCommandType.updateSubcategory,
      OutboxCommandType.archiveSubcategory,
    }.contains(type)) {
      throw ArgumentError.value(type, 'type');
    }
    return OutboxCommandEnvelope._new(
      type: type,
      userId: userId,
      payload: {
        'p_id': entityId,
        if (type.name.endsWith('Subcategory')) 'p_category': categoryId,
        if (!type.name.startsWith('archive')) 'p_name': name,
        if (type == OutboxCommandType.createCategory ||
            type == OutboxCommandType.updateCategory)
          'p_type': categoryType,
        if (!type.name.startsWith('archive')) 'p_icon': icon,
        if (!type.name.startsWith('archive')) 'p_description': description,
      },
      expectedVersion: baseVersion,
      dataGeneration: dataGeneration,
      id:
          type == OutboxCommandType.createCategory ||
              type == OutboxCommandType.createSubcategory
          ? entityId
          : null,
      clientRequestId: clientRequestId,
      createdAt: createdAt,
    );
  }

  factory OutboxCommandEnvelope._new({
    required OutboxCommandType type,
    required String userId,
    required Map<String, dynamic> payload,
    required int dataGeneration,
    int? expectedVersion,
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
      expectedVersion: expectedVersion,
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
