<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';

// Get request method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string
$uri = strtok($uri, '?');

// Remove the current API base path from the request URI
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$basePath = rtrim($scriptDir, '/');
$uri = preg_replace('#^' . preg_quote($basePath, '#') . '#', '', $uri);

// Remove leading slash
$uri = ltrim($uri, '/');

// Parse URI
$segments = array_values(array_filter(explode('/', $uri), static function ($segment) {
    return $segment !== '';
}));
$resource = $segments[0] ?? '';
$resourceId = $segments[1] ?? null;
$action = $segments[2] ?? null;

// Route the request
try {
    switch ($resource) {
        case 'auth':
            require_once __DIR__ . '/../controllers/AuthController.php';
            $controller = new AuthController();
            
            if ($method === 'POST' && $resourceId === 'register') {
                $controller->register();
            } elseif ($method === 'POST' && $resourceId === 'login') {
                $controller->login();
            } elseif ($method === 'GET' && $resourceId === 'me') {
                $controller->me();
            } elseif ($method === 'PUT' && $resourceId === 'update') {
                $controller->update();
            } elseif ($method === 'POST' && $resourceId === 'change-password') {
                $controller->changePassword();
            } else {
                Response::error('Invalid auth endpoint', 404);
            }
            break;
            
        case 'transactions':
            require_once __DIR__ . '/../controllers/TransactionController.php';
            $controller = new TransactionController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId === 'statistics') {
                $controller->statistics();
            } elseif ($method === 'GET' && $resourceId === 'category-breakdown') {
                $controller->categoryBreakdown();
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid transactions endpoint', 404);
            }
            break;
            
        case 'accounts':
            require_once __DIR__ . '/../controllers/AccountController.php';
            $controller = new AccountController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId === 'total-balance') {
                $controller->totalBalance();
            } elseif ($method === 'GET' && $resourceId === 'overview') {
                $controller->overview();
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'statement') {
                $controller->statement($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'analytics') {
                $controller->analytics($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid accounts endpoint', 404);
            }
            break;
            
        case 'categories':
            require_once __DIR__ . '/../controllers/CategoryController.php';
            $controller = new CategoryController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId === 'statistics') {
                $controller->statistics();
            } elseif ($method === 'GET' && $resourceId === 'search') {
                $controller->search();
            } elseif ($method === 'POST' && $resourceId === 'reorder') {
                $controller->reorder();
            } elseif ($method === 'POST' && $resourceId === 'bulk') {
                $controller->bulkAction();
            } elseif ($method === 'POST' && $resourceId !== null && $action === 'duplicate') {
                $controller->duplicate($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'archive') {
                $controller->archive($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'restore') {
                $controller->restore($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'dynamic-subcategories') {
                $controller->dynamicSubcategories($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid categories endpoint', 404);
            }
            break;
            
        case 'subcategories':
            require_once __DIR__ . '/../controllers/SubcategoryController.php';
            $controller = new SubcategoryController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId === 'search') {
                $controller->search();
            } elseif ($method === 'POST' && $resourceId === 'reorder') {
                $controller->reorder();
            } elseif ($method === 'POST' && $resourceId === 'bulk') {
                $controller->bulkAction();
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'archive') {
                $controller->archive($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'restore') {
                $controller->restore($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid subcategories endpoint', 404);
            }
            break;
            
        case 'budgets':
            require_once __DIR__ . '/../controllers/BudgetController.php';
            $controller = new BudgetController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'progress') {
                $controller->progress($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid budgets endpoint', 404);
            }
            break;
            
        case 'goals':
            require_once __DIR__ . '/../controllers/GoalController.php';
            $controller = new GoalController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'progress') {
                $controller->progress($resourceId);
            } elseif ($method === 'POST' && $resourceId !== null && $action === 'contribute') {
                $controller->contribute($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid goals endpoint', 404);
            }
            break;
            
        case 'dashboard':
            require_once __DIR__ . '/../controllers/DashboardController.php';
            $controller = new DashboardController();
            
            if ($method === 'GET' && $resourceId === 'quick-stats') {
                $controller->quickStats();
            } elseif ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } else {
                Response::error('Invalid dashboard endpoint', 404);
            }
            break;
            
        case 'people':
            require_once __DIR__ . '/../controllers/PersonController.php';
            $controller = new PersonController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId !== null && $action === 'ledger') {
                $controller->ledger($resourceId);
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid people endpoint', 404);
            }
            break;
            
        case 'karobar':
            require_once __DIR__ . '/../controllers/KarobarController.php';
            $controller = new KarobarController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'GET' && $resourceId === 'dashboard') {
                $controller->dashboard();
            } elseif ($method === 'GET' && $resourceId === 'reports') {
                $controller->creditReports();
            } elseif ($method === 'GET' && $resourceId === 'ai-analysis') {
                $controller->aiAnalysis();
            } elseif ($method === 'GET' && $resourceId !== null) {
                $controller->show($resourceId);
            } elseif ($method === 'POST' && $resourceId === 'repayment') {
                $controller->repayment();
            } elseif ($method === 'POST' && $resourceId === 'receiving') {
                $controller->receiving();
            } elseif ($method === 'POST' && count($segments) === 1) {
                $controller->store();
            } elseif ($method === 'PUT' && $resourceId !== null) {
                $controller->update($resourceId);
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid karobar endpoint', 404);
            }
            break;
            
        case 'notifications':
            require_once __DIR__ . '/../controllers/NotificationController.php';
            $controller = new NotificationController();
            
            if ($method === 'GET' && $resourceId === 'unread-count') {
                $controller->unreadCount();
            } elseif ($method === 'GET' && $resourceId === 'recent') {
                $controller->recent();
            } elseif ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } elseif ($method === 'POST' && $resourceId === 'read-all') {
                $controller->markAllRead();
            } elseif ($method === 'POST' && $resourceId !== null && $action === 'read') {
                $controller->markRead($resourceId);
            } elseif ($method === 'POST' && $resourceId !== null) {
                $controller->markRead($resourceId);
            } elseif ($method === 'DELETE' && $resourceId === null && count($segments) === 1) {
                $controller->destroyAll();
            } elseif ($method === 'DELETE' && $resourceId !== null) {
                $controller->destroy($resourceId);
            } else {
                Response::error('Invalid notifications endpoint', 404);
            }
            break;
            
        case 'savings':
            require_once __DIR__ . '/../controllers/SavingsController.php';
            $controller = new SavingsController();
            
            if ($method === 'GET' && $resourceId === 'data') {
                $controller->data();
            } elseif ($method === 'GET' && count($segments) === 1) {
                $controller->data();
            } else {
                Response::error('Invalid savings endpoint', 404);
            }
            break;
            
        case 'reports':
            require_once __DIR__ . '/../controllers/ReportsController.php';
            $controller = new ReportsController();
            
            if ($method === 'GET' && $resourceId === 'income-expense') {
                $controller->incomeExpense();
            } elseif ($method === 'GET' && $resourceId === 'category-breakdown') {
                $controller->categoryBreakdown();
            } elseif ($method === 'GET' && $resourceId === 'budget-health') {
                $controller->budgetHealth();
            } else {
                Response::error('Invalid reports endpoint', 404);
            }
            break;

        case 'analysis':
            require_once __DIR__ . '/../controllers/AnalysisController.php';
            $controller = new AnalysisController();
            
            if ($method === 'GET' && count($segments) === 1) {
                $controller->index();
            } else {
                Response::error('Invalid analysis endpoint', 404);
            }
            break;
            
        default:
            Response::error('Endpoint not found', 404);
    }
} catch (\Throwable $e) {
    Response::serverError($e->getMessage());
}
