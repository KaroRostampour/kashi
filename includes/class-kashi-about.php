<?php
/**
 * کاشی — صفحه «درباره و سازنده» و تشخیص نسخه رسمی
 *
 * Copyright (c) 2026 KaroRostampour <Karorostampoor@gmail.com>
 * Licensed under GPL-2.0-or-later.
 *
 * نسخه رسمی: هر انتشار رسمی یک فایل kashi-release.json دارد که فهرست SHA-256 همه فایل‌ها
 * را با کلید خصوصی سازنده (Ed25519) امضا می‌کند. اگر حتی یک فایل تغییر کند (مثلاً نام
 * سازنده حذف شود)، این صفحه «نسخه تغییریافته / غیررسمی» نشان می‌دهد.
 *
 * @package Kashi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kashi_About {

	const CREATOR       = 'KaroRostampour';
	const CREATOR_EMAIL = 'Karorostampoor@gmail.com';
	const REPO_URL      = 'https://github.com/KaroRostampour/kashi';
	/** کلید عمومی امضای نسخه‌های رسمی */
	const RELEASE_KEY   = 'Q0hLpG+jnUCal8fQ3Ihti+QLOxsBhZ/sG+L1b6BOJ1k=';
	const MANIFEST      = 'kashi-release.json';

	/** @var string */
	private static $dir = '';

	public static function init( $plugin_file ) {
		self::$dir = dirname( $plugin_file );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 20 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 20, 2 );
		add_filter( 'all_plugins', array( __CLASS__, 'plugin_desc' ) );
	}

	/** توضیح افزونه در فهرست «افزونه‌ها» به زبان افزونه */
	public static function plugin_desc( $plugins ) {
		foreach ( $plugins as $file => $data ) {
			if ( 'kashi.php' === basename( $file ) && is_array( $data ) ) {
				$plugins[ $file ]['Description'] = 'fa' === Kashi::lang()
					? 'صفحه‌ساز کدمحور و سبک: صفحه را کاشی‌به‌کاشی با کد خودتان بسازید و همان لحظه نتیجه را ببینید. بدون بار اضافه، سازگار با هر قالب و افزونه سئو.'
					: Kashi::t( 'صفحه‌ساز کدمحور و سبک: صفحه را کاشی‌به‌کاشی با کد خودتان بسازید.' );
			}
		}
		return $plugins;
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=lsec_section',
			Kashi::t( 'درباره کاشی' ),
			Kashi::t( 'درباره کاشی' ),
			'edit_pages',
			'kashi-about',
			array( __CLASS__, 'page' )
		);
	}

	public static function logo_url() {
		return plugins_url( 'assets/kr-logo.svg', self::$dir . '/kashi.php' );
	}

	/** آیا صفحه فعلی پیشخوان یکی از صفحه‌های کاشی است؟ */
	private static function on_kashi_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $s && ( 'lsec_section' === $s->post_type || false !== strpos( (string) $s->id, 'kashi' ) );
	}

	public static function footer_text( $text ) {
		if ( ! self::on_kashi_screen() ) {
			return $text;
		}
		return sprintf(
			'<span class="kashi-credit">%s <a href="%s" target="_blank" rel="noopener">%s</a> · v%s</span>',
			esc_html( Kashi::t( 'کاشی — ساخته‌شده توسط' ) ),
			esc_url( self::REPO_URL ),
			esc_html( self::CREATOR ),
			esc_html( Kashi::VERSION )
		);
	}

	public static function row_meta( $links, $file ) {
		if ( 'kashi.php' === basename( $file ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=lsec_section&page=kashi-about' ) ) . '">' . esc_html( Kashi::t( 'درباره کاشی' ) ) . '</a>';
		}
		return $links;
	}

	/* ---------- نسخه رسمی ---------- */

	/** فهرست فایل‌هایی که امضا می‌شوند (نسبت به پوشه افزونه) */
	public static function file_list( $dir ) {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) );
			if ( self::MANIFEST === $rel || 0 === strpos( $rel, '.git' ) || '.DS_Store' === basename( $rel ) ) {
				continue;
			}
			$out[] = $rel;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	public static function manifest_body( $version, $files ) {
		ksort( $files, SORT_STRING );
		return wp_json_encode( array( 'name' => 'kashi', 'version' => (string) $version, 'files' => $files ), JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @return array status: official | modified | unsigned | nosodium ; changed: list of files
	 */
	public static function verify( $dir = null ) {
		$dir = null === $dir ? self::$dir : $dir;
		$f   = $dir . '/' . self::MANIFEST;
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return array( 'status' => 'nosodium', 'changed' => array() );
		}
		if ( ! is_readable( $f ) ) {
			return array( 'status' => 'unsigned', 'changed' => array() );
		}
		$m = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $m ) || empty( $m['files'] ) || empty( $m['sig'] ) || ! is_array( $m['files'] ) ) {
			return array( 'status' => 'modified', 'changed' => array( self::MANIFEST ) );
		}
		$sig = base64_decode( (string) $m['sig'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$pk  = base64_decode( self::RELEASE_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ok  = false;
		try {
			$ok = 64 === strlen( (string) $sig ) && sodium_crypto_sign_verify_detached( $sig, self::manifest_body( isset( $m['version'] ) ? $m['version'] : '', $m['files'] ), $pk );
		} catch ( Exception $e ) {
			$ok = false;
		}
		if ( ! $ok || ! isset( $m['version'] ) || Kashi::VERSION !== $m['version'] ) {
			return array( 'status' => 'modified', 'changed' => array( self::MANIFEST ) );
		}
		$changed = array();
		$now     = self::file_list( $dir );
		foreach ( $m['files'] as $rel => $sha ) {
			$p = $dir . '/' . $rel;
			if ( ! is_file( $p ) || ! hash_equals( (string) $sha, (string) hash_file( 'sha256', $p ) ) ) {
				$changed[] = $rel;
			}
		}
		foreach ( array_diff( $now, array_keys( $m['files'] ) ) as $extra ) {
			$changed[] = $extra . ' (+)';
		}
		return array( 'status' => $changed ? 'modified' : 'official', 'changed' => $changed );
	}

	/* ---------- صفحه ---------- */

	public static function page() {
		$v    = self::verify();
		$dir  = Kashi::dir();
		$logo = self::logo_url();
		$st   = array(
			'official' => array( 'ok', Kashi::t( 'نسخه رسمی و دست‌نخورده' ), Kashi::t( 'همه فایل‌ها با امضای دیجیتال سازنده تأیید شدند.' ) ),
			'modified' => array( 'bad', Kashi::t( 'نسخه تغییریافته / غیررسمی' ), Kashi::t( 'فایل‌های این نسخه با نسخه رسمی امضاشده یکی نیستند. نسخه رسمی را فقط از گیت‌هاب سازنده دریافت کنید.' ) ),
			'unsigned' => array( 'warn', Kashi::t( 'نسخه توسعه (امضانشده)' ), Kashi::t( 'این بسته فایل امضای نسخه رسمی ندارد.' ) ),
			'nosodium' => array( 'warn', Kashi::t( 'بررسی امضا ممکن نیست' ), Kashi::t( 'سرور از امضای دیجیتال (sodium) پشتیبانی نمی‌کند.' ) ),
		);
		$s = $st[ $v['status'] ];
		?>
		<div class="wrap kashi-about" dir="<?php echo esc_attr( $dir ); ?>">
			<style>
				.kashi-about{max-width:980px}
				.kashi-about .ka-hero{position:relative;overflow:hidden;margin:18px 0;border-radius:20px;padding:40px 40px 34px;color:#e9e7ff;background:radial-gradient(1200px 400px at 85% -20%,rgba(124,108,255,.55),transparent 60%),radial-gradient(600px 300px at 0% 120%,rgba(52,201,138,.18),transparent 60%),#0f1118;box-shadow:0 30px 80px -30px rgba(20,16,60,.6)}
				.kashi-about .ka-hero:after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}
				.kashi-about .ka-top{position:relative;display:flex;align-items:center;gap:26px;flex-wrap:wrap}
				.kashi-about .ka-mark{width:86px;height:86px;border-radius:22px;display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12)}
				.kashi-about .ka-mark i{border-radius:6px;background:#7c6cff}.kashi-about .ka-mark i:nth-child(2){background:#a99bff}.kashi-about .ka-mark i:nth-child(3){background:#34c98a}.kashi-about .ka-mark i:nth-child(4){background:#f0b13c}
				.kashi-about h1{color:#fff;font-size:34px;line-height:1.2;margin:0;padding:0;font-weight:800;letter-spacing:-.01em}
				.kashi-about .ka-tag{margin:6px 0 0;color:#bdb6ff;font-size:15px}
				.kashi-about .ka-ver{display:inline-flex;gap:8px;align-items:center;margin-top:14px;padding:5px 12px;border-radius:99px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);font-size:12.5px;direction:ltr}
				.kashi-about .ka-grid{display:grid;grid-template-columns:1.25fr 1fr;gap:18px}
				@media (max-width:900px){.kashi-about .ka-grid{grid-template-columns:1fr}}
				.kashi-about .ka-card{background:#fff;border:1px solid #e6e4f0;border-radius:18px;padding:26px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
				.kashi-about .ka-card h2{margin:0 0 14px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#6b6880}
				.kashi-about .ka-creator{display:flex;align-items:center;gap:22px}
				.kashi-about .ka-logo{flex:0 0 132px;height:132px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#f6f2ea,#e9e2d4);box-shadow:inset 0 2px 6px rgba(0,0,0,.08),0 10px 30px -12px rgba(60,40,10,.35);color:#2d2a26}
				.kashi-about .ka-logo img{width:96px;height:auto}
				.kashi-about .ka-name{font-size:24px;font-weight:800;color:#15131f;margin:0;direction:ltr;text-align:start}
				.kashi-about .ka-role{color:#6b6880;margin:4px 0 12px}
				.kashi-about .ka-links{display:flex;flex-wrap:wrap;gap:8px}
				.kashi-about .ka-links a{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;border:1px solid #e1def0;text-decoration:none;color:#3b3560;font-weight:600;direction:ltr}
				.kashi-about .ka-links a:hover{border-color:#7c6cff;color:#4b36c8}
				.kashi-about .ka-badge{display:flex;gap:12px;align-items:flex-start;padding:16px;border-radius:14px}
				.kashi-about .ka-badge b{display:block;font-size:15px;margin-bottom:4px}
				.kashi-about .ka-badge.ok{background:#e9f8f0;color:#0a6b3c}.kashi-about .ka-badge.bad{background:#fdecec;color:#9a1c1f}.kashi-about .ka-badge.warn{background:#fff6e5;color:#8a5a00}
				.kashi-about .ka-badge .dot{flex:0 0 12px;height:12px;border-radius:50%;margin-top:5px;background:currentColor}
				.kashi-about .ka-list{margin:10px 0 0;padding:0;list-style:none;color:#4a4760;line-height:2}
				.kashi-about .ka-files{margin-top:10px;max-height:140px;overflow:auto;direction:ltr;text-align:left;font:12px/1.7 ui-monospace,Consolas,monospace;color:#9a1c1f}
				.kashi-about .ka-feat{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:4px}
				@media (max-width:900px){.kashi-about .ka-feat{grid-template-columns:1fr 1fr}}
				.kashi-about .ka-feat div{padding:12px;border-radius:12px;background:#f7f6fc;color:#2c2843;font-weight:600;font-size:13px}
				.kashi-about .ka-feat small{display:block;font-weight:400;color:#6b6880;margin-top:2px}
				.kashi-about .ka-legal{color:#6b6880;font-size:12.5px;line-height:1.9}
			</style>

			<div class="ka-hero">
				<div class="ka-top">
					<div class="ka-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
					<div>
						<h1>Kashi <span style="opacity:.55;font-weight:600">کاشی</span></h1>
						<p class="ka-tag"><?php echo esc_html( Kashi::t( 'صفحه‌ساز کدمحور و سبک: صفحه را کاشی‌به‌کاشی با کد خودتان بسازید.' ) ); ?></p>
						<span class="ka-ver">v<?php echo esc_html( Kashi::VERSION ); ?> · GPL-2.0-or-later · <?php echo esc_html( Kashi::LANGS[ Kashi::lang() ][0] ); ?></span>
					</div>
				</div>
			</div>

			<div class="ka-grid">
				<div class="ka-card">
					<h2><?php echo esc_html( Kashi::t( 'سازنده' ) ); ?></h2>
					<div class="ka-creator">
						<div class="ka-logo"><img src="<?php echo esc_url( self::logo_url() ); ?>" alt="KR" width="96" height="66"></div>
						<div>
							<p class="ka-name"><?php echo esc_html( self::CREATOR ); ?></p>
							<p class="ka-role"><?php echo esc_html( Kashi::t( 'طراح و توسعه‌دهنده کاشی' ) ); ?></p>
							<div class="ka-links">
								<a href="mailto:<?php echo esc_attr( self::CREATOR_EMAIL ); ?>">✉ <?php echo esc_html( self::CREATOR_EMAIL ); ?></a>
								<a href="<?php echo esc_url( self::REPO_URL ); ?>" target="_blank" rel="noopener">GitHub ↗</a>
							</div>
						</div>
					</div>
				</div>

				<div class="ka-card">
					<h2><?php echo esc_html( Kashi::t( 'اصالت نسخه' ) ); ?></h2>
					<div class="ka-badge <?php echo esc_attr( $s[0] ); ?>">
						<span class="dot"></span>
						<div><b><?php echo esc_html( $s[1] ); ?></b><?php echo esc_html( $s[2] ); ?></div>
					</div>
					<?php if ( 'modified' === $v['status'] && $v['changed'] ) : ?>
						<div class="ka-files"><?php echo esc_html( implode( "\n", array_slice( $v['changed'], 0, 30 ) ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="ka-card" style="margin-top:18px">
				<h2><?php echo esc_html( Kashi::t( 'امکانات' ) ); ?></h2>
				<div class="ka-feat">
					<?php
					$feats = array(
						array( Kashi::t( 'ویرایشگر زنده' ), Kashi::t( 'کد HTML/CSS/JS با پیش‌نمایش داخل قالب واقعی' ) ),
						array( Kashi::t( 'ویرایش مستقیم' ), Kashi::t( 'متن، لینک، تصویر، رنگ و آیکون بدون کد' ) ),
						array( Kashi::t( 'هدر و فوتر' ), Kashi::t( 'با هر قالبی، با شرط نمایش' ) ),
						array( Kashi::t( 'نمایش در دستگاه‌ها' ), Kashi::t( 'دسکتاپ، لپ‌تاپ، تبلت، موبایل' ) ),
						array( Kashi::t( 'زمان‌بندی انتشار' ), Kashi::t( 'هر بخش سر وقت خودش منتشر می‌شود' ) ),
						array( Kashi::t( 'سئو و سرعت' ), Kashi::t( 'اسکیما، LCP، lazy، بدون فایل اضافه' ) ),
						array( Kashi::t( 'امنیت' ), Kashi::t( 'آپدیت امضاشده، مدل اعتماد کاربران' ) ),
						array( Kashi::t( 'کتابخانه آیکون' ), Kashi::t( '۳۰۰+ آیکون مینیمال و محصول' ) ),
						array( Kashi::t( '۱۰ زبان' ), Kashi::t( 'با تقویم شمسی و میلادی' ) ),
					);
					foreach ( $feats as $f ) {
						echo '<div>' . esc_html( $f[0] ) . '<small>' . esc_html( $f[1] ) . '</small></div>';
					}
					?>
				</div>
			</div>

			<p class="ka-legal">© 2026 <?php echo esc_html( self::CREATOR ); ?> — <?php echo esc_html( Kashi::t( 'کاشی نرم‌افزار آزاد با مجوز GPL-2.0-or-later است. نام «کاشی» و لوگوی KR متعلق به سازنده است. نسخه رسمی فقط از گیت‌هاب سازنده منتشر می‌شود و با امضای دیجیتال قابل تشخیص است.' ) ); ?></p>
		</div>
		<?php
	}
}
