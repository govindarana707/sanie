// Categories Management Module - Refactored with DataTableService and lifecycle hooks
class CategoriesManager {
    constructor() {
        if (!window.APP_CONFIG?.API_BASE || !window.Api) {
            console.error('API configuration missing.');
            return;
        }

        this.categories = [];
        this.selectedCategories = new Set();
        this.dataTable = null;
        this.isLoadingCategories = false;
        this.isLoadingStatistics = false;
        this._mounted = false;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        this.loadCategories();
        this.loadStatistics();
    }

    onUnmount() {
        this._mounted = false;
        if (window.DataTableService) {
            DataTableService.destroy('#categories-table');
        }
        this.dataTable = null;
    }

    setupEventListeners() {
        document.getElementById('add-category-btn')?.addEventListener('click', () => {
            this.showAddCategoryModal();
        });

        document.getElementById('apply-category-filters')?.addEventListener('click', () => {
            this.applyFilters();
        });

        document.getElementById('clear-category-filters')?.addEventListener('click', () => {
            this.clearFilters();
        });

        document.getElementById('search-categories')?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value);
        });

        document.getElementById('select-all-categories')?.addEventListener('change', (e) => {
            this.toggleSelectAll(e.target.checked);
        });

        document.querySelectorAll('#category-bulk-actions button').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handleBulkAction(btn.dataset.action);
            });
        });

        document.getElementById('import-categories-btn')?.addEventListener('click', () => {
            this.showImportModal();
        });

        document.getElementById('export-categories-btn')?.addEventListener('click', () => {
            this.exportCategories();
        });
    }

    async loadCategories() {
        if (this.isLoadingCategories) return;
        this.isLoadingCategories = true;
        try {
            const type = document.getElementById('filter-category-type')?.value;
            const status = document.getElementById('filter-category-status')?.value || 'active';

            const result = await window.Api.get(`/categories?${new URLSearchParams({ type: type || '', status })}`);

            if (result.success) {
                this.categories = result.data;
                this.renderCategories();
                this.initializeDataTable();
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
            NotificationService.error('Failed to load categories');
        } finally {
            this.isLoadingCategories = false;
        }
    }

    async loadStatistics() {
        if (this.isLoadingStatistics) return;
        this.isLoadingStatistics = true;
        try {
            const result = await window.Api.get('/categories/statistics');

            if (result.success) {
                this.updateStatistics(result.data);
            }
        } catch (error) {
            console.error('Failed to load statistics:', error);
        } finally {
            this.isLoadingStatistics = false;
        }
    }

    updateStatistics(data) {
        const animateValue = (element, value) => {
            if (element && window.CountUp) {
                const countUp = new CountUp(element, value, { duration: 1 });
                countUp.start();
            } else if (element) {
                element.textContent = value;
            }
        };

        animateValue(document.getElementById('stat-total-categories'), data.categories.total || 0);
        animateValue(document.getElementById('stat-income-categories'), data.categories.income_count || 0);
        animateValue(document.getElementById('stat-expense-categories'), data.categories.expense_count || 0);
        animateValue(document.getElementById('stat-total-subcategories'), data.subcategories.total || 0);
        animateValue(document.getElementById('stat-inactive-categories'), data.categories.inactive_count || 0);
    }

    renderCategories() {
        const tbody = document.getElementById('categories-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (this.categories.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <div class="empty-state">
                            <i class="fas fa-tags fa-3x mb-3"></i>
                            <h4>No Categories Found</h4>
                            <p>Create your first category to get started.</p>
                            <button class="btn btn-primary" onclick="categoriesManager.showAddCategoryModal()">
                                <i class="fas fa-plus"></i> Add Category
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        this.categories.forEach(category => {
            const row = document.createElement('tr');
            row.dataset.id = category.id;
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="category-checkbox" value="${category.id}"
                        ${category.is_default ? 'disabled' : ''}>
                </td>
                <td>
                    <div class="category-icon" style="background-color: ${category.color}20; color: ${category.color}">
                        <i class="bi bi-${category.icon}"></i>
                    </div>
                </td>
                <td>
                    <div class="category-name">
                        <strong>${Formatters.escapeHTML(category.name)}</strong>
                        ${category.is_default ? '<span class="badge bg-primary ms-2">Default</span>' : ''}
                        ${category.description ? `<small class="text-muted d-block">${Formatters.escapeHTML(category.description)}</small>` : ''}
                    </div>
                </td>
                <td>
                    <span class="badge ${category.type === 'income' ? 'bg-success' : 'bg-danger'}">
                        ${category.type.charAt(0).toUpperCase() + category.type.slice(1)}
                    </span>
                </td>
                <td>
                    <div class="color-preview" style="background-color: ${category.color}"></div>
                </td>
                <td>
                    <span class="subcategory-count">${category.subcategory_count || 0}</span>
                </td>
                <td>
                    <span class="badge ${this.getStatusBadgeClass(category.status)}">
                        ${category.status.charAt(0).toUpperCase() + category.status.slice(1)}
                    </span>
                </td>
                <td>
                    <small>${Formatters.date(category.created_at)}</small>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-outline-primary" onclick="categoriesManager.viewCategory(${category.id})" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${!category.is_default ? `
                            <button class="btn btn-sm btn-outline-secondary" onclick="categoriesManager.editCategory(${category.id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning" onclick="categoriesManager.archiveCategory(${category.id})" title="Archive">
                                <i class="fas fa-archive"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="categoriesManager.deleteCategory(${category.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            `;
            tbody.appendChild(row);

            const checkbox = row.querySelector('.category-checkbox');
            checkbox.addEventListener('change', () => this.handleSelectionChange());
        });
    }

    getStatusBadgeClass(status) {
        switch (status) {
            case 'active': return 'bg-success';
            case 'archived': return 'bg-warning';
            case 'deleted': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    initializeDataTable() {
        if (window.DataTableService) {
            this.dataTable = DataTableService.init('#categories-table', {
                responsive: true,
                pageLength: 25,
                order: [[7, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [0, 8] },
                    { responsivePriority: 1, targets: [2, 3] },
                    { responsivePriority: 2, targets: [1, 4] }
                ],
                language: {
                    search: '_INPUT_',
                    searchPlaceholder: 'Search categories...'
                }
            });
        } else if (window.jQuery) {
            this.dataTable = jQuery('#categories-table').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[7, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [0, 8] }
                ]
            });
        }
    }

    handleSelectionChange() {
        const checkboxes = document.querySelectorAll('.category-checkbox:checked');
        this.selectedCategories = new Set(Array.from(checkboxes).map(cb => cb.value));

        const countEl = document.getElementById('selected-categories-count');
        if (countEl) countEl.textContent = this.selectedCategories.size;

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

        const confirmMessage = {
            delete: 'Are you sure you want to delete the selected categories?',
            archive: 'Are you sure you want to archive the selected categories?',
            restore: 'Are you sure you want to restore the selected categories?',
            activate: 'Are you sure you want to activate the selected categories?'
        }[action];

        const confirmed = await NotificationService.confirm({
            title: 'Confirm Action',
            text: confirmMessage,
            confirmButtonText: 'Yes, proceed'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.post('/categories/bulk', {
                action,
                ids: Array.from(this.selectedCategories)
            });

            if (result.success) {
                NotificationService.success('Bulk action completed successfully');
                this.selectedCategories.clear();
                this.handleSelectionChange();
                this.loadCategories();
                this.loadStatistics();
            } else {
                NotificationService.error(result.message || 'Bulk action failed');
            }
        } catch (error) {
            console.error('Bulk action failed:', error);
            NotificationService.error('Bulk action failed');
        }
    }

    showAddCategoryModal() {
        const modalBody = `
            <form id="category-form">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="category-name" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Type *</label>
                            <select class="form-control" id="category-type" required>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Icon</label>
                            <div class="icon-picker-wrapper">
                                <input type="text" class="form-control" id="category-icon" value="tag" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="categoriesManager.showIconPicker()">
                                    <i class="fas fa-icons"></i>
                                </button>
                            </div>
                            <div class="icon-preview mt-2">
                                <i class="bi bi-tag" id="icon-preview"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" class="form-control form-control-color" id="category-color" value="#10B981">
                                <div class="color-presets">
                                    <button type="button" class="color-preset" data-color="#10B981" style="background-color: #10B981"></button>
                                    <button type="button" class="color-preset" data-color="#EF4444" style="background-color: #EF4444"></button>
                                    <button type="button" class="color-preset" data-color="#3B82F6" style="background-color: #3B82F6"></button>
                                    <button type="button" class="color-preset" data-color="#F59E0B" style="background-color: #F59E0B"></button>
                                    <button type="button" class="color-preset" data-color="#8B5CF6" style="background-color: #8B5CF6"></button>
                                    <button type="button" class="color-preset" data-color="#EC4899" style="background-color: #EC4899"></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="category-description" rows="3"></textarea>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-control" id="category-sort-order" value="0" min="0">
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Category',
                subtitle: 'Create a new category for your transactions',
                icon: 'fa-tags',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.saveCategory()
            });
        } else if (window.premiumModal) {
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

        setTimeout(() => {
            document.querySelectorAll('.color-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('category-color').value = btn.dataset.color;
                });
            });

            const typeSelect = document.getElementById('category-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', (e) => {
                    const defaultColor = e.target.value === 'income' ? '#10B981' : '#EF4444';
                    document.getElementById('category-color').value = defaultColor;
                });
            }
        }, 50);
    }

    showIconPicker() {
        const icons = [
            'tag', 'wallet', 'credit-card', 'bank', 'piggy-bank', 'home', 'car', 'utensils',
            'shopping-bag', 'heart-pulse', 'book', 'film', 'file-text', 'plane', 'shield',
            'briefcase', 'laptop', 'building', 'trending-up', 'gift', 'heart', 'graduation-cap',
            'more-horizontal', 'calendar', 'clock', 'lightbulb', 'pen', 'palette', 'code',
            'box', 'cog', 'percent', 'graph-up', 'currency-bitcoin', 'house', 'cart',
            'capsule', 'activity', 'mortarboard', 'people', 'lightning', 'droplet', 'wifi',
            'telephone', 'recycle', 'airplane', 'camera', 'controller', 'music-note', 'calendar-event'
        ];

        const iconGrid = icons.map(icon => `
            <button type="button" class="icon-option" data-icon="${icon}" onclick="categoriesManager.selectIcon('${icon}')">
                <i class="bi bi-${icon}"></i>
            </button>
        `).join('');

        const iconPickerHTML = `
            <div class="icon-picker-modal">
                <div class="icon-search mb-3">
                    <input type="text" class="form-control" id="icon-search" placeholder="Search icons..." oninput="categoriesManager.filterIcons(this.value)">
                </div>
                <div class="icon-grid" id="icon-grid">
                    ${iconGrid}
                </div>
            </div>
        `;

        Swal.fire({
            title: 'Select Icon',
            html: iconPickerHTML,
            width: '600px',
            showConfirmButton: false,
            showCloseButton: true
        });
    }

    selectIcon(icon) {
        document.getElementById('category-icon').value = icon;
        document.getElementById('icon-preview').className = `bi bi-${icon}`;
        Swal.close();
    }

    filterIcons(query) {
        const buttons = document.querySelectorAll('.icon-option');
        buttons.forEach(btn => {
            const icon = btn.dataset.icon;
            btn.style.display = icon.includes(query.toLowerCase()) ? 'inline-flex' : 'none';
        });
    }

    async saveCategory() {
        const form = document.getElementById('category-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const categoryData = {
            name: document.getElementById('category-name').value,
            icon: document.getElementById('category-icon').value,
            color: document.getElementById('category-color').value,
            type: document.getElementById('category-type').value,
            description: document.getElementById('category-description').value,
            sort_order: parseInt(document.getElementById('category-sort-order').value) || 0
        };

        try {
            const result = await window.Api.post('/categories', categoryData);

            if (result.success) {
                NotificationService.success('Category created successfully');
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                this.loadCategories();
                this.loadStatistics();
            } else {
                NotificationService.error(result.message || 'Failed to create category');
            }
        } catch (error) {
            console.error('Failed to save category:', error);
            NotificationService.error('Failed to save category');
        }
    }

    async editCategory(id) {
        const category = this.categories.find(c => c.id === id);
        if (!category) return;

        const modalBody = `
            <form id="category-form">
                <input type="hidden" id="category-id" value="${category.id}">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="category-name" value="${Formatters.escapeHTML(category.name)}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Type *</label>
                            <select class="form-control" id="category-type" required>
                                <option value="income" ${category.type === 'income' ? 'selected' : ''}>Income</option>
                                <option value="expense" ${category.type === 'expense' ? 'selected' : ''}>Expense</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Icon</label>
                            <div class="icon-picker-wrapper">
                                <input type="text" class="form-control" id="category-icon" value="${category.icon}" readonly>
                                <button type="button" class="btn btn-outline-secondary" onclick="categoriesManager.showIconPicker()">
                                    <i class="fas fa-icons"></i>
                                </button>
                            </div>
                            <div class="icon-preview mt-2">
                                <i class="bi bi-${category.icon}" id="icon-preview"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label class="form-label">Color</label>
                            <div class="color-picker-wrapper">
                                <input type="color" class="form-control form-control-color" id="category-color" value="${category.color}">
                                <div class="color-presets">
                                    <button type="button" class="color-preset" data-color="#10B981" style="background-color: #10B981"></button>
                                    <button type="button" class="color-preset" data-color="#EF4444" style="background-color: #EF4444"></button>
                                    <button type="button" class="color-preset" data-color="#3B82F6" style="background-color: #3B82F6"></button>
                                    <button type="button" class="color-preset" data-color="#F59E0B" style="background-color: #F59E0B"></button>
                                    <button type="button" class="color-preset" data-color="#8B5CF6" style="background-color: #8B5CF6"></button>
                                    <button type="button" class="color-preset" data-color="#EC4899" style="background-color: #EC4899"></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="category-description" rows="3">${Formatters.escapeHTML(category.description || '')}</textarea>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-control" id="category-status">
                        <option value="active" ${category.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="archived" ${category.status === 'archived' ? 'selected' : ''}>Archived</option>
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-control" id="category-sort-order" value="${category.sort_order || 0}" min="0">
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Edit Category',
                subtitle: 'Update category details',
                icon: 'fa-edit',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.updateCategory()
            });
        } else if (window.premiumModal) {
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

        setTimeout(() => {
            document.querySelectorAll('.color-preset').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('category-color').value = btn.dataset.color;
                });
            });
        }, 50);
    }

    async updateCategory() {
        const form = document.getElementById('category-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const id = document.getElementById('category-id').value;
        const categoryData = {
            name: document.getElementById('category-name').value,
            icon: document.getElementById('category-icon').value,
            color: document.getElementById('category-color').value,
            type: document.getElementById('category-type').value,
            description: document.getElementById('category-description').value,
            status: document.getElementById('category-status').value,
            sort_order: parseInt(document.getElementById('category-sort-order').value) || 0
        };

        try {
            const result = await window.Api.put(`/categories/${id}`, categoryData);

            if (result.success) {
                NotificationService.success('Category updated successfully');
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                this.loadCategories();
                this.loadStatistics();
            } else {
                NotificationService.error(result.message || 'Failed to update category');
            }
        } catch (error) {
            console.error('Failed to update category:', error);
            NotificationService.error('Failed to update category');
        }
    }

    async viewCategory(id) {
        try {
            const result = await window.Api.get(`/categories/${id}`);

            if (result.success) {
                const category = result.data;
                const modalBody = `
                    <div class="category-details">
                        <div class="category-header d-flex align-items-center mb-4">
                            <div class="category-icon-lg me-3" style="background-color: ${category.color}20; color: ${category.color}">
                                <i class="bi bi-${category.icon} fa-2x"></i>
                            </div>
                            <div>
                                <h4 class="mb-1">${Formatters.escapeHTML(category.name)}</h4>
                                <span class="badge ${category.type === 'income' ? 'bg-success' : 'bg-danger'}">
                                    ${category.type.charAt(0).toUpperCase() + category.type.slice(1)}
                                </span>
                                ${category.is_default ? '<span class="badge bg-primary ms-2">Default</span>' : ''}
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Subcategories</label>
                                    <p>${category.subcategory_count || 0}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Status</label>
                                    <p>${category.status.charAt(0).toUpperCase() + category.status.slice(1)}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Created Date</label>
                                    <p>${Formatters.date(category.created_at)}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Has Transactions</label>
                                    <p>${category.has_transactions ? 'Yes' : 'No'}</p>
                                </div>
                            </div>
                        </div>
                        ${category.description ? `
                            <div class="detail-item mt-3">
                                <label>Description</label>
                                <p>${Formatters.escapeHTML(category.description)}</p>
                            </div>
                        ` : ''}
                    </div>
                `;

                if (window.ModalService) {
                    window.modalService.open({
                        title: 'Category Details',
                        subtitle: 'View category information',
                        icon: 'fa-eye',
                        bodyHTML: modalBody,
                        showFooter: false,
                        onSave: null
                    });
                    const saveBtn = document.getElementById('modal-save-btn');
                    if (saveBtn) saveBtn.style.display = 'none';
                } else if (window.premiumModal) {
                    window.premiumModal.setTitle('Category Details');
                    window.premiumModal.setSubtitle('View category information');
                    window.premiumModal.setIcon('fa-eye');
                    document.getElementById('modal-body').innerHTML = modalBody;
                    document.getElementById('modal-save-btn').style.display = 'none';
                    document.getElementById('modal-cancel-btn').textContent = 'Close';
                    document.getElementById('modal-draft-btn').style.display = 'none';
                    document.getElementById('modal-reset-btn').style.display = 'none';
                    window.premiumModal.open();
                }
            }
        } catch (error) {
            console.error('Failed to load category:', error);
            NotificationService.error('Failed to load category details');
        }
    }

    async deleteCategory(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Category',
            text: 'Are you sure you want to delete this category? This action cannot be undone.',
            confirmButtonText: 'Yes, delete it'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.delete(`/categories/${id}`);

            if (result.success) {
                NotificationService.success('Category deleted successfully');
                this.loadCategories();
                this.loadStatistics();
            } else {
                NotificationService.error(result.message || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            NotificationService.error('Failed to delete category');
        }
    }

    async archiveCategory(id) {
        try {
            const result = await window.Api.get(`/categories/${id}/archive`);

            if (result.success) {
                NotificationService.success('Category archived successfully');
                this.loadCategories();
                this.loadStatistics();
            } else {
                NotificationService.error(result.message || 'Failed to archive category');
            }
        } catch (error) {
            console.error('Failed to archive category:', error);
            NotificationService.error('Failed to archive category');
        }
    }

    applyFilters() {
        this.loadCategories();
    }

    clearFilters() {
        document.getElementById('filter-category-type').value = '';
        document.getElementById('filter-category-status').value = 'active';
        document.getElementById('filter-category-default').value = '';
        document.getElementById('search-categories').value = '';
        this.loadCategories();
    }

    async handleSearch(query) {
        if (query.length < 2) {
            this.loadCategories();
            return;
        }

        try {
            const type = document.getElementById('filter-category-type')?.value;
            const status = document.getElementById('filter-category-status')?.value || 'active';

            const params = new URLSearchParams({ q: query, type: type || '', status });
            const result = await window.Api.get(`/categories/search?${params}`);

            if (result.success) {
                this.categories = result.data;
                this.renderCategories();
                if (this.dataTable) {
                    this.dataTable.clear();
                    this.dataTable.rows.add(this.categories);
                    this.dataTable.draw();
                }
            }
        } catch (error) {
            console.error('Search failed:', error);
        }
    }

    showImportModal() {
        const modalBody = `
            <div class="import-section">
                <div class="upload-area" id="category-upload-area">
                    <i class="fas fa-cloud-upload-alt fa-3x mb-3"></i>
                    <h5>Drag & Drop CSV/Excel File</h5>
                    <p class="text-muted">or click to browse</p>
                    <input type="file" id="category-import-file" accept=".csv,.xlsx,.xls" style="display: none">
                    <button class="btn btn-primary" onclick="document.getElementById('category-import-file').click()">
                        Browse Files
                    </button>
                </div>
                <div class="import-preview mt-4" id="category-import-preview" style="display: none;">
                    <h6>Preview</h6>
                    <div id="category-preview-table"></div>
                </div>
            </div>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Import Categories',
                subtitle: 'Import categories from CSV or Excel',
                icon: 'fa-file-import',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.importCategories()
            });
        } else if (window.premiumModal) {
            window.premiumModal.setTitle('Import Categories');
            window.premiumModal.setSubtitle('Import categories from CSV or Excel');
            window.premiumModal.setIcon('fa-file-import');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').textContent = 'Import';
            document.getElementById('modal-save-btn').onclick = () => this.importCategories();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }
    }

    async importCategories() {
        NotificationService.info('Import functionality coming soon');
    }

    async exportCategories() {
        try {
            const result = await window.Api.get('/categories');

            if (result.success) {
                const csv = this.convertToCSV(result.data);
                this.downloadCSV(csv, 'categories.csv');
                NotificationService.success('Categories exported successfully');
            }
        } catch (error) {
            console.error('Export failed:', error);
            NotificationService.error('Failed to export categories');
        }
    }

    convertToCSV(data) {
        const headers = ['Name', 'Type', 'Icon', 'Color', 'Description', 'Status', 'Sort Order'];
        const rows = data.map(cat => [
            cat.name,
            cat.type,
            cat.icon,
            cat.color,
            cat.description || '',
            cat.status,
            cat.sort_order
        ]);

        return [headers, ...rows].map(row => row.join(',')).join('\n');
    }

    downloadCSV(csv, filename) {
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        window.URL.revokeObjectURL(url);
    }
}

window.categoriesManager = null;
window.CategoriesManager = CategoriesManager;
