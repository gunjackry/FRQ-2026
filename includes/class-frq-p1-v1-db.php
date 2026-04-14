<?php

if (! defined('ABSPATH')) {
    exit;
}

class FRQ_P1_V1_DB
{
    private const SCHEMA_VERSION = '1.3.0';

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'frq_p1_v1_submissions';
    }

    public static function shares_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'frq_p1_v1_shares';
    }

    public static function activate(): void
    {
        self::migrate();
    }

    public static function maybe_migrate(): void
    {
        $installed = (string) get_option('frq_p1_v1_db_version', '');
        if ($installed !== self::SCHEMA_VERSION) {
            self::migrate();
        }
    }

    public static function migrate(): void
    {
        global $wpdb;

        $table = self::table_name();
        $shares_table = self::shares_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $submissions_sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_token VARCHAR(64) NOT NULL,
            contact_name VARCHAR(190) NULL,
            guest_email VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            tree_data LONGTEXT NOT NULL,
            share_token VARCHAR(64) NOT NULL,
            gamification_score INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_token (submission_token),
            KEY share_token (share_token)
        ) {$charset_collate};";

        $shares_sql = "CREATE TABLE {$shares_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            share_token VARCHAR(64) NOT NULL,
            sender_email VARCHAR(190) NULL,
            recipient_email VARCHAR(190) NOT NULL,
            recipient_name VARCHAR(190) NULL,
            message TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            first_opened_at DATETIME NULL,
            last_opened_at DATETIME NULL,
            open_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY share_token (share_token),
            KEY submission_id (submission_id),
            KEY recipient_email (recipient_email),
            KEY status_expires (status, expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($submissions_sql);
        dbDelta($shares_sql);

        update_option('frq_p1_v1_db_version', self::SCHEMA_VERSION);
    }
}
