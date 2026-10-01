// Centralized Phase 5 synchronization for Phase 4 offline transaction creates.
(function initializeSanIESync(window) {
    'use strict';

    const AUTO_RETRY_LIMIT = 4;
    const BACKOFF_MS = [30000, 120000, 300000];
    const LOCK_TTL_MS = 90000;
    const STALE_SYNC_MS = 120000;

    class SanIESyncEngine {
        constructor() {
            this.isSyncing = false;
            this.suspended = false;
            this.retryTimer = null;
            this.lastFocusSyncAt = 0;
            this.ownerId = window.crypto?.randomUUID?.() || `tab_${Date.now()}_${Math.random().toString(36).slice(2)}`;
            this.channel = 'BroadcastChannel' in window ? new BroadcastChannel('sanie-sync') : null;
            this.state = { state: 'idle', total: 0, current: 0, synced: 0, failed: 0, message: '' };
            this._uiRefreshPending = false;
            this.bindTriggers();
        }

        bindTriggers() {
            window.addEventListener('online', () => this.syncPendingActions({ trigger: 'online' }));
            window.addEventListener('auth:authenticated', () => this.syncPendingActions({ trigger: 'authentication' }));
            window.addEventListener('auth:unauthenticated', () => this.setState({ state: 'auth-required', message: 'Sign in to sync pending changes.' }));
            window.addEventListener('focus', () => {
                if (!navigator.onLine || Date.now() - this.lastFocusSyncAt < 30000) return;
                this.lastFocusSyncAt = Date.now();
                this.syncPendingActions({ trigger: 'focus' });
            });
            window.addEventListener('load', () => setTimeout(() => this.syncPendingActions({ trigger: 'startup' }), 750));
            this.channel?.addEventListener('message', event => {
                if (event.data?.type === 'state') {
                    if (String(event.data.userId || '') !== String(this.currentUserId() || '')) return;
                    this.state = event.data.state;
                    window.dispatchEvent(new CustomEvent('sync:state', { detail: this.state }));
                }
                if (event.data?.type === 'queue-changed') {
                    if (String(event.data.userId || '') !== String(this.currentUserId() || '')) return;
                    window.refreshPendingSyncStatus?.();
                    window.dispatchEvent(new CustomEvent('sync:remote-queue-changed', { detail: { userId: event.data.userId } }));
                }
            });
        }

        currentUserId() {
            const userId = window.authManager?.getCurrentUser?.()?.id;
            return userId === undefined || userId === null ? null : String(userId);
        }

        getSyncState() {
            return { ...this.state, isSyncing: this.isSyncing };
        }

        setState(update) {
            this.state = { ...this.state, ...update };
            window.dispatchEvent(new CustomEvent('sync:state', { detail: this.state }));
            this.channel?.postMessage({ type: 'state', state: this.state, userId: this.currentUserId() });
        }

        async syncPendingActions(options = {}) {
            if (this.suspended || this.isSyncing || !window.OfflineStorage || !window.Api) return this.getSyncState();
            const user = window.authManager?.getCurrentUser?.();
            const userId = user?.id === undefined || user?.id === null ? null : String(user.id);
            if (!userId) {
                this.setState({ state: 'auth-required', message: 'Sign in to sync pending changes.' });
                return this.getSyncState();
            }
            if (!navigator.onLine) {
                const total = await window.OfflineStorage.countPendingActions(userId);
                this.setState({ state: 'offline', total, message: `${total} change${total === 1 ? '' : 's'} waiting to sync` });
                return this.getSyncState();
            }

            const lockName = `sanie-sync-user-${userId}`;
            if (navigator.locks?.request) {
                let result = this.getSyncState();
                await navigator.locks.request(lockName, { ifAvailable: true }, async lock => {
                    if (!lock) return;
                    result = await this.runLocked(userId, options);
                });
                return result;
            }

            if (!this.acquireLease(lockName)) return this.getSyncState();
            try {
                return await this.runLocked(userId, { ...options, fallbackLease: true });
            } finally {
                this.releaseLease(lockName);
            }
        }

        acquireLease(lockName) {
            const key = `sanie:${lockName}`;
            try {
                const existing = JSON.parse(localStorage.getItem(key) || 'null');
                if (existing?.ownerId !== this.ownerId && Number(existing?.expiresAt) > Date.now()) return false;
                localStorage.setItem(key, JSON.stringify({ ownerId: this.ownerId, expiresAt: Date.now() + LOCK_TTL_MS }));
                return JSON.parse(localStorage.getItem(key) || 'null')?.ownerId === this.ownerId;
            } catch (error) {
                return !this.isSyncing;
            }
        }

        renewLease(lockName) {
            try {
                localStorage.setItem(`sanie:${lockName}`, JSON.stringify({ ownerId: this.ownerId, expiresAt: Date.now() + LOCK_TTL_MS }));
            } catch (error) {}
        }

        releaseLease(lockName) {
            const key = `sanie:${lockName}`;
            try {
                const existing = JSON.parse(localStorage.getItem(key) || 'null');
                if (existing?.ownerId === this.ownerId) localStorage.removeItem(key);
            } catch (error) {}
        }

        async runLocked(userId, options) {
            this.isSyncing = true;
            clearTimeout(this.retryTimer);
            const lockName = `sanie-sync-user-${userId}`;
            let synced = 0;
            let failed = 0;
            let haltOutcome = null;
            try {
                await window.OfflineStorage.resetStaleSyncingActions(userId, STALE_SYNC_MS);
                const authenticated = await this.confirmAuthenticatedUser(userId);
                if (!authenticated) return this.getSyncState();

                let actions = await window.OfflineStorage.getPendingActions(userId);
                if (options.retryLocalId) actions = actions.filter(action => action.localId === options.retryLocalId);
                const total = actions.length;
                if (!total) {
                    this.setState({ state: 'idle', total: 0, current: 0, synced: 0, failed: 0, message: '' });
                    return this.getSyncState();
                }

                this.setState({ state: 'syncing', total, current: 0, synced: 0, failed: 0, message: `Syncing 0 of ${total}...` });
                for (let index = 0; index < actions.length; index++) {
                    let action = actions[index];
                    if (options.fallbackLease) this.renewLease(lockName);
                    if (String(action.userId) !== userId) continue;
                    if (['failed', 'conflict'].includes(action.status) && !options.retryLocalId) {
                        failed++;
                        continue;
                    }
                    if (action.nextAttemptAt && Date.parse(action.nextAttemptAt) > Date.now()) {
                        this.scheduleRetry(Date.parse(action.nextAttemptAt) - Date.now());
                        break;
                    }
                    if (!action.clientRequestId) {
                        const clientRequestId = this.generateClientRequestId();
                        const saved = await window.OfflineStorage.updatePendingAction(action.localId, { clientRequestId });
                        if (!saved) break;
                        action = { ...action, clientRequestId };
                    }

                    this.setState({ state: 'syncing', current: index + 1, synced, failed, message: `Syncing ${index + 1} of ${total}...` });
                    if (action.status === 'synced' && action.serverId) {
                        if (await this.reconcileLocalRecord(action, action.serverId, userId)) synced++;
                        else break;
                        continue;
                    }

                    const outcome = await this.syncSingleAction(action, userId);
                    if (outcome === 'success') synced++;
                    if (outcome === 'failed') failed++;
                    if (['network', 'aborted', 'auth', 'server', 'reconcile'].includes(outcome)) {
                        haltOutcome = outcome;
                        break;
                    }
                }

                const remaining = await window.OfflineStorage.getPendingActions(userId);
                failed = remaining.filter(action => ['failed', 'conflict'].includes(action.status)).length;
                if (['network', 'aborted', 'auth'].includes(haltOutcome)) return this.getSyncState();
                const state = remaining.length === 0 ? 'success' : (failed ? 'partial' : 'pending');
                const message = remaining.length === 0
                    ? `${synced} change${synced === 1 ? '' : 's'} synced`
                    : failed
                        ? `${synced} synced, ${failed} need${failed === 1 ? 's' : ''} attention`
                        : `${remaining.length} change${remaining.length === 1 ? '' : 's'} waiting to sync`;
                if (remaining.length === 0 && synced > 0) {
                    await window.OfflineStorage.saveMetadata?.(userId, 'last_synced_at', new Date().toISOString());
                }
                this.setState({ state, total: remaining.length, current: 0, synced, failed, message });
                return this.getSyncState();
            } finally {
                this.isSyncing = false;
                if (this._uiRefreshPending) {
                    this._uiRefreshPending = false;
                    document.dispatchEvent(new CustomEvent('app:data-changed'));
                }
                window.refreshPendingSyncStatus?.();
                this.channel?.postMessage({ type: 'queue-changed', userId });
            }
        }

        async confirmAuthenticatedUser(userId) {
            if (!window.authManager?.isAuthenticated?.() || !window.Api.isAuthenticated()) {
                this.setState({ state: 'auth-required', message: 'Sign in to sync pending changes.' });
                return false;
            }
            try {
                const response = await authAPI.getMe();
                if (!response?.success || String(response.data?.id) !== userId) {
                    this.setState({ state: 'auth-required', message: 'Sign in to the correct account to sync pending changes.' });
                    return false;
                }
                return true;
            } catch (error) {
                if ([401, 403, 419].includes(Number(error?.status))) {
                    this.setState({ state: 'auth-required', message: 'Please sign in again to sync your pending changes.' });
                } else {
                    this.setState({ state: 'pending', message: 'Unable to confirm your session. Changes remain safely on this device.' });
                }
                return false;
            }
        }

        async syncSingleAction(action, userId) {
            const now = new Date().toISOString();
            await window.OfflineStorage.updatePendingAction(action.localId, {
                status: 'syncing',
                lastAttemptAt: now,
                nextAttemptAt: null,
                lastError: null
            });
            try {
                const requestData = {
                    ...action.payload,
                    client_request_id: action.clientRequestId,
                    ...(action.baseVersion ? { base_version: action.baseVersion } : {})
                };
                let response;
                const generationHeaders = { headers: { 'X-SanIE-Data-Generation': String(Math.max(1, Number(action.dataGeneration) || 1)) } };
                if (action.action === 'create') response = await window.Api.post(action.endpoint, requestData, generationHeaders);
                else if (action.action === 'update') response = await window.Api.put(action.endpoint, requestData, generationHeaders);
                else if (action.action === 'delete') response = await window.Api.delete(action.endpoint, requestData, generationHeaders);
                else throw Object.assign(new Error('Unsupported pending action.'), { status: 422 });
                const serverId = response?.data?.id || action.serverId;
                if (!response?.success || !serverId) throw Object.assign(new Error('Server did not confirm the transaction.'), { status: 502 });
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'synced',
                    serverId,
                    lastError: null
                });
                return await this.reconcileLocalRecord(action, serverId, userId) ? 'success' : 'reconcile';
            } catch (error) {
                return await this.handleSyncError(action, error);
            }
        }

        async reconcileLocalRecord(action, serverId, userId) {
            try {
                window.Api.constructor.invalidateCache();
                if (action.action !== 'delete') {
                    const confirmedResponse = await window.Api.get(`/transactions/${encodeURIComponent(serverId)}`);
                    if (!confirmedResponse?.success || String(confirmedResponse.data?.id) !== String(serverId)) {
                        throw new Error('Confirmed transaction is not yet available for reconciliation.');
                    }
                }
                const transactionResponse = await window.Api.get('/transactions?');
                if (!transactionResponse?.success || !Array.isArray(transactionResponse.data)) throw new Error('Transaction refresh failed.');
                if (action.action === 'delete' && transactionResponse.data.some(transaction => String(transaction.id) === String(serverId))) {
                    throw new Error('Deleted transaction is still present during reconciliation.');
                }
                await window.OfflineStorage.saveTransactionSnapshot(userId, transactionResponse.data);

                if (typeof dashboardAPI !== 'undefined') {
                    const range = window.dashboardManager?.getPeriodDates?.() || this.defaultDashboardRange();
                    const dashboardResponse = await dashboardAPI.getData(range.startDate, range.endDate);
                    if (!dashboardResponse?.success || !dashboardResponse.data) throw new Error('Dashboard refresh failed.');
                    await window.OfflineStorage.saveDashboardSnapshot(userId, dashboardResponse.data);
                }

                await window.OfflineStorage.deletePendingAction(action.localId);
                this._uiRefreshPending = true;
                return true;
            } catch (error) {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'synced',
                    serverId,
                    lastError: 'Server saved this transaction. Local refresh will resume automatically.'
                });
                return false;
            }
        }

        async handleSyncError(action, error) {
            const status = Number(error?.status || 0);
            if (error?.category === 'aborted_error' || error?.code === 'ABORTED_ERROR') {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'pending', nextAttemptAt: null, lastError: null
                });
                this.setState({ state: 'pending', message: 'Synchronization was cancelled. Your change is still waiting.' });
                return 'aborted';
            }
            const networkFailure = navigator.onLine === false
                || ['network_error', 'timeout_error'].includes(error?.category)
                || ['NETWORK_ERROR', 'TIMEOUT_ERROR'].includes(error?.code);
            if (networkFailure) {
                const retryCount = Number(action.retryCount || 0) + 1;
                const exhausted = retryCount >= AUTO_RETRY_LIMIT;
                const delay = BACKOFF_MS[Math.min(retryCount - 1, BACKOFF_MS.length - 1)];
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: exhausted ? 'failed' : 'pending',
                    retryCount,
                    nextAttemptAt: exhausted ? null : new Date(Date.now() + delay).toISOString(),
                    lastError: exhausted
                        ? 'Sync could not complete after several connection attempts. Try again manually.'
                        : (error?.category === 'timeout_error' ? 'Sync timed out. It will retry later.' : 'Connection lost. Waiting to sync.')
                });
                if (!exhausted && navigator.onLine) this.scheduleRetry(delay);
                this.setState({
                    state: navigator.onLine ? (exhausted ? 'partial' : 'pending') : 'offline',
                    message: exhausted ? 'A pending change needs manual attention.' : 'Connection unavailable. Changes are still waiting to sync.'
                });
                return 'network';
            }
            if ([401, 403, 419].includes(status)) {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'pending',
                    lastError: 'Please sign in again to sync this change.'
                });
                this.setState({ state: 'auth-required', message: 'Please sign in again to sync your pending changes.' });
                return 'auth';
            }
            if (status === 409 || error?.apiCode === 'CONFLICT') {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'conflict',
                    conflictData: error?.serverData || null,
                    lastError: action.action === 'delete'
                        ? 'This transaction changed before deletion. Review it before continuing.'
                        : 'This transaction changed elsewhere. Choose which version to keep.'
                });
                return 'conflict';
            }
            if (status === 404 && action.action === 'update') {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'conflict',
                    conflictData: null,
                    lastError: 'This transaction no longer exists on the server.'
                });
                return 'conflict';
            }
            if ([400, 422].includes(status)) {
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: 'failed',
                    retryCount: Number(action.retryCount || 0) + 1,
                    lastError: this.safeError(error, 'This transaction needs review before it can sync.')
                });
                return 'failed';
            }
            if (status >= 500) {
                const retryCount = Number(action.retryCount || 0) + 1;
                const exhausted = retryCount >= AUTO_RETRY_LIMIT;
                const delay = BACKOFF_MS[Math.min(retryCount - 1, BACKOFF_MS.length - 1)];
                await window.OfflineStorage.updatePendingAction(action.localId, {
                    status: exhausted ? 'failed' : 'pending',
                    retryCount,
                    nextAttemptAt: exhausted ? null : new Date(Date.now() + delay).toISOString(),
                    lastError: exhausted ? 'Sync could not complete after several attempts. Try again manually.' : 'Server is temporarily unavailable. Sync will retry later.'
                });
                if (!exhausted) this.scheduleRetry(delay);
                return exhausted ? 'failed' : 'server';
            }
            await window.OfflineStorage.updatePendingAction(action.localId, {
                status: 'failed',
                retryCount: Number(action.retryCount || 0) + 1,
                lastError: 'This transaction could not be synchronized. Please retry.'
            });
            return 'failed';
        }

        safeError(error, fallback) {
            const message = String(error?.message || '').trim();
            if (!message || /sql|pdo|database|query|stack|exception|constraint/i.test(message)) return fallback;
            return message.slice(0, 180);
        }

        defaultDashboardRange() {
            const range = DateUtils.getKathmanduRange('month');
            return {
                startDate: range.start,
                endDate: range.end
            };
        }

        scheduleRetry(delay) {
            clearTimeout(this.retryTimer);
            this.retryTimer = setTimeout(() => this.syncPendingActions({ trigger: 'backoff' }), Math.max(1000, delay));
        }

        suspendForFreshStart() {
            if (this.isSyncing) return false;
            this.suspended = true;
            clearTimeout(this.retryTimer);
            return true;
        }

        resumeAfterFreshStartFailure() { this.suspended = false; }

        async retryAction(localId) {
            const action = await window.OfflineStorage?.getPendingAction(localId);
            const userId = window.authManager?.getCurrentUser?.()?.id;
            if (!action || String(action.userId) !== String(userId)) return false;
            if (action.status === 'conflict') return this.resolveConflict(localId);
            await window.OfflineStorage.updatePendingAction(localId, {
                status: 'pending',
                retryCount: 0,
                lastAttemptAt: null,
                nextAttemptAt: null,
                lastError: null
            });
            await this.syncPendingActions({ trigger: 'manual-retry', retryLocalId: localId });
            return true;
        }

        async resolveConflict(localId) {
            const action = await window.OfflineStorage?.getPendingAction(localId);
            const userId = window.authManager?.getCurrentUser?.()?.id;
            if (!action || action.status !== 'conflict' || String(action.userId) !== String(userId)) return false;
            if (!navigator.onLine) {
                this.setState({ state: 'offline', message: 'Reconnect before resolving this conflict.' });
                return false;
            }
            const server = action.conflictData;
            if (!server) {
                const result = window.Swal
                    ? await window.Swal.fire({
                        icon: 'warning',
                        title: 'Transaction no longer exists',
                        text: 'This transaction is no longer available on the server. Discard the offline change?',
                        showCancelButton: true,
                        confirmButtonText: 'Discard offline change',
                        confirmButtonColor: '#EF4444'
                    })
                    : { isConfirmed: window.confirm('This transaction no longer exists. Discard the offline change?') };
                if (!result.isConfirmed) return false;
                if (!await this.refreshServerSnapshots(String(userId))) return false;
                await window.OfflineStorage.deletePendingAction(localId);
                await window.transactionsManager?.loadTransactions?.();
                return true;
            }

            const money = value => `Rs ${Number(value || 0).toLocaleString('en-IN')}`;
            const mine = action.action === 'delete' ? action.baseRecord : action.payload;
            const isDelete = action.action === 'delete';
            const result = window.Swal
                ? await window.Swal.fire({
                    icon: 'warning',
                    title: isDelete ? 'Transaction changed before deletion' : 'Transaction changed elsewhere',
                    html: `<div class="text-start small"><strong>Server version</strong><br>${money(server.amount)} · ${this.escapeHtml(server.category_name || server.type || 'Transaction')}<hr><strong>${isDelete ? 'Your request' : 'Your offline change'}</strong><br>${isDelete ? 'Delete this transaction' : `${money(mine.amount)} · ${this.escapeHtml(action.display?.category_name || mine.type || 'Transaction')}`}</div>`,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: isDelete ? 'Delete current version' : 'Apply my changes',
                    denyButtonText: 'Keep server version',
                    confirmButtonColor: '#EF4444',
                    denyButtonColor: '#64748B'
                })
                : { isConfirmed: false, isDenied: window.confirm('Keep the server version and discard the offline change?') };
            if (!result.isConfirmed && !result.isDenied) return false;
            if (result.isDenied) {
                if (!await this.refreshServerSnapshots(String(userId))) return false;
                await window.OfflineStorage.deletePendingAction(localId);
                await window.transactionsManager?.loadTransactions?.();
                return true;
            }

            try {
                window.Api.constructor.invalidateCache();
                const latestResponse = await window.Api.get(`/transactions/${encodeURIComponent(action.serverId)}`);
                if (!latestResponse?.success || !latestResponse.data?.version) throw new Error('Latest transaction version is unavailable.');
                await window.OfflineStorage.updatePendingAction(localId, {
                    baseVersion: Number(latestResponse.data.version),
                    baseRecord: latestResponse.data,
                    status: 'pending',
                    retryCount: 0,
                    lastError: null,
                    conflictData: null,
                    nextAttemptAt: null
                });
                await this.syncPendingActions({ trigger: 'conflict-resolution', retryLocalId: localId });
                return true;
            } catch (error) {
                await window.OfflineStorage.updatePendingAction(localId, {
                    status: 'conflict',
                    lastError: 'Could not confirm the latest server version. Try resolving again when online.'
                });
                return false;
            }
        }

        async refreshServerSnapshots(userId) {
            window.Api.constructor.invalidateCache();
            const transactionResponse = await window.Api.get('/transactions?');
            if (!transactionResponse?.success || !Array.isArray(transactionResponse.data)) return false;
            await window.OfflineStorage.saveTransactionSnapshot(userId, transactionResponse.data);
            if (typeof dashboardAPI !== 'undefined') {
                const range = window.dashboardManager?.getPeriodDates?.() || this.defaultDashboardRange();
                const dashboardResponse = await dashboardAPI.getData(range.startDate, range.endDate);
                if (dashboardResponse?.success && dashboardResponse.data) {
                    await window.OfflineStorage.saveDashboardSnapshot(userId, dashboardResponse.data);
                }
            }
            document.dispatchEvent(new CustomEvent('app:data-changed'));
            this.channel?.postMessage({ type: 'queue-changed', userId });
            return true;
        }

        escapeHtml(value) {
            return String(value ?? '').replace(/[&<>'"]/g, character => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            })[character]);
        }

        generateClientRequestId() {
            return window.crypto?.randomUUID?.() || `req_${Date.now()}_${Math.random().toString(36).slice(2, 18)}`;
        }
    }

    window.SanIESync = new SanIESyncEngine();
})(window);
