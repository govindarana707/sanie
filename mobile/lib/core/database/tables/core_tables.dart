import 'package:drift/drift.dart';

class Profiles extends Table {
  TextColumn get id => text()();
  TextColumn get firstName => text()();
  TextColumn get lastName => text().nullable()();
  TextColumn get phone => text().nullable()();
  TextColumn get avatarPath => text().nullable()();
  TextColumn get currency => text().withDefault(const Constant('NPR'))();
  TextColumn get language => text().withDefault(const Constant('en'))();
  TextColumn get theme => text().withDefault(const Constant('light'))();
  TextColumn get notificationPreferences =>
      text().withDefault(const Constant('{}'))();
  TextColumn get settings => text().withDefault(const Constant('{}'))();
  IntColumn get dataGeneration => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Accounts extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get name => text()();
  TextColumn get accountType => text()();
  TextColumn get accountNumber => text().nullable()();
  RealColumn get openingBalance => real().withDefault(const Constant(0))();
  RealColumn get balance => real().withDefault(const Constant(0))();
  TextColumn get currency => text().withDefault(const Constant('NPR'))();
  TextColumn get color => text().nullable()();
  TextColumn get icon => text().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  BoolColumn get isDefault => boolean().withDefault(const Constant(false))();
  BoolColumn get includeInSavings =>
      boolean().withDefault(const Constant(false))();
  BoolColumn get includeInNetBalance =>
      boolean().withDefault(const Constant(true))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Categories extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text().nullable()();
  TextColumn get name => text()();
  TextColumn get categoryType => text()();
  TextColumn get icon => text().nullable()();
  TextColumn get color => text().nullable()();
  TextColumn get description => text().nullable()();
  BoolColumn get isSystem => boolean().withDefault(const Constant(false))();
  TextColumn get status => text().withDefault(const Constant('active'))();
  BoolColumn get isPinned => boolean().withDefault(const Constant(false))();
  IntColumn get sortOrder => integer().withDefault(const Constant(999))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Subcategories extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get categoryId => text()();
  TextColumn get name => text()();
  TextColumn get icon => text().nullable()();
  TextColumn get description => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('active'))();
  IntColumn get sortOrder => integer().withDefault(const Constant(0))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Goals extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get name => text()();
  RealColumn get targetAmount => real()();
  RealColumn get initialAmount => real().withDefault(const Constant(0))();
  RealColumn get currentAmount => real().withDefault(const Constant(0))();
  TextColumn get deadline => text().nullable()();
  TextColumn get icon => text().nullable()();
  TextColumn get color => text().nullable()();
  TextColumn get description => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('active'))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class People extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get name => text()();
  TextColumn get personType => text().withDefault(const Constant('person'))();
  TextColumn get phone => text().nullable()();
  TextColumn get email => text().nullable()();
  TextColumn get address => text().nullable()();
  TextColumn get photoPath => text().nullable()();
  TextColumn get notes => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('active'))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class RecurringTransactions extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get accountId => text()();
  TextColumn get categoryId => text()();
  TextColumn get subcategoryId => text().nullable()();
  RealColumn get amount => real()();
  TextColumn get transactionType => text()();
  TextColumn get frequency => text()();
  IntColumn get dayOfMonth => integer().nullable()();
  IntColumn get dayOfWeek => integer().nullable()();
  TextColumn get startDate => text()();
  TextColumn get endDate => text().nullable()();
  TextColumn get nextOccurrence => text().nullable()();
  TextColumn get description => text().nullable()();
  TextColumn get notes => text().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Transactions extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get accountId => text().nullable()();
  TextColumn get fromAccountId => text().nullable()();
  TextColumn get toAccountId => text().nullable()();
  TextColumn get categoryId => text().nullable()();
  TextColumn get subcategoryId => text().nullable()();
  RealColumn get amount => real()();
  TextColumn get transactionType => text()();
  TextColumn get paymentMethod => text().nullable()();
  TextColumn get karobarTransactionId => text().nullable()();
  TextColumn get clientRequestId => text().nullable()();
  TextColumn get transferParentId => text().nullable()();
  TextColumn get goalId => text().nullable()();
  TextColumn get recurringDefinitionId => text().nullable()();
  TextColumn get recurringOccurrenceDate => text().nullable()();
  IntColumn get version => integer().withDefault(const Constant(1))();
  TextColumn get transactionDate => text()();
  TextColumn get description => text().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class Budgets extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get categoryId => text().nullable()();
  TextColumn get subcategoryId => text().nullable()();
  TextColumn get name => text()();
  RealColumn get amount => real()();
  TextColumn get period => text().withDefault(const Constant('monthly'))();
  TextColumn get startDate => text()();
  TextColumn get endDate => text()();
  RealColumn get alertThreshold => real().withDefault(const Constant(80))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get version => integer().withDefault(const Constant(1))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class KarobarTransactions extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get personId => text()();
  TextColumn get transactionType => text()();
  RealColumn get amount => real()();
  TextColumn get accountId => text().nullable()();
  TextColumn get expenseTransactionId => text().nullable()();
  TextColumn get incomeTransactionId => text().nullable()();
  TextColumn get paymentMethod => text().nullable()();
  TextColumn get clientRequestId => text().nullable()();
  IntColumn get version => integer().withDefault(const Constant(1))();
  TextColumn get description => text().nullable()();
  TextColumn get transactionDate => text()();
  TextColumn get dueDate => text().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get deletedAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

class SyncStates extends Table {
  TextColumn get userId => text()();
  IntColumn get lastCursor => integer().withDefault(const Constant(0))();
  IntColumn get dataGeneration => integer().withDefault(const Constant(1))();
  DateTimeColumn get lastSuccessfulSyncAt => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {userId};
}

class OutboxCommands extends Table {
  TextColumn get id => text()();
  TextColumn get userId => text()();
  TextColumn get clientRequestId => text().unique()();
  TextColumn get commandType => text()();
  TextColumn get payloadJson => text()();
  IntColumn get dataGeneration => integer()();
  IntColumn get expectedVersion => integer().nullable()();
  TextColumn get status => text().withDefault(const Constant('pending'))();
  IntColumn get attemptCount => integer().withDefault(const Constant(0))();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();
  DateTimeColumn get lastAttemptAt => dateTime().nullable()();
  DateTimeColumn get nextAttemptAt => dateTime().nullable()();
  TextColumn get lastErrorCode => text().nullable()();
  TextColumn get lastErrorMessage => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {id};
}
