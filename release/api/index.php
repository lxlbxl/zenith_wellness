<?php
// ===========================================
// Zenith Wellness API Entry Point
// ===========================================

// Suppress HTML errors - output as JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Global error handler to catch all errors and return JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($exception) {
    http_response_code(500);
    $response = [
        'error' => 'Server Error',
        'message' => $exception->getMessage()
    ];

    // Include debug info only in development
    if (!defined('PRODUCTION') || PRODUCTION !== true) {
        $response['file'] = basename($exception->getFile());
        $response['line'] = $exception->getLine();
    }

    echo json_encode($response);
    exit();
});

// Set content type early
header("Content-Type: application/json; charset=UTF-8");

// Basic Routing
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uriParts = explode('/', trim($uri, '/'));

// Find where 'api' is to determine resource
$apiIndex = array_search('api', $uriParts);
if ($apiIndex === false) {
    http_response_code(404);
    echo json_encode(["message" => "Invalid API Endpoint"]);
    exit();
}

$resource = $uriParts[$apiIndex + 1] ?? null;
$action = $uriParts[$apiIndex + 2] ?? null;

// Load Config & Database
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config/Database.php';

// Initialize Database Connection
$database = new Database();
$db = $database->getConnection();

// Load Middleware
require_once __DIR__ . '/middleware/CorsMiddleware.php';
require_once __DIR__ . '/middleware/SecurityHeaders.php';
require_once __DIR__ . '/middleware/RateLimitMiddleware.php';

// Apply global middleware
(new CorsMiddleware())->handle();
(new SecurityHeadersMiddleware())->handle();

// Create rate limiter instance
$rateLimiter = new RateLimitMiddleware($db);

// Apply endpoint-specific rate limiting
$sensitiveEndpoints = ['auth', 'password'];
if (in_array($resource, $sensitiveEndpoints)) {
    $rateLimiter->handleAuth();
} else {
    // Global rate limit
    $rateLimiter->handle();
}

// Skip OPTIONS (already handled by CORS middleware)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

// ===========================================
// Route Handling
// ===========================================

