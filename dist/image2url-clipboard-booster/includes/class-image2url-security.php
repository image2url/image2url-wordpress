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
     * Maximum uploads per minute per user or IP.
     */
    const MAX_UPLOADS_PER_MINUTE = 10;

    /**
     * The rolling time window used for rate limiting.
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Check if the current actor has exceeded the upload rate limit.
     */
    public static function check_rate_limit($user_id = null): bool
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $key = self::get_rate_limit_key($user_id);
        $current_time = time();
        $window_start = $current_time - self::RATE_LIMIT_WINDOW;

        $attempts = get_transient($key);
        if (!is_array($attempts)) {
            $attempts = [];
        }

        $attempts = array_values(
            array_filter(
                array_map('intval', $attempts),
                static function ($timestamp) use ($window_start) {
                    return $timestamp > $window_start;
                }
            )
        );

        if (count($attempts) >= self::MAX_UPLOADS_PER_MINUTE) {
            return false;
        }

        $attempts[] = $current_time;
        set_transient($key, $attempts, self::RATE_LIMIT_WINDOW);

        return true;
    }

    /**
     * Sanitize and validate endpoint URL.
     */
    public static function validate_endpoint($url): string
    {
        $url = trim((string) $url);

        if ('' === $url) {
            throw new \InvalidArgumentException(esc_html__('无效的端点 URL。', 'image2url-clipboard-booster'));
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['scheme'])) {
            throw new \InvalidArgumentException(esc_html__('端点 URL 格式不正确。', 'image2url-clipboard-booster'));
        }

        $scheme = strtolower((string) $parsed['scheme']);
        $allow_insecure_endpoint = (bool) apply_filters('image2url_allow_insecure_endpoint', false, $url, $parsed);
        if ('https' !== $scheme && !$allow_insecure_endpoint) {
            throw new \InvalidArgumentException(esc_html__('端点 URL 必须使用 HTTPS 协议。', 'image2url-clipboard-booster'));
        }

        $allowed_protocols = $allow_insecure_endpoint ? ['http', 'https'] : ['https'];
        $sanitized_url = esc_url_raw($url, $allowed_protocols);
        if ('' === $sanitized_url || filter_var($sanitized_url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(esc_html__('端点 URL 格式不正确。', 'image2url-clipboard-booster'));
        }

        $validated_url = wp_http_validate_url($sanitized_url);
        $allow_unsafe_endpoint = (bool) apply_filters('image2url_allow_unsafe_endpoint', false, $sanitized_url, $parsed);
        if (false === $validated_url && !$allow_unsafe_endpoint) {
            throw new \InvalidArgumentException(
                esc_html__('端点 URL 必须是公开可访问的安全地址。', 'image2url-clipboard-booster')
            );
        }

        return false !== $validated_url ? $validated_url : $sanitized_url;
    }

    /**
     * Enhanced file validation beyond MIME type checking.
     */
    public static function validate_file_security($file): array
    {
        $errors = [];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = esc_html__('无效的上传文件。', 'image2url-clipboard-booster');
            return $errors;
        }

        if ((int) ($file['size'] ?? 0) === 0) {
            $errors[] = esc_html__('文件大小为 0。', 'image2url-clipboard-booster');
        }

        $header = file_get_contents($file['tmp_name'], false, null, 0, 512);
        if (false === $header) {
            $errors[] = esc_html__('无法读取上传文件。', 'image2url-clipboard-booster');
        } elseif (preg_match('/<\?(php|=)|<script|eval\s*\(/i', $header)) {
            $errors[] = esc_html__('检测到潜在危险内容。', 'image2url-clipboard-booster');
        }

        if (function_exists('getimagesize')) {
            $image_info = @getimagesize($file['tmp_name']);
            if (!$image_info) {
                $errors[] = esc_html__('无法读取图片信息。', 'image2url-clipboard-booster');
            } else {
                $max_dimension = 10000;
                if ($image_info[0] > $max_dimension || $image_info[1] > $max_dimension) {
                    $errors[] = esc_html__('图片尺寸过大。', 'image2url-clipboard-booster');
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

    /**
     * Build a stable transient key for rate limiting.
     */
    private static function get_rate_limit_key($user_id): string
    {
        if (!empty($user_id)) {
            return 'image2url_rate_limit_user_' . (int) $user_id;
        }

        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'guest'));

        return 'image2url_rate_limit_ip_' . md5($ip_address);
    }
}
