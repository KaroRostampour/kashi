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
	const FAIL_TTL    = 30 * MINUTE_IN_SECONDS;

	/** @var string plugin_basename فایل اصلی، مثل kashi/kashi.php */
	private static $basename = '';
	private static $version  = '';

	public static function init( $plugin_file, $version ) {
		self::$basename = plugin_basename( $plugin_file );
		self::$version  = $version;

		// کلاینت
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'check_update' ), 10, 4 );
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
				'url'           => '',
				'key'           => '',
				'server'        => false,
				'server_key'    => '',
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
		return $s;
	}

	private static function set_status( $status ) {
		$s                = get_option( self::OPT, array() );
		$s                = is_array( $s ) ? $s : array();
		$s['last_check']  = time();
		$s['last_status'] = $status;
		update_option( self::OPT, $s, false );
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
				'timeout' => 12,
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
		);
		set_site_transient( self::CACHE, $m, self::CACHE_TTL );
		self::set_status(
			version_compare( $m['version'], self::$version, '>' )
				? 'نسخه جدید ' . $m['version'] . ' موجود است.'
				: 'افزونه به‌روز است (آخرین نسخه: ' . $m['version'] . ').'
		);
		return $m;
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
		if ( ! $m || empty( $m['sha256'] ) ) {
			return new WP_Error( 'kashi_no_checksum', 'امضای نسخه جدید دریافت نشد؛ برای امنیت نصب متوقف شد. «بررسی آپدیت» را بزنید و دوباره امتحان کنید.' );
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
		wp_update_plugins();
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'kashi_checked', '1', $back ) );
		exit;
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
			if ( preg_match( '#^[^/]+/kashi\.php$#', $name ) ) {
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
		if ( isset( $_GET['kashi_saved'] ) ) {
			$msg = 'تنظیمات ذخیره شد.';
			if ( 'noversion' === $_GET['kashi_saved'] ) {
				$msg = 'نسخه از داخل فایل zip خوانده نشد؛ شماره نسخه را دستی وارد کنید.';
			}
			echo '<div class="notice notice-' . ( 'noversion' === $_GET['kashi_saved'] ? 'warning' : 'success' ) . ' is-dismissible"><p><b>کاشی:</b> ' . esc_html( $msg ) . '</p></div>';
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
				'url'        => isset( $_POST['kashi_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['kashi_url'] ) ) ) : '',
				'key'        => isset( $_POST['kashi_key'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_key'] ) ) : '',
				'server'     => ! empty( $_POST['kashi_server'] ),
				'server_key' => isset( $_POST['kashi_server_key'] ) ? sanitize_text_field( wp_unslash( $_POST['kashi_server_key'] ) ) : '',
			)
		);
		update_option( self::OPT, $new, false );
		self::flush_cache();

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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kashi_save_settings">
				<?php wp_nonce_field( 'kashi_save_settings' ); ?>

				<div class="kashi-card">
					<h2>آپدیت خودکار</h2>
					<p class="desc">آدرس سرور آپدیت را وارد کنید تا نسخه‌های جدید کاشی مثل بقیه افزونه‌ها در «افزونه‌ها» و «به‌روزرسانی‌ها» نمایش داده شوند. فایل هر نسخه قبل از نصب با امضای SHA-256 بررسی می‌شود.</p>
					<div class="kashi-row">
						<label for="kashi_url">آدرس سرور آپدیت</label>
						<div>
							<input type="url" class="ltr" id="kashi_url" name="kashi_url" value="<?php echo esc_attr( $s['url'] ); ?>" placeholder="https://your-site.com/wp-json/kashi/v1/update" <?php disabled( defined( 'KASHI_UPDATE_URL' ) && KASHI_UPDATE_URL ); ?>>
							<p class="description">آدرسی که در «سرور آپدیت» سایت اصلی‌تان نمایش داده می‌شود، یا آدرس یک فایل update.json.</p>
						</div>
					</div>
					<div class="kashi-row">
						<label for="kashi_key">کلید دسترسی</label>
						<div><input type="text" class="ltr" id="kashi_key" name="kashi_key" value="<?php echo esc_attr( $s['key'] ); ?>" autocomplete="off"><p class="description">اگر روی سرور کلید گذاشته‌اید، همان را اینجا بنویسید.</p></div>
					</div>
					<div class="kashi-row">
						<label>وضعیت</label>
						<div>
							<?php if ( $new ) : ?>
								<span class="kashi-pill new">نسخه <?php echo esc_html( $m['version'] ); ?> آماده نصب است</span>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">رفتن به افزونه‌ها</a>
							<?php elseif ( $s['last_status'] ) : ?>
								<span class="kashi-pill <?php echo is_array( $m ) && ! empty( $m['version'] ) ? 'ok' : ''; ?>"><?php echo esc_html( $s['last_status'] ); ?></span>
							<?php else : ?>
								<span class="kashi-pill">هنوز بررسی نشده</span>
							<?php endif; ?>
							<?php if ( $s['last_check'] ) : ?>
								<p class="description">آخرین بررسی: <?php echo esc_html( human_time_diff( (int) $s['last_check'], time() ) ); ?> پیش</p>
							<?php endif; ?>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kashi_check_updates' ), 'kashi_check_updates' ) ); ?>">بررسی آپدیت الان</a></p>
						</div>
					</div>
				</div>

				<div class="kashi-card">
					<h2>سرور آپدیت (فقط روی سایت اصلی)</h2>
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
						<label for="kashi_server_key">کلید دسترسی (اختیاری)</label>
						<div><input type="text" class="ltr" id="kashi_server_key" name="kashi_server_key" value="<?php echo esc_attr( $s['server_key'] ); ?>" autocomplete="off"><p class="description">اگر بگذارید، فقط سایت‌هایی که این کلید را دارند آپدیت را می‌بینند.</p></div>
					</div>
				</div>

				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
			<script>
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
