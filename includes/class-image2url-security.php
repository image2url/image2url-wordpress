<?php

/**
 * Security utilities for Image2URL plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Security
{
    /**
     * Rate limiting storage.
     */
    private static $upload_attempts = [];

    /**
     * Maximum uploads per minute per user.
     */
    const MAX_UPLOADS_PER_MINUTE = 10;

    /**
     * Check if user has exceeded upload rate limit.
     */
    public static function check_rate_limit($user_id = null): bool
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $current_time = time();
        $window_start = $current_time - 60;

        if (isset(self::$upload_attempts[$user_id])) {
            self::$upload_attempts[$user_id] = array_filter(
                self::$upload_attempts[$user_id],
                static function ($timestamp) use ($window_start) {
                    return $timestamp > $window_start;
                }
            );
        } else {
            self::$upload_attempts[$user_id] = [];
        }

        if (count(self::$upload_attempts[$user_id]) >= self::MAX_UPLOADS_PER_MINUTE) {
            return false;
        }

        self::$upload_attempts[$user_id][] = $current_time;
        return true;
    }

    /**
     * Sanitize and validate endpoint URL.
     */
    public static function validate_endpoint($url): string
    {
        $url = esc_url_raw($url);

        if (empty($url)) {
            throw new InvalidArgumentException(esc_html__('无效的端点URL', 'image2url-clipboard-booster'));
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException(esc_html__('端点URL必须使用HTTP或HTTPS协议', 'image2url-clipboard-booster'));
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(esc_html__('端点URL格式不正确', 'image2url-clipboard-booster'));
        }

        return $url;
    }

    /**
     * Enhanced file validation beyond MIME type checking.
     */
    public static function validate_file_security($file): array
    {
        $errors = [];

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = esc_html__('无效的上传文件', 'image2url-clipboard-booster');
        }

        if ((int) $file['size'] === 0) {
            $errors[] = esc_html__('文件大小为 0', 'image2url-clipboard-booster');
        }

        if (function_exists('getimagesize')) {
            $image_info = @getimagesize($file['tmp_name']);
            if (!$image_info) {
                $errors[] = esc_html__('无法读取图片信息', 'image2url-clipboard-booster');
            } else {
                $max_dimension = 10000;
                if ($image_info[0] > $max_dimension || $image_info[1] > $max_dimension) {
                    $errors[] = esc_html__('图片尺寸过大', 'image2url-clipboard-booster');
                }
            }
        }

        return $errors;
    }

    /**
     * Log security events.
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
            wp_json_encode($context),
            get_current_user_id(),
            sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'))
        );

        error_log($log_entry); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Verify nonce with additional security checks.
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
