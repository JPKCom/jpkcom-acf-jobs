<?php
/**
 * Template: Archive Jobs
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;

get_header();
?>

<div id="content" class="jpkcom-acf-job--archive site-content container pt-4 pb-5">
    <div id="primary" class="content-area">

        <main id="main" class="site-main">

            <!-- Header -->
            <div class="p-5 text-center bg-body-tertiary rounded mb-4">
                <h1 class="entry-title display-4 mb-4"><?php echo __( 'Current job offers', 'jpkcom-acf-jobs' ); ?></h1>
            </div>

            <!-- Job List -->
            <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>

                <article id="job-<?php the_ID(); ?>" class="jpkcom-acf-job--item card horizontal p-0 mb-4<?php if ( get_field('job_featured') ) { echo ' jpkcom-acf-job--item-featured'; } ?><?php if ( get_field('job_closed') ) { echo ' jpkcom-acf-job--item-closed'; } ?>">

                    <div class="row g-0">

                        <?php if ( has_post_thumbnail() ) : ?>
                            <div class="col-lg-6 col-xl-5 col-xxl-4">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail( 'jpkcom-acf-job-16x9', array( 'class' => 'jpkcom-acf-job--16x9 card-img-lg-start' ) ); ?>
                            </a>
                            </div>
                        <?php endif; ?>

                        <div class="col">

                            <header class="card-header">

                                <div class="d-flex justify-content-start gap-3">

                                    <?php if ( get_field('job_featured') ) { ?>
                                        <p class="sticky-badge"><span class="badge text-bg-danger"><i class="fa-solid fa-star"></i></span></p>
                                    <?php } ?>

                                    <a class="text-body text-decoration-none" href="<?php the_permalink(); ?>">
                                        <?php the_title('<h2 class="job-title h4">', '</h2>'); ?>
                                    </a>

                                </div>

                                <?php if ('job' === get_post_type()) : ?>
                                <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/layout/meta' ); ?>
                                <?php endif; ?>

                            </header>

                            <div class="card-body">

                                <?php if ( get_field( 'job_company' ) || get_field( 'job_location' ) || get_field( 'job_type' ) ) { ?>
                                <div class="alert alert-light d-flex p-3">
                                    <ul class="list-unstyled d-md-flex w-100 justify-content-md-between align-items-md-stretch gap-3">

                                    <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/archive/job_type' ); ?>

                                    <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/archive/job_company' ); ?>

                                    <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/archive/job_location' ); ?>

                                    </ul>
                                </div>
                                <?php } ?>

                                <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_closed' ); ?>

                                <p class="card-text">
                                    <a class="text-body text-decoration-none" href="<?php the_permalink(); ?>">
                                        <?php echo wp_kses_post( get_field( 'job_short_description' ) ); ?>
                                    </a>
                                </p>

                            </div>

                            <footer class="card-footer text-end">

                                <a href="<?php the_permalink(); ?>" class="btn btn-primary stretched-link"><?php echo __( 'View details…', 'jpkcom-acf-jobs' ); ?></a>

                            </footer>

                        </div>

                    </div>

                </article>

            <?php endwhile; ?>

            <?php jpkcom_acf_jobs_pagination(); ?>

            <?php else : ?>
                <div class="alert alert-info" role="alert">
                    <p><?php echo __( 'There are currently no job offers available.', 'jpkcom-acf-jobs' ); ?></p>
                </div>
            <?php endif; ?>

        </main>

    </div>
</div>

<?php get_footer(); ?>
