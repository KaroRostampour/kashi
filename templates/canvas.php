<?php
/**
 * کاشی — بوم خالی: بدون هدر و فوتر قالب، فقط بخش‌های شما
 * (استایل‌ها و اسکریپت‌های لازم سایت از طریق wp_head / wp_footer لود می‌شوند)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'kashi-canvas' ); ?>>
<?php
wp_body_open();

while ( have_posts() ) :
	the_post();
	echo '<div class="kashi-page kashi-page-canvas">';
	the_content();
	echo '</div>';
endwhile;

wp_footer();
?>
</body>
</html>
