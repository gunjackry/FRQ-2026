<?php

if (! defined('ABSPATH')) {
    exit;
}

class FRQ_P1_V1_REST
{
    private const MAX_PEOPLE = 250;
    private const MAX_SUBMISSIONS_PER_HOUR = 20;
    private const SCORE_PER_KNOWN_FIELD = 10;
    private const SCORE_PER_SHARE_CREATED = 15;
    private const SCORE_PER_SHARE_OPEN = 5;

    public static function register_routes(): void
    {
        register_rest_route('frq-p1-v1/v1', '/submission', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'create_submission'],
            'permission_callback' => [self::class, 'can_submit'],
        ]);

        register_rest_route('frq-p1-v1/v1', '/shares', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'create_shares'],
            'permission_callback' => [self::class, 'can_submit'],
        ]);

        register_rest_route('frq-p1-v1/v1', '/shares', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'list_shares'],
            'permission_callback' => [self::class, 'can_submit'],
        ]);

        register_rest_route('frq-p1-v1/v1', '/shares/revoke', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'revoke_share'],
            'permission_callback' => [self::class, 'can_submit'],
        ]);

        register_rest_route('frq-p1-v1/v1', '/shares/view', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'view_share'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('frq-p1-v1/v1', '/score', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'get_score_card'],
            'permission_callback' => [self::class, 'can_submit'],
        ]);
    }

    public static function can_submit(WP_REST_Request $request)
    {
        $nonce = (string) $request->get_header('X-FRQ-Nonce');
        if (! wp_verify_nonce($nonce, 'frq_p1_v1_submit')) {
            return new WP_Error('frq_invalid_nonce', 'Security check failed.', ['status' => 403]);
        }

        if (self::is_rate_limited()) {
            return new WP_Error('frq_rate_limited', 'Too many attempts. Please try again later.', ['status' => 429]);
        }

        return true;
    }

    public static function create_submission(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $table = FRQ_P1_V1_DB::table_name();

        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        if (! empty($payload['company'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Submission rejected.',
            ], 400);
        }

        $consent = ! empty($payload['consent']);

        $people = isset($payload['people']) && is_array($payload['people']) ? array_values($payload['people']) : [];
        if (empty($people)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Please add at least one family member before saving.',
            ], 400);
        }

        if (count($people) > self::MAX_PEOPLE) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Tree is too large. Please keep this phase to 250 people or fewer.',
            ], 400);
        }

        $clean_people = array_map([self::class, 'sanitize_person'], $people);
        $contact_name = self::trimmed_text($payload['contact_name'] ?? '', 160);
        $guest_email = isset($payload['guest_email']) ? sanitize_email((string) $payload['guest_email']) : '';
        $status = isset($payload['status']) && $payload['status'] === 'submitted' ? 'submitted' : 'draft';

        if (! $contact_name || ! $guest_email) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Please add your name and email before saving.',
            ], 400);
        }

        if ($status === 'submitted' && ! $consent) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Consent is required before submitting.',
            ], 400);
        }

        $submission_token = wp_generate_password(32, false, false);
        $share_token = wp_generate_password(24, false, false);
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $table,
            [
                'submission_token'   => $submission_token,
                'contact_name'       => $contact_name ?: null,
                'guest_email'        => $guest_email ?: null,
                'status'             => $status,
                'tree_data'          => wp_json_encode(['people' => $clean_people]),
                'share_token'        => $share_token,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_REST_Response(['success' => false, 'message' => 'Unable to save right now. Please try again.'], 500);
        }

        self::bump_rate_limit_counter();

        if ($status === 'submitted') {
            self::send_submission_notification([
                'submission_token' => $submission_token,
                'share_token'      => $share_token,
                'contact_name'     => $contact_name,
                'guest_email'      => $guest_email,
                'people'           => $clean_people,
                'created_at'       => $now,
            ]);
        } else {
            self::send_draft_save_copy([
                'submission_token' => $submission_token,
                'share_token'      => $share_token,
                'contact_name'     => $contact_name,
                'guest_email'      => $guest_email,
                'people'           => $clean_people,
                'created_at'       => $now,
            ]);
        }

        self::refresh_submission_score((int) $wpdb->insert_id, $clean_people);

        return new WP_REST_Response([
            'success'          => true,
            'submission_token' => $submission_token,
            'share_token'      => $share_token,
            'status'           => $status,
            'message'          => $status === 'submitted' ? 'Phase 1 submitted.' : 'Progress saved. A copy was emailed to you.',
            'score'            => self::submission_score_by_token($submission_token),
        ], 201);
    }

    public static function create_shares(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $submission_token = self::trimmed_text($payload['submission_token'] ?? '', 64);
        $submission = self::find_submission_by_token($submission_token);
        if (! $submission) {
            return new WP_REST_Response(['success' => false, 'message' => 'Submission not found.'], 404);
        }

        if (empty($payload['consent_to_share'])) {
            return new WP_REST_Response(['success' => false, 'message' => 'Sharing consent is required.'], 400);
        }

        $recipients = isset($payload['recipients']) && is_array($payload['recipients']) ? $payload['recipients'] : [];
        if (empty($recipients) || count($recipients) > 20) {
            return new WP_REST_Response(['success' => false, 'message' => 'Add between 1 and 20 recipients.'], 400);
        }

        $shares_table = FRQ_P1_V1_DB::shares_table_name();
        $sender_email = sanitize_email((string) ($payload['sender_email'] ?? ($submission['guest_email'] ?? '')));
        $message = self::trimmed_text($payload['message'] ?? '', 1000);
        $expires_days = (int) ($payload['expires_in_days'] ?? 30);
        $expires_days = max(1, min(90, $expires_days));
        $expires_at = gmdate('Y-m-d H:i:s', time() + ($expires_days * DAY_IN_SECONDS));
        $now = current_time('mysql');

        $created = [];

        foreach ($recipients as $recipient) {
            $email = sanitize_email((string) ($recipient['email'] ?? ''));
            if (! $email) {
                continue;
            }

            $name = self::trimmed_text($recipient['name'] ?? '', 160);
            $share_token = wp_generate_password(32, false, false);
            $inserted = $wpdb->insert(
                $shares_table,
                [
                    'submission_id'   => (int) $submission['id'],
                    'share_token'     => $share_token,
                    'sender_email'    => $sender_email ?: null,
                    'recipient_email' => $email,
                    'recipient_name'  => $name ?: null,
                    'message'         => $message ?: null,
                    'status'          => 'active',
                    'expires_at'      => $expires_at,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                continue;
            }

            $share_url = add_query_arg(['t' => $share_token], home_url('/frq-share/'));
            self::send_share_invite_email([
                'recipient_email' => $email,
                'recipient_name'  => $name,
                'sender_email'    => $sender_email,
                'share_url'       => $share_url,
                'expires_at'      => $expires_at,
                'message'         => $message,
            ]);

            $created[] = [
                'recipient_email' => $email,
                'share_token'     => $share_token,
                'share_url'       => $share_url,
                'expires_at'      => $expires_at,
            ];
        }

        if (empty($created)) {
            return new WP_REST_Response(['success' => false, 'message' => 'No share links were created.'], 500);
        }

        self::refresh_submission_score((int) $submission['id']);

        return new WP_REST_Response(['success' => true, 'shares' => $created], 201);
    }

    public static function list_shares(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $submission_token = self::trimmed_text($request->get_param('submission_token') ?? '', 64);
        $submission = self::find_submission_by_token($submission_token);
        if (! $submission) {
            return new WP_REST_Response(['success' => false, 'message' => 'Submission not found.'], 404);
        }

        $shares_table = FRQ_P1_V1_DB::shares_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT share_token, recipient_email, recipient_name, status, expires_at, open_count, last_opened_at, created_at
                 FROM {$shares_table}
                 WHERE submission_id = %d
                 ORDER BY id DESC",
                (int) $submission['id']
            ),
            ARRAY_A
        );

        return new WP_REST_Response(['success' => true, 'shares' => $rows], 200);
    }

    public static function revoke_share(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }

        $submission_token = self::trimmed_text($payload['submission_token'] ?? '', 64);
        $share_token = self::trimmed_text($payload['share_token'] ?? '', 64);
        $submission = self::find_submission_by_token($submission_token);

        if (! $submission || ! $share_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid revoke request.'], 400);
        }

        $shares_table = FRQ_P1_V1_DB::shares_table_name();
        $updated = $wpdb->update(
            $shares_table,
            ['status' => 'revoked', 'updated_at' => current_time('mysql')],
            ['submission_id' => (int) $submission['id'], 'share_token' => $share_token],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            return new WP_REST_Response(['success' => false, 'message' => 'Unable to revoke share.'], 500);
        }

        return new WP_REST_Response(['success' => true, 'status' => 'revoked'], 200);
    }

    public static function view_share(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $token = self::trimmed_text($request->get_param('token') ?? '', 64);
        if (! $token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Missing share token.'], 400);
        }

        $shares_table = FRQ_P1_V1_DB::shares_table_name();
        $submissions_table = FRQ_P1_V1_DB::table_name();

        $share = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.*, sub.submission_token, sub.contact_name, sub.tree_data
                 FROM {$shares_table} s
                 INNER JOIN {$submissions_table} sub ON sub.id = s.submission_id
                 WHERE s.share_token = %s
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        if (! $share) {
            return new WP_REST_Response(['success' => false, 'message' => 'Share not found.'], 404);
        }

        if (($share['status'] ?? '') !== 'active') {
            return new WP_REST_Response(['success' => false, 'message' => 'Share is not active.'], 410);
        }

        if (! empty($share['expires_at']) && strtotime((string) $share['expires_at']) < time()) {
            return new WP_REST_Response(['success' => false, 'message' => 'Share has expired.'], 410);
        }

        $now = current_time('mysql');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$shares_table}
                 SET open_count = open_count + 1,
                     first_opened_at = COALESCE(first_opened_at, %s),
                     last_opened_at = %s,
                     updated_at = %s
                 WHERE id = %d",
                $now,
                $now,
                $now,
                (int) $share['id']
            )
        );

        $decoded = json_decode((string) ($share['tree_data'] ?? ''), true);
        $people = is_array($decoded) && isset($decoded['people']) && is_array($decoded['people']) ? $decoded['people'] : [];
        self::refresh_submission_score((int) $share['submission_id'], $people);

        return new WP_REST_Response([
            'success' => true,
            'share'   => [
                'status'     => $share['status'],
                'expires_at' => $share['expires_at'],
            ],
            'origin'  => [
                'submission_token' => $share['submission_token'],
                'contact_name'     => $share['contact_name'],
            ],
            'tree'    => ['people' => $people],
            'cta'     => [
                'create_own_tree_url' => add_query_arg(['ref' => $token], home_url('/frq-start/')),
            ],
        ], 200);
    }

    public static function get_score_card(WP_REST_Request $request): WP_REST_Response
    {
        $submission_token = self::trimmed_text($request->get_param('submission_token') ?? '', 64);
        $submission = self::find_submission_by_token($submission_token);
        if (! $submission) {
            return new WP_REST_Response(['success' => false, 'message' => 'Submission not found.'], 404);
        }

        $decoded = json_decode((string) ($submission['tree_data'] ?? ''), true);
        $people = is_array($decoded) && isset($decoded['people']) && is_array($decoded['people']) ? $decoded['people'] : [];
        self::refresh_submission_score((int) $submission['id'], $people);
        $share_stats = self::submission_share_stats((int) $submission['id']);
        $score = self::submission_score_by_token($submission_token);

        return new WP_REST_Response([
            'success' => true,
            'score' => [
                'total' => $score,
                'known_fields' => self::known_fields_count($people),
                'shares_created' => (int) ($share_stats['shares_created'] ?? 0),
                'share_opens' => (int) ($share_stats['share_opens'] ?? 0),
                'social_share_text' => sprintf('I scored %d points on my FRQ Family Quest. Help me unlock more clues.', $score),
            ],
        ], 200);
    }

    private static function find_submission_by_token(string $submission_token): ?array
    {
        global $wpdb;
        if (! $submission_token) {
            return null;
        }

        $table = FRQ_P1_V1_DB::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE submission_token = %s LIMIT 1", $submission_token),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private static function sanitize_person(array $person): array
    {
        $generation = isset($person['generation']) ? (int) $person['generation'] : 1;

        return [
            'relation'        => self::sanitize_relation($person['relation'] ?? 'other'),
            'name'            => self::trimmed_text($person['name'] ?? '', 160),
            'dob'             => self::trimmed_text($person['dob'] ?? '', 50),
            'pob'             => self::trimmed_text($person['pob'] ?? '', 160),
            'occupation'      => self::trimmed_text($person['occupation'] ?? '', 120),
            'marital_status'  => self::trimmed_text($person['marital_status'] ?? '', 60),
            'crown_service'   => self::sanitize_crown_service($person['crown_service'] ?? 'unknown'),
            'research_branch' => ! empty($person['research_branch']),
            'generation'      => max(0, min(8, $generation)),
        ];
    }

    private static function sanitize_relation($value): string
    {
        $clean = sanitize_key((string) $value);
        $allowed = ['applicant', 'father', 'mother', 'paternal_grandfather', 'paternal_grandmother', 'maternal_grandfather', 'maternal_grandmother', 'great_paternal_grandfather', 'son', 'daughter', 'step_father', 'step_mother', 'adopted_parent', 'legal_guardian', 'step_son', 'step_daughter', 'adopted_son', 'adopted_daughter', 'other'];

        return in_array($clean, $allowed, true) ? $clean : 'other';
    }

    private static function sanitize_crown_service($value): string
    {
        $clean = sanitize_key((string) $value);

        return in_array($clean, ['yes', 'no', 'unknown'], true) ? $clean : 'unknown';
    }

    private static function trimmed_text($value, int $max_len): string
    {
        $clean = sanitize_text_field((string) $value);

        return function_exists('mb_substr') ? mb_substr($clean, 0, $max_len) : substr($clean, 0, $max_len);
    }

    private static function is_rate_limited(): bool
    {
        $key = self::rate_limit_key();

        return ((int) get_transient($key)) >= self::MAX_SUBMISSIONS_PER_HOUR;
    }

    private static function bump_rate_limit_counter(): void
    {
        $key = self::rate_limit_key();
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    private static function rate_limit_key(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return 'frq_p1_v1_rl_' . md5($ip);
    }

    private static function send_submission_notification(array $submission): void
    {
        $admin_url = admin_url('admin.php?page=frq-p1-v1-submissions');
        $recipients = self::notification_recipients($submission);
        if (empty($recipients)) {
            return;
        }

        $subject = sprintf('[FRQ Phase 1] New submitted assessment (%s)', $submission['submission_token']);
        $people_count = is_array($submission['people'] ?? null) ? count($submission['people']) : 0;
        $contact_name = ! empty($submission['contact_name']) ? (string) $submission['contact_name'] : 'Not provided';
        $guest_email = ! empty($submission['guest_email']) ? (string) $submission['guest_email'] : 'Not provided';

        $people_lines = self::format_people_lines($submission['people'] ?? []);

        $lines = [
            'A new FRQ Phase 1 assessment has been submitted.',
            '',
            'Submission token: ' . (string) $submission['submission_token'],
            'Share token: ' . (string) $submission['share_token'],
            'Contact name: ' . $contact_name,
            'Guest email: ' . $guest_email,
            'People in tree: ' . $people_count,
            'Submitted at: ' . (string) $submission['created_at'],
            '',
            'People details:',
            ...$people_lines,
            '',
            'Review submissions in WP Admin:',
            $admin_url,
        ];

        self::send_mail_with_configured_sender($recipients, $subject, implode("\n", $lines));
    }

    private static function send_draft_save_copy(array $submission): void
    {
        $team_recipients = self::notification_recipients($submission);
        $guest_email = sanitize_email((string) ($submission['guest_email'] ?? ''));
        $recipient_pool = array_merge($team_recipients, $guest_email ? [$guest_email] : []);
        $recipients = array_values(array_filter(array_unique(array_map('sanitize_email', $recipient_pool))));
        if (empty($recipients)) {
            return;
        }

        $subject = sprintf('[FRQ Phase 1] Progress saved (%s)', (string) ($submission['submission_token'] ?? ''));
        $people_count = is_array($submission['people'] ?? null) ? count($submission['people']) : 0;
        $contact_name = ! empty($submission['contact_name']) ? (string) $submission['contact_name'] : 'Not provided';
        $people_lines = self::format_people_lines($submission['people'] ?? []);

        $lines = [
            'An FRQ Phase 1 draft has been saved.',
            '',
            'Submission token: ' . (string) ($submission['submission_token'] ?? ''),
            'Contact name: ' . $contact_name,
            'Guest email: ' . ($guest_email ?: 'Not provided'),
            'People in tree: ' . $people_count,
            'Saved at: ' . (string) ($submission['created_at'] ?? ''),
            '',
            'People details:',
            ...$people_lines,
        ];

        self::send_mail_with_configured_sender($recipients, $subject, implode("\n", $lines));
    }

    private static function notification_recipients(array $submission): array
    {
        $configured_emails = (string) get_option('frq_p1_v1_notification_emails', '');
        $parsed = preg_split('/[\s,;]+/', $configured_emails);
        $recipients = array_values(array_filter(array_unique(array_map('sanitize_email', (array) $parsed))));
        if (empty($recipients)) {
            $recipients = [sanitize_email((string) get_option('admin_email'))];
        }

        $recipients = apply_filters('frq_p1_v1_notification_recipients', $recipients, $submission);

        return array_values(array_filter(array_unique(array_map('sanitize_email', (array) $recipients))));
    }

    private static function send_share_invite_email(array $data): void
    {
        $recipient = sanitize_email((string) ($data['recipient_email'] ?? ''));
        if (! $recipient) {
            return;
        }

        $subject = 'A family declaration has been shared with you';
        $name = self::trimmed_text($data['recipient_name'] ?? '', 160);
        $greeting = $name ? 'Hi ' . $name . ',' : 'Hi,';
        $message = self::trimmed_text($data['message'] ?? '', 1000);

        $lines = [
            $greeting,
            '',
            'A read-only FRQ family declaration has been shared with you.',
            'You can view it here:',
            (string) ($data['share_url'] ?? ''),
            '',
            'This share link expires on: ' . (string) ($data['expires_at'] ?? 'N/A'),
        ];

        if ($message) {
            $lines[] = '';
            $lines[] = 'Message from sender:';
            $lines[] = $message;
        }

        $lines[] = '';
        $lines[] = 'Want to create your own tree? Use this link after viewing the shared copy.';

        self::send_mail_with_configured_sender([$recipient], $subject, implode("\n", $lines));
    }

    private static function format_people_lines($people): array
    {
        if (! is_array($people) || empty($people)) {
            return ['- No person records submitted'];
        }

        $lines = [];
        foreach ($people as $person) {
            $relation = self::relation_label((string) ($person['relation'] ?? 'other'));
            $name = self::trimmed_text($person['name'] ?? '', 160);
            $dob = self::trimmed_text($person['dob'] ?? '', 50);
            $pob = self::trimmed_text($person['pob'] ?? '', 160);

            $parts = [$relation];
            if ($name) {
                $parts[] = $name;
            }
            if ($dob) {
                $parts[] = 'DOB: ' . $dob;
            }
            if ($pob) {
                $parts[] = 'POB: ' . $pob;
            }

            $lines[] = '- ' . implode(' | ', $parts);
        }

        return $lines;
    }

    private static function relation_label(string $relation): string
    {
        $labels = [
            'applicant' => 'Applicant',
            'father' => 'Father',
            'mother' => 'Mother',
            'paternal_grandfather' => 'Paternal Grandfather',
            'paternal_grandmother' => 'Paternal Grandmother',
            'maternal_grandfather' => 'Maternal Grandfather',
            'maternal_grandmother' => 'Maternal Grandmother',
            'great_paternal_grandfather' => 'Great Paternal Grandfather',
            'son' => 'Son',
            'daughter' => 'Daughter',
            'step_father' => 'Step Father',
            'step_mother' => 'Step Mother',
            'adopted_parent' => 'Adopted Parent',
            'legal_guardian' => 'Legal Guardian',
            'step_son' => 'Step Son',
            'step_daughter' => 'Step Daughter',
            'adopted_son' => 'Adopted Son',
            'adopted_daughter' => 'Adopted Daughter',
            'other' => 'Other',
        ];

        return $labels[$relation] ?? 'Other';
    }

    private static function known_fields_count(array $people): int
    {
        $count = 0;
        foreach ($people as $person) {
            if (! is_array($person)) {
                continue;
            }
            if (! empty(trim((string) ($person['name'] ?? '')))) {
                $count++;
            }
            if (! empty(trim((string) ($person['dob'] ?? '')))) {
                $count++;
            }
            if (! empty(trim((string) ($person['pob'] ?? '')))) {
                $count++;
            }
        }

        return $count;
    }

    private static function submission_share_stats(int $submission_id): array
    {
        global $wpdb;
        $shares_table = FRQ_P1_V1_DB::shares_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS shares_created, COALESCE(SUM(open_count), 0) AS share_opens
                 FROM {$shares_table}
                 WHERE submission_id = %d",
                $submission_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : ['shares_created' => 0, 'share_opens' => 0];
    }

    private static function refresh_submission_score(int $submission_id, ?array $people = null): void
    {
        global $wpdb;
        if ($submission_id <= 0) {
            return;
        }

        if ($people === null) {
            $table = FRQ_P1_V1_DB::table_name();
            $row = $wpdb->get_row($wpdb->prepare("SELECT tree_data FROM {$table} WHERE id = %d LIMIT 1", $submission_id), ARRAY_A);
            $decoded = json_decode((string) ($row['tree_data'] ?? ''), true);
            $people = is_array($decoded) && isset($decoded['people']) && is_array($decoded['people']) ? $decoded['people'] : [];
        }

        $known_fields = self::known_fields_count($people);
        $share_stats = self::submission_share_stats($submission_id);
        $score = ($known_fields * self::SCORE_PER_KNOWN_FIELD)
            + ((int) ($share_stats['shares_created'] ?? 0) * self::SCORE_PER_SHARE_CREATED)
            + ((int) ($share_stats['share_opens'] ?? 0) * self::SCORE_PER_SHARE_OPEN);

        $table = FRQ_P1_V1_DB::table_name();
        $wpdb->update(
            $table,
            ['gamification_score' => $score, 'updated_at' => current_time('mysql')],
            ['id' => $submission_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    private static function submission_score_by_token(string $submission_token): int
    {
        global $wpdb;
        if (! $submission_token) {
            return 0;
        }

        $table = FRQ_P1_V1_DB::table_name();
        $score = $wpdb->get_var($wpdb->prepare("SELECT gamification_score FROM {$table} WHERE submission_token = %s LIMIT 1", $submission_token));

        return max(0, (int) $score);
    }

    private static function send_mail_with_configured_sender(array $recipients, string $subject, string $message): void
    {
        $from_name = sanitize_text_field((string) get_option('frq_p1_v1_mail_from_name', ''));
        $from_email = sanitize_email((string) get_option('frq_p1_v1_mail_from_email', ''));

        $name_filter = null;
        $email_filter = null;

        if ($from_name) {
            $name_filter = static function () use ($from_name) {
                return $from_name;
            };
            add_filter('wp_mail_from_name', $name_filter);
        }

        if ($from_email) {
            $email_filter = static function () use ($from_email) {
                return $from_email;
            };
            add_filter('wp_mail_from', $email_filter);
        }

        wp_mail($recipients, $subject, $message);

        if ($name_filter) {
            remove_filter('wp_mail_from_name', $name_filter);
        }
        if ($email_filter) {
            remove_filter('wp_mail_from', $email_filter);
        }
    }
}
