<?php
/**
 * کاشی — طرح تمام‌عرض: هدر و فوتر قالب + محتوای لبه‌تالبه
 * (بدون عنوان، ستون کناری و کانتینر قالب)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	echo '<div class="kashi-page kashi-page-full">';
	the_content();
	echo '</div>';
endwhile;

get_footer();
