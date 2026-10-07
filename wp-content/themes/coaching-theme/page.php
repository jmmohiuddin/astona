<?php get_header(); the_post(); ?>
<section class="page-head"><div class="container"><h1><?php the_title(); ?></h1></div></section>
<section class="section"><div class="container prose"><?php the_content(); ?></div></section>
<?php get_footer(); ?>
