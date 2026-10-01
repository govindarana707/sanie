<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Subcategory.php';
require_once __DIR__ . '/../models/Goal.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../services/NotificationService.php';

class CategoryController {
    private $categoryModel;
    private $subcategoryModel;
    private $notifService;

    public function __construct() {
        $this->categoryModel = new Category();
        $this->subcategoryModel = new Subcategory();
        $this->notifService = new NotificationService();
    }

    public function index() {
        $userId = Middleware::auth();
        $type = $_GET['type'] ?? null;
        $status = $_GET['status'] ?? 'active';
        $categories = $this->categoryModel->findAll($userId, $type, $status);

        $categoryIds = array_column($categories, 'id');

        $subcategoriesByCategory = [];
        if (!empty($categoryIds)) {
            try {
                $subcategoriesByCategory = $this->subcategoryModel->getByCategoryIds($categoryIds, $userId, $status);
            } catch (Exception $e) {
                error_log('CategoryController::index eager-load subcategories failed: ' . $e->getMessage());
                $subcategoriesByCategory = [];
            }
        }

        foreach ($categories as &$category) {
            if ($category['user_id'] === null) {
                $category['is_pinned'] = 0;
                $category['sort_order'] = 999;
            }
            $category['subcategories'] = $subcategoriesByCategory[$category['id']] ?? [];
            $category['subcategory_count'] = count($category['subcategories']);
        }

        Response::success($categories);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $category = $this->categoryModel->findById($id, $userId);
        
        if ($category) {
            if ($category['user_id'] === null) {
                $category['is_pinned'] = 0;
                $category['sort_order'] = 999;
            }
            $category['subcategory_count'] = $this->subcategoryModel->getCountByCategory($category['id']);
            $category['has_transactions'] = $this->categoryModel->hasTransactions($id, $userId);
            $category['subcategories'] = $this->subcategoryModel->getByCategory($category['id'], $userId);

            $txInfo = $this->categoryModel->getTransactionStats($id, $userId);
            $category['transaction_count'] = $txInfo['tx_count'] ?? 0;
            $category['last_used_at'] = $txInfo['last_used_at'] ?? null;

            Response::success($category);
        }
        
        Response::notFound('Category not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) Response::error('Invalid JSON payload', 400);
        
        $errors = Middleware::validateRequired($data, ['name', 'type']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if (!in_array($data['type'], ['income', 'expense'], true)) {
            Response::error('Validation failed', 422, ['type' => 'Type must be income or expense']);
        }
        $isPinned = false;
        if (array_key_exists('is_pinned', $data)) {
            $isPinned = $this->validatedBoolean($data['is_pinned'], 'is_pinned');
        }
        $categoryCount = $this->categoryModel->countOwnedByType($userId, $data['type']) + 1;
        $sortOrder = $isPinned
            ? $this->validatedSortOrder($data['sort_order'] ?? $this->categoryModel->getNextPinnedSortOrder($userId, $data['type']), $categoryCount)
            : 999;

        $categoryData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? 'tag',
            'color' => $data['color'] ?? ($data['type'] === 'income' ? '#10B981' : '#EF4444'),
            'description' => $data['description'] ?? '',
            'is_default' => false,
            'status' => 'active',
            'is_pinned' => $isPinned,
            'sort_order' => $sortOrder
        ];

        $categoryId = $this->categoryModel->create($categoryData);
        
        if ($categoryId) {
            $category = $this->categoryModel->findById($categoryId, $userId);
            $this->notifService->create($userId, 'category_created',
                'Category Created',
                "New {$data['type']} category \"{$data['name']}\" has been created.",
                'category', $categoryId);
            Response::success($category, 'Category created successfully', 201);
        }
        
        Response::serverError('Category creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) Response::error('Invalid JSON payload', 400);

        $allowedFields = ['name', 'type', 'icon', 'color', 'description', 'status', 'is_pinned', 'sort_order'];
        $unknownFields = array_diff(array_keys($data), $allowedFields);
        if ($unknownFields) Response::error('Unsupported category fields', 422);
        $priorityOnly = !empty($data)
            && empty(array_diff(array_keys($data), ['is_pinned', 'sort_order']))
            && (array_key_exists('is_pinned', $data) || array_key_exists('sort_order', $data));

        if (!$priorityOnly) {
            $errors = Middleware::validateRequired($data, ['name', 'type']);
            if (!empty($errors)) Response::error('Validation failed', 422, $errors);
        }
        
        $existingCategory = $this->categoryModel->findById($id, $userId);
        if (!$existingCategory) {
            Response::notFound('Category not found');
        }
        
        if ((int)$existingCategory['user_id'] !== (int)$userId) {
            Response::error('Shared categories cannot be reprioritized', 403);
        }

        if ($existingCategory['is_default'] && !$priorityOnly) {
            Response::error('Cannot update default category', 403);
        }

        $type = $data['type'] ?? $existingCategory['type'];
        if (!in_array($type, ['income', 'expense'], true)) {
            Response::error('Validation failed', 422, ['type' => 'Type must be income or expense']);
        }
        $status = $data['status'] ?? $existingCategory['status'];
        if (!in_array($status, ['active', 'archived', 'deleted'], true)) {
            Response::error('Validation failed', 422, ['status' => 'Invalid category status']);
        }
        $isPinned = (bool)$existingCategory['is_pinned'];
        if (array_key_exists('is_pinned', $data)) {
            $isPinned = $this->validatedBoolean($data['is_pinned'], 'is_pinned');
        }
        if ($priorityOnly && !array_key_exists('is_pinned', $data) && !(bool)$existingCategory['is_pinned']) {
            Response::error('Pin the category before changing its priority', 422);
        }
        if ($isPinned && $existingCategory['status'] !== 'active' && $priorityOnly) {
            Response::error('Only active categories can be pinned', 422);
        }
        if ($status !== 'active') $isPinned = false;
        $typeChanged = $type !== $existingCategory['type'];
        $categoryCount = max(1, $this->categoryModel->countOwnedByType($userId, $type) + ($typeChanged ? 1 : 0));
        if ($isPinned) {
            $candidateOrder = array_key_exists('sort_order', $data)
                ? $data['sort_order']
                : ($typeChanged
                    ? $this->categoryModel->getNextPinnedSortOrder($userId, $type)
                    : ((int)$existingCategory['is_pinned'] === 1
                    ? $existingCategory['sort_order']
                    : $this->categoryModel->getNextPinnedSortOrder($userId, $type)));
            $sortOrder = $this->validatedSortOrder($candidateOrder, $categoryCount);
        } else {
            $sortOrder = 999;
        }

        $categoryData = [
            'name' => $data['name'] ?? $existingCategory['name'],
            'type' => $type,
            'icon' => $data['icon'] ?? $existingCategory['icon'],
            'color' => $data['color'] ?? $existingCategory['color'],
            'description' => $data['description'] ?? $existingCategory['description'],
            'status' => $status,
            'is_pinned' => $isPinned,
            'sort_order' => $sortOrder
        ];

        if ($this->categoryModel->update($id, $userId, $categoryData)) {
            $category = $this->categoryModel->findById($id, $userId);
            Response::success($category, 'Category updated successfully');
        }
        
        Response::serverError('Category update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        
        $category = $this->categoryModel->findById($id, $userId);
        if (!$category) {
            Response::notFound('Category not found');
        }
        
        if ($category['is_default']) {
            Response::error('Cannot delete default category', 403);
        }
        
        if ($this->categoryModel->hasTransactions($id)||$this->categoryModel->hasBudgets($id)) {
            Response::error('Cannot delete category with existing transactions or budgets. Consider archiving instead.', 409);
        }
        
        if ($this->categoryModel->delete($id, $userId)) {
            Response::success(null, 'Category deleted successfully');
        }
        
        Response::serverError('Category deletion failed');
    }

    public function archive($id) {
        $userId = Middleware::auth();
        
        $category = $this->categoryModel->findById($id, $userId);
        if (!$category) {
            Response::notFound('Category not found');
        }
        
        if ($category['is_default']) {
            Response::error('Cannot archive default category', 403);
        }
        
        if ($this->categoryModel->archive($id, $userId)) {
            Response::success(null, 'Category archived successfully');
        }
        
        Response::serverError('Category archive failed');
    }

    public function restore($id) {
        $userId = Middleware::auth();
        
        $category = $this->categoryModel->findById($id, $userId);
        if (!$category) {
            Response::notFound('Category not found');
        }
        
        if ($category['is_default']) {
            Response::error('Cannot restore default category', 403);
        }
        
        if ($this->categoryModel->restore($id, $userId)) {
            Response::success(null, 'Category restored successfully');
        }
        
        Response::serverError('Category restore failed');
    }

    public function statistics() {
        $userId = Middleware::auth();
        $categoryStats = $this->categoryModel->getStatistics($userId);
        $subcategoryStats = $this->subcategoryModel->getStatistics($userId);
        
        Response::success([
            'categories' => $categoryStats,
            'subcategories' => $subcategoryStats
        ]);
    }

    public function search() {
        $userId = Middleware::auth();
        $query = $_GET['q'] ?? '';
        $type = $_GET['type'] ?? null;
        $status = $_GET['status'] ?? 'active';
        
        if (empty($query)) {
            Response::error('Search query is required', 422);
        }
        
        $results = $this->categoryModel->search($userId, $query, $type, $status);

        $categoryIds = array_column($results, 'id');
        $subcategoriesByCategory = [];
        if (!empty($categoryIds)) {
            try {
                $subcategoriesByCategory = $this->subcategoryModel->getByCategoryIds($categoryIds, $userId, 'active');
            } catch (Exception $e) {
                error_log('CategoryController::search eager-load subcategories failed: ' . $e->getMessage());
            }
        }

        foreach ($results as &$category) {
            if ($category['user_id'] === null) {
                $category['is_pinned'] = 0;
                $category['sort_order'] = 999;
            }
            $category['subcategories'] = $subcategoriesByCategory[$category['id']] ?? [];
            $category['subcategory_count'] = count($category['subcategories']);
        }

        Response::success($results);
    }

    public function duplicate($id) {
        $userId = Middleware::auth();
        
        $category = $this->categoryModel->findById($id, $userId);
        if (!$category) {
            Response::notFound('Category not found');
        }
        
        if ($category['is_default']) {
            Response::error('Cannot duplicate default category', 403);
        }
        
        $newId = $this->categoryModel->duplicate($id, $userId);
        
        if ($newId) {
            $newCategory = $this->categoryModel->findById($newId, $userId);
            $subcats = $this->subcategoryModel->getByCategory($newId, $userId);
            $newCategory['subcategories'] = $subcats;
            $newCategory['subcategory_count'] = count($subcats);
            Response::success($newCategory, 'Category duplicated successfully', 201);
        }
        
        Response::serverError('Category duplication failed');
    }

    public function reorder() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) Response::error('Invalid JSON payload', 400);
        if (isset($data['orders_by_type'])) {
            if (!is_array($data['orders_by_type']) || !$data['orders_by_type']) {
                Response::error('Invalid reorder data', 422);
            }
            $ordersByType = [];
            foreach ($data['orders_by_type'] as $type => $orders) {
                if (!in_array($type, ['income', 'expense'], true)) Response::error('Invalid category type', 422);
                $ordersByType[$type] = $this->normalizePriorityOrders($orders, $userId, $type);
            }
        } else {
            $type = $data['type'] ?? null;
            if (!in_array($type, ['income', 'expense'], true)) Response::error('Type must be income or expense', 422);
            $ordersByType = [$type => $this->normalizePriorityOrders($data['orders'] ?? null, $userId, $type)];
        }

        if ($this->categoryModel->reorderPinnedBatch($ordersByType, $userId)) {
            Response::success(null, 'Pinned category order saved');
        }
        Response::error('Pinned categories changed. Refresh and try again.', 409);
    }

    public function bulkAction() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['action', 'ids']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }
        
