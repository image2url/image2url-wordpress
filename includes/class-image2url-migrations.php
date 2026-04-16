<?php

if (!defined('ABSPATH')) {
    exit;
}

class Image2URL_Migrations
{
    const TABLE_VERSION = '1.1.0';
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
                    'running' => esc_html__('后台正在执行批量回退...', 'image2url-clipboard-booster'),
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
                    <p><?php echo esc_html__('检测到站点已禁用 WP-Cron。若未在服务器侧单独触发 wp-cron.php，批量回退任务将无法自动推进。', 'image2url-clipboard-booster'); ?></p>
                </div>
            <?php endif; ?>

            <p><?php echo esc_html__('这个页面用于把文章里的外链图片下载到 WordPress 媒体库，并将内容中的外链替换为本地 URL。', 'image2url-clipboard-booster'); ?></p>
            <p><?php echo esc_html__('回退时会优先把 core/image、core/cover 和 core/media-text 区块同步为本地附件引用；如果文章还没有特色图，会尝试将正文首张已本地化图片设为特色图。', 'image2url-clipboard-booster'); ?></p>
            <p><?php echo esc_html__('单篇模式适合即时回退；批量模式会创建后台任务，由 WP-Cron 按批次逐篇处理，不依赖当前页面持续打开。', 'image2url-clipboard-booster'); ?></p>

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
                            <th><?php echo esc_html__('状态', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('进度', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('下载', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('替换', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('失败', 'image2url-clipboard-booster'); ?></th>
                            <th><?php echo esc_html__('更新时间', 'image2url-clipboard-booster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_jobs as $job) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url($this->build_job_link((int) $job['id'])); ?>">#<?php echo esc_html((string) $job['id']); ?></a></td>
                                <td><?php echo esc_html($job['status']); ?></td>
                                <td><?php echo esc_html((string) $job['processed_posts']); ?>/<?php echo esc_html((string) $job['total_posts']); ?></td>
                                <td><?php echo esc_html((string) $job['localized_count']); ?></td>
                                <td><?php echo esc_html((string) $job['replaced_count']); ?></td>
                                <td><?php echo esc_html((string) $job['failed_count']); ?></td>
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

            case 'queue_batch_job':
                $post_ids = $this->parse_post_ids(isset($_POST['post_ids']) ? wp_unslash($_POST['post_ids']) : '');
                if (empty($post_ids)) {
                    add_settings_error('image2url_migration', 'image2url_batch_error', esc_html__('请至少输入一个有效的文章 ID。', 'image2url-clipboard-booster'), 'error');
                    return;
                }
                $job_id = $this->create_batch_job($post_ids);
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
        }
    }

    private function render_query_notice(): void
    {
        $notice = isset($_GET['image2url_notice']) ? sanitize_key(wp_unslash($_GET['image2url_notice'])) : '';
        if ('batch_job_created' !== $notice || $this->current_job_id <= 0) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html(sprintf(__('批量回退任务 #%d 已创建，并已加入后台队列。页面会自动刷新状态。', 'image2url-clipboard-booster'), $this->current_job_id)); ?></p>
        </div>
        <?php
    }

    private function render_current_job_panel(array $job): void
    {
        $job_view = $this->format_job_for_response($job);
        ?>
        <hr>
        <h2><?php echo esc_html__('当前任务', 'image2url-clipboard-booster'); ?></h2>
        <div data-image2url-job-panel="true" data-job-id="<?php echo esc_attr((string) $job_view['id']); ?>" data-job-status="<?php echo esc_attr($job_view['status']); ?>" style="border:1px solid #dcdcde; background:#fff; padding:16px; max-width:900px;">
            <p><strong><?php echo esc_html__('任务 #', 'image2url-clipboard-booster') . esc_html((string) $job_view['id']); ?></strong> <span style="margin-left:12px;"><?php echo esc_html__('状态：', 'image2url-clipboard-booster'); ?><span data-image2url-job-status-label="true"><?php echo esc_html($job_view['status']); ?></span></span></p>
            <p data-image2url-job-message="true"><?php echo esc_html($job_view['lastMessage']); ?></p>
            <table class="widefat striped" style="max-width:760px; margin-bottom:12px;">
                <tbody>
                    <tr><th><?php echo esc_html__('进度', 'image2url-clipboard-booster'); ?></th><td><span data-image2url-job-progress="true"><?php echo esc_html((string) $job_view['processedPosts']); ?>/<?php echo esc_html((string) $job_view['totalPosts']); ?></span></td></tr>
                    <tr><th><?php echo esc_html__('下载到本地', 'image2url-clipboard-booster'); ?></th><td><span data-image2url-job-localized="true"><?php echo esc_html((string) $job_view['localizedCount']); ?></span></td></tr>
                    <tr><th><?php echo esc_html__('内容替换', 'image2url-clipboard-booster'); ?></th><td><span data-image2url-job-replaced="true"><?php echo esc_html((string) $job_view['replacedCount']); ?></span></td></tr>
                    <tr><th><?php echo esc_html__('失败', 'image2url-clipboard-booster'); ?></th><td><span data-image2url-job-failed="true"><?php echo esc_html((string) $job_view['failedCount']); ?></span></td></tr>
                </tbody>
            </table>
            <p><button type="button" class="button button-primary" data-image2url-run-job="true"><?php echo esc_html($this->get_job_button_label($job_view['status'])); ?></button></p>
            <p><strong><?php echo esc_html__('最近日志', 'image2url-clipboard-booster'); ?></strong></p>
            <pre data-image2url-job-log="true" style="background:#f6f7f7; border:1px solid #dcdcde; padding:12px; max-height:220px; overflow:auto; white-space:pre-wrap;"><?php echo esc_html($job_view['errorLog']); ?></pre>
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
            return $wpdb->get_results("SELECT id, status, total_posts, processed_posts, localized_count, replaced_count, failed_count, updated_at FROM {$this->jobs_table_name} ORDER BY updated_at DESC, id DESC LIMIT 10", ARRAY_A);
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, total_posts, processed_posts, localized_count, replaced_count, failed_count, updated_at
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

    private function create_batch_job(array $post_ids): int
    {
        global $wpdb;
        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        if (empty($post_ids)) {
            return 0;
        }

        $timestamp = current_time('mysql');
        $inserted = $wpdb->insert(
            $this->jobs_table_name,
            [
                'created_by' => get_current_user_id(),
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
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'completed_at' => null,
            ],
            ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
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

        $current_index = (int) $job['current_index'];
        $processed_posts = (int) $job['processed_posts'];
        $localized_count = (int) $job['localized_count'];
        $replaced_count = (int) $job['replaced_count'];
        $failed_count = (int) $job['failed_count'];
        $last_message = (string) $job['last_message'];
        $job_log = isset($job['error_log']) ? (string) $job['error_log'] : '';
        $batch_messages = [];
        $processed_in_batch = 0;
        $total_posts = count($post_ids);
        $actor_user_id = isset($job['created_by']) ? (int) $job['created_by'] : 0;

        $this->update_job(
            (int) $job['id'],
            [
                'status' => 'running',
                'last_message' => esc_html__('后台正在处理当前批次。', 'image2url-clipboard-booster'),
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

            $result = $this->rollback_post($post_id, $actor_user_id);
            if (!empty($result['error'])) {
                $failed_count++;
                $message = sprintf(esc_html__('文章 #%1$d 处理失败：%2$s', 'image2url-clipboard-booster'), $post_id, $result['error']);
                $batch_messages[] = $message;
                $last_message = $message;
                continue;
            }

            $localized_count += (int) ($result['localized'] ?? 0);
            $replaced_count += (int) ($result['replaced'] ?? 0);
            if (!empty($result['failed'])) {
                $failed_count += (int) $result['failed'];
                $batch_messages[] = sprintf(esc_html__('文章 #%1$d 已完成，但有 %2$d 项失败。', 'image2url-clipboard-booster'), $post_id, (int) $result['failed']);
            }
            $last_message = sprintf(esc_html__('文章 #%1$d 已处理，替换 %2$d 处。', 'image2url-clipboard-booster'), $post_id, (int) ($result['replaced'] ?? 0));
        }

        $status = 'running';
        $completed_at = null;
        if ($current_index >= $total_posts) {
            $status = $failed_count > 0 ? 'completed_with_errors' : 'completed';
            $completed_at = current_time('mysql');
            $last_message = 'completed' === $status ? esc_html__('批量回退任务已完成。', 'image2url-clipboard-booster') : esc_html__('批量回退任务已完成，但存在失败项。', 'image2url-clipboard-booster');
        }

        $job_log = $this->append_job_log($job_log, $batch_messages);
        $this->update_job((int) $job['id'], ['status' => $status, 'current_index' => $current_index, 'processed_posts' => $processed_posts, 'localized_count' => $localized_count, 'replaced_count' => $replaced_count, 'failed_count' => $failed_count, 'last_message' => $last_message, 'error_log' => $job_log, 'updated_at' => current_time('mysql'), 'completed_at' => $completed_at]);

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
        $allowed = ['status' => '%s', 'post_ids_json' => '%s', 'current_index' => '%d', 'total_posts' => '%d', 'processed_posts' => '%d', 'localized_count' => '%d', 'replaced_count' => '%d', 'failed_count' => '%d', 'last_message' => '%s', 'error_log' => '%s', 'updated_at' => '%s', 'completed_at' => '%s'];
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
        $total_posts = max(0, (int) ($job['total_posts'] ?? 0));
        $processed_posts = max(0, (int) ($job['processed_posts'] ?? 0));
        $status = (string) ($job['status'] ?? '');
        return [
            'id' => $job_id,
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
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_by (created_by),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        dbDelta($mapping_sql);
        dbDelta($jobs_sql);
        update_option($this->table_version_option, self::TABLE_VERSION);
    }
}
