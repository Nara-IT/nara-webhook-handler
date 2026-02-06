<?php


if (!defined('ABSPATH')) exit;

class Tally_Webhook_Emailer {
	const OPTION_SECRET  = 'twe_tally_signing_secret';
	const TALLY_SECRET  = 'fcbb3114-25e9-4858-8d76-c0b1f2cb575e';
	const OPTION_ADMINS  = 'twe_admin_emails';
	const ADMIN_EMAILS  = 'kamal@nara.ae,kamalacca@gmail.com';// @TODO; only for testing personal email used, REMOVE IT AFTER TEST.
	const OPTION_REQUIRE = 'twe_require_signature';

	public static function init() {
		require_once __DIR__ . '/settings.php';
		TWE_Settings::init();

		add_action('rest_api_init', [__CLASS__, 'register_routes']);
		add_filter('wp_mail_content_type', [__CLASS__, 'force_html_mail_content_type']);
	}

	public static function register_routes() {
		// http://nara.ae/wp-json/nara/tally/v1/webhook
		register_rest_route('nara/tally/v1', '/webhook', [
			'methods'  => 'POST',
			'callback' => [__CLASS__, 'handle_webhook'],
			'permission_callback' => '__return_true', // public endpoint - we protect via signature secret
		]);
	}

	public static function force_html_mail_content_type($content_type) {
		// Ensure HTML formatting works in wp_mail()
		return 'text/html; charset=UTF-8';
	}