        $action = $data['action'];
        $ids = $data['ids'];
        
        try {
            foreach ($ids as $id) {
                $category = $this->categoryModel->findById($id, $userId);
                if (!$category || $category['is_default']) {
                    continue;
                }
                
                switch ($action) {
                    case 'delete':
                        if (!$this->categoryModel->hasTransactions($id)&&!$this->categoryModel->hasBudgets($id)) {
                            $this->categoryModel->delete($id, $userId);
                        }
                        break;
                    case 'archive':
                        $this->categoryModel->archive($id, $userId);
                        break;
                    case 'restore':
                        $this->categoryModel->restore($id, $userId);
                        break;
                    case 'activate':
                        $this->categoryModel->update($id, $userId, ['status' => 'active', 'name' => $category['name'], 'type' => $category['type'], 'icon' => $category['icon'], 'color' => $category['color'], 'description' => $category['description'], 'is_pinned' => $category['is_pinned'], 'sort_order' => $category['sort_order']]);
                        break;
                    default:
                        Response::error('Invalid bulk action', 400);
                }
            }
            
            Response::success(null, 'Bulk action completed successfully');
        } catch (Exception $e) {
            Response::serverError('Bulk action failed: ' . $e->getMessage());
        }
    }

    private function validatedBoolean($value, string $field): bool {
        if (is_bool($value)) return $value;
        if (is_int($value) && ($value === 0 || $value === 1)) return (bool)$value;
        Response::error('Validation failed', 422, [$field => 'Must be a boolean']);
    }

    private function normalizePriorityOrders($orders, int $userId, string $type): array {
        if (!is_array($orders)) Response::error('Invalid reorder data', 422);
        $categoryCount = $this->categoryModel->countOwnedByType($userId, $type);
        if (count($orders) > $categoryCount) Response::error('Invalid reorder data', 422);
        $normalized = [];
        $ids = [];
        foreach ($orders as $index => $order) {
            if (!is_array($order) || !isset($order['id'])) Response::error('Invalid reorder item', 422);
            $id = filter_var($order['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || isset($ids[$id])) Response::error('Category IDs must be unique positive integers', 422);
            $ids[$id] = true;
            $normalized[] = ['id' => (int)$id, 'sort_order' => $index + 1];
        }
        return $normalized;
    }

    private function validatedSortOrder($value, int $maximum): int {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            Response::error('Validation failed', 422, ['sort_order' => 'Must be an integer']);
        }
        $order = (int)$value;
        if ($order < 1 || $order > max(1, $maximum)) {
            Response::error('Validation failed', 422, [
                'sort_order' => 'Must be between 1 and ' . max(1, $maximum)
            ]);
        }
        return $order;
    }

    public function dynamicSubcategories($categoryId) {
        $userId = Middleware::auth();
        $category = $this->categoryModel->findById($categoryId, $userId);
        if (!$category) {
            Response::notFound('Category not found');
        }

        $subcategories = [];

        if (strtolower($category['name']) === 'savings') {
            $goalModel = new Goal();
            $accountModel = new Account();

            $goals = $goalModel->findAll($userId);
            foreach ($goals as $goal) {
                if ($goal['status'] !== 'active') continue;
                $percentage = $goal['target_amount'] > 0 ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
                $subcategories[] = [
                    'id' => 'goal_' . $goal['id'],
                    'name' => $goal['name'],
                    'icon' => $goal['icon'] ?? 'fa-bullseye',
                    'description' => $goal['description'] ?? '',
                    'dynamic_type' => 'goal',
                    'reference_id' => $goal['id'],
                    'current_amount' => (float)$goal['current_amount'],
                    'target_amount' => (float)$goal['target_amount'],
                    'percentage' => round($percentage, 1),
                    'deadline' => $goal['deadline'],
                    'color' => $goal['color'] ?? '#10B981'
                ];
            }

            $accounts = $accountModel->findAll($userId);
            foreach ($accounts as $account) {
                if (!$account['is_active'] || $account['type'] !== 'savings') continue;
                $subcategories[] = [
                    'id' => 'account_' . $account['id'],
                    'name' => $account['name'],
                    'icon' => $account['icon'] ?? 'fa-piggy-bank',
                    'description' => $account['account_number'] ?? '',
                    'dynamic_type' => 'account',
                    'reference_id' => $account['id'],
                    'balance' => (float)$account['balance'],
                    'color' => $account['color'] ?? '#6366f1'
                ];
            }
        } else {
            $realSubcategories = $this->subcategoryModel->getByCategory($categoryId, $userId);
            foreach ($realSubcategories as $sub) {
                $subcategories[] = [
                    'id' => $sub['id'],
                    'name' => $sub['name'],
                    'icon' => $sub['icon'] ?? 'tag',
                    'description' => $sub['description'] ?? '',
                    'dynamic_type' => null,
                    'reference_id' => $sub['id']
                ];
            }
        }

        Response::success($subcategories);
    }
}
