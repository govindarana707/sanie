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
            $category['subcategories'] = $subcategoriesByCategory[$category['id']] ?? [];
            $category['subcategory_count'] = count($category['subcategories']);
        }

        Response::success($categories);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $category = $this->categoryModel->findById($id, $userId);
        
        if ($category) {
            $category['subcategory_count'] = $this->subcategoryModel->getCountByCategory($category['id']);
            $category['has_transactions'] = $this->categoryModel->hasTransactions($id);
            $category['subcategories'] = $this->subcategoryModel->getByCategory($category['id'], $userId);

            $txInfo = $this->categoryModel->getTransactionStats($id);
            $category['transaction_count'] = $txInfo['tx_count'] ?? 0;
            $category['last_used_at'] = $txInfo['last_used_at'] ?? null;

            Response::success($category);
        }
        
        Response::notFound('Category not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['name', 'type']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $maxSortOrder = $this->getMaxSortOrder($userId, $data['type']);

        $categoryData = [
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? 'tag',
            'color' => $data['color'] ?? ($data['type'] === 'income' ? '#10B981' : '#EF4444'),
            'description' => $data['description'] ?? '',
            'is_default' => false,
            'status' => 'active',
            'sort_order' => $data['sort_order'] ?? ($maxSortOrder + 1)
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
        
        $errors = Middleware::validateRequired($data, ['name', 'type']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }
        
        $existingCategory = $this->categoryModel->findById($id, $userId);
        if (!$existingCategory) {
            Response::notFound('Category not found');
        }
        
        if ($existingCategory['is_default']) {
            Response::error('Cannot update default category', 403);
        }

        $categoryData = [
            'name' => $data['name'] ?? $existingCategory['name'],
            'type' => $data['type'] ?? $existingCategory['type'],
            'icon' => $data['icon'] ?? $existingCategory['icon'],
            'color' => $data['color'] ?? $existingCategory['color'],
            'description' => $data['description'] ?? $existingCategory['description'],
            'status' => $data['status'] ?? $existingCategory['status'],
            'sort_order' => $data['sort_order'] ?? $existingCategory['sort_order']
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
        
        if ($this->categoryModel->hasTransactions($id)) {
            Response::error('Cannot delete category with existing transactions. Consider archiving instead.', 409);
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
        
        if (empty($data['orders']) || !is_array($data['orders'])) {
            Response::error('Invalid reorder data', 422);
        }
        
        if ($this->categoryModel->updateSortOrder($data['orders'], $userId)) {
            Response::success(null, 'Categories reordered successfully');
        }
        
        Response::serverError('Reorder failed');
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
                        if (!$this->categoryModel->hasTransactions($id)) {
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
                        $this->categoryModel->update($id, $userId, ['status' => 'active', 'name' => $category['name'], 'type' => $category['type'], 'icon' => $category['icon'], 'color' => $category['color'], 'description' => $category['description'], 'sort_order' => $category['sort_order']]);
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

    private function getMaxSortOrder($userId, $type) {
        $categories = $this->categoryModel->findAll($userId, $type);
        $maxOrder = 0;
        foreach ($categories as $category) {
            if ($category['sort_order'] > $maxOrder) {
                $maxOrder = $category['sort_order'];
            }
        }
        return $maxOrder;
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
