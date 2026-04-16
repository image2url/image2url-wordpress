<?php

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Plugin
{
    private $option_key = 'image2url_settings';

    private $migrations;

    public function __construct()
    {
        $this->migrations = new Image2URL_Migrations();
    }

    public static function activate(): void
    {
        Image2URL_Migrations::activate();
    }

    public static function deactivate(): void
    {
        Image2URL_Migrations::deactivate();
    }

    public function init(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'add_privacy_policy_content']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('wp_ajax_image2url_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_ajax_image2url_verify_endpoint', [$this, 'handle_verify_endpoint']);

        add_filter(
            'plugin_action_links_' . plugin_basename(IMAGE2URL_PLUGIN_FILE),
            [$this, 'add_plugin_action_links']
        );

        $this->migrations->init();
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'image2url-clipboard-booster',
            false,
            dirname(plugin_basename(IMAGE2URL_PLUGIN_FILE)) . '/languages'
        );
    }

    private function get_default_endpoint(): string
    {
        return 'https://www.image2url.com/api/upload';
    }

    private function get_default_service_url(): string
    {
        return 'https://www.image2url.com/';
    }

    private function get_service_terms_url(): string
    {
        return 'https://www.image2url.com/en-IN/terms';
    }

    private function get_service_privacy_url(): string
    {
        return 'https://www.image2url.com/en-IN/privacy';
    }

    public function defaults(): array
    {
        return [
            'endpoint' => $this->get_default_endpoint(),
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
                echo '<p>' . esc_html__(
                    '配置上传端点、体积限制和编辑器行为。默认直传 image2url，不占用本地媒体库。',
                    'image2url-clipboard-booster'
                ) . '</p>';
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

    public function add_privacy_policy_content(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . esc_html__(
            'Image2URL Clipboard Booster 会在编辑器中粘贴图片时，把图片文件发送到你配置的远端上传端点。默认端点为 Image2URL，但站点管理员可以替换成自建的 HTTPS 上传服务。',
            'image2url-clipboard-booster'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            '在使用默认服务时，远端服务会接收图片文件本身，以及请求中附带的文件名、MIME 类型、文件大小等上传元数据。和任何标准 HTTPS 请求一样，对方服务及其基础设施也可能看到你站点服务器的 IP、User-Agent 和时间戳等网络层信息。',
            'image2url-clipboard-booster'
        ) . '</p>';

        $content .= '<p>' . wp_kses_post(
            sprintf(
                /* translators: 1: service homepage URL, 2: terms URL, 3: privacy URL. */
                __(
                    '默认服务站点：<a href="%1$s" target="_blank" rel="noopener noreferrer">Image2URL</a>。使用前请阅读其<a href="%2$s" target="_blank" rel="noopener noreferrer">服务条款</a>和<a href="%3$s" target="_blank" rel="noopener noreferrer">隐私政策</a>。',
                    'image2url-clipboard-booster'
                ),
                esc_url($this->get_default_service_url()),
                esc_url($this->get_service_terms_url()),
                esc_url($this->get_service_privacy_url())
            )
        ) . '</p>';

        wp_add_privacy_policy_content(
            'Image2URL Clipboard Booster',
            wp_kses_post($content)
        );
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->defaults();
        $current = $this->get_options();

        if (!is_array($input)) {
            return $current;
        }

        $sanitized = [];

        try {
            $sanitized['endpoint'] = isset($input['endpoint'])
                ? Image2URL_Security::validate_endpoint(wp_unslash($input['endpoint']))
                : $current['endpoint'];
        } catch (\InvalidArgumentException $exception) {
            $sanitized['endpoint'] = $current['endpoint'];

            add_settings_error(
                $this->option_key,
                'image2url_invalid_endpoint',
                $exception->getMessage()
            );
        }

        $size = isset($input['max_size_mb']) ? (float) $input['max_size_mb'] : $current['max_size_mb'];
        $sanitized['max_size_mb'] = min(20, max(0.1, $size > 0 ? $size : $defaults['max_size_mb']));
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $allowed_types = array_map(
            static function ($mime) {
                return strtoupper((string) preg_replace('/^image\//', '', $mime));
            },
            $this->get_allowed_mime_types()
        );
        ?>
        <div class="wrap">
            <h1>Image2URL Clipboard Booster</h1>
            <?php settings_errors($this->option_key); ?>
            <p><?php echo esc_html__('核心卖点：在 Gutenberg 里直接粘贴图片并上传到远端，降低共享主机 inode 和媒体库维护成本。', 'image2url-clipboard-booster'); ?></p>
            <p>
                <?php echo wp_kses_post(
                    sprintf(
                        /* translators: 1: service homepage URL, 2: terms URL, 3: privacy URL. */
                        __(
                            '默认上传服务为 <a href="%1$s" target="_blank" rel="noopener noreferrer">Image2URL</a>。无论你使用默认服务还是自定义上传端点，都应先确认远端服务的服务条款和隐私政策；默认服务文档见<a href="%2$s" target="_blank" rel="noopener noreferrer">服务条款</a>与<a href="%3$s" target="_blank" rel="noopener noreferrer">隐私政策</a>。',
                            'image2url-clipboard-booster'
                        ),
                        esc_url($this->get_default_service_url()),
                        esc_url($this->get_service_terms_url()),
                        esc_url($this->get_service_privacy_url())
                    )
                ); ?>
            </p>
            <form action="options.php" method="post">
                <?php
                settings_fields('image2url_settings');
                do_settings_sections('image2url');
                submit_button();
                ?>
            </form>
            <hr>
            <h2><?php echo esc_html__('运营建议', 'image2url-clipboard-booster'); ?></h2>
            <ul style="list-style: disc; padding-left: 1.25rem;">
                <li><?php echo esc_html__('保存设置前先验证端点，确保当前主机可以连通目标服务。', 'image2url-clipboard-booster'); ?></li>
                <li><?php echo esc_html__('自定义上传端点建议使用公开可访问的 HTTPS 地址；开发环境如需放宽限制，请通过过滤器显式覆盖。', 'image2url-clipboard-booster'); ?></li>
                <li><?php echo esc_html__('当前默认支持 JPEG、PNG、GIF、WebP。SVG 建议交给单独的安全管线处理。', 'image2url-clipboard-booster'); ?></li>
                <li><?php echo esc_html__('如需把文章中的外链图片回退到本地媒体库，请前往 工具 -> Image2URL Migration。', 'image2url-clipboard-booster'); ?></li>
            </ul>
            <p><strong><?php echo esc_html__('支持格式：', 'image2url-clipboard-booster'); ?></strong><?php echo esc_html(implode(', ', $allowed_types)); ?></p>
        </div>
        <?php
    }

    public function render_endpoint_field(): void
    {
        $options = $this->get_options();
        ?>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input
                type="url"
                name="<?php echo esc_attr($this->option_key); ?>[endpoint]"
                value="<?php echo esc_attr($options['endpoint']); ?>"
                class="regular-text"
                data-image2url-endpoint-field="true"
            />
            <button type="button" class="button" data-image2url-verify-endpoint="true">
                <?php echo esc_html__('验证端点', 'image2url-clipboard-booster'); ?>
            </button>
        </div>
        <p class="description"><?php echo esc_html__('默认 https://www.image2url.com/api/upload。仅支持公开可访问的 HTTPS 上传端点，可替换为自建服务或自定义域。', 'image2url-clipboard-booster'); ?></p>
        <p class="description">
            <?php echo wp_kses_post(
                sprintf(
                    /* translators: 1: terms URL, 2: privacy URL. */
                    __(
                        '默认服务文档：<a href="%1$s" target="_blank" rel="noopener noreferrer">服务条款</a> / <a href="%2$s" target="_blank" rel="noopener noreferrer">隐私政策</a>。',
                        'image2url-clipboard-booster'
                    ),
                    esc_url($this->get_service_terms_url()),
                    esc_url($this->get_service_privacy_url())
                )
            ); ?>
        </p>
        <p class="description" data-image2url-endpoint-status="true" aria-live="polite"></p>
        <?php
    }

    public function render_max_size_field(): void
    {
        $options = $this->get_options();
        ?>
        <input
            type="number"
            step="0.1"
            min="0.1"
            max="20"
            name="<?php echo esc_attr($this->option_key); ?>[max_size_mb]"
            value="<?php echo esc_attr($options['max_size_mb']); ?>"
        />
        <p class="description"><?php echo esc_html__('超过此体积将在本地阻断。建议与远端服务的单文件限制保持一致。', 'image2url-clipboard-booster'); ?></p>
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
        <p class="description"><?php echo esc_html__('拦截剪贴板中的图片文件，上传后直接插入外链图片块，不进入媒体库。', 'image2url-clipboard-booster'); ?></p>
        <?php
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ('settings_page_image2url' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'image2url-admin',
            IMAGE2URL_PLUGIN_URL . 'assets/js/admin-settings.js',
            [],
            IMAGE2URL_VERSION,
            true
        );

        wp_localize_script(
            'image2url-admin',
            'image2urlAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('image2url_verify_endpoint'),
                'messages' => [
                    'checking' => esc_html__('正在检测端点连通性...', 'image2url-clipboard-booster'),
                    'invalid' => esc_html__('请输入有效的 HTTPS 端点 URL。', 'image2url-clipboard-booster'),
                    'networkError' => esc_html__('端点检测失败，请检查网络或目标服务配置。', 'image2url-clipboard-booster'),
                ],
            ]
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
            'maxBytes' => (int) ($options['max_size_mb'] * 1024 * 1024),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('image2url_upload'),
            'allowedTypes' => $this->get_allowed_mime_types(),
        ];

        wp_localize_script('image2url-editor', 'image2urlConfig', $config);
    }

    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=image2url')),
            esc_html__('Settings', 'image2url-clipboard-booster')
        );
        $migration_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('tools.php?page=image2url-migration')),
            esc_html__('Migration', 'image2url-clipboard-booster')
        );

        array_unshift($links, $settings_link);
        array_unshift($links, $migration_link);

        return $links;
    }

    public function get_allowed_mime_types(): array
    {
        return apply_filters(
            'image2url_allowed_mime_types',
            [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ]
        );
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
        if (!$mime) {
            $mime = $check['type'];
        }

        return in_array($mime, $this->get_allowed_mime_types(), true);
    }

    public function handle_ajax_upload(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!Image2URL_Security::verify_nonce_security($nonce, 'image2url_upload')) {
            wp_send_json_error(['message' => esc_html__('安全验证失败。', 'image2url-clipboard-booster')], 403);
        }

        if (!current_user_can('upload_files')) {
            Image2URL_Security::log_security_event(
                'PERMISSION_DENIED',
                'User without upload_files permission attempted upload'
            );

            wp_send_json_error(['message' => esc_html__('您没有权限上传文件。', 'image2url-clipboard-booster')], 403);
        }

        if (!Image2URL_Security::check_rate_limit()) {
            Image2URL_Security::log_security_event(
                'RATE_LIMIT_EXCEEDED',
                'User exceeded upload rate limit'
            );

            wp_send_json_error(['message' => esc_html__('上传过于频繁，请稍后再试。', 'image2url-clipboard-booster')], 429);
        }

        if (
            !isset($_FILES['file']) ||
            !is_array($_FILES['file']) ||
            !isset($_FILES['file']['error'], $_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type'], $_FILES['file']['size']) ||
            (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK
        ) {
            wp_send_json_error(['message' => esc_html__('文件上传失败。', 'image2url-clipboard-booster')], 400);
        }

        $file = $_FILES['file'];
        $security_errors = Image2URL_Security::validate_file_security($file);
        if (!empty($security_errors)) {
            Image2URL_Security::log_security_event(
                'FILE_VALIDATION_FAILED',
                'File failed security validation',
                ['errors' => $security_errors, 'filename' => $file['name']]
            );

            wp_send_json_error(['message' => implode(' ', $security_errors)], 400);
        }

        if (!$this->validate_file_type($file)) {
            Image2URL_Security::log_security_event(
                'INVALID_FILE_TYPE',
                'Invalid file type detected',
                ['filename' => $file['name'], 'type' => $file['type']]
            );

            wp_send_json_error(['message' => esc_html__('不支持的文件类型。', 'image2url-clipboard-booster')], 400);
        }

        $options = $this->get_options();
        $max_bytes = (int) ($options['max_size_mb'] * 1024 * 1024);
        if ((int) $file['size'] > $max_bytes) {
            wp_send_json_error(['message' => esc_html__('文件过大。', 'image2url-clipboard-booster')], 400);
        }

        $this->upload_to_external_service($file);
    }

    private function get_options(): array
    {
        return wp_parse_args(
            get_option($this->option_key, []),
            $this->defaults()
        );
    }

    private function upload_to_external_service(array $file): void
    {
        $options = $this->get_options();
        $post_id = $this->get_request_post_id();

        try {
            $endpoint = Image2URL_Security::validate_endpoint($options['endpoint']);
        } catch (\InvalidArgumentException $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }

        $response = $this->send_external_upload_request($endpoint, $file);
        if (is_wp_error($response)) {
            Image2URL_Security::log_security_event(
                'REMOTE_UPLOAD_FAILED',
                'External upload transport failed',
                ['message' => $response->get_error_message()]
            );

            wp_send_json_error(
                ['message' => esc_html__('上传请求失败：', 'image2url-clipboard-booster') . $response->get_error_message()],
                502
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code < 200 || $http_code >= 300) {
            wp_send_json_error(
                ['message' => sprintf(
                    /* translators: %d is the remote HTTP status code. */
                    esc_html__('上传失败，远端服务返回 HTTP %d。', 'image2url-clipboard-booster'),
                    $http_code
                )],
                502
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            wp_send_json_error(['message' => esc_html__('上传服务返回了无效 JSON。', 'image2url-clipboard-booster')], 502);
        }

        $remote_url = $this->extract_remote_url($data);
        if (!$remote_url) {
            wp_send_json_error(['message' => esc_html__('上传服务响应中缺少图片 URL。', 'image2url-clipboard-booster')], 502);
        }

        $this->track_uploaded_remote_image($post_id, $remote_url);

        wp_send_json_success(
            [
                'url' => esc_url_raw($remote_url),
                'filename' => sanitize_file_name($file['name']),
            ]
        );
    }

    private function send_external_upload_request(string $endpoint, array $file)
    {
        return $this->upload_via_wp_http($endpoint, $file);
    }

    private function upload_via_wp_http(string $endpoint, array $file)
    {
        $multipart = $this->build_multipart_payload($file);
        if (is_wp_error($multipart)) {
            return $multipart;
        }

        $request_args = [
            'timeout' => 30,
            'redirection' => 3,
            'user-agent' => 'Image2URL-WordPress/' . IMAGE2URL_VERSION,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
            ],
            'body' => $multipart['body'],
            'data_format' => 'body',
        ];

        return wp_safe_remote_post(
            $endpoint,
            apply_filters('image2url_upload_request_args', $request_args, $endpoint, $file)
        );
    }

    private function build_multipart_payload(array $file)
    {
        $file_contents = file_get_contents($file['tmp_name']);
        if (false === $file_contents) {
            return new WP_Error('image2url_read_failed', esc_html__('无法读取待上传文件。', 'image2url-clipboard-booster'));
        }

        $boundary = 'image2url-' . wp_generate_password(12, false, false);
        $eol = "\r\n";
        $filename = str_replace(['"', "\r", "\n"], '', sanitize_file_name($file['name']));

        $body = '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: ' . $file['type'] . $eol . $eol;
        $body .= $file_contents . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        return [
            'boundary' => $boundary,
            'body' => $body,
        ];
    }

    private function extract_remote_url(array $data): string
    {
        $paths = [
            ['url'],
            ['data', 'url'],
            ['result', 'url'],
            ['image', 'url'],
            ['secure_url'],
        ];

        foreach ($paths as $path) {
            $value = $data;

            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }

                $value = $value[$segment];
            }

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                return $value;
            }
        }

        return '';
    }

    private function get_request_post_id(): int
    {
        $post_id = isset($_POST['postId']) ? absint(wp_unslash($_POST['postId'])) : 0;

        if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
            return 0;
        }

        return $post_id;
    }

    private function track_uploaded_remote_image(int $post_id, string $remote_url): void
    {
        if (!$remote_url) {
            return;
        }

        $this->migrations->track_remote_image($post_id, $remote_url);
    }

    public function handle_verify_endpoint(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!Image2URL_Security::verify_nonce_security($nonce, 'image2url_verify_endpoint')) {
            wp_send_json_error(['message' => esc_html__('安全验证失败。', 'image2url-clipboard-booster')], 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('您没有权限执行该操作。', 'image2url-clipboard-booster')], 403);
        }

        try {
            $endpoint = isset($_POST['endpoint'])
                ? Image2URL_Security::validate_endpoint(wp_unslash($_POST['endpoint']))
                : '';
        } catch (\InvalidArgumentException $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }

        $result = $this->check_endpoint_reachability($endpoint);
        if (!$result['success']) {
            wp_send_json_error(
                [
                    'message' => $result['message'],
                    'statusCode' => $result['statusCode'],
                ],
                502
            );
        }

        wp_send_json_success(
            [
                'message' => $result['message'],
                'statusCode' => $result['statusCode'],
            ]
        );
    }

    private function check_endpoint_reachability(string $endpoint): array
    {
        $request_args = [
            'timeout' => 10,
            'redirection' => 3,
            'sslverify' => true,
            'user-agent' => 'Image2URL-WordPress/' . IMAGE2URL_VERSION,
        ];

        $response = wp_safe_remote_request(
            $endpoint,
            array_merge($request_args, ['method' => 'HEAD'])
        );

        if (!is_wp_error($response)) {
            $status_code = (int) wp_remote_retrieve_response_code($response);

            if (405 === $status_code || 501 === $status_code) {
                $response = wp_safe_remote_get($endpoint, $request_args);
            }
        }

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'statusCode' => 0,
                'message' => esc_html__('无法连通该端点：', 'image2url-clipboard-booster') . $response->get_error_message(),
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 500) {
            return [
                'success' => true,
                'statusCode' => $status_code,
                'message' => sprintf(
                    /* translators: %d is the remote HTTP status code. */
                    esc_html__('端点可达，最近一次探测返回 HTTP %d。', 'image2url-clipboard-booster'),
                    $status_code
                ),
            ];
        }

        return [
            'success' => false,
            'statusCode' => $status_code,
            'message' => sprintf(
                /* translators: %d is the remote HTTP status code. */
                esc_html__('端点返回异常状态：HTTP %d。', 'image2url-clipboard-booster'),
                $status_code
            ),
        ];
    }
}
