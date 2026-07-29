class CategoriesManager {
    constructor() {
        if (!window.APP_CONFIG?.API_BASE || !window.Api) {
            console.error('API configuration missing.');
            return;
        }

        this.categories = [];
        this.selectedCategories = new Set();
        this.isLoading = false;
        this._mounted = false;
        this._listeners = {};
        this._bulkListeners = [];
        this._dragState = { draggedId: null };
        this._sortField = 'name';
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        window.addEventListener('app:data-changed', this._onDataChanged = () => { this.loadCategories(); this.loadStatistics(); });
        this.loadCategories();
        this.loadStatistics();
    }

    onUnmount() {
        this._mounted = false;

        if (this._onDataChanged) {
            window.removeEventListener('app:data-changed', this._onDataChanged);
        }

        if (this._cardHandler) {
            document.removeEventListener('click', this._cardHandler);
            this._cardHandler = null;
        }

        const removers = [
            ['addClick', 'add-category-btn'],
            ['addEmptyClick', 'add-category-btn-empty'],
            ['applyClick', 'apply-category-filters'],
            ['clearClick', 'clear-category-filters'],
            ['searchInput', 'search-categories'],
            ['selectAllChange', 'select-all-categories'],
            ['sortChange', 'category-sort-select']
        ];

        removers.forEach(([key, id]) => {
            if (this._listeners[key]) {
                document.getElementById(id)?.removeEventListener(
                    key === 'searchInput' ? 'input' : key === 'sortChange' ? 'change' : 'click',
                    this._listeners[key]
                );
            }
        });
        this._listeners = {};

        this._bulkListeners.forEach(({ el, handler }) => el.removeEventListener('click', handler));
        this._bulkListeners = [];
    }

    setupEventListeners() {
        const bind = (id, event, fn) => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener(event, fn);
                return fn;
            }
            return null;
        };

        this._listeners.addClick = bind('add-category-btn', 'click', () => this.showAddCategoryModal());
        this._listeners.addEmptyClick = bind('add-category-btn-empty', 'click', () => this.showAddCategoryModal());
        this._listeners.applyClick = bind('apply-category-filters', 'click', () => this.loadCategories());
        this._listeners.clearClick = bind('clear-category-filters', 'click', () => this.clearFilters());
        this._listeners.searchInput = bind('search-categories', 'input', (e) => this.handleSearch(e.target.value));
        this._listeners.sortChange = bind('category-sort-select', 'change', (e) => {
            this._sortField = e.target.value;
            this.renderCards();
        });

        const selectAll = document.getElementById('select-all-categories');
        if (selectAll) {
            this._listeners.selectAllChange = (e) => this.toggleSelectAll(e.target.checked);
            selectAll.addEventListener('change', this._listeners.selectAllChange);
        }

        document.querySelectorAll('#category-bulk-actions button').forEach(btn => {
            const handler = () => this.handleBulkAction(btn.dataset.action);
            this._bulkListeners.push({ el: btn, handler });
            btn.addEventListener('click', handler);
        });

        const importBtn = document.getElementById('import-categories-btn');
        if (importBtn) {
            const h = () => this.showImportModal();
            importBtn.addEventListener('click', h);
            this._bulkListeners.push({ el: importBtn, handler: h });
        }

        const exportBtn = document.getElementById('export-categories-btn');
        if (exportBtn) {
            const h = () => this.exportCategories();
            exportBtn.addEventListener('click', h);
            this._bulkListeners.push({ el: exportBtn, handler: h });
        }
    }

    async loadCategories() {
        if (this.isLoading) return;
        this.isLoading = true;
        this.showLoading(true);

        try {
            const type = document.getElementById('filter-category-type')?.value || '';
            const status = document.getElementById('filter-category-status')?.value || 'active';
            const params = new URLSearchParams();
            if (type) params.set('type', type);
            if (status) params.set('status', status);

            const result = await window.Api.get(`/categories?${params}`);

            if (result.success) {
                this.categories = (result.data || []).map(c => this._normalize(c));
            } else {
                this.categories = [];
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
            this.categories = [];
            NotificationService.error('Failed to load categories');
        } finally {
            this.isLoading = false;
            this.applyClientFilters();
            this.showLoading(false);
        }
    }

    _normalize(cat) {
        return {
            ...cat,
            id: Number(cat.id),
            is_default: Number(cat.is_default),
            sort_order: Number(cat.sort_order) || 0,
            transaction_count: Number(cat.transaction_count) || 0,
            subcategory_count: Number(cat.subcategory_count) || 0,
            subcategories: (cat.subcategories || []).map(s => ({
                ...s,
                id: Number(s.id),
                category_id: Number(s.category_id),
                sort_order: Number(s.sort_order) || 0
            }))
        };
    }

    applyClientFilters() {
        const search = (document.getElementById('search-categories')?.value || '').toLowerCase().trim();
        const defaultFilter = document.getElementById('filter-category-default')?.value;

        let filtered = [...this.categories];

        if (search) {
            filtered = filtered.filter(c => {
                if (c.name.toLowerCase().includes(search)) return true;
                if (c.description && c.description.toLowerCase().includes(search)) return true;
                if (c.subcategories && c.subcategories.some(s =>
                    s.name.toLowerCase().includes(search) ||
                    (s.description && s.description.toLowerCase().includes(search))
                )) return true;
                return false;
            });
        }

        if (defaultFilter === 'default') {
            filtered = filtered.filter(c => Number(c.is_default) === 1);
        } else if (defaultFilter === 'custom') {
            filtered = filtered.filter(c => Number(c.is_default) === 0);
        }

        this._filtered = filtered;
        this.sortCards();
    }

    sortCards() {
        const data = this._filtered || this.categories;
        const sorted = [...data];

        switch (this._sortField) {
            case 'name':
                sorted.sort((a, b) => a.name.localeCompare(b.name));
                break;
            case 'type':
                sorted.sort((a, b) => a.type.localeCompare(b.type) || a.name.localeCompare(b.name));
                break;
            case 'created':
                sorted.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
                break;
            case 'subcategories':
                sorted.sort((a, b) => (b.subcategory_count || 0) - (a.subcategory_count || 0));
                break;
            case 'transactions':
                sorted.sort((a, b) => (b.transaction_count || 0) - (a.transaction_count || 0));
                break;
            case 'last_used':
                sorted.sort((a, b) => {
                    if (!a.last_used_at) return 1;
                    if (!b.last_used_at) return -1;
                    return new Date(b.last_used_at) - new Date(a.last_used_at);
                });
                break;
        }

        this._renderData = sorted;
        this.renderCards();
    }

    handleSearch(query) {
        clearTimeout(this._searchTimer);
        this._searchTimer = setTimeout(() => {
            this.applyClientFilters();
            this.sortCards();
        }, 200);
    }

    renderCards() {
        const grid = document.getElementById('categories-card-grid');
        const emptyState = document.getElementById('categories-empty-state');
        const countBadge = document.getElementById('category-count-badge');

        if (!grid) return;

        const data = this._renderData || this._filtered || this.categories;

        if (countBadge) countBadge.textContent = data.length;

        if (data.length === 0) {
            grid.innerHTML = '';
            grid.classList.add('d-none');
            if (emptyState) emptyState.classList.remove('d-none');
            return;
        }

        if (emptyState) emptyState.classList.add('d-none');
        grid.classList.remove('d-none');

        grid.innerHTML = data.map(cat => this.buildCardHTML(cat)).join('');

        this.attachCardListeners();
        this.updateBulkVisibility();
    }

    buildCardHTML(cat) {
        const esc = (str) => (str ? String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : '');
        const subcats = cat.subcategories || [];
        const typeClass = cat.type === 'income' ? 'income' : 'expense';
        const statusClass = cat.status === 'active' ? 'bg-success' : cat.status === 'archived' ? 'bg-warning' : 'bg-danger';
        const statusText = cat.status.charAt(0).toUpperCase() + cat.status.slice(1);
        const isSelected = this.selectedCategories.has(String(cat.id));
        const isDefault = Number(cat.is_default) === 1;
        const txCount = cat.transaction_count || 0;
        const lastUsed = cat.last_used_at ? Formatters.date(cat.last_used_at) : 'Never';
        const subCount = subcats.length;
        const catColor = cat.color || '#10B981';

        const subChips = subcats.map(s => `
            <span class="cat-sub-chip" data-sub-id="${s.id}" data-cat-id="${cat.id}">
                <i class="bi bi-${esc(s.icon || 'tag')}"></i>
                <span class="cat-sub-chip-name">${esc(s.name)}</span>
                ${!isDefault ? `
                <button class="cat-sub-chip-edit" data-sub-id="${s.id}" data-cat-id="${cat.id}" title="Edit subcategory">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <button class="cat-sub-chip-delete" data-sub-id="${s.id}" data-cat-id="${cat.id}" title="Delete subcategory">
                    <i class="fas fa-trash-alt"></i>
                </button>
                ` : ''}
            </span>
        `).join('');

        const menuId = `cat-menu-${cat.id}`;

        return `
        <div class="category-card${isSelected ? ' selected' : ''}" data-id="${cat.id}" draggable="${!isDefault}" style="border-left: 4px solid ${catColor}">
            <input type="checkbox" class="cat-card-checkbox category-checkbox" value="${cat.id}" ${isSelected ? 'checked' : ''} ${isDefault ? 'disabled title="Default category"' : ''}>
            <div class="cat-card-header">
                <div class="cat-card-icon" style="background-color: ${catColor}18; color: ${catColor}">
                    <i class="bi bi-${esc(cat.icon || 'tag')}"></i>
                </div>
                <div class="cat-card-title-area">
                    <h6 class="cat-card-name" title="${esc(cat.name)}">${esc(cat.name)}</h6>
                    <div class="cat-card-meta">
                        <span class="cat-card-type-badge ${typeClass}">${typeClass}</span>
                        <span class="cat-card-status-badge badge ${statusClass}">${statusText}</span>
                        ${isDefault ? '<span class="cat-card-default-badge">Default</span>' : ''}
                    </div>
                </div>
                <div class="cat-dropdown">
                    <button class="cat-card-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end cat-dropdown-menu" id="${menuId}">
                        <li><a class="dropdown-item cat-menu-view" data-id="${cat.id}" href="#"><i class="fas fa-eye me-2"></i>View Details</a></li>
                        ${!isDefault ? `
                        <li><a class="dropdown-item cat-menu-edit" data-id="${cat.id}" href="#"><i class="fas fa-edit me-2"></i>Edit Category</a></li>
                        <li><a class="dropdown-item cat-menu-duplicate" data-id="${cat.id}" href="#"><i class="fas fa-copy me-2"></i>Duplicate</a></li>
                        <li><hr class="dropdown-divider"></li>
                        ${cat.status === 'active'
                            ? `<li><a class="dropdown-item cat-menu-archive" data-id="${cat.id}" href="#"><i class="fas fa-archive me-2"></i>Archive</a></li>`
                            : `<li><a class="dropdown-item cat-menu-restore" data-id="${cat.id}" href="#"><i class="fas fa-undo me-2"></i>Restore</a></li>`
                        }
                        <li><a class="dropdown-item text-danger cat-menu-delete" data-id="${cat.id}" href="#"><i class="fas fa-trash me-2"></i>Delete</a></li>
                        ` : ''}
                    </ul>
                </div>
            </div>
            <div class="cat-card-body">
                ${cat.description ? `<p class="cat-card-description">${esc(cat.description)}</p>` : ''}
                <div class="cat-card-subcategories" data-cat-id="${cat.id}">
                    ${(subChips || '')}
                    ${subCount === 0 ? '<span class="cat-card-no-subs"><i class="fas fa-folder-open" style="font-size:0.55rem;opacity:0.5;"></i> No subcategories yet</span>' : ''}
                    ${!isDefault ? `
                    <button class="cat-card-sub-add" data-category-id="${cat.id}" title="Add subcategory">
                        <i class="fas fa-plus"></i>Add
                    </button>` : ''}
                </div>
            </div>
            <div class="cat-card-footer">
                <div class="cat-card-stats">
                    <span class="cat-card-stat" title="Transactions"><i class="fas fa-exchange-alt"></i>${txCount}</span>
                    <span class="cat-card-stat" title="Subcategories"><i class="fas fa-layer-group"></i>${subCount}</span>
                    <span class="cat-card-stat" title="Last used"><i class="far fa-clock"></i>${lastUsed}</span>
                </div>
            </div>
        </div>`;
    }

    attachCardListeners() {
        const grid = document.getElementById('categories-card-grid');
        if (!grid) return;

        grid.querySelectorAll('.category-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.handleSelectionChange());
        });

        grid.querySelectorAll('.category-card[draggable="true"]').forEach(card => {
            card.addEventListener('dragstart', (e) => this.onDragStart(e));
            card.addEventListener('dragend', (e) => this.onDragEnd(e));
            card.addEventListener('dragover', (e) => this.onDragOver(e));
            card.addEventListener('dragenter', (e) => this.onDragEnter(e));
            card.addEventListener('dragleave', (e) => this.onDragLeave(e));
            card.addEventListener('drop', (e) => this.onDrop(e));
        });

        if (!this._cardHandler) {
            this._cardHandler = (e) => {
                const page = document.getElementById('categories-page');
                if (!page || !page.contains(e.target)) return;

                const target = e.target.closest('.cat-menu-view, .cat-menu-edit, .cat-menu-duplicate, .cat-menu-archive, .cat-menu-restore, .cat-menu-delete, .cat-card-sub-add, .cat-sub-chip-edit, .cat-sub-chip-delete');
                if (!target) return;

                e.preventDefault();

                const catId = Number(target.dataset.id || target.dataset.catId || 0);
                const subId = Number(target.dataset.subId || 0);
                const catCategoryId = Number(target.dataset.categoryId || 0);

                if (target.classList.contains('cat-menu-view')) { this.viewCategory(catId); return; }
                if (target.classList.contains('cat-menu-edit')) { this.editCategory(catId); return; }
                if (target.classList.contains('cat-menu-duplicate')) { this.duplicateCategory(catId); return; }
                if (target.classList.contains('cat-menu-archive')) { this.archiveCategory(catId); return; }
                if (target.classList.contains('cat-menu-restore')) { this.restoreCategory(catId); return; }
                if (target.classList.contains('cat-menu-delete')) { this.deleteCategory(catId); return; }
                if (target.classList.contains('cat-card-sub-add')) {
                    const c = this.categories.find(cat => cat.id === catCategoryId);
                    if (c) { e.stopPropagation(); this.showAddSubcategoryModal(c); }
                    return;
                }
                if (target.classList.contains('cat-sub-chip-edit')) { e.stopPropagation(); this.inlineEditSubcategory(subId, catId); return; }
                if (target.classList.contains('cat-sub-chip-delete')) { e.stopPropagation(); this.inlineDeleteSubcategory(subId, catId); return; }
            };
            document.addEventListener('click', this._cardHandler);
        }
    }

    onDragStart(e) {
        const card = e.target.closest('.category-card');
        if (!card) return;
        this._dragState.draggedId = Number(card.dataset.id);
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    }

    onDragEnd(e) {
        const card = e.target.closest('.category-card');
        if (card) card.classList.remove('dragging');
        document.querySelectorAll('.category-card').forEach(c => c.classList.remove('drag-over'));
        this._dragState.draggedId = null;
    }

    onDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    onDragEnter(e) {
        e.preventDefault();
        const card = e.target.closest('.category-card');
        if (card && Number(card.dataset.id) !== this._dragState.draggedId) {
            card.classList.add('drag-over');
        }
    }

    onDragLeave(e) {
        const card = e.target.closest('.category-card');
        if (card && !card.contains(e.relatedTarget)) {
            card.classList.remove('drag-over');
        }
    }

    async onDrop(e) {
        e.preventDefault();
        const targetCard = e.target.closest('.category-card');
        if (!targetCard) return;
        targetCard.classList.remove('drag-over');

        const draggedId = this._dragState.draggedId;
        const targetId = Number(targetCard.dataset.id);
        if (!draggedId || draggedId === targetId) return;

        const fromIdx = this.categories.findIndex(c => c.id === draggedId);
        const toIdx = this.categories.findIndex(c => c.id === targetId);
        if (fromIdx === -1 || toIdx === -1) return;

        const item = this.categories.splice(fromIdx, 1)[0];
        this.categories.splice(toIdx, 0, item);

        const orders = this.categories.map((c, i) => ({ id: c.id, sort_order: i }));

        this.renderCards();

        try {
            await window.Api.post('/categories/reorder', { orders });
        } catch (err) {
            console.error('Reorder failed:', err);
            this.loadCategories();
        }
    }

    handleSelectionChange() {
        const checkboxes = document.querySelectorAll('.category-checkbox:checked');
        this.selectedCategories = new Set(Array.from(checkboxes).map(cb => cb.value));

        const countEl = document.getElementById('selected-categories-count');
        if (countEl) countEl.textContent = this.selectedCategories.size;

        document.querySelectorAll('.category-card').forEach(card => {
            const id = String(card.dataset.id);
            card.classList.toggle('selected', this.selectedCategories.has(id));
        });

        this.updateBulkVisibility();
    }

    updateBulkVisibility() {
        const bulkEl = document.getElementById('category-bulk-actions');
        if (bulkEl) bulkEl.style.display = this.selectedCategories.size > 0 ? 'flex' : 'none';
    }

    toggleSelectAll(checked) {
        document.querySelectorAll('.category-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = checked;
        });
        this.handleSelectionChange();
    }

    async handleBulkAction(action) {
        if (this.selectedCategories.size === 0) return;

        const msgs = {
            delete: 'Are you sure you want to delete the selected categories?',
            archive: 'Are you sure you want to archive the selected categories?',
            restore: 'Are you sure you want to restore the selected categories?',
            activate: 'Are you sure you want to activate the selected categories?'
        };

        const confirmed = await NotificationService.confirm({
            title: 'Confirm Action',
            text: msgs[action],
            confirmButtonText: 'Yes, proceed'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.post('/categories/bulk', {
                action,
                ids: Array.from(this.selectedCategories)
            });

            if (result.success) {
                NotificationService.success(result.message || 'Bulk action completed successfully');
                this.selectedCategories.clear();
                this.handleSelectionChange();
                const sa = document.getElementById('select-all-categories');
                if (sa) sa.checked = false;
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Bulk action failed');
            }
        } catch (error) {
            console.error('Bulk action failed:', error);
            NotificationService.error('Bulk action failed');
        }
    }

    async loadStatistics() {
        try {
            const result = await window.Api.get('/categories/statistics');
            if (result.success) this.updateStatistics(result.data);
        } catch (error) {
            console.error('Failed to load statistics:', error);
        }
    }

    updateStatistics(data) {
        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val || 0;
        };
        const total = parseInt(data.categories.total) || 0;
        const inactive = parseInt(data.categories.inactive_count) || 0;
        set('stat-total-categories', total);
        set('stat-income-categories', data.categories.income_count);
        set('stat-expense-categories', data.categories.expense_count);
        set('stat-total-subcategories', data.subcategories.total);
        set('stat-inactive-categories', inactive);
        set('stat-active-categories', Math.max(0, total - inactive));

        const badge = document.getElementById('category-count-badge');
        if (badge) badge.textContent = total;
    }

    showLoading(show) {
        const loadingEl = document.getElementById('categories-loading-state');
        const grid = document.getElementById('categories-card-grid');
        const emptyState = document.getElementById('categories-empty-state');

        if (show) {
            if (loadingEl) loadingEl.classList.remove('d-none');
            if (grid) grid.classList.add('d-none');
            if (emptyState) emptyState.classList.add('d-none');
        } else {
            if (loadingEl) loadingEl.classList.add('d-none');
        }
    }

    clearFilters() {
        const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
        setVal('filter-category-type', '');
        setVal('filter-category-status', 'active');
        setVal('filter-category-default', '');
        setVal('search-categories', '');
        setVal('category-sort-select', 'name');
        this._sortField = 'name';
        this.loadCategories();
    }

    _buildCategoryFormHTML(category = null) {
        const v = (val, fallback = '') => (val != null ? val : fallback);
        return `
        <form id="category-form">
            ${category ? `<input type="hidden" id="category-id" value="${category.id}">` : ''}
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="category-name" value="${v(category?.name)}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Type *</label>
                        <select class="form-control" id="category-type" required>
                            <option value="income" ${category?.type === 'income' ? 'selected' : ''}>Income</option>
                            <option value="expense" ${category?.type === 'expense' ? 'selected' : ''}>Expense</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Icon</label>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="cat-card-icon cat-icon-preview" style="background-color: ${category?.color || '#10B981'}18; color: ${category?.color || '#10B981'}; width:40px;height:40px;border-radius:10px;font-size:1rem;display:flex;align-items:center;justify-content:center">
                                <i class="bi bi-${category?.icon || 'tag'}"></i>
                            </div>
                            <input type="text" class="form-control" id="category-icon" value="${category?.icon || 'tag'}" readonly style="flex:1">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="pick-icon-btn"><i class="fas fa-icons"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Color</label>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="color" class="form-control form-control-color" id="category-color" value="${category?.color || '#10B981'}">
                            <div class="d-flex gap-1">
                                <button type="button" class="color-preset-btn" data-color="#10B981" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#10B981"></button>
                                <button type="button" class="color-preset-btn" data-color="#EF4444" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#EF4444"></button>
                                <button type="button" class="color-preset-btn" data-color="#3B82F6" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#3B82F6"></button>
                                <button type="button" class="color-preset-btn" data-color="#F59E0B" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#F59E0B"></button>
                                <button type="button" class="color-preset-btn" data-color="#8B5CF6" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#8B5CF6"></button>
                                <button type="button" class="color-preset-btn" data-color="#EC4899" style="width:24px;height:24px;border-radius:6px;border:2px solid transparent;cursor:pointer;background:#EC4899"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" id="category-description" rows="2">${v(category?.description)}</textarea>
            </div>
            ${category ? `
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-control" id="category-status">
                            <option value="active" ${category.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="archived" ${category.status === 'archived' ? 'selected' : ''}>Archived</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" class="form-control" id="category-sort-order" value="${category.sort_order || 0}" min="0">
                    </div>
                </div>
            </div>` : `
            <div class="form-group mb-3">
                <label class="form-label">Sort Order</label>
                <input type="number" class="form-control" id="category-sort-order" value="0" min="0">
            </div>`}
        </form>`;
    }

    _setupFormListeners() {
        requestAnimationFrame(() => {
            const colorInput = document.getElementById('category-color');
            const iconInput = document.getElementById('category-icon');
            const iconPreview = document.querySelector('.cat-icon-preview i');

            document.querySelectorAll('.color-preset-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const c = btn.dataset.color;
                    if (colorInput) colorInput.value = c;
                    if (iconInput && iconPreview) {
                        document.querySelector('.cat-icon-preview').style.backgroundColor = c + '18';
                        document.querySelector('.cat-icon-preview').style.color = c;
                    }
                });
            });

            if (colorInput) {
                colorInput.addEventListener('input', (e) => {
                    const preview = document.querySelector('.cat-icon-preview');
                    if (preview) {
                        preview.style.backgroundColor = e.target.value + '18';
                        preview.style.color = e.target.value;
                    }
                });
            }

            const typeSelect = document.getElementById('category-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', (e) => {
                    const defaultColor = e.target.value === 'income' ? '#10B981' : '#EF4444';
                    const ci = document.getElementById('category-color');
                    if (ci) ci.value = defaultColor;
                    const preview = document.querySelector('.cat-icon-preview');
                    if (preview) {
                        preview.style.backgroundColor = defaultColor + '18';
                        preview.style.color = defaultColor;
                    }
                });
            }

            const pickBtn = document.getElementById('pick-icon-btn');
            if (pickBtn) pickBtn.addEventListener('click', () => this.showIconPicker());
        });
    }

    showAddCategoryModal() {
        const modalBody = this._buildCategoryFormHTML();

        if (window.modalService && typeof window.modalService.open === 'function') {
            window.modalService.open({
                title: 'Add Category',
                subtitle: 'Create a new category for your transactions',
                icon: 'fa-tags',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.saveCategory()
            });
        } else if (window.premiumModal && typeof window.premiumModal.open === 'function') {
            window.premiumModal.setTitle('Add Category');
            window.premiumModal.setSubtitle('Create a new category for your transactions');
            window.premiumModal.setIcon('fa-tags');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').onclick = () => this.saveCategory();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }

        this._setupFormListeners();
    }

    editCategory(id) {
        const category = this.categories.find(c => c.id === id);
        if (!category) return;

        const modalBody = this._buildCategoryFormHTML(category);

        if (window.modalService && typeof window.modalService.open === 'function') {
            window.modalService.open({
                title: 'Edit Category',
                subtitle: 'Update category details',
                icon: 'fa-edit',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.updateCategory()
            });
        } else if (window.premiumModal && typeof window.premiumModal.open === 'function') {
            window.premiumModal.setTitle('Edit Category');
            window.premiumModal.setSubtitle('Update category details');
            window.premiumModal.setIcon('fa-edit');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').onclick = () => this.updateCategory();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }

        this._setupFormListeners();
    }

    async saveCategory() {
        const form = document.getElementById('category-form');
        if (form && !form.checkValidity()) { form.reportValidity(); return; }

        const data = {
            name: document.getElementById('category-name').value.trim(),
            icon: document.getElementById('category-icon').value,
            color: document.getElementById('category-color').value,
            type: document.getElementById('category-type').value,
            description: document.getElementById('category-description').value.trim(),
            sort_order: parseInt(document.getElementById('category-sort-order').value) || 0
        };

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const result = await window.Api.post('/categories', data);
            if (result.success) {
                NotificationService.success('Category created successfully');
                if (window.modalService) window.modalService.close();
                else if (window.premiumModal) window.premiumModal.close();
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to create category');
            }
        } catch (error) {
            console.error('Failed to save category:', error);
            NotificationService.error('Failed to save category');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    async updateCategory() {
        const form = document.getElementById('category-form');
        if (form && !form.checkValidity()) { form.reportValidity(); return; }

        const id = document.getElementById('category-id').value;
        const data = {
            name: document.getElementById('category-name').value.trim(),
            icon: document.getElementById('category-icon').value,
            color: document.getElementById('category-color').value,
            type: document.getElementById('category-type').value,
            description: document.getElementById('category-description').value.trim(),
            status: document.getElementById('category-status')?.value || 'active',
            sort_order: parseInt(document.getElementById('category-sort-order').value) || 0
        };

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            const result = await window.Api.put(`/categories/${id}`, data);
            if (result.success) {
                NotificationService.success('Category updated successfully');
                if (window.modalService) window.modalService.close();
                else if (window.premiumModal) window.premiumModal.close();
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to update category');
            }
        } catch (error) {
            console.error('Failed to update category:', error);
            NotificationService.error(error.message || 'Failed to update category');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    async viewCategory(id) {
        try {
            const result = await window.Api.get(`/categories/${id}`);
            if (!result.success) return;

            const cat = result.data;
            const subcats = cat.subcategories || (cat.subcategory_count > 0 ? [] : []);
            const subList = subcats.length > 0
                ? subcats.map(s => `<span class="cat-sub-chip"><i class="bi bi-${s.icon || 'tag'}"></i>${this._esc(s.name)}</span>`).join('')
                : '<span class="text-muted small">No subcategories</span>';

            const txCount = cat.transaction_count || 0;
            const lastUsed = cat.last_used_at ? Formatters.date(cat.last_used_at) : 'Never';

            const body = `
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="cat-card-icon" style="background-color:${cat.color}18;color:${cat.color};width:56px;height:56px;border-radius:16px;font-size:1.5rem;display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-${cat.icon || 'tag'}"></i>
                    </div>
                    <div>
                        <h4 class="mb-1 fw-bold">${this._esc(cat.name)}</h4>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge ${cat.type === 'income' ? 'bg-success' : 'bg-danger'}">${cat.type}</span>
                            ${Number(cat.is_default) === 1 ? '<span class="badge bg-primary">Default</span>' : ''}
                            <span class="badge ${cat.status === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary'}">${cat.status}</span>
                        </div>
                    </div>
                </div>
                ${cat.description ? `<p class="text-muted mb-3">${this._esc(cat.description)}</p>` : ''}
                <div class="row g-3 mb-3">
                    <div class="col-4"><strong class="d-block text-muted small mb-1">Transactions</strong><span class="fw-semibold">${txCount}</span></div>
                    <div class="col-4"><strong class="d-block text-muted small mb-1">Subcategories</strong><span class="fw-semibold">${cat.subcategory_count || subcats.length || 0}</span></div>
                    <div class="col-4"><strong class="d-block text-muted small mb-1">Last Used</strong><span class="fw-semibold">${lastUsed}</span></div>
                    <div class="col-6"><strong class="d-block text-muted small mb-1">Created</strong>${Formatters.date(cat.created_at)}</div>
                    <div class="col-6"><strong class="d-block text-muted small mb-1">Sort Order</strong>${cat.sort_order || 0}</div>
                </div>
                <div><strong class="d-block text-muted small mb-2">Subcategories</strong><div class="d-flex flex-wrap gap-1">${subList}</div></div>
            `;

            if (window.modalService && typeof window.modalService.open === 'function') {
                window.modalService.open({
                    title: 'Category Details',
                    subtitle: cat.name,
                    icon: 'fa-eye',
                    bodyHTML: body,
                    showFooter: false,
                    onSave: null
                });
            } else if (window.premiumModal && typeof window.premiumModal.open === 'function') {
                window.premiumModal.setTitle('Category Details');
                window.premiumModal.setSubtitle(cat.name);
                window.premiumModal.setIcon('fa-eye');
                document.getElementById('modal-body').innerHTML = body;
                document.getElementById('modal-save-btn').style.display = 'none';
                document.getElementById('modal-cancel-btn').textContent = 'Close';
                document.getElementById('modal-draft-btn').style.display = 'none';
                document.getElementById('modal-reset-btn').style.display = 'none';
                window.premiumModal.open();
            }
        } catch (error) {
            console.error('Failed to load category:', error);
            NotificationService.error('Failed to load category details');
        }
    }

    async deleteCategory(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Category',
            text: 'Are you sure you want to delete this category and all its subcategories? This action cannot be undone.',
            confirmButtonText: 'Yes, delete it'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.delete(`/categories/${id}`);
            if (result.success) {
                NotificationService.success('Category deleted successfully');
                this.selectedCategories.delete(String(id));
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            NotificationService.error(error.message || 'Failed to delete category');
        }
    }

    async archiveCategory(id) {
        try {
            const result = await window.Api.get(`/categories/${id}/archive`);
            if (result.success) {
                NotificationService.success('Category archived successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to archive category');
            }
        } catch (error) {
            console.error('Failed to archive category:', error);
            NotificationService.error(error.message || 'Failed to archive category');
        }
    }

    async restoreCategory(id) {
        try {
            const result = await window.Api.get(`/categories/${id}/restore`);
            if (result.success) {
                NotificationService.success('Category restored successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to restore category');
            }
        } catch (error) {
            console.error('Failed to restore category:', error);
            NotificationService.error(error.message || 'Failed to restore category');
        }
    }

    async duplicateCategory(id) {
        const cat = this.categories.find(c => c.id === id);
        if (!cat) return;

        const confirmed = await NotificationService.confirm({
            title: 'Duplicate Category',
            text: `Create a copy of "${cat.name}"?`,
            confirmButtonText: 'Duplicate'
        });
        if (!confirmed) return;

        try {
            const result = await window.Api.post(`/categories/${id}/duplicate`, {});
            if (result.success) {
                NotificationService.success('Category duplicated successfully');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to duplicate category');
            }
        } catch (error) {
            console.error('Failed to duplicate category:', error);
            NotificationService.error(error.message || 'Failed to duplicate category');
        }
    }

    async inlineEditSubcategory(subId, catId) {
        const cat = this.categories.find(c => c.id === catId);
        if (!cat) return;
        const sub = (cat.subcategories || []).find(s => s.id === subId);
        if (!sub) return;

        const { value: newName } = await Swal.fire({
            title: 'Edit Subcategory',
            input: 'text',
            inputLabel: 'Subcategory Name',
            inputValue: sub.name,
            showCancelButton: true,
            confirmButtonText: 'Save',
            inputValidator: (val) => { if (!val || !val.trim()) return 'Name is required'; }
        });

        if (newName === undefined || newName.trim() === sub.name) return;

        try {
            const result = await window.Api.put(`/subcategories/${subId}`, {
                category_id: catId,
                name: newName.trim(),
                icon: sub.icon || 'tag',
                description: sub.description || '',
                status: sub.status || 'active',
                sort_order: sub.sort_order || 0
            });
            if (result.success) {
                NotificationService.success('Subcategory updated');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to update subcategory');
            }
        } catch (error) {
            console.error('Failed to update subcategory:', error);
            NotificationService.error(error.message || 'Failed to update subcategory');
        }
    }

    async inlineDeleteSubcategory(subId, catId) {
        const cat = this.categories.find(c => c.id === catId);
        const sub = cat ? (cat.subcategories || []).find(s => s.id === subId) : null;

        const confirmed = await NotificationService.confirm({
            title: 'Delete Subcategory',
            text: `Are you sure you want to delete "${sub?.name || 'this subcategory'}"? This cannot be undone.`,
            confirmButtonText: 'Yes, delete it'
        });
        if (!confirmed) return;

        try {
            const result = await window.Api.delete(`/subcategories/${subId}`);
            if (result.success) {
                NotificationService.success('Subcategory deleted');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to delete subcategory');
            }
        } catch (error) {
            console.error('Failed to delete subcategory:', error);
            NotificationService.error(error.message || 'Failed to delete subcategory');
        }
    }

    showAddSubcategoryModal(category) {
        const body = `
        <form id="subcategory-form">
            <input type="hidden" id="subcategory-category-id" value="${category.id}">
            <div class="form-group mb-3">
                <label class="form-label">Parent Category</label>
                <input type="text" class="form-control" value="${this._esc(category.name)}" disabled>
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Subcategory Name *</label>
                <input type="text" class="form-control" id="subcategory-name" required placeholder="e.g. Groceries">
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Icon</label>
                <div class="d-flex gap-2 align-items-center">
                    <div class="cat-card-icon" style="background-color:${category.color}18;color:${category.color};width:36px;height:36px;border-radius:8px;font-size:0.9rem;display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-tag" id="sub-icon-preview"></i>
                    </div>
                    <input type="text" class="form-control" id="subcategory-icon" value="tag" readonly style="flex:1">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="pick-sub-icon-btn"><i class="fas fa-icons"></i></button>
                </div>
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" id="subcategory-description" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Sort Order</label>
                <input type="number" class="form-control" id="subcategory-sort-order" value="0" min="0">
            </div>
        </form>`;

        if (window.modalService && typeof window.modalService.open === 'function') {
            window.modalService.open({
                title: 'Add Subcategory',
                subtitle: `Add to ${category.name}`,
                icon: 'fa-layer-group',
                bodyHTML: body,
                showFooter: true,
                onSave: () => this.saveSubcategory()
            });
        } else if (window.premiumModal && typeof window.premiumModal.open === 'function') {
            window.premiumModal.setTitle('Add Subcategory');
            window.premiumModal.setSubtitle(`Add to ${category.name}`);
            window.premiumModal.setIcon('fa-layer-group');
            document.getElementById('modal-body').innerHTML = body;
            document.getElementById('modal-save-btn').onclick = () => this.saveSubcategory();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }

        requestAnimationFrame(() => {
            const pickBtn = document.getElementById('pick-sub-icon-btn');
            if (pickBtn) pickBtn.addEventListener('click', () => {
                this._subIconTarget = 'subcategory-icon';
                this._subIconPreview = 'sub-icon-preview';
                this.showIconPicker();
            });
        });
    }

    async saveSubcategory() {
        const form = document.getElementById('subcategory-form');
        if (form && !form.checkValidity()) { form.reportValidity(); return; }

        const data = {
            category_id: parseInt(document.getElementById('subcategory-category-id').value),
            name: document.getElementById('subcategory-name').value.trim(),
            icon: document.getElementById('subcategory-icon').value,
            description: document.getElementById('subcategory-description').value.trim(),
            sort_order: parseInt(document.getElementById('subcategory-sort-order').value) || 0
        };

        try {
            const result = await window.Api.post('/subcategories', data);
            if (result.success) {
                NotificationService.success('Subcategory created successfully');
                if (window.modalService) window.modalService.close();
                else if (window.premiumModal) window.premiumModal.close();
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to create subcategory');
            }
        } catch (error) {
            console.error('Failed to save subcategory:', error);
            NotificationService.error('Failed to save subcategory');
        }
    }

    showIconPicker() {
        const icons = [
            'tag', 'wallet', 'credit-card', 'bank', 'piggy-bank', 'home', 'car', 'utensils',
            'shopping-bag', 'heart-pulse', 'book', 'film', 'file-text', 'plane', 'shield',
            'briefcase', 'laptop', 'building', 'trending-up', 'gift', 'heart', 'graduation-cap',
            'calendar', 'clock', 'lightbulb', 'pen', 'palette', 'code', 'box', 'cog',
            'percent', 'graph-up', 'house', 'cart', 'capsule', 'activity', 'mortarboard',
            'people', 'lightning', 'droplet', 'wifi', 'telephone', 'recycle', 'airplane',
            'camera', 'controller', 'music-note', 'calendar-event'
        ];

        const targetId = this._subIconTarget || 'category-icon';
        const previewId = this._subIconPreview || 'icon-preview';

        const grid = icons.map(icon => `
            <button type="button" class="btn btn-outline-secondary btn-sm icon-option" data-icon="${icon}" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:8px">
                <i class="bi bi-${icon}"></i>
            </button>
        `).join('');

        const html = `
            <div>
                <input type="text" class="form-control mb-3" id="icon-search-input" placeholder="Search icons...">
                <div class="d-flex flex-wrap gap-1" style="max-height:300px;overflow-y:auto">${grid}</div>
            </div>
        `;

        Swal.fire({
            title: 'Select Icon',
            html: html,
            width: '550px',
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: () => {
                const searchInput = document.getElementById('icon-search-input');
                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        const q = e.target.value.toLowerCase();
                        Swal.getPopup().querySelectorAll('.icon-option').forEach(btn => {
                            const icon = btn.dataset.icon;
                            btn.style.display = icon.includes(q) ? 'inline-flex' : 'none';
                        });
                    });
                }

                Swal.getPopup().querySelectorAll('.icon-option').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const icon = btn.dataset.icon;
                        const input = document.getElementById(targetId);
                        if (input) input.value = icon;
                        const preview = document.getElementById(previewId);
                        if (preview) preview.className = `bi bi-${icon}`;
                        this._subIconTarget = null;
                        this._subIconPreview = null;
                        Swal.close();
                    });
                });

            }
        });
    }

    showImportModal() {
        const body = `
            <div class="text-center py-4">
                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                <h5>Import Categories</h5>
                <p class="text-muted mb-3">Upload a CSV file with columns: Name, Type, Icon, Color, Description</p>
                <input type="file" id="category-import-file" accept=".csv,.xlsx,.xls" class="form-control">
            </div>`;

        if (window.modalService && typeof window.modalService.open === 'function') {
            window.modalService.open({
                title: 'Import Categories',
                subtitle: 'Import from CSV or Excel',
                icon: 'fa-file-import',
                bodyHTML: body,
                showFooter: true,
                onSave: () => NotificationService.info('Import functionality coming soon')
            });
        } else if (window.premiumModal && typeof window.premiumModal.open === 'function') {
            window.premiumModal.setTitle('Import Categories');
            window.premiumModal.setIcon('fa-file-import');
            document.getElementById('modal-body').innerHTML = body;
            document.getElementById('modal-save-btn').onclick = () => NotificationService.info('Import functionality coming soon');
            window.premiumModal.open();
        }
    }

    async exportCategories() {
        try {
            const result = await window.Api.get('/categories');
            if (result.success) {
                const escCSV = val => `"${String(val || '').replace(/"/g, '""')}"`;
                const headers = ['Name', 'Type', 'Icon', 'Color', 'Description', 'Status', 'Sort Order', 'Subcategories'];
                const rows = result.data.map(cat => [
                    cat.name, cat.type, cat.icon, cat.color,
                    cat.description || '', cat.status, cat.sort_order || 0,
                    (cat.subcategories || []).map(s => s.name).join('; ')
                ].map(escCSV));
                const csv = '\uFEFF' + [headers.map(escCSV), ...rows].map(r => r.join(',')).join('\r\n');
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'categories.csv';
                a.click();
                URL.revokeObjectURL(url);
                NotificationService.success('Categories exported successfully');
            }
        } catch (error) {
            console.error('Export failed:', error);
            NotificationService.error('Failed to export categories');
        }
    }

    _esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
}

window.categoriesManager = null;
window.CategoriesManager = CategoriesManager;
