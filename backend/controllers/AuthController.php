<?php

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PasswordPolicy.php';
require_once __DIR__ . '/../services/CredentialService.php';
require_once __DIR__ . '/../services/PasswordResetDeliveryService.php';
require_once __DIR__ . '/../services/InitialUserDataService.php';

class AuthController {
    private $userModel;
    private $accountModel;
    private $categoryModel;
    private $conn;

    public function __construct() {
        $this->userModel = new User();
        $this->accountModel = new Account();
        $this->categoryModel = new Category();
        $this->conn = (new Database())->getConnection();
    }

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['email', 'password', 'first_name']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim((string)$data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Validation failed', 422, ['email' => 'A valid email address is required']);
        }
        if ($passwordError=PasswordPolicy::error($data['password']??null)) Response::error('Validation failed',422,['password'=>$passwordError]);
        $rateKey = 'register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::hit($rateKey, 5, 3600)) {
            Response::error('Too many registration attempts. Try again later.', 429);
        }

        if ($this->userModel->findByEmail($email)) {
            Response::error('Email already exists', 409);
        }

        $userData = [
            'email' => $email,
            'password' => $data['password'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'currency' => $data['currency'] ?? 'NPR',
            'language' => $data['language'] ?? 'en',
            'theme' => $data['theme'] ?? 'light'
        ];

        try {
            if (!$this->conn) throw new RuntimeException('Database unavailable.');
            $this->conn->beginTransaction();
            $userId = $this->userModel->register($userData);
            if (!$userId) throw new RuntimeException('Unable to create user.');
            (new InitialUserDataService($this->conn))->createDefaults((int)$userId);
            $this->conn->commit();

            $token = JWT::encode(['user_id' => $userId, 'token_version' => 1]);
            $user = $this->userModel->findById($userId);

            Response::success([
                'user' => $user,
                'token' => $token
            ], 'Registration successful', 201);
        } catch (PDOException $e) {
            if ($this->conn instanceof PDO && $this->conn->inTransaction()) $this->conn->rollBack();
            if ((string)$e->getCode() === '23000') {
                Response::error('Email already exists', 409);
            }
            error_log('Registration database error: ' . $e->getMessage());
            Response::serverError();
        } catch (Throwable $e) {
            if ($this->conn instanceof PDO && $this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Registration initialization error: ' . $e->getMessage());
            Response::serverError();
        }
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
            if (!$this->accountModel->create($accountData)) return false;
        }
        return true;
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
            $categoryData['is_pinned'] = false;
            $categoryData['sort_order'] = 999;
            if (!$this->categoryModel->create($categoryData)) return false;
        }
        return true;
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $errors = Middleware::validateRequired($data, ['email', 'password']);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }
        if(!is_string($data['email'])||!is_string($data['password']))Response::error('Validation failed',422,['credentials'=>'Email and password must be strings.']);

        $email = strtolower(trim((string)$data['email']));
        $rateKey = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $email;
        if (!RateLimiter::hit($rateKey, 5, 300)) {
            Response::error('Too many login attempts. Try again later.', 429);
        }

        $user = $this->userModel->login($email, $data['password']);
        
        if ($user) {
            RateLimiter::clear($rateKey);
            $token = JWT::encode(['user_id' => $user['id'], 'token_version' => (int)$user['token_version']]);
            
            // Remove password from response
            unset($user['password'],$user['token_version'],$user['password_changed_at']);
            
            Response::success([
                'user' => $user,
                'token' => $token
            ], 'Login successful');
        }
        
        Response::error('Invalid email or password.', 401);
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
        $existing = $this->userModel->findById($userId);
        if (!$existing) Response::notFound('User not found');
        
        $userData = [
            'first_name' => $data['first_name'] ?? $existing['first_name'],
            'last_name' => $data['last_name'] ?? $existing['last_name'],
            'phone' => $data['phone'] ?? $existing['phone'],
            'avatar' => $data['avatar'] ?? $existing['avatar'],
            'currency' => $data['currency'] ?? $existing['currency'],
            'language' => $data['language'] ?? $existing['language'],
            'theme' => $data['theme'] ?? $existing['theme'],
            'notification_preferences' => array_key_exists('notification_preferences', $data) ? json_encode($data['notification_preferences']) : ($existing['notification_preferences'] ?: '[]'),
            'settings' => array_key_exists('settings', $data) ? json_encode($data['settings']) : ($existing['settings'] ?: '[]')
        ];

        if ($this->userModel->update($userId, $userData)) {
            $user = $this->userModel->findById($userId);
            Response::success($user, 'Profile updated successfully');
        }
        
        Response::serverError('Update failed');
    }

    public function uploadAvatar() {
        $userId = Middleware::auth();
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Please choose a valid profile photo.', 422);
        }

        $file = $_FILES['avatar'];
        if ($file['size'] > 2 * 1024 * 1024) Response::error('Profile photo must be smaller than 2 MB.', 422);

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) Response::error('Only JPG, PNG or WebP images are allowed.', 422);

        $directory = __DIR__ . '/../uploads/avatars';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) Response::serverError('Unable to save profile photo.');

        $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) Response::serverError('Unable to save profile photo.');

        $avatar = 'uploads/avatars/' . $filename;
        if (!$this->userModel->updateAvatar($userId, $avatar)) {
            @unlink($directory . '/' . $filename);
            Response::serverError('Unable to update profile photo.');
        }

        Response::success($this->userModel->findById($userId), 'Profile photo updated successfully');
    }

    public function changePassword() {
        $userId = Middleware::auth();
        $data = json_decode(file_get_contents('php://input'), true);
        if(!is_array($data))Response::error('Validation failed',422,['request'=>'A valid JSON object is required']);
        $rateKey='password-change:'.$userId.':'.($_SERVER['REMOTE_ADDR']??'unknown');
        if(!RateLimiter::hit($rateKey,10,900))Response::error('Too many password change attempts. Try again later.',429);
        try{$result=(new CredentialService($this->conn))->changePassword($userId,$data);RateLimiter::clear($rateKey);$token=JWT::encode(['user_id'=>$userId,'token_version'=>$result['token_version']]);Response::success(['token'=>$token,'session_policy'=>'other_sessions_revoked'],'Password changed successfully');}
        catch(CredentialValidationException$e){Response::error($e->getMessage(),$e->status,$e->errors);}
        catch(Throwable$e){error_log('Password change failed for authenticated user ID '.$userId);Response::serverError('Password change failed');}
    }

    public function forgotPassword() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !isset($data['email']) || !is_string($data['email'])) {
            Response::error('Validation failed', 422, ['email' => 'A valid email address is required.']);
        }
        $email = strtolower(trim($data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Validation failed', 422, ['email' => 'A valid email address is required.']);
        }
        $rateKey = 'password-reset-request:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . hash('sha256', $email);
        if (!RateLimiter::hit($rateKey, 5, 900)) {
            Response::error('Too many password reset requests. Try again later.', 429);
        }

        $message = 'If an eligible account exists, password reset instructions will be sent.';
        try {
            $credentials = new CredentialService($this->conn);
            $reset = $credentials->createPasswordReset($email, PASSWORD_RESET_TTL);
            if ($reset) {
                $delivered = (new PasswordResetDeliveryService())->deliverPasswordReset(
                    $reset['email'],
                    $reset['token'],
                    $reset['expires_at']
                );
                if (!$delivered) {
                    $credentials->invalidatePasswordResetToken($reset['token']);
                    error_log('[SanIE] Password reset delivery failed; the undelivered token was invalidated.');
                }
            }
            Response::success(null, $message);
        } catch (Throwable $e) {
            error_log('[SanIE] Password reset request failed internally.');
            Response::success(null, $message);
        }
    }

    public function validatePasswordReset() {
        $data = json_decode(file_get_contents('php://input'), true);
        $token = is_array($data) ? ($data['token'] ?? null) : null;
        try {
            if (!(new CredentialService($this->conn))->validatePasswordResetToken($token)) {
                Response::error('Reset link is invalid or expired.', 422);
            }
            Response::success(['valid' => true], 'Reset link is valid.');
        } catch (Throwable $e) {
            error_log('[SanIE] Password reset token validation failed internally.');
            Response::serverError('Password reset validation failed');
        }
    }

    public function resetPassword() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) Response::error('Validation failed', 422, ['request' => 'A valid JSON object is required.']);
        $rateKey = 'password-reset:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::hit($rateKey, 10, 900)) {
            Response::error('Too many password reset attempts. Try again later.', 429);
        }
        try {
            (new CredentialService($this->conn))->resetPassword($data);
            Response::success(null, 'Password reset successful. Sign in with your new password.');
        } catch (CredentialValidationException $e) {
            Response::error($e->getMessage(), $e->status, $e->errors);
        } catch (Throwable $e) {
            error_log('[SanIE] Password reset failed internally.');
            Response::serverError('Password reset failed');
        }
    }
}
