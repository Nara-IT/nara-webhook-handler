<?php
/**
 * Admin Settings Page for Tally Webhook Emailer
 *
 * Options:
 * - twe_tally_signing_secret (string)
 * - twe_admin_emails (string)
 * - twe_require_signature (0/1)
 * - twe_debug_logging (0/1)
 */

if (!defined('ABSPATH')) exit;

class TWE_Settings {
	const OPTION_SECRET  = 'twe_tally_signing_secret';
	const OPTION_ADMINS  = 'twe_admin_emails';
	const OPTION_REQUIRE = 'twe_require_signature';
	const OPTION_DEBUG   = 'twe_debug_logging';

	const PAGE_SLUG      = 'twe-settings';
	const GROUP          = 'twe_settings_group';

	const AJAX_TEST      = 'twe_send_test_email';
	const AJAX_CLEARLOGS = 'twe_clear_logs';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'add_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);

		// AJAX handlers (admin only)
		add_action('wp_ajax_' . self::AJAX_TEST, [__CLASS__, 'ajax_send_test_email']);
		add_action('wp_ajax_' . self::AJAX_CLEARLOGS, [__CLASS__, 'ajax_clear_logs']);
	}

	public static function add_menu(): void {
		add_options_page(
			'Tally Webhook Emailer',
			'Tally Webhook',
			'manage_options',
			self::PAGE_SLUG,
			[__CLASS__, 'render_page']
		);
	}

	public static function register_settings(): void {
		register_setting(self::GROUP, self::OPTION_SECRET, [
			'type'              => 'string',
			'sanitize_callback' => fn($v) => trim((string)$v),
			'default'           => '',
		]);

		register_setting(self::GROUP, self::OPTION_ADMINS, [
			'type'              => 'string',
			'sanitize_callback' => [__CLASS__, 'sanitize_admin_emails'],
			'default'           => get_option('admin_email'),
		]);

		register_setting(self::GROUP, self::OPTION_REQUIRE, [
			'type'              => 'boolean',
			'sanitize_callback' => fn($v) => $v ? 1 : 0,
			'default'           => 1,
		]);

		register_setting(self::GROUP, self::OPTION_DEBUG, [
			'type'              => 'boolean',
			'sanitize_callback' => fn($v) => $v ? 1 : 0,
			'default'           => 0,
		]);
	}

	public static function sanitize_admin_emails($value): string {
		$parts = preg_split('/[,\n;\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
		$emails = [];
		foreach ($parts as $p) {
			$e = sanitize_email($p);
			if ($e) $emails[] = $e;
		}
		$emails = array_values(array_unique($emails));
		if (!$emails) $emails[] = sanitize_email(get_option('admin_email'));
		return implode(', ', $emails);
	}

	private static function current_admin_emails(): array {
		$raw = (string)get_option(self::OPTION_ADMINS, get_option('admin_email'));
		$parts = preg_split('/[,\n;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
		$emails = [];
		foreach ($parts as $p) {
			$e = sanitize_email($p);
			if ($e) $emails[] = $e;
		}
		return array_values(array_unique($emails));
	}

	private static function logs_dir(): string {
		$uploads = wp_upload_dir();
		$dir = trailingslashit($uploads['basedir']) . 'tally-webhook-emailer';
		if (!is_dir($dir)) wp_mkdir_p($dir);
		return $dir;
	}

	private static function log_paths(): array {
		$dir = self::logs_dir();
		return [
			'incoming' => $dir . '/incoming.log',
			'outgoing' => $dir . '/outgoing.log',
		];
	}

	private static function tail_file(string $path, int $maxBytes = 60000): string {
		if (!file_exists($path)) return '';
		$size = filesize($path);
		if ($size === false || $size <= 0) return '';
		$fh = fopen($path, 'rb');
		if (!$fh) return '';
		$seek = max(0, $size - $maxBytes);
		fseek($fh, $seek);
		$data = stream_get_contents($fh);
		fclose($fh);
		return $data ?: '';
	}

	public static function ajax_send_test_email(): void {
		if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
		check_ajax_referer(self::AJAX_TEST);

		$admins = self::current_admin_emails();
		if (!$admins) wp_send_json_error(['message' => 'No admin emails configured'], 400);

		$subject = '[Tally Webhook] Test email';
		$html = '<p>This is a test email from <strong>Tally Webhook Emailer</strong>.</p>'
		        . '<p>If you received this, <code>wp_mail()</code> is working.</p>';

		$headers = ['Content-Type: text/html; charset=UTF-8'];
		$from = get_bloginfo('name') . ' <' . sanitize_email(get_option('admin_email')) . '>';
		$headers[] = 'From: ' . $from;

		$sent = wp_mail($admins, $subject, $html, $headers);
		if (!$sent) wp_send_json_error(['message' => 'wp_mail() failed. Check SMTP / mail setup.'], 500);

		wp_send_json_success(['message' => 'Test email sent to: ' . implode(', ', $admins)]);
	}

	public static function ajax_clear_logs(): void {
		if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
		check_ajax_referer(self::AJAX_CLEARLOGS);

		$paths = self::log_paths();
		foreach ($paths as $p) {
			if (file_exists($p)) @file_put_contents($p, '');
		}
		wp_send_json_success(['message' => 'Logs cleared']);
	}

	public static function render_page(): void {
		if (!current_user_can('manage_options')) return;

		$secret  = (string)get_option(self::OPTION_SECRET, '');
		$admins  = (string)get_option(self::OPTION_ADMINS, get_option('admin_email'));
		$require = (int)get_option(self::OPTION_REQUIRE, 1);
		$debug   = (int)get_option(self::OPTION_DEBUG, 0);

		$endpoint = esc_url(rest_url('nara/tally/v1/webhook'));
		$paths = self::log_paths();

		// show last chunk of logs (kept small)
		$incomingTail = self::tail_file($paths['incoming']);
		$outgoingTail = self::tail_file($paths['outgoing']);

		$nonceTest = wp_create_nonce(self::AJAX_TEST);
		$nonceClear = wp_create_nonce(self::AJAX_CLEARLOGS);

		?>
        <div class="wrap">
            <h1>Tally Webhook Emailer</h1>

            <p>Webhook endpoint: <code><?php echo $endpoint; ?></code></p>

            <form method="post" action="options.php">
				<?php settings_fields(self::GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ADMINS); ?>">Admin emails</label></th>
                        <td>
              <textarea
                      name="<?php echo esc_attr(self::OPTION_ADMINS); ?>"
                      id="<?php echo esc_attr(self::OPTION_ADMINS); ?>"
                      class="large-text"
                      rows="3"
              ><?php echo esc_textarea($admins); ?></textarea>
                            <p class="description">Comma / space / new line separated.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_SECRET); ?>">Tally signing secret</label></th>
                        <td>
                            <input
                                    type="password"
                                    name="<?php echo esc_attr(self::OPTION_SECRET); ?>"
                                    id="<?php echo esc_attr(self::OPTION_SECRET); ?>"
                                    class="regular-text"
                                    value="<?php echo esc_attr($secret); ?>"
                                    autocomplete="new-password"
                            />
                            <p class="description">Enable “Signing secret” in Tally → Webhooks and paste it here.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Require signature</th>
                        <td>
                            <label>
                                <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_REQUIRE); ?>"
                                        value="1"
									<?php checked(1, $require); ?>
                                />
                                Reject requests without a valid <code>Tally-Signature</code>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Debug logging</th>
                        <td>
                            <label>
                                <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(self::OPTION_DEBUG); ?>"
                                        value="1"
									<?php checked(1, $debug); ?>
                                />
                                Save incoming/outgoing payloads to log files (PII risk)
                            </label>
                            <p class="description">
                                Logs are stored in uploads: <code>.../uploads/tally-webhook-emailer/</code>
                            </p>
                        </td>
                    </tr>
                </table>

				<?php submit_button('Save settings'); ?>
            </form>

            <hr />

            <h2>Tools</h2>
            <p>
                <button class="button button-secondary" id="tweTestEmail">Send test email</button>
                <span id="tweTestEmailMsg" style="margin-left:10px;"></span>
            </p>

            <p>
                <button class="button button-secondary" id="tweClearLogs">Clear logs</button>
                <span id="tweClearLogsMsg" style="margin-left:10px;"></span>
            </p>

            <h2>Logs (tail)</h2>

            <h3>Incoming (latest)</h3>
            <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea($incomingTail); ?></textarea>

            <h3>Outgoing (latest)</h3>
            <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea($outgoingTail); ?></textarea>

            <script>
                (function(){
                    const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";

                    function post(action, nonce, onOk, onErr){
                        const fd = new FormData();
                        fd.append("action", action);
                        fd.append("_ajax_nonce", nonce);
                        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
                            .then(r => r.json())
                            .then(j => j.success ? onOk(j.data) : onErr(j.data))
                            .catch(e => onErr({message: e.message || "Request failed"}));
                    }

                    document.getElementById("tweTestEmail")?.addEventListener("click", function(e){
                        e.preventDefault();
                        const el = document.getElementById("tweTestEmailMsg");
                        el.textContent = "Sending...";
                        post("<?php echo esc_js(self::AJAX_TEST); ?>", "<?php echo esc_js($nonceTest); ?>",
                            (d)=>{ el.textContent = d.message || "Sent"; },
                            (d)=>{ el.textContent = (d && d.message) ? d.message : "Failed"; }
                        );
                    });

                    document.getElementById("tweClearLogs")?.addEventListener("click", function(e){
                        e.preventDefault();
                        const el = document.getElementById("tweClearLogsMsg");
                        el.textContent = "Clearing...";
                        post("<?php echo esc_js(self::AJAX_CLEARLOGS); ?>", "<?php echo esc_js($nonceClear); ?>",
                            (d)=>{ el.textContent = d.message || "Cleared"; location.reload(); },
                            (d)=>{ el.textContent = (d && d.message) ? d.message : "Failed"; }
                        );
                    });
                })();
            </script>

        </div>
		<?php
	}
}