	private static function get_option_admin_emails(): array {
		$raw = get_option(self::OPTION_ADMINS, get_option('admin_email'));
		// Accept comma/semicolon/newline separated
		$parts = preg_split('/[,\n;\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
		$emails = [];
		foreach ($parts as $p) {
			$e = sanitize_email($p);
			if ($e) $emails[] = $e;
		}
		return array_values(array_unique($emails));
	}

	private static function get_signing_secret(): string {
		return (string)get_option(self::OPTION_SECRET, '');
	}

	private static function require_signature(): bool {
		return (bool)get_option(self::OPTION_REQUIRE, 1);
	}

	private static function get_raw_body(): string {
		// WP REST usually parses JSON, but signature verification needs raw body.
		$raw = file_get_contents('php://input');
		return $raw === false ? '' : $raw;
	}

	private static function verify_tally_signature(string $rawBody, string $secret, ?string $receivedSignature): bool {
		if (!$secret) return false;
		if (!$receivedSignature) return false;

		// Tally signature = base64(HMAC_SHA256(rawBody, secret))
		$calculated = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
		return hash_equals($calculated, $receivedSignature);
	}

	private static function e(string $s): string {
		return esc_html($s);
	}

	private static function is_assoc(array $arr): bool {
		return array_keys($arr) !== range(0, count($arr) - 1);
	}

	private static function looks_like_url(string $s): bool {
		return (bool)preg_match('~^https?://~i', $s);
	}

	private static function format_scalar_html($value): string {
		if ($value === null) return '<em>(empty)</em>';
		if (is_bool($value)) return $value ? 'Yes' : 'No';
		if (is_int($value) || is_float($value)) return self::e((string)$value);

		$str = (string)$value;
		if ($str === '') return '<em>(empty)</em>';

		if (self::looks_like_url($str)) {
			$u = esc_url($str);
			$label = self::e($str);
			return "<a href=\"{$u}\" target=\"_blank\" rel=\"noopener noreferrer\">{$label}</a>";
		}

		return nl2br(self::e($str));
	}

	/**
	 * Format a single Tally field object.
	 */
	private static function format_field_value_html(array $field): string {
		$type  = (string)($field['type'] ?? '');
		$value = $field['value'] ?? null;

		// File uploads or signatures often come as arrays of objects with url/name
		if (is_array($value) && in_array($type, ['FILE_UPLOAD', 'SIGNATURE'], true)) {
			if ($value === []) return '<em>(no files)</em>';
			$items = [];
			foreach ($value as $file) {
				if (!is_array($file)) continue;
				$name = self::e((string)($file['name'] ?? 'file'));
				$url  = (string)($file['url'] ?? '');
				$size = isset($file['size']) ? (' (' . self::e((string)$file['size']) . ' bytes)') : '';
				if ($url) {
					$u = esc_url($url);
					$items[] = "<a href=\"{$u}\" target=\"_blank\" rel=\"noopener noreferrer\">{$name}</a>{$size}";
				} else {
					$items[] = "{$name}{$size}";
				}
			}
			return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
		}

		// Choice-like fields may store array of option IDs; options maps id -> text
		if (is_array($value) && in_array($type, ['MULTIPLE_CHOICE', 'CHECKBOXES', 'DROPDOWN', 'MULTI_SELECT', 'RANKING'], true)) {
			$options = $field['options'] ?? [];
			$map = [];
			if (is_array($options)) {
				foreach ($options as $opt) {
					if (is_array($opt) && isset($opt['id'], $opt['text'])) {
						$map[(string)$opt['id']] = (string)$opt['text'];
					}
				}
			}

			$labels = [];
			foreach ($value as $id) {
				$idStr = (string)$id;
				$labels[] = self::e($map[$idStr] ?? $idStr);
			}

			if ($type === 'RANKING') {
				return '<ol><li>' . implode('</li><li>', $labels) . '</li></ol>';
			}
			return $labels ? implode(', ', $labels) : '<em>(none selected)</em>';
		}

		// Matrix: value rowId => [colId...], with rows/columns definitions
		if ($type === 'MATRIX' && is_array($value)) {
			$rows = $field['rows'] ?? [];
			$cols = $field['columns'] ?? [];
			$rowMap = [];
			$colMap = [];

			if (is_array($rows)) foreach ($rows as $r) if (is_array($r) && isset($r['id'], $r['text'])) $rowMap[(string)$r['id']] = (string)$r['text'];
			if (is_array($cols)) foreach ($cols as $c) if (is_array($c) && isset($c['id'], $c['text'])) $colMap[(string)$c['id']] = (string)$c['text'];

			$out = [];
			foreach ($value as $rowId => $colIds) {
				$rowLabel = self::e($rowMap[(string)$rowId] ?? (string)$rowId);
				$chosen = [];
				if (is_array($colIds)) {
					foreach ($colIds as $cid) $chosen[] = self::e($colMap[(string)$cid] ?? (string)$cid);
				}
				$out[] = "<strong>{$rowLabel}:</strong> " . ($chosen ? implode(', ', $chosen) : '<em>(none)</em>');
			}
			return $out ? implode('<br>', $out) : '<em>(empty)</em>';
		}

		// Generic arrays/objects: render safely as list/table
		if (is_array($value)) {
			if ($value === []) return '<em>(empty)</em>';

			if (!self::is_assoc($value)) {
				$items = array_map(fn($v) => '<li>' . self::format_scalar_html($v) . '</li>', $value);
				return '<ul>' . implode('', $items) . '</ul>';
			}

			$rows = [];
			foreach ($value as $k => $v) {
				$rows[] =
					'<tr>' .
					'<td style="padding:6px 10px;border:1px solid #eee;background:#fafafa;"><code>' . self::e((string)$k) . '</code></td>' .
					'<td style="padding:6px 10px;border:1px solid #eee;">' . self::format_scalar_html($v) . '</td>' .
					'</tr>';
			}
			return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . implode('', $rows) . '</table>';
		}

		return self::format_scalar_html($value);
	}

	private static function build_email_simple(array $payload): array {
		$data = $payload['data'] ?? [];
		$formName = (string)($data['formName'] ?? 'Tally Form');
		$formId   = (string)($data['formId'] ?? '');
		$createdAt = (string)($data['createdAt'] ?? ($payload['createdAt'] ?? ''));
		$submissionId = (string)($data['submissionId'] ?? ($data['responseId'] ?? ''));

		$fields = $data['fields'] ?? [];
		if (!is_array($fields)) $fields = [];

		$meta = [];
		$meta[] = ['Form', $formName];
		if ($formId) $meta[] = ['Form ID', $formId];
		if ($submissionId) $meta[] = ['Submission ID', $submissionId];
		if ($createdAt) $meta[] = ['Submitted At', $createdAt];

		$metaRows = '';
		foreach ($meta as [$k, $v]) {
			$metaRows .= '<tr>'
			             . '<td style="padding:8px 10px;border:1px solid #eee;background:#fafafa;width:180px;"><strong>' . self::e($k) . '</strong></td>'
			             . '<td style="padding:8px 10px;border:1px solid #eee;">' . self::e($v) . '</td>'
			             . '</tr>';
		}

		$answerRows = '';
		foreach ($fields as $f) {
			if (!is_array($f)) continue;
			$label = (string)($f['label'] ?? $f['key'] ?? 'Field');
			$type  = (string)($f['type'] ?? '');
			$valueHtml = self::format_field_value_html($f);

			$answerRows .= '<tr>'
			               . '<td style="padding:10px;border:1px solid #eee;vertical-align:top;width:240px;">'
			               . '<div style="font-weight:700;">' . self::e($label) . '</div>'
			               . ($type ? '<div style="color:#666;font-size:12px;margin-top:4px;">' . self::e($type) . '</div>' : '')
			               . '</td>'
			               . '<td style="padding:10px;border:1px solid #eee;vertical-align:top;">' . $valueHtml . '</td>'
			               . '</tr>';
		}
		if ($answerRows === '') {
			$answerRows = '<tr><td style="padding:10px;border:1px solid #eee;" colspan="2"><em>No fields found.</em></td></tr>';
		}

		$html =
			'<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.4;">'
			. '<h2 style="margin:0 0 12px 0;">' . self::e($formName) . ' — New submission</h2>'
			. '<h3 style="margin:18px 0 8px 0;font-size:16px;">Submission details</h3>'
			. '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:900px;">'
			. $metaRows
			. '</table>'
			. '<h3 style="margin:18px 0 8px 0;font-size:16px;">Answers</h3>'
			. '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:900px;">'
			. $answerRows
			. '</table>'
			. '</body></html>';

		$subject = '[Tally Feedback] ' . $formName . ($submissionId ? " (#{$submissionId})" : '');

		// Plaintext fallback
		$text = $formName . " — New submission\n";
		foreach ($meta as [$k, $v]) $text .= "{$k}: {$v}\n";
		$text .= "\nAnswers:\n";
		foreach ($fields as $f) {
			if (!is_array($f)) continue;
			$label = (string)($f['label'] ?? $f['key'] ?? 'Field');
			$val   = $f['value'] ?? null;
			$text .= "- {$label}: " . (is_scalar($val) || $val === null ? (string)$val : wp_json_encode($val)) . "\n";
		}

		return [$subject, $html, $text];
	}

	private static function build_email(array $payload): array {
		$data = $payload['data'] ?? [];
		$formName = (string)($data['formName'] ?? 'Tally Form');
		$formId   = (string)($data['formId'] ?? '');
		$createdAtRaw = (string)($data['createdAt'] ?? ($payload['createdAt'] ?? ''));
		$createdAt = self::format_wp_datetime($createdAtRaw);

		$submissionId = (string)($data['submissionId'] ?? ($data['responseId'] ?? ''));
		$respondentId = (string)($data['respondentId'] ?? '');

		$fields = $data['fields'] ?? [];
		if (!is_array($fields)) $fields = [];

		// --- Meta rows ---
		$meta = [];
		$meta[] = ['Form', $formName];
		//if ($formId) $meta[] = ['Form ID', $formId];
		//if ($submissionId) $meta[] = ['Submission ID', $submissionId];
		//if ($respondentId) $meta[] = ['Respondent ID', $respondentId];
		if ($createdAt) $meta[] = ['Submitted At', $createdAt];
		//if (!empty($payload['eventId']))   $meta[] = ['Event ID', (string)$payload['eventId']];
		//if (!empty($payload['eventType'])) $meta[] = ['Event Type', (string)$payload['eventType']];

		// --- Inline styles (email safe) - Nara Premium Brand ---
		$styles = [
			'body' => 'margin:0;padding:0;background:#fdf6eb;font-family:Georgia,Garamond,"Times New Roman",serif;color:#4a3226;line-height:1.6;',
			'wrap' => 'max-width:720px;margin:0 auto;padding:30px 20px;',
			'card' => 'background:#ffffff;border:2px solid #b39c76;box-shadow:0 8px 24px rgba(74,50,38,0.08);overflow:hidden;',
			'head' => 'padding:32px 28px 28px;background:linear-gradient(135deg, #4a3226 0%, #5d4434 100%);color:#fdf6eb;position:relative;',
			'title'=> 'margin:0;font-size:24px;line-height:1.3;font-weight:400;letter-spacing:0.5px;color:#fdf6eb;',
			'sub'  => 'margin:8px 0 0 0;font-size:13px;opacity:.82;letter-spacing:0.8px;text-transform:uppercase;color:#b39c76;font-family:Arial,Helvetica,sans-serif;',
			'sec'  => 'padding:24px 28px;border-top:1px solid #b39c7633;',
			'h2'   => 'margin:0 0 16px 0;font-size:12px;color:#ae6c4a;text-transform:uppercase;letter-spacing:1.2px;font-weight:600;font-family:Arial,Helvetica,sans-serif;',
			'metaT'=> 'width:100%;border-collapse:collapse;',
			'metaK'=> 'padding:12px 14px;border:1px solid #b39c7622;background:#fdf6eb;width:180px;font-weight:600;font-size:13px;color:#4a3226;font-family:Arial,Helvetica,sans-serif;',
			'metaV'=> 'padding:12px 14px;border:1px solid #b39c7622;font-size:14px;color:#4a3226;',
			'tbl'  => 'width:100%;border-collapse:separate;border-spacing:0;',
			'q'    => 'padding:16px 14px;border-top:1px solid #b39c7622;vertical-align:top;width:240px;background:#fdf6eb;',
			'a'    => 'padding:16px 14px;border-top:1px solid #b39c7622;vertical-align:top;',
			'label'=> 'font-weight:600;font-size:14px;color:#4a3226;margin:0;font-family:Arial,Helvetica,sans-serif;',
			'pill' => 'display:inline-block;margin-top:8px;padding:4px 12px;border-radius:3px;background:#b39c7620;color:#ae6c4a;font-size:11px;letter-spacing:0.5px;text-transform:uppercase;font-family:Arial,Helvetica,sans-serif;font-weight:500;',
			'empty'=> 'color:#8b7355;font-style:italic;',
			'foot' => 'padding:20px 28px;background:#fdf6eb;border-top:2px solid #b39c76;font-size:12px;color:#8b7355;text-align:center;',
			'chip' => 'display:inline-block;padding:6px 14px;border-radius:3px;background:#ffffff15;border:1px solid #ffffff25;color:#fdf6eb;font-size:12px;margin:0 8px 8px 0;font-family:Arial,Helvetica,sans-serif;font-weight:500;',
			'muted'=> 'color:#8b7355;font-size:12px;font-style:italic;',
			'logo' => 'margin:0 0 12px 0;',
			'accent'=> 'width:100%;height:3px;background:linear-gradient(90deg, #b39c76 0%, #ae6c4a 100%);margin:0;',
		];

		// --- Helper: skip noisy per-option checkbox booleans like "Checkboxes (Soccer)" ---
		$shouldSkipField = function(array $f): bool {
			$type  = (string)($f['type'] ?? '');
			$label = (string)($f['label'] ?? '');
			$value = $f['value'] ?? null;

			if ($type === 'CHECKBOXES' && is_bool($value)) {
				// label ends with "(Option)"
				if (preg_match('/\(.+\)\s*$/', $label)) return true;
			}
			return false;
		};

		// --- Meta HTML ---
		$metaRows = '';
		foreach ($meta as [$k, $v]) {
			$metaRows .= '<tr>'
			             . '<td style="'.$styles['metaK'].'"><strong>' . self::e((string)$k) . '</strong></td>'
			             . '<td style="'.$styles['metaV'].'">' . self::e((string)$v) . '</td>'
			             . '</tr>';
		}

		// --- Optional: quick "highlights" row (makes emails scan-friendly) ---
		// We try to pull a few common fields if present (Rating, Email, Phone).
		$highlights = [];
		foreach ($fields as $f) {
			if (!is_array($f)) continue;
			$label = (string)($f['label'] ?? '');
			$type  = (string)($f['type'] ?? '');
			$val   = $f['value'] ?? null;

			if ($label && in_array($type, ['INPUT_EMAIL','INPUT_PHONE_NUMBER','RATING','LINEAR_SCALE'], true)) {
				if ($val !== null && $val !== '' && !is_array($val)) {
					$highlights[] = self::e($label) . ': <strong>' . self::e((string)$val) . '</strong>';
				}
			}
			if (count($highlights) >= 4) break;
		}

		$highlightsHtml = '';
		if ($highlights) {
			$chips = [];
			foreach ($highlights as $h) {
				$chips[] = '<span style="'.$styles['chip'].'">'.$h.'</span>';
			}
			$highlightsHtml =
				'<div style="margin-top:10px;">' . implode(' ', $chips) . '</div>';
		}

		// --- Answers HTML ---
		$answerRows = '';
		foreach ($fields as $f) {
			if (!is_array($f)) continue;
			if ($shouldSkipField($f)) continue;

			$label = (string)($f['label'] ?? $f['key'] ?? 'Field');
			$type  = (string)($f['type'] ?? '');

			// Your existing formatter already covers:
			// - INPUT_* scalars
			// - MULTIPLE_CHOICE/CHECKBOXES/DROPDOWN/MULTI_SELECT/RANKING with options mapping
			// - FILE_UPLOAD/SIGNATURE arrays
			// - MATRIX with rows/columns mapping
			// - generic arrays/objects
			$valueHtml = self::format_field_value_html($f);

			// If your formatter returns empty, make it obvious
			if ($valueHtml === '' || $valueHtml === null) {
				$valueHtml = '<span style="'.$styles['empty'].'">(empty)</span>';
			}

			$answerRows .= '<tr>'
			               . '<td style="'.$styles['q'].'">'
			               .   '<div style="'.$styles['label'].'">' . self::e($label) . '</div>'
			               . '</td>'
			               . '<td style="'.$styles['a'].'">' . $valueHtml . '</td>'
			               . '</tr>';
		}

		if ($answerRows === '') {
			$answerRows = '<tr><td colspan="2" style="padding:14px;border-top:1px solid #b39c7622;"><span style="'.$styles['empty'].'">No fields found.</span></td></tr>';
		}

		//$siteName = (string) get_bloginfo('name');
		//$siteUrl = (string) get_bloginfo('url');
		$logoUrl = 'https://www.nara.ae/wp-content/uploads/2025/12/NARA-LOGO-WHITE.png';

		$html =
			'<!doctype html><html><head><meta charset="utf-8"></head>'
			. '<body style="'.$styles['body'].'">'
			.   '<div style="'.$styles['wrap'].'">'
			.     '<div style="'.$styles['card'].'">'
			.       '<div style="'.$styles['accent'].'"></div>'
			.       '<div style="'.$styles['head'].'">'
			.         '<div style="'.$styles['logo'].'">'
			.           '<img src="' . esc_url($logoUrl) . '" alt="Nara" width="120" style="display:block;height:auto;" />'
			.         '</div>'
			.         '<h1 style="'.$styles['title'].'">' . self::e($formName) . '</h1>'
			.         '<div style="'.$styles['sub'].'">New Submission Received</div>'
			.         $highlightsHtml
			.       '</div>'

			.       '<div style="'.$styles['sec'].'">'
			.         '<div style="'.$styles['h2'].'">Submission Details</div>'
			.         '<table style="'.$styles['metaT'].'" cellpadding="0" cellspacing="0">' . $metaRows . '</table>'
			.       '</div>'

			.       '<div style="'.$styles['sec'].'">'
			.         '<div style="'.$styles['h2'].'">Guest Responses</div>'
			.         '<table style="'.$styles['tbl'].'" cellpadding="0" cellspacing="0">' . $answerRows . '</table>'
			.         '<div style="'.$styles['muted'].';margin-top:16px;">'
			.           'This is an automated notification. Please do not reply to this email.'
			.         '</div>'
			.       '</div>'

			.       '<div style="'.$styles['foot'].'">'
			.         '<div style="margin-bottom:8px;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">NARA HOSPITALITY</div>'
			.         '<div style="font-size:11px;color:#ae6c4a;">Transform Moments Into Signature Memories</div>'
			.       '</div>'
			.     '</div>'
			.   '</div>'
			. '</body></html>';

		$subject = '[Sonara Feedback] ' . $formName . ($submissionId ? " (#{$submissionId})" : '');

		// --- Plaintext fallback (keep yours, but skip the noisy per-option checkbox entries too) ---
		$text = $formName . " — New submission\n";
		foreach ($meta as [$k, $v]) $text .= "{$k}: {$v}\n";
		$text .= "\nAnswers:\n";
		foreach ($fields as $f) {
			if (!is_array($f)) continue;
			if ($shouldSkipField($f)) continue;

			$label = (string)($f['label'] ?? $f['key'] ?? 'Field');
			$val   = $f['value'] ?? null;
			$text .= "- {$label}: " . (is_scalar($val) || $val === null ? (string)$val : wp_json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		}

		return [$subject, $html, $text];
	}


	public static function handle_webhook(WP_REST_Request $request) {
		$raw = self::get_raw_body();
		// -- Log starts
		self::log_write('incoming', "RAW BODY:\n" . $raw);
		// -- Log Ends
		if ($raw === '') return new WP_REST_Response(['ok' => false, 'error' => 'Empty body'], 400);

		// Signature verification (recommended)
		$secret = self::get_signing_secret();
		$require = self::require_signature();
		$sig = $request->get_header('tally-signature'); // header names are case-insensitive in WP

		if ($require) {
			if (!$secret) return new WP_REST_Response(['ok' => false, 'error' => 'Server missing signing secret'], 500);
			if (!self::verify_tally_signature($raw, $secret, $sig)) {
				return new WP_REST_Response(['ok' => false, 'error' => 'Invalid signature'], 401);
			}
		}

		$payload = json_decode($raw, true);
		if (!is_array($payload)) return new WP_REST_Response(['ok' => false, 'error' => 'Invalid JSON'], 400);

		// Optional: accept only form responses
		$eventType = (string)($payload['eventType'] ?? '');
		if ($eventType !== '' && $eventType !== 'FORM_RESPONSE') {
			return new WP_REST_Response(['ok' => true, 'ignored' => true, 'eventType' => $eventType], 200);
		}

		[$subject, $html, $text] = self::build_email($payload);
		$admins = self::get_option_admin_emails();

		// ----- log start
		// Be careful not to bloat logs. Store a short summary + first part of email.
		$summary = [
			'to' => $admins,
			'subject' => $subject,
			'html_preview' => mb_substr(wp_strip_all_tags($html), 0, 2000),
			'text_preview' => mb_substr($text, 0, 2000),
		];
		self::log_write('outgoing', "EMAIL SUMMARY:\n" . wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		// --- log ends

		if (!$admins) return new WP_REST_Response(['ok' => false, 'error' => 'No admin emails configured'], 500);

		// wp_mail headers
		$headers = [];
		$headers[] = 'Content-Type: text/html; charset=UTF-8';

		// Use site "from" if you want:
		$from = get_bloginfo('name') . ' <' . sanitize_email(get_option('admin_email')) . '>';
		$headers[] = 'From: ' . $from;

		$sent = wp_mail($admins, $subject, $html, $headers);

		if (!$sent) {
			return new WP_REST_Response(['ok' => false, 'error' => 'Failed to send email'], 500);
		}

		return new WP_REST_Response(['ok' => true], 200);
	}
	private static function debug_enabled(): bool {
		return (bool) get_option('twe_debug_logging', 0);
	}

	private static function logs_dir(): string {
		$uploads = wp_upload_dir();
		$dir = trailingslashit($uploads['basedir']) . 'tally-webhook-emailer';
		if (!is_dir($dir)) wp_mkdir_p($dir);
		return $dir;
	}

	private static function log_write(string $which, string $message): void {
		if (!self::debug_enabled()) return;

		$dir = self::logs_dir();
		$path = $dir . '/' . ($which === 'outgoing' ? 'outgoing.log' : 'incoming.log');

		// add timestamp + ip (helpful)
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$line = "---- " . gmdate('c') . " UTC | IP: {$ip} ----\n" . $message . "\n\n";

		// best-effort, do not break webhook flow
		@file_put_contents($path, $line, FILE_APPEND);
	}

	private static function format_wp_datetime(?string $iso): string {
		if (!$iso) return '';

		try {
			// Parse ISO 8601
			$dt = new DateTime($iso, new DateTimeZone('UTC'));

			// Convert to WP timezone
			$tz = wp_timezone();
			$dt->setTimezone($tz);

			// Use WP date + time format (AM/PM aware)
			return wp_date(
				get_option('date_format') . ' ' . get_option('time_format'),
				$dt->getTimestamp()
			);
		} catch (Exception $e) {
			return $iso; // fallback
		}
	}


}

Tally_Webhook_Emailer::init();