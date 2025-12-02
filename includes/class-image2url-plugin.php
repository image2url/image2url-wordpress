<?php

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Plugin
{
    private $option_key = 'image2url_settings';

    public function init(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('wp_ajax_image2url_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_ajax_image2url_verify_endpoint', [$this, 'handle_verify_endpoint']);
    }

    public function defaults(): array
    {
        return [
            'endpoint' => 'https://www.image2url.com/api/upload',
            'max_size_mb' => 2,
            'enable_clipboard' => 1,
        ];
    }

    public function register_settings(): void
    {
        register_setting(
            'image2url_settings',
            $this->option_key,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->defaults(),
            ]
        );

        add_settings_section(
            'image2url_general',
            esc_html__('基础设置', 'image2url-clipboard-booster'),
            static function () {
                echo '<p>' . esc_html__('配置上传端点与粘贴行为。默认直传 image2url，无需占用本地媒体库。', 'image2url-clipboard-booster') . '</p>';
            },
            'image2url'
        );

        add_settings_field(
            'endpoint',
            esc_html__('上传端点', 'image2url-clipboard-booster'),
            [$this, 'render_endpoint_field'],
            'image2url',
            'image2url_general'
        );

        add_settings_field(
            'max_size_mb',
            esc_html__('体积限制 (MB)', 'image2url-clipboard-booster'),
            [$this, 'render_max_size_field'],
            'image2url',
            'image2url_general'
        );

        add_settings_field(
            'enable_clipboard',
            esc_html__('启用剪贴板直传', 'image2url-clipboard-booster'),
            [$this, 'render_clipboard_field'],
            'image2url',
            'image2url_general'
        );
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->defaults();
        if (!is_array($input)) {
            return $defaults;
        }

        $sanitized = [];
        $sanitized['endpoint'] = isset($input['endpoint']) ? esc_url_raw(trim(wp_unslash($input['endpoint']))) : $defaults['endpoint'];

        $size = isset($input['max_size_mb']) ? (float) $input['max_size_mb'] : $defaults['max_size_mb'];
        $sanitized['max_size_mb'] = $size > 0 ? $size : $defaults['max_size_mb'];

        $sanitized['enable_clipboard'] = !empty($input['enable_clipboard']) ? 1 : 0;

        return $sanitized;
    }

    public function add_settings_page(): void
    {
        add_options_page(
            'Image2URL Clipboard Booster',
            'Image2URL',
            'manage_options',
            'image2url',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        ?>
        <div class="wrap">
            <h1>Image2URL Clipboard Booster</h1>
            <p><?php echo esc_html__('核心卖点：Gutenberg 粘贴图片即上云，返回外链，降低 inode 压力。', 'image2url-clipboard-booster'); ?></p>
            <form action="options.php" method="post">
                <?php
                settings_fields('image2url_settings');
                do_settings_sections('image2url');
                submit_button();
                ?>
            </form>
            <hr>
            <h2><?php echo esc_html__('使用建议', 'image2url-clipboard-booster'); ?></h2>
            <ol>
                <li><?php echo esc_html__('保持默认端点以获得免配置体验；如需私有化，可改为自建 API 地址。', 'image2url-clipboard-booster'); ?></li>
                <li><?php echo esc_html__('如担心链接失效，可在后续版本启用本地+云端镜像（计划中）。', 'image2url-clipboard-booster'); ?></li>
                <li><?php echo esc_html__('多人协作推荐统一端点并开启 CDN 自定义域，保证 SEO 友好。', 'image2url-clipboard-booster'); ?></li>
            </ol>
        </div>
        <?php
    }

    public function render_endpoint_field(): void
    {
        $options = $this->get_options();
        ?>
        <input type="url" name="<?php echo esc_attr($this->option_key); ?>[endpoint]" value="<?php echo esc_attr($options['endpoint']); ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('默认 https://www.image2url.com/api/upload，可替换为自建端点或自定义域。', 'image2url-clipboard-booster'); ?></p>
        <?php
    }

    public function render_max_size_field(): void
    {
        $options = $this->get_options();
        ?>
        <input type="number" step="0.1" min="0.1" name="<?php echo esc_attr($this->option_key); ?>[max_size_mb]" value="<?php echo esc_attr($options['max_size_mb']); ?>" />
        <p class="description"><?php echo esc_html__('超过此体积将在本地阻断，默认 2MB 对齐官方限制。', 'image2url-clipboard-booster'); ?></p>
        <?php
    }

    public function render_clipboard_field(): void
    {
        $options = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enable_clipboard]" value="1" <?php checked($options['enable_clipboard'], 1); ?> />
            <?php echo esc_html__('启用 Gutenberg 粘贴图片自动上云', 'image2url-clipboard-booster'); ?>
        </label>
        <p class="description"><?php echo esc_html__('拦截剪贴板里的图片文件，自动上传并插入外链，不占用媒体库。', 'image2url-clipboard-booster'); ?></p>
        <?php
    }

    private function get_options(): array
    {
        return wp_parse_args(
            get_option($this->option_key, []),
            $this->defaults()
        );
    }

    public function enqueue_editor_assets(): void
    {
        $options = $this->get_options();
        if (empty($options['enable_clipboard'])) {
            return;
        }

        wp_enqueue_script(
            'image2url-editor',
            IMAGE2URL_PLUGIN_URL . 'assets/js/editor-paste.js',
            ['wp-blocks', 'wp-data', 'wp-notices', 'wp-element', 'wp-i18n', 'wp-a11y'],
            IMAGE2URL_VERSION,
            true
        );

        $config = [
            'endpoint' => $options['endpoint'],
            'maxBytes' => (int) ($options['max_size_mb'] * 1024 * 1024),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('image2url_upload'),
            'allowedTypes' => $this->get_allowed_mime_types(),
        ];

        wp_localize_script('image2url-editor', 'image2urlConfig', $config);
    }

    public function get_allowed_mime_types(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ];
    }

    public function validate_file_type($file): bool
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (empty($check['type']) || !in_array($check['type'], $this->get_allowed_mime_types(), true)) {
            return false;
        }

        $mime = wp_get_image_mime($file['tmp_name']);
        return $mime && in_array($mime, $this->get_allowed_mime_types(), true);
    }

    public function handle_ajax_upload(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!Image2URL_Security::verify_nonce_security($nonce, 'image2url_upload')) {
            wp_send_json_error(['message' => esc_html__('安全验证失败。', 'image2url-clipboard-booster')]);
        }

        if (!current_user_can('upload_files')) {
            Image2URL_Security::log_security_event(
                'PERMISSION_DENIED',
                'User without upload_files permission attempted upload'
            );
            wp_die(esc_html__('您没有权限上传文件。', 'image2url-clipboard-booster'));
        }

        if (!Image2URL_Security::check_rate_limit()) {
            Image2URL_Security::log_security_event(
                'RATE_LIMIT_EXCEEDED',
                'User exceeded upload rate limit'
            );
            wp_send_json_error(['message' => esc_html__('上传过于频繁，请稍后再试。', 'image2url-clipboard-booster')]);
        }

        if (
            !isset($_FILES['file']) ||
            !is_array($_FILES['file']) ||
            !isset($_FILES['file']['error'], $_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type'], $_FILES['file']['size']) ||
            (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK
        ) {
            wp_send_json_error(['message' => esc_html__('文件上传失败。', 'image2url-clipboard-booster')]);
        }

        $file = $_FILES['file'];

        $security_errors = Image2URL_Security::validate_file_security($file);
        if (!empty($security_errors)) {
            Image2URL_Security::log_security_event(
                'FILE_VALIDATION_FAILED',
                'File failed security validation',
                ['errors' => $security_errors, 'filename' => $file['name']]
            );
            wp_send_json_error(['message' => implode(' ', $security_errors)]);
        }

        if (!$this->validate_file_type($file)) {
            Image2URL_Security::log_security_event(
                'INVALID_FILE_TYPE',
                'Invalid file type detected',
                ['filename' => $file['name'], 'type' => $file['type']]
            );
            wp_send_json_error(['message' => esc_html__('不支持的文件类型。', 'image2url-clipboard-booster')]);
        }

        $options = $this->get_options();
        $max_bytes = (int) ($options['max_size_mb'] * 1024 * 1024);
        if ($file['size'] > $max_bytes) {
            wp_send_json_error(['message' => esc_html__('文件过大。', 'image2url-clipboard-booster')]);
        }

        $this->upload_to_external_service($file);
    }

    private function upload_to_external_service($file): void
    {
        $options = $this->get_options();
        $endpoint = $options['endpoint'];

        $file_upload = class_exists('CURLFile')
            ? new CURLFile($file['tmp_name'], $file['type'], $file['name'])
            : '@' . $file['tmp_name'];

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 30,
                'user-agent' => 'Image2URL-WordPress/' . IMAGE2URL_VERSION,
                'sslverify' => true,
                'body' => [
                    'file' => $file_upload,
                ],
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => esc_html__('上传请求失败：', 'image2url-clipboard-booster') . $response->get_error_message()]);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ((int) $http_code !== 200) {
            wp_send_json_error(['message' => esc_html__('上传失败，HTTP状态码：', 'image2url-clipboard-booster') . $http_code]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data || !isset($data['url'])) {
            wp_send_json_error(['message' => esc_html__('上传服务返回无效响应。', 'image2url-clipboard-booster')]);
        }

        wp_send_json_success([
            'url' => esc_url_raw($data['url']),
            'filename' => sanitize_file_name($file['name']),
        ]);
    }

    public function handle_verify_endpoint(): void
    {
        check_ajax_referer('image2url_upload', 'nonce');

        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint'])) : '';
        if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => esc_html__('无效的端点URL。', 'image2url-clipboard-booster')]);
        }

        wp_send_json_success(['message' => esc_html__('端点验证通过。', 'image2url-clipboard-booster')]);
    }
}
