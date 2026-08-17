// Reusable, user-scoped IndexedDB storage for read-only offline snapshots.
(function initializeOfflineStorage(window) {
    'use strict';

    const DB_NAME = 'sanie_offline_db';
    const DB_VERSION = 4;
    const DASHBOARD_STORE = 'dashboard_cache';
    const TRANSACTIONS_STORE = 'transactions_cache';
    const METADATA_STORE = 'metadata';
    const REFERENCE_STORE = 'reference_cache';
    const PENDING_STORE = 'pending_actions';
    const OFFLINE_IDENTITY_KEY = 'sanie_offline_identity';
    const TRANSACTION_LIMIT = 50;
    const REFERENCE_ENTRY_LIMIT = 50;

    let databasePromise = null;

    function normalizeUserId(userId) {
        if (userId === undefined || userId === null || userId === '') return null;
        return String(userId);
    }

    function openOfflineDB() {
        if (!('indexedDB' in window)) return Promise.resolve(null);
        if (databasePromise) return databasePromise;

        databasePromise = new Promise(resolve => {
            let request;
            try {
                request = window.indexedDB.open(DB_NAME, DB_VERSION);
            } catch (error) {
                resolve(null);
                return;
            }

            request.onupgradeneeded = event => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(DASHBOARD_STORE)) {
                    db.createObjectStore(DASHBOARD_STORE, { keyPath: 'userId' });
                }
                if (!db.objectStoreNames.contains(TRANSACTIONS_STORE)) {
                    db.createObjectStore(TRANSACTIONS_STORE, { keyPath: 'userId' });
                }
                if (!db.objectStoreNames.contains(METADATA_STORE)) {
                    const metadata = db.createObjectStore(METADATA_STORE, { keyPath: 'key' });
                    metadata.createIndex('userId', 'userId', { unique: false });
                }
                if (!db.objectStoreNames.contains(REFERENCE_STORE)) {
                    const references = db.createObjectStore(REFERENCE_STORE, { keyPath: 'key' });
                    references.createIndex('userId', 'userId', { unique: false });
                }
                if (!db.objectStoreNames.contains(PENDING_STORE)) {
                    const pending = db.createObjectStore(PENDING_STORE, { keyPath: 'localId' });
                    pending.createIndex('userId', 'userId', { unique: false });
                    pending.createIndex('createdAt', 'createdAt', { unique: false });
                    pending.createIndex('userStatus', ['userId', 'status'], { unique: false });
                }
                if (event.oldVersion < 3 && db.objectStoreNames.contains(PENDING_STORE)) {
                    const pending = event.target.transaction.objectStore(PENDING_STORE);
                    const cursorRequest = pending.openCursor();
                    cursorRequest.onsuccess = () => {
                        const cursor = cursorRequest.result;
                        if (!cursor) return;
                        const record = cursor.value;
                        if (!record.clientRequestId) record.clientRequestId = generateClientRequestId();
                        if (!Object.prototype.hasOwnProperty.call(record, 'lastAttemptAt')) record.lastAttemptAt = null;
                        if (!Object.prototype.hasOwnProperty.call(record, 'nextAttemptAt')) record.nextAttemptAt = null;
                        cursor.update(record);
                        cursor.continue();
                    };
                }
            };
            request.onsuccess = () => {
                request.result.onversionchange = () => request.result.close();
                resolve(request.result);
            };
            request.onerror = () => {
                databasePromise = null;
                resolve(null);
            };
            request.onblocked = () => {
                databasePromise = null;
                resolve(null);
            };
        });

        return databasePromise;
    }

    async function runRequest(storeName, mode, operation) {
        const db = await openOfflineDB();
        if (!db) return null;

        return new Promise(resolve => {
            let transaction;
            let requestResult = true;
            let settled = false;
            const finish = value => {
                if (settled) return;
                settled = true;
                resolve(value);
            };
            try {
                transaction = db.transaction(storeName, mode);
                const request = operation(transaction.objectStore(storeName));
                request.onsuccess = () => { requestResult = request.result; };
                request.onerror = () => {};
                transaction.oncomplete = () => finish(
                    mode === 'readonly' ? (requestResult ?? null) : (requestResult ?? true)
                );
                transaction.onerror = () => finish(null);
                transaction.onabort = () => finish(null);
            } catch (error) {
                finish(null);
            }
        });
    }

    function sanitizeText(value, maxLength = 255) {
        return String(value ?? '').slice(0, maxLength);
    }

    function sanitizeColor(value, fallback = '#6366f1') {
        const color = String(value || '').trim();
        return /^#[0-9a-f]{6}$/i.test(color) ? color : fallback;
    }

    function sanitizeIcon(value, fallback = '') {
        const icon = String(value || '').trim().slice(0, 50);
        return /^[a-z0-9_-]+(?:\s+[a-z0-9_-]+)*$/i.test(icon) ? icon : fallback;
    }

    function select(source, fields) {
        const output = {};
        if (!source || typeof source !== 'object' || Array.isArray(source)) return output;
        fields.forEach(field => {
            if (source[field] !== undefined && source[field] !== null) output[field] = source[field];
        });
        return output;
    }

    function selectArray(value, limit, mapper) {
        if (!Array.isArray(value)) return [];
        return value.slice(0, limit).map(mapper).filter(Boolean);
    }

    function sanitizeTransaction(transaction) {
        if (!transaction || typeof transaction !== 'object') return null;
        if (!['income', 'expense', 'transfer'].includes(transaction.type)) return null;
        if (!transaction.date || !Number.isFinite(Number(transaction.amount))) return null;

        const sanitized = select(transaction, [
            'id', 'version', 'created_at', 'updated_at', 'date', 'type', 'amount', 'payment_method', 'description',
            'account_id', 'from_account_id', 'to_account_id', 'category_id', 'subcategory_id',
            'category_name', 'category_icon', 'category_color', 'subcategory_name',
            'account_name', 'from_account_name', 'to_account_name', 'karobar_transaction_id'
        ]);
        sanitized.description = sanitizeText(sanitized.description, 255);
        for (const field of ['category_name', 'subcategory_name', 'account_name', 'from_account_name', 'to_account_name']) {
            if (sanitized[field] !== undefined) sanitized[field] = sanitizeText(sanitized[field], 120);
        }
        if (sanitized.category_icon !== undefined) sanitized.category_icon = sanitizeIcon(sanitized.category_icon);
        if (sanitized.category_color !== undefined) sanitized.category_color = sanitizeColor(sanitized.category_color);
        return sanitized;
    }

    function sanitizeDashboard(data) {
        if (!data || typeof data !== 'object' || Array.isArray(data)) return null;
        if (!data.statistics || typeof data.statistics !== 'object') return null;

        const snapshot = select(data, [
            'total_balance', 'savings_balance', 'total_receivable', 'total_payable', 'net_worth'
        ]);
        snapshot.statistics = select(data.statistics, [
            'total_income', 'total_expense', 'balance', 'income_count', 'expense_count'
        ]);
        snapshot.recent_transactions = selectArray(data.recent_transactions, 5, sanitizeTransaction);
        const sanitizeBreakdown = item => ({
            ...select(item, ['total_amount', 'transaction_count']),
            category_name: sanitizeText(item?.category_name, 120),
            category_color: sanitizeColor(item?.category_color)
        });
        snapshot.expense_breakdown = selectArray(data.expense_breakdown, 25, sanitizeBreakdown);
        snapshot.income_breakdown = selectArray(data.income_breakdown, 25, sanitizeBreakdown);
        snapshot.monthly_data = selectArray(data.monthly_data, 24, item => select(item, [
            'month', 'income', 'expense'
        ]));
        snapshot.budget_progress = selectArray(data.budget_progress, 3, item => ({
            percentage: Number(item?.percentage) || 0,
            spent: Number(item?.spent) || 0,
            budget: { ...select(item?.budget, ['amount']), name: sanitizeText(item?.budget?.name, 120) }
        }));
        snapshot.goal_progress = selectArray(data.goal_progress, 3, item => ({
            percentage: Number(item?.percentage) || 0,
            goal: {
                ...select(item?.goal, ['current_amount', 'target_amount']),
                name: sanitizeText(item?.goal?.name, 120),
                icon: sanitizeIcon(item?.goal?.icon, 'bi-bullseye')
            }
        }));
        snapshot.financial_health_score = select(data.financial_health_score, ['score', 'status']);
        snapshot.accounts_overview = selectArray(data.accounts_overview, 25, account => ({
            ...select(account, [
                'id', 'type', 'is_default', 'calculated_balance', 'balance',
                'total_income', 'total_expense', 'last_transaction_date'
            ]),
            name: sanitizeText(account?.name, 120),
            color: sanitizeColor(account?.color)
        }));
        snapshot.period = select(data.period, ['start_date', 'end_date']);
        return snapshot;
    }

    async function saveDashboardSnapshot(userId, data) {
        const scope = normalizeUserId(userId);
        const sanitized = sanitizeDashboard(data);
        if (!scope || !sanitized) return false;
        const cachedAt = new Date().toISOString();
        const saved = await runRequest(DASHBOARD_STORE, 'readwrite', store => store.put({
            userId: scope,
            data: sanitized,
            cachedAt
        }));
        if (saved) await saveMetadata(scope, 'dashboard_cached_at', cachedAt);
        return Boolean(saved);
    }

    async function getDashboardSnapshot(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return null;
        return await runRequest(DASHBOARD_STORE, 'readonly', store => store.get(scope));
    }

    async function saveTransactionSnapshot(userId, transactions) {
        const scope = normalizeUserId(userId);
        if (!scope || !Array.isArray(transactions)) return false;
        const data = transactions.slice(0, TRANSACTION_LIMIT).map(sanitizeTransaction).filter(Boolean);
        if (transactions.length > 0 && data.length === 0) return false;
        const cachedAt = new Date().toISOString();
        const saved = await runRequest(TRANSACTIONS_STORE, 'readwrite', store => store.put({
            userId: scope,
            data,
            cachedAt,
            limit: TRANSACTION_LIMIT
        }));
        if (saved) await saveMetadata(scope, 'transactions_cached_at', cachedAt);
        return Boolean(saved);
    }

    async function getTransactionSnapshot(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return null;
        return await runRequest(TRANSACTIONS_STORE, 'readonly', store => store.get(scope));
    }

    async function saveMetadata(userId, name, value) {
        const scope = normalizeUserId(userId);
        if (!scope || !name) return false;
        return Boolean(await runRequest(METADATA_STORE, 'readwrite', store => store.put({
            key: `${scope}:${name}`,
            userId: scope,
            name,
            value
        })));
    }

    async function getMetadata(userId, name) {
        const scope = normalizeUserId(userId);
        if (!scope || !name) return null;
        const record = await runRequest(METADATA_STORE, 'readonly', store => store.get(`${scope}:${name}`));
        return record?.value ?? null;
    }

    async function clearOfflineUserData(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return false;
        const dashboardDeleted = await runRequest(DASHBOARD_STORE, 'readwrite', store => store.delete(scope));
        const transactionsDeleted = await runRequest(TRANSACTIONS_STORE, 'readwrite', store => store.delete(scope));
        const metadataDeleted = await deleteMetadataForUser(scope);
        const referencesDeleted = await deleteRecordsByUser(REFERENCE_STORE, scope);
        return Boolean(dashboardDeleted || transactionsDeleted || metadataDeleted || referencesDeleted);
    }

    async function deleteMetadataForUser(userId) {
        return deleteRecordsByUser(METADATA_STORE, userId);
    }

    async function deleteRecordsByUser(storeName, userId) {
        const db = await openOfflineDB();
        if (!db) return false;
        return new Promise(resolve => {
            try {
                const transaction = db.transaction(storeName, 'readwrite');
                const index = transaction.objectStore(storeName).index('userId');
                const request = index.openCursor(window.IDBKeyRange.only(userId));
                request.onsuccess = () => {
                    const cursor = request.result;
                    if (cursor) {
                        cursor.delete();
                        cursor.continue();
                    }
                };
                transaction.oncomplete = () => resolve(true);
                transaction.onerror = () => resolve(false);
                transaction.onabort = () => resolve(false);
            } catch (error) {
                resolve(false);
            }
        });
    }

    function generateLocalId() {
        if (window.crypto?.randomUUID) return `local_${window.crypto.randomUUID()}`;
        const random = Math.random().toString(36).slice(2, 12);
        return `local_${Date.now()}_${random}`;
    }

    function generateClientRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        return `req_${Date.now()}_${Math.random().toString(36).slice(2, 18)}`;
    }

    function sanitizePendingTransactionPayload(payload) {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
        if (!['income', 'expense'].includes(payload.type)) return null;
        if (payload.payment_method && payload.payment_method !== 'cash') return null;

        const amount = Number(payload.amount);
        const accountId = Number(payload.account_id);
        const categoryId = Number(payload.category_id);
        const date = String(payload.date || '');
        const description = String(payload.description || '').trim();
        if (!Number.isFinite(amount) || amount <= 0 || amount > 999999999999.99) return null;
        if (!Number.isInteger(accountId) || accountId <= 0) return null;
        if (!Number.isInteger(categoryId) || categoryId <= 0) return null;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(new Date(`${date}T00:00:00`).getTime())) return null;
        if (description.length > 255) return null;

        const sanitized = {
            type: payload.type,
            amount,
            date,
            account_id: accountId,
            category_id: categoryId,
            subcategory_id: payload.subcategory_id ? Number(payload.subcategory_id) : null,
            payment_method: payload.payment_method || 'cash',
            description
        };
        if (sanitized.payment_method !== 'cash') return null;
        if (sanitized.subcategory_id !== null && (!Number.isInteger(sanitized.subcategory_id) || sanitized.subcategory_id <= 0)) return null;
        return sanitized;
    }

    function sanitizePendingDisplay(display) {
        const clean = value => sanitizeText(value, 120);
        return {
            category_name: clean(display?.category_name),
            category_icon: sanitizeIcon(display?.category_icon),
            category_color: sanitizeColor(display?.category_color, '#F59E0B'),
            subcategory_name: clean(display?.subcategory_name),
            account_name: clean(display?.account_name)
        };
    }

    async function addPendingAction(action) {
        const userId = normalizeUserId(action?.userId);
        const actionType = String(action?.action || '');
        if (!userId || action?.entityType !== 'transaction' || !['create', 'update', 'delete'].includes(actionType)) return null;
        const serverId = actionType === 'create' ? null : Number(action?.serverId);
        const baseVersion = actionType === 'create' ? null : Number(action?.baseVersion);
        const expectedEndpoint = actionType === 'create' ? '/transactions' : `/transactions/${serverId}`;
        const expectedMethod = actionType === 'create' ? 'POST' : (actionType === 'update' ? 'PUT' : 'DELETE');
        if (action?.endpoint !== expectedEndpoint || String(action?.method).toUpperCase() !== expectedMethod) return null;
        if (actionType !== 'create' && (!Number.isInteger(serverId) || serverId <= 0 || !Number.isInteger(baseVersion) || baseVersion < 1)) return null;
        const payload = actionType === 'delete' ? {} : sanitizePendingTransactionPayload(action.payload);
        if (actionType !== 'delete' && !payload) return null;
        const baseRecord = actionType === 'create' ? null : sanitizeTransaction(action.baseRecord);
        if (actionType !== 'create' && (!baseRecord || String(baseRecord.id) !== String(serverId))) return null;

        const now = new Date().toISOString();
        const record = {
            localId: generateLocalId(),
            clientRequestId: generateClientRequestId(),
            serverId,
            userId,
            entityType: 'transaction',
            action: actionType,
            endpoint: expectedEndpoint,
            method: expectedMethod,
            payload,
            display: sanitizePendingDisplay(action.display),
            baseVersion,
            baseRecord,
            createdAt: now,
            updatedAt: now,
            status: 'pending',
            retryCount: 0,
            lastAttemptAt: null,
            nextAttemptAt: null,
            lastError: null
        };
        const saved = await runRequest(PENDING_STORE, 'readwrite', store => store.add(record));
        if (!saved) return null;
        window.dispatchEvent(new CustomEvent('offline:pending-changed', { detail: { userId } }));
        return record;
    }

    async function getPendingActions(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return [];
        const records = await runRequest(PENDING_STORE, 'readonly', store => store.index('userId').getAll(scope));
        if (!Array.isArray(records)) return [];
        return records.sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)));
    }

    async function getPendingAction(localId) {
        if (!localId) return null;
        return await runRequest(PENDING_STORE, 'readonly', store => store.get(String(localId)));
    }

    async function updatePendingAction(localId, updates) {
        const existing = await getPendingAction(localId);
        if (!existing) return false;
        const allowedStatuses = ['pending', 'syncing', 'failed', 'conflict', 'synced'];
        const next = { ...existing, updatedAt: new Date().toISOString() };
        if (updates?.action === 'delete' && existing.action === 'update' && existing.serverId) {
            next.action = 'delete';
            next.method = 'DELETE';
            next.endpoint = `/transactions/${existing.serverId}`;
            next.payload = {};
        }
        if (updates?.status && allowedStatuses.includes(updates.status)) next.status = updates.status;
        if (Number.isInteger(updates?.retryCount) && updates.retryCount >= 0) next.retryCount = updates.retryCount;
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'clientRequestId') && updates.clientRequestId) {
            next.clientRequestId = String(updates.clientRequestId).slice(0, 64);
        }
        for (const field of ['lastAttemptAt', 'nextAttemptAt']) {
            if (updates && Object.prototype.hasOwnProperty.call(updates, field)) {
                next[field] = updates[field] ? String(updates[field]).slice(0, 40) : null;
            }
        }
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'lastError')) {
            next.lastError = updates.lastError ? String(updates.lastError).slice(0, 500) : null;
        }
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'serverId')) next.serverId = updates.serverId;
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'baseVersion')) {
            const baseVersion = Number(updates.baseVersion);
            if (Number.isInteger(baseVersion) && baseVersion > 0) next.baseVersion = baseVersion;
        }
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'payload')) {
            const payload = next.action === 'delete' ? {} : sanitizePendingTransactionPayload(updates.payload);
            if (!payload && next.action !== 'delete') return false;
            next.payload = payload;
        }
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'display')) next.display = sanitizePendingDisplay(updates.display);
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'baseRecord')) {
            const baseRecord = sanitizeTransaction(updates.baseRecord);
            if (baseRecord) next.baseRecord = baseRecord;
        }
        if (updates && Object.prototype.hasOwnProperty.call(updates, 'conflictData')) {
            next.conflictData = updates.conflictData ? sanitizeTransaction(updates.conflictData) : null;
        }
        const saved = await runRequest(PENDING_STORE, 'readwrite', store => store.put(next));
        if (saved) window.dispatchEvent(new CustomEvent('offline:pending-changed', { detail: { userId: existing.userId } }));
        return Boolean(saved);
    }

    async function deletePendingAction(localId) {
        const existing = await getPendingAction(localId);
        if (!existing) return false;
        const deleted = await runRequest(PENDING_STORE, 'readwrite', store => store.delete(String(localId)));
        if (deleted) window.dispatchEvent(new CustomEvent('offline:pending-changed', { detail: { userId: existing.userId } }));
        return Boolean(deleted);
    }

    async function countPendingActions(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return 0;
        const records = await getPendingActions(scope);
        return records.filter(record => ['pending', 'syncing', 'failed', 'conflict', 'synced'].includes(record.status)).length;
    }

    async function queueTransactionUpdate({ userId, transaction, payload, display }) {
        const scope = normalizeUserId(userId);
        if (!scope || !transaction) return null;
        const sanitizedPayload = sanitizePendingTransactionPayload(payload);
        if (!sanitizedPayload) return null;
        const actions = await getPendingActions(scope);

        if (transaction._pending && transaction.localId) {
            const existing = actions.find(action => action.localId === transaction.localId);
            if (!existing || !['create', 'update'].includes(existing.action)) return null;
            const saved = await updatePendingAction(existing.localId, {
                payload: sanitizedPayload,
                display,
                status: 'pending',
                retryCount: 0,
                nextAttemptAt: null,
                lastError: null,
                conflictData: null
            });
            return saved ? await getPendingAction(existing.localId) : null;
        }

        const serverId = Number(transaction.id);
        const baseVersion = Number(transaction.version);
        if (!Number.isInteger(serverId) || serverId <= 0 || !Number.isInteger(baseVersion) || baseVersion < 1) return null;
        const existing = actions.find(action => Number(action.serverId) === serverId);
        if (existing?.action === 'delete') return null;
        if (existing?.action === 'update') {
            const saved = await updatePendingAction(existing.localId, {
                payload: sanitizedPayload,
                display,
                status: 'pending',
                retryCount: 0,
                nextAttemptAt: null,
                lastError: null,
                conflictData: null
            });
            return saved ? await getPendingAction(existing.localId) : null;
        }
        return addPendingAction({
            userId: scope,
            entityType: 'transaction',
            action: 'update',
            endpoint: `/transactions/${serverId}`,
            method: 'PUT',
            serverId,
            baseVersion,
            baseRecord: transaction,
            payload: sanitizedPayload,
            display
        });
    }

    async function queueTransactionDelete({ userId, transaction, display }) {
        const scope = normalizeUserId(userId);
        if (!scope || !transaction) return null;
        const actions = await getPendingActions(scope);

        if (transaction._pending && transaction.localId) {
            const existing = actions.find(action => action.localId === transaction.localId);
            if (!existing) return null;
            if (existing.action === 'create') {
                const removed = await deletePendingAction(existing.localId);
                return removed ? { cancelledCreate: true, localId: existing.localId } : null;
            }
            if (existing.action === 'delete') return existing;
            if (existing.action === 'update') {
                const saved = await updatePendingAction(existing.localId, {
                    action: 'delete',
                    status: 'pending',
                    retryCount: 0,
                    nextAttemptAt: null,
                    lastError: null,
                    conflictData: null
                });
                return saved ? await getPendingAction(existing.localId) : null;
            }
        }

        const serverId = Number(transaction.id);
        const baseVersion = Number(transaction.version);
        if (!Number.isInteger(serverId) || serverId <= 0 || !Number.isInteger(baseVersion) || baseVersion < 1) return null;
        const existing = actions.find(action => Number(action.serverId) === serverId);
        if (existing?.action === 'delete') return existing;
        if (existing?.action === 'update') {
            const saved = await updatePendingAction(existing.localId, {
                action: 'delete', status: 'pending', retryCount: 0,
                nextAttemptAt: null, lastError: null, conflictData: null
            });
            return saved ? await getPendingAction(existing.localId) : null;
        }
        return addPendingAction({
            userId: scope,
            entityType: 'transaction',
            action: 'delete',
            endpoint: `/transactions/${serverId}`,
            method: 'DELETE',
            serverId,
            baseVersion,
            baseRecord: transaction,
            display
        });
    }

    async function resetStaleSyncingActions(userId, staleAfterMs = 120000) {
        const cutoff = Date.now() - staleAfterMs;
        const actions = await getPendingActions(userId);
        let resetCount = 0;
        for (const action of actions) {
            if (action.status !== 'syncing') continue;
            const attemptedAt = Date.parse(action.lastAttemptAt || action.updatedAt || action.createdAt);
            if (Number.isFinite(attemptedAt) && attemptedAt > cutoff) continue;
            if (await updatePendingAction(action.localId, { status: 'pending', nextAttemptAt: null })) resetCount++;
        }
        return resetCount;
    }

    async function applyPendingDashboardAdjustments(userId, dashboardData) {
        if (!dashboardData || typeof dashboardData !== 'object') return { data: dashboardData, pendingCount: 0 };
        const actions = (await getPendingActions(userId)).filter(action => action.status !== 'synced');
        if (!actions.length) return { data: dashboardData, pendingCount: 0 };
        const data = typeof structuredClone === 'function'
            ? structuredClone(dashboardData)
            : JSON.parse(JSON.stringify(dashboardData));
        if (!data.statistics) data.statistics = {};
        const statistics = data.statistics;
        const startDate = data.period?.start_date || '0000-00-00';
        const endDate = data.period?.end_date || '9999-12-31';
        const withinPeriod = record => record?.date >= startDate && record?.date <= endDate;
        const applyRecord = (record, direction) => {
            if (!record || !withinPeriod(record) || !['income', 'expense'].includes(record.type)) return;
            const amount = Number(record.amount) || 0;
            const totalField = record.type === 'income' ? 'total_income' : 'total_expense';
            const countField = record.type === 'income' ? 'income_count' : 'expense_count';
            statistics[totalField] = (Number(statistics[totalField]) || 0) + (direction * amount);
            statistics[countField] = Math.max(0, (Number(statistics[countField]) || 0) + direction);
            statistics.balance = (Number(statistics.balance) || 0) + (direction * (record.type === 'income' ? amount : -amount));
        };
        actions.forEach(action => {
            if (action.action === 'create') applyRecord(action.payload, 1);
            if (action.action === 'update') {
                applyRecord(action.baseRecord, -1);
                applyRecord(action.payload, 1);
            }
            if (action.action === 'delete') applyRecord(action.baseRecord, -1);
        });
        data._includesPendingChanges = true;
        return { data, pendingCount: actions.length };
    }

    function sanitizeReferenceData(name, data) {
        if (!Array.isArray(data)) return null;
        if (name === 'accounts') {
            return data.slice(0, 100).map(item => ({ id: item?.id, name: sanitizeText(item?.name, 120) })).filter(item => item.id && item.name);
        }
        if (name.startsWith('categories:')) {
            return data.slice(0, 100).map(item => ({
                id: item?.id,
                name: sanitizeText(item?.name, 120),
                icon: sanitizeIcon(item?.icon),
                color: sanitizeColor(item?.color)
            })).filter(item => item.id && item.name);
        }
        if (name.startsWith('subcategories:')) {
            return data.slice(0, 100).map(item => ({
                id: item?.id,
                name: sanitizeText(item?.name, 120),
                category_id: item?.category_id
            })).filter(item => item.id && item.name);
        }
        return null;
    }

    async function saveReferenceData(userId, name, data) {
        const scope = normalizeUserId(userId);
        const sanitized = sanitizeReferenceData(String(name || ''), data);
        if (!scope || !sanitized) return false;
        const saved = Boolean(await runRequest(REFERENCE_STORE, 'readwrite', store => store.put({
            key: `${scope}:${name}`,
            userId: scope,
            name,
            data: sanitized,
            cachedAt: new Date().toISOString()
        })));
        if (saved) await pruneReferenceData(scope);
        return saved;
    }

    async function pruneReferenceData(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return false;
        const records = await runRequest(REFERENCE_STORE, 'readonly', store => store.index('userId').getAll(scope));
        if (!Array.isArray(records) || records.length <= REFERENCE_ENTRY_LIMIT) return true;
        const obsoleteKeys = records
            .sort((a, b) => String(b.cachedAt || '').localeCompare(String(a.cachedAt || '')))
            .slice(REFERENCE_ENTRY_LIMIT)
            .map(record => record.key);
        const db = await openOfflineDB();
        if (!db) return false;
        return new Promise(resolve => {
            try {
                const transaction = db.transaction(REFERENCE_STORE, 'readwrite');
                const store = transaction.objectStore(REFERENCE_STORE);
                obsoleteKeys.forEach(key => store.delete(key));
                transaction.oncomplete = () => resolve(true);
                transaction.onerror = () => resolve(false);
                transaction.onabort = () => resolve(false);
            } catch (error) {
                resolve(false);
            }
        });
    }

    async function getReferenceData(userId, name) {
        const scope = normalizeUserId(userId);
        if (!scope || !name) return null;
        return await runRequest(REFERENCE_STORE, 'readonly', store => store.get(`${scope}:${name}`));
    }

    async function getStorageStatus(userId) {
        const scope = normalizeUserId(userId);
        if (!scope) return null;
        const [dashboard, transactions, actions, lastSyncedAt] = await Promise.all([
            getDashboardSnapshot(scope),
            getTransactionSnapshot(scope),
            getPendingActions(scope),
            getMetadata(scope, 'last_synced_at')
        ]);
        let estimate = null;
        let persisted = null;
        try {
            if (window.navigator.storage?.estimate) estimate = await window.navigator.storage.estimate();
            if (window.navigator.storage?.persisted) persisted = await window.navigator.storage.persisted();
        } catch (error) {}
        return {
            dashboardCachedAt: dashboard?.cachedAt || null,
            transactionsCachedAt: transactions?.cachedAt || null,
            lastSyncedAt: lastSyncedAt || null,
            pendingCount: actions.filter(action => ['pending', 'syncing', 'synced'].includes(action.status)).length,
            failedCount: actions.filter(action => action.status === 'failed').length,
            conflictCount: actions.filter(action => action.status === 'conflict').length,
            usage: Number(estimate?.usage) || 0,
            quota: Number(estimate?.quota) || 0,
            persisted
        };
    }

    async function requestStoragePersistence() {
        try {
            if (!window.navigator.storage?.persist) return null;
            return await window.navigator.storage.persist();
        } catch (error) {
            return false;
        }
    }

    function rememberIdentity(user) {
        if (!user || normalizeUserId(user.id) === null) return;
        const identity = {
            id: String(user.id),
            first_name: String(user.first_name || 'User').slice(0, 80),
            last_name: String(user.last_name || '').slice(0, 80)
        };
        try {
            window.sessionStorage.setItem(OFFLINE_IDENTITY_KEY, JSON.stringify(identity));
        } catch (error) {}
    }

    function getRememberedIdentity() {
        try {
            const identity = JSON.parse(window.sessionStorage.getItem(OFFLINE_IDENTITY_KEY) || 'null');
            if (!identity || normalizeUserId(identity.id) === null) return null;
            return {
                id: String(identity.id),
                first_name: String(identity.first_name || 'User'),
                last_name: String(identity.last_name || ''),
                email: 'Offline read mode',
                avatar: null,
                offline: true
            };
        } catch (error) {
            return null;
        }
    }

    function clearRememberedIdentity() {
        try { window.sessionStorage.removeItem(OFFLINE_IDENTITY_KEY); } catch (error) {}
    }

    window.OfflineStorage = Object.freeze({
        DB_NAME,
        DB_VERSION,
        TRANSACTION_LIMIT,
        REFERENCE_ENTRY_LIMIT,
        openOfflineDB,
        saveDashboardSnapshot,
        getDashboardSnapshot,
        saveTransactionSnapshot,
        getTransactionSnapshot,
        saveMetadata,
        getMetadata,
        clearOfflineUserData,
        addPendingAction,
        queueTransactionUpdate,
        queueTransactionDelete,
        getPendingActions,
        getPendingAction,
        updatePendingAction,
        deletePendingAction,
        countPendingActions,
        resetStaleSyncingActions,
        applyPendingDashboardAdjustments,
        saveReferenceData,
        getReferenceData,
        getStorageStatus,
        requestStoragePersistence,
        rememberIdentity,
        getRememberedIdentity,
        clearRememberedIdentity
    });
})(window);
