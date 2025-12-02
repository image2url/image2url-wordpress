<?php

/**
 * Security utilities for Image2URL plugin
 *
 * This file contains security-related functions and validation utilities
 * to ensure the plugin operates securely.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Security
{
    /**
     * Rate limiting storage
     */
    private static $upload_attempts = [];

    /**
     * Maximum uploads per minute per user
     */
    const MAX_UPLOADS_PER_MINUTE = 10;

    /**
     * Check if user has exceeded upload rate limit
     */
    public static function check_rate_limit($user_id = null): bool
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $current_time = time();
        $window_start = $current_time - 60; // 1 minute window

        // Clean old attempts
        if (isset(self::$upload_attempts[$user_id])) {
            self::$upload_attempts[$user_id] = array_filter(
                self::$upload_attempts[$user_id],
                function($timestamp) use ($window_start) {
                    return $timestamp > $window_start;
                }
            );
        } else {
            self::$upload_attempts[$user_id] = [];
        }

        // Check rate limit
        if (count(self::$upload_attempts[$user_id]) >= self::MAX_UPLOADS_PER_MINUTE) {
            return false;
        }

        // Record this attempt
        self::$upload_attempts[$user_id][] = $current_time;
        return true;
    }

    /**
     * Sanitize and validate endpoint URL
     */
    public static function validate_endpoint($url): string
    {
        $url = sanitize_url($url);

        if (empty($url)) {
            throw new InvalidArgumentException(__('无效的端点URL', 'image2url'));
        }

        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'], ['http', 'https'])) {
            throw new InvalidArgumentException(__('端点URL必须使用HTTP或HTTPS协议', 'image2url'));
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(__('端点URL格式不正确', 'image2url'));
        }

        return $url;
    }

    /**
     * Enhanced file validation beyond MIME type checking
     */
    public static function validate_file_security($file): array
    {
        $errors = [];

        // Basic file checks
        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = __('无效的上传文件', 'image2url');
        }

        if ($file['size'] === 0) {
            $errors[] = __('文件大小为0', 'image2url');
        }

        // Check for potential dangerous content in images
        if (self::contains_malicious_content($file['tmp_name'])) {
            $errors[] = __('文件包含潜在恶意内容', 'image2url');
        }

        // Validate image dimensions
        if (function_exists('getimagesize')) {
            $image_info = @getimagesize($file['tmp_name']);
            if (!$image_info) {
                $errors[] = __('无法读取图片信息', 'image2url');
            } else {
                $max_dimension = 10000; // 10k pixels
                if ($image_info[0] > $max_dimension || $image_info[1] > $max_dimension) {
                    $errors[] = __('图片尺寸过大', 'image2url');
                }
            }
        }

        return $errors;
    }

    /**
     * Basic scan for potentially malicious content
     */
    private static function contains_malicious_content($file_path): bool
    {
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return true; // Assume malicious if can't read
        }

        $header = fread($handle, 1024);
        fclose($handle);

        // Check for common PHP patterns (in case of fake images)
        $malicious_patterns = [
            '<?php',
            '<script',
            'javascript:',
            'vbscript:',
            'data:text/html',
        ];

        foreach ($malicious_patterns as $pattern) {
            if (stripos($header, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log security events
     */
    public static function log_security_event($event_type, $message, $context = []): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_entry = sprintf(
            '[Image2URL Security] %s: %s | Context: %s | User: %d | IP: %s',
            $event_type,
            $message,
            json_encode($context),
            get_current_user_id(),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );

        error_log($log_entry);
    }

    /**
     * Verify nonce with additional security checks
     */
    public static function verify_nonce_security($nonce, $action): bool
    {
        if (!wp_verify_nonce($nonce, $action)) {
            self::log_security_event(
                'NONCE_INVALID',
                'Invalid nonce verification attempt',
                ['action' => $action]
            );
            return false;
        }

        return true;
    }
}