<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Category.php';

class AuthController {
    private $userModel;
    private $accountModel;
    private $categoryModel;

    public function __construct() {
        $this->userModel = new User();
        $this->accountModel = new Account();
        $this->categoryModel = new Category();
    }

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['email', 'password', 'first_name']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        if ($this->userModel->findByEmail($data['email'])) {
            Response::error('Email already exists', 409);
        }

        $userData = [
            'email' => $data['email'],
            'password' => $data['password'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'currency' => $data['currency'] ?? 'NPR',
            'language' => $data['language'] ?? 'en',
            'theme' => $data['theme'] ?? 'light'
        ];

        $userId = $this->userModel->register($userData);
        
        if ($userId) {
            // Create default accounts for new user
            $this->createDefaultAccounts($userId);
            
            // Create default categories for new user
            $this->createDefaultCategories($userId);
            
            $token = JWT::encode(['user_id' => $userId]);
            $user = $this->userModel->findById($userId);
            
            Response::success([
                'user' => $user,
                'token' => $token
            ], 'Registration successful', 201);
        }
        
        Response::serverError('Registration failed');
    }

    private function createDefaultAccounts($userId) {
        $defaultAccounts = [
            [
                'name' => 'Cash',
                'type' => 'cash',
                'account_number' => '',
                'balance' => 0,
                'currency' => 'NPR',
                'color' => '#10B981',
                'icon' => 'cash',
                'is_active' => true,
                'is_default' => true
            ],
            [
                'name' => 'Bank Account',
                'type' => 'bank',
                'account_number' => '',
                'balance' => 0,
                'currency' => 'NPR',
                'color' => '#6366f1',
                'icon' => 'bank',
                'is_active' => true,
                'is_default' => false
            ],
            [
                'name' => 'eSewa',
                'type' => 'esewa',
                'account_number' => '',
                'balance' => 0,
                'currency' => 'NPR',
                'color' => '#F59E0B',
                'icon' => 'wallet',
                'is_active' => true,
                'is_default' => false
            ]
        ];

        foreach ($defaultAccounts as $accountData) {
            $accountData['user_id'] = $userId;
            $this->accountModel->create($accountData);
        }
    }

    private function createDefaultCategories($userId) {
        $defaultCategories = [
            // Income categories
            [
                'name' => 'Salary',
                'type' => 'income',
                'icon' => 'briefcase',
                'color' => '#10B981',
                'description' => 'Monthly salary and wages',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 1
            ],
            [
                'name' => 'Freelance',
                'type' => 'income',
                'icon' => 'laptop',
                'color' => '#6366f1',
                'description' => 'Freelance work income',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 2
            ],
            [
                'name' => 'Investments',
                'type' => 'income',
                'icon' => 'chart-line',
                'color' => '#8B5CF6',
                'description' => 'Investment returns',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 3
            ],
            [
                'name' => 'Gifts',
                'type' => 'income',
                'icon' => 'gift',
                'color' => '#EC4899',
                'description' => 'Gifts and bonuses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 4
            ],
            // Expense categories
            [
                'name' => 'Food & Dining',
                'type' => 'expense',
                'icon' => 'utensils',
                'color' => '#EF4444',
                'description' => 'Food and dining expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 1
            ],
            [
                'name' => 'Transportation',
                'type' => 'expense',
                'icon' => 'car',
                'color' => '#F59E0B',
                'description' => 'Transportation costs',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 2
            ],
            [
                'name' => 'Shopping',
                'type' => 'expense',
                'icon' => 'shopping-bag',
                'color' => '#8B5CF6',
                'description' => 'Shopping expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 3
            ],
            [
                'name' => 'Bills & Utilities',
                'type' => 'expense',
                'icon' => 'file-invoice',
                'color' => '#6366f1',
                'description' => 'Bills and utilities',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 4
            ],
            [
                'name' => 'Entertainment',
                'type' => 'expense',
                'icon' => 'film',
                'color' => '#EC4899',
                'description' => 'Entertainment expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 5
            ],
            [
                'name' => 'Healthcare',
                'type' => 'expense',
                'icon' => 'heart',
                'color' => '#10B981',
                'description' => 'Healthcare expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 6
            ],
            [
                'name' => 'Education',
                'type' => 'expense',
                'icon' => 'book',
                'color' => '#3B82F6',
                'description' => 'Education expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 7
            ],
            [
                'name' => 'Others',
                'type' => 'expense',
                'icon' => 'ellipsis-h',
                'color' => '#6B7280',
                'description' => 'Other expenses',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 8
            ]
        ];

        foreach ($defaultCategories as $categoryData) {
            $categoryData['user_id'] = $userId;
            $this->categoryModel->create($categoryData);
        }
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['email', 'password']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $user = $this->userModel->login($data['email'], $data['password']);
        
        if ($user) {
            $token = JWT::encode(['user_id' => $user['id']]);
            
            // Remove password from response
            unset($user['password']);
            
            Response::success([
                'user' => $user,
                'token' => $token
            ], 'Login successful');
        }
        
        Response::error('Invalid credentials', 401);
    }

    public function me() {
        $userId = Middleware::auth();
        $user = $this->userModel->findById($userId);
        
        if ($user) {
            Response::success($user);
        }
        
        Response::notFound('User not found');
    }

    public function update() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $userData = [
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'avatar' => $data['avatar'] ?? '',
            'currency' => $data['currency'] ?? 'NPR',
            'language' => $data['language'] ?? 'en',
            'theme' => $data['theme'] ?? 'light',
            'notification_preferences' => json_encode($data['notification_preferences'] ?? []),
            'settings' => json_encode($data['settings'] ?? [])
        ];

        if ($this->userModel->update($userId, $userData)) {
            $user = $this->userModel->findById($userId);
            Response::success($user, 'Profile updated successfully');
        }
        
        Response::serverError('Update failed');
    }

    public function changePassword() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['current_password', 'new_password']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $user = $this->userModel->findById($userId);
        
        if (!password_verify($data['current_password'], $user['password'])) {
            Response::error('Current password is incorrect', 401);
        }

        if ($this->userModel->updatePassword($userId, $data['new_password'])) {
            Response::success(null, 'Password changed successfully');
        }
        
        Response::serverError('Password change failed');
    }
}
