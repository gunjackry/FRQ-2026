<?php

if (! defined('ABSPATH')) {
    exit;
}

class FRQ_P1_V1_Admin
{
    public static function register_menu(): void
    {
        add_menu_page(
            'FRQ Phase 1 Submissions',
            'FRQ Phase 1',
            'manage_options',
            'frq-p1-v1-submissions',
            [self::class, 'render_submissions_page'],
            'dashicons-networking',
            58
        );

        add_submenu_page(
            'frq-p1-v1-submissions',
            'FRQ Phase 1 Settings',
            'Settings',
            'manage_options',
            'frq-p1-v1-settings',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('frq_p1_v1_settings', 'frq_p1_v1_notification_emails', [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_email_list'],
            'default'           => '',
        ]);

        register_setting('frq_p1_v1_settings', 'frq_p1_v1_mail_from_name', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('frq_p1_v1_settings', 'frq_p1_v1_mail_from_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ]);
    }

    public static function sanitize_email_list($value): string
    {
        $raw = preg_split('/[\s,;]+/', (string) $value);
        $emails = [];
        foreach ((array) $raw as $item) {
            $clean = sanitize_email($item);
            if ($clean) {
                $emails[] = $clean;
            }
        }

        return implode("\n", array_values(array_unique($emails)));
    }

    public static function render_submissions_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        global $wpdb;
        $table = FRQ_P1_V1_DB::table_name();
        $rows = $wpdb->get_results("SELECT id, contact_name, guest_email, status, tree_data, created_at FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        echo '<div class="wrap"><h1>FRQ Phase 1 Submissions</h1>';
        echo '<p>Latest guest submissions for review and follow-up.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Contact Name</th><th>Email</th><th>Status</th><th>People</th><th>Created</th></tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="6">No submissions found.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row['tree_data'], true);
                $count = is_array($decoded) && isset($decoded['people']) && is_array($decoded['people']) ? count($decoded['people']) : 0;
                printf(
                    '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
                    (int) $row['id'],
                    esc_html((string) ($row['contact_name'] ?? '')),
                    esc_html((string) ($row['guest_email'] ?? '')),
                    esc_html((string) ($row['status'] ?? '')),
                    $count,
                    esc_html((string) ($row['created_at'] ?? ''))
                );
            }
        }

        echo '</tbody></table></div>';
    }

    public static function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        ?>
        <div class="wrap">
            <h1>FRQ Phase 1 Settings</h1>
            <p>Configure team notifications for submitted FRQ assessments.</p>
            <form method="post" action="options.php">
                <?php settings_fields('frq_p1_v1_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="frq_p1_v1_notification_emails">Notification Emails</label></th>
                        <td>
                            <textarea name="frq_p1_v1_notification_emails" id="frq_p1_v1_notification_emails" rows="5" cols="50"><?php echo esc_textarea((string) get_option('frq_p1_v1_notification_emails', '')); ?></textarea>
                            <p class="description">One email per line (or comma separated). If empty, WordPress admin email is used.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="frq_p1_v1_mail_from_name">From Name</label></th>
                        <td>
                            <input type="text" class="regular-text" name="frq_p1_v1_mail_from_name" id="frq_p1_v1_mail_from_name" value="<?php echo esc_attr((string) get_option('frq_p1_v1_mail_from_name', '')); ?>" />
                            <p class="description">Optional sender name for notification emails.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="frq_p1_v1_mail_from_email">From Email</label></th>
                        <td>
                            <input type="email" class="regular-text" name="frq_p1_v1_mail_from_email" id="frq_p1_v1_mail_from_email" value="<?php echo esc_attr((string) get_option('frq_p1_v1_mail_from_email', '')); ?>" />
                            <p class="description">Optional sender email for notification emails.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save FRQ Settings'); ?>
            </form>
        </div>
        <?php
    }
}
