<?php

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Migrations
{
    const TABLE_VERSION = '1.3.0';
    const DEFAULT_BATCH_SIZE = 2;
    const CRON_HOOK = 'image2url_migration_process_job';
    const JOB_LOCK_TTL = 300;
    const JOB_LOCK_PREFIX = 'image2url_migration_lock_';

    private $mapping_table_name;
    private $jobs_table_name;
    private $table_version_option = 'image2url_mapping_table_version';
    private $current_job_id = 0;
    private $last_validation_report = null;

    public function __construct()
    {
        global $wpdb;
        $this->mapping_table_name = $wpdb->prefix . 'image2url_mappings';
        $this->jobs_table_name = $wpdb->prefix . 'image2url_migration_jobs';
    }

    public static function activate(): void
    {
        $instance = new self();
        $instance->create_tables();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'maybe_install']);
        add_action('admin_menu', [$this, 'add_tools_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_tools_assets']);
        add_action(self::CRON_HOOK, [$this, 'handle_scheduled_job'], 10, 1);
        add_action('wp_ajax_image2url_migration_process_job', [$this, 'handle_ajax_process_job']);
        add_action('wp_ajax_image2url_migration_get_job', [$this, 'handle_ajax_get_job']);
    }

    public function maybe_install(): void
    {
        if (get_option($this->table_version_option) !== self::TABLE_VERSION) {
            $this->create_tables();
        }
    }

    public function add_tools_page(): void
    {
        add_management_page('Image2URL Migration', 'Image2URL Migration', 'upload_files', 'image2url-migration', [$this, 'render_tools_page']);
    }

    public function enqueue_tools_assets(string $hook): void
    {
        if ('tools_page_image2url-migration' !== $hook) {
            return;
        }

        $job = $this->get_current_job();

        wp_enqueue_script(
            'image2url-migration-jobs',
            IMAGE2URL_PLUGIN_URL . 'assets/js/migration-jobs.js',
            [],
            IMAGE2URL_VERSION,
            true
        );

        wp_localize_script(
            'image2url-migration-jobs',
            'image2urlMigration',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('image2url_migration_job'),
                'currentJobId' => !empty($job['id']) ? (int) $job['id'] : 0,
                'currentJobStatus' => !empty($job['status']) ? $job['status'] : '',
                'pollInterval' => 3000,
                'messages' => [
                    'running' => esc_html__('后台正在执行批量任务...', 'image2url-clipboard-booster'),
                    'idle' => esc_html__('等待开始。', 'image2url-clipboard-booster'),
                    'completed' => esc_html__('任务已完成。', 'image2url-clipboard-booster'),
                    'completedWithErrors' => esc_html__('任务已完成，但存在失败项。', 'image2url-clipboard-booster'),
                    'error' => esc_html__('任务执行失败，请刷新页面后重试。', 'image2url-clipboard-booster'),
                    'resume' => esc_html__('继续执行', 'image2url-clipboard-booster'),
                    'start' => esc_html__('开始执行', 'image2url-clipboard-booster'),
                    'retry' => esc_html__('重新入队', 'image2url-clipboard-booster'),
                    'processing' => esc_html__('正在加入后台队列...', 'image2url-clipboard-booster'),
                    'scheduled' => esc_html__('任务已加入后台队列，等待 WP-Cron 执行。', 'image2url-clipboard-booster'),
                    'runningBackground' => esc_html__('后台执行中', 'image2url-clipboard-booster'),
                    'refreshing' => esc_html__('正在刷新任务状态...', 'image2url-clipboard-booster'),
                ],
            ]
        );
    }

    public function track_remote_image(int $post_id, string $remote_url): void
    {
        $remote_url = esc_url_raw($remote_url);
        if (!$remote_url) {
            return;
        }

        $existing = $this->find_mapping($post_id, $remote_url);

        $this->upsert_mapping(
            $post_id,
            $remote_url,
            [
                'status' => !empty($existing['local_attachment_id']) ? 'localized' : 'remote_only',
                'last_error' => null,
            ]
        );
    }

    public function render_tools_page(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        $this->handle_tools_action();

        $stats = $this->get_mapping_stats();
        $recent_rows = $this->get_recent_mappings();
        $recent_jobs = $this->get_recent_jobs();
        $current_job = $this->get_current_job();
        ?>
        <div class="wrap">
            <h1>Image2URL Migration</h1>
            <?php settings_errors('image2url_migration'); ?>
            <?php $this->render_query_notice(); ?>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('检测到站点已禁用 WP-Cron。若未在服务器侧单独触发 wp-cron.php，批量任务将无法自动推进。', 'image2url-clipboard-booster'); ?></p>
                </div>
            <?php endif; ?>

            <p><?php echo esc_html__('这个页面用于把文章里的外链图片下载到 WordPress 媒体库、将内容中的外链替换为本地 URL，并验证回退结果。', 'image2url-clipboard-booster'); ?></p>
            <p><?php echo esc_html__('回退时会优先把 core/image、core/cover 和 core/media-text 区块同步为本地附件引用；如果文章还没有特色图，会尝试将正文首张已本地化图片设为特色图。', 'image2url-clipboard-booster'); ?></p>
            <p><?php echo esc_html__('单篇模式适合即时回退和验证；批量模式会创建后台任务，由 WP-Cron 按批次逐篇处理，不依赖当前页面持续打开。', 'image2url-clipboard-booster'); ?></p>

            <h2><?php echo esc_html__('状态概览', 'image2url-clipboard-booster'); ?></h2>
            <table class="widefat striped" style="max-width: 760px;">
                <tbody>
                    <tr><th><?php echo esc_html__('映射总数', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $stats['total']); ?></td></tr>
                    <tr><th><?php echo esc_html__('仅远端', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $stats['remote_only']); ?></td></tr>
                    <tr><th><?php echo esc_html__('已本地化', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $stats['localized']); ?></td></tr>
                    <tr><th><?php echo esc_html__('失败', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $stats['failed']); ?></td></tr>
                </tbody>
            </table>

            <hr>
            <h2><?php echo esc_html__('单篇文章', 'image2url-clipboard-booster'); ?></h2>
            <form method="post" style="margin-bottom: 16px;">
                <?php wp_nonce_field('image2url_migration_action', 'image2url_migration_nonce'); ?>
                <input type="hidden" name="image2url_migration_action" value="scan_post" />
                <label for="image2url-post-id-scan"><strong><?php echo esc_html__('文章 ID', 'image2url-clipboard-booster'); ?></strong></label>
                <input id="image2url-post-id-scan" type="number" min="1" name="post_id" value="" class="small-text" />
                <?php submit_button(__('扫描外链图片', 'image2url-clipboard-booster'), 'secondary', 'submit', false); ?>
            </form>

            <form method="post" style="margin-bottom: 24px;">
                <?php wp_nonce_field('image2url_migration_action', 'image2url_migration_nonce'); ?>
                <input type="hidden" name="image2url_migration_action" value="rollback_post" />
                <label for="image2url-post-id-rollback"><strong><?php echo esc_html__('文章 ID', 'image2url-clipboard-booster'); ?></strong></label>
                <input id="image2url-post-id-rollback" type="number" min="1" name="post_id" value="" class="small-text" />
                <?php submit_button(__('回退到本地媒体库', 'image2url-clipboard-booster'), 'primary', 'submit', false); ?>
            </form>

            <form method="post" style="margin-bottom: 24px;">
                <?php wp_nonce_field('image2url_migration_action', 'image2url_migration_nonce'); ?>
                <input type="hidden" name="image2url_migration_action" value="validate_post" />
                <label for="image2url-post-id-validate"><strong><?php echo esc_html__('文章 ID', 'image2url-clipboard-booster'); ?></strong></label>
                <input id="image2url-post-id-validate" type="number" min="1" name="post_id" value="" class="small-text" />
                <?php submit_button(__('验证回退结果', 'image2url-clipboard-booster'), 'secondary', 'submit', false); ?>
            </form>

            <?php if (!empty($this->last_validation_report)) : ?>
                <?php $this->render_validation_report($this->last_validation_report); ?>
            <?php endif; ?>

            <h2><?php echo esc_html__('批量回退队列', 'image2url-clipboard-booster'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('image2url_migration_action', 'image2url_migration_nonce'); ?>
                <input type="hidden" name="image2url_migration_action" value="queue_batch_job" />
                <p>
                    <label for="image2url-post-ids"><strong><?php echo esc_html__('文章 ID 列表', 'image2url-clipboard-booster'); ?></strong></label><br />
                    <textarea id="image2url-post-ids" name="post_ids" rows="4" cols="70" placeholder="12, 35, 48"></textarea>
                </p>
                <p class="description"><?php echo esc_html__('输入逗号、空格或换行分隔的文章 ID。系统会创建后台任务并按批次逐篇回退，适合大站点处理。', 'image2url-clipboard-booster'); ?></p>
                <?php submit_button(__('创建批量回退任务', 'image2url-clipboard-booster'), 'primary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('批量验证队列', 'image2url-clipboard-booster'); ?></h2>
            <form method="post" style="margin-bottom: 24px;">
                <?php wp_nonce_field('image2url_migration_action', 'image2url_migration_nonce'); ?>
                <input type="hidden" name="image2url_migration_action" value="queue_validation_job" />
                <p>
                    <label for="image2url-validation-post-ids"><strong><?php echo esc_html__('文章 ID 列表', 'image2url-clipboard-booster'); ?></strong></label><br />
                    <textarea id="image2url-validation-post-ids" name="post_ids" rows="4" cols="70" placeholder="12, 35, 48"></textarea>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="audit_all_posts" value="1" />
                        <?php echo esc_html__('审计当前可访问的全部已发布文章', 'image2url-clipboard-booster'); ?>
                    </label>
                </p>
                <p class="description"><?php echo esc_html__('可输入指定文章 ID，也可以直接做全站审计。验证任务会检查残留外链、块附件绑定和特色图状态。', 'image2url-clipboard-booster'); ?></p>
                <?php submit_button(__('创建批量验证任务', 'image2url-clipboard-booster'), 'secondary', 'submit', false); ?>
            </form>

            <?php if (!empty($current_job)) : ?>
                <?php $this->render_current_job_panel($current_job); ?>
            <?php endif; ?>

            <hr>
            <h2><?php echo esc_html__('最近任务', 'image2url-clipboard-booster'); ?></h2>
            <?php if (empty($recent_jobs)) : ?>
                <p><?php echo esc_html__('还没有批量任务记录。', 'image2url-clipboard-booster'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('任务', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('类型', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('状态', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('进度', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('结果摘要', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('更新时间', 'image2url-clipboard-booster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_jobs as $job) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($this->build_job_link((int) $job['id'])); ?>">#<?php echo esc_html((string) $job['id']); ?></a>
                                    <?php if ('validation' === $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'))) : ?>
                                        <br />
                                        <a href="<?php echo esc_url($this->build_job_export_link((int) $job['id'])); ?>" class="button-link"><?php echo esc_html__('导出 CSV', 'image2url-clipboard-booster'); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($this->get_job_type_label((string) ($job['job_type'] ?? 'rollback'))); ?></td>
                                <td><?php echo esc_html($job['status']); ?></td>
                                <td><?php echo esc_html((string) $job['processed_posts']); ?>/<?php echo esc_html((string) $job['total_posts']); ?></td>
                                <td><?php echo esc_html($this->get_job_results_summary($job)); ?></td>
                                <td><?php echo esc_html($job['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h2><?php echo esc_html__('最近映射', 'image2url-clipboard-booster'); ?></h2>
            <?php if (empty($recent_rows)) : ?>
                <p><?php echo esc_html__('还没有可展示的映射记录。', 'image2url-clipboard-booster'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('文章', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('远端 URL', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('状态', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('本地附件', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('更新时间', 'image2url-clipboard-booster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_rows as $row) : ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['post_id'])) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link((int) $row['post_id'])); ?>"><?php echo esc_html(get_the_title((int) $row['post_id']) ?: '#' . (int) $row['post_id']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html__('未关联文章', 'image2url-clipboard-booster'); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="word-break: break-all;"><?php echo esc_html($row['remote_url']); ?></td>
                                <td><?php echo esc_html($row['status']); ?></td>
                                <td>
                                    <?php if (!empty($row['local_attachment_id'])) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link((int) $row['local_attachment_id'])); ?>">#<?php echo esc_html((string) $row['local_attachment_id']); ?></a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($row['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function scan_post_for_mappings(int $post_id, int $actor_user_id = 0): array
    {
        $post = $this->validate_target_post($post_id, $actor_user_id);
        if (is_wp_error($post)) {
            return ['error' => $post->get_error_message()];
        }

        $urls = $this->extract_remote_image_urls($post->post_content);
        $created = 0;
        $updated = 0;

        foreach ($urls as $remote_url) {
            $existing = $this->find_mapping($post_id, $remote_url);
            $this->upsert_mapping(
                $post_id,
                $remote_url,
                ['status' => !empty($existing['local_attachment_id']) ? 'localized' : 'remote_only', 'last_error' => null]
            );

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }
        }

        return ['post_id' => $post_id, 'post_title' => get_the_title($post_id), 'urls' => $urls, 'found' => count($urls), 'created' => $created, 'updated' => $updated];
    }

    public function rollback_post(int $post_id, int $actor_user_id = 0): array
    {
        $scan_result = $this->scan_post_for_mappings($post_id, $actor_user_id);
        if (!empty($scan_result['error'])) {
            return $scan_result;
        }

        $replacements = [];
        $attachment_map = [];
        $errors = [];
        $localized = 0;

        foreach ($scan_result['urls'] as $remote_url) {
            $attachment_id = $this->get_or_create_local_attachment($post_id, $remote_url);
            if (is_wp_error($attachment_id)) {
                $errors[$remote_url] = $attachment_id->get_error_message();
                $this->upsert_mapping($post_id, $remote_url, ['status' => 'failed', 'last_error' => $attachment_id->get_error_message()]);
                continue;
            }

            $local_url = wp_get_attachment_url($attachment_id);
            if (!$local_url) {
                $errors[$remote_url] = esc_html__('本地附件已创建，但无法读取附件 URL。', 'image2url-clipboard-booster');
                continue;
            }

            $replacements[$remote_url] = $local_url;
            $attachment_map[$remote_url] = (int) $attachment_id;
            $localized++;
        }

        $replaced = 0;
        $synced_blocks = 0;
        $featured_image_set = false;
        if (!empty($replacements)) {
            $replace_result = $this->replace_post_content_urls($post_id, $replacements, $attachment_map);
            if (is_wp_error($replace_result)) {
                $errors['post_update'] = $replace_result->get_error_message();
            } else {
                $replaced = (int) ($replace_result['replaced'] ?? 0);
                $synced_blocks = (int) ($replace_result['synced_blocks'] ?? 0);
                $featured_image_set = !empty($replace_result['featured_image_set']);
            }
        }

        return ['post_id' => $post_id, 'post_title' => get_the_title($post_id), 'found' => $scan_result['found'], 'localized' => $localized, 'replaced' => $replaced, 'synced_blocks' => $synced_blocks, 'featured_image_set' => $featured_image_set, 'failed' => count($errors), 'errors' => $errors];
    }

    public function validate_post_localization(int $post_id, int $actor_user_id = 0): array
    {
        $post = $this->validate_target_post($post_id, $actor_user_id);
        if (is_wp_error($post)) {
            return ['error' => $post->get_error_message()];
        }

        $content = (string) $post->post_content;
        $mappings = $this->get_post_mappings($post_id);
        $remaining_remote_urls = $this->extract_remote_image_urls($content);
        $issues = [];
        $block_summary = [
            'checked' => 0,
            'issues' => 0,
        ];

        $mapping_index = [];
        $localized_mappings = 0;
        foreach ($mappings as $mapping) {
            if (empty($mapping['remote_url']) || !is_string($mapping['remote_url'])) {
                continue;
            }

            $remote_url = (string) $mapping['remote_url'];
            $mapping_index[$remote_url] = $mapping;

            $attachment_id = !empty($mapping['local_attachment_id']) ? absint($mapping['local_attachment_id']) : 0;
            $has_valid_attachment = $attachment_id > 0 && get_post($attachment_id);
            if ($has_valid_attachment) {
                $localized_mappings++;
            }

            if (in_array($remote_url, $remaining_remote_urls, true)) {
                $issues[] = sprintf(
                    esc_html__('正文仍引用远端图片：%s', 'image2url-clipboard-booster'),
                    $remote_url
                );
            }

            if ($attachment_id > 0 && !$has_valid_attachment) {
                $issues[] = sprintf(
                    esc_html__('映射记录指向的本地附件不存在：%1$s -> #%2$d', 'image2url-clipboard-booster'),
                    $remote_url,
                    $attachment_id
                );
            }
        }

        if (function_exists('has_blocks') && function_exists('parse_blocks') && has_blocks($content)) {
            $blocks = parse_blocks($content);
            if (is_array($blocks)) {
                $this->collect_block_validation_issues($blocks, $mapping_index, $issues, $block_summary);
            }
        }

        $featured_image_id = has_post_thumbnail($post_id) ? (int) get_post_thumbnail_id($post_id) : 0;
        if ($featured_image_id > 0 && !get_post($featured_image_id)) {
            $issues[] = sprintf(
                esc_html__('特色图引用的附件不存在：#%d', 'image2url-clipboard-booster'),
                $featured_image_id
            );
        } elseif (0 === $featured_image_id && $localized_mappings > 0 && post_type_supports((string) get_post_type($post_id), 'thumbnail')) {
            $issues[] = esc_html__('文章没有特色图，但已有可用的本地化图片。', 'image2url-clipboard-booster');
        }

        return [
            'post_id' => $post_id,
            'post_title' => get_the_title($post_id),
            'mapping_total' => count($mappings),
            'localized_mappings' => $localized_mappings,
            'remaining_remote_urls' => $remaining_remote_urls,
            'checked_blocks' => (int) $block_summary['checked'],
            'block_issues' => (int) $block_summary['issues'],
            'featured_image_id' => $featured_image_id,
            'issues' => array_values(array_unique(array_filter($issues))),
            'passed' => empty($issues),
        ];
    }

    public function handle_ajax_process_job(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'image2url_migration_job')) {
            wp_send_json_error(['message' => esc_html__('安全验证失败。', 'image2url-clipboard-booster')], 403);
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('您没有权限执行迁移任务。', 'image2url-clipboard-booster')], 403);
        }

        $job_id = isset($_POST['jobId']) ? absint(wp_unslash($_POST['jobId'])) : 0;
        $result = $this->queue_job_for_background($job_id);
        if (is_wp_error($result) || !$result) {
            wp_send_json_error(['message' => is_wp_error($result) ? $result->get_error_message() : esc_html__('任务启动失败，请稍后重试。', 'image2url-clipboard-booster')], 400);
        }

        wp_send_json_success($this->format_job_for_response($result));
    }

    public function handle_ajax_get_job(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'image2url_migration_job')) {
            wp_send_json_error(['message' => esc_html__('安全验证失败。', 'image2url-clipboard-booster')], 403);
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('您没有权限查看迁移任务。', 'image2url-clipboard-booster')], 403);
        }

        $job_id = isset($_POST['jobId']) ? absint(wp_unslash($_POST['jobId'])) : 0;
        $job = $this->get_job($job_id);
        if (!$job || !$this->can_access_job($job)) {
            wp_send_json_error(['message' => esc_html__('未找到可访问的批量任务。', 'image2url-clipboard-booster')], 404);
        }

        wp_send_json_success($this->format_job_for_response($job));
    }

    public function handle_scheduled_job(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        if (!$this->acquire_job_lock($job_id)) {
            return;
        }

        try {
            $result = $this->process_job_batch($job_id, self::DEFAULT_BATCH_SIZE, true);
            if (is_wp_error($result)) {
                $this->update_job(
                    $job_id,
                    [
                        'status' => 'failed',
                        'last_message' => $result->get_error_message(),
                        'updated_at' => current_time('mysql'),
                        'completed_at' => current_time('mysql'),
                    ]
                );
                return;
            }

            if (!empty($result) && empty($this->format_job_for_response($result)['completed'])) {
                $this->schedule_job($job_id, 5);
            }
        } finally {
            $this->release_job_lock($job_id);
        }
    }

    private function handle_tools_action(): void
    {
        $get_action = isset($_GET['image2url_migration_action']) ? sanitize_key(wp_unslash($_GET['image2url_migration_action'])) : '';
        if ('export_job_report' === $get_action) {
            $job_id = isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0;
            check_admin_referer('image2url_export_job_report_' . $job_id);
            $this->export_validation_job_report($job_id);
            return;
        }

        $requested_job_id = isset($_GET['job_id']) ? absint(wp_unslash($_GET['job_id'])) : 0;
        if ($requested_job_id > 0) {
            $requested_job = $this->get_job($requested_job_id);
            if ($requested_job && $this->can_access_job($requested_job)) {
                $this->current_job_id = $requested_job_id;
            }
        }

        if (!isset($_POST['image2url_migration_action'], $_POST['image2url_migration_nonce']) || !is_string($_POST['image2url_migration_action'])) {
            return;
        }

        check_admin_referer('image2url_migration_action', 'image2url_migration_nonce');
        $action = sanitize_key(wp_unslash($_POST['image2url_migration_action']));

        switch ($action) {
            case 'scan_post':
                $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
                $result = $this->scan_post_for_mappings($post_id);
                if (!empty($result['error'])) {
                    add_settings_error('image2url_migration', 'image2url_scan_error', $result['error'], 'error');
                    return;
                }
                add_settings_error('image2url_migration', 'image2url_scan_success', sprintf(esc_html__('文章“%1$s”扫描完成，发现 %2$d 张外链图片，新建 %3$d 条映射。', 'image2url-clipboard-booster'), $result['post_title'] ?: '#' . $post_id, (int) $result['found'], (int) $result['created']), 'updated');
                return;

            case 'rollback_post':
                $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
                $result = $this->rollback_post($post_id);
                if (!empty($result['error'])) {
                    add_settings_error('image2url_migration', 'image2url_rollback_error', $result['error'], 'error');
                    return;
                }
                $message = sprintf(esc_html__('文章“%1$s”回退完成，下载 %2$d 张，替换 %3$d 处，失败 %4$d 张。', 'image2url-clipboard-booster'), $result['post_title'] ?: '#' . $post_id, (int) $result['localized'], (int) $result['replaced'], (int) $result['failed']);
                if (!empty($result['synced_blocks'])) {
                    $message .= ' ' . sprintf(esc_html__('同步 %d 个图片区块到本地附件。', 'image2url-clipboard-booster'), (int) $result['synced_blocks']);
                }
                if (!empty($result['featured_image_set'])) {
                    $message .= ' ' . esc_html__('已自动设置正文首图为特色图。', 'image2url-clipboard-booster');
                }
                if (!empty($result['errors'])) {
                    $message .= ' ' . implode(' ', array_values($result['errors']));
                }
                add_settings_error('image2url_migration', 'image2url_rollback_success', $message, 'updated');
                return;

            case 'validate_post':
                $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
                $result = $this->validate_post_localization($post_id);
                if (!empty($result['error'])) {
                    add_settings_error('image2url_migration', 'image2url_validate_error', $result['error'], 'error');
                    return;
                }

                $this->last_validation_report = $result;
                $summary = sprintf(
                    esc_html__('文章“%1$s”验证完成：映射 %2$d 条，已本地化 %3$d 条，残留外链 %4$d 条，检测区块 %5$d 个，问题 %6$d 项。', 'image2url-clipboard-booster'),
                    $result['post_title'] ?: '#' . $post_id,
                    (int) $result['mapping_total'],
                    (int) $result['localized_mappings'],
                    count($result['remaining_remote_urls']),
                    (int) $result['checked_blocks'],
                    count($result['issues'])
                );
                add_settings_error('image2url_migration', 'image2url_validate_result', $summary, !empty($result['passed']) ? 'updated' : 'error');
                return;

            case 'queue_batch_job':
                $post_ids = $this->parse_post_ids(isset($_POST['post_ids']) ? wp_unslash($_POST['post_ids']) : '');
                if (empty($post_ids)) {
                    add_settings_error('image2url_migration', 'image2url_batch_error', esc_html__('请至少输入一个有效的文章 ID。', 'image2url-clipboard-booster'), 'error');
                    return;
                }
                $job_id = $this->create_batch_job($post_ids, 'rollback');
                if ($job_id <= 0) {
                    add_settings_error('image2url_migration', 'image2url_batch_error', esc_html__('创建批量任务失败，请稍后重试。', 'image2url-clipboard-booster'), 'error');
                    return;
                }
                $queue_result = $this->queue_job_for_background($job_id);
                if (is_wp_error($queue_result)) {
                    add_settings_error('image2url_migration', 'image2url_batch_error', $queue_result->get_error_message(), 'error');
                    return;
                }
                wp_safe_redirect(add_query_arg(['page' => 'image2url-migration', 'job_id' => $job_id, 'image2url_notice' => 'batch_job_created'], admin_url('tools.php')));
                exit;

            case 'queue_validation_job':
                $audit_all_posts = !empty($_POST['audit_all_posts']);
                $post_ids = $audit_all_posts
                    ? $this->get_all_auditable_post_ids()
                    : $this->parse_post_ids(isset($_POST['post_ids']) ? wp_unslash($_POST['post_ids']) : '');

                if (empty($post_ids)) {
                    add_settings_error('image2url_migration', 'image2url_validation_batch_error', esc_html__('请至少输入一个有效的文章 ID，或选择全站审计。', 'image2url-clipboard-booster'), 'error');
                    return;
                }

                $job_id = $this->create_batch_job($post_ids, 'validation');
                if ($job_id <= 0) {
                    add_settings_error('image2url_migration', 'image2url_validation_batch_error', esc_html__('创建批量验证任务失败，请稍后重试。', 'image2url-clipboard-booster'), 'error');
                    return;
                }

                $queue_result = $this->queue_job_for_background($job_id);
                if (is_wp_error($queue_result)) {
                    add_settings_error('image2url_migration', 'image2url_validation_batch_error', $queue_result->get_error_message(), 'error');
                    return;
                }

                wp_safe_redirect(add_query_arg(['page' => 'image2url-migration', 'job_id' => $job_id, 'image2url_notice' => 'batch_job_created'], admin_url('tools.php')));
                exit;
        }
    }

    private function render_query_notice(): void
    {
        $notice = isset($_GET['image2url_notice']) ? sanitize_key(wp_unslash($_GET['image2url_notice'])) : '';
        if ('batch_job_created' !== $notice || $this->current_job_id <= 0) {
            return;
        }

        $current_job = $this->get_current_job();
        $job_label = $this->get_job_type_label((string) ($current_job['job_type'] ?? 'rollback'));
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html(sprintf(__('%1$s #%2$d 已创建，并已加入后台队列。页面会自动刷新状态。', 'image2url-clipboard-booster'), $job_label, $this->current_job_id)); ?></p>
        </div>
        <?php
    }

    private function render_current_job_panel(array $job): void
    {
        $job_view = $this->format_job_for_response($job);
        $metric_labels = $this->get_job_metric_labels((string) ($job_view['jobType'] ?? 'rollback'));
        $is_validation_job = 'validation' === $this->normalize_job_type((string) ($job_view['jobType'] ?? 'rollback'));
        ?>
        <hr>
        <h2><?php echo esc_html__('当前任务', 'image2url-clipboard-booster'); ?></h2>
        <div data-image2url-job-panel="true" data-job-id="<?php echo esc_attr((string) $job_view['id']); ?>" data-job-status="<?php echo esc_attr($job_view['status']); ?>" style="border:1px solid #dcdcde; background:#fff; padding:16px; max-width:900px;">
            <p><strong><?php echo esc_html__('任务 #', 'image2url-clipboard-booster') . esc_html((string) $job_view['id']); ?></strong> <span style="margin-left:12px;"><?php echo esc_html__('类型：', 'image2url-clipboard-booster'); ?><?php echo esc_html($this->get_job_type_label((string) ($job_view['jobType'] ?? 'rollback'))); ?></span> <span style="margin-left:12px;"><?php echo esc_html__('状态：', 'image2url-clipboard-booster'); ?><span data-image2url-job-status-label="true"><?php echo esc_html($job_view['status']); ?></span></span></p>
            <p data-image2url-job-message="true"><?php echo esc_html($job_view['lastMessage']); ?></p>
            <table class="widefat striped" style="max-width:760px; margin-bottom:12px;">
                <tbody>
                    <tr><th><?php echo esc_html__('进度', 'image2url-clipboard-booster'); ?></th><td><span data-image2url-job-progress="true"><?php echo esc_html((string) $job_view['processedPosts']); ?>/<?php echo esc_html((string) $job_view['totalPosts']); ?></span></td></tr>
                    <tr><th><?php echo esc_html($metric_labels['primary']); ?></th><td><span data-image2url-job-localized="true"><?php echo esc_html((string) $job_view['localizedCount']); ?></span></td></tr>
                    <tr><th><?php echo esc_html($metric_labels['secondary']); ?></th><td><span data-image2url-job-replaced="true"><?php echo esc_html((string) $job_view['replacedCount']); ?></span></td></tr>
                    <tr><th><?php echo esc_html($metric_labels['failure']); ?></th><td><span data-image2url-job-failed="true"><?php echo esc_html((string) $job_view['failedCount']); ?></span></td></tr>
                </tbody>
            </table>
            <p>
                <button type="button" class="button button-primary" data-image2url-run-job="true"><?php echo esc_html($this->get_job_button_label($job_view['status'])); ?></button>
                <?php if ($is_validation_job) : ?>
                    <a href="<?php echo esc_url($this->build_job_export_link((int) $job_view['id'])); ?>" class="button button-secondary" style="margin-left:8px;"><?php echo esc_html__('导出 CSV', 'image2url-clipboard-booster'); ?></a>
                <?php endif; ?>
            </p>
            <p><strong><?php echo esc_html__('最近日志', 'image2url-clipboard-booster'); ?></strong></p>
            <pre data-image2url-job-log="true" style="background:#f6f7f7; border:1px solid #dcdcde; padding:12px; max-height:220px; overflow:auto; white-space:pre-wrap;"><?php echo esc_html($job_view['errorLog']); ?></pre>
            <?php if ($is_validation_job) : ?>
                <?php $this->render_validation_job_report_preview($job); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_validation_report(array $report): void
    {
        ?>
        <div style="border:1px solid #dcdcde; background:#fff; padding:16px; max-width:900px; margin-bottom:24px;">
            <h3 style="margin-top:0;"><?php echo esc_html__('回退验证结果', 'image2url-clipboard-booster'); ?></h3>
            <table class="widefat striped" style="max-width:760px; margin-bottom:12px;">
                <tbody>
                    <tr><th><?php echo esc_html__('文章', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html($report['post_title'] ?: '#' . (int) $report['post_id']); ?></td></tr>
                    <tr><th><?php echo esc_html__('映射总数', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $report['mapping_total']); ?></td></tr>
                    <tr><th><?php echo esc_html__('有效本地化映射', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $report['localized_mappings']); ?></td></tr>
                    <tr><th><?php echo esc_html__('残留远端图片', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) count($report['remaining_remote_urls'])); ?></td></tr>
                    <tr><th><?php echo esc_html__('已检查媒体块', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $report['checked_blocks']); ?></td></tr>
                    <tr><th><?php echo esc_html__('块级问题', 'image2url-clipboard-booster'); ?></th><td><?php echo esc_html((string) $report['block_issues']); ?></td></tr>
                    <tr><th><?php echo esc_html__('特色图', 'image2url-clipboard-booster'); ?></th><td><?php echo !empty($report['featured_image_id']) ? esc_html('#' . (int) $report['featured_image_id']) : esc_html__('未设置', 'image2url-clipboard-booster'); ?></td></tr>
                    <tr><th><?php echo esc_html__('结果', 'image2url-clipboard-booster'); ?></th><td><?php echo !empty($report['passed']) ? esc_html__('通过', 'image2url-clipboard-booster') : esc_html__('发现问题', 'image2url-clipboard-booster'); ?></td></tr>
                </tbody>
            </table>

            <?php if (empty($report['issues'])) : ?>
                <p><?php echo esc_html__('没有发现明显的回退残留或块引用问题。', 'image2url-clipboard-booster'); ?></p>
            <?php else : ?>
                <p><strong><?php echo esc_html__('问题明细', 'image2url-clipboard-booster'); ?></strong></p>
                <ul style="list-style:disc; padding-left:20px;">
                    <?php foreach ($report['issues'] as $issue) : ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_validation_job_report_preview(array $job): void
    {
        $entries = $this->get_job_report_entries($job);
        $filters = $this->get_validation_report_filters();
        $summary = $this->summarize_validation_report_entries($entries);
        $filtered_entries = $this->filter_validation_report_entries($entries, $filters['result_status'], $filters['issue_type'], $filters['severity'], $filters['post_type']);
        $ordered_entries = ('all' === $filters['result_status'] && 'all' === $filters['issue_type'] && 'all' === $filters['severity'] && 'all' === $filters['post_type'])
            ? $this->prioritize_validation_report_entries($filtered_entries)
            : $filtered_entries;
        $preview_entries = array_slice($ordered_entries, 0, 20);
        $job_id = (int) ($job['id'] ?? 0);
        ?>
        <div style="margin-top:16px;">
            <p><strong><?php echo esc_html__('验证报告预览', 'image2url-clipboard-booster'); ?></strong></p>
            <?php if (empty($entries)) : ?>
                <p><?php echo esc_html__('当前还没有可导出的验证结果。任务至少处理一篇文章后，就可以下载 CSV。', 'image2url-clipboard-booster'); ?></p>
            <?php else : ?>
                <p class="description"><?php echo esc_html(sprintf(__('已记录 %d 篇文章的验证结果。默认会优先展示有问题的条目；完整结果请使用上方筛选或 CSV 导出。', 'image2url-clipboard-booster'), count($entries))); ?></p>
                <p>
                    <strong><?php echo esc_html__('结果筛选：', 'image2url-clipboard-booster'); ?></strong>
                    <?php foreach ($this->get_validation_result_filter_options() as $result_key => $label) : ?>
                        <?php $is_active = $filters['result_status'] === $result_key; ?>
                        <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_result' => $result_key])); ?>" class="<?php echo $is_active ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html($label); ?><?php if (isset($summary['result_counts'][$result_key])) : ?> (<?php echo esc_html((string) $summary['result_counts'][$result_key]); ?>)<?php endif; ?></a>
                    <?php endforeach; ?>
                </p>
                <?php if (!empty($summary['severity_counts'])) : ?>
                    <p>
                        <strong><?php echo esc_html__('严重度：', 'image2url-clipboard-booster'); ?></strong>
                        <?php foreach ($this->get_validation_severity_filter_options() as $severity_key => $label) : ?>
                            <?php $is_active = $filters['severity'] === $severity_key; ?>
                            <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_severity' => $severity_key])); ?>" class="<?php echo $is_active ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html($label); ?><?php if (isset($summary['severity_counts'][$severity_key])) : ?> (<?php echo esc_html((string) $summary['severity_counts'][$severity_key]); ?>)<?php endif; ?></a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($summary['post_type_counts'])) : ?>
                    <p>
                        <strong><?php echo esc_html__('文章类型：', 'image2url-clipboard-booster'); ?></strong>
                        <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_post_type' => 'all'])); ?>" class="<?php echo 'all' === $filters['post_type'] ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html__('全部类型', 'image2url-clipboard-booster'); ?></a>
                        <?php foreach ($summary['post_type_counts'] as $post_type => $count) : ?>
                            <?php $is_active = $filters['post_type'] === $post_type; ?>
                            <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_post_type' => $post_type])); ?>" class="<?php echo $is_active ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html($this->get_validation_post_type_label((string) $post_type)); ?> (<?php echo esc_html((string) $count); ?>)</a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($summary['issue_type_counts'])) : ?>
                    <p>
                        <strong><?php echo esc_html__('问题类型：', 'image2url-clipboard-booster'); ?></strong>
                        <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_issue_type' => 'all'])); ?>" class="<?php echo 'all' === $filters['issue_type'] ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html__('全部问题', 'image2url-clipboard-booster'); ?></a>
                        <?php foreach ($summary['issue_type_counts'] as $issue_type => $count) : ?>
                            <?php $is_active = $filters['issue_type'] === $issue_type; ?>
                            <a href="<?php echo esc_url($this->build_validation_report_filter_link($job_id, ['image2url_validation_issue_type' => $issue_type])); ?>" class="<?php echo $is_active ? 'button button-primary' : 'button'; ?>" style="margin:0 6px 6px 0;"><?php echo esc_html($this->get_validation_issue_type_label((string) $issue_type)); ?> (<?php echo esc_html((string) $count); ?>)</a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <table class="widefat striped" style="max-width:860px; margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('文章', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('文章类型', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('结果', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('严重度', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('问题类型', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('问题数', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('摘要', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('检查时间', 'image2url-clipboard-booster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($preview_entries)) : ?>
                            <tr>
                                <td colspan="8"><?php echo esc_html__('当前筛选条件下没有匹配结果。', 'image2url-clipboard-booster'); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($preview_entries as $entry) : ?>
                            <?php $edit_link = !empty($entry['post_id']) ? get_edit_post_link((int) $entry['post_id']) : false; ?>
                            <tr>
                                <td>
                                    <?php if (!empty($edit_link)) : ?>
                                        <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($entry['post_title'] ?: '#' . (int) $entry['post_id']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($entry['post_title'] ?: '#' . (int) $entry['post_id']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($this->get_validation_post_type_label((string) ($entry['post_type'] ?? ''))); ?></td>
                                <td><?php echo esc_html($this->get_validation_result_label((string) ($entry['result_status'] ?? 'issues'))); ?></td>
                                <td><?php echo esc_html($this->get_validation_severity_label((string) ($entry['severity'] ?? 'none'))); ?></td>
                                <td><?php echo esc_html($this->format_validation_issue_types((array) ($entry['issue_types'] ?? []))); ?></td>
                                <td><?php echo esc_html((string) ($entry['issue_count'] ?? 0)); ?></td>
                                <td style="word-break:break-word;"><?php echo esc_html($entry['issue_summary'] ?: __('无异常', 'image2url-clipboard-booster')); ?></td>
                                <td><?php echo esc_html((string) ($entry['checked_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($ordered_entries) > count($preview_entries)) : ?>
                    <p class="description"><?php echo esc_html(sprintf(__('当前筛选命中 %1$d 条，仅展示前 %2$d 条。其余结果请导出 CSV 查看完整明细。', 'image2url-clipboard-booster'), count($ordered_entries), count($preview_entries))); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_mapping_stats(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$this->mapping_table_name} GROUP BY status", ARRAY_A);
        $stats = ['total' => 0, 'remote_only' => 0, 'localized' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $status = isset($row['status']) ? (string) $row['status'] : '';
            $count = isset($row['total']) ? (int) $row['total'] : 0;
            $stats['total'] += $count;
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
        }

        return $stats;
    }

    private function get_recent_mappings(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT post_id, remote_url, status, local_attachment_id, updated_at FROM {$this->mapping_table_name} ORDER BY updated_at DESC, id DESC LIMIT 20", ARRAY_A);
    }

    private function get_recent_jobs(): array
    {
        global $wpdb;

        if (current_user_can('manage_options')) {
            return $wpdb->get_results("SELECT id, job_type, status, total_posts, processed_posts, localized_count, replaced_count, failed_count, updated_at FROM {$this->jobs_table_name} ORDER BY updated_at DESC, id DESC LIMIT 10", ARRAY_A);
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, job_type, status, total_posts, processed_posts, localized_count, replaced_count, failed_count, updated_at
                FROM {$this->jobs_table_name}
                WHERE created_by = %d
                ORDER BY updated_at DESC, id DESC
                LIMIT 10",
                get_current_user_id()
            ),
            ARRAY_A
        );
    }

    private function get_current_job(): ?array
    {
        if ($this->current_job_id <= 0) {
            return null;
        }

        $job = $this->get_job($this->current_job_id);
        if (!$job || !$this->can_access_job($job)) {
            $this->current_job_id = 0;
            return null;
        }

        return $job;
    }

    private function get_job(int $job_id): ?array
    {
        global $wpdb;
        if ($job_id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table_name} WHERE id = %d LIMIT 1", $job_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function get_job_report_entries(array $job): array
    {
        if ('validation' !== $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'))) {
            return [];
        }

        $decoded = json_decode((string) ($job['report_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entries[] = $this->normalize_job_report_entry($entry);
        }

        return $entries;
    }

    private function normalize_job_report_entry(array $entry): array
    {
        $issues = [];
        foreach ((array) ($entry['issues'] ?? []) as $issue) {
            if (!is_string($issue) || '' === trim($issue)) {
                continue;
            }

            $issues[] = wp_strip_all_tags($issue);
        }

        $issues = array_values(array_unique($issues));
        $issue_types = $this->extract_validation_issue_types($issues);
        $result_status = (string) ($entry['result_status'] ?? 'issues');
        if (!in_array($result_status, ['passed', 'issues', 'failed'], true)) {
            $result_status = 'issues';
        }
        $severity = (string) ($entry['severity'] ?? $this->calculate_validation_severity($result_status, $issue_types));

        return [
            'post_id' => (int) ($entry['post_id'] ?? 0),
            'post_title' => (string) ($entry['post_title'] ?? ''),
            'post_type' => (string) ($entry['post_type'] ?? ''),
            'checked_at' => (string) ($entry['checked_at'] ?? ''),
            'result_status' => $result_status,
            'severity' => $severity,
            'mapping_total' => (int) ($entry['mapping_total'] ?? 0),
            'localized_mappings' => (int) ($entry['localized_mappings'] ?? 0),
            'remaining_remote_count' => (int) ($entry['remaining_remote_count'] ?? 0),
            'checked_blocks' => (int) ($entry['checked_blocks'] ?? 0),
            'block_issues' => (int) ($entry['block_issues'] ?? 0),
            'featured_image_id' => (int) ($entry['featured_image_id'] ?? 0),
            'issue_count' => (int) ($entry['issue_count'] ?? count($issues)),
            'issues' => $issues,
            'issue_types' => $issue_types,
            'issue_summary' => (string) ($entry['issue_summary'] ?? implode(' | ', array_slice($issues, 0, 3))),
        ];
    }

    private function merge_validation_report_entry(array $entries, array $entry): array
    {
        $normalized_entry = $this->normalize_job_report_entry($entry);

        foreach ($entries as $index => $existing_entry) {
            if ((int) ($existing_entry['post_id'] ?? 0) !== (int) $normalized_entry['post_id']) {
                continue;
            }

            $entries[$index] = $normalized_entry;
            return array_values($entries);
        }

        $entries[] = $normalized_entry;

        return array_values($entries);
    }

    private function build_validation_report_entry(int $post_id, array $result): array
    {
        $issues = [];
        foreach ((array) ($result['issues'] ?? []) as $issue) {
            if (!is_string($issue) || '' === trim($issue)) {
                continue;
            }

            $issues[] = wp_strip_all_tags($issue);
        }

        if (!empty($result['error'])) {
            $issues[] = wp_strip_all_tags((string) $result['error']);
        }

        $issues = array_values(array_unique($issues));
        $issue_types = $this->extract_validation_issue_types($issues);
        $post_title = '';
        if (!empty($result['post_title']) && is_string($result['post_title'])) {
            $post_title = (string) $result['post_title'];
        } elseif ($post_id > 0) {
            $post_title = (string) get_the_title($post_id);
        }

        $result_status = !empty($result['error'])
            ? 'failed'
            : (!empty($result['passed']) ? 'passed' : 'issues');
        $severity = $this->calculate_validation_severity($result_status, $issue_types);

        return [
            'post_id' => $post_id,
            'post_title' => $post_title,
            'post_type' => $post_id > 0 ? (string) get_post_type($post_id) : '',
            'checked_at' => current_time('mysql'),
            'result_status' => $result_status,
            'severity' => $severity,
            'mapping_total' => (int) ($result['mapping_total'] ?? 0),
            'localized_mappings' => (int) ($result['localized_mappings'] ?? 0),
            'remaining_remote_count' => isset($result['remaining_remote_urls']) && is_array($result['remaining_remote_urls']) ? count($result['remaining_remote_urls']) : 0,
            'checked_blocks' => (int) ($result['checked_blocks'] ?? 0),
            'block_issues' => (int) ($result['block_issues'] ?? 0),
            'featured_image_id' => (int) ($result['featured_image_id'] ?? 0),
            'issue_count' => count($issues),
            'issues' => $issues,
            'issue_types' => $issue_types,
            'issue_summary' => !empty($issues) ? implode(' | ', array_slice($issues, 0, 3)) : '',
        ];
    }

    private function extract_validation_issue_types(array $issues): array
    {
        $types = [];
        foreach ($issues as $issue) {
            if (!is_string($issue) || '' === trim($issue)) {
                continue;
            }

            $types[] = $this->classify_validation_issue_type($issue);
        }

        return array_values(array_unique(array_filter($types)));
    }

    private function classify_validation_issue_type(string $issue): string
    {
        if (false !== strpos($issue, '正文仍引用远端图片')) {
            return 'remaining_remote';
        }

        if (false !== strpos($issue, '映射记录指向的本地附件不存在')) {
            return 'missing_mapped_attachment';
        }

        if (false !== strpos($issue, '特色图引用的附件不存在')) {
            return 'missing_featured_attachment';
        }

        if (false !== strpos($issue, '文章没有特色图')) {
            return 'missing_featured_image';
        }

        if (preg_match('/^core\/image .*仍使用远端图片/u', $issue)) {
            return 'block_remote_image';
        }

        if (preg_match('/^core\/(image|cover|media-text) 引用的本地附件不存在/u', $issue)) {
            return 'block_missing_attachment';
        }

        if (preg_match('/^core\/(image|cover|media-text) 已切到本地 URL，但缺少附件 ID/u', $issue)) {
            return 'block_missing_attachment_id';
        }

        if (preg_match('/^core\/(image|cover|media-text) 已有映射记录，但块属性还没绑定本地附件/u', $issue)) {
            return 'block_unbound_mapping';
        }

        return 'other';
    }

    private function calculate_validation_severity(string $result_status, array $issue_types): string
    {
        if ('failed' === $result_status) {
            return 'critical';
        }

        if ('passed' === $result_status || empty($issue_types)) {
            return 'none';
        }

        foreach ($issue_types as $issue_type) {
            if (in_array($issue_type, ['remaining_remote', 'missing_mapped_attachment', 'block_remote_image', 'block_missing_attachment'], true)) {
                return 'high';
            }
        }

        foreach ($issue_types as $issue_type) {
            if (in_array($issue_type, ['missing_featured_attachment', 'block_missing_attachment_id', 'block_unbound_mapping'], true)) {
                return 'medium';
            }
        }

        return 'low';
    }

    private function create_batch_job(array $post_ids, string $job_type = 'rollback'): int
    {
        global $wpdb;
        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        $job_type = $this->normalize_job_type($job_type);
        if (empty($post_ids)) {
            return 0;
        }

        $timestamp = current_time('mysql');
        $inserted = $wpdb->insert(
            $this->jobs_table_name,
            [
                'created_by' => get_current_user_id(),
                'job_type' => $job_type,
                'status' => 'queued',
                'post_ids_json' => wp_json_encode($post_ids),
                'current_index' => 0,
                'total_posts' => count($post_ids),
                'processed_posts' => 0,
                'localized_count' => 0,
                'replaced_count' => 0,
                'failed_count' => 0,
                'last_message' => esc_html__('等待开始。', 'image2url-clipboard-booster'),
                'error_log' => '',
                'report_json' => '[]',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'completed_at' => null,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (false === $inserted) {
            return 0;
        }

        $this->current_job_id = (int) $wpdb->insert_id;

        return $this->current_job_id;
    }

    private function queue_job_for_background(int $job_id)
    {
        $job = $this->get_job($job_id);
        if (!$job) {
            return new WP_Error('image2url_job_missing', esc_html__('未找到该批量任务。', 'image2url-clipboard-booster'));
        }
        if (!$this->can_access_job($job)) {
            return new WP_Error('image2url_job_forbidden', esc_html__('您没有权限执行该批量任务。', 'image2url-clipboard-booster'));
        }
        if (in_array($job['status'], ['completed', 'completed_with_errors'], true)) {
            return $job;
        }

        $fields = [
            'updated_at' => current_time('mysql'),
            'completed_at' => null,
        ];

        if ('failed' === $job['status']) {
            $fields['status'] = 'queued';
            $fields['last_message'] = esc_html__('任务已重新加入后台队列。', 'image2url-clipboard-booster');
        } elseif ('queued' === $job['status']) {
            $fields['last_message'] = esc_html__('任务已加入后台队列，等待 WP-Cron 执行。', 'image2url-clipboard-booster');
        } elseif ($this->is_job_locked($job_id)) {
            $fields['last_message'] = esc_html__('后台正在执行当前任务。', 'image2url-clipboard-booster');
            $this->update_job($job_id, $fields);
            return $this->get_job($job_id);
        } else {
            $fields['last_message'] = esc_html__('任务将继续在后台执行。', 'image2url-clipboard-booster');
        }

        $this->update_job($job_id, $fields);
        if (!$this->schedule_job($job_id, 1)) {
            return new WP_Error('image2url_job_schedule_failed', esc_html__('任务已创建，但加入后台队列失败。请检查站点定时任务配置。', 'image2url-clipboard-booster'));
        }

        $this->maybe_spawn_cron();

        return $this->get_job($job_id);
    }

    private function process_job_batch(int $job_id, int $limit, bool $skip_job_access_check = false)
    {
        $job = $this->get_job($job_id);
        if (!$job) {
            return new WP_Error('image2url_job_missing', esc_html__('未找到该批量任务。', 'image2url-clipboard-booster'));
        }
        if (!$skip_job_access_check && !$this->can_access_job($job)) {
            return new WP_Error('image2url_job_forbidden', esc_html__('您没有权限执行该批量任务。', 'image2url-clipboard-booster'));
        }
        if (in_array($job['status'], ['completed', 'completed_with_errors'], true)) {
            return $job;
        }

        $post_ids = $this->decode_job_post_ids($job);
        if (empty($post_ids)) {
            $this->update_job((int) $job['id'], ['status' => 'failed', 'last_message' => esc_html__('任务中没有可处理的文章 ID。', 'image2url-clipboard-booster'), 'updated_at' => current_time('mysql'), 'completed_at' => current_time('mysql')]);
            return $this->get_job((int) $job['id']);
        }

        $job_type = $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'));
        $current_index = (int) $job['current_index'];
        $processed_posts = (int) $job['processed_posts'];
        $localized_count = (int) $job['localized_count'];
        $replaced_count = (int) $job['replaced_count'];
        $failed_count = (int) $job['failed_count'];
        $last_message = (string) $job['last_message'];
        $job_log = isset($job['error_log']) ? (string) $job['error_log'] : '';
        $job_reports = 'validation' === $job_type ? $this->get_job_report_entries($job) : [];
        $batch_messages = [];
        $processed_in_batch = 0;
        $total_posts = count($post_ids);
        $actor_user_id = isset($job['created_by']) ? (int) $job['created_by'] : 0;

        $this->update_job(
            (int) $job['id'],
            [
                'status' => 'running',
                'last_message' => 'validation' === $job_type
                    ? esc_html__('后台正在验证当前批次。', 'image2url-clipboard-booster')
                    : esc_html__('后台正在处理当前批次。', 'image2url-clipboard-booster'),
                'updated_at' => current_time('mysql'),
                'completed_at' => null,
            ]
        );

        while ($current_index < $total_posts && $processed_in_batch < $limit) {
            $post_id = absint($post_ids[$current_index]);
            $processed_posts++;
            $processed_in_batch++;
            $current_index++;

            if ($post_id <= 0) {
                $failed_count++;
                $message = esc_html__('任务中包含无效的文章 ID，已跳过。', 'image2url-clipboard-booster');
                $batch_messages[] = $message;
                $last_message = $message;
                continue;
            }

            $result = 'validation' === $job_type
                ? $this->validate_post_localization($post_id, $actor_user_id)
                : $this->rollback_post($post_id, $actor_user_id);

            if (!empty($result['error'])) {
                $failed_count++;
                if ('validation' === $job_type) {
                    $job_reports = $this->merge_validation_report_entry($job_reports, $this->build_validation_report_entry($post_id, $result));
                }
                $message = sprintf(
                    'validation' === $job_type
                        ? esc_html__('文章 #%1$d 验证失败：%2$s', 'image2url-clipboard-booster')
                        : esc_html__('文章 #%1$d 处理失败：%2$s', 'image2url-clipboard-booster'),
                    $post_id,
                    $result['error']
                );
                $batch_messages[] = $message;
                $last_message = $message;
                continue;
            }

            if ('validation' === $job_type) {
                $issue_count = isset($result['issues']) && is_array($result['issues']) ? count($result['issues']) : 0;
                $job_reports = $this->merge_validation_report_entry($job_reports, $this->build_validation_report_entry($post_id, $result));
                if (!empty($result['passed'])) {
                    $localized_count++;
                    $last_message = sprintf(
                        esc_html__('文章 #%1$d 验证通过。', 'image2url-clipboard-booster'),
                        $post_id
                    );
                } else {
                    $replaced_count += $issue_count;
                    $issue_summary = sprintf(
                        esc_html__('文章 #%1$d 验证发现 %2$d 项问题。', 'image2url-clipboard-booster'),
                        $post_id,
                        $issue_count
                    );
                    $batch_messages[] = $issue_summary;
                    $last_message = $issue_summary;

                    foreach ((array) ($result['issues'] ?? []) as $issue) {
                        if (!is_string($issue) || '' === trim($issue)) {
                            continue;
                        }

                        $batch_messages[] = sprintf(
                            esc_html__('文章 #%1$d：%2$s', 'image2url-clipboard-booster'),
                            $post_id,
                            wp_strip_all_tags($issue)
                        );
                    }
                }
            } else {
                $localized_count += (int) ($result['localized'] ?? 0);
                $replaced_count += (int) ($result['replaced'] ?? 0);
                if (!empty($result['failed'])) {
                    $failed_count += (int) $result['failed'];
                    $batch_messages[] = sprintf(esc_html__('文章 #%1$d 已完成，但有 %2$d 项失败。', 'image2url-clipboard-booster'), $post_id, (int) $result['failed']);
                }
                $last_message = sprintf(esc_html__('文章 #%1$d 已处理，替换 %2$d 处。', 'image2url-clipboard-booster'), $post_id, (int) ($result['replaced'] ?? 0));
            }
        }

        $status = 'running';
        $completed_at = null;
        if ($current_index >= $total_posts) {
            $has_issues = 'validation' === $job_type ? ($replaced_count > 0 || $failed_count > 0) : ($failed_count > 0);
            $status = $has_issues ? 'completed_with_errors' : 'completed';
            $completed_at = current_time('mysql');
            if ('validation' === $job_type) {
                $last_message = 'completed' === $status
                    ? esc_html__('批量验证任务已完成，未发现问题。', 'image2url-clipboard-booster')
                    : esc_html__('批量验证任务已完成，但发现问题或失败项。', 'image2url-clipboard-booster');
            } else {
                $last_message = 'completed' === $status
                    ? esc_html__('批量回退任务已完成。', 'image2url-clipboard-booster')
                    : esc_html__('批量回退任务已完成，但存在失败项。', 'image2url-clipboard-booster');
            }
        }

        $job_log = $this->append_job_log($job_log, $batch_messages);
        $job_update = ['status' => $status, 'current_index' => $current_index, 'processed_posts' => $processed_posts, 'localized_count' => $localized_count, 'replaced_count' => $replaced_count, 'failed_count' => $failed_count, 'last_message' => $last_message, 'error_log' => $job_log, 'updated_at' => current_time('mysql'), 'completed_at' => $completed_at];
        if ('validation' === $job_type) {
            $job_update['report_json'] = wp_json_encode($job_reports);
        }
        $this->update_job((int) $job['id'], $job_update);

        return $this->get_job((int) $job['id']);
    }

    private function decode_job_post_ids(array $job): array
    {
        $decoded = json_decode((string) ($job['post_ids_json'] ?? '[]'), true);
        return is_array($decoded) ? array_values(array_filter(array_map('absint', $decoded))) : [];
    }

    private function can_access_job(array $job): bool
    {
        return current_user_can('manage_options') || (int) ($job['created_by'] ?? 0) === get_current_user_id();
    }

    private function update_job(int $job_id, array $fields): void
    {
        global $wpdb;
        if ($job_id <= 0 || empty($fields)) {
            return;
        }
        $allowed = ['status' => '%s', 'post_ids_json' => '%s', 'current_index' => '%d', 'total_posts' => '%d', 'processed_posts' => '%d', 'localized_count' => '%d', 'replaced_count' => '%d', 'failed_count' => '%d', 'last_message' => '%s', 'error_log' => '%s', 'report_json' => '%s', 'updated_at' => '%s', 'completed_at' => '%s'];
        $data = [];
        $formats = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $data[$key] = $value;
            $formats[] = $allowed[$key];
        }
        if (empty($data)) {
            return;
        }
        $wpdb->update($this->jobs_table_name, $data, ['id' => $job_id], $formats, ['%d']);
    }

    private function format_job_for_response(array $job): array
    {
        $job_id = (int) $job['id'];
        $job_type = $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'));
        $total_posts = max(0, (int) ($job['total_posts'] ?? 0));
        $processed_posts = max(0, (int) ($job['processed_posts'] ?? 0));
        $status = (string) ($job['status'] ?? '');
        return [
            'id' => $job_id,
            'jobType' => $job_type,
            'status' => $status,
            'processedPosts' => $processed_posts,
            'totalPosts' => $total_posts,
            'localizedCount' => (int) ($job['localized_count'] ?? 0),
            'replacedCount' => (int) ($job['replaced_count'] ?? 0),
            'failedCount' => (int) ($job['failed_count'] ?? 0),
            'lastMessage' => (string) ($job['last_message'] ?? ''),
            'errorLog' => (string) ($job['error_log'] ?? ''),
            'percentage' => $total_posts > 0 ? min(100, (int) floor(($processed_posts / $total_posts) * 100)) : 0,
            'completed' => in_array($status, ['completed', 'completed_with_errors'], true),
            'scheduled' => $this->has_scheduled_job($job_id),
            'locked' => $this->is_job_locked($job_id),
        ];
    }

    private function normalize_job_type(string $job_type): string
    {
        return 'validation' === $job_type ? 'validation' : 'rollback';
    }

    private function get_job_type_label(string $job_type): string
    {
        return 'validation' === $this->normalize_job_type($job_type)
            ? esc_html__('批量验证', 'image2url-clipboard-booster')
            : esc_html__('批量回退', 'image2url-clipboard-booster');
    }

    private function get_job_metric_labels(string $job_type): array
    {
        if ('validation' === $this->normalize_job_type($job_type)) {
            return [
                'primary' => esc_html__('通过文章', 'image2url-clipboard-booster'),
                'secondary' => esc_html__('问题项', 'image2url-clipboard-booster'),
                'failure' => esc_html__('失败文章', 'image2url-clipboard-booster'),
            ];
        }

        return [
            'primary' => esc_html__('下载到本地', 'image2url-clipboard-booster'),
            'secondary' => esc_html__('内容替换', 'image2url-clipboard-booster'),
            'failure' => esc_html__('失败', 'image2url-clipboard-booster'),
        ];
    }

    private function get_job_results_summary(array $job): string
    {
        $job_type = $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'));

        if ('validation' === $job_type) {
            return sprintf(
                esc_html__('通过 %1$d / 问题 %2$d / 失败 %3$d', 'image2url-clipboard-booster'),
                (int) ($job['localized_count'] ?? 0),
                (int) ($job['replaced_count'] ?? 0),
                (int) ($job['failed_count'] ?? 0)
            );
        }

        return sprintf(
            esc_html__('下载 %1$d / 替换 %2$d / 失败 %3$d', 'image2url-clipboard-booster'),
            (int) ($job['localized_count'] ?? 0),
            (int) ($job['replaced_count'] ?? 0),
            (int) ($job['failed_count'] ?? 0)
        );
    }

    private function get_validation_result_label(string $result_status): string
    {
        switch ($result_status) {
            case 'passed':
                return esc_html__('通过', 'image2url-clipboard-booster');
            case 'failed':
                return esc_html__('执行失败', 'image2url-clipboard-booster');
            default:
                return esc_html__('发现问题', 'image2url-clipboard-booster');
        }
    }

    private function get_validation_issue_type_label(string $issue_type): string
    {
        switch ($issue_type) {
            case 'remaining_remote':
                return esc_html__('正文残留外链', 'image2url-clipboard-booster');
            case 'missing_mapped_attachment':
                return esc_html__('映射附件缺失', 'image2url-clipboard-booster');
            case 'missing_featured_attachment':
                return esc_html__('特色图附件缺失', 'image2url-clipboard-booster');
            case 'missing_featured_image':
                return esc_html__('缺少特色图', 'image2url-clipboard-booster');
            case 'block_remote_image':
                return esc_html__('区块仍用外链', 'image2url-clipboard-booster');
            case 'block_missing_attachment':
                return esc_html__('区块附件缺失', 'image2url-clipboard-booster');
            case 'block_missing_attachment_id':
                return esc_html__('区块缺少附件 ID', 'image2url-clipboard-booster');
            case 'block_unbound_mapping':
                return esc_html__('区块未绑定映射', 'image2url-clipboard-booster');
            default:
                return esc_html__('其他问题', 'image2url-clipboard-booster');
        }
    }

    private function format_validation_issue_types(array $issue_types): string
    {
        if (empty($issue_types)) {
            return esc_html__('无异常', 'image2url-clipboard-booster');
        }

        $labels = [];
        foreach ($issue_types as $issue_type) {
            if (!is_string($issue_type) || '' === trim($issue_type)) {
                continue;
            }

            $labels[] = $this->get_validation_issue_type_label($issue_type);
        }

        $labels = array_values(array_unique(array_filter($labels)));

        return !empty($labels)
            ? implode(' / ', $labels)
            : esc_html__('其他问题', 'image2url-clipboard-booster');
    }

    private function get_validation_severity_label(string $severity): string
    {
        switch ($severity) {
            case 'critical':
                return esc_html__('阻断', 'image2url-clipboard-booster');
            case 'high':
                return esc_html__('高', 'image2url-clipboard-booster');
            case 'medium':
                return esc_html__('中', 'image2url-clipboard-booster');
            case 'low':
                return esc_html__('低', 'image2url-clipboard-booster');
            default:
                return esc_html__('通过', 'image2url-clipboard-booster');
        }
    }

    private function get_validation_post_type_label(string $post_type): string
    {
        if ('' === trim($post_type)) {
            return esc_html__('未知', 'image2url-clipboard-booster');
        }

        $post_type_object = get_post_type_object($post_type);

        return $post_type_object && !empty($post_type_object->labels->singular_name)
            ? (string) $post_type_object->labels->singular_name
            : $post_type;
    }

    private function get_validation_result_filter_options(): array
    {
        return [
            'all' => esc_html__('全部', 'image2url-clipboard-booster'),
            'issues' => esc_html__('仅问题', 'image2url-clipboard-booster'),
            'failed' => esc_html__('仅失败', 'image2url-clipboard-booster'),
            'passed' => esc_html__('仅通过', 'image2url-clipboard-booster'),
        ];
    }

    private function get_validation_severity_filter_options(): array
    {
        return [
            'all' => esc_html__('全部', 'image2url-clipboard-booster'),
            'critical' => esc_html__('阻断', 'image2url-clipboard-booster'),
            'high' => esc_html__('高', 'image2url-clipboard-booster'),
            'medium' => esc_html__('中', 'image2url-clipboard-booster'),
            'low' => esc_html__('低', 'image2url-clipboard-booster'),
            'none' => esc_html__('通过', 'image2url-clipboard-booster'),
        ];
    }

    private function get_validation_report_filters(): array
    {
        $result_status = isset($_GET['image2url_validation_result']) ? sanitize_key(wp_unslash($_GET['image2url_validation_result'])) : 'all';
        $issue_type = isset($_GET['image2url_validation_issue_type']) ? sanitize_key(wp_unslash($_GET['image2url_validation_issue_type'])) : 'all';
        $severity = isset($_GET['image2url_validation_severity']) ? sanitize_key(wp_unslash($_GET['image2url_validation_severity'])) : 'all';
        $post_type = isset($_GET['image2url_validation_post_type']) ? sanitize_key(wp_unslash($_GET['image2url_validation_post_type'])) : 'all';

        if (!array_key_exists($result_status, $this->get_validation_result_filter_options())) {
            $result_status = 'all';
        }

        if ('all' !== $issue_type && !in_array($issue_type, ['remaining_remote', 'missing_mapped_attachment', 'missing_featured_attachment', 'missing_featured_image', 'block_remote_image', 'block_missing_attachment', 'block_missing_attachment_id', 'block_unbound_mapping', 'other'], true)) {
            $issue_type = 'all';
        }

        if (!array_key_exists($severity, $this->get_validation_severity_filter_options())) {
            $severity = 'all';
        }

        if ('all' !== $post_type && !post_type_exists($post_type)) {
            $post_type = 'all';
        }

        return [
            'result_status' => $result_status,
            'issue_type' => $issue_type,
            'severity' => $severity,
            'post_type' => $post_type,
        ];
    }

    private function summarize_validation_report_entries(array $entries): array
    {
        $result_counts = [
            'all' => count($entries),
            'issues' => 0,
            'failed' => 0,
            'passed' => 0,
        ];
        $severity_counts = [
            'all' => count($entries),
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'none' => 0,
        ];
        $issue_type_counts = [];
        $post_type_counts = [];

        foreach ($entries as $entry) {
            $result_status = (string) ($entry['result_status'] ?? 'issues');
            if (isset($result_counts[$result_status])) {
                $result_counts[$result_status]++;
            }

            $severity = (string) ($entry['severity'] ?? 'none');
            if (isset($severity_counts[$severity])) {
                $severity_counts[$severity]++;
            }

            $post_type = (string) ($entry['post_type'] ?? '');
            if ('' !== $post_type) {
                if (!isset($post_type_counts[$post_type])) {
                    $post_type_counts[$post_type] = 0;
                }

                $post_type_counts[$post_type]++;
            }

            foreach ((array) ($entry['issue_types'] ?? []) as $issue_type) {
                if (!is_string($issue_type) || '' === trim($issue_type)) {
                    continue;
                }

                if (!isset($issue_type_counts[$issue_type])) {
                    $issue_type_counts[$issue_type] = 0;
                }

                $issue_type_counts[$issue_type]++;
            }
        }

        arsort($issue_type_counts);
        arsort($post_type_counts);

        return [
            'result_counts' => $result_counts,
            'severity_counts' => $severity_counts,
            'post_type_counts' => $post_type_counts,
            'issue_type_counts' => $issue_type_counts,
        ];
    }

    private function filter_validation_report_entries(array $entries, string $result_status, string $issue_type, string $severity, string $post_type): array
    {
        return array_values(array_filter($entries, static function (array $entry) use ($result_status, $issue_type, $severity, $post_type): bool {
            $entry_result = (string) ($entry['result_status'] ?? 'issues');
            if ('all' !== $result_status && $entry_result !== $result_status) {
                return false;
            }

            if ('all' !== $issue_type && !in_array($issue_type, (array) ($entry['issue_types'] ?? []), true)) {
                return false;
            }

            $entry_severity = (string) ($entry['severity'] ?? 'none');
            if ('all' !== $severity && $entry_severity !== $severity) {
                return false;
            }

            $entry_post_type = (string) ($entry['post_type'] ?? '');
            if ('all' !== $post_type && $entry_post_type !== $post_type) {
                return false;
            }

            return true;
        }));
    }

    private function prioritize_validation_report_entries(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $priority_map = [
                'failed' => 0,
                'issues' => 1,
                'passed' => 2,
            ];
            $severity_map = [
                'critical' => 0,
                'high' => 1,
                'medium' => 2,
                'low' => 3,
                'none' => 4,
            ];

            $left_priority = $priority_map[(string) ($left['result_status'] ?? 'issues')] ?? 3;
            $right_priority = $priority_map[(string) ($right['result_status'] ?? 'issues')] ?? 3;

            if ($left_priority !== $right_priority) {
                return $left_priority <=> $right_priority;
            }

            $left_severity = $severity_map[(string) ($left['severity'] ?? 'none')] ?? 5;
            $right_severity = $severity_map[(string) ($right['severity'] ?? 'none')] ?? 5;
            if ($left_severity !== $right_severity) {
                return $left_severity <=> $right_severity;
            }

            $left_issue_count = (int) ($left['issue_count'] ?? 0);
            $right_issue_count = (int) ($right['issue_count'] ?? 0);
            if ($left_issue_count !== $right_issue_count) {
                return $right_issue_count <=> $left_issue_count;
            }

            return strcmp((string) ($right['checked_at'] ?? ''), (string) ($left['checked_at'] ?? ''));
        });

        return array_values($entries);
    }

    private function get_job_button_label(string $status): string
    {
        if (in_array($status, ['completed', 'completed_with_errors'], true)) {
            return esc_html__('已完成', 'image2url-clipboard-booster');
        }

        if ('failed' === $status) {
            return esc_html__('重新入队', 'image2url-clipboard-booster');
        }

        return 'running' === $status ? esc_html__('后台执行中', 'image2url-clipboard-booster') : esc_html__('开始执行', 'image2url-clipboard-booster');
    }

    private function build_job_export_link(int $job_id): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'page' => 'image2url-migration',
                    'job_id' => $job_id,
                    'image2url_migration_action' => 'export_job_report',
                ],
                admin_url('tools.php')
            ),
            'image2url_export_job_report_' . $job_id
        );
    }

    private function build_validation_report_filter_link(int $job_id, array $filters = []): string
    {
        $query_args = [
            'page' => 'image2url-migration',
            'job_id' => $job_id,
            'image2url_validation_result' => isset($filters['image2url_validation_result']) ? $filters['image2url_validation_result'] : (isset($_GET['image2url_validation_result']) ? sanitize_key(wp_unslash($_GET['image2url_validation_result'])) : 'all'),
            'image2url_validation_issue_type' => isset($filters['image2url_validation_issue_type']) ? $filters['image2url_validation_issue_type'] : (isset($_GET['image2url_validation_issue_type']) ? sanitize_key(wp_unslash($_GET['image2url_validation_issue_type'])) : 'all'),
            'image2url_validation_severity' => isset($filters['image2url_validation_severity']) ? $filters['image2url_validation_severity'] : (isset($_GET['image2url_validation_severity']) ? sanitize_key(wp_unslash($_GET['image2url_validation_severity'])) : 'all'),
            'image2url_validation_post_type' => isset($filters['image2url_validation_post_type']) ? $filters['image2url_validation_post_type'] : (isset($_GET['image2url_validation_post_type']) ? sanitize_key(wp_unslash($_GET['image2url_validation_post_type'])) : 'all'),
        ];

        if ('all' === $query_args['image2url_validation_result']) {
            unset($query_args['image2url_validation_result']);
        }

        if ('all' === $query_args['image2url_validation_issue_type']) {
            unset($query_args['image2url_validation_issue_type']);
        }

        if ('all' === $query_args['image2url_validation_severity']) {
            unset($query_args['image2url_validation_severity']);
        }

        if ('all' === $query_args['image2url_validation_post_type']) {
            unset($query_args['image2url_validation_post_type']);
        }

        return add_query_arg($query_args, admin_url('tools.php'));
    }

    private function export_validation_job_report(int $job_id): void
    {
        $job = $this->get_job($job_id);
        if (!$job) {
            add_settings_error('image2url_migration', 'image2url_export_missing', esc_html__('未找到可导出的验证任务。', 'image2url-clipboard-booster'), 'error');
            return;
        }

        if (!$this->can_access_job($job)) {
            add_settings_error('image2url_migration', 'image2url_export_forbidden', esc_html__('您没有权限导出这个验证任务。', 'image2url-clipboard-booster'), 'error');
            return;
        }

        if ('validation' !== $this->normalize_job_type((string) ($job['job_type'] ?? 'rollback'))) {
            add_settings_error('image2url_migration', 'image2url_export_invalid_type', esc_html__('只有批量验证任务支持导出 CSV。', 'image2url-clipboard-booster'), 'error');
            return;
        }

        $entries = $this->get_job_report_entries($job);
        $filename = sprintf('image2url-validation-job-%d-%s.csv', $job_id, gmdate('Ymd-His'));

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        if (false === $output) {
            wp_die(esc_html__('无法创建导出文件。', 'image2url-clipboard-booster'));
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['job_id', 'post_id', 'post_title', 'post_type_label', 'result', 'severity', 'issue_types', 'mapping_total', 'localized_mappings', 'remaining_remote_count', 'checked_blocks', 'block_issues', 'featured_image_id', 'issue_count', 'issues', 'checked_at']);

        foreach ($entries as $entry) {
            fputcsv(
                $output,
                [
                    $job_id,
                    (int) ($entry['post_id'] ?? 0),
                    (string) ($entry['post_title'] ?? ''),
                    $this->get_validation_post_type_label((string) ($entry['post_type'] ?? '')),
                    $this->get_validation_result_label((string) ($entry['result_status'] ?? 'issues')),
                    $this->get_validation_severity_label((string) ($entry['severity'] ?? 'none')),
                    $this->format_validation_issue_types((array) ($entry['issue_types'] ?? [])),
                    (int) ($entry['mapping_total'] ?? 0),
                    (int) ($entry['localized_mappings'] ?? 0),
                    (int) ($entry['remaining_remote_count'] ?? 0),
                    (int) ($entry['checked_blocks'] ?? 0),
                    (int) ($entry['block_issues'] ?? 0),
                    (int) ($entry['featured_image_id'] ?? 0),
                    (int) ($entry['issue_count'] ?? 0),
                    implode(' | ', (array) ($entry['issues'] ?? [])),
                    (string) ($entry['checked_at'] ?? ''),
                ]
            );
        }

        fclose($output);
        exit;
    }

    private function build_job_link(int $job_id): string
    {
        return add_query_arg(['page' => 'image2url-migration', 'job_id' => $job_id], admin_url('tools.php'));
    }

    private function schedule_job(int $job_id, int $delay = 1): bool
    {
        if ($job_id <= 0) {
            return false;
        }

        if ($this->has_scheduled_job($job_id)) {
            return true;
        }

        return false !== wp_schedule_single_event(time() + max(1, $delay), self::CRON_HOOK, [$job_id]);
    }

    private function has_scheduled_job(int $job_id): bool
    {
        if ($job_id <= 0) {
            return false;
        }

        return false !== wp_next_scheduled(self::CRON_HOOK, [$job_id]);
    }

    private function maybe_spawn_cron(): void
    {
        if (defined('DOING_CRON') || !function_exists('spawn_cron')) {
            return;
        }

        spawn_cron(time());
    }

    private function get_job_lock_key(int $job_id): string
    {
        return self::JOB_LOCK_PREFIX . $job_id;
    }

    private function is_job_locked(int $job_id): bool
    {
        if ($job_id <= 0) {
            return false;
        }

        return false !== get_transient($this->get_job_lock_key($job_id));
    }

    private function acquire_job_lock(int $job_id): bool
    {
        if ($job_id <= 0 || $this->is_job_locked($job_id)) {
            return false;
        }

        return set_transient($this->get_job_lock_key($job_id), time(), self::JOB_LOCK_TTL);
    }

    private function release_job_lock(int $job_id): void
    {
        if ($job_id <= 0) {
            return;
        }

        delete_transient($this->get_job_lock_key($job_id));
    }

    private function append_job_log(string $existing_log, array $messages): string
    {
        $lines = '' !== trim($existing_log) ? preg_split('/\r\n|\r|\n/', trim($existing_log)) : [];
        if (!is_array($lines)) {
            $lines = [];
        }
        foreach ($messages as $message) {
            if (!is_string($message) || '' === trim($message)) {
                continue;
            }
            $lines[] = '[' . current_time('mysql') . '] ' . $message;
        }
        return implode("\n", array_slice(array_values(array_filter($lines)), -50));
    }

    private function parse_post_ids($raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }
        $parts = preg_split('/[\s,]+/', trim($raw));
        if (!is_array($parts)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('absint', $parts))));
    }

    private function get_all_auditable_post_ids(): array
    {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        if (empty($post_types)) {
            return [];
        }

        $query_args = [
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ];

        if (!current_user_can('manage_options')) {
            $query_args['author'] = get_current_user_id();
        }

        $post_ids = get_posts($query_args);

        return is_array($post_ids) ? array_values(array_unique(array_filter(array_map('absint', $post_ids)))) : [];
    }

    private function validate_target_post(int $post_id, int $actor_user_id = 0)
    {
        if ($post_id <= 0) {
            return new WP_Error('image2url_invalid_post', esc_html__('请输入有效的文章 ID。', 'image2url-clipboard-booster'));
        }
        $post = get_post($post_id);
        if (!$post || 'revision' === $post->post_type || 'attachment' === $post->post_type) {
            return new WP_Error('image2url_post_not_found', esc_html__('未找到可处理的文章。', 'image2url-clipboard-booster'));
        }

        $can_edit = $actor_user_id > 0 ? user_can($actor_user_id, 'edit_post', $post_id) : current_user_can('edit_post', $post_id);
        if (!$can_edit) {
            return new WP_Error('image2url_forbidden', esc_html__('您没有权限处理这篇文章。', 'image2url-clipboard-booster'));
        }
        return $post;
    }

    private function extract_remote_image_urls(string $content): array
    {
        if ('' === trim($content)) {
            return [];
        }
        if (!preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches) || empty($matches[1])) {
            return [];
        }
        $urls = [];
        foreach ($matches[1] as $url) {
            $url = html_entity_decode(trim((string) $url), ENT_QUOTES);
            if (!$this->is_external_image_url($url)) {
                continue;
            }
            $urls[$url] = $url;
        }
        return array_values($urls);
    }

    private function is_external_image_url(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $remote_host = wp_parse_url($url, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$remote_host) {
            return false;
        }
        return !($site_host && 0 === strcasecmp((string) $remote_host, (string) $site_host));
    }

    private function get_or_create_local_attachment(int $post_id, string $remote_url)
    {
        $mapping = $this->find_mapping($post_id, $remote_url);
        if (!empty($mapping['local_attachment_id'])) {
            $attachment_id = (int) $mapping['local_attachment_id'];
            if ($attachment_id > 0 && get_post($attachment_id)) {
                return $attachment_id;
            }
        }

        $existing_attachment_id = $this->find_attachment_by_source_url($remote_url);
        if ($existing_attachment_id > 0) {
            $this->upsert_mapping($post_id, $remote_url, ['local_attachment_id' => $existing_attachment_id, 'status' => 'localized', 'last_error' => null, 'migrated_at' => current_time('mysql')]);
            return $existing_attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temp_file = download_url($remote_url, 30);
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $file_array = ['name' => $this->build_filename($remote_url, $temp_file), 'tmp_name' => $temp_file];
        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return $attachment_id;
        }

        update_post_meta($attachment_id, '_image2url_source_url', esc_url_raw($remote_url));
        $this->upsert_mapping($post_id, $remote_url, ['local_attachment_id' => (int) $attachment_id, 'status' => 'localized', 'last_error' => null, 'migrated_at' => current_time('mysql')]);

        return (int) $attachment_id;
    }

    private function build_filename(string $remote_url, string $tmp_file): string
    {
        $path = (string) wp_parse_url($remote_url, PHP_URL_PATH);
        $filename = sanitize_file_name(wp_basename($path));
        $stem = $filename ? pathinfo($filename, PATHINFO_FILENAME) : 'image2url-' . substr(md5($remote_url), 0, 12);
        $extension = $filename ? pathinfo($filename, PATHINFO_EXTENSION) : '';

        if (!$extension) {
            $mime = wp_get_image_mime($tmp_file);
            $extension_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $extension = isset($extension_map[$mime]) ? $extension_map[$mime] : 'jpg';
        }

        return sanitize_file_name($stem . '.' . $extension);
    }

    private function find_attachment_by_source_url(string $remote_url): int
    {
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_image2url_source_url' AND meta_value = %s LIMIT 1", esc_url_raw($remote_url)));
        $attachment_id = (int) $attachment_id;
        return $attachment_id > 0 && get_post($attachment_id) ? $attachment_id : 0;
    }

    private function replace_post_content_urls(int $post_id, array $replacements, array $attachment_map = [])
    {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('image2url_post_missing', esc_html__('文章不存在，无法替换内容。', 'image2url-clipboard-booster'));
        }

        $original_content = (string) $post->post_content;
        $content = $original_content;
        $synced_blocks = 0;

        if (!empty($attachment_map) && function_exists('has_blocks') && function_exists('parse_blocks') && function_exists('serialize_blocks') && has_blocks($content)) {
            $block_sync_result = $this->synchronize_content_blocks($content, $replacements, $attachment_map);
            if (is_wp_error($block_sync_result)) {
                return $block_sync_result;
            }

            $content = (string) ($block_sync_result['content'] ?? $content);
            $synced_blocks = (int) ($block_sync_result['synced_blocks'] ?? 0);
        }

        $replacement_count = 0;
        foreach ($replacements as $remote_url => $local_url) {
            $content = str_replace($remote_url, $local_url, $content, $count);
            $replacement_count += (int) $count;
        }

        if ($content === $original_content) {
            return [
                'replaced' => $replacement_count,
                'synced_blocks' => $synced_blocks,
                'featured_image_set' => $this->maybe_set_featured_image_from_localized_images($post_id, $original_content, $attachment_map),
            ];
        }

        $update_result = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
        if (is_wp_error($update_result)) {
            return $update_result;
        }

        return [
            'replaced' => $replacement_count,
            'synced_blocks' => $synced_blocks,
            'featured_image_set' => $this->maybe_set_featured_image_from_localized_images($post_id, $original_content, $attachment_map),
        ];
    }

    private function synchronize_content_blocks(string $content, array $replacements, array $attachment_map): array
    {
        $blocks = parse_blocks($content);
        if (!is_array($blocks)) {
            return [
                'content' => $content,
                'synced_blocks' => 0,
            ];
        }

        $synced_blocks = 0;
        $blocks = $this->synchronize_blocks_recursive($blocks, $replacements, $attachment_map, $synced_blocks);

        return [
            'content' => serialize_blocks($blocks),
            'synced_blocks' => $synced_blocks,
        ];
    }

    private function synchronize_blocks_recursive(array $blocks, array $replacements, array $attachment_map, int &$synced_blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->synchronize_blocks_recursive($block['innerBlocks'], $replacements, $attachment_map, $synced_blocks);
            }

            $block_name = (string) ($block['blockName'] ?? '');
            $did_sync = false;
            if ('core/image' === $block_name) {
                [$block, $did_sync] = $this->synchronize_image_block($block, $replacements, $attachment_map);
            } elseif ('core/cover' === $block_name) {
                [$block, $did_sync] = $this->synchronize_cover_block($block, $attachment_map);
            } elseif ('core/media-text' === $block_name) {
                [$block, $did_sync] = $this->synchronize_media_text_block($block, $attachment_map);
            }

            if ($did_sync) {
                $synced_blocks++;
            }

            $blocks[$index] = $block;
        }

        return $blocks;
    }

    private function synchronize_image_block(array $block, array $replacements, array $attachment_map): array
    {
        $remote_url = $this->detect_image_block_url($block);
        if ('' === $remote_url || empty($attachment_map[$remote_url])) {
            return [$block, false];
        }

        $attachment_id = absint($attachment_map[$remote_url]);
        if ($attachment_id <= 0 || !get_post($attachment_id)) {
            return [$block, false];
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $size_slug = $this->resolve_image_block_size_slug($attrs, $attachment_id);
        $local_url = wp_get_attachment_image_url($attachment_id, $size_slug);
        $full_url = wp_get_attachment_url($attachment_id);

        if (!$local_url) {
            $size_slug = 'full';
            $local_url = $full_url;
        }

        if (!$local_url) {
            return [$block, false];
        }

        $alt = isset($attrs['alt']) && is_string($attrs['alt']) ? trim($attrs['alt']) : '';
        if ('' === $alt) {
            $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        }

        $attrs['id'] = $attachment_id;
        $attrs['url'] = esc_url_raw($local_url);
        $attrs['sizeSlug'] = $size_slug;
        if ('' !== $alt) {
            $attrs['alt'] = $alt;
        }

        if (isset($attrs['href']) && is_string($attrs['href']) && isset($replacements[$attrs['href']])) {
            $attrs['href'] = esc_url_raw($replacements[$attrs['href']]);
        }

        $link_destination = isset($attrs['linkDestination']) && is_string($attrs['linkDestination']) ? $attrs['linkDestination'] : '';
        if (in_array($link_destination, ['media', 'attachment'], true) && $full_url) {
            $attrs['href'] = esc_url_raw($full_url);
        }

        $markup = $this->build_image_block_markup($attachment_id, $attrs, $size_slug, $full_url ?: $local_url);
        if ('' === $markup) {
            return [$block, false];
        }

        $block['attrs'] = $attrs;
        $block['innerHTML'] = $markup;
        $block['innerContent'] = [$markup];

        return [$block, true];
    }

    private function synchronize_cover_block(array $block, array $attachment_map): array
    {
        $remote_url = $this->detect_cover_block_url($block);
        if ('' === $remote_url || empty($attachment_map[$remote_url])) {
            return [$block, false];
        }

        $attachment_id = absint($attachment_map[$remote_url]);
        if ($attachment_id <= 0 || !get_post($attachment_id)) {
            return [$block, false];
        }

        $full_url = wp_get_attachment_url($attachment_id);
        if (!$full_url) {
            return [$block, false];
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $attrs['id'] = $attachment_id;
        $attrs['url'] = esc_url_raw($full_url);
        $attrs['backgroundType'] = 'image';

        $image_markup = $this->build_cover_image_markup($attachment_id, $attrs);
        if ('' === $image_markup) {
            return [$block, false];
        }

        $block['attrs'] = $attrs;
        $block = $this->replace_block_image_markup($block, $remote_url, $image_markup, '/<img\b[^>]*wp-block-cover__image-background[^>]*>/i');

        return [$block, true];
    }

    private function synchronize_media_text_block(array $block, array $attachment_map): array
    {
        $remote_url = $this->detect_media_text_block_url($block);
        if ('' === $remote_url || empty($attachment_map[$remote_url])) {
            return [$block, false];
        }

        $attachment_id = absint($attachment_map[$remote_url]);
        if ($attachment_id <= 0 || !get_post($attachment_id)) {
            return [$block, false];
        }

        $full_url = wp_get_attachment_url($attachment_id);
        if (!$full_url) {
            return [$block, false];
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $attrs['mediaId'] = $attachment_id;
        $attrs['mediaUrl'] = esc_url_raw($full_url);
        $attrs['mediaType'] = 'image';

        $image_markup = $this->build_media_text_image_markup($attachment_id, $attrs);
        if ('' === $image_markup) {
            return [$block, false];
        }

        $block['attrs'] = $attrs;
        $block = $this->replace_block_image_markup($block, $remote_url, $image_markup);

        return [$block, true];
    }

    private function detect_image_block_url(array $block): string
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        if (!empty($attrs['url']) && is_string($attrs['url'])) {
            return html_entity_decode(trim($attrs['url']), ENT_QUOTES);
        }

        $inner_html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
        if ('' !== $inner_html && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $inner_html, $matches) && !empty($matches[1])) {
            return html_entity_decode(trim((string) $matches[1]), ENT_QUOTES);
        }

        return '';
    }

    private function detect_cover_block_url(array $block): string
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        if (!empty($attrs['url']) && is_string($attrs['url'])) {
            return html_entity_decode(trim($attrs['url']), ENT_QUOTES);
        }

        $inner_html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
        if (
            '' !== $inner_html &&
            preg_match('/<img[^>]+class=["\'][^"\']*wp-block-cover__image-background[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i', $inner_html, $matches) &&
            !empty($matches[1])
        ) {
            return html_entity_decode(trim((string) $matches[1]), ENT_QUOTES);
        }

        return '';
    }

    private function detect_media_text_block_url(array $block): string
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        if (!empty($attrs['mediaUrl']) && is_string($attrs['mediaUrl'])) {
            return html_entity_decode(trim($attrs['mediaUrl']), ENT_QUOTES);
        }

        $inner_html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
        if ('' !== $inner_html && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $inner_html, $matches) && !empty($matches[1])) {
            return html_entity_decode(trim((string) $matches[1]), ENT_QUOTES);
        }

        return '';
    }

    private function resolve_image_block_size_slug(array $attrs, int $attachment_id): string
    {
        $size_slug = !empty($attrs['sizeSlug']) && is_string($attrs['sizeSlug']) ? sanitize_key($attrs['sizeSlug']) : 'full';
        if ('' !== $size_slug && wp_get_attachment_image_url($attachment_id, $size_slug)) {
            return $size_slug;
        }

        return 'full';
    }

    private function build_image_block_markup(int $attachment_id, array $attrs, string $size_slug, string $fallback_href): string
    {
        $figure_classes = ['wp-block-image', 'size-' . sanitize_html_class($size_slug)];
        if (!empty($attrs['align']) && is_string($attrs['align'])) {
            $figure_classes[] = 'align' . sanitize_html_class($attrs['align']);
        }
        if (!empty($attrs['width']) || !empty($attrs['height'])) {
            $figure_classes[] = 'is-resized';
        }
        if (!empty($attrs['className']) && is_string($attrs['className'])) {
            $custom_classes = preg_split('/\s+/', trim($attrs['className']));
            if (is_array($custom_classes)) {
                foreach ($custom_classes as $custom_class) {
                    $custom_class = sanitize_html_class($custom_class);
                    if ('' !== $custom_class) {
                        $figure_classes[] = $custom_class;
                    }
                }
            }
        }

        $alt = isset($attrs['alt']) && is_string($attrs['alt']) ? $attrs['alt'] : '';
        $image_attributes = ['alt' => $alt];
        if (!empty($attrs['width'])) {
            $image_attributes['width'] = absint($attrs['width']);
        }
        if (!empty($attrs['height'])) {
            $image_attributes['height'] = absint($attrs['height']);
        }

        $image_html = wp_get_attachment_image($attachment_id, $size_slug, false, $image_attributes);
        if (!$image_html) {
            $image_src = wp_get_attachment_image_url($attachment_id, $size_slug);
            if (!$image_src) {
                return '';
            }

            $image_html = sprintf(
                '<img src="%1$s" alt="%2$s" class="%3$s" />',
                esc_url($image_src),
                esc_attr($alt),
                esc_attr(sprintf('attachment-%1$s size-%1$s wp-image-%2$d', sanitize_html_class($size_slug), $attachment_id))
            );
        }

        $link_destination = isset($attrs['linkDestination']) && is_string($attrs['linkDestination']) ? $attrs['linkDestination'] : '';
        $link_href = '';
        if ('custom' === $link_destination && !empty($attrs['href']) && is_string($attrs['href'])) {
            $link_href = $attrs['href'];
        } elseif (in_array($link_destination, ['media', 'attachment'], true)) {
            $link_href = $fallback_href;
        }

        if ('' !== $link_href) {
            $link_attributes = ['href="' . esc_url($link_href) . '"'];
            if (!empty($attrs['linkTarget']) && is_string($attrs['linkTarget'])) {
                $link_attributes[] = 'target="' . esc_attr($attrs['linkTarget']) . '"';
            }
            if (!empty($attrs['rel']) && is_string($attrs['rel'])) {
                $link_attributes[] = 'rel="' . esc_attr($attrs['rel']) . '"';
            }

            $image_html = '<a ' . implode(' ', $link_attributes) . '>' . $image_html . '</a>';
        }

        $caption = isset($attrs['caption']) && is_string($attrs['caption']) ? trim($attrs['caption']) : '';
        $markup = '<figure class="' . esc_attr(implode(' ', array_values(array_unique(array_filter($figure_classes))))) . '">' . $image_html;
        if ('' !== $caption) {
            $markup .= '<figcaption class="wp-element-caption">' . wp_kses_post($caption) . '</figcaption>';
        }
        $markup .= '</figure>';

        return $markup;
    }

    private function build_cover_image_markup(int $attachment_id, array $attrs): string
    {
        $position = $this->build_object_position_value($attrs);
        $image_attributes = [
            'class' => 'wp-block-cover__image-background wp-image-' . $attachment_id,
            'alt' => '',
            'data-object-fit' => 'cover',
        ];

        if ('' !== $position) {
            $image_attributes['style'] = 'object-position:' . $position . ';';
            $image_attributes['data-object-position'] = $position;
        }

        $image_html = wp_get_attachment_image($attachment_id, 'full', false, $image_attributes);
        if (!$image_html) {
            $image_src = wp_get_attachment_image_url($attachment_id, 'full');
            if (!$image_src) {
                return '';
            }

            $image_html = sprintf(
                '<img src="%1$s" alt="" class="%2$s" data-object-fit="cover"%3$s />',
                esc_url($image_src),
                esc_attr('wp-block-cover__image-background wp-image-' . $attachment_id),
                '' !== $position ? ' style="object-position:' . esc_attr($position) . ';" data-object-position="' . esc_attr($position) . '"' : ''
            );
        }

        return $image_html;
    }

    private function build_media_text_image_markup(int $attachment_id, array $attrs): string
    {
        $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        $image_attributes = [
            'class' => 'wp-image-' . $attachment_id . ' size-full',
            'alt' => $alt,
        ];

        $position = $this->build_object_position_value($attrs);
        if ('' !== $position) {
            $image_attributes['style'] = 'object-position:' . $position . ';';
        }

        $image_html = wp_get_attachment_image($attachment_id, 'full', false, $image_attributes);
        if (!$image_html) {
            $image_src = wp_get_attachment_image_url($attachment_id, 'full');
            if (!$image_src) {
                return '';
            }

            $style = '' !== $position ? ' style="object-position:' . esc_attr($position) . ';"' : '';
            $image_html = sprintf(
                '<img src="%1$s" alt="%2$s" class="%3$s"%4$s />',
                esc_url($image_src),
                esc_attr($alt),
                esc_attr('wp-image-' . $attachment_id . ' size-full'),
                $style
            );
        }

        return $image_html;
    }

    private function replace_block_image_markup(array $block, string $remote_url, string $replacement_html, string $preferred_pattern = ''): array
    {
        $patterns = [];
        if ('' !== $preferred_pattern) {
            $patterns[] = $preferred_pattern;
        }

        if ('' !== $remote_url) {
            $patterns[] = '/<img\b(?=[^>]*\bsrc=["\']' . preg_quote($remote_url, '/') . '["\'])[^>]*>/i';
        }

        if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
            $block['innerHTML'] = $this->replace_first_matching_markup((string) $block['innerHTML'], $patterns, $replacement_html);
        }

        if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            foreach ($block['innerContent'] as $index => $segment) {
                if (!is_string($segment) || '' === $segment) {
                    continue;
                }

                $block['innerContent'][$index] = $this->replace_first_matching_markup($segment, $patterns, $replacement_html);
            }
        }

        return $block;
    }

    private function replace_first_matching_markup(string $markup, array $patterns, string $replacement_html): string
    {
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || '' === $pattern) {
                continue;
            }

            $result = preg_replace_callback(
                $pattern,
                static function () use ($replacement_html) {
                    return $replacement_html;
                },
                $markup,
                1,
                $count
            );

            if (is_string($result) && $count > 0) {
                return $result;
            }
        }

        return $markup;
    }

    private function build_object_position_value(array $attrs): string
    {
        if (
            empty($attrs['focalPoint']) ||
            !is_array($attrs['focalPoint']) ||
            !isset($attrs['focalPoint']['x'], $attrs['focalPoint']['y'])
        ) {
            return '';
        }

        $x = min(100, max(0, round(((float) $attrs['focalPoint']['x']) * 100, 2)));
        $y = min(100, max(0, round(((float) $attrs['focalPoint']['y']) * 100, 2)));

        return $x . '% ' . $y . '%';
    }

    private function maybe_set_featured_image_from_localized_images(int $post_id, string $content, array $attachment_map): bool
    {
        if (
            empty($attachment_map) ||
            has_post_thumbnail($post_id) ||
            !post_type_supports((string) get_post_type($post_id), 'thumbnail')
        ) {
            return false;
        }

        $should_auto_set = (bool) apply_filters('image2url_migration_auto_set_featured_image', true, $post_id, $attachment_map);
        if (!$should_auto_set) {
            return false;
        }

        $attachment_id = $this->find_featured_image_candidate($content, $attachment_map);
        if ($attachment_id <= 0) {
            return false;
        }

        return (bool) set_post_thumbnail($post_id, $attachment_id);
    }

    private function collect_block_validation_issues(array $blocks, array $mapping_index, array &$issues, array &$summary): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = (string) ($block['blockName'] ?? '');
            $remote_url = '';
            $attachment_id = 0;
            $is_supported_block = false;

            if ('core/image' === $block_name) {
                $remote_url = $this->detect_image_block_url($block);
                $attachment_id = $this->extract_supported_block_attachment_id($block);
                $is_supported_block = true;
            } elseif ('core/cover' === $block_name) {
                $remote_url = $this->detect_cover_block_url($block);
                $attachment_id = $this->extract_supported_block_attachment_id($block);
                $is_supported_block = true;
            } elseif ('core/media-text' === $block_name) {
                $remote_url = $this->detect_media_text_block_url($block);
                $attachment_id = $this->extract_supported_block_attachment_id($block);
                $is_supported_block = true;
            }

            if ($is_supported_block) {
                $summary['checked']++;

                if ('' !== $remote_url && $this->is_external_image_url($remote_url)) {
                    $summary['issues']++;
                    $issues[] = sprintf(
                        esc_html__('%1$s 仍使用远端图片：%2$s', 'image2url-clipboard-booster'),
                        $block_name,
                        $remote_url
                    );
                }

                if ($attachment_id > 0 && !get_post($attachment_id)) {
                    $summary['issues']++;
                    $issues[] = sprintf(
                        esc_html__('%1$s 引用的本地附件不存在：#%2$d', 'image2url-clipboard-booster'),
                        $block_name,
                        $attachment_id
                    );
                }

                if (!$this->is_external_image_url($remote_url) && 0 === $attachment_id && '' !== $remote_url) {
                    $summary['issues']++;
                    $issues[] = sprintf(
                        esc_html__('%1$s 已切到本地 URL，但缺少附件 ID。', 'image2url-clipboard-booster'),
                        $block_name
                    );
                }

                if ('' !== $remote_url && isset($mapping_index[$remote_url]) && !empty($mapping_index[$remote_url]['local_attachment_id']) && 0 === $attachment_id) {
                    $summary['issues']++;
                    $issues[] = sprintf(
                        esc_html__('%1$s 已有映射记录，但块属性还没绑定本地附件。', 'image2url-clipboard-booster'),
                        $block_name
                    );
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collect_block_validation_issues($block['innerBlocks'], $mapping_index, $issues, $summary);
            }
        }
    }

    private function extract_supported_block_attachment_id(array $block): int
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $block_name = (string) ($block['blockName'] ?? '');

        if (in_array($block_name, ['core/image', 'core/cover'], true) && !empty($attrs['id'])) {
            return absint($attrs['id']);
        }

        if ('core/media-text' === $block_name && !empty($attrs['mediaId'])) {
            return absint($attrs['mediaId']);
        }

        return 0;
    }

    private function find_featured_image_candidate(string $content, array $attachment_map): int
    {
        if (function_exists('has_blocks') && function_exists('parse_blocks') && has_blocks($content)) {
            $blocks = parse_blocks($content);
            if (is_array($blocks)) {
                $attachment_id = $this->find_featured_image_candidate_in_blocks($blocks, $attachment_map);
                if ($attachment_id > 0) {
                    return $attachment_id;
                }
            }
        }

        $urls = $this->extract_remote_image_urls($content);
        foreach ($urls as $url) {
            if (!empty($attachment_map[$url])) {
                return absint($attachment_map[$url]);
            }
        }

        return 0;
    }

    private function find_featured_image_candidate_in_blocks(array $blocks, array $attachment_map): int
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = (string) ($block['blockName'] ?? '');
            $remote_url = '';
            if ('core/image' === $block_name) {
                $remote_url = $this->detect_image_block_url($block);
            } elseif ('core/cover' === $block_name) {
                $remote_url = $this->detect_cover_block_url($block);
            } elseif ('core/media-text' === $block_name) {
                $remote_url = $this->detect_media_text_block_url($block);
            }

            if ('' !== $remote_url && !empty($attachment_map[$remote_url])) {
                return absint($attachment_map[$remote_url]);
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $attachment_id = $this->find_featured_image_candidate_in_blocks($block['innerBlocks'], $attachment_map);
                if ($attachment_id > 0) {
                    return $attachment_id;
                }
            }
        }

        return 0;
    }

    private function find_mapping(int $post_id, string $remote_url): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->mapping_table_name} WHERE post_id = %d AND remote_url_hash = %s ORDER BY id DESC LIMIT 1", $post_id, md5($remote_url)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function get_post_mappings(int $post_id): array
    {
        global $wpdb;
        if ($post_id <= 0) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->mapping_table_name} WHERE post_id = %d ORDER BY id DESC",
                $post_id
            ),
            ARRAY_A
        );
    }

    private function upsert_mapping(int $post_id, string $remote_url, array $fields = []): void
    {
        global $wpdb;
        $existing = $this->find_mapping($post_id, $remote_url);
        $timestamp = current_time('mysql');
        $data = array_merge(
            [
                'post_id' => $post_id,
                'remote_url' => esc_url_raw($remote_url),
                'remote_url_hash' => md5($remote_url),
                'local_attachment_id' => !empty($existing['local_attachment_id']) ? (int) $existing['local_attachment_id'] : 0,
                'status' => !empty($existing['status']) ? $existing['status'] : 'remote_only',
                'last_error' => isset($existing['last_error']) ? $existing['last_error'] : null,
                'created_by' => get_current_user_id(),
                'updated_at' => $timestamp,
                'migrated_at' => !empty($existing['migrated_at']) ? $existing['migrated_at'] : null,
            ],
            $fields
        );

        if ($existing) {
            $wpdb->update($this->mapping_table_name, $data, ['id' => (int) $existing['id']], ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s'], ['%d']);
            return;
        }

        $data['created_at'] = $timestamp;
        $wpdb->insert($this->mapping_table_name, $data, ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    private function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $mapping_sql = "CREATE TABLE {$this->mapping_table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            remote_url text NOT NULL,
            remote_url_hash char(32) NOT NULL,
            local_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(32) NOT NULL DEFAULT 'remote_only',
            last_error text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            migrated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY remote_url_hash (remote_url_hash),
            KEY local_attachment_id (local_attachment_id),
            KEY status (status)
        ) {$charset_collate};";

        $jobs_sql = "CREATE TABLE {$this->jobs_table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            job_type varchar(32) NOT NULL DEFAULT 'rollback',
            status varchar(32) NOT NULL DEFAULT 'queued',
            post_ids_json longtext NOT NULL,
            current_index int(11) NOT NULL DEFAULT 0,
            total_posts int(11) NOT NULL DEFAULT 0,
            processed_posts int(11) NOT NULL DEFAULT 0,
            localized_count int(11) NOT NULL DEFAULT 0,
            replaced_count int(11) NOT NULL DEFAULT 0,
            failed_count int(11) NOT NULL DEFAULT 0,
            last_message text NULL,
            error_log longtext NULL,
            report_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_by (created_by),
            KEY job_type (job_type),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        dbDelta($mapping_sql);
        dbDelta($jobs_sql);
        update_option($this->table_version_option, self::TABLE_VERSION);
    }
}
