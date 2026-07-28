class NotificationsManager {
    constructor() {
        this.notifications = [];
        this.filters = {};
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalNotifications = 0;
        this.unreadCount = 0;
        this._pollInterval = null;
        this._dropdownBound = false;
    }

    async onMount() {
        this.filters = {};
        this.currentPage = 1;
        this.bindPageEvents();
        await this.loadNotifications();
    }

    onUnmount() {
        this.stopPolling();
        const dropdown = document.getElementById('notif-dropdown');
        if (dropdown) dropdown.classList.remove('show');
    }

    bindPageEvents() {
        const searchInput = document.getElementById('notif-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', this._debounce(() => {
                this.filters.search = searchInput.value || undefined;
                this.currentPage = 1;
                this.loadNotifications();
            }, 400));
        }

        document.querySelectorAll('.notif-filter-btn[data-filter]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.notif-filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const f = btn.dataset.filter;
                if (f === 'unread') {
                    this.filters.is_read = '0';
                } else if (f === 'read') {
                    this.filters.is_read = '1';
                } else {
                    delete this.filters.is_read;
                }
                this.currentPage = 1;
                this.loadNotifications();
            });
        });

        document.querySelectorAll('.notif-filter-btn[data-period]').forEach(btn => {
            btn.addEventListener('click', () => {
                const wasActive = btn.classList.contains('active');
                document.querySelectorAll('.notif-filter-btn[data-period]').forEach(b => b.classList.remove('active'));
                if (wasActive) {
                    delete this.filters.period;
                } else {
                    btn.classList.add('active');
                    this.filters.period = btn.dataset.period;
                }
                this.currentPage = 1;
                this.loadNotifications();
            });
        });

        const selectAllBtn = document.getElementById('notif-select-all');
        if (selectAllBtn) {
            selectAllBtn.onclick = () => this.markAllAsRead();
        }

        const deleteAllBtn = document.getElementById('notif-delete-all');
        if (deleteAllBtn) {
            deleteAllBtn.onclick = () => this.deleteAll();
        }
    }

    async loadNotifications() {
        try {
            const params = { ...this.filters, limit: this.pageSize, offset: (this.currentPage - 1) * this.pageSize };
            const response = await notificationsAPI.getAll(params);
            if (response.success) {
                this.notifications = response.data.notifications;
                this.totalNotifications = response.data.total;
                this.unreadCount = response.data.unread_count;
                this.renderNotifications();
                this.renderPagination();
                this.updateBadge();
            }
        } catch (error) {
            console.error('Failed to load notifications:', error);
        }
    }

    renderNotifications() {
        const body = document.getElementById('notif-page-body');
        const emptyState = document.getElementById('notif-empty-state');
        if (!body) return;

        if (this.notifications.length === 0) {
            body.innerHTML = '';
            if (emptyState) {
                emptyState.style.display = '';
                body.appendChild(emptyState);
            }
            return;
        }

        let html = '<div class="notif-list">';
        this.notifications.forEach(n => {
            html += this._renderNotifItem(n, true);
        });
        html += '</div>';
        body.innerHTML = html;

        body.querySelectorAll('.notif-item').forEach(item => {
            const id = parseInt(item.dataset.id);

            item.querySelector('.notif-item-action.read')?.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleRead(id);
            });

            item.querySelector('.notif-item-action.delete')?.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteNotification(id);
            });

            if (item.querySelector('.notif-item-action.view')) {
                item.querySelector('.notif-item-action.view').addEventListener('click', (e) => {
                    e.stopPropagation();
                    const refType = item.dataset.refType;
                    const refId = item.dataset.refId;
                    if (refType && refId) {
                        this.navigateToReference(refType, refId);
                    }
                });
            }
        });
    }

    _renderNotifItem(n, showActions = false) {
        const timeAgo = this._timeAgo(n.created_at);
        const readClass = n.is_read ? 'read' : 'unread';
        const icon = n.icon || 'fa-bell';
        const color = n.color || '#6366f1';
        const title = this._escapeHTML(n.title);
        const message = this._escapeHTML(n.message || '');

        return `
            <div class="notif-item ${readClass}" data-id="${n.id}" ${n.reference_type ? `data-ref-type="${n.reference_type}" data-ref-id="${n.reference_id}"` : ''}>
                <div class="notif-item-icon" style="background:${color}15;color:${color}">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="notif-item-content">
                    <div class="notif-item-title">${title}</div>
                    <div class="notif-item-message">${message}</div>
                    <div class="notif-item-time">${timeAgo}</div>
                </div>
                ${showActions ? `
                <div class="notif-item-actions">
                    <button class="notif-item-action read" title="${n.is_read ? 'Mark unread' : 'Mark read'}">
                        <i class="fas ${n.is_read ? 'fa-envelope' : 'fa-envelope-open'}"></i>
                    </button>
                    <button class="notif-item-action delete" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                ` : ''}
            </div>
        `;
    }

    renderPagination() {
        const container = document.getElementById('notif-page-pagination');
        if (!container) return;

        const totalPages = Math.ceil(this.totalNotifications / this.pageSize);
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '<div class="notif-pagination">';
        html += `<button class="notif-page-btn" ${this.currentPage <= 1 ? 'disabled' : ''} data-page="${this.currentPage - 1}"><i class="fas fa-chevron-left"></i></button>`;

        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
                html += `<button class="notif-page-btn ${i === this.currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
                html += `<span class="notif-page-ellipsis">...</span>`;
            }
        }

        html += `<button class="notif-page-btn" ${this.currentPage >= totalPages ? 'disabled' : ''} data-page="${this.currentPage + 1}"><i class="fas fa-chevron-right"></i></button>`;
        html += '</div>';
        container.innerHTML = html;

        container.querySelectorAll('.notif-page-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
                this.currentPage = parseInt(btn.dataset.page);
                this.loadNotifications();
            });
        });
    }

    async toggleRead(id) {
        try {
            const notif = this.notifications.find(n => n.id === id);
            if (!notif) return;

            if (notif.is_read) {
                notif.is_read = 0;
            } else {
                await notificationsAPI.markAsRead(id);
                notif.is_read = 1;
            }
            this.renderNotifications();
            this.updateBadge();
        } catch (error) {
            console.error('Failed to toggle read:', error);
        }
    }

    async deleteNotification(id) {
        try {
            await notificationsAPI.delete(id);
            this.notifications = this.notifications.filter(n => n.id !== id);
            this.totalNotifications--;
            this.renderNotifications();
            this.renderPagination();
            this.updateBadge();
            NotificationService.success('Notification deleted');
        } catch (error) {
            NotificationService.error('Failed to delete notification');
        }
    }

    async markAllAsRead() {
        try {
            await notificationsAPI.markAllAsRead();
            this.notifications.forEach(n => n.is_read = 1);
            this.unreadCount = 0;
            this.renderNotifications();
            this.updateBadge();
            NotificationService.success('All notifications marked as read');
        } catch (error) {
            NotificationService.error('Failed to mark all as read');
        }
    }

    async deleteAll() {
        const confirmed = await NotificationService.confirm({
            title: 'Delete all notifications?',
            text: 'This action cannot be undone.',
            confirmButtonText: 'Yes, delete all'
        });
        if (!confirmed) return;

        try {
            await notificationsAPI.deleteAll();
            this.notifications = [];
            this.totalNotifications = 0;
            this.unreadCount = 0;
            this.renderNotifications();
            this.renderPagination();
            this.updateBadge();
            NotificationService.success('All notifications deleted');
        } catch (error) {
            NotificationService.error('Failed to delete notifications');
        }
    }

    navigateToReference(type, id) {
        const routeMap = {
            'transaction': 'transactions',
            'budget': 'budgets',
            'goal': 'goals',
            'category': 'categories',
            'account': 'accounts',
            'karobar': 'karobar'
        };
        const page = routeMap[type];
        if (page && window.appRouter) {
            window.appRouter.navigate(page);
        }
    }

    updateBadge() {
        this._fetchUnreadCount();
    }

    async _fetchUnreadCount() {
        try {
            const response = await notificationsAPI.getUnreadCount();
            if (response.success) {
                this.unreadCount = response.data.unread_count;
                this._renderBadge();
            }
        } catch (e) {
        }
    }

    _renderBadge() {
        const badge = document.getElementById('notif-badge');
        const sidebarBadge = document.getElementById('sidebar-notif-badge');
        if (badge) {
            if (this.unreadCount > 0) {
                badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }
        if (sidebarBadge) {
            if (this.unreadCount > 0) {
                sidebarBadge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                sidebarBadge.style.display = '';
            } else {
                sidebarBadge.style.display = 'none';
            }
        }
    }

    startPolling(interval = 60000) {
        this.stopPolling();
        this._pollInterval = setInterval(() => this._fetchUnreadCount(), interval);
    }

    stopPolling() {
        if (this._pollInterval) {
            clearInterval(this._pollInterval);
            this._pollInterval = null;
        }
    }

    setupBellDropdown() {
        if (this._dropdownBound) return;
        this._dropdownBound = true;

        const bellWrapper = document.getElementById('notif-bell-wrapper');
        const bellBtn = document.getElementById('notifications-btn');
        const dropdown = document.getElementById('notif-dropdown');
        const markAllBtn = document.getElementById('notif-mark-all-read');
        const viewAllBtn = document.getElementById('notif-view-all');

        if (!bellBtn || !dropdown) return;

        bellBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = dropdown.classList.contains('show');
            if (isOpen) {
                this._closeDropdown();
            } else {
                this._openDropdown();
            }
        });

        document.addEventListener('click', (e) => {
            if (!bellWrapper.contains(e.target)) {
                this._closeDropdown();
            }
        });

        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        if (markAllBtn) {
            markAllBtn.addEventListener('click', () => this.markAllAsRead());
        }

        if (viewAllBtn) {
            viewAllBtn.addEventListener('click', () => {
                this._closeDropdown();
                if (window.appRouter) {
                    window.appRouter.navigate('notifications');
                }
            });
        }

        // Close notification when theme toggled, profile clicked, quick-add clicked, or mobile sidebar toggled
        const closeTriggers = ['theme-toggle', 'profile-btn', 'quick-add-btn', 'mobile-sidebar-toggle'];
        closeTriggers.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', () => this._closeDropdown());
            }
        });

        this._loadDropdownNotifications();
    }

    async _openDropdown() {
        const dropdown = document.getElementById('notif-dropdown');
        if (!dropdown) return;
        closeAllFloatingMenus();
        // Close mobile sidebar if open
        const sidebar = document.querySelector('.sidebar.mobile-show');
        if (sidebar) {
            sidebar.classList.remove('mobile-show');
            const backdrop = document.querySelector('.sidebar-backdrop');
            if (backdrop) backdrop.classList.remove('show');
        }
        dropdown.classList.add('show');
        await this._loadDropdownNotifications();
    }

    _closeDropdown() {
        const dropdown = document.getElementById('notif-dropdown');
        if (dropdown) dropdown.classList.remove('show');
    }

    async _loadDropdownNotifications() {
        const body = document.getElementById('notif-dropdown-body');
        if (!body) return;

        try {
            const response = await notificationsAPI.getRecent(8);
            if (response.success) {
                this.unreadCount = response.data.unread_count;
                this._renderBadge();

                const notifs = response.data.notifications;
                if (notifs.length === 0) {
                    body.innerHTML = `
                        <div class="notif-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>No notifications yet</p>
                        </div>`;
                    return;
                }

                let html = '';
                notifs.forEach(n => {
                    html += this._renderNotifItem(n, false);
                });
                body.innerHTML = html;

                body.querySelectorAll('.notif-item').forEach(item => {
                    const id = parseInt(item.dataset.id);
                    item.addEventListener('click', async () => {
                        const notif = notifs.find(n => n.id === id);
                        if (notif && !notif.is_read) {
                            await notificationsAPI.markAsRead(id);
                            notif.is_read = 1;
                            this.unreadCount = Math.max(0, this.unreadCount - 1);
                            this._renderBadge();
                            item.classList.remove('unread');
                            item.classList.add('read');
                        }
                        if (notif && notif.reference_type && notif.reference_id) {
                            this._closeDropdown();
                            this.navigateToReference(notif.reference_type, notif.reference_id);
                        }
                    });
                });
            }
        } catch (error) {
            body.innerHTML = `
                <div class="notif-empty">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Failed to load notifications</p>
                </div>`;
        }
    }

    _timeAgo(dateStr) {
        const date = new Date(dateStr.replace(' ', 'T') + (dateStr.includes('+') ? '' : 'Z'));
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'Just now';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours}h ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days}d ago`;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    _escapeHTML(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    _debounce(fn, delay) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }
}

window.NotificationsManager = NotificationsManager;

/**
 * Close all open floating menus/dropdowns.
 * Ensures only one floating element is visible at a time.
 */
function closeAllFloatingMenus() {
    // Close notification dropdown
    const notifDropdown = document.getElementById('notif-dropdown');
    if (notifDropdown) notifDropdown.classList.remove('show');

    // Close any other dropdowns (future-proofing)
    document.querySelectorAll('.dropdown-menu.show, .theme-menu.show, .profile-menu.show').forEach(el => {
        el.classList.remove('show');
    });
}

window.closeAllFloatingMenus = closeAllFloatingMenus;
