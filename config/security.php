<?php
/**
 * Security Configuration
 */

return [
    'password_policy' => [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special_chars' => true,
        'max_age_days' => 90
    ],
    
    'session_security' => [
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'session_timeout' => 7200,
        'session_regenerate' => 300
    ],
    
    'rate_limiting' => [
        'login_attempts' => 5,
        'api_requests' => 100,
        'window_minutes' => 15
    ],
    
    'input_validation' => [
        'email_regex' => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
        'phone_regex' => '/^\+?[1-9]\d{1,14}$/',
        'xss_filters' => true
    ]
];