switch ($resource) {
    case 'auth':
        require_once __DIR__ . '/controllers/AuthController.php';
        $controller = new AuthController($db);

        // Check for account lockout on login
        if ($action === 'login') {
            $input = json_decode(file_get_contents("php://input"), true);
            $email = $input['email'] ?? '';
            if ($email && $rateLimiter->isAccountLocked($email)) {
                http_response_code(429);
                echo json_encode([
                    'error' => 'account_locked',
                    'message' => 'Too many failed login attempts. Please try again in 15 minutes.'
                ]);
                exit();
            }
        }

        if ($action === 'register')
            $controller->register();
        elseif ($action === 'login')
            $controller->login();
        elseif ($action === 'persona')
            $controller->updatePersona();
        else
            http_response_code(404);
        break;

    case 'ai':
        require_once __DIR__ . '/controllers/AIController.php';
        $controller = new AIController($db);
        $controller->handleRequest($action);
        break;

    case 'enrollment':
        require_once __DIR__ . '/controllers/EnrollmentController.php';
        $controller = new EnrollmentController($db);
        $controller->handleRequest($action);
        break;

    case 'analytics':
        require_once __DIR__ . '/controllers/AnalyticsController.php';
        $controller = new AnalyticsController($db);
        $controller->handleRequest($action);
        break;

    case 'notifications':
        require_once __DIR__ . '/controllers/NotificationController.php';
        $controller = new NotificationController($db);
        $controller->handleRequest($action);
        break;

    case 'payment':
        require_once __DIR__ . '/controllers/PaymentController.php';
        $controller = new PaymentController($db);
        $controller->process($action);
        break;

    case 'webhooks':
        // Webhook endpoints - no rate limiting, verify signatures internally
        require_once __DIR__ . '/webhooks/index.php';
        break;

    case 'cohort':
        require_once __DIR__ . '/controllers/CohortController.php';
        $controller = new CohortController($db);
        $controller->handleRequest($action);
        break;

    case 'password':
        // Extra rate limiting for password reset (prevent enumeration)
        $rateLimiter->handlePasswordReset();
        require_once __DIR__ . '/controllers/PasswordResetController.php';
        $controller = new PasswordResetController($db);
        $controller->handleRequest($action);
        break;

    case 'admin':
        $subResource = $action;

        if ($subResource === 'settings') {
            require_once __DIR__ . '/controllers/SettingsController.php';
            $c = new SettingsController($db);
            $subAction = $uriParts[$apiIndex + 3] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'update' : 'list');
            $c->handleRequest($subAction);
        } elseif ($subResource === 'cohorts' || $subResource === 'prompts' || $subResource === 'challenges') {
            require_once __DIR__ . '/controllers/ContentController.php';
            $c = new ContentController($db);
            $c->handleRequest($subResource);
        } elseif ($subResource === 'leads') {
            require_once __DIR__ . '/controllers/LeadsController.php';
            $c = new LeadsController($db);
            $subAction = $uriParts[$apiIndex + 3] ?? 'list';
            $c->handleRequest($subAction);
        } else {
            require_once __DIR__ . '/controllers/AdminController.php';
            $controller = new AdminController($db);
            $targetId = $uriParts[$apiIndex + 3] ?? null;
            $controller->handleRequest($action, $targetId);
        }
        break;

    case 'leads':
        require_once __DIR__ . '/controllers/LeadsController.php';
        $controller = new LeadsController($db);
        $controller->handleRequest($action);
        break;

    case 'health':
        // Health check endpoint
        $checks = [
            'status' => 'ok',
            'database' => $db ? 'connected' : 'disconnected',
            'timestamp' => time(),
            'version' => '1.0.0'
        ];

        // Check logs directory
        $logsDir = __DIR__ . '/../logs';
        $checks['logs_writable'] = is_writable($logsDir) || (file_exists($logsDir) === false && is_writable(dirname($logsDir)));

        // Check exports directory
        $exportsDir = __DIR__ . '/../exports';
        $checks['exports_writable'] = is_writable($exportsDir) || !file_exists($exportsDir);

        // Environment
        $checks['environment'] = defined('APP_ENV') ? APP_ENV : 'unknown';

        echo json_encode($checks);
        break;

    case 'users':
        require_once __DIR__ . '/controllers/UserController.php';
        $controller = new UserController($db);
        $userId = $uriParts[$apiIndex + 2] ?? null;
        $resource = $uriParts[$apiIndex + 3] ?? null;
        $resourceId = $uriParts[$apiIndex + 4] ?? null;
        $controller->handleRequest($userId, $resource, $resourceId);
        break;

    case 'cycle':
        require_once __DIR__ . '/controllers/CycleController.php';
        $controller = new CycleController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null; // status, settings
        $subId = $uriParts[$apiIndex + 3] ?? null; // userId
        $controller->handleRequest($subResource, $subId);
        break;

    case 'meals':
        require_once __DIR__ . '/controllers/MealController.php';
        $controller = new MealController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'routines':
        require_once __DIR__ . '/controllers/RoutineController.php';
        $controller = new RoutineController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'wellness':
        require_once __DIR__ . '/controllers/WellnessController.php';
        $controller = new WellnessController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'gamification':
        require_once __DIR__ . '/controllers/GamificationController.php';
        $controller = new GamificationController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'journal':
        require_once __DIR__ . '/controllers/JournalController.php';
        $controller = new JournalController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'goals':
        require_once __DIR__ . '/controllers/GoalsController.php';
        $controller = new GoalsController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'habits':
        require_once __DIR__ . '/controllers/HabitController.php';
        $controller = new HabitController($db);
        $subResource = $uriParts[$apiIndex + 2] ?? null;
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($subResource, $subId);
        break;

    case 'leaderboard':
        require_once __DIR__ . '/controllers/LeaderboardController.php';
        $controller = new LeaderboardController($db);
        $controller->handleRequest($action);
        break;

    case 'privacy':
        require_once __DIR__ . '/controllers/PrivacyController.php';
        $controller = new PrivacyController($db);
        $controller->handleRequest($action);
        break;

    case 'admin-compliance':
        require_once __DIR__ . '/controllers/AdminComplianceController.php';
        $controller = new AdminComplianceController($db);
        $controller->handleRequest($action);
        break;

    case 'activity':
        require_once __DIR__ . '/controllers/ActivityController.php';
        $controller = new ActivityController($db);
        $subId = $uriParts[$apiIndex + 3] ?? null;
        $controller->handleRequest($action, $subId);
        break;

    default:
        http_response_code(404);
        echo json_encode(["message" => "Resource not found"]);
        break;
}
