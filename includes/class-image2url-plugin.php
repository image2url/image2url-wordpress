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
        ];

        wp_localize_script('image2url-editor', 'image2urlConfig', $config);
    }
}
