<?php
/**
 * کاشی — حالت اینترنت ملی و فونت محلی
 *
 * ۱) حالت اینترنت ملی (اختیاری): درخواست‌های سمت سرور به سرویس‌های خارجی که در قطعی
 *    یا کندی اینترنت بین‌الملل پیشخوان را چند ثانیه تا چند دقیقه معطل می‌کنند
 *    (wordpress.org، گراواتار، گوگل‌فونت، سرورهای لایسنس افزونه‌ها و …) فوراً رد می‌شوند.
 *    دامنه‌های .ir، شبکه داخلی، خود سایت و سرور آپدیت کاشی هرگز مسدود نمی‌شوند.
 * ۲) فونت وزیرمتن محلی (اختیاری): از پوشه خود افزونه، بدون گوگل‌فونت یا CDN.
 *
 * @package Kashi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kashi_Net {

	const OPT = 'kashi_net';

	/** فهرست پیش‌فرض: سرویس‌هایی که در قطعی، پیشخوان را کند می‌کنند */
	const DEFAULT_HOSTS = "wordpress.org\nw.org\nwp.com\ngravatar.com\ngoogleapis.com\ngstatic.com\nelementor.com\nwoocommerce.com\nyoast.com\nrankmath.com\nfreemius.com\nwpml.org\nenvato.com\nthemeforest.net";

	/** @var string */
	private static $file = '';

	public static function init( $plugin_file ) {
		self::$file = $plugin_file;
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 1 );
	}

	public static function boot() {
		$s = self::settings();
		if ( $s['block'] ) {
			add_filter( 'pre_http_request', array( __CLASS__, 'pre_http' ), 1, 3 );
			// گراواتار در مرورگر هم معطل می‌کند؛ آواتار پیش‌فرض محلی
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar' ), 1, 2 );
		}
		if ( $s['font'] ) {
			add_action( 'wp_head', array( __CLASS__, 'font_head' ), 2 );
		}
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		$s = wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'block' => false,
				'hosts' => self::DEFAULT_HOSTS,
				'font'  => false,
			)
		);
		$s['block'] = ! empty( $s['block'] );
		$s['font']  = ! empty( $s['font'] );
		return $s;
	}

	/** فهرست دامنه‌ها (حروف کوچک، بدون www و *.) */
	public static function hosts( $text = null ) {
		if ( null === $text ) {
			$s    = self::settings();
			$text = $s['hosts'];
		}
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) $text ) ) as $h ) {
			$h = preg_replace( '#^(https?://)?(\*\.)?(www\.)?#', '', trim( $h ) );
			$h = preg_replace( '#[/:].*$#', '', $h );
			if ( '' !== $h && preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $h ) ) {
				$out[ $h ] = true;
			}
		}
		return array_keys( $out );
	}

	/** آیا این میزبان باید مسدود شود؟ */
	public static function is_blocked( $url, $hosts = null ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		// همیشه مجاز: شبکه داخلی، دامنه‌های ایران، خود سایت، سرور آپدیت کاشی
		if ( 'localhost' === $host || preg_match( '/\.(ir|local|localhost|test)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false; // آی‌پی مستقیم: احتمالاً سرویس داخلی
		}
		$own = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		if ( class_exists( 'Kashi_Updater' ) ) {
			$u     = Kashi_Updater::settings();
			$own[] = $u['url'] ? wp_parse_url( $u['url'], PHP_URL_HOST ) : '';
		}
		foreach ( $own as $o ) {
			if ( $o && strtolower( $o ) === $host ) {
				return false;
			}
		}
		foreach ( null === $hosts ? self::hosts() : $hosts as $h ) {
			if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}
		return false;
	}

	public static function pre_http( $pre, $args, $url ) {
		if ( false !== $pre || ! self::is_blocked( $url ) ) {
			return $pre;
		}
		return new WP_Error( 'kashi_offline', Kashi::t( 'حالت اینترنت ملی کاشی: درخواست به ' ) . wp_parse_url( $url, PHP_URL_HOST ) . Kashi::t( ' ارسال نشد.' ) );
	}

	public static function avatar( $args, $id_or_email ) {
		if ( empty( $args['url'] ) ) {
			$args['url']          = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#dcdde3"/><circle cx="32" cy="25" r="11" fill="#a3a7b3"/><path d="M12 58c3-11 11-17 20-17s17 6 20 17z" fill="#a3a7b3"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$args['found_avatar'] = false;
		}
		return $args;
	}

	public static function font_url() {
		return plugins_url( 'assets/fonts/vazirmatn.woff2', self::$file );
	}

	/** فونت محلی: preload + @font-face؛ در کاشی‌ها font-family: Vazirmatn بنویسید */
	public static function font_head() {
		$u = esc_url( self::font_url() );
		echo '<link rel="preload" href="' . $u . '" as="font" type="font/woff2" crossorigin>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<style id="kashi-font">@font-face{font-family:"Vazirmatn";src:url(' . $u . ') format("woff2");font-weight:100 900;font-style:normal;font-display:swap}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ---------- تنظیمات (در صفحه تنظیمات کاشی) ---------- */

	public static function save_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce در Kashi_Updater::save_settings بررسی شده
		$hosts = isset( $_POST['kashi_net_hosts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kashi_net_hosts'] ) ) : self::DEFAULT_HOSTS;
		update_option(
			self::OPT,
			array(
				'block' => ! empty( $_POST['kashi_net_block'] ),
				'hosts' => implode( "\n", self::hosts( $hosts ) ),
				'font'  => ! empty( $_POST['kashi_net_font'] ),
			),
			true
		);
		// phpcs:enable
	}

	public static function card() {
		$s = self::settings();
		?>
		<div class="kashi-card">
			<h2><?php echo esc_html( Kashi::t( 'اینترنت ملی و سرعت' ) ); ?></h2>
			<p class="desc"><?php echo esc_html( Kashi::t( 'برای وقتی اینترنت بین‌الملل قطع یا کند است. کاشی خودش هیچ فایلی از خارج بارگذاری نمی‌کند؛ این گزینه‌ها بقیه سایت را هم سبک می‌کنند.' ) ); ?></p>
			<div class="kashi-row">
				<label for="kashi_net_block"><?php echo esc_html( Kashi::t( 'حالت اینترنت ملی' ) ); ?></label>
				<div>
					<label><input type="checkbox" id="kashi_net_block" name="kashi_net_block" value="1" <?php checked( $s['block'] ); ?>> <?php echo esc_html( Kashi::t( 'درخواست‌های سرور به سرویس‌های خارجی زیر فوراً رد شوند' ) ); ?></label>
					<p class="description"><?php echo esc_html( Kashi::t( 'پیشخوان دیگر منتظر wordpress.org، گراواتار، گوگل و سرور لایسنس افزونه‌ها نمی‌ماند. در این حالت آپدیت وردپرس و افزونه‌های مخزن، و فعال‌سازی لایسنس افزونه‌های خارجی کار نمی‌کند؛ بعد از وصل شدن اینترنت خاموشش کنید. دامنه‌های .ir (درگاه پرداخت، پیامک)، خود سایت و سرور آپدیت کاشی هیچ‌وقت مسدود نمی‌شوند.' ) ); ?></p>
				</div>
			</div>
			<div class="kashi-row">
				<label for="kashi_net_hosts"><?php echo esc_html( Kashi::t( 'دامنه‌های مسدود' ) ); ?></label>
				<div>
					<textarea id="kashi_net_hosts" name="kashi_net_hosts" rows="5" class="ltr"><?php echo esc_textarea( $s['hosts'] ); ?></textarea>
					<p class="description"><?php echo esc_html( Kashi::t( 'هر خط یک دامنه؛ زیردامنه‌ها هم شامل می‌شوند (مثلاً gravatar.com شامل secure.gravatar.com).' ) ); ?></p>
				</div>
			</div>
			<div class="kashi-row">
				<label for="kashi_net_font"><?php echo esc_html( Kashi::t( 'فونت وزیرمتن محلی' ) ); ?></label>
				<div>
					<label><input type="checkbox" id="kashi_net_font" name="kashi_net_font" value="1" <?php checked( $s['font'] ); ?>> <?php echo esc_html( Kashi::t( 'فونت Vazirmatn از خود سایت بارگذاری شود (۱۱۰ کیلوبایت، همه وزن‌ها)' ) ); ?></label>
					<p class="description"><?php echo esc_html( Kashi::t( 'در CSS کاشی‌ها بنویسید' ) ); ?> <code class="ltr">font-family: Vazirmatn, sans-serif;</code> <?php echo esc_html( Kashi::t( 'و لینک گوگل‌فونت را حذف کنید. فونت پیش‌بارگذاری می‌شود تا متن پرش نکند. مجوز: SIL Open Font License.' ) ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
