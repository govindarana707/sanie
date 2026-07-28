// Subcategories Management Module - Refactored with DataTableService and lifecycle hooks
class SubcategoriesManager {
    constructor() {
        if (!window.APP_CONFIG?.API_BASE || !window.Api) {
            console.error('API configuration missing.');
            return;
        }

        this.subcategories = [];
        this.categories = [];
        this.selectedSubcategories = new Set();
        this.dataTable = null;
        this.isLoadingCategories = false;
        this.isLoadingSubcategories = false;
        this._mounted = false;
        this._listeners = {};
        this._bulkListeners = [];
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        this.loadCategories();
        this.loadSubcategories();
    }

    onUnmount() {
        this._mounted = false;
        if (this._listeners.addClick) {
            document.getElementById('add-subcategory-btn')?.removeEventListener('click', this._listeners.addClick);
        }
        if (this._listeners.applyClick) {
            document.getElementById('apply-subcategory-filters')?.removeEventListener('click', this._listeners.applyClick);
        }
        if (this._listeners.clearClick) {
            document.getElementById('clear-subcategory-filters')?.removeEventListener('click', this._listeners.clearClick);
        }
        if (this._listeners.searchInput) {
            document.getElementById('search-subcategories')?.removeEventListener('input', this._listeners.searchInput);
        }
        if (this._listeners.selectAllChange) {
            document.getElementById('select-all-subcategories')?.removeEventListener('change', this._listeners.selectAllChange);
        }
        this._bulkListeners.forEach(({ el, handler }) => el.removeEventListener('click', handler));
        this._bulkListeners = [];
        if (this._listeners.importClick) {
            document.getElementById('import-subcategories-btn')?.removeEventListener('click', this._listeners.importClick);
        }
        if (this._listeners.exportClick) {
            document.getElementById('export-subcategories-btn')?.removeEventListener('click', this._listeners.exportClick);
        }
        if (window.DataTableService) {
            DataTableService.destroy('#subcategories-table');
        }
        this.dataTable = null;
    }

    setupEventListeners() {
        const addBtn = document.getElementById('add-subcategory-btn');
        if (addBtn) {
            this._listeners.addClick = () => this.showAddSubcategoryModal();
            addBtn.addEventListener('click', this._listeners.addClick);
        }

        const applyBtn = document.getElementById('apply-subcategory-filters');
        if (applyBtn) {
            this._listeners.applyClick = () => this.applyFilters();
            applyBtn.addEventListener('click', this._listeners.applyClick);
        }

        const clearBtn = document.getElementById('clear-subcategory-filters');
        if (clearBtn) {
            this._listeners.clearClick = () => this.clearFilters();
            clearBtn.addEventListener('click', this._listeners.clearClick);
        }

        const searchInput = document.getElementById('search-subcategories');
        if (searchInput) {
            this._listeners.searchInput = (e) => this.handleSearch(e.target.value);
            searchInput.addEventListener('input', this._listeners.searchInput);
        }

        const selectAll = document.getElementById('select-all-subcategories');
        if (selectAll) {
            this._listeners.selectAllChange = (e) => this.toggleSelectAll(e.target.checked);
            selectAll.addEventListener('change', this._listeners.selectAllChange);
        }

        document.querySelectorAll('#subcategory-bulk-actions button').forEach(btn => {
            const handler = () => this.handleBulkAction(btn.dataset.action);
            this._bulkListeners.push({ el: btn, handler });
            btn.addEventListener('click', handler);
        });

        const importBtn = document.getElementById('import-subcategories-btn');
        if (importBtn) {
            this._listeners.importClick = () => this.showImportModal();
            importBtn.addEventListener('click', this._listeners.importClick);
        }

        const exportBtn = document.getElementById('export-subcategories-btn');
        if (exportBtn) {
            this._listeners.exportClick = () => this.exportSubcategories();
            exportBtn.addEventListener('click', this._listeners.exportClick);
        }
    }

