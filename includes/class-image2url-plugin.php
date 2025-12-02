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
            __('基础设置', 'image2url'),
            static function () {
                echo '<p>配置上传端点与粘贴行为。默认直连 image2url，无需占用本地媒体库。</p>';
            },
            'image2url'
        );

        add_settings_field(
            'endpoint',
            __('上传端点', 'image2url'),
            [$this, 'render_endpoint_field'],
            'image2url',
            'image2url_general'
        );

        add_settings_field(
            'max_size_mb',
            __('体积限制 (MB)', 'image2url'),
            [$this, 'render_max_size_field'],
            'image2url',
            'image2url_general'
        );

        add_settings_field(
            'enable_clipboard',
            __('启用剪贴板直传', 'image2url'),
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
        $sanitized['endpoint'] = isset($input['endpoint']) ? esc_url_raw(trim($input['endpoint'])) : $defaults['endpoint'];

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
            <p>核心卖点：Gutenberg 粘贴图片即上云，返回外链，降低 inode 压力。</p>
            <form action="options.php" method="post">
                <?php
                settings_fields('image2url_settings');
                do_settings_sections('image2url');
                submit_button();
                ?>
            </form>
            <hr>
            <h2>使用建议</h2>
            <ol>
                <li>保持默认端点以获得免配置体验；如需私有化，可改为自建 API 地址。</li>
                <li>若担心链接失效，可启用“本地+云端”双路径（后续版本将提供镜像选项）。</li>
                <li>在多人协作场景，推荐统一端点并开启 CDN 自定义域，保证 SEO 友好。</li>
            </ol>
        </div>
        <?php
    }

    public function render_endpoint_field(): void
    {
        $options = $this->get_options();
        ?>
        <input type="url" name="<?php echo esc_attr($this->option_key); ?>[endpoint]" value="<?php echo esc_attr($options['endpoint']); ?>" class="regular-text" />
        <p class="description">默认：<code>https://www.image2url.com/api/upload</code>。可替换为自建端点或自定义域。</p>
        <?php
    }

    public function render_max_size_field(): void
    {
        $options = $this->get_options();
        ?>
        <input type="number" step="0.1" min="0.1" name="<?php echo esc_attr($this->option_key); ?>[max_size_mb]" value="<?php echo esc_attr($options['max_size_mb']); ?>" />
        <p class="description">超过此体积将在本地阻断，默认 2MB 对齐官方限制。</p>
        <?php
    }

    public function render_clipboard_field(): void
    {
        $options = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enable_clipboard]" value="1" <?php checked($options['enable_clipboard'], 1); ?> />
            启用 Gutenberg 粘贴图片自动上云
        </label>
        <p class="description">拦截剪贴板里的图片文件，自动上传并插入外链，不占用媒体库。</p>
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

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($detected_type, $this->get_allowed_mime_types())) {
            return false;
        }

        // Additional validation: check file signatures
        $signatures = [
            'image/jpeg' => "\xFF\xD8\xFF",
            'image/png' => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",
            'image/gif' => "GIF87a",
            'image/webp' => "RIFF",
        ];

        if (isset($signatures[$detected_type])) {
            $handle = fopen($file['tmp_name'], 'rb');
            $header = fread($handle, strlen($signatures[$detected_type]));
            fclose($handle);

            if ($detected_type === 'image/webp') {
                return substr($header, 8, 4) === 'WEBP';
            }

            return $header === $signatures[$detected_type];
        }

        return true;
    }

    public function handle_ajax_upload(): void
    {
        // Enhanced nonce verification
        if (!Image2URL_Security::verify_nonce_security($_POST['nonce'] ?? '', 'image2url_upload')) {
            wp_send_json_error(['message' => __('安全验证失败。', 'image2url')]);
        }

        if (!current_user_can('upload_files')) {
            Image2URL_Security::log_security_event(
                'PERMISSION_DENIED',
                'User without upload_files permission attempted upload'
            );
            wp_die(__('您没有权限上传文件。', 'image2url'));
        }

        // Rate limiting
        if (!Image2URL_Security::check_rate_limit()) {
            Image2URL_Security::log_security_event(
                'RATE_LIMIT_EXCEEDED',
                'User exceeded upload rate limit'
            );
            wp_send_json_error(['message' => __('上传过于频繁，请稍后再试。', 'image2url')]);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('文件上传失败。', 'image2url')]);
        }

        $file = $_FILES['file'];

        // Enhanced file validation
        $security_errors = Image2URL_Security::validate_file_security($file);
        if (!empty($security_errors)) {
            Image2URL_Security::log_security_event(
                'FILE_VALIDATION_FAILED',
                'File failed security validation',
                ['errors' => $security_errors, 'filename' => $file['name']]
            );
            wp_send_json_error(['message' => implode(' ', $security_errors)]);
        }

        // Validate file type with signature checking
        if (!$this->validate_file_type($file)) {
            Image2URL_Security::log_security_event(
                'INVALID_FILE_TYPE',
                'Invalid file type detected',
                ['filename' => $file['name'], 'type' => $file['type']]
            );
            wp_send_json_error(['message' => __('不支持的文件类型。', 'image2url')]);
        }

        // Check file size
        $options = $this->get_options();
        $max_bytes = (int) ($options['max_size_mb'] * 1024 * 1024);
        if ($file['size'] > $max_bytes) {
            wp_send_json_error(['message' => __('文件过大。', 'image2url')]);
        }

        // Proceed with upload to external service
        $this->upload_to_external_service($file);
    }

    private function upload_to_external_service($file): void
    {
        $options = $this->get_options();
        $endpoint = $options['endpoint'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name'])
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Image2URL-WordPress/' . IMAGE2URL_VERSION,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            wp_send_json_error(['message' => __('上传请求失败：', 'image2url') . $error]);
        }

        if ($http_code !== 200) {
            wp_send_json_error(['message' => __('上传失败，HTTP状态码：', 'image2url') . $http_code]);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['url'])) {
            wp_send_json_error(['message' => __('上传服务返回无效响应。', 'image2url')]);
        }

        wp_send_json_success([
            'url' => $data['url'],
            'filename' => $file['name']
        ]);
    }

    public function handle_verify_endpoint(): void
    {
        check_ajax_referer('image2url_upload', 'nonce');

        $endpoint = sanitize_url($_POST['endpoint'] ?? '');
        if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('无效的端点URL。', 'image2url')]);
        }

        wp_send_json_success(['message' => __('端点验证通过。', 'image2url')]);
    }
}
