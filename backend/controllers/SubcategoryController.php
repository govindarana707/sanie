<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../models/Subcategory.php';

class SubcategoryController {
    private $subcategoryModel;

    public function __construct() {
        $this->subcategoryModel = new Subcategory();
    }

    public function index() {
        $userId = Middleware::auth();
        $categoryId = $_GET['category_id'] ?? null;
        $status = $_GET['status'] ?? 'active';
        $subcategories = $this->subcategoryModel->findAll($userId, $categoryId, $status);
        Response::success($subcategories);
    }

    public function show($id) {
        $userId = Middleware::auth();
        $subcategory = $this->subcategoryModel->findById($id, $userId);
        
        if ($subcategory) {
            $subcategory['has_transactions'] = $this->subcategoryModel->hasTransactions($id);
            Response::success($subcategory);
        }
        
        Response::notFound('Subcategory not found');
    }

    public function store() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['category_id', 'name']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $maxSortOrder = $this->getMaxSortOrder($userId, $data['category_id']);

        $subcategoryData = [
            'category_id' => $data['category_id'],
            'user_id' => $userId,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? 'tag',
            'description' => $data['description'] ?? '',
            'status' => 'active',
            'sort_order' => $data['sort_order'] ?? ($maxSortOrder + 1)
        ];

        $subcategoryId = $this->subcategoryModel->create($subcategoryData);
        
        if ($subcategoryId) {
            $subcategory = $this->subcategoryModel->findById($subcategoryId, $userId);
            Response::success($subcategory, 'Subcategory created successfully', 201);
        }
        
        Response::serverError('Subcategory creation failed');
    }

    public function update($id) {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $existingSubcategory = $this->subcategoryModel->findById($id, $userId);
        if (!$existingSubcategory) {
            Response::notFound('Subcategory not found');
        }

        $subcategoryData = [
            'category_id' => $data['category_id'] ?? $existingSubcategory['category_id'],
            'name' => $data['name'] ?? $existingSubcategory['name'],
            'icon' => $data['icon'] ?? $existingSubcategory['icon'],
            'description' => $data['description'] ?? $existingSubcategory['description'],
            'status' => $data['status'] ?? $existingSubcategory['status'],
            'sort_order' => $data['sort_order'] ?? $existingSubcategory['sort_order']
        ];

        if ($this->subcategoryModel->update($id, $userId, $subcategoryData)) {
            $subcategory = $this->subcategoryModel->findById($id, $userId);
            Response::success($subcategory, 'Subcategory updated successfully');
        }
        
        Response::serverError('Subcategory update failed');
    }

    public function destroy($id) {
        $userId = Middleware::auth();
        
        $subcategory = $this->subcategoryModel->findById($id, $userId);
        if (!$subcategory) {
            Response::notFound('Subcategory not found');
        }
        
        if ($this->subcategoryModel->hasTransactions($id)) {
            Response::error('Cannot delete subcategory with existing transactions. Consider archiving instead.', 409);
        }
        
        if ($this->subcategoryModel->delete($id, $userId)) {
            Response::success(null, 'Subcategory deleted successfully');
        }
        
        Response::serverError('Subcategory deletion failed');
    }

    public function archive($id) {
        $userId = Middleware::auth();
        
        $subcategory = $this->subcategoryModel->findById($id, $userId);
        if (!$subcategory) {
            Response::notFound('Subcategory not found');
        }
        
        if ($this->subcategoryModel->archive($id, $userId)) {
            Response::success(null, 'Subcategory archived successfully');
        }
        
        Response::serverError('Subcategory archive failed');
    }

    public function restore($id) {
        $userId = Middleware::auth();
        
        $subcategory = $this->subcategoryModel->findById($id, $userId);
        if (!$subcategory) {
            Response::notFound('Subcategory not found');
        }
        
        if ($this->subcategoryModel->restore($id, $userId)) {
            Response::success(null, 'Subcategory restored successfully');
        }
        
        Response::serverError('Subcategory restore failed');
    }

    public function search() {
        $userId = Middleware::auth();
        $query = $_GET['q'] ?? '';
        $categoryId = $_GET['category_id'] ?? null;
        $status = $_GET['status'] ?? 'active';
        
        if (empty($query)) {
            Response::error('Search query is required', 422);
        }
        
        $results = $this->subcategoryModel->search($userId, $query, $categoryId, $status);
        Response::success($results);
    }

    public function reorder() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['orders']) || !is_array($data['orders'])) {
            Response::error('Invalid reorder data', 422);
        }
        
        if ($this->subcategoryModel->updateSortOrder($data['orders'], $userId)) {
            Response::success(null, 'Subcategories reordered successfully');
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
                $subcategory = $this->subcategoryModel->findById($id, $userId);
                if (!$subcategory) {
                    continue;
                }
                
                switch ($action) {
                    case 'delete':
                        if (!$this->subcategoryModel->hasTransactions($id)) {
                            $this->subcategoryModel->delete($id, $userId);
                        }
                        break;
                    case 'archive':
                        $this->subcategoryModel->archive($id, $userId);
                        break;
                    case 'restore':
                        $this->subcategoryModel->restore($id, $userId);
                        break;
                    case 'activate':
                        $this->subcategoryModel->update($id, $userId, [
                            'category_id' => $subcategory['category_id'],
                            'name' => $subcategory['name'],
                            'icon' => $subcategory['icon'],
                            'description' => $subcategory['description'],
                            'status' => 'active',
                            'sort_order' => $subcategory['sort_order']
                        ]);
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

    private function getMaxSortOrder($userId, $categoryId) {
        $subcategories = $this->subcategoryModel->getByCategory($categoryId, $userId);
        $maxOrder = 0;
        foreach ($subcategories as $subcategory) {
            if ($subcategory['sort_order'] > $maxOrder) {
                $maxOrder = $subcategory['sort_order'];
            }
        }
        return $maxOrder;
    }
}