    async loadCategories() {
        if (this.isLoadingCategories) return;
        this.isLoadingCategories = true;
        try {
            const result = await window.Api.get('/categories?status=active');

            if (result.success) {
                this.categories = result.data;
                this.populateCategoryFilter();
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        } finally {
            this.isLoadingCategories = false;
        }
    }

    populateCategoryFilter() {
        const select = document.getElementById('filter-subcategory-category');
        if (!select) return;

        select.innerHTML = '<option value="">All Categories</option>';
        this.categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            select.appendChild(option);
        });
    }

    async loadSubcategories() {
        if (this.isLoadingSubcategories) return;
        this.isLoadingSubcategories = true;
        try {
            const categoryId = document.getElementById('filter-subcategory-category')?.value;
            const status = document.getElementById('filter-subcategory-status')?.value || 'active';

            const params = new URLSearchParams({ status });
            if (categoryId) params.set('category_id', categoryId);
            const result = await window.Api.get(`/subcategories?${params}`);

            if (result.success) {
                this.subcategories = result.data;
                this.renderSubcategories();
                this.initializeDataTable();
            }
        } catch (error) {
            console.error('Failed to load subcategories:', error);
            NotificationService.error('Failed to load subcategories');
        } finally {
            this.isLoadingSubcategories = false;
        }
    }

    renderSubcategories() {
        const tbody = document.getElementById('subcategories-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (this.subcategories.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="empty-state">
                            <i class="fas fa-layer-group fa-3x mb-3"></i>
                            <h4>No Subcategories Found</h4>
                            <p>Create your first subcategory to get started.</p>
                            <button class="btn btn-primary" onclick="subcategoriesManager.showAddSubcategoryModal()">
                                <i class="fas fa-plus"></i> Add Subcategory
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        this.subcategories.forEach(subcategory => {
            const row = document.createElement('tr');
            row.dataset.id = subcategory.id;
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="subcategory-checkbox" value="${subcategory.id}">
                </td>
                <td>
                    <div class="category-name">
                        <strong>${Formatters.escapeHTML(subcategory.category_name || 'Unknown')}</strong>
                        <span class="badge ${subcategory.category_type === 'income' ? 'bg-success' : 'bg-danger'} ms-2">
                            ${subcategory.category_type || 'N/A'}
                        </span>
                    </div>
                </td>
                <td>
                    <div class="subcategory-name">
                        <strong>${Formatters.escapeHTML(subcategory.name)}</strong>
                        ${subcategory.description ? `<small class="text-muted d-block">${Formatters.escapeHTML(subcategory.description)}</small>` : ''}
                    </div>
                </td>
                <td>
                    <div class="category-icon" style="background-color: ${subcategory.category_color}20; color: ${subcategory.category_color}">
                        <i class="bi bi-${subcategory.icon || 'tag'}"></i>
                    </div>
                </td>
                <td>
                    <span class="badge ${this.getStatusBadgeClass(subcategory.status)}">
                        ${subcategory.status.charAt(0).toUpperCase() + subcategory.status.slice(1)}
                    </span>
                </td>
                <td>
                    <small>${Formatters.date(subcategory.created_at)}</small>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-outline-primary" onclick="subcategoriesManager.viewSubcategory(${subcategory.id})" title="View">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="subcategoriesManager.editSubcategory(${subcategory.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="subcategoriesManager.archiveSubcategory(${subcategory.id})" title="Archive">
                            <i class="fas fa-archive"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="subcategoriesManager.deleteSubcategory(${subcategory.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(row);

            const checkbox = row.querySelector('.subcategory-checkbox');
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
            this.dataTable = DataTableService.init('#subcategories-table', {
                responsive: true,
                pageLength: 25,
                order: [[5, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [0, 6] },
                    { responsivePriority: 1, targets: [2, 1] },
                    { responsivePriority: 2, targets: [3, 4] }
                ],
                language: {
                    search: '_INPUT_',
                    searchPlaceholder: 'Search subcategories...'
                }
            });
        } else if (window.jQuery) {
            this.dataTable = jQuery('#subcategories-table').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[5, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [0, 6] }
                ]
            });
        }
    }

    handleSelectionChange() {
        const checkboxes = document.querySelectorAll('.subcategory-checkbox:checked');
        this.selectedSubcategories = new Set(Array.from(checkboxes).map(cb => cb.value));

        const countEl = document.getElementById('selected-subcategories-count');
        if (countEl) countEl.textContent = this.selectedSubcategories.size;

        const bulkEl = document.getElementById('subcategory-bulk-actions');
        if (bulkEl) bulkEl.style.display = this.selectedSubcategories.size > 0 ? 'flex' : 'none';
    }

    toggleSelectAll(checked) {
        document.querySelectorAll('.subcategory-checkbox').forEach(cb => {
            cb.checked = checked;
        });
        this.handleSelectionChange();
    }

    async handleBulkAction(action) {
        if (this.selectedSubcategories.size === 0) return;

        const confirmMessage = {
            delete: 'Are you sure you want to delete the selected subcategories?',
            archive: 'Are you sure you want to archive the selected subcategories?',
            restore: 'Are you sure you want to restore the selected subcategories?',
            activate: 'Are you sure you want to activate the selected subcategories?'
        }[action];

        const confirmed = await NotificationService.confirm({
            title: 'Confirm Action',
            text: confirmMessage,
            confirmButtonText: 'Yes, proceed'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.post('/subcategories/bulk', {
                action,
                ids: Array.from(this.selectedSubcategories)
            });

            if (result.success) {
                NotificationService.success('Bulk action completed successfully');
                this.selectedSubcategories.clear();
                this.handleSelectionChange();
                this.loadSubcategories();
            } else {
                NotificationService.error(result.message || 'Bulk action failed');
            }
        } catch (error) {
            console.error('Bulk action failed:', error);
            NotificationService.error('Bulk action failed');
        }
    }

    showAddSubcategoryModal() {
        if (this.categories.length === 0) {
            NotificationService.warning('Please create categories first');
            return;
        }

        const categoryOptions = this.categories.map(cat =>
            `<option value="${cat.id}">${Formatters.escapeHTML(cat.name)}</option>`
        ).join('');

        const modalBody = `
            <form id="subcategory-form">
                <div class="form-group mb-3">
                    <label class="form-label">Parent Category *</label>
                    <select class="form-control" id="subcategory-category-id" required>
                        ${categoryOptions}
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Subcategory Name *</label>
                    <input type="text" class="form-control" id="subcategory-name" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Icon</label>
                    <div class="icon-picker-wrapper">
                        <input type="text" class="form-control" id="subcategory-icon" value="tag" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="subcategoriesManager.showIconPicker()">
                            <i class="fas fa-icons"></i>
                        </button>
                    </div>
                    <div class="icon-preview mt-2">
                        <i class="bi bi-tag" id="icon-preview"></i>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="subcategory-description" rows="3"></textarea>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-control" id="subcategory-sort-order" value="0" min="0">
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Add Subcategory',
                subtitle: 'Create a new subcategory',
                icon: 'fa-layer-group',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.saveSubcategory()
            });
        } else if (window.premiumModal) {
            window.premiumModal.setTitle('Add Subcategory');
            window.premiumModal.setSubtitle('Create a new subcategory');
            window.premiumModal.setIcon('fa-layer-group');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').onclick = () => this.saveSubcategory();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }
    }

    showIconPicker() {
        const icons = [
            'tag', 'wallet', 'credit-card', 'bank', 'home', 'car', 'utensils',
            'shopping-bag', 'heart-pulse', 'book', 'film', 'file-text', 'plane',
            'briefcase', 'laptop', 'building', 'gift', 'heart', 'calendar',
            'clock', 'lightbulb', 'pen', 'palette', 'code', 'box', 'cog',
            'percent', 'graph-up', 'house', 'cart', 'capsule', 'activity',
            'mortarboard', 'people', 'lightning', 'droplet', 'wifi', 'telephone'
        ];

        const iconGrid = icons.map(icon => `
            <button type="button" class="icon-option" data-icon="${icon}" onclick="subcategoriesManager.selectIcon('${icon}')">
                <i class="bi bi-${icon}"></i>
            </button>
        `).join('');

        const iconPickerHTML = `
            <div class="icon-picker-modal">
                <div class="icon-search mb-3">
                    <input type="text" class="form-control" id="icon-search" placeholder="Search icons..." oninput="subcategoriesManager.filterIcons(this.value)">
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
        document.getElementById('subcategory-icon').value = icon;
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

    async saveSubcategory() {
        const form = document.getElementById('subcategory-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const subcategoryData = {
            category_id: parseInt(document.getElementById('subcategory-category-id').value),
            name: document.getElementById('subcategory-name').value,
            icon: document.getElementById('subcategory-icon').value,
            description: document.getElementById('subcategory-description').value,
            sort_order: parseInt(document.getElementById('subcategory-sort-order').value) || 0
        };

        try {
            const result = await window.Api.post('/subcategories', subcategoryData);

            if (result.success) {
                NotificationService.success('Subcategory created successfully');
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                this.loadSubcategories();
            } else {
                NotificationService.error(result.message || 'Failed to create subcategory');
            }
        } catch (error) {
            console.error('Failed to save subcategory:', error);
            NotificationService.error('Failed to save subcategory');
        }
    }

    async editSubcategory(id) {
        const subcategory = this.subcategories.find(s => s.id === id);
        if (!subcategory) return;

        const categoryOptions = this.categories.map(cat =>
            `<option value="${cat.id}" ${cat.id === subcategory.category_id ? 'selected' : ''}>${Formatters.escapeHTML(cat.name)}</option>`
        ).join('');

        const modalBody = `
            <form id="subcategory-form">
                <input type="hidden" id="subcategory-id" value="${subcategory.id}">
                <div class="form-group mb-3">
                    <label class="form-label">Parent Category *</label>
                    <select class="form-control" id="subcategory-category-id" required>
                        ${categoryOptions}
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Subcategory Name *</label>
                    <input type="text" class="form-control" id="subcategory-name" value="${Formatters.escapeHTML(subcategory.name)}" required>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Icon</label>
                    <div class="icon-picker-wrapper">
                        <input type="text" class="form-control" id="subcategory-icon" value="${subcategory.icon || 'tag'}" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="subcategoriesManager.showIconPicker()">
                            <i class="fas fa-icons"></i>
                        </button>
                    </div>
                    <div class="icon-preview mt-2">
                        <i class="bi bi-${subcategory.icon || 'tag'}" id="icon-preview"></i>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="subcategory-description" rows="3">${Formatters.escapeHTML(subcategory.description || '')}</textarea>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-control" id="subcategory-status">
                        <option value="active" ${subcategory.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="archived" ${subcategory.status === 'archived' ? 'selected' : ''}>Archived</option>
                    </select>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-control" id="subcategory-sort-order" value="${subcategory.sort_order || 0}" min="0">
                </div>
            </form>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Edit Subcategory',
                subtitle: 'Update subcategory details',
                icon: 'fa-edit',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.updateSubcategory()
            });
        } else if (window.premiumModal) {
            window.premiumModal.setTitle('Edit Subcategory');
            window.premiumModal.setSubtitle('Update subcategory details');
            window.premiumModal.setIcon('fa-edit');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').onclick = () => this.updateSubcategory();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }
    }

    async updateSubcategory() {
        const form = document.getElementById('subcategory-form');
        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const id = document.getElementById('subcategory-id').value;
        const subcategoryData = {
            category_id: parseInt(document.getElementById('subcategory-category-id').value),
            name: document.getElementById('subcategory-name').value,
            icon: document.getElementById('subcategory-icon').value,
            description: document.getElementById('subcategory-description').value,
            status: document.getElementById('subcategory-status').value,
            sort_order: parseInt(document.getElementById('subcategory-sort-order').value) || 0
        };

        try {
            const result = await window.Api.put(`/subcategories/${id}`, subcategoryData);

            if (result.success) {
                NotificationService.success('Subcategory updated successfully');
                if (window.modalService) modalService.close();
                else if (window.premiumModal) premiumModal.close();
                this.loadSubcategories();
            } else {
                NotificationService.error(result.message || 'Failed to update subcategory');
            }
        } catch (error) {
            console.error('Failed to update subcategory:', error);
            NotificationService.error('Failed to update subcategory');
        }
    }

    async viewSubcategory(id) {
        try {
            const result = await window.Api.get(`/subcategories/${id}`);

            if (result.success) {
                const subcategory = result.data;
                const modalBody = `
                    <div class="subcategory-details">
                        <div class="subcategory-header d-flex align-items-center mb-4">
                            <div class="category-icon-lg me-3" style="background-color: ${subcategory.category_color}20; color: ${subcategory.category_color}">
                                <i class="bi bi-${subcategory.icon || 'tag'} fa-2x"></i>
                            </div>
                            <div>
                                <h4 class="mb-1">${Formatters.escapeHTML(subcategory.name)}</h4>
                                <span class="badge ${subcategory.category_type === 'income' ? 'bg-success' : 'bg-danger'}">
                                    ${Formatters.escapeHTML(subcategory.category_name || 'Unknown')}
                                </span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Status</label>
                                    <p>${subcategory.status.charAt(0).toUpperCase() + subcategory.status.slice(1)}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Created Date</label>
                                    <p>${Formatters.date(subcategory.created_at)}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Has Transactions</label>
                                    <p>${subcategory.has_transactions ? 'Yes' : 'No'}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label>Sort Order</label>
                                    <p>${subcategory.sort_order || 0}</p>
                                </div>
                            </div>
                        </div>
                        ${subcategory.description ? `
                            <div class="detail-item mt-3">
                                <label>Description</label>
                                <p>${Formatters.escapeHTML(subcategory.description)}</p>
                            </div>
                        ` : ''}
                    </div>
                `;

                if (window.ModalService) {
                    window.modalService.open({
                        title: 'Subcategory Details',
                        subtitle: 'View subcategory information',
                        icon: 'fa-eye',
                        bodyHTML: modalBody,
                        showFooter: false,
                        onSave: null
                    });
                    const saveBtn = document.getElementById('modal-save-btn');
                    if (saveBtn) saveBtn.style.display = 'none';
                } else if (window.premiumModal) {
                    window.premiumModal.setTitle('Subcategory Details');
                    window.premiumModal.setSubtitle('View subcategory information');
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
            console.error('Failed to load subcategory:', error);
            NotificationService.error('Failed to load subcategory details');
        }
    }

    async deleteSubcategory(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Delete Subcategory',
            text: 'Are you sure you want to delete this subcategory? This action cannot be undone.',
            confirmButtonText: 'Yes, delete it'
        });

        if (!confirmed) return;

        try {
            const result = await window.Api.delete(`/subcategories/${id}`);

            if (result.success) {
                NotificationService.success('Subcategory deleted successfully');
                this.loadSubcategories();
            } else {
                NotificationService.error(result.message || 'Failed to delete subcategory');
            }
        } catch (error) {
            console.error('Failed to delete subcategory:', error);
            NotificationService.error('Failed to delete subcategory');
        }
    }

    async archiveSubcategory(id) {
        try {
            const result = await window.Api.get(`/subcategories/${id}/archive`);

            if (result.success) {
                NotificationService.success('Subcategory archived successfully');
                this.loadSubcategories();
            } else {
                NotificationService.error(result.message || 'Failed to archive subcategory');
            }
        } catch (error) {
            console.error('Failed to archive subcategory:', error);
            NotificationService.error('Failed to archive subcategory');
        }
    }

    applyFilters() {
        this.loadSubcategories();
    }

    clearFilters() {
        document.getElementById('filter-subcategory-category').value = '';
        document.getElementById('filter-subcategory-status').value = 'active';
        document.getElementById('search-subcategories').value = '';
        this.loadSubcategories();
    }

    async handleSearch(query) {
        if (query.length < 2) {
            this.loadSubcategories();
            return;
        }

        try {
            const categoryId = document.getElementById('filter-subcategory-category')?.value;
            const status = document.getElementById('filter-subcategory-status')?.value || 'active';

            const params = new URLSearchParams({ q: query, category_id: categoryId || '', status });
            const result = await window.Api.get(`/subcategories/search?${params}`);

            if (result.success) {
                this.subcategories = result.data;
                this.renderSubcategories();
                if (this.dataTable) {
                    this.dataTable.clear();
                    this.dataTable.rows.add(this.subcategories);
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
                <div class="upload-area" id="subcategory-upload-area">
                    <i class="fas fa-cloud-upload-alt fa-3x mb-3"></i>
                    <h5>Drag & Drop CSV/Excel File</h5>
                    <p class="text-muted">or click to browse</p>
                    <input type="file" id="subcategory-import-file" accept=".csv,.xlsx,.xls" style="display: none">
                    <button class="btn btn-primary" onclick="document.getElementById('subcategory-import-file').click()">
                        Browse Files
                    </button>
                </div>
                <div class="import-preview mt-4" id="subcategory-import-preview" style="display: none;">
                    <h6>Preview</h6>
                    <div id="subcategory-preview-table"></div>
                </div>
            </div>
        `;

        if (window.ModalService) {
            window.modalService.open({
                title: 'Import Subcategories',
                subtitle: 'Import subcategories from CSV or Excel',
                icon: 'fa-file-import',
                bodyHTML: modalBody,
                showFooter: true,
                onSave: () => this.importSubcategories()
            });
        } else if (window.premiumModal) {
            window.premiumModal.setTitle('Import Subcategories');
            window.premiumModal.setSubtitle('Import subcategories from CSV or Excel');
            window.premiumModal.setIcon('fa-file-import');
            document.getElementById('modal-body').innerHTML = modalBody;
            document.getElementById('modal-save-btn').textContent = 'Import';
            document.getElementById('modal-save-btn').onclick = () => this.importSubcategories();
            document.getElementById('modal-cancel-btn').style.display = 'block';
            document.getElementById('modal-draft-btn').style.display = 'none';
            document.getElementById('modal-reset-btn').style.display = 'none';
            window.premiumModal.open();
        }
    }

    async importSubcategories() {
        NotificationService.info('Import functionality coming soon');
    }

    async exportSubcategories() {
        try {
            const result = await window.Api.get('/subcategories');

            if (result.success) {
                const csv = this.convertToCSV(result.data);
                this.downloadCSV(csv, 'subcategories.csv');
                NotificationService.success('Subcategories exported successfully');
            }
        } catch (error) {
            console.error('Export failed:', error);
            NotificationService.error('Failed to export subcategories');
        }
    }

    convertToCSV(data) {
        const headers = ['Category', 'Name', 'Icon', 'Description', 'Status', 'Sort Order'];
        const rows = data.map(sub => [
            sub.category_name || 'Unknown',
            sub.name,
            sub.icon || '',
            sub.description || '',
            sub.status,
            sub.sort_order
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

window.subcategoriesManager = null;
window.SubcategoriesManager = SubcategoriesManager;
