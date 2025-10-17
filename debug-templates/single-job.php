<?php
/**
 * Template: Single Job
 */

// Exit if accessed directly
defined(constant_name: 'ABSPATH') || exit;

get_header();

if ( have_posts() ) :
    while ( have_posts() ) : the_post();
?>

<main id="job-<?php the_ID(); ?>" <?php post_class( 'jpkcom-acf-job--single-job container mx-auto py-12' ); ?>>

<div class="container my-5">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <h1 class="display-4 mb-3"><?php the_title(); ?></h1>

            <?php if ( has_post_thumbnail() ): ?>
                <div class="mb-4">
                    <?php the_post_thumbnail('large', ['class'=>'img-fluid rounded']); ?>
                </div>
            <?php endif; ?>

            <div class="mb-5">
                <?php the_content(); ?>
            </div>

            <h2 class="h4 mb-3">Details</h2>
            <?php jpkcom_render_acf_fields(); ?>

            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/debug/schema-job' ); ?>

            <?php
                if ( current_user_can( 'edit_post', get_the_ID() ) ) {

                    edit_post_link( __( 'Edit job', 'jpkcom-acf-jobs' ), '<p class="edit-link">', '</p>' );

                }
            ?>

            <p><a href="<?php echo get_post_type_archive_link( 'job' ); ?>" class="btn btn-primary">&larr; <?php echo __( 'Back to overview', 'jpkcom-acf-jobs' ); ?></a></p>
        </div>
    </div>
</div>

</main>

<?php
    endwhile;
endif;

get_footer();
