<?php
/**
 * کاشی — سیستم آپدیت اختصاصی
 *
 * دو نقش دارد:
 *  ۱) کلاینت: روی هر سایت، نسخه جدید را از «آدرس آپدیت» می‌خواند و در صفحه افزونه‌ها
 *     دکمه «به‌روزرسانی» نشان می‌دهد (مثل افزونه‌های wordpress.org). فایل دانلودشده با
 *     SHA-256 بررسی می‌شود تا دست‌کاری‌شده یا ناقص نصب نشود.
 *  ۲) سرور (اختیاری): یکی از سایت‌های خودتان را «سرور آپدیت» می‌کنید؛ فقط فایل zip نسخه
 *     جدید را در رسانه انتخاب می‌کنید و همه سایت‌ها آپدیت را می‌بینند.
 *
 * @package Kashi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kashi_Updater {

	const SLUG        = 'kashi';
	const HOST        = 'kashi.update';           // باید با هدر Update URI یکی باشد
	const OPT         = 'kashi_update_settings';
	const OPT_RELEASE = 'kashi_server_release';
	const CACHE       = 'kashi_update_manifest';
	const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL    = 2 * HOUR_IN_SECONDS;      // در قطعی اینترنت، هر بار پیشخوان معطل نشود
	const TIMEOUT     = 5;
	const GH_REPO     = 'KaroRostampour/kashi';

	/** @var string plugin_basename فایل اصلی، مثل kashi/kashi.php */
	private static $basename = '';
	private static $version  = '';
	private static $inline   = false;

	public static function init( $plugin_file, $version ) {
		self::$basename = plugin_basename( $plugin_file );
		self::$version  = $version;

		// کلاینت
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'check_update' ), 10, 4 );
		// وردپرس فیلتر بالا را فقط وقتی صدا می‌زند که به api.wordpress.org وصل شود؛ در ایران
		// (و در حالت اینترنت ملی) این اتصال قطع است و آپدیت هیچ‌وقت دیده نمی‌شد. پس خودمان
		// نسخه جدید را مستقیم در فهرست آپدیت‌های وردپرس می‌گذاریم.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ), 20 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_kashi_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ), 10, 0 );

		// سرور
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

		// تنظیمات
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_kashi_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_kashi_gen_keys', array( __CLASS__, 'generate_keys' ) );
		add_action( 'admin_post_kashi_offline_update', array( __CLASS__, 'offline_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ================================================================== *
	 * تنظیمات
	 * ================================================================== */
	public static function settings() {
		$s = get_option( self::OPT, array() );
		$s = wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'source'        => 'github', // github | server
				'gh_repo'       => self::GH_REPO,
				'gh_token'      => '',
				'url'           => '',
				'key'           => '',
				'server'        => false,
				'server_key'    => '',
				'pubkey'        => '',   // کلاینت: کلید عمومی امضا (پین‌شده)
				'sign_pub'      => '',   // سرور: کلید عمومی جفت‌کلید این سرور
				'last_check'    => 0,
				'last_status'   => '',
			)
		);
		if ( defined( 'KASHI_UPDATE_URL' ) && KASHI_UPDATE_URL ) {
			$s['url'] = (string) KASHI_UPDATE_URL;
		}
		if ( defined( 'KASHI_UPDATE_KEY' ) && KASHI_UPDATE_KEY ) {
			$s['key'] = (string) KASHI_UPDATE_KEY;
		}
		if ( defined( 'KASHI_UPDATE_PUBKEY' ) && KASHI_UPDATE_PUBKEY ) {
			$s['pubkey'] = (string) KASHI_UPDATE_PUBKEY;
		}
		if ( defined( 'KASHI_GITHUB_TOKEN' ) && KASHI_GITHUB_TOKEN ) {
			$s['gh_token'] = (string) KASHI_GITHUB_TOKEN;
		}
		$s['source']  = 'server' === $s['source'] ? 'server' : 'github';
		$s['gh_repo'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $s['gh_repo'] ) ? $s['gh_repo'] : self::GH_REPO;
		return $s;
	}

	private static function set_status( $status ) {
		$s                = get_option( self::OPT, array() );
		$s                = is_array( $s ) ? $s : array();
		$s['last_check']  = time();
		$s['last_status'] = $status;
		update_option( self::OPT, $s, false );
	}

	/* ================================================================== *
	 * امضای دیجیتال (Ed25519)
	 * پیام امضاشده: «kashi|نسخه|sha256 فایل». کلید خصوصی هیچ‌جا ذخیره نمی‌شود؛
	 * کلاینت‌ها فقط کلید عمومی پین‌شده را دارند. پس حتی با هک کامل سایت سرور،
	 * بدون کلید خصوصی نمی‌توان نسخه مخرب منتشر کرد.
	 * ================================================================== */
	private static function sign_message( $version, $sha ) {
		return 'kashi|' . trim( (string) $version ) . '|' . strtolower( trim( (string) $sha ) );
	}

	private static function b64( $s ) {
		$d = base64_decode( trim( (string) $s ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $d ? '' : $d;
	}

	/** کلید عمومی معتبر (۳۲ بایت) یا رشته خالی */
	public static function pinned_key() {
		$s  = self::settings();
		$pk = self::b64( $s['pubkey'] );
		return 32 === strlen( $pk ) ? $pk : '';
	}

	/** امضای یک manifest با کلید پین‌شده؛ اگر کلیدی پین نشده، true */
	private static function signature_ok( $m ) {
		$pk = self::pinned_key();
		if ( '' === $pk ) {
			return true;
		}
		$sig = self::b64( isset( $m['signature'] ) ? $m['signature'] : '' );
		if ( 64 !== strlen( $sig ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, self::sign_message( $m['version'], $m['sha256'] ), $pk );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * جفت‌کلید جدید: کلید عمومی ذخیره می‌شود، کلید خصوصی فقط برگردانده می‌شود (ذخیره نمی‌شود).
	 * @return array array( public_b64, secret_b64 )
	 */
	public static function create_keypair() {
		$kp  = sodium_crypto_sign_keypair();
		$sk  = base64_encode( sodium_crypto_sign_secretkey( $kp ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$pk  = base64_encode( sodium_crypto_sign_publickey( $kp ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$opt = get_option( self::OPT, array() );
		$opt = is_array( $opt ) ? $opt : array();
		$opt['sign_pub'] = $pk;
		update_option( self::OPT, $opt, false );
		$rel = get_option( self::OPT_RELEASE, array() );
		if ( is_array( $rel ) ) {
			unset( $rel['signature'], $rel['signed_for'] ); // امضاهای قبلی با کلید جدید نمی‌خوانند
			update_option( self::OPT_RELEASE, $rel, false );
		}
		return array( $pk, $sk );
	}

	/** سرور: ساخت جفت‌کلید؛ کلید خصوصی فقط یک بار دانلود می‌شود */
	public static function generate_keys() {
		check_admin_referer( 'kashi_gen_keys' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			wp_die( 'این سرور از امضای دیجیتال (sodium) پشتیبانی نمی‌کند.' );
		}
		list( $pk, $sk ) = self::create_keypair();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kashi-signing-key-' . gmdate( 'Ymd' ) . '.txt"' );
		echo "KASHI SIGNING KEY — کلید خصوصی امضای آپدیت کاشی\n";
		echo "==================================================\n\n";
		echo "کلید خصوصی (محرمانه — به هیچ‌کس ندهید، در سایت ذخیره نکنید):\n" . $sk . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "کلید عمومی (این را در «کلید عمومی امضا» همه سایت‌ها بگذارید):\n" . $pk . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "هر بار که نسخه جدیدی منتشر می‌کنید، کلید خصوصی را در فیلد «کلید خصوصی امضا» بچسبانید.\n";
		echo "این فایل را در جای امن (مثلاً مدیر رمز عبور) نگه دارید. اگر گم شود، کلید جدید بسازید\n";
		echo "و کلید عمومی جدید را در همه سایت‌ها عوض کنید.\n";
		exit;
	}

	/** آدرس manifest این سایت وقتی سرور آپدیت است */
	public static function server_url() {
		return rest_url( 'kashi/v1/update' );
	}

	/* ================================================================== *
	 * کلاینت: خواندن manifest
	 * ================================================================== */
	private static function is_safe_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' === $scheme ) {
			return true;
		}
		// http فقط اگر صراحتاً اجازه داده شود (مثلاً سرور داخلی)
		return 'http' === $scheme && defined( 'KASHI_UPDATE_ALLOW_HTTP' ) && KASHI_UPDATE_ALLOW_HTTP;
	}

	/**
	 * @param bool $force بدون کش
	 * @return array|null
	 */
	public static function manifest( $force = false ) {
		$s = self::settings();
		if ( 'github' === $s['source'] ) {
			return self::manifest_github( $force, $s );
		}
		if ( '' === $s['url'] || ! self::is_safe_url( $s['url'] ) ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return isset( $cached['version'] ) ? $cached : null;
			}
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $s['key'] ) {
			$headers['X-Kashi-Key'] = $s['key']; // در هدر، نه در آدرس (تا در لاگ سرورها ثبت نشود)
		}
		$res = wp_safe_remote_get(
			$s['url'],
			array(
				'timeout' => (int) apply_filters( 'kashi_update_timeout', self::TIMEOUT ),
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $res ) ) {
			self::fail( 'اتصال به سرور آپدیت ممکن نشد: ' . $res->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			self::fail( 403 === $code ? 'کلید دسترسی آپدیت اشتباه است.' : 'سرور آپدیت پاسخ معتبر نداد (کد ' . $code . ').' );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			self::fail( 'فایل آپدیت (manifest) نامعتبر است.' );
			return null;
		}
		if ( ! self::is_safe_url( $data['package'] ) ) {
			self::fail( 'آدرس فایل نسخه جدید باید https باشد.' );
			return null;
		}
		if ( empty( $data['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $data['sha256'] ) ) {
			self::fail( 'فایل آپدیت امضای SHA-256 ندارد؛ برای امنیت نصب نمی‌شود.' );
			return null;
		}

		$m = array(
			'version'      => sanitize_text_field( $data['version'] ),
			'package'      => esc_url_raw( $data['package'] ),
			'sha256'       => isset( $data['sha256'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', $data['sha256'] ) ) : '',
			'requires'     => isset( $data['requires'] ) ? sanitize_text_field( $data['requires'] ) : '',
			'requires_php' => isset( $data['requires_php'] ) ? sanitize_text_field( $data['requires_php'] ) : '',
			'tested'       => isset( $data['tested'] ) ? sanitize_text_field( $data['tested'] ) : '',
			'changelog'    => isset( $data['changelog'] ) ? wp_kses_post( $data['changelog'] ) : '',
			'last_updated' => isset( $data['last_updated'] ) ? sanitize_text_field( $data['last_updated'] ) : '',
			'homepage'     => isset( $data['homepage'] ) ? esc_url_raw( $data['homepage'] ) : '',
			'signature'    => isset( $data['signature'] ) ? preg_replace( '#[^A-Za-z0-9+/=]#', '', (string) $data['signature'] ) : '',
		);
		if ( ! self::signature_ok( $m ) ) {
			self::fail( 'امضای دیجیتال نسخه ' . $m['version'] . ' معتبر نیست یا وجود ندارد؛ برای امنیت نمایش و نصب نمی‌شود.' );
			return null;
		}
		set_site_transient( self::CACHE, $m, self::CACHE_TTL );
		self::set_status(
			version_compare( $m['version'], self::$version, '>' )
				? 'نسخه جدید ' . $m['version'] . ' موجود است.'
				: 'افزونه به‌روز است (آخرین نسخه: ' . $m['version'] . ').'
		);
		return $m;
	}

	/* ================================================================== *
	 * گیت‌هاب: آخرین Release مخزن، با فایل kashi*.zip ضمیمه‌شده
	 * ================================================================== */
	private static function gh_headers( $s, $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'Kashi-Updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== trim( (string) $s['gh_token'] ) ) {
			$h['Authorization'] = 'Bearer ' . trim( (string) $s['gh_token'] );
		}
		return $h;
	}

	private static function manifest_github( $force, $s ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return isset( $cached['version'] ) ? $cached : null;
			}
		}
		$res = wp_safe_remote_get(
			'https://api.github.com/repos/' . $s['gh_repo'] . '/releases/latest',
			array(
				'timeout' => (int) apply_filters( 'kashi_update_timeout', 8 ),
				'headers' => self::gh_headers( $s ),
			)
		);
		if ( is_wp_error( $res ) ) {
			self::fail( 'اتصال به گیت‌هاب ممکن نشد: ' . $res->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code && '' !== trim( (string) $s['gh_token'] ) ) {
			// هنوز Release ای ساخته نشده: آخرین کد شاخه اصلی مخزن
			return self::manifest_github_branch( $s );
		}
		if ( 404 === $code ) {
			self::fail( 'مخزن پیدا نشد یا خصوصی است؛ «توکن گیت‌هاب» را وارد کنید.' );
			return null;
		}
		if ( 401 === $code || 403 === $code ) {
			self::fail( 401 === $code ? 'توکن گیت‌هاب نامعتبر یا منقضی شده است.' : 'گیت‌هاب درخواست را رد کرد (محدودیت تعداد درخواست یا دسترسی توکن). کمی بعد دوباره امتحان کنید.' );
			return null;
		}
		if ( 200 !== $code ) {
			self::fail( 'پاسخ نامعتبر از گیت‌هاب (کد ' . $code . ').' );
			return null;
		}
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $d ) || empty( $d['tag_name'] ) ) {
			self::fail( 'اطلاعات نسخه در گیت‌هاب نامعتبر است.' );
			return null;
		}
		$asset = null;
		foreach ( isset( $d['assets'] ) && is_array( $d['assets'] ) ? $d['assets'] : array() as $a ) {
			if ( isset( $a['name'], $a['url'] ) && preg_match( '#^kashi[^/]*\.zip$#i', $a['name'] ) ) {
				$asset = $a;
				break;
			}
		}
		if ( ! $asset ) {
			self::fail( 'در آخرین نسخه گیت‌هاب (' . $d['tag_name'] . ') فایل kashi.zip ضمیمه نشده است.' );
			return null;
		}
		$sha = '';
		if ( isset( $asset['digest'] ) && preg_match( '#^sha256:([a-f0-9]{64})$#i', (string) $asset['digest'], $mm ) ) {
			$sha = strtolower( $mm[1] );
		}
		$m = array(
			'source'       => 'github',
			'version'      => sanitize_text_field( ltrim( (string) $d['tag_name'], 'vV' ) ),
			'package'      => esc_url_raw( $asset['url'] ), // آدرس API فایل؛ با توکن دانلود می‌شود
			'sha256'       => $sha,
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'changelog'    => isset( $d['body'] ) ? wp_kses_post( (string) $d['body'] ) : '',
			'last_updated' => isset( $d['published_at'] ) ? sanitize_text_field( (string) $d['published_at'] ) : '',
			'homepage'     => isset( $d['html_url'] ) ? esc_url_raw( $d['html_url'] ) : '',
			'signature'    => '',
		);
		set_site_transient( self::CACHE, $m, self::CACHE_TTL );
		self::set_status(
			version_compare( $m['version'], self::$version, '>' )
				? 'نسخه جدید ' . $m['version'] . ' در گیت‌هاب موجود است.'
				: 'افزونه به‌روز است (آخرین نسخه در گیت‌هاب: ' . $m['version'] . ').'
		);
		return $m;
	}

	/**
	 * بدون Release: نسخه از هدر kashi.php آخرین کامیت شاخه اصلی خوانده می‌شود و
	 * بسته zip همان کامیت دانلود می‌شود (هر بار که شماره نسخه بالا برود، آپدیت دیده می‌شود).
	 */
	private static function manifest_github_branch( $s ) {
		$base = 'https://api.github.com/repos/' . $s['gh_repo'];
		$args = array(
			'timeout' => (int) apply_filters( 'kashi_update_timeout', 8 ),
			'headers' => self::gh_headers( $s ),
		);
		$repo = wp_safe_remote_get( $base, $args );
		if ( is_wp_error( $repo ) || 200 !== (int) wp_remote_retrieve_response_code( $repo ) ) {
			self::fail( is_wp_error( $repo ) ? 'اتصال به گیت‌هاب ممکن نشد: ' . $repo->get_error_message() : 'توکن به مخزن ' . $s['gh_repo'] . ' دسترسی ندارد.' );
			return null;
		}
		$info   = json_decode( wp_remote_retrieve_body( $repo ), true );
		$branch = is_array( $info ) && ! empty( $info['default_branch'] ) ? (string) $info['default_branch'] : 'main';
		$com    = wp_safe_remote_get( $base . '/commits/' . rawurlencode( $branch ), $args );
		$cd     = is_wp_error( $com ) ? null : json_decode( wp_remote_retrieve_body( $com ), true );
		if ( ! is_array( $cd ) || empty( $cd['sha'] ) || ! preg_match( '/^[a-f0-9]{40}$/', $cd['sha'] ) ) {
			self::fail( 'آخرین کامیت مخزن خوانده نشد.' );
			return null;
		}
		$sha  = $cd['sha'];
		$args['headers']['Accept'] = 'application/vnd.github.raw';
		$file = wp_safe_remote_get( $base . '/contents/kashi.php?ref=' . $sha, $args );
		$head = is_wp_error( $file ) ? '' : substr( (string) wp_remote_retrieve_body( $file ), 0, 8192 );
		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $head, $mm ) ) {
			self::fail( 'شماره نسخه از kashi.php مخزن خوانده نشد.' );
			return null;
		}
		$m = array(
			'source'       => 'github',
			'branch'       => true,
			'version'      => $mm[1],
			'package'      => $base . '/zipball/' . $sha,
			'sha256'       => '',
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'changelog'    => isset( $cd['commit']['message'] ) ? esc_html( (string) $cd['commit']['message'] ) : '',
			'last_updated' => isset( $cd['commit']['committer']['date'] ) ? sanitize_text_field( (string) $cd['commit']['committer']['date'] ) : '',
			'homepage'     => 'https://github.com/' . $s['gh_repo'],
			'signature'    => '',
		);
		set_site_transient( self::CACHE, $m, self::CACHE_TTL );
		self::set_status(
			version_compare( $m['version'], self::$version, '>' )
				? 'نسخه جدید ' . $m['version'] . ' در گیت‌هاب موجود است.'
				: 'افزونه به‌روز است (آخرین نسخه در گیت‌هاب: ' . $m['version'] . ').'
		);
		return $m;
	}

	/** zip کامیت گیت‌هاب پوشه‌ای مثل Owner-kashi-abc123 دارد؛ هنگام نصب به kashi تغییر نام می‌دهیم */
	public static function fix_source_dir( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( ! isset( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( self::$basename );
		if ( untrailingslashit( $source ) === $want || ! file_exists( trailingslashit( $source ) . 'kashi.php' ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $want, true ) ) {
			return trailingslashit( $want );
		}
		return new WP_Error( 'kashi_rename', 'پوشه بسته گیت‌هاب به kashi تغییر نام داده نشد.' );
	}

	/** دانلود فایل Release گیت‌هاب (خصوصی یا عمومی) و بررسی آن */
	private static function github_download( $m ) {
		$s   = self::settings();
		$tmp = wp_tempnam( 'kashi-github.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'kashi_tmp', 'فایل موقت ساخته نشد.' );
		}
		// قدم ۱: آدرس API فایل با توکن؛ گیت‌هاب به آدرس موقت دانلود هدایت می‌کند
		$r = wp_safe_remote_get(
			$m['package'],
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $tmp,
				'headers'     => self::gh_headers( $s, 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			wp_delete_file( $tmp );
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$loc = (string) wp_remote_retrieve_header( $r, 'location' );
			if ( 'https' !== wp_parse_url( $loc, PHP_URL_SCHEME ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'kashi_gh_redirect', 'آدرس دانلود گیت‌هاب نامعتبر است.' );
			}
			// قدم ۲: آدرس موقت (امضاشده توسط گیت‌هاب) بدون توکن
			$r    = wp_safe_remote_get(
				$loc,
				array(
					'timeout'  => 300,
					'stream'   => true,
					'filename' => $tmp,
				)
			);
			if ( is_wp_error( $r ) ) {
				wp_delete_file( $tmp );
				return $r;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
		}
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_gh_download', 'دانلود از گیت‌هاب انجام نشد (کد ' . $code . ').' );
		}
		if ( '' !== $m['sha256'] && ! hash_equals( $m['sha256'], strtolower( (string) hash_file( 'sha256', $tmp ) ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_checksum', 'فایل دانلودشده با فایل گیت‌هاب یکی نیست (SHA-256)؛ نصب متوقف شد.' );
		}
		$info = self::read_zip( $tmp );
		$fold = empty( $m['branch'] ) ? $info['folder'] === dirname( self::$basename ) : '' !== $info['folder'];
		if ( ! $fold || '' === $info['version'] ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_gh_bad', 'فایل گیت‌هاب بسته معتبر کاشی نیست (پوشه داخل zip باید ' . dirname( self::$basename ) . '/ باشد).' );
		}
		if ( version_compare( $info['version'], $m['version'], '!=' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_gh_version', 'نسخه داخل فایل (' . $info['version'] . ') با نسخه Release (' . $m['version'] . ') یکی نیست.' );
		}
		return $tmp;
	}

	private static function fail( $msg ) {
		set_site_transient( self::CACHE, array( 'error' => $msg ), self::FAIL_TTL );
		self::set_status( $msg );
	}

	public static function flush_cache() {
		delete_site_transient( self::CACHE );
	}

	/** وردپرس (۵.۸+) این را برای افزونه‌هایی با Update URI اختصاصی صدا می‌زند */
	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== self::$basename ) {
			return $update;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $update;
		}
		return self::update_item( $m );
	}

	private static function update_item( $m ) {
		return array(
			'id'           => 'https://' . self::HOST . '/',
			'slug'         => self::SLUG,
			'plugin'       => self::$basename,
			'version'      => $m['version'],
			'new_version'  => $m['version'],
			'url'          => $m['homepage'],
			'package'      => $m['package'],
			'tested'       => $m['tested'],
			'requires'     => $m['requires'],
			'requires_php' => $m['requires_php'],
			'icons'        => array(),
		);
	}

	/**
	 * نسخه جدید را مستقیم در فهرست آپدیت‌های وردپرس قرار می‌دهد، مستقل از اتصال به wordpress.org.
	 * فقط در پیشخوان / کرون / WP-CLI؛ در صفحه‌های عمومی سایت هیچ درخواستی ارسال نمی‌شود.
	 */
	public static function inject_update( $t ) {
		if ( '' === self::$basename ) {
			return $t;
		}
		$ctx = is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $ctx ) {
			return $t;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $t;
		}
		if ( ! is_object( $t ) ) {
			$t = new stdClass();
		}
		if ( ! isset( $t->response ) || ! is_array( $t->response ) ) {
			$t->response = array();
		}
		if ( ! isset( $t->no_update ) || ! is_array( $t->no_update ) ) {
			$t->no_update = array();
		}
		$item = (object) self::update_item( $m );
		if ( version_compare( $m['version'], self::$version, '>' ) ) {
			$t->response[ self::$basename ] = $item;
			unset( $t->no_update[ self::$basename ] );
		} else {
			unset( $t->response[ self::$basename ] );
			$t->no_update[ self::$basename ] = $item;
		}
		return $t;
	}

	/** پنجره «مشاهده جزئیات نسخه» */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $result;
		}
		return (object) array(
			'name'          => 'کاشی — Kashi',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => 'Karorostampoor@gmail.com',
			'homepage'      => $m['homepage'],
			'requires'      => $m['requires'],
			'requires_php'  => $m['requires_php'],
			'tested'        => $m['tested'],
			'last_updated'  => $m['last_updated'],
			'download_link' => $m['package'],
			'sections'      => array(
				'description' => '<p>صفحه‌ساز کدمحور و سبک: صفحه را کاشی‌به‌کاشی با کد خودتان بسازید.</p>',
				'changelog'   => $m['changelog'] ? wpautop( $m['changelog'] ) : '<p>—</p>',
			),
		);
	}

	/** دانلود نسخه جدید + بررسی SHA-256 */
	public static function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$m       = get_site_transient( self::CACHE );
		$upd     = get_site_transient( 'update_plugins' );
		$offered = ( is_object( $upd ) && isset( $upd->response[ self::$basename ]->package ) ) ? (string) $upd->response[ self::$basename ]->package : '';
		$is_ours = ( isset( $hook_extra['plugin'] ) && self::$basename === $hook_extra['plugin'] )
			|| ( is_array( $m ) && ! empty( $m['package'] ) && $package === $m['package'] )
			|| ( '' !== $offered && $package === $offered ); // حتی وقتی کش manifest منقضی شده
		if ( ! $is_ours ) {
			return $reply;
		}
		// بستن در حالت شک: بدون امضای معتبرِ همین فایل، نصب نمی‌شود
		if ( ! is_array( $m ) || empty( $m['sha256'] ) || $package !== ( isset( $m['package'] ) ? $m['package'] : '' ) ) {
			$m = self::manifest( true );
		}
		if ( is_array( $m ) && isset( $m['source'] ) && 'github' === $m['source'] ) {
			if ( $package !== $m['package'] ) {
				return new WP_Error( 'kashi_package_mismatch', 'فایل این آپدیت با نسخه گیت‌هاب یکی نیست؛ «بررسی آپدیت» را بزنید و دوباره امتحان کنید.' );
			}
			return self::github_download( $m );
		}
		if ( ! $m || empty( $m['sha256'] ) ) {
			return new WP_Error( 'kashi_no_checksum', 'امضای نسخه جدید دریافت نشد؛ برای امنیت نصب متوقف شد. «بررسی آپدیت» را بزنید و دوباره امتحان کنید.' );
		}
		if ( ! self::signature_ok( $m ) ) {
			return new WP_Error( 'kashi_signature', 'امضای دیجیتال این نسخه معتبر نیست؛ نصب متوقف شد.' );
		}
		if ( $package !== $m['package'] ) {
			return new WP_Error( 'kashi_package_mismatch', 'فایل این آپدیت با نسخه اعلام‌شده سرور یکی نیست؛ «بررسی آپدیت» را بزنید و دوباره امتحان کنید.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( $m['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_checksum', 'فایل دانلودشده با امضای نسخه (SHA-256) یکی نیست؛ نصب متوقف شد. دوباره امتحان کنید یا فایل سرور را بررسی کنید.' );
		}
		return $tmp;
	}

	public static function row_meta( $links, $file ) {
		if ( $file === self::$basename && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kashi_check_updates' ), 'kashi_check_updates' ) ) . '">بررسی آپدیت</a>';
			$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=lsec_section&page=kashi-settings' ) ) . '">تنظیمات</a>';
		}
		return $links;
	}

	public static function check_now() {
		check_admin_referer( 'kashi_check_updates' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		self::flush_cache();
		self::manifest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins(); // اگر wordpress.org در دسترس نباشد هم inject_update نسخه را نشان می‌دهد
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'kashi_checked', '1', $back ) );
		exit;
	}

	/* ================================================================== *
	 * آپدیت آفلاین: نصب فایل zip بارگذاری‌شده، با همان بررسی امضا
	 * برای وقتی سرور آپدیت در دسترس نیست (قطعی اینترنت بین‌الملل).
	 * ================================================================== */
	public static function offline_update() {
		check_admin_referer( 'kashi_offline_update' );
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$res = self::offline_install(
			isset( $_FILES['kashi_offline_zip'] ) ? $_FILES['kashi_offline_zip'] : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_POST['kashi_offline_sig'] ) ? (string) wp_unslash( $_POST['kashi_offline_sig'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			! empty( $_POST['kashi_offline_trust'] )
		);
		if ( is_wp_error( $res ) ) {
			set_transient( 'kashi_offline_msg_' . get_current_user_id(), $res->get_error_message(), 300 );
			$flag = 'offerr';
		} else {
			$flag = 'offok';
		}
		wp_safe_redirect( add_query_arg( 'kashi_saved', $flag, admin_url( 'edit.php?post_type=lsec_section&page=kashi-settings' ) ) );
		exit;
	}

	/**
	 * بررسی بسته آفلاین: پوشه، نسخه، امضا.
	 * @return array|WP_Error array( info, sha256 )
	 */
	public static function offline_check( $path, $sig, $trust ) {
		$info = self::read_zip( $path );
		$dir  = dirname( self::$basename );
		if ( '' === $info['version'] || $info['folder'] !== $dir ) {
			return new WP_Error( 'kashi_off_bad', 'این فایل، بسته افزونه کاشی نیست (پوشه داخل zip باید ' . $dir . '/ باشد).' );
		}
		if ( version_compare( $info['version'], self::$version, '<' ) ) {
			return new WP_Error( 'kashi_off_old', 'نسخه این فایل (' . $info['version'] . ') از نسخه نصب‌شده (' . self::$version . ') قدیمی‌تر است.' );
		}
		$sha = strtolower( (string) hash_file( 'sha256', $path ) );
		if ( '' !== self::pinned_key() ) {
			$m = array(
				'version'   => $info['version'],
				'sha256'    => $sha,
				'signature' => preg_replace( '#[^A-Za-z0-9+/=]#', '', $sig ),
			);
			if ( ! self::signature_ok( $m ) ) {
				return new WP_Error( 'kashi_off_sig', 'امضای دیجیتال با این فایل نمی‌خواند؛ نصب نشد. امضا را از «سرور آپدیت» همان نسخه کپی کنید.' );
			}
		} elseif ( ! $trust ) {
			return new WP_Error( 'kashi_off_trust', 'کلید عمومی امضا تنظیم نشده؛ برای نصب، تأیید کنید که فایل را از منبع مطمئن گرفته‌اید.' );
		}
		return array( $info, $sha );
	}

	/**
	 * بررسی و نصب. جدا از offline_update تا قابل تست باشد.
	 * @return true|WP_Error
	 */
	public static function offline_install( $file, $sig, $trust ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'kashi_off_nofile', 'فایل zip انتخاب نشد یا کامل بارگذاری نشد.' );
		}
		if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'kashi_off_big', 'حجم فایل بیش از حد مجاز است.' );
		}
		$path = $file['tmp_name'];
		$chk  = self::offline_check( $path, $sig, $trust );
		if ( is_wp_error( $chk ) ) {
			return $chk;
		}
		list( $info, $sha ) = $chk;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$tmp = wp_tempnam( 'kashi-offline.zip' );
		if ( ! $tmp || ! @move_uploaded_file( $path, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'kashi_off_move', 'فایل در پوشه موقت ذخیره نشد.' );
		}
		if ( ! hash_equals( $sha, strtolower( (string) hash_file( 'sha256', $tmp ) ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'kashi_off_changed', 'فایل هنگام انتقال تغییر کرد؛ نصب نشد.' );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$r        = $upgrader->install(
			$tmp,
			array(
				'overwrite_package'  => true,
				'clear_update_cache' => true,
			)
		);
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r ) {
			$err = $skin->get_errors();
			if ( is_wp_error( $err ) && $err->has_errors() ) {
				return $err;
			}
			return new WP_Error( 'kashi_off_fail', 'نصب انجام نشد. دسترسی نوشتن روی پوشه افزونه‌ها را بررسی کنید.' );
		}
		self::flush_cache();
		self::set_status( 'نسخه ' . $info['version'] . ' به‌صورت آفلاین نصب شد.' );
		return true;
	}

	/* ================================================================== *
	 * سرور: /wp-json/kashi/v1/update
	 * ================================================================== */
	public static function register_route() {
		register_rest_route(
			'kashi/v1',
			'/update',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'serve_manifest' ),
			)
		);
	}

	public static function serve_manifest( $request ) {
		$s = self::settings();
		$r = get_option( self::OPT_RELEASE, array() );
		if ( empty( $s['server'] ) || ! is_array( $r ) || empty( $r['version'] ) ) {
			return new WP_Error( 'kashi_no_release', 'سرور آپدیت فعال نیست.', array( 'status' => 404 ) );
		}
		$given = (string) $request->get_header( 'x_kashi_key' );
		if ( '' === $given ) {
			$given = (string) $request->get_param( 'key' ); // سازگاری با نسخه ۱.۱.۰
		}
		if ( '' !== $s['server_key'] && ! hash_equals( $s['server_key'], $given ) ) {
			return new WP_Error( 'kashi_forbidden', 'کلید دسترسی نامعتبر است.', array( 'status' => 403 ) );
		}
		$res = rest_ensure_response(
			array(
				'name'         => 'Kashi',
				'version'      => $r['version'],
				'package'      => $r['package'],
				'sha256'       => $r['sha256'],
				'requires'     => $r['requires'],
				'requires_php' => $r['requires_php'],
				'tested'       => $r['tested'],
				'changelog'    => $r['changelog'],
				'last_updated' => $r['last_updated'],
				'signature'    => ( ! empty( $r['signature'] ) && isset( $r['signed_for'] ) && self::sign_message( $r['version'], $r['sha256'] ) === $r['signed_for'] ) ? $r['signature'] : '',
				'homepage'     => home_url( '/' ),
			)
		);
		$res->header( 'Cache-Control', 'no-cache' );
		return $res;
	}

	/** اطلاعات نسخه را از داخل فایل zip می‌خواند */
	private static function read_zip( $path ) {
		$info = array(
			'version'      => '',
			'requires'     => '',
			'requires_php' => '',
			'folder'       => '',
		);
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $info;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return $info;
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( preg_match( '#^([^/]+)/kashi\.php$#', $name, $fm ) ) {
				$info['folder'] = $fm[1];
				$head = substr( (string) $zip->getFromIndex( $i ), 0, 8192 );
				foreach ( array(
					'version'      => 'Version',
					'requires'     => 'Requires at least',
					'requires_php' => 'Requires PHP',
				) as $k => $h ) {
					if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $h, '/' ) . ':(.*)$/mi', $head, $mm ) ) {
						$info[ $k ] = trim( $mm[1] );
					}
				}
				break;
			}
		}
		$zip->close();
		return $info;
	}

	/* ================================================================== *
	 * صفحه تنظیمات
	 * ================================================================== */
	public static function menu() {
		$hook = add_submenu_page(
			'edit.php?post_type=lsec_section',
			'تنظیمات کاشی',
			'تنظیمات',
			'manage_options',
			'kashi-settings',
			array( __CLASS__, 'page' )
		);
		add_action(
			'admin_print_scripts-' . $hook,
			function () {
				wp_enqueue_media();
			}
		);
	}

	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['kashi_checked'] ) ) {
			$s = self::settings();
			echo '<div class="notice notice-info is-dismissible"><p><b>کاشی:</b> ' . esc_html( $s['last_status'] ? $s['last_status'] : 'بررسی انجام شد.' ) . '</p></div>';
		}
		if ( isset( $_GET['kashi_saved'] ) && ! self::$inline ) {
			$code = sanitize_key( wp_unslash( $_GET['kashi_saved'] ) );
			$all  = array(
				'1'           => array( 'success', 'تنظیمات ذخیره شد.' ),
				'signed'      => array( 'success', 'نسخه با امضای دیجیتال منتشر شد. کلید خصوصی ذخیره نشد.' ),
				'noversion'   => array( 'warning', 'نسخه از داخل فایل zip خوانده نشد؛ شماره نسخه را دستی وارد کنید.' ),
				'badkey'      => array( 'error', 'کلید خصوصی نامعتبر است؛ نسخه امضا نشد.' ),
				'keymismatch' => array( 'error', 'این کلید خصوصی با کلید عمومی این سرور یکی نیست؛ نسخه امضا نشد.' ),
				'offok'       => array( 'success', 'کاشی با فایل آفلاین به‌روز شد.' ),
				'offerr'      => array( 'error', '' ),
			);
			$n = isset( $all[ $code ] ) ? $all[ $code ] : $all['1'];
			if ( 'offerr' === $code ) {
				$k    = 'kashi_offline_msg_' . get_current_user_id();
				$n[1] = 'آپدیت آفلاین انجام نشد: ' . (string) get_transient( $k );
				delete_transient( $k );
			}
			echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p><b>کاشی:</b> ' . esc_html( $n[1] ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function save_settings() {
		check_admin_referer( 'kashi_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$old = get_option( self::OPT, array() );
		$old = is_array( $old ) ? $old : array();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new = array_merge(
			$old,
			array(
				'source'     => ( isset( $_POST['kashi_source'] ) && 'server' === $_POST['kashi_source'] ) ? 'server' : 'github',
				'gh_repo'    => isset( $_POST['kashi_gh_repo'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_gh_repo'] ) ) : self::GH_REPO,
				'gh_token'   => isset( $_POST['kashi_gh_token'] ) ? preg_replace( '#[^A-Za-z0-9_]#', '', wp_unslash( $_POST['kashi_gh_token'] ) ) : '',
				'url'        => isset( $_POST['kashi_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['kashi_url'] ) ) ) : '',
				'key'        => isset( $_POST['kashi_key'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_key'] ) ) : '',
				'server'     => ! empty( $_POST['kashi_server'] ),
				'server_key' => isset( $_POST['kashi_server_key'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_server_key'] ) ) : '',
				'pubkey'     => isset( $_POST['kashi_pubkey'] ) ? preg_replace( '#[^A-Za-z0-9+/=]#', '', wp_unslash( $_POST['kashi_pubkey'] ) ) : '',
			)
		);
		update_option( self::OPT, $new, false );
		self::flush_cache();
		if ( class_exists( 'Kashi_Net' ) ) {
			Kashi_Net::save_from_post();
		}
		if ( 'github' === $new['source'] || '' !== $new['url'] ) {
			self::manifest( true ); // بلافاصله بررسی شود تا «وضعیت» نتیجه واقعی را نشان دهد
			delete_site_transient( 'update_plugins' );
		}

		$flag = '1';
		if ( $new['server'] ) {
			$att  = isset( $_POST['kashi_zip'] ) ? absint( $_POST['kashi_zip'] ) : 0;
			$prev = get_option( self::OPT_RELEASE, array() );
			$prev = is_array( $prev ) ? $prev : array();
			$rel  = $prev;
			if ( $att && 'attachment' === get_post_type( $att ) ) {
				$path = get_attached_file( $att );
				if ( $path && is_readable( $path ) ) {
					$zip = self::read_zip( $path );
					$rel = array_merge(
						$rel,
						array(
							'attachment'   => $att,
							'package'      => wp_get_attachment_url( $att ),
							'sha256'       => hash_file( 'sha256', $path ),
							'requires'     => $zip['requires'],
							'requires_php' => $zip['requires_php'],
							'version'      => $zip['version'],
						)
					);
					if ( ! isset( $prev['attachment'] ) || (int) $prev['attachment'] !== $att ) {
						$rel['last_updated'] = gmdate( 'Y-m-d H:i:s' );
					}
				}
			}
			$manual = isset( $_POST['kashi_version'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_version'] ) ) : '';
			if ( '' !== $manual ) {
				$rel['version'] = $manual;
			}
			$rel['tested']    = isset( $_POST['kashi_tested'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_tested'] ) ) : get_bloginfo( 'version' );
			$rel['changelog'] = isset( $_POST['kashi_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['kashi_changelog'] ) ) : '';
			if ( empty( $rel['version'] ) && ! empty( $rel['package'] ) ) {
				$flag = 'noversion';
			}
			// امضا: کلید خصوصی فقط در همین درخواست استفاده و بلافاصله پاک می‌شود
			$secret = isset( $_POST['kashi_sign_secret'] ) ? trim( wp_unslash( $_POST['kashi_sign_secret'] ) ) : '';
			unset( $_POST['kashi_sign_secret'] );
			if ( '' !== $secret && ! empty( $rel['version'] ) && ! empty( $rel['sha256'] ) ) {
				$sk = self::b64( $secret );
				$pk = isset( $new['sign_pub'] ) ? self::b64( $new['sign_pub'] ) : '';
				if ( 64 !== strlen( $sk ) || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
					$flag = 'badkey';
				} elseif ( '' === $pk || ! hash_equals( $pk, sodium_crypto_sign_publickey_from_secretkey( $sk ) ) ) {
					$flag = 'keymismatch';
				} else {
					$msg               = self::sign_message( $rel['version'], $rel['sha256'] );
					$rel['signature']  = base64_encode( sodium_crypto_sign_detached( $msg, $sk ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					$rel['signed_for'] = $msg;
					$flag              = 'signed';
				}
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $sk );
				}
			}
			$secret = '';
			update_option( self::OPT_RELEASE, $rel, false );
		}
		// phpcs:enable
		wp_safe_redirect( add_query_arg( 'kashi_saved', $flag, admin_url( 'edit.php?post_type=lsec_section&page=kashi-settings' ) ) );
		exit;
	}

	public static function page() {
		$s   = self::settings();
		$r   = get_option( self::OPT_RELEASE, array() );
		$r   = wp_parse_args(
			is_array( $r ) ? $r : array(),
			array(
				'attachment' => 0,
				'package'    => '',
				'version'    => '',
				'sha256'     => '',
				'tested'     => '',
				'changelog'  => '',
				'signature'  => '',
				'signed_for' => '',
			)
		);
		$m   = get_site_transient( self::CACHE );
		$new = is_array( $m ) && ! empty( $m['version'] ) && version_compare( $m['version'], self::$version, '>' );
		?>
		<div class="wrap kashi-settings">
			<style>
				.kashi-settings h1{display:flex;align-items:center;gap:10px}
				.kashi-settings .kashi-card{background:#fff;border:1px solid #e2e4e9;border-radius:12px;padding:22px 24px;margin:18px 0;max-width:820px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
				.kashi-settings .kashi-card h2{margin:0 0 4px;font-size:16px}
				.kashi-settings .kashi-card > p.desc{margin:0 0 16px;color:#646970}
				.kashi-settings .kashi-row{display:grid;grid-template-columns:200px 1fr;gap:14px;align-items:start;padding:12px 0;border-top:1px solid #f0f0f1}
				.kashi-settings .kashi-row label{font-weight:600;padding-top:6px}
				.kashi-settings .kashi-row input[type=text],.kashi-settings .kashi-row input[type=url],.kashi-settings .kashi-row textarea{width:100%;max-width:520px}
				.kashi-settings .kashi-row .ltr{direction:ltr;text-align:left}
				.kashi-settings .kashi-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:99px;font-size:12px;background:#f0f0f1}
				.kashi-settings .kashi-pill.ok{background:#e7f6ee;color:#0a6b3c}.kashi-settings .kashi-pill.new{background:#efeaff;color:#4b36c8}
				.kashi-settings code.copy{cursor:pointer;direction:ltr;display:inline-block;padding:6px 10px;background:#f6f7f7;border-radius:6px}
				.kashi-settings .kashi-zip{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.kashi-settings .kashi-actions{display:flex;gap:8px;margin-top:6px}
			</style>
			<h1>تنظیمات کاشی <span class="kashi-pill">نسخه <?php echo esc_html( self::$version ); ?></span></h1>
			<?php
			if ( isset( $_GET['kashi_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				self::$inline = false;
				self::notices();
			}
			self::$inline = true; // پیام در admin_notices تکرار نشود
			$self_server  = 'server' === $s['source'] && ! empty( $s['server'] ) && '' !== $s['url'] && false !== strpos( $s['url'], '//' . (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kashi_save_settings">
				<?php wp_nonce_field( 'kashi_save_settings' ); ?>

				<div class="kashi-card">
					<h2>آپدیت خودکار</h2>
					<p class="desc">نسخه‌های جدید کاشی مثل بقیه افزونه‌ها در «افزونه‌ها» با دکمه «به‌روزرسانی» نمایش داده می‌شوند.</p>
					<div class="kashi-row">
						<label>منبع آپدیت</label>
						<div>
							<label style="display:block;margin-bottom:6px"><input type="radio" name="kashi_source" value="github" <?php checked( $s['source'], 'github' ); ?> onchange="kashiSrc()"> <b>گیت‌هاب</b> (پیشنهادی — بدون سرور اضافه)</label>
							<label style="display:block"><input type="radio" name="kashi_source" value="server" <?php checked( $s['source'], 'server' ); ?> onchange="kashiSrc()"> سرور آپدیت خودم</label>
						</div>
					</div>
					<div class="kashi-row kashi-src-github">
						<label for="kashi_gh_repo">مخزن گیت‌هاب</label>
						<div><input type="text" class="ltr" id="kashi_gh_repo" name="kashi_gh_repo" value="<?php echo esc_attr( $s['gh_repo'] ); ?>" style="max-width:320px"></div>
					</div>
					<div class="kashi-row kashi-src-github">
						<label for="kashi_gh_token">توکن گیت‌هاب</label>
						<div>
							<input type="password" class="ltr" id="kashi_gh_token" name="kashi_gh_token" value="<?php echo esc_attr( $s['gh_token'] ); ?>" autocomplete="off" <?php disabled( defined( 'KASHI_GITHUB_TOKEN' ) && KASHI_GITHUB_TOKEN ); ?>>
							<p class="description">برای مخزن خصوصی لازم است. در گیت‌هاب: Settings ← Developer settings ← Fine-grained tokens ← فقط همین مخزن، دسترسی <b>Contents: Read-only</b>. اگر مخزن عمومی باشد خالی بگذارید.</p>
						</div>
					</div>
					<div class="kashi-row kashi-src-server">
						<label for="kashi_url">آدرس سرور آپدیت</label>
						<div>
							<input type="url" class="ltr" id="kashi_url" name="kashi_url" value="<?php echo esc_attr( $s['url'] ); ?>" placeholder="https://your-site.com/wp-json/kashi/v1/update" <?php disabled( defined( 'KASHI_UPDATE_URL' ) && KASHI_UPDATE_URL ); ?>>
							<p class="description">آدرسی که در «سرور آپدیت» سایت اصلی‌تان نمایش داده می‌شود، یا آدرس یک فایل update.json.</p>
						</div>
					</div>
					<div class="kashi-row kashi-src-server">
						<label for="kashi_key">کلید دسترسی</label>
						<div><input type="text" class="ltr" id="kashi_key" name="kashi_key" value="<?php echo esc_attr( $s['key'] ); ?>" autocomplete="off"><p class="description">اگر روی سرور کلید گذاشته‌اید، همان را اینجا بنویسید.</p></div>
					</div>
					<div class="kashi-row kashi-src-server">
						<label for="kashi_pubkey">کلید عمومی امضا</label>
						<div>
							<input type="text" class="ltr" id="kashi_pubkey" name="kashi_pubkey" value="<?php echo esc_attr( $s['pubkey'] ); ?>" autocomplete="off" <?php disabled( defined( 'KASHI_UPDATE_PUBKEY' ) && KASHI_UPDATE_PUBKEY ); ?>>
							<?php if ( '' !== self::pinned_key() ) : ?>
								<p><span class="kashi-pill ok">امضای دیجیتال فعال: فقط نسخه‌های امضاشده نصب می‌شوند</span></p>
							<?php else : ?>
								<p class="description" style="color:#b32d2e">هنوز تنظیم نشده. کلید عمومی را از «سرور آپدیت» سایت اصلی‌تان کپی کنید تا هیچ نسخه امضانشده‌ای نصب نشود (حتی اگر سرور هک شود).</p>
							<?php endif; ?>
						</div>
					</div>
					<div class="kashi-row">
						<label>وضعیت</label>
						<div>
							<?php if ( $new ) : ?>
								<span class="kashi-pill new">نسخه <?php echo esc_html( $m['version'] ); ?> آماده نصب است</span>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">رفتن به افزونه‌ها</a>
							<?php elseif ( $self_server ) : ?>
								<span class="kashi-pill ok">این سایت خودش سرور آپدیت است</span>
								<p class="description">روی سایت سرور، آدرس سرور لازم نیست و آپدیتی هم نمایش داده نمی‌شود (همین نسخه را منتشر می‌کند). این آدرس را در سایت‌های <b>دیگر</b> وارد کنید. برای به‌روز کردن خود این سایت، zip نسخه جدید را از «افزونه‌ها ← افزودن ← بارگذاری» نصب کنید.</p>
							<?php elseif ( $s['last_status'] ) : ?>
								<span class="kashi-pill <?php echo is_array( $m ) && ! empty( $m['version'] ) ? 'ok' : ''; ?>"><?php echo esc_html( $s['last_status'] ); ?></span>
							<?php else : ?>
								<span class="kashi-pill">هنوز بررسی نشده</span>
							<?php endif; ?>
							<?php if ( is_array( $m ) && ! empty( $m['version'] ) ) : ?>
								<p class="description">نسخه نصب‌شده: <b><?php echo esc_html( self::$version ); ?></b> — نسخه منتشرشده: <b><?php echo esc_html( $m['version'] ); ?></b></p>
							<?php endif; ?>
							<?php if ( $s['last_check'] ) : ?>
								<p class="description">آخرین بررسی: <?php echo esc_html( human_time_diff( (int) $s['last_check'], time() ) ); ?> پیش</p>
							<?php endif; ?>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kashi_check_updates' ), 'kashi_check_updates' ) ); ?>">بررسی آپدیت الان</a></p>
						</div>
					</div>
				</div>

				<details class="kashi-card" <?php echo ! empty( $s['server'] ) ? 'open' : ''; ?>>
					<summary style="cursor:pointer;font-weight:600;font-size:15px">پیشرفته: سرور آپدیت اختصاصی (فقط اگر گیت‌هاب استفاده نمی‌کنید)</summary>
					<h2 style="margin-top:14px">سرور آپدیت (فقط روی سایت اصلی)</h2>
					<p class="desc">این سایت را منبع آپدیت سایت‌های دیگر کنید. هر بار نسخه جدید آماده شد، فقط فایل zip آن را اینجا انتخاب و ذخیره کنید؛ نسخه و امضا خودکار خوانده می‌شوند و همه سایت‌ها آپدیت را می‌بینند.</p>
					<div class="kashi-row">
						<label for="kashi_server">فعال</label>
						<div><label><input type="checkbox" id="kashi_server" name="kashi_server" value="1" <?php checked( ! empty( $s['server'] ) ); ?>> این سایت سرور آپدیت کاشی باشد</label></div>
					</div>
					<?php if ( ! empty( $s['server'] ) ) : ?>
					<div class="kashi-row">
						<label>آدرس برای سایت‌های دیگر</label>
						<div><code class="copy" title="کلیک برای کپی" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent)"><?php echo esc_html( self::server_url() ); ?></code></div>
					</div>
					<?php endif; ?>
					<div class="kashi-row">
						<label>فایل نسخه (zip)</label>
						<div>
							<div class="kashi-zip">
								<input type="hidden" id="kashi_zip" name="kashi_zip" value="<?php echo (int) $r['attachment']; ?>">
								<button type="button" class="button" id="kashi_pick">انتخاب فایل zip از رسانه</button>
								<span id="kashi_zip_name" class="ltr"><?php echo $r['package'] ? esc_html( wp_basename( $r['package'] ) ) : '—'; ?></span>
							</div>
							<?php if ( $r['version'] ) : ?>
								<p><span class="kashi-pill ok">نسخه منتشرشده: <?php echo esc_html( $r['version'] ); ?></span></p>
								<p class="description ltr">SHA-256: <?php echo esc_html( $r['sha256'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<div class="kashi-row">
						<label for="kashi_version">شماره نسخه</label>
						<div><input type="text" class="ltr" id="kashi_version" name="kashi_version" value="" placeholder="<?php echo esc_attr( $r['version'] ? $r['version'] : 'خودکار از فایل' ); ?>" style="max-width:160px"><p class="description">خالی بگذارید تا از داخل فایل خوانده شود.</p></div>
					</div>
					<div class="kashi-row">
						<label for="kashi_changelog">تغییرات این نسخه</label>
						<div><textarea id="kashi_changelog" name="kashi_changelog" rows="4"><?php echo esc_textarea( $r['changelog'] ); ?></textarea></div>
					</div>
					<div class="kashi-row">
						<label for="kashi_tested">تست‌شده تا وردپرس</label>
						<div><input type="text" class="ltr" id="kashi_tested" name="kashi_tested" value="<?php echo esc_attr( $r['tested'] ? $r['tested'] : get_bloginfo( 'version' ) ); ?>" style="max-width:120px"></div>
					</div>
					<div class="kashi-row">
						<label>امضای دیجیتال</label>
						<div>
							<?php if ( empty( $s['sign_pub'] ) ) : ?>
								<p class="description">با امضای دیجیتال، سایت‌ها فقط نسخه‌هایی را نصب می‌کنند که با کلید خصوصی شما امضا شده باشند. کلید خصوصی فقط یک بار دانلود می‌شود و هیچ‌جا ذخیره نمی‌شود.</p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kashi_gen_keys' ), 'kashi_gen_keys' ) ); ?>">ساخت کلید امضا و دانلود</a>
							<?php else : ?>
								<p>کلید عمومی (در همه سایت‌ها، فیلد «کلید عمومی امضا»):</p>
								<p><code class="copy" title="کلیک برای کپی" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent)"><?php echo esc_html( $s['sign_pub'] ); ?></code></p>
								<?php
								$signed = ! empty( $r['signature'] ) && isset( $r['signed_for'] ) && $r['version'] && self::sign_message( $r['version'], $r['sha256'] ) === $r['signed_for'];
								?>
								<p><?php echo $signed ? '<span class="kashi-pill ok">نسخه فعلی امضا شده است</span>' : '<span class="kashi-pill" style="background:#fcf0e3;color:#8a4b00">نسخه فعلی امضا ندارد</span>'; ?></p>
								<?php if ( $signed ) : ?>
									<p class="description">امضای این نسخه برای «آپدیت آفلاین» (همراه فایل zip به سایت‌ها بدهید):</p>
									<p><code class="copy" title="کلیک برای کپی" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent)"><?php echo esc_html( $r['signature'] ); ?></code></p>
								<?php endif; ?>
								<p style="margin-top:10px"><input type="password" class="ltr" name="kashi_sign_secret" value="" autocomplete="off" placeholder="کلید خصوصی امضا را اینجا بچسبانید (ذخیره نمی‌شود)" style="width:100%;max-width:520px"></p>
								<p class="description">هنگام انتشار هر نسخه جدید، کلید خصوصی را بچسبانید و ذخیره کنید. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kashi_gen_keys' ), 'kashi_gen_keys' ) ); ?>" onclick="return confirm('با ساخت کلید جدید، باید کلید عمومی را در همه سایت‌ها عوض کنید. ادامه؟');">ساخت کلید جدید</a></p>
							<?php endif; ?>
						</div>
					</div>
					<div class="kashi-row">
						<label for="kashi_server_key">کلید دسترسی (اختیاری)</label>
						<div><input type="text" class="ltr" id="kashi_server_key" name="kashi_server_key" value="<?php echo esc_attr( $s['server_key'] ); ?>" autocomplete="off"><p class="description">اگر بگذارید، فقط سایت‌هایی که این کلید را دارند آپدیت را می‌بینند.</p></div>
					</div>
				</details>

				<?php
				if ( class_exists( 'Kashi_Net' ) ) {
					Kashi_Net::card();
				}
				submit_button( 'ذخیره تنظیمات' );
				?>
			</form>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kashi-card">
				<input type="hidden" name="action" value="kashi_offline_update">
				<?php wp_nonce_field( 'kashi_offline_update' ); ?>
				<h2>آپدیت آفلاین</h2>
				<p class="desc">وقتی سرور آپدیت در دسترس نیست (مثلاً در قطعی اینترنت بین‌الملل)، فایل zip نسخه جدید را مستقیم بارگذاری کنید. همان بررسی‌های امنیتی آپدیت خودکار انجام می‌شود و داده‌ها و صفحه‌ها دست نمی‌خورند.</p>
				<div class="kashi-row">
					<label for="kashi_offline_zip">فایل zip</label>
					<div><input type="file" id="kashi_offline_zip" name="kashi_offline_zip" accept=".zip,application/zip" required></div>
				</div>
				<?php if ( '' !== self::pinned_key() ) : ?>
				<div class="kashi-row">
					<label for="kashi_offline_sig">امضای دیجیتال نسخه</label>
					<div><input type="text" class="ltr" id="kashi_offline_sig" name="kashi_offline_sig" autocomplete="off" required><p class="description">در «سرور آپدیت» سایت اصلی، کنار همان نسخه نمایش داده می‌شود. بدون امضای معتبر نصب نمی‌شود.</p></div>
				</div>
				<?php else : ?>
				<div class="kashi-row">
					<label>تأیید</label>
					<div><label><input type="checkbox" name="kashi_offline_trust" value="1" required> این فایل را از منبع مطمئن (خودم) گرفته‌ام</label><p class="description">پیشنهاد: «کلید عمومی امضا» را بالاتر تنظیم کنید تا فقط فایل‌های امضاشده نصب شوند.</p></div>
				</div>
				<?php endif; ?>
				<p><button type="submit" class="button button-primary">بارگذاری و نصب</button></p>
			</form>
			<script>
			function kashiSrc() {
				var r = document.querySelector('input[name=kashi_source]:checked'), v = r ? r.value : 'github';
				document.querySelectorAll('.kashi-src-github').forEach(function (e) { e.style.display = v === 'github' ? '' : 'none'; });
				document.querySelectorAll('.kashi-src-server').forEach(function (e) { e.style.display = v === 'server' ? '' : 'none'; });
			}
			kashiSrc();
			(function () {
				var b = document.getElementById('kashi_pick'), frame;
				if (!b) { return; }
				b.addEventListener('click', function () {
					if (!window.wp || !wp.media) { alert('کتابخانه رسانه بارگذاری نشد.'); return; }
					if (!frame) {
						frame = wp.media({ title: 'انتخاب فایل zip کاشی', button: { text: 'انتخاب' }, multiple: false, library: { type: 'application/zip' } });
						frame.on('select', function () {
							var a = frame.state().get('selection').first().toJSON();
							document.getElementById('kashi_zip').value = a.id;
							document.getElementById('kashi_zip_name').textContent = a.filename || a.url;
						});
					}
					frame.open();
				});
			})();
			</script>
		</div>
		<?php
	}
}
