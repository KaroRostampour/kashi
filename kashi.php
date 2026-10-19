<?php
/**
 * Plugin Name:       کاشی — Kashi
 * Description:       صفحه‌ساز کدمحور و سبک: صفحه را کاشی‌به‌کاشی با کد خودتان بسازید و همان لحظه نتیجه را ببینید. بدون بار اضافه، سازگار با هر قالب و افزونه سئو.
 * Version:           1.5.0
 * Author:            Karorostampoor@gmail.com
 * Author URI:        mailto:Karorostampoor@gmail.com
 * License:           GPL-2.0-or-later
 * Text Domain:       kashi
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://kashi.update/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kashi {

	const CPT     = 'lsec_section';
	const VERSION = '1.5.0';

	private static $printed_css  = array();
	private static $footer_js    = array();
	private static $render_stack = array();
	private static $live_cache   = array();

	/** هدر/فوتری که در این درخواست جایگزین می‌شوند: array( 'header' => id, 'footer' => id ) */
	private static $hf_active = array();

	/** پیش‌نمایش یک هدر/فوتر در ویرایشگر خودش */
	private static $hf_override = array();

	/** بافر خروجی صفحه (اگر وسط کار flush شود) */
	private static $hf_buf = '';

	/** HTML آماده هدر/فوتر (خارج از output handler ساخته می‌شود) */
	private static $hf_markup = array();

	/** اسکریپت‌های هدر/فوتر برای wp_footer */
	private static $hf_scripts = '';

	/** خروجی HTML نیست (فایل، JSON، CSV ...): بدون دست‌کاری عبور کند */
	private static $hf_pass = false;

	/** اسکیماهای JSON-LD که چاپ شده‌اند */
	private static $printed_ld = array();

	/** نشانگرهای جای محتوا در the_content */
	private static $ph = array();

	/** عرض کانتینر صفحه‌ی در حال رندر (برای بخش‌های لینک‌شده از کتابخانه) */
	private static $ctx_box = null;

	/** حداکثر تعداد نسخه‌های ذخیره‌شده برای هر برگه */
	const MAX_REVS = 20;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );

		// پیشخوان
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );

		// ویرایشگر زنده
		add_action( 'admin_post_kashi_edit', array( __CLASS__, 'editor_page' ) );
		add_action( 'admin_post_kashi_new', array( __CLASS__, 'new_section' ) );
		add_action( 'admin_post_kashi_export', array( __CLASS__, 'export_download' ) );
		add_action( 'admin_post_kashi_import', array( __CLASS__, 'import_upload' ) );
		add_action( 'wp_ajax_kashi_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_kashi_rev_get', array( __CLASS__, 'ajax_rev_get' ) );
		add_action( 'wp_ajax_kashi_lib_list', array( __CLASS__, 'ajax_lib_list' ) );
		add_action( 'wp_ajax_kashi_lib_get', array( __CLASS__, 'ajax_lib_get' ) );
		add_action( 'wp_ajax_kashi_lib_save', array( __CLASS__, 'ajax_lib_save' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_revs' ) );

		// فایل‌های PHP اختصاصی (شورت‌کدهای خودتان) از پوشه wp-content/kashi-php
		self::load_php_snippets();
		add_action( 'admin_init', array( __CLASS__, 'guard_php_folders' ) );

		// سایت
		add_shortcode( 'kashi', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'lsec', array( __CLASS__, 'shortcode' ) ); // سازگاری با نسخه قبل (Lite Sections)
		add_filter( 'the_content', array( __CLASS__, 'content_placeholder' ), 0 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 9999 );
		add_action( 'wp_head', array( __CLASS__, 'head_assets' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_js' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'section_preview' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_in_preview' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );

		// هدر و فوتر با کاشی
		add_action( 'template_redirect', array( __CLASS__, 'live_headers' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'hf_start' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'hf_prepare' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'hf_print_scripts' ), 100 );
		add_action( 'transition_post_status', array( __CLASS__, 'hf_status_changed' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'hf_post_deleted' ) );
	}

	/* ================================================================== *
	 * کمکی‌ها
	 * ================================================================== */

	private static function post_types() {
		return (array) apply_filters( 'kashi_post_types', array( 'page', 'post', self::CPT ) );
	}

	private static function editor_url( $id ) {
		return admin_url( 'admin-post.php?action=kashi_edit&post=' . (int) $id );
	}

	private static function section_preview_url( $id ) {
		return add_query_arg( 'kashi_preview', (int) $id, home_url( '/' ) );
	}

	private static function live_url( $post ) {
		if ( self::CPT === $post->post_type ) {
			$url = self::section_preview_url( $post->ID );
		} elseif ( 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
		} else {
			$url = get_preview_post_link( $post );
		}
		return add_query_arg( 'kashi_live', '1', $url );
	}

	private static function scope_on( $id ) {
		$scope = get_post_meta( $id, '_lsec_scope', true );
		return ( '' === $scope ) ? true : ( '1' === $scope );
	}

	/** آیا درخواست فعلی پیش‌نمایش زنده‌ی معتبر برای این برگه است؟ */
	private static function live_valid( $id ) {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$cache[ $id ] = ! empty( $_POST['kashi_live_nonce'] )
				&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kashi_live_nonce'] ) ), 'kashi_live_' . $id )
				&& current_user_can( 'edit_post', $id );
		}
		return $cache[ $id ];
	}

	private static function sanitize_layout( $layout ) {
		return in_array( $layout, array( 'default', 'full', 'canvas' ), true ) ? $layout : 'full';
	}

	/** طرح‌بندی ذخیره‌شده (پیش‌فرض: تمام‌عرض برای برگه‌ها، قالب برای نوشته‌ها) */
	private static function saved_layout( $id ) {
		$layout = (string) get_post_meta( $id, '_lsec_layout', true );
		if ( '' === $layout ) {
			return 'page' === get_post_type( $id ) ? 'full' : 'default';
		}
		return self::sanitize_layout( $layout );
	}

	/** طرح‌بندی که باید الان اعمال شود */
	private static function layout_for( $id ) {
		if ( self::live_valid( $id ) && isset( $_POST['kashi_live_layout'] ) ) {
			return self::sanitize_layout( sanitize_key( wp_unslash( $_POST['kashi_live_layout'] ) ) );
		}
		if ( '1' !== get_post_meta( $id, '_lsec_enabled', true ) ) {
			return 'default';
		}
		return self::saved_layout( $id );
	}

	private static function saved_box_width( $id ) {
		$w = absint( get_post_meta( $id, '_lsec_boxw', true ) );
		return $w ? min( max( $w, 320 ), 3000 ) : 1200;
	}

	private static function box_width_for( $id ) {
		if ( self::live_valid( $id ) && isset( $_POST['kashi_live_boxw'] ) ) {
			$w = absint( $_POST['kashi_live_boxw'] );
			return $w ? min( max( $w, 320 ), 3000 ) : 1200;
		}
		return self::saved_box_width( $id );
	}

	/* ------------------------------------------------------------------ *
	 * PHP اختصاصی: هر فایل .php داخل wp-content/kashi-php لود می‌شود.
	 * کد PHP هرگز از دیتابیس اجرا نمی‌شود (امنیت)؛ فقط از فایل.
	 * برای غیرفعال کردن: define( 'KASHI_DISABLE_PHP', true ); در wp-config.php
	 * ------------------------------------------------------------------ */
	private static function load_php_snippets() {
		if ( ( defined( 'KASHI_DISABLE_PHP' ) && KASHI_DISABLE_PHP ) || ( defined( 'LSEC_DISABLE_PHP' ) && LSEC_DISABLE_PHP ) ) {
			return;
		}
		$files = array();
		foreach ( array( 'kashi-php', 'lsec-php' ) as $folder ) { // lsec-php: سازگاری با نسخه قبل
			$dir = WP_CONTENT_DIR . '/' . $folder;
			if ( is_dir( $dir ) ) {
				$files = array_merge( $files, (array) glob( $dir . '/*.php' ) );
			}
		}
		if ( ! $files ) {
			return;
		}
		sort( $files );
		foreach ( $files as $file ) {
			if ( 'index.php' !== basename( $file ) ) {
				include_once $file;
			}
		}
	}

	/**
	 * جلوگیری از اجرای مستقیم فایل‌های پوشه PHP از مرورگر (Apache/LiteSpeed).
	 * فایل‌ها فقط از داخل وردپرس include می‌شوند.
	 */
	public static function guard_php_folders() {
		foreach ( array( 'kashi-php', 'lsec-php' ) as $folder ) {
			$dir = WP_CONTENT_DIR . '/' . $folder;
			if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
				continue;
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents(
					$dir . '/.htaccess',
					"# Kashi: no direct web access — files are loaded by WordPress only\n" .
					"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n" .
					"<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
				);
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * تاریخچه نسخه‌ها — در option جدا (autoload خاموش) تا روی سرعت سایت اثر نگذارد
	 * ------------------------------------------------------------------ */
	private static function get_revs( $id ) {
		$revs = get_option( 'lsec_revs_' . (int) $id, array() );
		return is_array( $revs ) ? $revs : array();
	}

	private static function snapshot( $id ) {
		$snap = array(
			'blocks' => self::get_blocks( $id ),
			'scope'  => self::scope_on( $id ),
			'layout' => self::saved_layout( $id ),
			'boxw'   => self::saved_box_width( $id ),
		);
		if ( self::CPT === get_post_type( $id ) ) {
			$snap['hf'] = self::hf_meta( $id );
		}
		return $snap;
	}

	private static function push_rev( $id, $snap, $time = null, $user = null, $trusted = null ) {
		if ( null === $trusted ) {
			$trusted = current_user_can( 'unfiltered_html' ) && ! self::post_untrusted( $id );
		}
		$revs = self::get_revs( $id );
		$hash = md5( wp_json_encode( $snap ) );
		if ( $revs && isset( $revs[0]['hash'] ) && $revs[0]['hash'] === $hash ) {
			return; // بدون تغییر
		}
		if ( null === $user ) {
			$u    = wp_get_current_user();
			$user = ( $u && $u->exists() ) ? $u->display_name : '';
		}
		array_unshift(
			$revs,
			array(
				't'    => $time ? (int) $time : time(),
				'user' => (string) $user,
				'hash' => $hash,
				'n'       => count( $snap['blocks'] ),
				'trusted' => (bool) $trusted,
				'data'    => $snap,
			)
		);
		$revs = array_slice( $revs, 0, self::MAX_REVS );
		// سقف حجم تاریخچه (~۲ مگابایت) تا ذخیره در دیتابیس شکست نخورد
		while ( count( $revs ) > 1 && strlen( maybe_serialize( $revs ) ) > 2 * MB_IN_BYTES ) {
			array_pop( $revs );
		}
		update_option( 'lsec_revs_' . (int) $id, $revs, false );
	}

	private static function revs_meta( $id ) {
		$out = array();
		foreach ( self::get_revs( $id ) as $i => $r ) {
			$t     = isset( $r['t'] ) ? (int) $r['t'] : 0;
			$out[] = array(
				'i'    => $i,
				'when' => $t ? wp_date( 'Y/m/d H:i', $t ) : '',
				'ago'  => $t ? human_time_diff( $t, time() ) . ' پیش' : '',
				'user' => isset( $r['user'] ) ? $r['user'] : '',
				'n'    => isset( $r['n'] ) ? (int) $r['n'] : 0,
			);
		}
		return $out;
	}

	public static function delete_revs( $post_id ) {
		delete_option( 'lsec_revs_' . (int) $post_id );
	}

	/** افزونه سئوی فعال (برای پیام‌های راهنما) */
	private static function seo_plugin_name() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}
		return '';
	}

	/** آیکون منوی پیشخوان: یک کاشی ساده */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="black" stroke-width="1.6" stroke-linejoin="round">'
			. '<rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.5"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.5"/>'
			. '<rect x="2.5" y="11" width="6.5" height="6.5" rx="1.5"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.5" fill="black"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/* ------------------------------------------------------------------ *
	 * آیکون‌های خطی مینیمال (سبک Lucide، داخل خود پلاگین — بدون فایل خارجی)
	 * ------------------------------------------------------------------ */
	private static function print_icon_sprite() {
		echo '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">'
			. '<symbol id="lsi-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol><symbol id="lsi-sliders" viewBox="0 0 24 24"><path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></symbol><symbol id="lsi-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></symbol><symbol id="lsi-gauge" viewBox="0 0 24 24"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></symbol><symbol id="lsi-monitor" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></symbol><symbol id="lsi-tablet" viewBox="0 0 24 24"><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M12 18h.01"/></symbol><symbol id="lsi-phone" viewBox="0 0 24 24"><rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/></symbol><symbol id="lsi-refresh" viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></symbol><symbol id="lsi-external" viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol><symbol id="lsi-save" viewBox="0 0 24 24"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></symbol><symbol id="lsi-layers" viewBox="0 0 24 24"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></symbol><symbol id="lsi-plus" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5v14"/></symbol><symbol id="lsi-up" viewBox="0 0 24 24"><path d="m18 15-6-6-6 6"/></symbol><symbol id="lsi-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol><symbol id="lsi-copy" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol><symbol id="lsi-bookmark" viewBox="0 0 24 24"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/><path d="M12 7v6"/><path d="M15 10H9"/></symbol><symbol id="lsi-trash" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></symbol><symbol id="lsi-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></symbol><symbol id="lsi-alert" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></symbol><symbol id="lsi-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></symbol><symbol id="lsi-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></symbol><symbol id="lsi-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></symbol><symbol id="lsi-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol><symbol id="lsi-code" viewBox="0 0 24 24"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></symbol><symbol id="lsi-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol><symbol id="lsi-width" viewBox="0 0 24 24"><path d="m18 8 4 4-4 4"/><path d="M2 12h20"/><path d="m6 8-4 4 4 4"/></symbol><symbol id="lsi-undo" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></symbol><symbol id="lsi-layout-full" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 8h18"/><path d="M3 16h18"/></symbol><symbol id="lsi-layout-canvas" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 12h8"/></symbol><symbol id="lsi-panel-top" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/></symbol><symbol id="lsi-panel-bottom" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 15h18"/></symbol><symbol id="lsi-pen" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.38 3.62a1 1 0 0 1 3 3L7.37 18.64a2 2 0 0 1-.86.5l-2.87.84a.5.5 0 0 1-.62-.62l.84-2.87a2 2 0 0 1 .5-.86z"/></symbol><symbol id="lsi-image" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/></symbol><symbol id="lsi-layout-default" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 8h18"/><path d="M15 8v13"/></symbol><symbol id="lsi-shapes" viewBox="0 0 24 24"><circle cx="7" cy="7" r="4"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><path d="M7 13.5 11 20H3z"/><circle cx="17" cy="17" r="4"/></symbol><symbol id="lsi-drop" viewBox="0 0 24 24"><path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/></symbol><symbol id="lsi-laptop" viewBox="0 0 24 24"><path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v9H4z"/><path d="M2 19h20l-2-4H4z"/></symbol><symbol id="lsi-zap" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></symbol>'
			. '</svg>';
	}

	private static function icon( $name ) {
		echo '<svg class="ic" aria-hidden="true"><use href="#lsi-' . esc_attr( $name ) . '"></use></svg>';
	}

	/** بخش‌های ذخیره‌شده‌ی یک برگه */
	private static function get_blocks( $id ) {
		$blocks = get_post_meta( $id, '_lsec_blocks', true );
		if ( is_array( $blocks ) ) {
			return self::normalize_blocks( $blocks );
		}
		// سازگاری با نسخه ۰.۳ (یک کد واحد)
		$old = (string) get_post_meta( $id, '_lsec_code', true );
		if ( '' !== $old ) {
			return self::normalize_blocks(
				array(
					array(
						'name' => 'بخش ۱',
						'code' => $old,
						'full' => '1' === get_post_meta( $id, '_lsec_full', true ),
					),
				)
			);
		}
		return array();
	}

	/** دستگاه‌های نمایش: d=دسکتاپ l=لپ‌تاپ t=تبلت m=موبایل؛ خالی (یا همه) = همه‌جا (ریسپانسیو) */
	const DEVICES = array(
		'd' => '(min-width:1440px)',
		'l' => '(min-width:1025px) and (max-width:1439.98px)',
		't' => '(min-width:768px) and (max-width:1024.98px)',
		'm' => '(max-width:767.98px)',
	);

	private static function norm_dev( $dev ) {
		$dev = array_values( array_intersect( array_keys( self::DEVICES ), array_map( 'strval', (array) $dev ) ) );
		return count( $dev ) === count( self::DEVICES ) ? array() : $dev;
	}

	/** CSS پنهان‌کردن کاشی در دستگاه‌هایی که انتخاب نشده‌اند */
	private static function device_css( $uid, $dev ) {
		$dev = self::norm_dev( $dev );
		if ( ! $dev ) {
			return '';
		}
		$css = '';
		foreach ( self::DEVICES as $k => $mq ) {
			if ( ! in_array( $k, $dev, true ) ) {
				$css .= '@media ' . $mq . '{.kashi-b-' . $uid . '{display:none!important}}';
			}
		}
		return $css;
	}

	private static function normalize_blocks( $raw ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$uid = isset( $b['uid'] ) ? strtolower( substr( preg_replace( '/[^a-z0-9]/i', '', (string) $b['uid'] ), 0, 12 ) ) : '';
			if ( '' === $uid || isset( $seen[ $uid ] ) ) {
				$uid = strtolower( wp_generate_password( 6, false ) );
			}
			$seen[ $uid ] = true;
			$out[]        = array(
				'uid'  => $uid,
				'name' => isset( $b['name'] ) ? sanitize_text_field( (string) $b['name'] ) : '',
				'code' => isset( $b['code'] ) ? (string) $b['code'] : '',
				'full' => ! empty( $b['full'] ),
				'dev'  => self::norm_dev( isset( $b['dev'] ) ? $b['dev'] : array() ),
			);
		}
		return $out;
	}

	private static function blocks_from_json( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? self::normalize_blocks( $data ) : array();
	}

	/* ------------------------------------------------------------------ *
	 * مدل اعتماد (امنیت)
	 * کدی که کاربرِ بدون دسترسی unfiltered_html (نویسنده، همکار، ویرایشگر در
	 * مولتی‌سایت) ذخیره کرده، «نامطمئن» است: هنگام ذخیره پاک‌سازی می‌شود و هر
	 * بار که وارد یک محیط مطمئن شود (ویرایشگر مدیر، تاریخچه، کتابخانه) دوباره
	 * پاک‌سازی می‌شود تا هرگز با دسترسی مدیر اجرا نشود.
	 * ------------------------------------------------------------------ */

	/** آیا محتوای ذخیره‌شده این برگه/کاشی از منبع نامطمئن است؟ */
	private static function post_untrusted( $id ) {
		$flag = get_post_meta( $id, '_lsec_untrusted', true );
		if ( '1' === $flag ) {
			return true;
		}
		if ( '0' === $flag ) {
			return false;
		}
		// داده‌های قدیمی (قبل از این نسخه): آخرین ویرایش‌کننده یا نویسنده
		$uid = (int) get_post_meta( $id, '_edit_last', true );
		if ( ! $uid ) {
			$uid = (int) get_post_field( 'post_author', $id );
		}
		return ! $uid || ! user_can( $uid, 'unfiltered_html' );
	}

	/** پاک‌سازی کد منبع برای کاربر نامطمئن: HTML با kses، CSS امن، بدون اسکریپت و PHP */
	private static function sanitize_source( $code ) {
		$code   = preg_replace( '#<\?.*?(?:\?>|\z)#s', '', (string) $code );
		$styles = array();
		$code   = preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style\s*>#is',
			function ( $m ) use ( &$styles ) {
				$styles[] = $m[1];
				return 'KASHISTYLE' . ( count( $styles ) - 1 ) . 'ELYTSIHSAK';
			},
			$code
		);
		$code = wp_kses_post( $code );
		return preg_replace_callback(
			'#KASHISTYLE(\d+)ELYTSIHSAK#',
			function ( $m ) use ( $styles ) {
				return isset( $styles[ (int) $m[1] ] ) ? '<style>' . self::css_untrusted( $styles[ (int) $m[1] ] ) . '</style>' : '';
			},
			$code
		);
	}

	private static function sanitize_blocks( $blocks ) {
		foreach ( $blocks as $k => $b ) {
			if ( isset( $b['code'] ) ) {
				$blocks[ $k ]['code'] = self::sanitize_source( $b['code'] );
			}
		}
		return $blocks;
	}

	/** CSS کاربر نامطمئن: بدون @import، بدون url() خارجی، بدون expression/behavior */
	private static function css_untrusted( $css ) {
		$css  = str_replace( '<', '', (string) $css );
		$css  = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css  = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css  = preg_replace( '/(expression\s*\(|behavior\s*:|-moz-binding\s*:|javascript\s*:)/i', '/*x*/', $css );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
			function ( $m ) use ( $host ) {
				$u = trim( $m[2] );
				if ( preg_match( '#^data:image/(png|jpe?g|gif|webp|avif);#i', $u ) ) {
					return $m[0];
				}
				if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $u ) ) {
					$uh = wp_parse_url( ( 0 === strpos( $u, '//' ) ? 'https:' : '' ) . $u, PHP_URL_HOST );
					return ( $uh && $host && strtolower( $uh ) === strtolower( $host ) && preg_match( '#^(https?:)?//#i', $u ) ) ? $m[0] : 'none';
				}
				return $m[0]; // آدرس نسبی
			},
			$css
		);
	}

	private static function strip_scripts( $code ) {
		return preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $code );
	}

	/** کامپایل همه بخش‌ها؛ هر بخش CSS محدود به خودش دارد */
	private static function compile_blocks( $blocks, $scope_on, $trusted ) {
		$out = array();
		foreach ( array_values( $blocks ) as $i => $b ) {
			$code      = $trusted ? $b['code'] : self::strip_scripts( $b['code'] );
			$c         = self::compile( $code, '.kashi-b-' . $b['uid'], $scope_on, $trusted );
			$dev       = self::norm_dev( isset( $b['dev'] ) ? $b['dev'] : array() );
			// تصویر LCP فقط برای کاشی اولی که در همه دستگاه‌ها دیده می‌شود؛ کاشی‌های مخصوص یک دستگاه
			// lazy می‌مانند تا تصویرشان در دستگاه‌های دیگر (که پنهان است) دانلود نشود
			$c['html'] = self::optimize_media( $c['html'], 0 === $i && ! $dev );
			$c['css'] .= self::device_css( $b['uid'], $dev );
			$out[]     = array(
				'uid'  => $b['uid'],
				'full' => (bool) $b['full'],
				'dev'  => $dev,
				'c'    => $c,
			);
		}
		return $out;
	}

	/**
	 * Core Web Vitals: اولین تصویر بخش اول (معمولاً هیرو = LCP) با اولویت بالا لود می‌شود؛
	 * بقیه تصاویر و iframe ها lazy می‌شوند. اگر خودتان این ویژگی‌ها را نوشته باشید، دست نمی‌خورد.
	 */
	private static function optimize_media( $html, $first_block ) {
		$n    = 0;
		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$n, $first_block ) {
				$tag = $m[0];
				$n++;
				if ( $first_block && 1 === $n ) {
					if ( ! preg_match( '/\sfetchpriority\s*=/i', $tag ) && ! preg_match( '/\sloading\s*=\s*["\']?lazy/i', $tag ) ) {
						$tag = preg_replace( '#^<img\b#i', '<img fetchpriority="high"', $tag );
					}
					return $tag;
				}
				if ( ! preg_match( '/\sloading\s*=/i', $tag ) ) {
					$tag = preg_replace( '#^<img\b#i', '<img loading="lazy"', $tag );
				}
				if ( ! preg_match( '/\sdecoding\s*=/i', $tag ) ) {
					$tag = preg_replace( '#^<img\b#i', '<img decoding="async"', $tag );
				}
				return $tag;
			},
			$html
		);
		return preg_replace_callback(
			'#<iframe\b[^>]*>#i',
			function ( $m ) {
				return preg_match( '/\sloading\s*=/i', $m[0] ) ? $m[0] : preg_replace( '#^<iframe\b#i', '<iframe loading="lazy"', $m[0] );
			},
			$html
		);
	}

	/**
	 * نسخه HTML تمیز (بدون CSS/JS) برای post_content تا Rank Math / Yoast، جستجوی سایت،
	 * خلاصه نوشته، فید و نقشه سایت تصاویر محتوا را ببینند. بخش‌های لینک‌شده باز می‌شوند.
	 */
	private static function seo_html( $compiled, $depth = 0 ) {
		$out = array();
		foreach ( (array) $compiled as $b ) {
			if ( ! is_array( $b ) || empty( $b['c']['html'] ) ) {
				continue;
			}
			$h = preg_replace_callback(
				'#\[(?:kashi|lsec)\s+id=["\']?(\d+)["\']?\s*\]#',
				function ( $m ) use ( $depth ) {
					$sid = (int) $m[1];
					if ( $depth >= 3 || self::CPT !== get_post_type( $sid ) ) {
						return '';
					}
					if ( 'publish' !== get_post_status( $sid ) && ! current_user_can( 'edit_post', $sid ) ) {
						return ''; // محتوای پیش‌نویس/خصوصی نباید به post_content عمومی نشت کند
					}
					$c = self::saved_compiled( $sid );
					return $c ? self::seo_html( $c, $depth + 1 ) : '';
				},
				$b['c']['html']
			);
			$h = trim( $h );
			if ( '' !== $h ) {
				$out[] = $h;
			}
		}
		return implode( "\n\n", $out );
	}

	/* ================================================================== *
	 * نوع پست «کاشی‌ها» (کتابخانه بخش‌های قابل استفاده مجدد با شورت‌کد)
	 * ================================================================== */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'کاشی‌ها',
					'singular_name' => 'کاشی',
					'add_new'       => 'افزودن کاشی',
					'add_new_item'  => 'افزودن کاشی جدید',
					'edit_item'     => 'ویرایش کاشی',
					'all_items'     => 'کتابخانه کاشی',
					'menu_name'     => 'کاشی',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => self::menu_icon(),
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function admin_menu() {
		global $submenu;
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$submenu[ 'edit.php?post_type=' . self::CPT ][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			'کاشی جدید (ویرایشگر زنده)',
			'edit_pages',
			admin_url( 'admin-post.php?action=kashi_new&_wpnonce=' . wp_create_nonce( 'kashi_new' ) ),
		);
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			'هدر و فوتر',
			'هدر و فوتر',
			'edit_pages',
			'kashi-hf',
			array( __CLASS__, 'hf_admin_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			'انتقال کاشی (خروجی / ورودی)',
			'خروجی / ورودی',
			'edit_pages',
			'kashi-transfer',
			array( __CLASS__, 'transfer_page' )
		);
	}

	/* ================================================================== *
	 * انتقال بین سایت‌ها: خروجی و ورودی (فایل JSON)
	 * ================================================================== */

	const EXPORT_FORMAT = 1;

	/** همه چیزهایی که با کاشی ساخته شده‌اند و کاربر اجازه ویرایششان را دارد */
	private static function transfer_candidates() {
		$tiles = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$pages = get_posts(
			array(
				'post_type'      => array_values( array_diff( self::post_types(), array( self::CPT ) ) ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => 500,
				'meta_key'       => '_lsec_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$can = function ( $p ) {
			return current_user_can( 'edit_post', $p->ID );
		};
		return array(
			'tiles' => array_values( array_filter( $tiles, $can ) ),
			'pages' => array_values( array_filter( $pages, $can ) ),
		);
	}

	public static function transfer_page() {
		$c = self::transfer_candidates();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res = isset( $_GET['kashi_imported'] ) ? get_transient( 'kashi_import_result_' . get_current_user_id() ) : false;
		?>
		<div class="wrap kashi-transfer">
			<style>
				.kashi-transfer .kt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;max-width:1000px;margin-top:16px}
				.kashi-transfer .kt-card{background:#fff;border:1px solid #e2e4e9;border-radius:12px;padding:22px}
				.kashi-transfer .kt-card h2{margin:0 0 6px;font-size:16px}
				.kashi-transfer .kt-card > p{color:#646970;margin:0 0 14px}
				.kashi-transfer .kt-list{max-height:340px;overflow:auto;border:1px solid #f0f0f1;border-radius:8px;padding:6px 10px;margin-bottom:14px}
				.kashi-transfer .kt-list h4{margin:10px 0 4px;color:#50575e}
				.kashi-transfer .kt-list label{display:flex;gap:8px;align-items:center;padding:4px 0}
				.kashi-transfer .kt-tag{font-size:11px;padding:1px 8px;border-radius:99px;background:#f0f0f1;color:#50575e}
				.kashi-transfer .kt-warn{background:#fcf9e8;border:1px solid #f0e2a8;border-radius:8px;padding:10px 12px;color:#6b5600;margin:12px 0}
			</style>
			<h1>انتقال کاشی بین سایت‌ها</h1>
			<?php if ( is_array( $res ) ) : ?>
				<div class="notice notice-<?php echo empty( $res['error'] ) ? 'success' : 'error'; ?> is-dismissible"><p><b>کاشی:</b> <?php echo esc_html( empty( $res['error'] ) ? $res['msg'] : $res['error'] ); ?></p></div>
			<?php endif; ?>
			<div class="kt-grid">
				<div class="kt-card">
					<h2>خروجی</h2>
					<p>کاشی‌ها، هدر/فوترها و صفحه‌هایی را که با کاشی ساخته‌اید در یک فایل ذخیره کنید.</p>
					<?php if ( ! $c['tiles'] && ! $c['pages'] ) : ?>
						<p>هنوز چیزی با کاشی ساخته نشده است.</p>
					<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="kashi_export">
						<?php wp_nonce_field( 'kashi_export' ); ?>
						<label style="display:block;margin-bottom:8px"><input type="checkbox" onclick="document.querySelectorAll('.kt-item').forEach(function(x){x.checked=this.checked}.bind(this))" checked> انتخاب همه</label>
						<div class="kt-list">
							<?php if ( $c['tiles'] ) : ?><h4>کاشی‌ها و هدر/فوتر</h4><?php endif; ?>
							<?php
							foreach ( $c['tiles'] as $t ) :
								$loc = get_post_meta( $t->ID, '_lsec_location', true );
								?>
								<label><input type="checkbox" class="kt-item" name="ids[]" value="<?php echo (int) $t->ID; ?>" checked> <?php echo esc_html( $t->post_title ? $t->post_title : '#' . $t->ID ); ?>
								<?php if ( $loc ) : ?><span class="kt-tag"><?php echo 'header' === $loc ? 'هدر' : 'فوتر'; ?></span><?php endif; ?></label>
							<?php endforeach; ?>
							<?php if ( $c['pages'] ) : ?><h4>صفحه‌ها</h4><?php endif; ?>
							<?php foreach ( $c['pages'] as $pg ) : ?>
								<label><input type="checkbox" class="kt-item" name="ids[]" value="<?php echo (int) $pg->ID; ?>" checked> <?php echo esc_html( $pg->post_title ? $pg->post_title : '#' . $pg->ID ); ?> <span class="kt-tag"><?php echo esc_html( get_post_type_object( $pg->post_type ) ? get_post_type_object( $pg->post_type )->labels->singular_name : $pg->post_type ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<?php submit_button( 'دانلود فایل خروجی', 'primary', 'submit', false ); ?>
					</form>
					<?php endif; ?>
				</div>
				<div class="kt-card">
					<h2>ورودی</h2>
					<p>فایل خروجی سایت دیگر را انتخاب کنید. همه موارد به‌صورت <b>جدید</b> ساخته می‌شوند و چیزی در این سایت جایگزین نمی‌شود.</p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="kashi_import">
						<?php wp_nonce_field( 'kashi_import' ); ?>
						<p><input type="file" name="kashi_file" accept=".json,application/json" required></p>
						<div class="kt-warn">
							• صفحه‌ها و هدر/فوترها به‌صورت <b>پیش‌نویس</b> وارد می‌شوند تا قبل از نمایش بررسی کنید.<br>
							• شرط‌های «فقط این صفحه‌ها» به «کل سایت» برمی‌گردد (شناسه صفحه‌ها در هر سایت فرق دارد).<br>
							• تصاویر از آدرس سایت قبلی لود می‌شوند؛ در صورت نیاز با «ویرایش مستقیم» عوضشان کنید.<br>
							• فقط فایل‌هایی را وارد کنید که خودتان ساخته‌اید؛ فایل حاوی کد است.
							<?php if ( ! current_user_can( 'unfiltered_html' ) ) : ?><br>• حساب شما اجازه اسکریپت ندارد؛ کدها هنگام ورود پاک‌سازی می‌شوند.<?php endif; ?>
						</div>
						<?php submit_button( 'ورود فایل', 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/** ساخت فایل خروجی */
	public static function export_build( $ids ) {
		$items = array();
		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $id ) {
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$is_tile = ( self::CPT === $post->post_type );
			if ( ! $is_tile && '1' !== get_post_meta( $id, '_lsec_enabled', true ) ) {
				continue;
			}
			$blocks = self::get_blocks( $id );
			if ( self::post_untrusted( $id ) ) {
				$blocks = self::sanitize_blocks( $blocks );
			}
			$item = array(
				'kind'      => $is_tile ? 'tile' : 'page',
				'post_type' => $post->post_type,
				'id'        => (int) $id,
				'title'     => $post->post_title,
				'blocks'    => array_map(
					function ( $b ) {
						return array(
							'uid'  => $b['uid'],
							'name' => $b['name'],
							'code' => $b['code'],
							'full' => (bool) $b['full'],
							'dev'  => isset( $b['dev'] ) ? $b['dev'] : array(),
						);
					},
					$blocks
				),
				'scope'     => self::scope_on( $id ),
			);
			if ( $is_tile ) {
				$item['hf'] = self::hf_meta( $id );
			} else {
				$item['layout'] = self::saved_layout( $id );
				$item['boxw']   = self::saved_box_width( $id );
			}
			$items[] = $item;
		}
		// کاشی‌ها اول، تا صفحه‌هایی که به آن‌ها لینک دارند بعداً درست وصل شوند
		usort(
			$items,
			function ( $a, $b ) {
				return ( 'tile' === $a['kind'] ? 0 : 1 ) - ( 'tile' === $b['kind'] ? 0 : 1 );
			}
		);
		return array(
			'kashi_export' => self::EXPORT_FORMAT,
			'version'      => self::VERSION,
			'site'         => home_url( '/' ),
			'date'         => gmdate( 'c' ),
			'items'        => $items,
		);
	}

	public static function export_download() {
		check_admin_referer( 'kashi_export' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$ids  = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint در export_build
		$data = self::export_build( $ids );
		if ( ! $data['items'] ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::CPT . '&page=kashi-transfer' ) );
			exit;
		}
		$host = preg_replace( '/[^a-z0-9.-]/i', '', (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kashi-' . $host . '-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** ذخیره بخش‌ها روی یک پست (مشترک بین ذخیره عادی و ورود فایل) */
	private static function store_blocks( $id, $blocks, $scope_on, $trusted ) {
		if ( ! $trusted ) {
			$blocks = self::sanitize_blocks( $blocks );
		}
		$compiled = self::compile_blocks( $blocks, $scope_on, $trusted );
		update_post_meta( $id, '_lsec_untrusted', $trusted ? '0' : '1' );
		update_post_meta( $id, '_lsec_blocks', wp_slash( $blocks ) );
		update_post_meta( $id, '_lsec_scope', $scope_on ? '1' : '0' );
		update_post_meta( $id, '_lsec_compiled', wp_slash( $compiled ) );
		return $compiled;
	}

	/**
	 * ورود: همه چیز «جدید» ساخته می‌شود. ارجاع‌های [kashi id] به شناسه‌های جدید وصل می‌شوند.
	 * @return array|WP_Error array( 'tiles' => n, 'pages' => n )
	 */
	public static function import_data( $data ) {
		if ( ! is_array( $data ) || empty( $data['kashi_export'] ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'kashi_import_format', 'این فایل، خروجی کاشی نیست.' );
		}
		if ( (int) $data['kashi_export'] > self::EXPORT_FORMAT ) {
			return new WP_Error( 'kashi_import_version', 'این فایل با نسخه جدیدتر کاشی ساخته شده؛ اول کاشی این سایت را به‌روز کنید.' );
		}
		if ( count( $data['items'] ) > 500 ) {
			return new WP_Error( 'kashi_import_size', 'تعداد موارد فایل بیش از حد مجاز (۵۰۰) است.' );
		}
		$trusted = current_user_can( 'unfiltered_html' );
		$publish = current_user_can( 'publish_pages' );
		$map     = array();
		$done    = array(
			'tiles' => 0,
			'pages' => 0,
		);
		$created = array();

		foreach ( array( 'tile', 'page' ) as $pass ) {
			foreach ( $data['items'] as $it ) {
				if ( ! is_array( $it ) || ! isset( $it['kind'] ) || $pass !== $it['kind'] ) {
					continue;
				}
				$blocks = self::normalize_blocks( isset( $it['blocks'] ) ? (array) $it['blocks'] : array() );
				foreach ( $blocks as $k => $b ) {
					$blocks[ $k ]['code'] = preg_replace_callback(
						'#\[(kashi|lsec)(\s+id=["\']?)(\d+)(["\']?)#',
						function ( $m ) use ( &$map ) {
							return isset( $map[ (int) $m[3] ] ) ? '[kashi' . $m[2] . $map[ (int) $m[3] ] . $m[4] : $m[0];
						},
						$b['code']
					);
				}
				$title = isset( $it['title'] ) ? sanitize_text_field( (string) $it['title'] ) : '';
				$scope = ! empty( $it['scope'] );

				if ( 'tile' === $pass ) {
					$hf     = isset( $it['hf'] ) && is_array( $it['hf'] ) ? $it['hf'] : array();
					$loc    = isset( $hf['location'] ) && in_array( $hf['location'], array( 'header', 'footer' ), true ) ? $hf['location'] : '';
					$status = ( $loc || ! $publish ) ? 'draft' : 'publish'; // هدر/فوتر وارد شده سایت را ناگهان عوض نکند
					$type   = self::CPT;
					if ( ! current_user_can( 'edit_pages' ) ) {
						continue;
					}
				} else {
					$type = ( isset( $it['post_type'] ) && 'post' === $it['post_type'] ) ? 'post' : 'page';
					$pto  = get_post_type_object( $type );
					if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
						continue;
					}
					$status = 'draft';
				}

				$new_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'   => $type,
							'post_status' => $status,
							'post_title'  => '' !== $title ? $title : 'کاشی وارد شده',
						)
					),
					true
				);
				if ( is_wp_error( $new_id ) || ! $new_id ) {
					continue;
				}
				$compiled = self::store_blocks( $new_id, $blocks, $scope, $trusted );

				if ( 'tile' === $pass ) {
					if ( isset( $it['id'] ) ) {
						$map[ (int) $it['id'] ] = (int) $new_id;
					}
					if ( $loc ) {
						$mode = isset( $hf['mode'] ) && in_array( $hf['mode'], array( 'all', 'front' ), true ) ? $hf['mode'] : 'all';
						update_post_meta( $new_id, '_lsec_location', $loc );
						update_post_meta( $new_id, '_lsec_cond', array( 'mode' => $mode, 'ids' => array() ) );
						update_post_meta( $new_id, '_lsec_target', self::sanitize_target( isset( $hf['target'] ) ? $hf['target'] : '' ) );
					}
					$done['tiles']++;
				} else {
					update_post_meta( $new_id, '_lsec_enabled', '1' );
					update_post_meta( $new_id, '_lsec_layout', self::sanitize_layout( isset( $it['layout'] ) ? sanitize_key( $it['layout'] ) : 'full' ) );
					$w = isset( $it['boxw'] ) ? absint( $it['boxw'] ) : 1200;
					update_post_meta( $new_id, '_lsec_boxw', $w ? min( max( $w, 320 ), 3000 ) : 1200 );
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $new_id,
								'post_content' => self::seo_html( $compiled ),
							)
						)
					);
					$done['pages']++;
				}
				self::push_rev( $new_id, self::snapshot( $new_id ), null, 'ورود از فایل', $trusted );
				$created[] = (int) $new_id;
			}
		}
		self::hf_rebuild_index();
		$done['ids'] = $created;
		return $done;
	}

	public static function import_upload() {
		check_admin_referer( 'kashi_import' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$back = admin_url( 'edit.php?post_type=' . self::CPT . '&page=kashi-transfer&kashi_imported=1' );
		$key  = 'kashi_import_result_' . get_current_user_id();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$f = isset( $_FILES['kashi_file'] ) ? $_FILES['kashi_file'] : null;
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			set_transient( $key, array( 'error' => 'فایلی دریافت نشد.' ), 300 );
			wp_safe_redirect( $back );
			exit;
		}
		if ( (int) $f['size'] > 20 * MB_IN_BYTES ) {
			set_transient( $key, array( 'error' => 'حجم فایل بیش از ۲۰ مگابایت است.' ), 300 );
			wp_safe_redirect( $back );
			exit;
		}
		$json = file_get_contents( $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// phpcs:enable
		$res = self::import_data( json_decode( (string) $json, true ) );
		if ( is_wp_error( $res ) ) {
			set_transient( $key, array( 'error' => $res->get_error_message() ), 300 );
		} else {
			set_transient( $key, array( 'msg' => sprintf( '%d کاشی و %d صفحه وارد شد. صفحه‌ها و هدر/فوترها پیش‌نویس هستند؛ بعد از بررسی منتشرشان کنید.', $res['tiles'], $res['pages'] ) ), 300 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/** آدرس ساخت کاشی جدید (اختیاری: مستقیم به‌عنوان هدر یا فوتر) */
	private static function new_url( $loc = '' ) {
		$url = admin_url( 'admin-post.php?action=kashi_new' . ( $loc ? '&loc=' . $loc : '' ) );
		return wp_nonce_url( $url, 'kashi_new' );
	}

	/** صفحه «هدر و فوتر»: همه هدر/فوترها و ساخت سریع */
	public static function hf_admin_page() {
		$tiles = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'meta_key'       => '_lsec_location', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => array( 'header', 'footer' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare'   => 'IN',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$modes = array(
			'all'     => 'کل سایت',
			'front'   => 'فقط صفحه اصلی',
			'include' => 'فقط صفحه‌های: ',
			'exclude' => 'همه‌جا به‌جز: ',
		);
		?>
		<div class="wrap kashi-hfadmin">
			<style>
				.kashi-hfadmin .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:900px;margin:18px 0 26px}
				.kashi-hfadmin .card2{background:#fff;border:1px solid #e2e4e9;border-radius:12px;padding:20px;display:flex;gap:14px;align-items:flex-start}
				.kashi-hfadmin .card2 .ico{flex:0 0 44px;height:44px;border-radius:10px;background:#efeaff;display:flex;align-items:center;justify-content:center}
				.kashi-hfadmin .card2 h2{margin:0 0 4px;font-size:15px}
				.kashi-hfadmin .card2 p{margin:0 0 12px;color:#646970}
				.kashi-hfadmin table{max-width:900px}
				.kashi-hfadmin .pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;background:#f0f0f1}
				.kashi-hfadmin .pill.h{background:#efeaff;color:#4b36c8}.kashi-hfadmin .pill.f{background:#e7f6ee;color:#0a6b3c}
				.kashi-hfadmin .pill.d{background:#fcf0e3;color:#8a4b00}
			</style>
			<h1>هدر و فوتر</h1>
			<p>هدر و فوتر سایت را با کاشی بسازید؛ جای هدر و فوتر قالب را می‌گیرد و با هر قالبی کار می‌کند. می‌توانید برای صفحه‌های مختلف هدر/فوتر جدا داشته باشید.</p>
			<div class="cards">
				<div class="card2">
					<div class="ico"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#6b5af2" stroke-width="1.8"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/></svg></div>
					<div><h2>هدر جدید</h2><p>بالای همه صفحه‌ها، به‌جای هدر قالب.</p>
					<a class="button button-primary" href="<?php echo esc_url( self::new_url( 'header' ) ); ?>">ساخت هدر</a></div>
				</div>
				<div class="card2">
					<div class="ico" style="background:#e7f6ee"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0a6b3c" stroke-width="1.8"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 15h18"/></svg></div>
					<div><h2>فوتر جدید</h2><p>پایین همه صفحه‌ها، به‌جای فوتر قالب.</p>
					<a class="button button-primary" href="<?php echo esc_url( self::new_url( 'footer' ) ); ?>">ساخت فوتر</a></div>
				</div>
			</div>
			<h2>هدر و فوترهای شما</h2>
			<?php if ( ! $tiles ) : ?>
				<p>هنوز هدر یا فوتری نساخته‌اید. با دکمه‌های بالا شروع کنید.</p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>نام</th><th>محل</th><th>کجا نمایش داده می‌شود</th><th>وضعیت</th><th></th></tr></thead>
				<tbody>
				<?php
				foreach ( $tiles as $t ) :
					$m    = self::hf_meta( $t->ID );
					$cond = isset( $modes[ $m['mode'] ] ) ? $modes[ $m['mode'] ] : $modes['all'];
					if ( in_array( $m['mode'], array( 'include', 'exclude' ), true ) ) {
						$cond .= implode( '، ', array_map( 'intval', $m['ids'] ) );
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $t->post_title ? $t->post_title : '(بدون نام)' ); ?></strong></td>
						<td><?php echo 'header' === $m['location'] ? '<span class="pill h">هدر</span>' : '<span class="pill f">فوتر</span>'; ?></td>
						<td><?php echo esc_html( $cond ); ?></td>
						<td><?php echo 'publish' === $t->post_status ? '<span class="pill">فعال</span>' : '<span class="pill d">پیش‌نویس — هنوز نمایش داده نمی‌شود</span>'; ?></td>
						<td><a class="button" href="<?php echo esc_url( self::editor_url( $t->ID ) ); ?>">ویرایش با کاشی</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<p class="description" style="margin-top:16px">نکته: اگر هدر/فوتر قالب‌تان با المنتور یا صفحه‌ساز دیگری ساخته شده، آن را در تنظیمات قالب خاموش کنید تا فایل‌هایش بیهوده لود نشوند. اگر قالب تگ &lt;header&gt; ندارد، در ویرایشگر کاشی ← «محل نمایش» ← «المان قالب» شناسه آن را بنویسید (مثلاً #masthead).</p>
		</div>
		<?php
	}

	public static function new_section() {
		check_admin_referer( 'kashi_new' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$loc = isset( $_GET['loc'] ) ? sanitize_key( wp_unslash( $_GET['loc'] ) ) : '';
		$loc = in_array( $loc, array( 'header', 'footer' ), true ) ? $loc : '';
		$id  = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
				'post_title'  => 'header' === $loc ? 'هدر سایت' : ( 'footer' === $loc ? 'فوتر سایت' : 'کاشی جدید' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		if ( $loc ) {
			update_post_meta( $id, '_lsec_location', $loc );
			update_post_meta( $id, '_lsec_cond', array( 'mode' => 'all', 'ids' => array() ) );
		}
		wp_safe_redirect( self::editor_url( $id ) );
		exit;
	}

	/* ================================================================== *
	 * دکمه‌ها در پیشخوان
	 * ================================================================== */
	public static function row_actions( $actions, $post ) {
		if ( in_array( $post->post_type, self::post_types(), true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions = array( 'kashi_edit' => '<a href="' . esc_url( self::editor_url( $post->ID ) ) . '" style="font-weight:600">ویرایش با کاشی</a>' ) + $actions;
		}
		return $actions;
	}

	public static function post_states( $states, $post ) {
		if ( self::CPT === $post->post_type ) {
			$loc = get_post_meta( $post->ID, '_lsec_location', true );
			if ( 'header' === $loc ) {
				$states['kashi_hf'] = 'هدر سایت';
			} elseif ( 'footer' === $loc ) {
				$states['kashi_hf'] = 'فوتر سایت';
			}
			return $states;
		}
		if ( self::CPT !== $post->post_type && '1' === get_post_meta( $post->ID, '_lsec_enabled', true ) ) {
			$states['kashi'] = 'کاشی';
		}
		return $states;
	}

	public static function add_meta_boxes() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'kashi_box', 'کاشی', array( __CLASS__, 'render_meta_box' ), $type, self::CPT === $type ? 'normal' : 'side', 'high' );
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'kashi_box', 'kashi_box_nonce' );

		echo '<p><a class="button button-primary button-large" style="width:100%;text-align:center" href="' . esc_url( self::editor_url( $post->ID ) ) . '">ویرایش با کاشی</a></p>';

		if ( self::CPT === $post->post_type ) {
			echo '<p>شورت‌کد: <code>[kashi id="' . (int) $post->ID . '"]</code></p>';
			return;
		}

		if ( self::get_blocks( $post->ID ) ) {
			$enabled = ( '1' === get_post_meta( $post->ID, '_lsec_enabled', true ) );
			echo '<p><label><input type="checkbox" name="kashi_enabled" value="1" ' . checked( $enabled, true, false ) . '> نمایش محتوای کاشی به‌جای محتوای این ویرایشگر</label></p>';
			echo '<input type="hidden" name="kashi_enabled_present" value="1">';
			echo '<p class="description">متن این برگه با هر ذخیره در کاشی خودکار در محتوای اصلی هم کپی می‌شود تا افزونه‌های سئو و جستجوی سایت آن را ببینند. ویرایش محتوا در این ویرایشگر روی نمایش سایت اثری ندارد.</p>';
		} else {
			echo '<p class="description">با زدن دکمه، ویرایشگر زنده برای این برگه باز می‌شود.</p>';
		}
	}

	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['kashi_box_nonce'], $_POST['kashi_enabled_present'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kashi_box_nonce'] ) ), 'kashi_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_lsec_enabled', empty( $_POST['kashi_enabled'] ) ? '0' : '1' );
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['kashi_shortcode'] = 'شورت‌کد';
			}
		}
		return $new;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'kashi_shortcode' === $column ) {
			printf(
				'<input type="text" readonly onclick="this.select()" style="direction:ltr;width:170px" value="%s">',
				esc_attr( '[kashi id="' . (int) $post_id . '"]' )
			);
		}
	}

	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id || ! in_array( get_post_type( $id ), self::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'kashi-edit',
				'title' => 'ویرایش با کاشی',
				'href'  => self::editor_url( $id ),
			)
		);
	}

	/* ================================================================== *
	 * ویرایشگر زنده (تمام‌صفحه)
	 * ================================================================== */
	public static function editor_page() {
		$id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'این مورد پیدا نشد یا اجازه ویرایش آن را ندارید.' );
		}

		$settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media(); // کتابخانه رسانه برای تعویض تصویر در ویرایش مستقیم
		}
		$back     = ( self::CPT === $post->post_type ) ? admin_url( 'edit.php?post_type=' . self::CPT ) : get_edit_post_link( $id, 'raw' );
		$view     = ( self::CPT === $post->post_type ) ? self::section_preview_url( $id ) : get_permalink( $post );

		$config = array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'postId'    => $id,
			'saveNonce' => wp_create_nonce( 'kashi_save_' . $id ),
			'settings'  => $settings,
			'blocks'    => ( self::post_untrusted( $id ) || ! current_user_can( 'unfiltered_html' ) ) ? self::sanitize_blocks( self::get_blocks( $id ) ) : self::get_blocks( $id ),
			'revs'      => self::revs_meta( $id ),
			'libNonce'  => wp_create_nonce( 'kashi_lib' ),
			'editBase'  => admin_url( 'admin-post.php?action=kashi_edit&post=' ),
			'isSection' => self::CPT === $post->post_type,
			'seoPlugin' => self::seo_plugin_name(),
			'icons'     => plugins_url( 'assets/icons.json', KASHI_FILE ) . '?v=' . self::VERSION,
		);
		$is_section = ( self::CPT === $post->post_type );
		$layout     = self::saved_layout( $id );
		$boxw       = self::saved_box_width( $id );

		nocache_headers();
		?><!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( 'ویرایش: ' . get_the_title( $post ) ); ?></title>
<?php wp_print_styles(); ?>
<style>
@font-face{font-family:"Vazirmatn";src:url(<?php echo esc_url( plugins_url( 'assets/fonts/vazirmatn.woff2', KASHI_FILE ) ); ?>) format("woff2");font-weight:100 900;font-display:swap}
:root{
	--bg:#0d0f14;--panel:#13161d;--panel2:#191d26;--hover:#20252f;--line:#232834;--line2:#2e3442;
	--text:#e6e8ee;--muted:#8b92a5;--dim:#80879a;
	--accent:#6b5af2;--accent-h:#5b4ae0;--accent-soft:rgba(124,108,255,.14);--accent-line:rgba(124,108,255,.45);
	--ok:#34c98a;--warn:#f0b13c;--err:#f0555c;--info:#5aa9ff;
	--r:8px;--font:"Vazirmatn","IRANSansX","IRANSans","Segoe UI",Tahoma,sans-serif;
}
*{box-sizing:border-box;scrollbar-width:thin;scrollbar-color:var(--line2) transparent}
html,body{margin:0;height:100%;overflow:hidden;font-family:var(--font);font-size:13px;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
button,input{font:inherit;color:inherit}
button{cursor:pointer}
.ic{width:16px;height:16px;flex:0 0 auto;fill:none;stroke:currentColor;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round}
kbd{font-family:inherit;font-size:10.5px;padding:1px 5px;border-radius:4px;background:rgba(255,255,255,.16);color:#e8e6ff;direction:ltr}
:focus-visible{outline:2px solid var(--accent-line);outline-offset:1px}
input:focus-visible,textarea:focus-visible,select:focus-visible{outline:2px solid var(--accent-line)!important;outline-offset:0}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}

/* ---------- نوار بالا ---------- */
.kashi-bar{height:56px;display:flex;align-items:center;gap:6px;padding:0 12px;background:var(--panel);border-bottom:1px solid var(--line)}
.kashi-bar .g{display:flex;align-items:center;gap:4px;min-width:0}
.kashi-sep{flex:1}
.vr{width:1px;height:22px;background:var(--line2);margin:0 6px}
.ib,.tb{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;border:1px solid transparent;border-radius:var(--r);background:transparent;color:var(--muted);text-decoration:none;white-space:nowrap;transition:background .15s,color .15s,border-color .15s}
.ib{width:34px;padding:0}
.tb{padding:0 10px}
.ib:hover,.tb:hover{background:var(--hover);color:var(--text)}
.tb.on,.ib.on{background:var(--accent-soft);color:#fff;border-color:var(--accent-line)}
.ttl input{width:220px;height:34px;padding:0 10px;border:1px solid transparent;border-radius:var(--r);background:transparent;font-weight:600;font-size:14px;color:var(--text);transition:border-color .15s,background .15s}
.ttl input:hover{background:var(--panel2)}
.ttl input:focus{outline:0;background:var(--bg);border-color:var(--line2)}

.sw{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 8px;border-radius:var(--r);color:var(--muted);cursor:pointer;white-space:nowrap;user-select:none}
.sw:hover{color:var(--text)}
.sw input{position:absolute;opacity:0;pointer-events:none}
.sw i{position:relative;width:28px;height:16px;border-radius:99px;background:var(--line2);transition:background .2s}
.sw i::after{content:"";position:absolute;top:2px;right:2px;width:12px;height:12px;border-radius:50%;background:#fff;transition:transform .2s}
.sw input:checked+i{background:var(--accent)}
.sw input:checked+i::after{transform:translateX(-12px)}
.sw input:focus-visible+i{box-shadow:0 0 0 2px var(--accent-line)}
.tog{position:relative;cursor:pointer}
.tog input{position:absolute;inset:0;margin:0;opacity:0;cursor:pointer}
.tog:has(input:checked){background:var(--accent-soft);color:#fff;border-color:var(--accent-line)}
.tog:has(input:focus-visible){outline:2px solid var(--accent-line);outline-offset:1px}
.kashi-settings .swrow{height:auto;white-space:normal;color:var(--text);border-radius:0;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 2px;margin-top:8px;border-top:1px solid var(--line)}
.kashi-settings .swrow i{flex:0 0 28px}.kashi-settings .swrow>span{flex:1;min-width:0}
.kashi-settings .swrow b{display:block;font-size:13px}.kashi-settings .swrow small{display:block;color:var(--muted);font-size:12px;line-height:1.7}

.seg{display:inline-flex;padding:3px;gap:2px;border-radius:var(--r);background:var(--bg);border:1px solid var(--line)}
.seg button{display:inline-flex;align-items:center;justify-content:center;width:32px;height:28px;border:0;border-radius:6px;background:transparent;color:var(--dim)}
.seg button:hover{color:var(--text)}
.seg button.on{background:var(--panel2);color:var(--text);box-shadow:0 1px 2px rgba(0,0,0,.4)}

.st{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:12px;min-width:96px;justify-content:flex-end}
.st::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--ok)}
.st.dirty::before{background:var(--warn)}
.st.busy::before{background:var(--info);animation:kashi-pulse 1s infinite}
.st.err{color:var(--err)}.st.err::before{background:var(--err)}
@keyframes kashi-pulse{50%{opacity:.3}}

.primary{display:inline-flex;align-items:center;gap:8px;height:34px;padding:0 14px;border:0;border-radius:var(--r);background:var(--accent);color:#fff;font-weight:600;transition:background .15s}
.primary:hover{background:var(--accent-h)}

.kashi-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:99px;font-size:11px;font-weight:700;line-height:1}
.kashi-badge .ic{width:12px;height:12px;stroke-width:2.5}
.kashi-badge.bad{background:var(--err);color:#fff}.kashi-badge.warn{background:var(--warn);color:#1a1300}.kashi-badge.ok{background:rgba(52,201,138,.16);color:var(--ok)}

/* ---------- چیدمان ---------- */
.kashi-main{display:flex;height:calc(100% - 56px);direction:ltr}
.kashi-left{width:40%;min-width:300px;height:100%;display:flex;flex-direction:column;background:var(--panel)}
.kashi-div{position:relative;width:1px;flex:0 0 1px;cursor:col-resize;background:var(--line);transition:background .15s;z-index:5}
.kashi-div::before{content:"";position:absolute;top:0;bottom:0;left:-6px;right:-6px}
.kashi-div:hover,.kashi-div:focus-visible,.kashi-drag .kashi-div{background:var(--accent);box-shadow:0 0 0 1px var(--accent)}
.kashi-view{flex:1;height:100%;background:#0a0b0f;display:flex;justify-content:center;overflow:auto;padding:0}
.kashi-view{position:relative}
.kashi-view iframe{border:0;background:#fff;width:100%;height:100%;flex:0 0 auto;transform-origin:top center;box-shadow:0 0 0 1px var(--line)}
.kashi-view.scaled{overflow:hidden}
.kashi-view.scaled iframe{position:absolute;top:0;left:0;transform-origin:top left}
#kashi-zoom{position:absolute;bottom:10px;left:10px;z-index:3;direction:ltr;font-size:11px;color:var(--muted);background:rgba(13,15,20,.85);border:1px solid var(--line);border-radius:99px;padding:2px 9px;pointer-events:none}
.kashi-drag .kashi-view iframe{pointer-events:none}

/* ---------- لیست بخش‌ها ---------- */
.kashi-blocks{direction:rtl;max-height:44%;display:flex;flex-direction:column;border-bottom:1px solid var(--line)}
.kashi-blocks-head{display:flex;align-items:center;justify-content:space-between;padding:12px 12px 8px}
.kashi-blocks-head .t{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.kashi-blocks-head .t small{font-weight:500;color:var(--dim);background:var(--panel2);border-radius:99px;padding:1px 8px;font-size:11px}
.kashi-blocks-head .hb{display:flex;gap:6px}
.btn-s{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;border-radius:var(--r);border:1px solid var(--line2);background:transparent;color:var(--text);font-size:12px;transition:background .15s,border-color .15s}
.btn-s:hover{background:var(--hover)}
.btn-s.on{background:var(--accent-soft);border-color:var(--accent-line)}
.btn-s.acc{background:var(--accent-soft);border-color:var(--accent-line);color:#fff}
.btn-s.acc:hover{background:rgba(124,108,255,.24)}
#kashi-list{overflow:auto;padding:0 8px 10px}
.kashi-item{display:flex;align-items:center;gap:6px;height:40px;padding:0 6px;margin-bottom:3px;border-radius:var(--r);border:1px solid transparent;cursor:pointer;transition:background .12s,border-color .12s}
.kashi-item:hover{background:var(--panel2)}
.kashi-item.on{background:var(--accent-soft);border-color:var(--accent-line)}
.kashi-item .n{min-width:22px;height:22px;border-radius:6px;background:var(--panel2);color:var(--muted);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.kashi-item.on .n{background:var(--accent);color:#fff}
.kashi-item .lnk{display:inline-flex;color:var(--accent);padding:2px}
.kashi-item .lnk .ic{width:14px;height:14px}
.kashi-item .nm{flex:1;min-width:60px;height:28px;background:transparent;border:1px solid transparent;border-radius:6px;padding:0 6px;color:var(--text)}
.kashi-item .nm:focus{outline:0;border-color:var(--line2);background:var(--bg)}
.kashi-item .fw{display:inline-flex;cursor:pointer}
.kashi-item .dv{display:inline-flex;align-items:center;gap:2px;height:24px;padding:0 6px;border:1px solid var(--line2);border-radius:6px;background:transparent;color:var(--dim);cursor:pointer}
.kashi-item .dv .ic{width:13px;height:13px}
.kashi-item .dv:hover{color:var(--text)}
.kashi-item .dv.on{background:var(--accent-soft);border-color:var(--accent-line);color:#fff}
.kashi-dvpop{position:fixed;z-index:80;width:270px;direction:rtl;background:var(--panel);border:1px solid var(--line2);border-radius:12px;padding:12px;box-shadow:0 24px 60px -12px rgba(0,0,0,.7)}
.kashi-dvpop>b{display:block;font-size:13px;margin:0 2px 8px}
.kashi-dvpop .dvr{display:flex;justify-content:space-between;width:100%;height:auto;padding:7px 4px;color:var(--text);white-space:normal}
.kashi-dvpop .dvl{display:flex;align-items:center;gap:10px}
.kashi-dvpop .dvl small{display:block;color:var(--muted);font-size:11.5px}
.kashi-dvpop .dvr i{flex:0 0 28px}
.kashi-dvpop .hint{margin-top:8px;line-height:1.8}
.kashi-item .fw input{position:absolute;opacity:0;pointer-events:none}
.kashi-item .fw span{display:inline-flex;align-items:center;gap:4px;height:24px;padding:0 7px;border-radius:6px;font-size:11px;color:var(--dim);border:1px solid var(--line2);transition:all .15s}
.kashi-item .fw span .ic{width:13px;height:13px}
.kashi-item .fw input:checked+span{color:var(--text);border-color:var(--accent-line);background:var(--accent-soft)}
.kashi-item .acts{display:flex;gap:1px;opacity:0;transition:opacity .15s}
.kashi-item:hover .acts,.kashi-item.on .acts,.kashi-item:focus-within .acts{opacity:1}
.kashi-item .acts button{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:0;border-radius:6px;background:transparent;color:var(--muted)}
.kashi-item .acts button .ic{width:14px;height:14px}
.kashi-item .acts button:hover{background:var(--hover);color:var(--text)}
.kashi-item .acts button[data-a=del]:hover{background:rgba(240,85,92,.15);color:var(--err)}

#kashi-cur{direction:rtl;display:flex;align-items:center;gap:8px;height:34px;padding:0 12px;color:var(--muted);font-size:12px;background:var(--bg);border-bottom:1px solid var(--line)}
#kashi-cur .ic{color:var(--accent);width:14px;height:14px}
#kashi-cur b{color:var(--text);font-weight:600}
#kashi-cur .kind .kc{display:inline-block;margin:0 2px;padding:0 6px;border-radius:6px;background:var(--accent-soft);color:#e8e6ff;font-weight:600}
#kashi-cur .kind{margin-right:auto;font-size:11.5px;color:var(--muted);background:var(--panel2);border:1px solid var(--line);border-radius:99px;padding:2px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:60%}
#kashi-warn{direction:rtl;display:flex;gap:8px;align-items:flex-start;padding:8px 12px;background:rgba(240,85,92,.1);border-bottom:1px solid rgba(240,85,92,.25);color:#ffb3b6;font-size:12px;line-height:1.8}
#kashi-warn .ic{color:var(--err);margin-top:3px}
#kashi-warn code{background:rgba(0,0,0,.3);padding:1px 5px;border-radius:4px;direction:ltr;display:inline-block}

/* ---------- ویرایشگر کد (تم تیره) ---------- */
.kashi-code{flex:1;min-height:0;position:relative;background:var(--bg)}
.kashi-code textarea{width:100%;height:100%;border:0;background:var(--bg);color:var(--text);font-family:ui-monospace,"JetBrains Mono",Consolas,monospace;font-size:13px;padding:12px;direction:ltr;resize:none;outline:0}
.kashi-code .CodeMirror{height:100%;direction:ltr;text-align:left;background:var(--bg);color:#d4d8e2;font-family:ui-monospace,"JetBrains Mono",Consolas,monospace;font-size:13px;line-height:1.65}
.kashi-code .CodeMirror-gutters{background:var(--bg);border-right:1px solid var(--line)}
.kashi-code .CodeMirror-linenumber{color:var(--dim);padding:0 10px 0 6px}
.kashi-code .CodeMirror-cursor{border-left:2px solid var(--accent)}
.kashi-code .CodeMirror-selected,.kashi-code .CodeMirror-focused .CodeMirror-selected{background:rgba(124,108,255,.28)!important}
.kashi-code .CodeMirror-activeline-background{background:rgba(255,255,255,.035)}
.kashi-code .CodeMirror-matchingbracket{color:#fff!important;background:rgba(124,108,255,.35)}
.kashi-code .CodeMirror-matchingtag{background:rgba(124,108,255,.18)}
.kashi-code .cm-s-default .cm-tag{color:#f38ba8}
.kashi-code .cm-s-default .cm-attribute{color:#fab387}
.kashi-code .cm-s-default .cm-string,.kashi-code .cm-s-default .cm-string-2{color:#a6e3a1}
.kashi-code .cm-s-default .cm-comment{color:#6c7086;font-style:italic}
.kashi-code .cm-s-default .cm-keyword{color:#cba6f7}
.kashi-code .cm-s-default .cm-number,.kashi-code .cm-s-default .cm-atom{color:#fab387}
.kashi-code .cm-s-default .cm-property{color:#89b4fa}
.kashi-code .cm-s-default .cm-def,.kashi-code .cm-s-default .cm-variable-2{color:#89dceb}
.kashi-code .cm-s-default .cm-variable,.kashi-code .cm-s-default .cm-variable-3{color:#e6e8ee}
.kashi-code .cm-s-default .cm-qualifier,.kashi-code .cm-s-default .cm-builtin{color:#94e2d5}
.kashi-code .cm-s-default .cm-bracket,.kashi-code .cm-s-default .cm-meta{color:#9399b2}
.kashi-code .cm-s-default .cm-error{color:var(--err)}
.kashi-code .CodeMirror-lint-marker-error,.kashi-code .CodeMirror-lint-marker-warning{filter:saturate(.8)}

/* ---------- پنل‌های شناور ---------- */
.kashi-settings{position:absolute;top:62px;right:12px;width:340px;max-height:calc(100vh - 80px);overflow:auto;z-index:50;background:var(--panel);border:1px solid var(--line2);border-radius:12px;padding:16px;box-shadow:0 24px 60px -12px rgba(0,0,0,.7),0 0 0 1px rgba(0,0,0,.3);display:none;transform-origin:top right}
.kashi-settings.open{display:block;animation:kashi-in .16s ease-out}
@keyframes kashi-in{from{opacity:0;transform:translateY(-4px) scale(.98)}}
.kashi-settings h3{display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:14px;font-weight:700}
.kashi-settings h3 .ic{color:var(--accent)}
.kashi-settings .opt{display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:border-color .15s,background .15s}
.kashi-settings .opt:hover{border-color:var(--line2);background:var(--panel2)}
.kashi-settings .opt.on{border-color:var(--accent-line);background:var(--accent-soft)}
.kashi-settings .opt input{position:absolute;opacity:0;pointer-events:none}
.kashi-settings .opt .oi{flex:0 0 auto;width:36px;height:36px;border-radius:8px;background:var(--panel2);display:flex;align-items:center;justify-content:center;color:var(--muted)}
.kashi-settings .opt.on .oi{background:var(--accent);color:#fff}
.kashi-settings .opt .oi .ic{width:18px;height:18px}
.kashi-settings .opt b{display:block;margin-bottom:3px;font-weight:600}
.kashi-settings .opt small{color:var(--muted);line-height:1.7;font-size:12px}
.kashi-settings .row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
.kashi-settings input[type=number]{width:96px;height:32px;background:var(--bg);color:var(--text);border:1px solid var(--line2);border-radius:var(--r);padding:0 10px;direction:ltr}
.kashi-settings input[type=number]:focus{outline:0;border-color:var(--accent-line)}
.kashi-settings .hint{color:var(--muted);font-size:12px;margin:6px 0 2px;line-height:1.8}
.kashi-plist{margin-top:10px}
.kashi-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;margin-bottom:6px;transition:border-color .15s}
.kashi-row:hover{border-color:var(--line2)}
.kashi-row.cur{border-color:var(--accent-line);background:var(--accent-soft)}
.kashi-row b{font-weight:600}
.kashi-row small{display:block;color:var(--muted);margin-top:3px;font-size:11.5px}
.kashi-row .btns{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
.kashi-row button,.kashi-row a{display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 9px;border:1px solid var(--line2);border-radius:7px;background:transparent;color:var(--text);text-decoration:none;white-space:nowrap;font-size:12px;transition:background .15s,border-color .15s}
.kashi-row button .ic,.kashi-row a .ic{width:13px;height:13px}
.kashi-row button:hover,.kashi-row a:hover{background:var(--accent-soft);border-color:var(--accent-line)}
.kashi-search{position:relative;margin-bottom:8px}
.kashi-igrp{display:flex;gap:4px;margin:0 2px 8px;flex-wrap:wrap}
.kashi-igrp button{height:28px;padding:0 12px;border:1px solid var(--line2);border-radius:99px;background:transparent;color:var(--muted);font-size:12px;cursor:pointer}
.kashi-igrp button.on{background:var(--accent-soft);border-color:var(--accent-line);color:#fff}
.kashi-isz{display:flex;align-items:center;justify-content:space-between;margin:2px 2px 8px;color:var(--muted);font-size:12px}
.kashi-isz .seg button{width:auto;padding:0 8px;font-size:11.5px}
.kashi-igrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(52px,1fr));gap:4px;max-height:340px;overflow:auto;padding:2px}
.kashi-igrid button{display:flex;align-items:center;justify-content:center;height:52px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text);cursor:pointer;transition:background .12s,border-color .12s}
.kashi-igrid button:hover,.kashi-igrid button:focus-visible{background:var(--accent-soft);border-color:var(--accent-line)}
.kashi-igrid button svg{width:26px;height:26px}
#kashi-iname{min-height:20px;margin:6px 2px 0;color:var(--text);font-size:12.5px;font-weight:600}
.kashi-search .ic{position:absolute;top:9px;right:10px;color:var(--dim)}
.kashi-sel,.kashi-in{height:32px;background:var(--bg);color:var(--text);border:1px solid var(--line2);border-radius:var(--r);padding:0 10px;min-width:160px}
.kashi-in{direction:ltr;text-align:left;width:170px}
.kashi-sel:focus,.kashi-in:focus{outline:0;border-color:var(--accent-line)}
.kashi-settings .hint code{direction:ltr;display:inline-block;background:rgba(255,255,255,.06);padding:0 5px;border-radius:4px}
#kashi-lsearch,#kashi-isearch{width:100%;height:34px;background:var(--bg);color:var(--text);border:1px solid var(--line2);border-radius:var(--r);padding:0 34px 0 10px}
#kashi-lsearch:focus,#kashi-isearch:focus{outline:0;border-color:var(--accent-line)}

.kashi-chk{display:flex;gap:10px;padding:9px 10px;border-radius:8px;margin-bottom:3px;line-height:1.7}
.kashi-chk.go{cursor:pointer}.kashi-chk.go:hover{background:var(--panel2)}
.kashi-chk .i{flex:0 0 auto;display:flex;padding-top:2px}
.kashi-chk .i .ic{width:15px;height:15px}
.kashi-chk.error .i{color:var(--err)}.kashi-chk.warn .i{color:var(--warn)}.kashi-chk.info .i{color:var(--info)}.kashi-chk.ok .i{color:var(--ok)}
.kashi-chk small{color:var(--muted);display:block;font-size:11.5px}
.kashi-chk-h{display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:700;letter-spacing:.02em;color:var(--dim);margin:14px 4px 6px}
.kashi-chk-h span{background:var(--panel2);border-radius:99px;padding:0 7px;color:var(--muted)}

/* ---------- اعلان ---------- */
#kashi-toasts{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:99;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none}
.kashi-toast{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;background:var(--panel2);border:1px solid var(--line2);box-shadow:0 12px 30px rgba(0,0,0,.5);color:var(--text);font-size:12.5px;max-width:520px;animation:kashi-in .18s ease-out;pointer-events:auto}
.kashi-toast.ok .ic{color:var(--ok)}.kashi-toast.err{border-color:rgba(240,85,92,.4)}.kashi-toast.err .ic{color:var(--err)}

@media (max-width:1360px){.tb span:not(.kashi-badge){display:none}.ttl input{width:150px}}
</style>
</head>
<body>
<?php self::print_icon_sprite(); ?>
<div class="kashi-bar">
	<div class="g">
		<a class="ib" href="<?php echo esc_url( $back ); ?>" title="بازگشت به پیشخوان"><?php self::icon( 'arrow-right' ); ?></a>
		<div class="ttl"><input type="text" id="kashi-title" value="<?php echo esc_attr( $post->post_title ); ?>" placeholder="عنوان"></div>
		<span class="vr"></span>
		<?php if ( ! $is_section ) : ?>
		<button type="button" class="tb" id="kashi-gear" title="تنظیمات صفحه"><?php self::icon( 'sliders' ); ?><span>تنظیمات صفحه</span></button>
		<?php else : ?>
		<button type="button" class="tb" id="kashi-hfbtn" title="محل نمایش این کاشی"><?php self::icon( 'panel-top' ); ?><span>محل نمایش</span></button>
		<?php endif; ?>
		<button type="button" class="tb" id="kashi-hist" title="تاریخچه نسخه‌ها"><?php self::icon( 'history' ); ?><span>تاریخچه</span></button>
		<button type="button" class="tb" id="kashi-vedit" title="ویرایش مستقیم متن، لینک و تصویر داخل پیش‌نمایش"><?php self::icon( 'pen' ); ?><span>ویرایش مستقیم</span></button>
		<button type="button" class="tb" id="kashi-seo" title="بررسی سئو"><?php self::icon( 'gauge' ); ?><span>سئو</span><span id="kashi-seo-n" class="kashi-badge"></span></button>
	</div>
	<span class="kashi-sep"></span>
	<div class="g">
		<div class="seg kashi-dev">
			<button type="button" data-w="1440" class="on" aria-pressed="true" title="دسکتاپ (۱۴۴۰)"><?php self::icon( 'monitor' ); ?></button>
			<button type="button" data-w="1280" aria-pressed="false" title="لپ‌تاپ (۱۲۸۰)"><?php self::icon( 'laptop' ); ?></button>
			<button type="button" data-w="768" aria-pressed="false" title="تبلت (۷۶۸)"><?php self::icon( 'tablet' ); ?></button>
			<button type="button" data-w="390" aria-pressed="false" title="موبایل (۳۹۰)"><?php self::icon( 'phone' ); ?></button>
		</div>
		<label class="ib tog" title="پیش‌نمایش زنده: با هر تغییر به‌روز شود"><input type="checkbox" id="kashi-auto" checked aria-label="پیش‌نمایش زنده"><?php self::icon( 'zap' ); ?></label>
		<button type="button" class="ib" id="kashi-refresh" title="بروزرسانی پیش‌نمایش"><?php self::icon( 'refresh' ); ?></button>
		<a class="ib" href="<?php echo esc_url( $view ); ?>" target="_blank" title="مشاهده در سایت"><?php self::icon( 'external' ); ?></a>
		<span class="vr"></span>
		<span id="kashi-status" class="st">ذخیره شده</span>
		<button type="button" id="kashi-save" class="primary"><?php self::icon( 'save' ); ?><span>ذخیره</span><kbd>Ctrl S</kbd></button>
	</div>
</div>
<?php if ( ! $is_section ) : ?>
<div class="kashi-settings" id="kashi-settings">
	<h3><?php self::icon( 'sliders' ); ?>طرح‌بندی صفحه</h3>
	<label class="opt" data-layout="full"><input type="radio" name="kashi_layout" value="full" <?php checked( $layout, 'full' ); ?>>
		<span class="oi"><?php self::icon( 'layout-full' ); ?></span>
		<span><b>تمام‌عرض</b><small>هدر و فوتر سایت می‌ماند؛ بدون عنوان، ستون کناری و کانتینر قالب.</small></span></label>
	<label class="opt" data-layout="canvas"><input type="radio" name="kashi_layout" value="canvas" <?php checked( $layout, 'canvas' ); ?>>
		<span class="oi"><?php self::icon( 'layout-canvas' ); ?></span>
		<span><b>بوم خالی</b><small>بدون هدر و فوتر؛ فقط بخش‌های شما. مناسب لندینگ‌پیج.</small></span></label>
	<label class="opt" data-layout="default"><input type="radio" name="kashi_layout" value="default" <?php checked( $layout, 'default' ); ?>>
		<span class="oi"><?php self::icon( 'layout-default' ); ?></span>
		<span><b>پیش‌فرض قالب</b><small>همان چیدمان قالب <?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?> با کانتینر و تنظیمات خودش.</small></span></label>
	<div class="row"><span>عرض کانتینر بخش‌ها</span><input type="number" id="kashi-boxw" min="320" max="3000" step="10" value="<?php echo (int) $boxw; ?>"></div>
	<div class="hint">بخش‌هایی که «تمام‌عرض» نیستند در این عرض (پیکسل) وسط‌چین می‌شوند.</div>
	<label class="swrow sw"><span><b>محدود کردن CSS</b><small>استایل‌هایی مثل body و * فقط روی همان بخش اثر بگذارند.</small></span><input type="checkbox" id="kashi-scope" <?php checked( self::scope_on( $id ) ); ?>><i></i></label>
</div>
<?php endif; ?>
<?php if ( $is_section ) : $hf = self::hf_meta( $id ); ?>
<div class="kashi-settings" id="kashi-hfpanel" style="width:380px">
	<h3><?php self::icon( 'panel-top' ); ?>محل نمایش این کاشی</h3>
	<label class="opt" data-loc=""><input type="radio" name="kashi_loc" value="" <?php checked( $hf['location'], '' ); ?>>
		<span class="oi"><?php self::icon( 'layers' ); ?></span>
		<span><b>کاشی کتابخانه</b><small>فقط جایی نمایش داده می‌شود که درجش کنید (یا با شورت‌کد).</small></span></label>
	<label class="opt" data-loc="header"><input type="radio" name="kashi_loc" value="header" <?php checked( $hf['location'], 'header' ); ?>>
		<span class="oi"><?php self::icon( 'panel-top' ); ?></span>
		<span><b>هدر سایت</b><small>جای هدر قالب را می‌گیرد.</small></span></label>
	<label class="opt" data-loc="footer"><input type="radio" name="kashi_loc" value="footer" <?php checked( $hf['location'], 'footer' ); ?>>
		<span class="oi"><?php self::icon( 'panel-bottom' ); ?></span>
		<span><b>فوتر سایت</b><small>جای فوتر قالب را می‌گیرد.</small></span></label>
	<div id="kashi-hfcond">
		<div class="row"><span>کجا نمایش داده شود؟</span>
			<select id="kashi-hfmode" class="kashi-sel">
				<option value="all" <?php selected( $hf['mode'], 'all' ); ?>>کل سایت</option>
				<option value="front" <?php selected( $hf['mode'], 'front' ); ?>>فقط صفحه اصلی</option>
				<option value="include" <?php selected( $hf['mode'], 'include' ); ?>>فقط این صفحه‌ها</option>
				<option value="exclude" <?php selected( $hf['mode'], 'exclude' ); ?>>همه‌جا به‌جز این صفحه‌ها</option>
			</select>
		</div>
		<div class="row" id="kashi-hfids-row"><span>شناسه صفحه‌ها</span><input type="text" id="kashi-hfids" class="kashi-in" value="<?php echo esc_attr( implode( ', ', $hf['ids'] ) ); ?>" placeholder="مثلاً 12, 418"></div>
		<div class="row"><span>المان قالب (پیشرفته)</span><input type="text" id="kashi-hftarget" class="kashi-in" value="<?php echo esc_attr( $hf['target'] ); ?>" placeholder="خودکار"></div>
		<div class="hint">اگر چند هدر/فوتر داشته باشید، دقیق‌ترین شرط برنده است (صفحه‌های مشخص ← صفحه اصلی ← کل سایت). «المان قالب» را فقط وقتی پر کنید که هدر قالب‌تان تگ &lt;header&gt; ندارد؛ مثلاً <code>#masthead</code> یا <code>.site-header</code>.</div>
	</div>
	<label class="swrow sw"><span><b>محدود کردن CSS</b><small>استایل‌هایی مثل body و * فقط روی همان بخش اثر بگذارند.</small></span><input type="checkbox" id="kashi-scope" <?php checked( self::scope_on( $id ) ); ?>><i></i></label>
</div>
<?php endif; ?>
<div class="kashi-settings" id="kashi-spanel" style="width:440px">
	<h3><?php self::icon( 'gauge' ); ?>بررسی سئو</h3>
	<div class="hint" id="kashi-seo-info"></div>
	<div class="kashi-plist" id="kashi-slist"></div>
</div>
<div class="kashi-settings" id="kashi-hpanel" style="width:380px">
	<h3><?php self::icon( 'history' ); ?>تاریخچه نسخه‌ها</h3>
	<div class="hint">هر ذخیره یک نسخه ثبت می‌کند (<?php echo (int) self::MAX_REVS; ?> نسخه آخر). بازگردانی ابتدا در ویرایشگر اعمال می‌شود؛ برای ثبت، ذخیره کنید.</div>
	<div class="kashi-plist" id="kashi-hlist"></div>
</div>
<div class="kashi-settings" id="kashi-ipanel" style="left:12px;right:auto;width:420px;transform-origin:top left">
	<h3><?php self::icon( 'shapes' ); ?><span id="kashi-ititle">کتابخانه آیکون</span></h3>
	<div class="kashi-search"><?php self::icon( 'search' ); ?><input type="text" id="kashi-isearch" placeholder="جستجو: تلفن، سبد، واتساپ، کولر…"></div>
	<div class="kashi-igrp" id="kashi-igrp"><button type="button" data-g="" class="on">همه</button><button type="button" data-g="p">محصولات</button><button type="button" data-g="u">عمومی</button><button type="button" data-g="b">برندها</button></div>
	<div class="kashi-isz"><span>اندازه</span><div class="seg" id="kashi-isize"><button type="button" data-s="16">16</button><button type="button" data-s="20">20</button><button type="button" data-s="24" class="on">24</button><button type="button" data-s="32">32</button><button type="button" data-s="48">48</button></div></div>
	<div class="kashi-igrid" id="kashi-igrid"></div>
	<div id="kashi-iname"></div>
	<div class="hint" id="kashi-ihint">کلیک = درج در محل نشانگر کد. آیکون‌ها رنگ متن اطراف را می‌گیرند (currentColor) و با کد ذخیره می‌شوند؛ به هیچ فایل خارجی وابسته نیستند.</div>
</div>
<div class="kashi-settings" id="kashi-lpanel" style="left:12px;right:auto;width:420px;transform-origin:top left">
	<h3><?php self::icon( 'layers' ); ?>کتابخانه بخش‌ها</h3>
	<div class="kashi-search"><?php self::icon( 'search' ); ?><input type="text" id="kashi-lsearch" placeholder="جستجو…"></div>
	<div class="hint"><b>کپی:</b> نسخه مستقل که جدا ویرایش می‌شود. <b>لینک‌شده:</b> تغییر اصل آن، در همه صفحه‌ها اعمال می‌شود.</div>
	<div class="kashi-plist" id="kashi-llist"></div>
</div>
<div id="kashi-toasts" role="status" aria-live="polite"></div>
<div class="kashi-main">
	<div class="kashi-left">
		<div class="kashi-blocks">
			<div class="kashi-blocks-head">
				<span class="t">بخش‌های صفحه <small id="kashi-count">0</small></span>
				<span class="hb">
					<button type="button" class="btn-s" id="kashi-icobtn" title="کتابخانه آیکون"><?php self::icon( 'shapes' ); ?>آیکون</button>
					<button type="button" class="btn-s" id="kashi-libbtn"><?php self::icon( 'layers' ); ?>کتابخانه</button>
					<button type="button" class="btn-s acc" id="kashi-add"><?php self::icon( 'plus' ); ?>افزودن بخش</button>
				</span>
			</div>
			<div id="kashi-list"></div>
		</div>
		<div id="kashi-cur"></div>
		<div id="kashi-warn" style="display:none"><?php self::icon( 'alert' ); ?><span>کد PHP اینجا اجرا نمی‌شود و حذف می‌شود. شورت‌کدها کار می‌کنند؛ برای PHP خودتان از Code Snippets یا پوشه <code>wp-content/kashi-php</code> استفاده کنید.</span></div>
		<div class="kashi-code"><textarea id="kashi-code" spellcheck="false"></textarea></div>
	</div>
	<div class="kashi-div" id="kashi-div" role="separator" aria-orientation="vertical" aria-label="تغییر اندازه پنل‌ها (کلیدهای چپ و راست)" tabindex="0"></div>
	<div class="kashi-view"><iframe id="kashi-frame" name="kashi-frame" title="پیش‌نمایش"></iframe><span id="kashi-zoom"></span></div>
</div>
<form id="kashi-live" method="post" target="kashi-frame" action="<?php echo esc_url( self::live_url( $post ) ); ?>" style="display:none">
	<input type="hidden" name="kashi_live_nonce" value="<?php echo esc_attr( wp_create_nonce( 'kashi_live_' . $id ) ); ?>">
	<input type="hidden" name="kashi_live_blocks" value="">
	<input type="hidden" name="kashi_live_scope" value="">
	<?php if ( ! $is_section ) : ?>
	<input type="hidden" name="kashi_live_layout" value="">
	<input type="hidden" name="kashi_live_boxw" value="">
	<?php else : ?>
	<input type="hidden" name="kashi_live_location" value="">
	<?php endif; ?>
</form>
<?php wp_print_scripts(); ?>
<?php if ( function_exists( 'wp_print_media_templates' ) ) { wp_print_media_templates(); } ?>
<script>
(function () {
	var cfg = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
	var $ = function (id) { return document.getElementById(id); };
	/* فقط یک پنل شناور در هر لحظه باز باشد */
	function closeOthers(keep) {
		document.querySelectorAll('.kashi-settings.open').forEach(function (p) { if (p !== keep) { p.classList.remove('open'); } });
		[['kashi-gear', 'kashi-settings'], ['kashi-hfbtn', 'kashi-hfpanel'], ['kashi-hist', 'kashi-hpanel'], ['kashi-seo', 'kashi-spanel'], ['kashi-libbtn', 'kashi-lpanel'], ['kashi-icobtn', 'kashi-ipanel']].forEach(function (pair) {
			var b = document.getElementById(pair[0]), pn = document.getElementById(pair[1]);
			if (b && pn !== keep) { b.classList.remove('on'); }
		});
	}
	function a11y(root) {
		(root || document).querySelectorAll('button[title], a[title]').forEach(function (el) {
			if (!el.hasAttribute('aria-label')) { el.setAttribute('aria-label', el.getAttribute('title')); }
		});
	}
	function ic(n) { return '<svg class="ic" aria-hidden="true"><use href="#lsi-' + n + '"></use></svg>'; }
	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function toast(msg, type) {
		type = type || 'err';
		var box = $('kashi-toasts'), el = document.createElement('div');
		el.className = 'kashi-toast ' + type;
		el.innerHTML = ic(type === 'ok' ? 'check-circle' : 'alert') + '<span>' + esc(msg) + '</span>';
		box.appendChild(el);
		setTimeout(function () { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 320); }, type === 'ok' ? 2600 : 5000);
	}
	var ta = $('kashi-code'), frame = $('kashi-frame'), form = $('kashi-live'), list = $('kashi-list');
	var scope = $('kashi-scope'), auto = $('kashi-auto'), title = $('kashi-title');
	var cm = null, CM = null, mode = 'htmlmixed';
	var blocks = [], activeUid = null;
	var dirty = false, timer = null, scrollY = 0, saving = false, suppress = false;

	function setStatus(t) {
		var el = $('kashi-status');
		var st = /خطا/.test(t) ? 'err' : (/●|نشده|ذخیره کنید/.test(t) ? 'dirty' : (/…/.test(t) ? 'busy' : 'ok'));
		el.textContent = t.replace(/\s*[●✓]\s*/g, ' ').replace(/—\s*$/, '').trim();
		el.className = 'st ' + st;
	}
	function newUid() { return Math.random().toString(36).slice(2, 8); }

	if (cfg.settings && window.wp && wp.codeEditor) {
		cfg.settings.codemirror = Object.assign({}, cfg.settings.codemirror, {
			lineWrapping: true,
			extraKeys: { 'Ctrl-S': function () { save(); }, 'Cmd-S': function () { save(); } }
		});
		cm = wp.codeEditor.initialize(ta, cfg.settings).codemirror;
		CM = wp.CodeMirror || window.CodeMirror;
		mode = cm.getOption('mode');
		cm.on('change', function () { if (!suppress) { changed(); } });
	} else {
		ta.addEventListener('input', changed);
	}

	/* ---------- بخش‌ها ---------- */
	function makeBlock(b) {
		b = b || {};
		var o = { uid: b.uid || newUid(), name: b.name || ('بخش ' + (blocks.length + 1)), full: !!b.full, dev: Array.isArray(b.dev) ? b.dev.slice() : [], code: b.code || '' };
		if (cm && CM) { o.doc = CM.Doc(o.code, mode); }
		return o;
	}
	function idx() {
		for (var k = 0; k < blocks.length; k++) { if (blocks[k].uid === activeUid) { return k; } }
		return -1;
	}
	function syncTa() {
		if (!cm) { var k = idx(); if (k >= 0) { blocks[k].code = ta.value; } }
	}
	function codeOf(b) { return b.doc ? b.doc.getValue() : b.code; }
	function getBlocks() {
		syncTa();
		return blocks.map(function (b) { return { uid: b.uid, name: b.name, full: b.full, dev: b.dev || [], code: codeOf(b) }; });
	}

	function select(i, scrollPreview) {
		if (i < 0 || i >= blocks.length) { return; }
		syncTa();
		activeUid = blocks[i].uid;
		suppress = true;
		if (cm) { cm.swapDoc(blocks[i].doc); cm.refresh(); } else { ta.value = blocks[i].code; }
		suppress = false;
		markActive();
		highlight(scrollPreview);
	}

	var JS_RE = /(^|[\s;{(])(function\b|const\s|let\s|var\s|=>|document\.|window\.|addEventListener\s*\(|console\.|import\s|export\s|new\s+[A-Z]|if\s*\(|for\s*\()/;
	function codeKind(code) {
		var t = String(code || '').replace(/\/\*[\s\S]*?\*\//g, '').trim();
		if (!t || /<[a-z!\/]/i.test(t) || /^\[[a-z_-]+/i.test(t)) { return 'html'; }
		if (JS_RE.test(t)) { return 'js'; }
		if (/^(@[a-z-]+[^{;]*[{;]|:root\s*\{|[^{}<>]+\{[^{}]*(:[^{}]*)?\})/i.test(t)) { return 'css'; }
		return 'html';
	}
	function kindInfo(code) {
		var k = codeKind(code), parts = [];
		function chip(x) { return '<bdi class="kc">' + x + '</bdi>'; }
		if (k === 'css') { return { mode: 'css', label: 'تشخیص خودکار: ' + chip('CSS') + ' ← در head' }; }
		if (k === 'js') { return { mode: 'javascript', label: 'تشخیص خودکار: ' + chip('JS') + ' ← انتهای صفحه' }; }
		var c = String(code || '');
		if (/<(?!style|script|link)[a-z]/i.test(c.replace(/<(style|script)\b[\s\S]*?<\/\1\s*>/gi, ''))) { parts.push('HTML'); }
		if (/<style\b/i.test(c)) { parts.push('CSS'); }
		if (/<script\b(?![^>]*ld\+json)/i.test(c)) { parts.push('JS'); }
		if (/application\/ld\+json/i.test(c)) { parts.push('اسکیما'); }
		if (/\[[a-z_-]+[\s\]]/i.test(c)) { parts.push('شورت‌کد'); }
		return { mode: 'htmlmixed', label: parts.length ? 'تشخیص خودکار: ' + parts.map(chip).join(' ') : '' };
	}
	function showKind() {
		var k = idx(), el = $('kashi-kind');
		if (k < 0 || !el) { return; }
		var code = cm ? cm.getValue() : ta.value, info = kindInfo(code);
		el.innerHTML = info.label;
		el.style.display = info.label ? '' : 'none';
		if (cm && cm.getOption('mode') !== info.mode) { cm.setOption('mode', info.mode); }
	}

	function markActive() {
		var k = idx();
		Array.prototype.forEach.call(list.children, function (el, i) { el.classList.toggle('on', i === k); });
		$('kashi-cur').innerHTML = k >= 0 ? (ic('code') + '<span>در حال ویرایش</span><b>' + esc(blocks[k].name || 'بدون نام') + '</b><span id="kashi-kind" class="kind"></span>') : '';
		showKind();
		$('kashi-count').textContent = blocks.length;
		phpWarn();
	}

	var LINK_RE = /^\s*\[(?:kashi|lsec)\s+id=["']?(\d+)["']?\s*\]\s*$/;
	function phpWarn() {
		var k = idx();
		$('kashi-warn').style.display = (k >= 0 && /<\?(php|=|\s)/i.test(codeOf(blocks[k]))) ? '' : 'none';
	}
	function updateBadges() {
		syncTa();
		Array.prototype.forEach.call(list.children, function (el, i) {
			var b = blocks[i], a = el.querySelector('.lnk');
			if (!b || !a) { return; }
			var m = LINK_RE.exec(codeOf(b));
			a.style.display = m ? '' : 'none';
			if (m) { a.href = cfg.editBase + m[1]; }
		});
	}

	/* ---------- نمایش در دستگاه‌ها ---------- */
	var DEVS = [
		{ k: 'd', ic: 'monitor', fa: 'دسکتاپ', r: '۱۴۴۰ پیکسل و بیشتر' },
		{ k: 'l', ic: 'laptop', fa: 'لپ‌تاپ', r: '۱۰۲۵ تا ۱۴۳۹' },
		{ k: 't', ic: 'tablet', fa: 'تبلت', r: '۷۶۸ تا ۱۰۲۴' },
		{ k: 'm', ic: 'phone', fa: 'موبایل', r: 'کمتر از ۷۶۸' }
	];
	function devChip(btn, b) {
		var d = b.dev || [];
		btn.classList.toggle('on', d.length > 0);
		btn.innerHTML = d.length ? DEVS.filter(function (x) { return d.indexOf(x.k) > -1; }).map(function (x) { return ic(x.ic); }).join('') : ic('monitor') + ic('phone');
		btn.title = d.length ? 'فقط در: ' + DEVS.filter(function (x) { return d.indexOf(x.k) > -1; }).map(function (x) { return x.fa; }).join('، ') : 'نمایش در همه دستگاه‌ها (ریسپانسیو)';
		btn.setAttribute('aria-label', btn.title);
	}
	var dvPop = null;
	function devPop(btn, b) {
		if (dvPop) { var was = dvPop.owner === btn; dvPop.remove(); dvPop = null; if (was) { return; } }
		dvPop = document.createElement('div');
		dvPop.className = 'kashi-dvpop';
		dvPop.owner = btn;
		dvPop.innerHTML = '<b>نمایش این بخش در</b>' + DEVS.map(function (x) {
			return '<label class="sw dvr"><span class="dvl">' + ic(x.ic) + '<span><b>' + x.fa + '</b><small>' + x.r + '</small></span></span><input type="checkbox" data-k="' + x.k + '"' + ((b.dev || []).indexOf(x.k) > -1 ? ' checked' : '') + '><i></i></label>';
		}).join('') + '<div class="hint">همه خاموش = نمایش در همه دستگاه‌ها (ریسپانسیو).<br>مثلاً یک هیرو «فقط دسکتاپ و لپ‌تاپ» و یک هیروی دیگر «فقط موبایل و تبلت».</div>';
		document.body.appendChild(dvPop);
		var r = btn.getBoundingClientRect();
		dvPop.style.top = Math.min(r.bottom + 6, window.innerHeight - dvPop.offsetHeight - 8) + 'px';
		dvPop.style.left = Math.max(8, r.left) + 'px';
		dvPop.addEventListener('click', function (e) { e.stopPropagation(); });
		dvPop.querySelectorAll('input').forEach(function (inp) {
			inp.addEventListener('change', function () {
				var d = [];
				dvPop.querySelectorAll('input:checked').forEach(function (c) { d.push(c.getAttribute('data-k')); });
				b.dev = d.length === DEVS.length ? [] : d;
				devChip(btn, b);
				changed();
			});
		});
	}
	document.addEventListener('click', function () { if (dvPop) { dvPop.remove(); dvPop = null; } });

	function renderList() {
		list.innerHTML = '';
		blocks.forEach(function (b, i) {
			var el = document.createElement('div');
			el.className = 'kashi-item';
			el.innerHTML = '<span class="n"></span><a class="lnk" target="_blank" style="display:none" title="لینک‌شده به کتابخانه — برای ویرایش اصل آن کلیک کنید">' + ic('link') + '</a><input class="nm" type="text" placeholder="نام بخش">' +
				'<label class="fw" title="کل عرض صفحه"><input type="checkbox"><span>' + ic('width') + 'تمام‌عرض</span></label>' +
				'<button type="button" class="dv" title="نمایش در دستگاه‌ها"></button>' +
				'<span class="acts"><button type="button" data-a="up" title="بالا">' + ic('up') + '</button><button type="button" data-a="down" title="پایین">' + ic('down') + '</button>' +
				'<button type="button" data-a="dup" title="کپی">' + ic('copy') + '</button><button type="button" data-a="lib" title="ذخیره در کتابخانه">' + ic('bookmark') + '</button><button type="button" data-a="del" title="حذف">' + ic('trash') + '</button></span>';
			el.querySelector('.n').textContent = i + 1;
			var nm = el.querySelector('.nm');
			nm.value = b.name;
			nm.addEventListener('focus', function () { if (activeUid !== b.uid) { select(blocks.indexOf(b), true); } });
			nm.addEventListener('input', function () { b.name = nm.value; markActive(); markDirty(); });
			var dvb = el.querySelector('.dv');
			devChip(dvb, b);
			dvb.addEventListener('click', function (e) { e.stopPropagation(); devPop(dvb, b); });
			var fw = el.querySelector('.fw input');
			fw.checked = b.full;
			fw.addEventListener('change', function () { b.full = fw.checked; changed(); });
			el.addEventListener('click', function (e) {
				if (e.target.closest('button, input, label, a')) { return; }
				select(blocks.indexOf(b), true);
			});
			el.querySelector('.acts').addEventListener('click', function (e) {
				var btn = e.target.closest('button');
				if (btn) { act(btn.getAttribute('data-a'), blocks.indexOf(b)); }
			});
			list.appendChild(el);
		});
		markActive();
		updateBadges();
		a11y(list);
	}

	function act(a, i) {
		syncTa();
		var b = blocks[i];
		if (a === 'up' && i > 0) {
			blocks.splice(i, 1); blocks.splice(i - 1, 0, b); renderList();
		} else if (a === 'down' && i < blocks.length - 1) {
			blocks.splice(i, 1); blocks.splice(i + 1, 0, b); renderList();
		} else if (a === 'dup') {
			var copy = makeBlock({ name: b.name + ' (کپی)', code: codeOf(b), full: b.full, dev: b.dev });
			blocks.splice(i + 1, 0, copy); renderList(); select(i + 1, false);
		} else if (a === 'del') {
			if (!confirm('بخش «' + (b.name || (i + 1)) + '» حذف شود؟')) { return; }
			var wasActive = (b.uid === activeUid);
			blocks.splice(i, 1);
			if (!blocks.length) { blocks.push(makeBlock()); }
			renderList();
			if (wasActive) { activeUid = null; select(Math.min(i, blocks.length - 1), false); }
		} else if (a === 'lib') {
			saveToLib(b);
			return;
		} else {
			return;
		}
		changed();
	}

	$('kashi-add').addEventListener('click', function () {
		syncTa();
		var k = idx();
		var nb = makeBlock();
		blocks.splice(k + 1, 0, nb);
		renderList();
		select(k + 1, false);
		if (cm) { cm.focus(); } else { ta.focus(); }
		markDirty();
	});

	/* ---------- پیش‌نمایش ---------- */
	function refresh() {
		try { scrollY = frame.contentWindow.scrollY || 0; } catch (e) {}
		form.kashi_live_blocks.value = JSON.stringify(getBlocks());
		form.kashi_live_scope.value = scope.checked ? '1' : '0';
		if (form.kashi_live_layout) {
			form.kashi_live_layout.value = getLayout();
			form.kashi_live_boxw.value = boxw ? boxw.value : '';
		}
		if (form.kashi_live_location) { form.kashi_live_location.value = getLoc(); }
		form.submit();
		setStatus('در حال بارگذاری…');
	}

	function frameDoc() { try { return frame.contentDocument; } catch (e) { return null; } }

	function highlight(scrollTo) {
		var d = frameDoc();
		if (!d || !d.querySelectorAll) { return; }
		Array.prototype.forEach.call(d.querySelectorAll('.kashi-block.kashi-on'), function (el) { el.classList.remove('kashi-on'); });
		var el = activeUid ? d.querySelector('[data-kashi-block="' + activeUid + '"]') : null;
		if (el) {
			el.classList.add('kashi-on');
			if (scrollTo) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		}
	}

	frame.addEventListener('load', function () {
		var d = frameDoc();
		if (d && d.head) {
			try { frame.contentWindow.scrollTo(0, scrollY); } catch (e) {}
			var st = d.createElement('style');
			st.textContent = '.kashi-block:hover{outline:1px dashed rgba(124,108,255,.7);outline-offset:-1px}' +
				'.kashi-block.kashi-on{outline:2px solid #7c6cff;outline-offset:-2px}';
			d.head.appendChild(st);
			// دوبار کلیک روی یک بخش در پیش‌نمایش = انتخاب آن در ویرایشگر
			d.addEventListener('dblclick', function (e) {
				if (vOn) { return; }
				var el = e.target && e.target.closest ? e.target.closest('[data-kashi-block]') : null;
				while (el) {
					var u = el.getAttribute('data-kashi-block');
					for (var k = 0; k < blocks.length; k++) {
						if (blocks[k].uid === u) { e.preventDefault(); select(k, false); if (cm) { cm.focus(); } return; }
					}
					el = el.parentElement ? el.parentElement.closest('[data-kashi-block]') : null;
				}
			});
			highlight(false);
		}
		setStatus(dirty ? 'ذخیره نشده ●' : 'ذخیره شده');
	});

	function markDirty() { dirty = true; setStatus('ذخیره نشده ●'); }
	function changed() {
		if (vQuiet) { return; } // تغییر از ویرایش مستقیم: پیش‌نمایش همین الان درست است
		markDirty();
		updateBadges();
		phpWarn();
		showKind();
		if (typeof seoLater === 'function') { seoLater(); }
		if (auto.checked) { clearTimeout(timer); timer = setTimeout(refresh, 800); }
	}
	scope.addEventListener('change', changed);
	title.addEventListener('input', markDirty);

	/* ---------- تنظیمات صفحه (طرح‌بندی) ---------- */
	var panel = $('kashi-settings'), gear = $('kashi-gear'), boxw = $('kashi-boxw');
	function getLayout() {
		var r = document.querySelector('input[name=kashi_layout]:checked');
		return r ? r.value : 'full';
	}
	function paintLayout() {
		if (!panel) { return; }
		var l = getLayout();
		panel.querySelectorAll('.opt').forEach(function (o) { o.classList.toggle('on', o.getAttribute('data-layout') === l); });
		boxw.disabled = (l === 'default');
	}
	if (panel) {
		gear.addEventListener('click', function (e) {
			e.stopPropagation();
			closeOthers(panel);
			panel.classList.toggle('open');
			gear.classList.toggle('on', panel.classList.contains('open'));
		});
		document.addEventListener('click', function (e) {
			if (panel.classList.contains('open') && !panel.contains(e.target) && e.target !== gear) {
				panel.classList.remove('open');
				gear.classList.remove('on');
			}
		});
		panel.querySelectorAll('input[name=kashi_layout]').forEach(function (r) {
			r.addEventListener('change', function () { paintLayout(); changed(); });
		});
		boxw.addEventListener('change', changed);
		paintLayout();
	}
	$('kashi-refresh').addEventListener('click', refresh);

	/* ---------- محل نمایش کاشی (هدر / فوتر) ---------- */
	var hfPanel = $('kashi-hfpanel');
	function getLoc() {
		var r = document.querySelector('input[name=kashi_loc]:checked');
		return r ? r.value : '';
	}
	function paintLoc() {
		if (!hfPanel) { return; }
		var l = getLoc(), m = $('kashi-hfmode').value;
		hfPanel.querySelectorAll('.opt').forEach(function (o) { o.classList.toggle('on', o.getAttribute('data-loc') === l); });
		$('kashi-hfcond').style.display = l ? '' : 'none';
		$('kashi-hfids-row').style.display = (m === 'include' || m === 'exclude') ? '' : 'none';
		var b = $('kashi-hfbtn');
		if (b) { b.querySelector('span').textContent = l === 'header' ? 'هدر سایت' : (l === 'footer' ? 'فوتر سایت' : 'محل نمایش'); }
	}
	if (hfPanel) {
		hfPanel.querySelectorAll('input[name=kashi_loc]').forEach(function (r) {
			r.addEventListener('change', function () { paintLoc(); changed(); });
		});
		$('kashi-hfmode').addEventListener('change', function () { paintLoc(); markDirty(); });
		$('kashi-hfids').addEventListener('input', markDirty);
		$('kashi-hftarget').addEventListener('input', markDirty);
		$('kashi-hftarget').addEventListener('change', changed);
		paintLoc();
	}

	/* ---------- ذخیره ---------- */
	function save() {
		if (saving) { return; }
		saving = true;
		setStatus('در حال ذخیره…');
		var fd = new FormData();
		fd.append('action', 'kashi_save');
		fd.append('post_id', cfg.postId);
		fd.append('nonce', cfg.saveNonce);
		fd.append('blocks', JSON.stringify(getBlocks()));
		fd.append('title', title.value);
		fd.append('scope', scope.checked ? '1' : '0');
		if (panel) {
			fd.append('layout', getLayout());
			fd.append('boxw', boxw.value);
		}
		if (hfPanel) {
			fd.append('hf_location', getLoc());
			fd.append('hf_mode', $('kashi-hfmode').value);
			fd.append('hf_ids', $('kashi-hfids').value);
			fd.append('hf_target', $('kashi-hftarget').value);
		}
		fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				saving = false;
				if (d && d.success) {
					dirty = false; setStatus('ذخیره شد ✓');
					if (d.data && d.data.revs) { revs = d.data.revs; renderRevs(); }
				}
				else { setStatus('خطا در ذخیره'); toast((d && d.data) || 'ذخیره انجام نشد.'); }
			})
			.catch(function () { saving = false; setStatus('خطا در ذخیره'); toast('ارتباط با سرور برقرار نشد.'); });
	}
	$('kashi-save').addEventListener('click', save);
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(); }
	});

	/* ---------- ابزار مشترک ---------- */
	function post(data) {
		var fd = new FormData();
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function popup(btn, el, onopen) {
		if (!btn || !el) { return; }
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = !el.classList.contains('open');
			closeOthers(el);
			el.classList.toggle('open', open);
			btn.classList.toggle('on', open);
			if (open && onopen) { onopen(); }
		});
		document.addEventListener('click', function (e) {
			if (el.classList.contains('open') && document.body.contains(e.target) && !el.contains(e.target) && e.target !== btn) {
				closePop(btn, el);
			}
		});
	}
	function closePop(btn, el) { el.classList.remove('open'); btn.classList.remove('on'); }
	function row(title, sub, cur) {
		var el = document.createElement('div');
		el.className = 'kashi-row' + (cur ? ' cur' : '');
		el.innerHTML = '<div><b></b><small></small></div><div class="btns"></div>';
		el.querySelector('b').textContent = title;
		el.querySelector('small').textContent = sub;
		return el;
	}
	function btn(parent, label, fn, icon) {
		var b = document.createElement('button');
		b.type = 'button'; b.innerHTML = (icon ? ic(icon) : '') + esc(label);
		b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
		parent.appendChild(b);
		return b;
	}
	function hint(parent, text) {
		var d = document.createElement('div'); d.className = 'hint'; d.textContent = text; parent.appendChild(d);
	}

	/* ---------- تاریخچه نسخه‌ها ---------- */
	var revs = cfg.revs || [], hBtn = $('kashi-hist'), hPanel = $('kashi-hpanel'), hList = $('kashi-hlist');
	function renderRevs() {
		hList.innerHTML = '';
		if (!revs.length) { hint(hList, 'هنوز نسخه‌ای ثبت نشده. از اولین ذخیره، نسخه‌ها اینجا می‌آیند.'); return; }
		revs.forEach(function (r) {
			var cur = (r.i === 0);
			var el = row(r.when + (cur ? ' · نسخه فعلی' : ''), [r.ago, r.user, r.n + ' بخش'].filter(Boolean).join(' · '), cur);
			btn(el.querySelector('.btns'), 'بازگردانی', function () { restore(r); }, 'undo');
			hList.appendChild(el);
		});
	}
	function restore(r) {
		if (dirty && !confirm('تغییرات ذخیره‌نشده‌ی فعلی از بین می‌رود. ادامه می‌دهید؟')) { return; }
		post({ action: 'kashi_rev_get', post_id: cfg.postId, nonce: cfg.saveNonce, index: r.i })
			.then(function (d) {
				if (!d || !d.success) { toast((d && d.data) || 'بازگردانی انجام نشد.'); return; }
				loadState(d.data);
				closePop(hBtn, hPanel);
				setStatus('نسخه ' + r.when + ' آمد — ذخیره کنید ●');
			})
			.catch(function () { toast('ارتباط با سرور برقرار نشد.'); });
	}
	function loadState(st) {
		blocks = [];
		activeUid = null;
		(st.blocks || []).forEach(function (b) { blocks.push(makeBlock(b)); });
		if (!blocks.length) { blocks.push(makeBlock()); }
		scope.checked = !!st.scope;
		if (hfPanel && st.hf) {
			var lr = document.querySelector('input[name=kashi_loc][value="' + (st.hf.location || '') + '"]');
			if (lr) { lr.checked = true; }
			$('kashi-hfmode').value = st.hf.mode || 'all';
			$('kashi-hfids').value = (st.hf.ids || []).join(', ');
			$('kashi-hftarget').value = st.hf.target || '';
			paintLoc();
		}
		if (panel) {
			var rd = document.querySelector('input[name=kashi_layout][value="' + st.layout + '"]');
			if (rd) { rd.checked = true; }
			if (st.boxw) { boxw.value = st.boxw; }
			paintLayout();
		}
		renderList();
		select(0, false);
		changed();
	}
	popup(hBtn, hPanel, renderRevs);
	popup($('kashi-hfbtn'), hfPanel);

	/* ---------- کتابخانه بخش‌ها ---------- */
	var lBtn = $('kashi-libbtn'), lPanel = $('kashi-lpanel'), lList = $('kashi-llist'), lSearch = $('kashi-lsearch'), lib = [];
	function loadLib() {
		lList.innerHTML = '';
		hint(lList, 'در حال بارگذاری…');
		post({ action: 'kashi_lib_list', nonce: cfg.libNonce, exclude: cfg.postId })
			.then(function (d) { lib = (d && d.success) ? d.data : []; renderLib(); })
			.catch(function () { lList.innerHTML = ''; hint(lList, 'ارتباط با سرور برقرار نشد.'); });
	}
	function renderLib() {
		var q = lSearch.value.trim().toLowerCase();
		lList.innerHTML = '';
		var items = lib.filter(function (x) { return !q || x.title.toLowerCase().indexOf(q) > -1; });
		if (!items.length) {
			hint(lList, lib.length ? 'چیزی پیدا نشد.' : 'کتابخانه خالی است. با دکمه «ذخیره در کتابخانه» کنار هر بخش، آن را اینجا اضافه کنید.');
			return;
		}
		items.forEach(function (x) {
			var el = row(x.title, x.n + ' بخش · ' + x.ago, false), bs = el.querySelector('.btns');
			btn(bs, 'کپی', function () { insertCopy(x); }, 'copy');
			btn(bs, 'لینک‌شده', function () { insertLinked(x); }, 'link');
			var a = document.createElement('a');
			a.href = cfg.editBase + x.id; a.target = '_blank'; a.innerHTML = ic('external'); a.title = 'ویرایش';
			a.addEventListener('click', function (e) { e.stopPropagation(); });
			bs.appendChild(a);
			lList.appendChild(el);
		});
	}
	function insertAt(newBlocks) {
		syncTa();
		var k = idx();
		newBlocks.forEach(function (b, j) { blocks.splice(k + 1 + j, 0, makeBlock(b)); });
		renderList();
		select(k + 1, true);
		closePop(lBtn, lPanel);
		changed();
	}
	function insertCopy(x) {
		post({ action: 'kashi_lib_get', nonce: cfg.libNonce, id: x.id })
			.then(function (d) {
				if (!d || !d.success) { toast((d && d.data) || 'درج انجام نشد.'); return; }
				if (!d.data.length) { toast('این مورد خالی است.'); return; }
				insertAt(d.data.map(function (b) { return { name: b.name || x.title, code: b.code, full: b.full, dev: b.dev }; }));
			})
			.catch(function () { toast('ارتباط با سرور برقرار نشد.'); });
	}
	function insertLinked(x) {
		insertAt([{ name: x.title, code: '[kashi id="' + x.id + '"]', full: true }]);
	}
	function saveToLib(b) {
		var name = prompt('نام این بخش در کتابخانه:', b.name || '');
		if (name === null) { return; }
		post({
			action: 'kashi_lib_save', nonce: cfg.libNonce, name: name, scope: scope.checked ? '1' : '0',
			blocks: JSON.stringify([{ name: b.name, code: codeOf(b), full: b.full, dev: b.dev || [] }])
		}).then(function (d) {
			if (d && d.success) { toast('«' + (name || b.name) + '» در کتابخانه ذخیره شد', 'ok'); }
			else { toast((d && d.data) || 'ذخیره در کتابخانه انجام نشد.'); }
		}).catch(function () { toast('ارتباط با سرور برقرار نشد.'); });
	}
	popup(lBtn, lPanel, loadLib);

	/* ---------- کتابخانه آیکون ---------- */
	var iBtn = $('kashi-icobtn'), iPanel = $('kashi-ipanel'), iGrid = $('kashi-igrid'), iSearch = $('kashi-isearch');
	var icons = null, iSize = 24, iReplace = null, iGroup = '';
	function iconSvg(x, size, keep) {
		var line = x.t === 'l', a = keep || {};
		return '<svg' + (a.cls ? ' class="' + a.cls + '"' : ' class="kashi-icon"') + ' width="' + (a.w || size) + '" height="' + (a.h || size) + '" viewBox="0 0 24 24" ' +
			(line ? 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' : 'fill="currentColor"') +
			(a.style ? ' style="' + a.style + '"' : '') + ' aria-hidden="true">' + x.b + '</svg>';
	}
	function loadIcons() {
		$('kashi-ititle').textContent = iReplace ? 'تعویض آیکون' : 'کتابخانه آیکون';
		if (icons) { renderIcons(); setTimeout(function () { iSearch.focus(); }, 30); return; }
		iGrid.innerHTML = ''; hint(iGrid, 'در حال بارگذاری…');
		fetch(cfg.icons, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
			.then(function (d) { icons = Array.isArray(d) ? d : []; renderIcons(); iSearch.focus(); })
			.catch(function () { iGrid.innerHTML = ''; hint(iGrid, 'فهرست آیکون‌ها بارگذاری نشد.'); });
	}
	function renderIcons() {
		var q = iSearch.value.trim().toLowerCase();
		iGrid.innerHTML = '';
		var list = (icons || []).filter(function (x) {
			return (!iGroup || x.g === iGroup) && (!q || x.n.indexOf(q) > -1 || x.fa.indexOf(q) > -1);
		});
		if (!list.length) { hint(iGrid, 'آیکونی پیدا نشد.'); return; }
		list.forEach(function (x) {
			var b = document.createElement('button');
			b.type = 'button'; b.title = x.fa; b.setAttribute('aria-label', x.fa);
			b.innerHTML = iconSvg(x, 22);
			b.addEventListener('click', function (e) { e.stopPropagation(); useIcon(x); });
			b.addEventListener('mouseenter', function () { $('kashi-iname').textContent = x.fa; });
			b.addEventListener('focus', function () { $('kashi-iname').textContent = x.fa; });
			iGrid.appendChild(b);
		});
	}
	function openIcons() {
		closeOthers(iPanel); iPanel.classList.add('open'); iBtn.classList.add('on'); loadIcons();
	}
	function useIcon(x) {
		if (iReplace) { var r = iReplace; iReplace = null; closePop(iBtn, iPanel); vReplaceSvg(r, x); return; }
		var svg = iconSvg(x, iSize);
		if (cm) { cm.replaceSelection(svg); cm.focus(); }
		else { var a = ta.selectionStart, z = ta.selectionEnd; ta.value = ta.value.slice(0, a) + svg + ta.value.slice(z); ta.dispatchEvent(new Event('input')); }
		closePop(iBtn, iPanel);
		toast('آیکون «' + x.fa + '» درج شد', 'ok');
	}
	iBtn.addEventListener('click', function () { iReplace = null; });
	popup(iBtn, iPanel, loadIcons);
	iSearch.addEventListener('input', renderIcons);
	iSearch.addEventListener('click', function (e) { e.stopPropagation(); });
	document.querySelectorAll('#kashi-igrp button').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.stopPropagation();
			document.querySelectorAll('#kashi-igrp button').forEach(function (x) { x.classList.remove('on'); });
			b.classList.add('on'); iGroup = b.getAttribute('data-g'); renderIcons();
		});
	});
	document.querySelectorAll('#kashi-isize button').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.stopPropagation();
			document.querySelectorAll('#kashi-isize button').forEach(function (x) { x.classList.remove('on'); });
			b.classList.add('on'); iSize = +b.getAttribute('data-s');
		});
	});

	lSearch.addEventListener('input', renderLib);
	lSearch.addEventListener('click', function (e) { e.stopPropagation(); });

	/* ---------- بررسی سئو (زنده) ---------- */
	var sBtn = $('kashi-seo'), sPanel = $('kashi-spanel'), sList = $('kashi-slist'), sBadge = $('kashi-seo-n'), seoTimer = null;
	var SCHEMA_BY_PLUGIN = ['Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'Person', 'ImageObject'];

	function txt(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }
	function shortSrc(src) { src = src || ''; return src.length > 50 ? '…' + src.slice(-45) : src; }
	function schemaTypes(j, out) {
		if (Array.isArray(j)) { j.forEach(function (x) { schemaTypes(x, out); }); return; }
		if (!j || typeof j !== 'object') { return; }
		if (j['@type']) { [].concat(j['@type']).forEach(function (t) { out.push(String(t)); }); }
		if (j['@graph']) { schemaTypes(j['@graph'], out); }
	}

	function seoAudit() {
		var res = { error: [], warn: [], ok: [], info: [] };
		function add(level, msg, bi, sub) { res[level].push({ msg: msg, bi: bi, sub: sub || '' }); }
		var all = getBlocks(), h1 = [], heads = [], words = 0, imgs = 0, noAlt = 0, noDim = 0, types = [], ids = {}, linked = 0;
		var layout = (!cfg.isSection && panel) ? getLayout() : null;

		all.forEach(function (b, bi) {
			var code = b.code.replace(/<\?[\s\S]*?(\?>|$)/g, '');
			var bname = 'بخش ' + (bi + 1) + (b.name ? ' «' + b.name + '»' : '');
			if (LINK_RE.test(code)) { linked++; return; }
			var doc = new DOMParser().parseFromString(code, 'text/html');

			doc.querySelectorAll('script[type="application/ld+json"]').forEach(function (sc) {
				try { schemaTypes(JSON.parse(sc.textContent), types); }
				catch (e) { add('error', 'اسکیمای JSON-LD خراب است و گوگل آن را نمی‌خواند', bi, bname + ' — ' + e.message); }
			});
			doc.querySelectorAll('script,style,noscript,template').forEach(function (x) { x.remove(); });

			doc.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(function (h) {
				var lv = +h.tagName.charAt(1);
				heads.push({ lv: lv, bi: bi, t: txt(h), bname: bname });
				if (lv === 1) { h1.push({ bi: bi, t: txt(h), bname: bname }); }
				if (!txt(h)) { add('warn', 'تیتر خالی <h' + lv + '>', bi, bname); }
			});
			doc.querySelectorAll('img').forEach(function (im) {
				imgs++;
				var src = im.getAttribute('src') || im.getAttribute('data-src') || '';
				if (!im.hasAttribute('alt')) { noAlt++; add('error', 'تصویر بدون متن جایگزین (alt)', bi, bname + ' — ' + shortSrc(src)); }
				if (!(im.getAttribute('width') && im.getAttribute('height')) && !/aspect-ratio/.test(im.getAttribute('style') || '')) { noDim++; }
				if (/^data:image/i.test(src) && src.length > 20000) { add('warn', 'تصویر حجیم base64 داخل کد؛ آن را در رسانه آپلود کنید', bi, bname); }
			});
			doc.querySelectorAll('a').forEach(function (a) {
				var href = (a.getAttribute('href') || '').trim(), label = txt(a) || a.getAttribute('aria-label') || a.getAttribute('title') || '';
				var imgAlt = a.querySelector('img[alt]:not([alt=""])');
				if (!href || href === '#' || /^javascript:/i.test(href)) { add('warn', 'لینک بدون آدرس واقعی (href="' + href + '")', bi, bname + (label ? ' — «' + label.slice(0, 40) + '»' : '')); }
				if (!label && !imgAlt) { add('warn', 'لینک بدون متن؛ برای لینک آیکونی aria-label بگذارید', bi, bname + ' — ' + shortSrc(href)); }
			});
			doc.querySelectorAll('[id]').forEach(function (el) {
				var id = el.id;
				if (ids[id] !== undefined && ids[id] !== bi) { add('warn', 'شناسه تکراری id="' + id + '" در دو بخش', bi, bname); }
				ids[id] = bi;
			});
			words += (txt(doc.body).match(/\S+/g) || []).length;
		});

		// H1
		if (!cfg.isSection) {
			if (layout === 'default') {
				if (h1.length) { add('error', 'در طرح «پیش‌فرض قالب»، خود قالب عنوان صفحه را H1 می‌کند؛ H1 داخل بخش‌ها تکراری است — آن را h2 کنید', h1[0].bi, h1.map(function (x) { return x.bname; }).join('، ')); }
				else { add('ok', 'H1 صفحه توسط قالب ساخته می‌شود'); }
			} else if (!h1.length) {
				add('error', 'صفحه H1 ندارد؛ در طرح تمام‌عرض/بوم عنوان قالب نمایش داده نمی‌شود، پس یک <h1> در بخش اول بگذارید', 0);
			} else if (h1.length > 1) {
				add('error', 'صفحه ' + h1.length + ' تیتر H1 دارد؛ فقط یکی بگذارید و بقیه را h2 کنید', h1[1].bi, h1.map(function (x) { return x.bname + ': ' + x.t.slice(0, 30); }).join(' | '));
			} else {
				add('ok', 'یک H1: «' + h1[0].t.slice(0, 60) + '»');
			}
		}
		// ترتیب تیترها
		var prev = (layout === 'default') ? 1 : 0, skips = 0;
		heads.forEach(function (h) {
			if (prev && h.lv > prev + 1 && skips < 5) { skips++; add('warn', 'پرش در ترتیب تیترها: h' + prev + ' ← h' + h.lv, h.bi, h.bname + ' — «' + h.t.slice(0, 40) + '»'); }
			prev = h.lv;
		});
		if (heads.length && !skips) { add('ok', 'ترتیب تیترها درست است (' + heads.length + ' تیتر)'); }
		// تصاویر
		if (imgs && !noAlt) { add('ok', 'همه ' + imgs + ' تصویر alt دارند'); }
		if (noDim) { add('info', noDim + ' تصویر width و height ندارد؛ اضافه کردنشان از پرش صفحه (CLS) جلوگیری می‌کند'); }
		if (imgs) { add('ok', 'تصاویر خودکار lazy می‌شوند و تصویر اول بخش اول با اولویت بالا لود می‌شود'); }
		// متن
		if (!cfg.isSection) {
			if (words < 300) { add('warn', 'محتوای متنی کم است (' + words + ' کلمه)؛ صفحه‌های مهم معمولاً ۳۰۰+ کلمه دارند'); }
			else { add('ok', 'حجم متن: ' + words + ' کلمه'); }
		}
		// اسکیما
		if (types.length) {
			add('ok', 'اسکیما در <head> چاپ می‌شود: ' + types.join('، '));
			if (cfg.seoPlugin) {
				var dup = types.filter(function (t) { return SCHEMA_BY_PLUGIN.indexOf(t) > -1; });
				if (dup.length) { add('warn', cfg.seoPlugin + ' خودش ' + dup.join('، ') + ' را می‌سازد؛ اسکیمای تکراری را از کد حذف کنید یا در ' + cfg.seoPlugin + ' خاموشش کنید'); }
			}
		}
		if (linked) { add('info', linked + ' بخش لینک‌شده از کتابخانه است؛ محتوای آن‌ها در صفحه خودشان بررسی می‌شود ولی در سئوی این صفحه حساب می‌شوند'); }
		// پایه
		add('ok', 'CSS و فونت‌ها در <head> لود می‌شوند (بدون پرش صفحه) و اسکریپت‌ها در انتهای صفحه');
		if (!cfg.isSection) { add('ok', 'متن صفحه با هر ذخیره برای ' + (cfg.seoPlugin || 'افزونه سئو') + '، جستجوی سایت و نقشه سایت همگام می‌شود'); }
		return res;
	}

	function renderSeo() {
		var r = seoAudit(), bad = r.error.length, warn = r.warn.length;
		sBadge.innerHTML = (bad || warn) ? String(bad || warn) : ic('check');
		sBadge.className = 'kashi-badge ' + (bad ? 'bad' : (warn ? 'warn' : 'ok'));
		if (!sPanel.classList.contains('open')) { return; }
		$('kashi-seo-info').textContent = cfg.seoPlugin
			? 'عنوان سئو، توضیحات متا، کنونیکال، Open Graph و اسکیمای پایه را ' + cfg.seoPlugin + ' می‌سازد و این پلاگین با آن کاملاً سازگار است. اینجا فقط محتوای بخش‌ها بررسی می‌شود.'
			: 'افزونه سئو (مثل Rank Math یا Yoast) فعال نیست؛ برای عنوان و توضیحات متا، کنونیکال و نقشه سایت یکی نصب کنید.';
		sList.innerHTML = '';
		[['error', 'x-circle', 'مشکلات'], ['warn', 'alert', 'هشدارها'], ['info', 'info', 'پیشنهادها'], ['ok', 'check-circle', 'موارد درست']].forEach(function (g) {
			if (!r[g[0]].length) { return; }
			var h = document.createElement('div'); h.className = 'kashi-chk-h'; h.innerHTML = esc(g[2]) + '<span>' + r[g[0]].length + '</span>'; sList.appendChild(h);
			r[g[0]].forEach(function (it) {
				var el = document.createElement('div');
				el.className = 'kashi-chk ' + g[0] + (it.bi !== undefined ? ' go' : '');
				el.innerHTML = '<span class="i"></span><div><span class="m"></span><small></small></div>';
				el.querySelector('.i').innerHTML = ic(g[1]);
				el.querySelector('.m').textContent = it.msg;
				el.querySelector('small').textContent = it.sub;
				if (it.bi !== undefined) {
					el.title = 'رفتن به این بخش';
					el.addEventListener('click', function (e) { e.stopPropagation(); select(it.bi, true); });
				}
				sList.appendChild(el);
			});
		});
	}
	function seoLater() { clearTimeout(seoTimer); seoTimer = setTimeout(renderSeo, 600); }
	popup(sBtn, sPanel, renderSeo);

	/* ---------- ویرایش مستقیم (بدون کد) ----------
	 * روی متن یا تصویر داخل پیش‌نمایش کلیک کنید و همان‌جا تغییر دهید.
	 * تغییر دقیقاً در همان نقطه از کد منبع اعمال می‌شود؛ بقیه کد، فاصله‌ها و
	 * قالب‌بندی شما دست نمی‌خورد و Ctrl+Z داخل کد هم کار می‌کند.
	 */
	var vBtn = $('kashi-vedit'), vOn = false, vSel = null, vTimer = null, vQuiet = false, vDoc = null, vHover = null;
	var V_SKIP = { style: 1, script: 1, link: 1, meta: 1, title: 1, template: 1, head: 1 };
	var V_VOID = { area: 1, base: 1, br: 1, col: 1, embed: 1, hr: 1, img: 1, input: 1, link: 1, meta: 1, param: 1, source: 1, track: 1, wbr: 1 };
	var V_RAW = { script: 1, style: 1, textarea: 1, title: 1 };
	var MSG_DYN = 'این قسمت را اسکریپت یا شورت‌کد می‌سازد؛ آن را از داخل کد ویرایش کنید.';

	/* تجزیه کد منبع با موقعیت دقیق هر تگ */
	function vParse(src) {
		var root = { tag: '#root', kids: [], innerStart: 0, innerEnd: src.length }, stack = [root], m;
		var re = /<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<![^>]*>|<\?[\s\S]*?(?:\?>|$)|<\/([a-zA-Z][\w:-]*)\s*>|<([a-zA-Z][\w:-]*)((?:\s+[^\s"'>\/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*)\s*(\/?)>/g;
		var low = src.toLowerCase();
		while ((m = re.exec(src))) {
			if (m[1]) {
				var t = m[1].toLowerCase(), k;
				for (k = stack.length - 1; k > 0 && stack[k].tag !== t; k--) { }
				if (k > 0) {
					for (var j = stack.length - 1; j > k; j--) { stack[j].innerEnd = stack[j].end = m.index; }
					stack[k].innerEnd = m.index; stack[k].end = re.lastIndex;
					stack.length = k;
				}
			} else if (m[2]) {
				var tag = m[2].toLowerCase(), top = stack[stack.length - 1];
				var n = { tag: tag, start: m.index, tagEnd: re.lastIndex, kids: [] };
				top.kids.push(n);
				if (V_VOID[tag] || m[4] === '/') {
					n.innerStart = n.innerEnd = n.end = re.lastIndex;
				} else if (V_RAW[tag]) {
					var c = low.indexOf('</' + tag, re.lastIndex);
					if (c < 0) { c = src.length; }
					var gt = src.indexOf('>', c);
					n.innerStart = re.lastIndex; n.innerEnd = c; n.end = gt < 0 ? src.length : gt + 1;
					re.lastIndex = n.end;
				} else {
					n.innerStart = re.lastIndex;
					stack.push(n);
				}
			}
		}
		for (var q = 1; q < stack.length; q++) { stack[q].innerEnd = stack[q].end = src.length; }
		return root;
	}
	function vFindBody(n) {
		if (n.tag === 'body') { return n; }
		for (var i = 0; i < n.kids.length; i++) { var r = vFindBody(n.kids[i]); if (r) { return r; } }
		return null;
	}
	function vKids(n) { return n.kids.filter(function (k) { return !V_SKIP[k.tag]; }); }
	function vDomKids(el) {
		return Array.prototype.filter.call(el.children, function (k) { return !V_SKIP[k.tagName.toLowerCase()] && k.id !== 'kashi-vbar'; });
	}
	function vPath(el, rootEl) {
		var p = [];
		while (el && el !== rootEl) {
			var par = el.parentElement;
			if (!par) { return null; }
			p.unshift({ tag: el.tagName.toLowerCase(), i: vDomKids(par).indexOf(el) });
			el = par;
		}
		return el === rootEl ? p : null;
	}
	function vMap(code, path) {
		var tree = vParse(code), node = vFindBody(tree) || tree;
		for (var s = 0; s < path.length; s++) {
			var kids = vKids(node), st = path[s];
			if (st.tag === 'tbody' && kids[st.i] && kids[st.i].tag !== 'tbody') { continue; } // tbody ضمنی مرورگر
			var c = kids[st.i];
			if (!c || c.tag !== st.tag) { return null; }
			node = c;
		}
		return node === tree ? null : node;
	}
	function vNorm(t) { return String(t || '').replace(/\s+/g, ' ').trim(); }
	function vText(html) { return vNorm(new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html').body.textContent); }
	function vEsc(t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\u00a0/g, '&nbsp;'); }
	function vEscAttr(t) { return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	function vSetAttr(tag, name, val) {
		var re = new RegExp('\\s' + name + '\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)', 'i');
		var a = ' ' + name + '="' + vEscAttr(val) + '"';
		return re.test(tag) ? tag.replace(re, a) : tag.replace(/\s*(\/?)>$/, a + '$1>');
	}
	function vSetStyle(tag, prop, val) {
		var parts = vAttrOf(tag, 'style').split(';').map(function (x) { return x.trim(); }).filter(function (x) {
			return x && x.split(':')[0].trim().toLowerCase() !== prop;
		});
		if (val) { parts.push(prop + ': ' + val); }
		return parts.length ? vSetAttr(tag, 'style', parts.join('; ')) : vDelAttr(tag, 'style');
	}
	function vHex(c) {
		var m = String(c || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/);
		if (!m || (m[4] !== undefined && +m[4] === 0)) { return ''; }
		return '#' + [m[1], m[2], m[3]].map(function (x) { return ('0' + (+x).toString(16)).slice(-2); }).join('');
	}
	/* کنترل‌های رنگ: تغییر زنده در پیش‌نمایش، ثبت در کد هنگام انتخاب نهایی */
	function vColors(withBg) {
		var h = '<label class="cl" title="رنگ متن / آیکون"><input type="color" data-c="color"><span>' + ic('drop') + 'رنگ</span></label>';
		if (withBg) { h += '<label class="cl" title="رنگ پس‌زمینه"><input type="color" data-c="background-color"><span class="bgi">' + ic('drop') + 'پس‌زمینه</span></label>'; }
		return h + '<button data-v="nocolor" class="ghost" title="برگرداندن رنگ اصلی">' + ic('refresh') + '</button>';
	}
	function vBindColors(bar, el, bi, path) {
		var cs = vDoc.defaultView.getComputedStyle(el);
		bar.querySelectorAll('input[data-c]').forEach(function (inp) {
			var prop = inp.getAttribute('data-c');
			var dot = inp.parentNode.querySelector('.ic');
			inp.value = vHex(prop === 'color' ? cs.color : cs.backgroundColor) || (prop === 'color' ? '#000000' : '#ffffff');
			dot.style.fill = inp.value;
			inp.addEventListener('input', function () { el.style.setProperty(prop, inp.value); dot.style.fill = inp.value; });
			inp.addEventListener('change', function () {
				var v = inp.value;
				if (vEditTag(bi, path, function (t) { return vSetStyle(t, prop, v); })) { el.style.setProperty(prop, v); }
			});
		});
		var rb = bar.querySelector('[data-v=nocolor]');
		if (rb) {
			rb.addEventListener('click', function () {
				if (vEditTag(bi, path, function (t) { return vSetStyle(vSetStyle(t, 'color', ''), 'background-color', ''); })) {
					el.style.removeProperty('color'); el.style.removeProperty('background-color');
					if (!el.getAttribute('style')) { el.removeAttribute('style'); }
					toast('رنگ اصلی برگشت', 'ok');
				}
			});
		}
	}

	/* آیکون SVG داخل پیش‌نمایش: تعویض و رنگ */
	function vSelectSvg(sv) {
		var loc = vLocate(sv);
		if (loc.err !== undefined) { if (loc.err) { toast(loc.err); } return; }
		var code = codeOf(blocks[loc.bi]), node = vMap(code, loc.path);
		if (!node || node.tag !== 'svg') { toast(MSG_DYN); return; }
		if (blocks[loc.bi].uid !== activeUid) { select(loc.bi, false); }
		vSel = { bi: loc.bi, path: loc.path, el: sv, svg: true };
		sv.setAttribute('data-kv-edit', '');
		var bar = vBar(sv, '<button data-v="swap">' + ic('shapes') + 'تعویض آیکون</button>' + vColors(false) + '<button data-v="done">' + ic('check') + '</button>');
		bar.querySelector('[data-v=swap]').addEventListener('click', function () {
			iReplace = { bi: loc.bi, path: loc.path, el: sv };
			openIcons();
		});
		vBindColors(bar, sv, loc.bi, loc.path);
		bar.querySelector('[data-v=done]').addEventListener('click', vFinish);
	}
	function vReplaceSvg(r, x) {
		var code = codeOf(blocks[r.bi]), node = vMap(code, r.path);
		if (!node || node.tag !== 'svg') { toast(MSG_DYN); return; }
		var tag = code.slice(node.start, node.tagEnd);
		var keep = { cls: vEscAttr(vAttrOf(tag, 'class')), w: vEscAttr(vAttrOf(tag, 'width')), h: vEscAttr(vAttrOf(tag, 'height')), style: vEscAttr(vAttrOf(tag, 'style')) };
		if (!keep.w && !keep.h) { var bb = r.el.getBoundingClientRect(); keep.w = keep.h = String(Math.round(bb.width) || iSize); }
		var svg = iconSvg(x, iSize, keep);
		vApply(r.bi, node.start, node.end, svg);
		var t = r.el.ownerDocument.createElement('template');
		t.innerHTML = svg;
		var nw = t.content.firstElementChild;
		if (vSel && vSel.el === r.el) { vSel = null; vHideBar(); }
		r.el.replaceWith(nw);
		toast('آیکون عوض شد', 'ok');
	}

	function vDelAttr(tag, name) { return tag.replace(new RegExp('\\s' + name + '\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)', 'i'), ''); }
	function vAttrOf(tag, name) {
		var el = new DOMParser().parseFromString('<body>' + tag + '</body>', 'text/html').body.firstElementChild;
		return el ? (el.getAttribute(name) || '') : '';
	}

	/* اعمال تغییر روی کد یک کاشی بدون بازسازی پیش‌نمایش */
	function vApply(bi, from, to, text) {
		var b = blocks[bi];
		if (!b) { return; }
		vQuiet = true;
		if (b.doc) {
			b.doc.replaceRange(text, b.doc.posFromIndex(from), b.doc.posFromIndex(to), '+kashi-visual');
		} else {
			b.code = b.code.slice(0, from) + text + b.code.slice(to);
			if (b.uid === activeUid) { ta.value = b.code; }
		}
		vQuiet = false;
		markDirty(); updateBadges(); phpWarn();
		if (typeof seoLater === 'function') { seoLater(); }
	}

	/* کاشی و مسیر یک المان داخل پیش‌نمایش */
	function vLocate(el) {
		var blk = el.closest('[data-kashi-block]');
		if (!blk) { return { err: '' }; }
		var outer = blk, up;
		while (outer.parentElement && (up = outer.parentElement.closest('[data-kashi-block]'))) { outer = up; }
		var uid = outer.getAttribute('data-kashi-block'), bi = -1;
		for (var k = 0; k < blocks.length; k++) { if (blocks[k].uid === uid) { bi = k; break; } }
		if (bi < 0) { return { err: 'این قسمت جزو این صفحه نیست (مثلاً هدر یا فوتر)؛ از ویرایشگر خودش ویرایش کنید.' }; }
		if (blk !== outer) { return { err: 'این کاشی لینک‌شده از کتابخانه است؛ اصل آن را ویرایش کنید تا همه‌جا عوض شود.' }; }
		var path = vPath(el, outer);
		return path ? { bi: bi, root: outer, path: path } : { err: MSG_DYN };
	}
	function vHasText(el) {
		for (var n = el.firstChild; n; n = n.nextSibling) { if (n.nodeType === 3 && n.nodeValue.trim()) { return true; } }
		return false;
	}
	function vTarget(t) {
		var el = t && t.nodeType === 3 ? t.parentElement : t;
		while (el && el.nodeType === 1 && !el.hasAttribute('data-kashi-block')) {
			var tn = el.tagName.toLowerCase();
			if (tn === 'img') { return el; }
			if (!/^(svg|path|g|use|circle|rect|line|polyline|polygon|script|style|input|select|textarea|iframe|video)$/.test(tn) && vHasText(el)) { return el; }
			el = el.parentElement;
		}
		return null;
	}

	/* نوار کوچک ویرایش داخل پیش‌نمایش */
	function vBar(el, html) {
		var d = vDoc, bar = d.getElementById('kashi-vbar');
		if (!bar) { bar = d.createElement('div'); bar.id = 'kashi-vbar'; d.body.appendChild(bar); }
		bar.innerHTML = html;
		bar.style.visibility = 'hidden';
		bar.style.display = 'flex'; // اول نمایش، بعد اندازه‌گیری
		var r = el.getBoundingClientRect(), w = d.defaultView, bw = bar.offsetWidth, vw = d.documentElement.clientWidth;
		var top = r.top + w.scrollY - bar.offsetHeight - 8;
		if (r.top < bar.offsetHeight + 12) { top = r.bottom + w.scrollY + 8; }
		var left = r.right + w.scrollX - bw; // راست‌چین با المان
		left = Math.max(w.scrollX + 8, Math.min(left, w.scrollX + vw - bw - 8));
		bar.style.top = top + 'px';
		bar.style.left = left + 'px';
		bar.style.visibility = 'visible';
		return bar;
	}
	function vHideBar() { var b = vDoc && vDoc.getElementById('kashi-vbar'); if (b) { b.style.display = 'none'; } }

	function vFinish() {
		clearTimeout(vTimer);
		if (vSel && vSel.text) { vCommitText(); vSel.el.removeAttribute('contenteditable'); }
		if (vSel) { vSel.el.removeAttribute('data-kv-edit'); }
		vSel = null;
		vHideBar();
	}

	function vCommitText() {
		if (!vSel || !vSel.text) { return; }
		var code = codeOf(blocks[vSel.bi]), node = vMap(code, vSel.path), out = '';
		if (!node) { return; }
		var srcKids = vKids(node);
		// مرورگر هنگام تایپ، فاصله کنار تگ‌ها را &nbsp; می‌کند؛ اگر متن اصلی &nbsp; نداشت، فاصله عادی بگذار
		var origInner = code.slice(node.innerStart, node.innerEnd), keepNbsp = /&nbsp;|&#160;|\u00a0/.test(origInner);
		for (var n = vSel.el.firstChild; n; n = n.nextSibling) {
			if (n.nodeType === 3) { out += vEsc(keepNbsp ? n.nodeValue : n.nodeValue.replace(/\u00a0/g, ' ')); continue; }
			if (n.nodeType !== 1) { continue; }
			if (n.tagName === 'BR') { out += '<br>'; continue; }
			var orig = vSel.kids.filter(function (k) { return k.el === n; })[0];
			if (orig && n.outerHTML === orig.html && srcKids[orig.i]) {
				out += code.slice(srcKids[orig.i].start, srcKids[orig.i].end); // همان کد اصلی، دست‌نخورده
			} else {
				var c = n.cloneNode(true);
				[c].concat(Array.prototype.slice.call(c.querySelectorAll('*'))).forEach(function (x) {
					['contenteditable', 'data-kv-edit', 'data-kv-hover', 'spellcheck'].forEach(function (a) { x.removeAttribute(a); });
				});
				out += c.outerHTML;
			}
		}
		if (code.slice(node.innerStart, node.innerEnd) !== out) { vApply(vSel.bi, node.innerStart, node.innerEnd, out); }
	}

	function vEditTag(bi, path, fn) {
		var code = codeOf(blocks[bi]), node = vMap(code, path);
		if (!node) { toast(MSG_DYN); return false; }
		var tag = code.slice(node.start, node.tagEnd), nt = fn(tag);
		if (nt !== tag) { vApply(bi, node.start, node.tagEnd, nt); }
		return true;
	}

	function vSelect(el, e) {
		var loc = vLocate(el);
		if (loc.err !== undefined) { if (loc.err) { toast(loc.err); } return; }
		var code = codeOf(blocks[loc.bi]), node = vMap(code, loc.path);
		if (blocks[loc.bi].uid !== activeUid) { select(loc.bi, false); }

		if (el.tagName === 'IMG') {
			if (!node || node.tag !== 'img' || vAttrOf(code.slice(node.start, node.tagEnd), 'src') !== (el.getAttribute('src') || '')) { toast(MSG_DYN); return; }
			vSel = { bi: loc.bi, path: loc.path, el: el, img: true };
			el.setAttribute('data-kv-edit', '');
			var bar = vBar(el, '<button data-v="img">' + ic('image') + 'تعویض تصویر</button><input data-v="alt" placeholder="متن جایگزین (alt)" value="' + vEscAttr(el.getAttribute('alt') || '') + '"><button data-v="done">' + ic('check') + '</button>');
			bar.querySelector('[data-v=img]').addEventListener('click', function () {
				pickImage(function (att) {
					var ok = vEditTag(loc.bi, loc.path, function (t) {
						t = vDelAttr(vDelAttr(t, 'srcset'), 'sizes');
						t = vSetAttr(t, 'src', att.url);
						if (att.width && /\swidth\s*=/i.test(t)) { t = vSetAttr(vSetAttr(t, 'width', att.width), 'height', att.height); }
						if (att.alt && !vAttrOf(t, 'alt')) { t = vSetAttr(t, 'alt', att.alt); }
						return t;
					});
					if (ok) {
						el.removeAttribute('srcset'); el.removeAttribute('sizes'); el.src = att.url;
						if (att.alt && !el.getAttribute('alt')) { el.setAttribute('alt', att.alt); bar.querySelector('[data-v=alt]').value = att.alt; }
						toast('تصویر عوض شد', 'ok');
					}
				});
			});
			bar.querySelector('[data-v=alt]').addEventListener('change', function () {
				var v = this.value;
				if (vEditTag(loc.bi, loc.path, function (t) { return vSetAttr(t, 'alt', v); })) { el.setAttribute('alt', v); }
			});
			bar.querySelector('[data-v=done]').addEventListener('click', vFinish);
			return;
		}

		// متن
		if (!node || vText(code.slice(node.innerStart, node.innerEnd)) !== vNorm(el.textContent)) { toast(MSG_DYN); return; }
		var kids = vDomKids(el).map(function (k, i) { return { el: k, html: k.outerHTML, i: i }; });
		vSel = { bi: loc.bi, path: loc.path, el: el, text: true, kids: kids };
		el.setAttribute('data-kv-edit', '');
		el.setAttribute('contenteditable', 'true');
		el.focus();
		if (e && vDoc.caretRangeFromPoint) {
			var rg = vDoc.caretRangeFromPoint(e.clientX, e.clientY);
			if (rg) { var sl = vDoc.defaultView.getSelection(); sl.removeAllRanges(); sl.addRange(rg); }
		}
		var a = el.closest('a'), aLoc = a ? vLocate(a) : null;
		var html = '<span class="lb">' + ic('pen') + 'ویرایش متن</span>';
		if (aLoc && aLoc.path) { html += '<input data-v="href" placeholder="آدرس لینک" value="' + vEscAttr(a.getAttribute('href') || '') + '">'; }
		html += vColors(true) + '<button data-v="done">' + ic('check') + 'تمام</button>';
		var bar2 = vBar(el, html);
		vBindColors(bar2, el, loc.bi, loc.path);
		var hin = bar2.querySelector('[data-v=href]');
		if (hin) {
			hin.addEventListener('change', function () {
				var v = this.value.trim();
				if (vEditTag(aLoc.bi, aLoc.path, function (t) { return vSetAttr(t, 'href', v); })) { a.setAttribute('href', v); toast('لینک عوض شد', 'ok'); }
			});
		}
		bar2.querySelector('[data-v=done]').addEventListener('click', vFinish);
	}

	function pickImage(cb) {
		if (window.wp && wp.media) {
			var f = wp.media({ title: 'انتخاب تصویر', library: { type: 'image' }, multiple: false, button: { text: 'استفاده از این تصویر' } });
			f.on('select', function () { cb(f.state().get('selection').first().toJSON()); });
			f.open();
		} else {
			var u = window.prompt('آدرس تصویر جدید:');
			if (u) { cb({ url: u }); }
		}
	}

	/* رویدادهای داخل پیش‌نمایش */
	function vOnOver(e) {
		if (vSel && vSel.el.contains(e.target)) { return; }
		var sv = e.target.closest && e.target.closest('svg'), t = (sv && !sv.closest('#kashi-vbar')) ? sv : vTarget(e.target);
		if (vHover && vHover !== t) { vHover.removeAttribute('data-kv-hover'); }
		vHover = t && t.closest('[data-kashi-block]') ? t : null;
		if (vHover) { vHover.setAttribute('data-kv-hover', ''); }
	}
	function vOnClick(e) {
		var bar = vDoc.getElementById('kashi-vbar');
		if (bar && bar.contains(e.target)) { return; }
		var inEdit = vSel && vSel.el.contains(e.target);
		e.preventDefault(); e.stopPropagation();
		if (inEdit) { return; }
		vFinish();
		var sv = e.target.closest && e.target.closest('svg');
		if (sv && sv.closest('[data-kashi-block]')) { vSelectSvg(sv); return; }
		var t = vTarget(e.target);
		if (t && t.closest('[data-kashi-block]')) { vSelect(t, e); }
	}
	function vOnKey(e) {
		if (!vSel || !vSel.text || !vSel.el.contains(e.target)) { return; }
		if (e.key === 'Enter') {
			e.preventDefault();
			if (e.shiftKey) { vDoc.execCommand('insertLineBreak'); } else { vFinish(); }
			return;
		}
		if (e.key === 'Escape') { e.preventDefault(); vFinish(); return; }
		if ((e.ctrlKey || e.metaKey) && /^[biuBIU]$/.test(e.key)) { e.preventDefault(); return; } // بدون قالب‌بندی ناخواسته
		if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); vCommitText(); save(); }
	}
	function vOnInput(e) {
		if (!vSel || !vSel.text) { return; }
		clearTimeout(vTimer);
		vTimer = setTimeout(vCommitText, 350);
	}
	function vOnPaste(e) {
		if (!vSel || !vSel.text) { return; }
		e.preventDefault();
		var t = (e.clipboardData || window.clipboardData).getData('text/plain');
		vDoc.execCommand('insertText', false, t.replace(/\r?\n+/g, ' '));
	}
	var V_EVENTS = [['mouseover', vOnOver], ['click', vOnClick], ['keydown', vOnKey], ['input', vOnInput], ['paste', vOnPaste]];

	function vInstall() {
		var d = frameDoc();
		if (!d || !d.body || vDoc === d) { return; }
		vDoc = d;
		var st = d.createElement('style');
		st.id = 'kashi-vstyle';
		st.textContent = '[data-kashi-block] [data-kv-hover]{outline:1px dashed rgba(124,108,255,.9)!important;outline-offset:3px;cursor:text}' +
			'[data-kashi-block] img[data-kv-hover]{cursor:pointer}' +
			'[data-kv-edit]{outline:2px solid #7c6cff!important;outline-offset:3px;border-radius:2px}' +
			'[contenteditable][data-kv-edit]{cursor:text;caret-color:#7c6cff}' +
			'#kashi-vbar{position:absolute;z-index:2147483647;display:none;align-items:center;gap:6px;padding:6px;background:#13161d;color:#e6e8ee;border:1px solid #2e3442;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.35);font:12px/1.4 Vazirmatn,Tahoma,sans-serif;direction:rtl}' +
			'#kashi-vbar .lb{display:inline-flex;align-items:center;gap:6px;padding:0 6px;color:#8b92a5}' +
			'#kashi-vbar button{display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 10px;border:0;border-radius:7px;background:#6b5af2;color:#fff;font:inherit;cursor:pointer}' +
			'#kashi-vbar button:hover{background:#6b5af2}' +
			'#kashi-vbar input{height:28px;width:220px;padding:0 8px;border:1px solid #2e3442;border-radius:7px;background:#0d0f14;color:#e6e8ee;font:inherit;direction:ltr}' +
			'#kashi-vbar .ic{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
			'#kashi-vbar .cl{position:relative;display:inline-flex;align-items:center;height:28px;padding:0 8px;border:1px solid #2e3442;border-radius:7px;cursor:pointer;color:#e6e8ee}' +
			'#kashi-vbar .cl span{display:inline-flex;align-items:center;gap:4px;pointer-events:none}' +
			'#kashi-vbar .cl input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;padding:0;border:0}' +
			'#kashi-vbar button.ghost{background:transparent;border:1px solid #2e3442;padding:0 8px}#kashi-vbar button.ghost:hover{background:#20252f}' +
			'[data-kashi-block] svg[data-kv-hover]{cursor:pointer}';
		d.head.appendChild(st);
		// آیکون‌ها برای نوار داخل پیش‌نمایش
		var sp = document.querySelector('svg[style*="display:none"]');
		if (sp && !d.getElementById('kashi-vsprite')) { var c = d.importNode(sp, true); c.id = 'kashi-vsprite'; d.body.appendChild(c); }
		V_EVENTS.forEach(function (ev) { d.addEventListener(ev[0], ev[1], true); });
		d.documentElement.classList.add('kashi-vedit-on');
	}
	function vUninstall() {
		vFinish();
		if (!vDoc) { return; }
		var d = vDoc;
		V_EVENTS.forEach(function (ev) { d.removeEventListener(ev[0], ev[1], true); });
		['kashi-vstyle', 'kashi-vbar', 'kashi-vsprite'].forEach(function (id) { var x = d.getElementById(id); if (x) { x.remove(); } });
		if (vHover) { vHover.removeAttribute('data-kv-hover'); vHover = null; }
		d.documentElement.classList.remove('kashi-vedit-on');
		vDoc = null;
	}
	if (vBtn) {
		vBtn.addEventListener('click', function () {
			vOn = !vOn;
			vBtn.classList.toggle('on', vOn);
			if (vOn) { vInstall(); toast('روی هر متن کلیک کنید و بنویسید؛ روی تصویر برای تعویض. Enter = تمام', 'ok'); }
			else { vUninstall(); }
		});
	}
	frame.addEventListener('load', function () { vDoc = null; vSel = null; vHover = null; if (vOn) { vInstall(); } });

	/* ---------- اندازه‌ها ----------
	 * پیش‌نمایش با عرض واقعی هر دستگاه رندر می‌شود (تا media query ها و «نمایش در دستگاه‌ها»
	 * دقیقاً مثل سایت رفتار کنند) و اگر جا نشود، کوچک‌نمایی می‌شود. */
	var devW = 1440;
	function fitFrame() {
		var view = frame.parentNode, vw = view.clientWidth, vh = view.clientHeight;
		var k = devW > vw ? vw / devW : 1;
		frame.style.width = devW + 'px';
		frame.style.height = (k < 1 ? vh / k : vh) + 'px';
		frame.style.transform = k < 1 ? 'scale(' + k + ')' : '';
		view.classList.toggle('scaled', k < 1);
		var z = $('kashi-zoom');
		if (z) { z.textContent = devW + 'px' + (k < 1 ? ' · ' + Math.round(k * 100) + '%' : ''); }
	}
	window.addEventListener('resize', fitFrame);
	fitFrame();
	document.querySelectorAll('.kashi-dev button').forEach(function (b) {
		b.addEventListener('click', function () {
			document.querySelectorAll('.kashi-dev button').forEach(function (x) { x.classList.remove('on'); x.setAttribute('aria-pressed', 'false'); });
			b.classList.add('on'); b.setAttribute('aria-pressed', 'true');
			devW = +b.getAttribute('data-w');
			fitFrame();
		});
	});

	var div = $('kashi-div'), pane = document.querySelector('.kashi-left'), main = document.querySelector('.kashi-main');
	div.addEventListener('keydown', function (e) {
		if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
		e.preventDefault();
		var r = main.getBoundingClientRect(), w = pane.getBoundingClientRect().width + (e.key === 'ArrowRight' ? 1 : -1) * (e.shiftKey ? 80 : 24);
		pane.style.width = Math.min(Math.max(w, 260), r.width - 200) + 'px';
		if (cm) { cm.refresh(); }
		fitFrame();
	});
	div.addEventListener('mousedown', function (e) {
		e.preventDefault();
		document.body.classList.add('kashi-drag');
		function move(ev) {
			var r = main.getBoundingClientRect();
			pane.style.width = Math.min(Math.max(ev.clientX - r.left, 260), r.width - 200) + 'px';
			if (cm) { cm.refresh(); }
			fitFrame();
		}
		function up() {
			document.body.classList.remove('kashi-drag');
			document.removeEventListener('mousemove', move);
			document.removeEventListener('mouseup', up);
		}
		document.addEventListener('mousemove', move);
		document.addEventListener('mouseup', up);
	});

	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = ''; }
	});

	/* ---------- شروع ---------- */
	(cfg.blocks || []).forEach(function (b) { blocks.push(makeBlock(b)); });
	if (!blocks.length) { blocks.push(makeBlock({ name: 'هیرو' })); }
	a11y(document);
	renderList();
	select(0, false);
	refresh();
	renderSeo();
})();
</script>
</body>
</html>
		<?php
		exit;
	}

	public static function ajax_save() {
		$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $id || ! check_ajax_referer( 'kashi_save_' . $id, 'nonce', false ) ) {
			wp_send_json_error( 'نشست شما منقضی شده؛ قبل از بستن، کدها را کپی کنید و صفحه را دوباره باز کنید.' );
		}
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'اجازه ویرایش ندارید.' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON کد خام؛ در compile بر اساس دسترسی کنترل می‌شود.
		$blocks   = self::blocks_from_json( isset( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : '' );
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$scope_on = ! empty( $_POST['scope'] );
		$trusted  = current_user_can( 'unfiltered_html' );

		if ( ! $trusted ) {
			$blocks = self::sanitize_blocks( $blocks ); // منبع هم پاک‌سازی می‌شود، نه فقط خروجی
		}

		// اولین ذخیره بعد از نصب این نسخه: وضعیت قبلی هم در تاریخچه بماند
		if ( ! self::get_revs( $id ) && self::get_blocks( $id ) ) {
			self::push_rev( $id, self::snapshot( $id ), get_post_modified_time( 'U', true, $post ), 'نسخه قبلی', ! self::post_untrusted( $id ) );
		}

		update_post_meta( $id, '_lsec_untrusted', $trusted ? '0' : '1' );
		update_post_meta( $id, '_lsec_blocks', wp_slash( $blocks ) );
		update_post_meta( $id, '_lsec_scope', $scope_on ? '1' : '0' );
		$compiled = self::compile_blocks( $blocks, $scope_on, $trusted );
		update_post_meta( $id, '_lsec_compiled', wp_slash( $compiled ) );
		delete_post_meta( $id, '_lsec_code' );
		delete_post_meta( $id, '_lsec_full' );
		if ( self::CPT !== $post->post_type ) {
			update_post_meta( $id, '_lsec_enabled', '1' );
			if ( isset( $_POST['layout'] ) ) {
				update_post_meta( $id, '_lsec_layout', self::sanitize_layout( sanitize_key( wp_unslash( $_POST['layout'] ) ) ) );
			}
			if ( isset( $_POST['boxw'] ) ) {
				$w = absint( $_POST['boxw'] );
				update_post_meta( $id, '_lsec_boxw', $w ? min( max( $w, 320 ), 3000 ) : 1200 );
			}
		}

		if ( self::CPT === $post->post_type && isset( $_POST['hf_location'] ) ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$loc  = sanitize_key( wp_unslash( $_POST['hf_location'] ) );
			$mode = isset( $_POST['hf_mode'] ) ? sanitize_key( wp_unslash( $_POST['hf_mode'] ) ) : 'all';
			$ids  = isset( $_POST['hf_ids'] ) ? (string) wp_unslash( $_POST['hf_ids'] ) : '';
			$tgt  = isset( $_POST['hf_target'] ) ? (string) wp_unslash( $_POST['hf_target'] ) : '';
			// phpcs:enable
			update_post_meta( $id, '_lsec_location', in_array( $loc, array( 'header', 'footer' ), true ) ? $loc : '' );
			update_post_meta(
				$id,
				'_lsec_cond',
				array(
					'mode' => in_array( $mode, array( 'all', 'front', 'include', 'exclude' ), true ) ? $mode : 'all',
					'ids'  => self::parse_ids( $ids ),
				)
			);
			update_post_meta( $id, '_lsec_target', self::sanitize_target( $tgt ) );
		}

		$update = array( 'ID' => $id );
		if ( '' !== $title && $title !== $post->post_title ) {
			$update['post_title'] = $title;
		}
		if ( self::CPT === $post->post_type && 'publish' !== $post->post_status && current_user_can( 'publish_pages' ) ) {
			$update['post_status'] = 'publish';
		} elseif ( 'auto-draft' === $post->post_status ) {
			$update['post_status'] = 'draft';
		}
		// سئو: محتوای متنی صفحه در post_content هم ذخیره می‌شود (مثل المنتور)
		if ( self::CPT !== $post->post_type ) {
			$seo = self::seo_html( $compiled );
			if ( $seo !== $post->post_content ) {
				$update['post_content'] = $seo;
			}
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}

		self::push_rev( $id, self::snapshot( $id ), null, null, $trusted );
		if ( self::CPT === $post->post_type ) {
			self::hf_rebuild_index();
		}

		wp_send_json_success( array( 'revs' => self::revs_meta( $id ) ) );
	}

	/** محتوای یک نسخه از تاریخچه */
	public static function ajax_rev_get() {
		$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $id || ! check_ajax_referer( 'kashi_save_' . $id, 'nonce', false ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'اجازه ندارید یا نشست منقضی شده است.' );
		}
		$revs = self::get_revs( $id );
		$i    = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
		if ( ! isset( $revs[ $i ]['data'] ) ) {
			wp_send_json_error( 'این نسخه پیدا نشد.' );
		}
		$data = $revs[ $i ]['data'];
		$safe = isset( $revs[ $i ]['trusted'] ) ? (bool) $revs[ $i ]['trusted'] : ! self::post_untrusted( $id );
		if ( ! $safe || ! current_user_can( 'unfiltered_html' ) ) {
			$data['blocks'] = self::sanitize_blocks( isset( $data['blocks'] ) ? (array) $data['blocks'] : array() );
		}
		wp_send_json_success( $data );
	}

	/* ------------------------------------------------------------------ *
	 * کتابخانه کاشی
	 * ------------------------------------------------------------------ */
	private static function lib_check() {
		if ( ! check_ajax_referer( 'kashi_lib', 'nonce', false ) || ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( 'اجازه ندارید یا نشست منقضی شده است.' );
		}
	}

	public static function ajax_lib_list() {
		self::lib_check();
		$exclude = isset( $_POST['exclude'] ) ? absint( $_POST['exclude'] ) : 0;
		$posts   = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 300,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'post__not_in'   => $exclude ? array( $exclude ) : array(),
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			if ( ! current_user_can( 'edit_post', $p->ID ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $p->ID,
				'title' => '' !== $p->post_title ? $p->post_title : ( 'کاشی #' . $p->ID ),
				'n'     => count( self::get_blocks( $p->ID ) ),
				'ago'   => human_time_diff( (int) get_post_modified_time( 'U', true, $p ), time() ) . ' پیش',
			);
		}
		wp_send_json_success( $out );
	}

	public static function ajax_lib_get() {
		self::lib_check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || self::CPT !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'این مورد در کتابخانه پیدا نشد.' );
		}
		$src = self::get_blocks( $id );
		if ( self::post_untrusted( $id ) || ! current_user_can( 'unfiltered_html' ) ) {
			$src = self::sanitize_blocks( $src );
		}
		$blocks = array();
		foreach ( $src as $b ) {
			unset( $b['uid'] ); // نسخه کپی شناسه جدید می‌گیرد
			$blocks[] = $b;
		}
		wp_send_json_success( $blocks );
	}

	public static function ajax_lib_save() {
		self::lib_check();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			$name = 'بخش ذخیره‌شده';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$blocks = self::blocks_from_json( isset( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : '' );
		if ( ! $blocks ) {
			wp_send_json_error( 'این بخش خالی است.' );
		}
		$trusted = current_user_can( 'unfiltered_html' );
		if ( ! $trusted ) {
			$blocks = self::sanitize_blocks( $blocks );
		}
		$scope_on = ! empty( $_POST['scope'] );

		$sid = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::CPT,
					'post_status' => current_user_can( 'publish_pages' ) ? 'publish' : 'draft',
					'post_title'  => $name,
				)
			),
			true
		);
		if ( is_wp_error( $sid ) ) {
			wp_send_json_error( $sid->get_error_message() );
		}

		update_post_meta( $sid, '_lsec_untrusted', $trusted ? '0' : '1' );
		update_post_meta( $sid, '_lsec_blocks', wp_slash( $blocks ) );
		update_post_meta( $sid, '_lsec_scope', $scope_on ? '1' : '0' );
		update_post_meta( $sid, '_lsec_compiled', wp_slash( self::compile_blocks( $blocks, $scope_on, $trusted ) ) );
		self::push_rev( $sid, self::snapshot( $sid ), null, null, $trusted );

		wp_send_json_success( array( 'id' => $sid ) );
	}

	/* ================================================================== *
	 * تبدیل کد کامل یک بخش به css / links / html / scripts
	 * ================================================================== */
	/**
	 * تشخیص خودکار نوع کد: اگر کاشی فقط CSS یا فقط JavaScript خام (بدون تگ) باشد،
	 * خودش داخل <style> یا <script> گذاشته می‌شود تا درست اجرا شود.
	 * @return string 'html' | 'css' | 'js'
	 */
	public static function code_kind( $code ) {
		$t = trim( preg_replace( '#/\*.*?\*/#s', '', (string) $code ) );
		if ( '' === $t || preg_match( '#<[a-z!/]#i', $t ) || preg_match( '#^\[[a-z_-]+#i', $t ) ) {
			return 'html';
		}
		$js = '#(^|[\s;{(])(function\b|const\s|let\s|var\s|=>|document\.|window\.|addEventListener\s*\(|console\.|import\s|export\s|new\s+[A-Z]|if\s*\(|for\s*\()#';
		if ( preg_match( $js, $t ) ) {
			return 'js';
		}
		if ( preg_match( '#^(@[a-z-]+[^{;]*[{;]|:root\s*\{|[^{}<>]+\{[^{}]*(:[^{}]*)?\})#is', $t ) ) {
			return 'css';
		}
		return 'html';
	}

	private static function wrap_bare( $code ) {
		switch ( self::code_kind( $code ) ) {
			case 'css':
				return '<style>' . $code . '</style>';
			case 'js':
				return '<script>' . $code . '</script>';
		}
		return $code;
	}

	private static function compile( $code, $scope, $scope_on, $trusted ) {
		$css   = '';
		$links = array();
		$js    = array();

		// کد PHP از دیتابیس اجرا نمی‌شود؛ حذف می‌شود تا در خروجی سایت هم دیده نشود
		$code = preg_replace( '#<\?.*?(?:\?>|\z)#s', '', $code );
		$code = self::wrap_bare( $code );

		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style\s*>#is', $code, $m ) ) {
			$css  = implode( "\n", $m[1] );
			$code = preg_replace( '#<style\b[^>]*>.*?</style\s*>#is', '', $code );
		}

		if ( preg_match_all( '#<link\b[^>]*>#i', $code, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '#rel\s*=\s*["\']?(stylesheet|preconnect|preload)#i', $tag ) ) {
					$links[] = $tag;
				}
			}
			$code = preg_replace( '#<link\b[^>]*>#i', '', $code );
		}

		// اسکیما (JSON-LD) جدا نگه داشته می‌شود تا در <head> چاپ شود
		$jsonld = array();
		$ld_re  = '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script\s*>#is';
		if ( preg_match_all( $ld_re, $code, $m ) ) {
			foreach ( $m[1] as $j ) {
				$j = trim( $j );
				if ( '' !== $j ) {
					$jsonld[] = str_replace( '</', '<\/', $j );
				}
			}
			$code = preg_replace( $ld_re, '', $code );
		}

		if ( preg_match_all( '#<script\b[^>]*>.*?</script\s*>#is', $code, $m ) ) {
			$js   = $m[0];
			$code = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $code );
		}

		if ( preg_match( '#<body\b[^>]*>(.*)</body\s*>#is', $code, $m ) ) {
			$code = $m[1];
		} else {
			$code = preg_replace(
				array(
					'#<!DOCTYPE[^>]*>#i',
					'#<head\b[^>]*>.*?</head\s*>#is',
					'#</?html\b[^>]*>#i',
					'#</?body\b[^>]*>#i',
					'#<meta\b[^>]*>#i',
					'#<title\b[^>]*>.*?</title\s*>#is',
				),
				'',
				$code
			);
		}

		if ( ! $trusted ) {
			$css      = self::css_untrusted( $css );
			$scope_on = true; // CSS نامطمئن همیشه محدود به همان کاشی
		}
		$css = $scope_on ? self::scope_css( $css, $scope ) : str_replace( '{{root}}', $scope, $css );
		$css = str_replace( '<', '\3C ', $css );

		$html = trim( $code );
		if ( ! $trusted ) {
			$html  = wp_kses_post( $html );
			$links  = array();
			$js     = array();
			$jsonld = array();
		}

		return array(
			'jsonld'  => $jsonld,
			'css'     => trim( $css ),
			'links'   => implode( "\n", $links ),
			'html'    => $html,
			'scripts' => implode( "\n", $js ),
		);
	}

	private static function scope_css( $css, $scope ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$out = '';
		$len = strlen( $css );
		$i   = 0;

		while ( $i < $len ) {
			$brace = strpos( $css, '{', $i );
			$semi  = strpos( $css, ';', $i );

			if ( false === $brace ) {
				$rest = trim( substr( $css, $i ) );
				if ( '' !== $rest && '@' === $rest[0] ) {
					$out .= $rest . "\n";
				}
				break;
			}

			if ( false !== $semi && $semi < $brace ) {
				$stmt = trim( substr( $css, $i, $semi - $i + 1 ) );
				if ( '' !== $stmt && '@' === $stmt[0] ) {
					$out .= $stmt . "\n";
				}
				$i = $semi + 1;
				continue;
			}

			$prelude = trim( substr( $css, $i, $brace - $i ) );

			$depth = 1;
			$j     = $brace + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
				}
				$j++;
			}
			$body = ( $depth > 0 ) ? substr( $css, $brace + 1 ) : substr( $css, $brace + 1, $j - $brace - 2 );
			$i    = $j;

			if ( '' === $prelude ) {
				continue;
			}

			if ( '@' === $prelude[0] ) {
				if ( preg_match( '/^@(media|supports|container|layer|document)\b/i', $prelude ) ) {
					$out .= $prelude . '{' . self::scope_css( $body, $scope ) . "}\n";
				} else {
					$out .= $prelude . '{' . $body . "}\n";
				}
				continue;
			}

			$out .= self::scope_selectors( $prelude, $scope ) . '{' . $body . "}\n";
		}

		return $out;
	}

	private static function scope_selectors( $prelude, $scope ) {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$n     = strlen( $prelude );
		for ( $k = 0; $k < $n; $k++ ) {
			$c = $prelude[ $k ];
			if ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth--;
			}
			if ( ',' === $c && 0 === $depth ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$parts[] = $buf;

		$result = array();
		foreach ( $parts as $sel ) {
			$sel = trim( $sel );
			if ( '' === $sel ) {
				continue;
			}
			if ( false !== strpos( $sel, '{{root}}' ) ) {
				$result[] = str_replace( '{{root}}', $scope, $sel );
				continue;
			}
			if ( 0 === strpos( $sel, $scope ) ) {
				$result[] = $sel;
				continue;
			}
			if ( '*' === $sel ) {
				$result[] = $scope;
				$result[] = $scope . ' *';
				continue;
			}
			$new = preg_replace( '/^(?:html|:root)(?![\w-])(?:\s+body(?![\w-]))?/i', $scope, $sel, 1, $count );
			if ( ! $count ) {
				$new = preg_replace( '/^body(?![\w-])/i', $scope, $sel, 1, $count );
			}
			$result[] = $count ? $new : $scope . ' ' . $sel;
		}

		return implode( ', ', $result );
	}

	/* ================================================================== *
	 * نمایش در سایت
	 * ================================================================== */

	/** بخش‌هایی که ویرایشگر برای پیش‌نمایش فرستاده (ذخیره نشده) */
	private static function live_data( $id ) {
		if ( array_key_exists( $id, self::$live_cache ) ) {
			return self::$live_cache[ $id ];
		}
		self::$live_cache[ $id ] = null;

		if ( ! isset( $_POST['kashi_live_blocks'] ) || ! self::live_valid( $id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$blocks = self::blocks_from_json( wp_unslash( $_POST['kashi_live_blocks'] ) );

		self::$live_cache[ $id ] = self::compile_blocks( $blocks, ! empty( $_POST['kashi_live_scope'] ), current_user_can( 'unfiltered_html' ) );
		return self::$live_cache[ $id ];
	}

	/** کامپایل ذخیره‌شده (با سازگاری نسخه قبل) */
	private static function saved_compiled( $id ) {
		$c = get_post_meta( $id, '_lsec_compiled', true );
		if ( ! is_array( $c ) ) {
			return null;
		}
		if ( isset( $c['html'] ) ) { // ساختار نسخه ۰.۳
			$c = array(
				array(
					'uid'  => 'legacy',
					'full' => '1' === get_post_meta( $id, '_lsec_full', true ),
					'c'    => $c,
				),
			);
		}
		// صفحه‌هایی که با نسخه قبل (Lite Sections) ذخیره شده‌اند: کلاس‌های CSS به نام جدید
		foreach ( $c as $k => $b ) {
			if ( isset( $b['c']['css'] ) && false !== strpos( $b['c']['css'], '.lsec-' ) ) {
				$c[ $k ]['c']['css'] = str_replace( '.lsec-', '.kashi-', $b['c']['css'] );
			}
		}
		return $c;
	}

	/**
	 * @param int      $id
	 * @param array    $compiled
	 * @param int|null $box_width null = داخل کانتینر قالب (بخش تمام‌عرض از کانتینر بیرون می‌زند)؛
	 *                            عدد = طرح تمام‌عرض/بوم (بخش‌های عادی در این عرض وسط‌چین می‌شوند).
	 */
	private static function render( $id, $compiled, $box_width = null ) {
		if ( in_array( $id, self::$render_stack, true ) ) {
			return '';
		}
		self::$render_stack[] = $id;
		$prev_box             = self::$ctx_box;
		self::$ctx_box        = $box_width;

		$head  = '';
		$inner = '';
		foreach ( (array) $compiled as $b ) {
			if ( ! is_array( $b ) || empty( $b['uid'] ) ) {
				continue;
			}
			$uid = preg_replace( '/[^a-z0-9]/', '', (string) $b['uid'] );
			$c   = wp_parse_args( isset( $b['c'] ) ? (array) $b['c'] : array(), array( 'css' => '', 'links' => '', 'html' => '', 'scripts' => '' ) );

			if ( empty( self::$printed_css[ $uid ] ) ) {
				self::$printed_css[ $uid ] = true;
				if ( '' !== $c['links'] ) {
					$head .= $c['links'] . "\n";
				}
				if ( '' !== $c['css'] ) {
					$head .= '<style id="kashi-css-' . $uid . '">' . $c['css'] . '</style>';
				}
			}
			if ( '' !== $c['scripts'] ) {
				self::$footer_js[ $uid ] = $c['scripts'];
			}
			if ( ! empty( $c['jsonld'] ) && empty( self::$printed_ld[ $uid ] ) ) {
				self::$printed_ld[ $uid ] = true;
				$ld = '';
				foreach ( (array) $c['jsonld'] as $j ) {
					$ld .= '<script type="application/ld+json">' . $j . "</script>\n";
				}
				self::$footer_js[ $uid . 'ld' ] = $ld;
			}

			if ( null === $box_width ) {
				// داخل کانتینر قالب: بخش تمام‌عرض از کانتینر بیرون می‌زند
				$style = ! empty( $b['full'] ) ? ' style="width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)"' : '';
			} else {
				// طرح تمام‌عرض/بوم: محتوا خودش لبه‌تالبه است؛ بخش‌های عادی وسط‌چین می‌شوند
				$style = ! empty( $b['full'] ) ? '' : ' style="max-width:' . (int) $box_width . 'px;margin-left:auto;margin-right:auto"';
			}
			$inner .= '<div class="kashi-block kashi-b-' . $uid . '" data-kashi-block="' . $uid . '"' . $style . '>' . do_shortcode( $c['html'] ) . '</div>';
		}

		array_pop( self::$render_stack );
		self::$ctx_box = $prev_box;

		return $head . '<div class="kashi kashi-' . (int) $id . '">' . $inner . '</div>';
	}

	/** شورت‌کد [kashi id="12"] برای کاشی‌های کتابخانه */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kashi' );
		$id   = absint( $atts['id'] );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::CPT !== $post->post_type ) {
			return '';
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
			return '';
		}

		$live = self::live_data( $id );
		if ( null !== $live ) {
			return self::render( $id, $live, self::$ctx_box );
		}
		$c = self::saved_compiled( $id );
		return $c ? self::render( $id, $c, self::$ctx_box ) : '';
	}

	/** برگه‌هایی که با کاشی ساخته شده‌اند: جایگزینی محتوا */
	/** آیا این برگه باید با کاشی نمایش داده شود؟ */
	private static function is_kashi_page( $id ) {
		if ( ! $id || self::CPT === get_post_type( $id ) || post_password_required( $id ) ) {
			return false;
		}
		if ( null !== self::live_data( $id ) ) {
			return true;
		}
		return '1' === get_post_meta( $id, '_lsec_enabled', true ) && self::saved_compiled( $id );
	}

	/**
	 * مرحله ۱ (اولویت 0): محتوای قدیمی با یک نشانگر جایگزین می‌شود تا wpautop، شورت‌کدها و ...
	 * روی آن اجرا نشوند، ولی چیزهایی که پلاگین‌های دیگر به محتوا اضافه می‌کنند
	 * (دکمه اشتراک، فهرست مطالب، نوشته‌های مرتبط و ...) حفظ شوند.
	 */
	public static function content_placeholder( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( ! self::is_kashi_page( $id ) ) {
			return $content;
		}
		self::$ph[ $id ] = 'KASHIPH' . $id . 'Z' . wp_generate_password( 12, false );
		return "\n" . self::$ph[ $id ] . "\n";
	}

	/** مرحله ۲ (اولویت 9999): نشانگر با بخش‌های شما جایگزین می‌شود */
	public static function filter_content( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( ! isset( self::$ph[ $id ] ) ) {
			return $content;
		}
		$tok = self::$ph[ $id ];
		unset( self::$ph[ $id ] );

		$box  = ( 'default' === self::layout_for( $id ) ) ? null : self::box_width_for( $id );
		$live = self::live_data( $id );
		$c    = ( null !== $live ) ? $live : self::saved_compiled( $id );
		if ( ! $c ) {
			return $content;
		}
		$html = self::render( $id, $c, $box );

		$q       = preg_quote( $tok, '#' );
		$content = preg_replace( '#<p>\s*' . $q . '\s*</p>#', $tok, $content );
		if ( false === strpos( $content, $tok ) ) {
			return $html; // نشانگر توسط فیلتر دیگری حذف شده
		}
		return str_replace( $tok, $html, $content );
	}

	/**
	 * CSS، فونت‌ها و اسکیمای صفحه در <head> چاپ می‌شوند (نه وسط body):
	 * بدون پرش صفحه (CLS)، HTML معتبر، و اسکیما در جای استاندارد.
	 */
	public static function head_assets() {
		if ( ! is_singular() ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id ) {
			return;
		}
		$out = '';
		if ( self::CPT !== get_post_type( $id ) && ! self::live_valid( $id ) && ! post_password_required( $id )
			&& '1' === get_post_meta( $id, '_lsec_enabled', true ) ) {
			$c = self::saved_compiled( $id );
			if ( $c ) {
				$out .= self::collect_head( $c, 0 );
			}
		} else {
			// برگه‌های عادی که شورت‌کد [kashi] دارند (مثلاً داخل صفحه‌ساز Flatsome)
			$post = get_post( $id );
			if ( $post && false !== preg_match( '#\[(?:kashi|lsec)\s#', $post->post_content ) && preg_match_all( '#\[(?:kashi|lsec)\s+id=["\']?(\d+)#', $post->post_content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $sid ) {
					$out .= self::collect_section_head( (int) $sid, 1 );
				}
			}
		}
		if ( '' !== $out ) {
			echo "\n<!-- کاشی -->\n" . $out . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS/JSON ذخیره‌شده توسط مدیر
		}
	}

	private static function collect_head( $compiled, $depth ) {
		$out = '';
		foreach ( (array) $compiled as $b ) {
			if ( ! is_array( $b ) || empty( $b['uid'] ) ) {
				continue;
			}
			$uid = preg_replace( '/[^a-z0-9]/', '', (string) $b['uid'] );
			$c   = wp_parse_args( isset( $b['c'] ) ? (array) $b['c'] : array(), array( 'css' => '', 'links' => '', 'html' => '', 'jsonld' => array() ) );
			if ( empty( self::$printed_css[ $uid ] ) ) {
				self::$printed_css[ $uid ] = true;
				if ( '' !== $c['links'] ) {
					$out .= $c['links'] . "\n";
				}
				if ( '' !== $c['css'] ) {
					$out .= '<style id="kashi-css-' . $uid . '">' . $c['css'] . "</style>\n";
				}
			}
			if ( ! empty( $c['jsonld'] ) && empty( self::$printed_ld[ $uid ] ) ) {
				self::$printed_ld[ $uid ] = true;
				foreach ( (array) $c['jsonld'] as $j ) {
					$out .= '<script type="application/ld+json">' . $j . "</script>\n";
				}
			}
			if ( $depth < 3 && preg_match_all( '#\[(?:kashi|lsec)\s+id=["\']?(\d+)#', $c['html'], $m ) ) {
				foreach ( array_unique( $m[1] ) as $sid ) {
					$out .= self::collect_section_head( (int) $sid, $depth + 1 );
				}
			}
		}
		return $out;
	}

	private static function collect_section_head( $sid, $depth ) {
		if ( self::CPT !== get_post_type( $sid ) || 'publish' !== get_post_status( $sid ) ) {
			return '';
		}
		$c = self::saved_compiled( $sid );
		return $c ? self::collect_head( $c, $depth ) : '';
	}

	/** صفحه‌های پیش‌نمایش هرگز ایندکس نشوند */
	private static function is_preview_request() {
		return isset( $_GET['kashi_live'] ) || isset( $_GET['kashi_preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function robots( $robots ) {
		if ( self::is_preview_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** طرح‌بندی تمام‌عرض / بوم خالی بدون دست زدن به تنظیمات قالب */
	public static function template_include( $template ) {
		if ( ! is_singular() ) {
			return $template;
		}
		$id = get_queried_object_id();
		if ( ! $id || self::CPT === get_post_type( $id ) || post_password_required( $id ) ) {
			return $template;
		}
		$layout = self::layout_for( $id );
		if ( 'full' === $layout ) {
			return __DIR__ . '/templates/full-width.php';
		}
		if ( 'canvas' === $layout ) {
			return __DIR__ . '/templates/canvas.php';
		}
		return $template;
	}

	public static function body_class( $classes ) {
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( $id && self::CPT !== get_post_type( $id ) ) {
				$layout = self::layout_for( $id );
				if ( 'default' !== $layout ) {
					$classes[] = 'kashi-layout-' . $layout;
				}
			}
		}
		return $classes;
	}

	public static function print_js() {
		foreach ( self::$footer_js as $uid => $scripts ) {
			echo "\n<!-- kashi-" . esc_html( $uid ) . " -->\n" . $scripts . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- اسکریپت ذخیره‌شده توسط مدیر
		}
	}

	public static function section_preview() {
		if ( empty( $_GET['kashi_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = absint( $_GET['kashi_preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $id || self::CPT !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		get_header();
		if ( isset( self::$hf_override['header'] ) || isset( self::$hf_override['footer'] ) ) {
			echo '<div style="margin:48px auto;max-width:1100px;min-height:55vh;border:2px dashed rgba(124,108,255,.35);border-radius:14px;display:flex;align-items:center;justify-content:center;color:#8b92a5;font:15px Tahoma,sans-serif">محتوای صفحه</div>';
		} else {
			echo self::shortcode( array( 'id' => $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		get_footer();
		exit;
	}

	/* ================================================================== *
	 * هدر و فوتر با کاشی
	 *
	 * روش: خروجی کامل صفحه بافر می‌شود و فقط المان هدر/فوتر قالب (<header>/<footer>
	 * یا المانی که خودتان مشخص کنید) با کاشی شما جایگزین می‌شود. بقیه ساختار قالب
	 * (wrapper ها، wp_head، wp_footer) دست نمی‌خورد؛ پس با هر قالبی کار می‌کند و
	 * کد سازگاری مخصوص هر قالب لازم نیست.
	 * ================================================================== */

	private static function parse_ids( $str ) {
		$ids = array_filter( array_map( 'absint', preg_split( '/[\s,،]+/u', (string) $str ) ) );
		return array_values( array_unique( $ids ) );
	}

	/** «#id» ، «.class» یا نام تگ */
	private static function sanitize_target( $t ) {
		$t = trim( (string) $t );
		return preg_match( '/^[#.]?[A-Za-z][\w-]*$/', $t ) ? $t : '';
	}

	private static function hf_meta( $id ) {
		$cond = get_post_meta( $id, '_lsec_cond', true );
		$cond = is_array( $cond ) ? $cond : array();
		return array(
			'location' => (string) get_post_meta( $id, '_lsec_location', true ),
			'mode'     => isset( $cond['mode'] ) ? $cond['mode'] : 'all',
			'ids'      => isset( $cond['ids'] ) ? array_map( 'intval', (array) $cond['ids'] ) : array(),
			'target'   => (string) get_post_meta( $id, '_lsec_target', true ),
		);
	}

	/** فهرست هدر/فوترها در یک option کوچک تا در هر بازدید کوئری اضافه نخورد */
	private static function hf_rebuild_index() {
		$idx   = array(
			'header' => array(),
			'footer' => array(),
		);
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => '_lsec_location', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => array( 'header', 'footer' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare'   => 'IN',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $pid ) {
			$m = self::hf_meta( $pid );
			if ( isset( $idx[ $m['location'] ] ) ) {
				$idx[ $m['location'] ][ $pid ] = $m;
			}
		}
		update_option( 'kashi_hf_index', $idx, true );
	}

	public static function hf_status_changed( $new, $old, $post ) {
		if ( $post && self::CPT === $post->post_type && $new !== $old ) {
			self::hf_rebuild_index();
		}
	}

	public static function hf_post_deleted( $post_id ) {
		$idx = get_option( 'kashi_hf_index', array() );
		if ( isset( $idx['header'][ $post_id ] ) || isset( $idx['footer'][ $post_id ] ) ) {
			self::hf_rebuild_index();
		}
	}

	/** آیا شرط نمایش در صفحه فعلی برقرار است؟ عدد بزرگ‌تر = دقیق‌تر */
	private static function hf_score( $m ) {
		$qid      = is_singular() ? get_queried_object_id() : 0;
		$front_id = (int) get_option( 'page_on_front' );
		$in_ids   = $qid && in_array( (int) $qid, $m['ids'], true );
		switch ( $m['mode'] ) {
			case 'include':
				return ( $in_ids || ( is_front_page() && $front_id && in_array( $front_id, $m['ids'], true ) ) ) ? 3 : -1;
			case 'front':
				return is_front_page() ? 2 : -1;
			case 'exclude':
				return $in_ids ? -1 : 1;
			default:
				return 0;
		}
	}

	private static function hf_pick( $loc ) {
		if ( isset( self::$hf_override[ $loc ] ) ) {
			return self::$hf_override[ $loc ];
		}
		$idx  = get_option( 'kashi_hf_index', array() );
		$best = 0;
		$bs   = -1;
		foreach ( isset( $idx[ $loc ] ) ? (array) $idx[ $loc ] : array() as $sid => $m ) {
			$sc = self::hf_score( $m );
			if ( $sc > $bs ) {
				$bs   = $sc;
				$best = (int) $sid;
			}
		}
		return $best;
	}

	public static function hf_start() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| is_feed() || is_robots() || is_trackback() || is_embed() || get_query_var( 'sitemap' ) ) {
			return;
		}

		// پیش‌نمایش یک هدر/فوتر در ویرایشگر خودش
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['kashi_preview'] ) ) {
			$pid = absint( $_GET['kashi_preview'] );
			if ( $pid && self::CPT === get_post_type( $pid ) && current_user_can( 'edit_post', $pid ) ) {
				$loc = ( self::live_valid( $pid ) && isset( $_POST['kashi_live_location'] ) )
					? sanitize_key( wp_unslash( $_POST['kashi_live_location'] ) )
					: get_post_meta( $pid, '_lsec_location', true );
				if ( in_array( $loc, array( 'header', 'footer' ), true ) ) {
					self::$hf_override[ $loc ] = $pid;
				}
			}
		}
		// phpcs:enable

		// بوم خالی: هدر و فوتر ندارد
		if ( is_singular() && 'canvas' === self::layout_for( get_queried_object_id() ) ) {
			return;
		}

		$active = array_filter(
			array(
				'header' => self::hf_pick( 'header' ),
				'footer' => self::hf_pick( 'footer' ),
			)
		);
		if ( ! $active ) {
			return;
		}
		self::$hf_active  = $active;
		self::$hf_buf     = '';
		self::$hf_markup  = array();
		self::$hf_scripts = '';
		self::$hf_pass    = false;
		ob_start( array( __CLASS__, 'hf_buffer' ) );
	}

	private static function hf_compiled( $sid ) {
		$live = self::live_data( $sid );
		if ( null !== $live ) {
			return $live;
		}
		if ( 'publish' !== get_post_status( $sid ) && ! isset( self::$hf_override['header'] ) && ! isset( self::$hf_override['footer'] ) ) {
			return null;
		}
		return self::saved_compiled( $sid );
	}

	/**
	 * نسخه «ماسک‌شده» HTML: محتوای کامنت‌ها، اسکریپت‌ها، استایل‌ها و template ها با
	 * فاصله جایگزین می‌شود (با همان طول) تا جستجوی تگ‌ها گول متن داخل آن‌ها را نخورد.
	 */
	private static function hf_mask( $html ) {
		return preg_replace_callback(
			'#<!--.*?-->|<(script|style|template|textarea|noscript)\b[^>]*>.*?</\1\s*>#is',
			function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$html
		);
	}

	/** محدوده [شروع، پایان] یک المان در HTML با شمارش تگ‌های تودرتو */
	private static function hf_range( $masked, $tag, $start ) {
		$re    = '#<(/?)' . preg_quote( $tag, '#' ) . '(?=[\s/>])[^>]*>#i';
		$depth = 0;
		$pos   = $start;
		while ( preg_match( $re, $masked, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += ( '/' === $m[1][0] ) ? -1 : 1;
			$pos    = $m[0][1] + strlen( $m[0][0] );
			if ( 0 === $depth ) {
				return array( $start, $pos );
			}
		}
		return null;
	}

	/**
	 * المان هدر/فوتر قالب را پیدا می‌کند.
	 * $target: «#id» ، «.class» ، نام تگ یا خالی (= header / footer)
	 */
	private static function hf_find( $html, $loc, $target ) {
		$masked = self::hf_mask( $html );
		$body   = stripos( $masked, '<body' );
		if ( false === $body ) {
			return null;
		}
		if ( '' !== $target && ( '#' === $target[0] || '.' === $target[0] ) ) {
			$name = preg_quote( substr( $target, 1 ), '#' );
			$re   = '#' === $target[0]
				? '#<([a-zA-Z][\w-]*)(?=[\s/>])[^>]*\sid\s*=\s*(["\']?)' . $name . '\2[\s>/]#i'
				: '#<([a-zA-Z][\w-]*)(?=[\s/>])[^>]*\sclass\s*=\s*(["\'])(?:[^"\']*\s)?' . $name . '(?:\s[^"\']*)?\2#i';
			if ( ! preg_match( $re, $masked, $m, PREG_OFFSET_CAPTURE, $body ) ) {
				return null;
			}
			return self::hf_range( $masked, $m[1][0], $m[0][1] );
		}

		$tag = '' !== $target ? $target : $loc;
		$top = array();
		$pos = $body;
		while ( preg_match( '#<' . preg_quote( $tag, '#' ) . '(?=[\s/>])#i', $masked, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$r = self::hf_range( $masked, $tag, $m[0][1] );
			if ( ! $r ) {
				break;
			}
			$top[] = $r;
			$pos   = $r[1];
			if ( 'header' === $loc ) {
				break; // اولین هدر سطح بالا
			}
		}
		if ( ! $top ) {
			return null;
		}
		return 'header' === $loc ? $top[0] : end( $top );
	}

	/** پیش‌نمایش زنده هرگز کش و ایندکس نشود */
	public static function live_headers() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce در live_valid بررسی می‌شود
		if ( empty( $_POST['kashi_live_nonce'] ) ) {
			return;
		}
		$id = ! empty( $_GET['kashi_preview'] ) ? absint( $_GET['kashi_preview'] ) : get_queried_object_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && self::live_valid( $id ) ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // LiteSpeed / WP Rocket / W3TC
			}
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * ساخت هدر/فوتر در wp_head (خارج از output handler): شورت‌کدهایی که خودشان
	 * ob_start می‌کنند (منو، سبد خرید، فرم ...) بدون مشکل کار می‌کنند و CSS در head چاپ می‌شود.
	 */
	public static function hf_prepare() {
		if ( ! self::$hf_active || self::$hf_markup ) {
			return;
		}
		foreach ( array( 'header', 'footer' ) as $loc ) {
			if ( empty( self::$hf_active[ $loc ] ) ) {
				continue;
			}
			$sid = (int) self::$hf_active[ $loc ];
			$c   = self::hf_compiled( $sid );
			if ( ! $c ) {
				continue;
			}
			echo self::collect_head( $c, 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS/JSON ذخیره‌شده

			$saved_js        = self::$footer_js;
			self::$footer_js = array();
			ob_start(); // شورت‌کدهایی که اشتباهاً echo می‌کنند هم سر جای خودشان بیایند
			$inner                     = self::render( $sid, $c, null );
			$inner                     = ob_get_clean() . $inner;
			self::$hf_markup[ $loc ]   = '<div id="kashi-' . $loc . '" class="kashi-hf kashi-hf-' . $loc . '">' . $inner . '</div>';
			self::$hf_scripts         .= implode( "\n", self::$footer_js ) . "\n";
			self::$footer_js           = $saved_js;
		}
	}

	public static function hf_print_scripts() {
		if ( '' !== trim( self::$hf_scripts ) ) {
			echo self::$hf_scripts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- اسکریپت ذخیره‌شده توسط مدیر
			self::$hf_scripts = '';
		}
	}

	/** آیا پاسخ HTML است؟ (اگر هدر Content-Type غیر HTML فرستاده شده، دست نزن) */
	private static function hf_is_html_response() {
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) ) {
				return false !== stripos( $h, 'text/html' );
			}
		}
		return true;
	}

	/** output handler: فقط جایگزینی رشته‌ها؛ هیچ رندر یا شورت‌کدی اینجا اجرا نمی‌شود */
	public static function hf_buffer( $chunk, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			self::$hf_buf = ''; // ob_clean(): خروجی دورریخته‌شده برنگردد
			return '';
		}
		if ( self::$hf_pass ) {
			return $chunk;
		}
		self::$hf_buf .= $chunk;
		$final         = ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_END );

		// خروجی غیر HTML (دانلود فایل، JSON، CSV): بلافاصله و دست‌نخورده عبور کند
		$head = ltrim( substr( self::$hf_buf, 0, 512 ) );
		if ( ! self::hf_is_html_response() || ( strlen( $head ) > 0 && '<' !== $head[0] ) ) {
			self::$hf_pass = true;
			$out           = self::$hf_buf;
			self::$hf_buf  = '';
			return $out;
		}
		if ( ! $final ) {
			return '';
		}

		$html         = self::$hf_buf;
		self::$hf_buf = '';
		if ( ! self::$hf_markup || false === stripos( $html, '<body' ) ) {
			return $html;
		}

		// اول فوتر (آخرین المان)، بعد هدر (اولین المان)
		foreach ( array( 'footer', 'header' ) as $loc ) {
			if ( empty( self::$hf_markup[ $loc ] ) ) {
				continue;
			}
			$markup = self::$hf_markup[ $loc ];
			$target = (string) get_post_meta( (int) self::$hf_active[ $loc ], '_lsec_target', true );
			$range  = self::hf_find( $html, $loc, $target );
			if ( $range ) {
				$html = substr( $html, 0, $range[0] ) . $markup . substr( $html, $range[1] );
			} elseif ( 'header' === $loc && preg_match( '#<body(?=[\s>])[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$at   = $m[0][1] + strlen( $m[0][0] );
				$html = substr( $html, 0, $at ) . $markup . substr( $html, $at );
			} elseif ( 'footer' === $loc && false !== ( $at = strripos( $html, '</body>' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				$html = substr( $html, 0, $at ) . $markup . substr( $html, $at );
			}
		}
		return $html;
	}

	public static function hide_admin_bar_in_preview( $show ) {
		return isset( $_GET['kashi_live'] ) ? false : $show; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}

define( 'KASHI_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-kashi-updater.php';
require_once __DIR__ . '/includes/class-kashi-net.php';

Kashi::init();
Kashi_Updater::init( __FILE__, Kashi::VERSION );
Kashi_Net::init( __FILE__ );
