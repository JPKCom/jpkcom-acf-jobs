<?php
/**
 * Template: Single Job
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;

get_header();
?>

<div id="content" class="site-content container jpkcom-acf-job--single-job pt-3 pb-5">
    <div id="primary" class="content-area">

        <?php jpkcom_acf_jobs_breadcrumb(); ?>

        <main id="main" class="site-main<?php if ( get_field('job_featured') ) { echo ' jpkcom-acf-job--item-featured'; } ?><?php if ( get_field('job_closed') ) { echo ' jpkcom-acf-job--item-closed'; } ?>"></main>

        <div class="row mb-3">

            <div class="col">

                <div class="entry-header">

                    <?php the_post(); ?>

                    <div class="d-flex justify-content-start gap-3">

                        <?php if ( get_field('job_featured') ) { ?>
                            <p class="sticky-badge fs-2"><span class="badge text-bg-danger"><i class="fa-solid fa-star"></i></span></p>
                        <?php } ?>

                        <?php the_title('<h1 class="job-title">', '</h1>'); ?>

                    </div>

                    <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/layout/meta' ); ?>

                    <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_closed' ); ?>

                </div>

            </div>

        </div>

            <div class="row gx-md-4">

                <div class="col-md-7 d-flex flex-column mb-4">

                    <div class="flex-grow-1 p-4 rounded text-bg-light">

                        <?php the_post_thumbnail( 'jpkcom-acf-job-header', array( 'class' => 'jpkcom-acf-job--header rounded mb-4' ) ); ?>

                        <div class="entry-content">

                            <h2><?php echo __( 'Description', 'jpkcom-acf-jobs' ); ?>:</h2>

                            <hr>

                            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_short_description' ); ?>

                            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_layout_content' ); ?>

                            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_application_description' ); ?>

                            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_application_button' ); ?>

                            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_application_shortcode' ); ?>

                        </div>

                    </div>

                </div>

                <div class="col-md-5 d-flex flex-column mb-4">

                    <div class="flex-grow-1 p-4 rounded text-bg-secondary">

                        <h2><?php echo __( 'Details', 'jpkcom-acf-jobs' ); ?>:</h2>

                        <hr>

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_type' ); ?>

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_company' ); ?>

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_location' ); ?>

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_base_salary_group' ); ?>

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/job/job_attribute' ); ?>

                    </div>

                </div>

            </div>

            <div class="row mt-4">

                <div class="col">

                    <div class="entry-footer clear-both">

                        <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/layout/pagination-page' ); ?>

                    </div>

                </div>

            </div>

            <?php jpkcom_acf_jobs_get_template_part( slug: 'partials/debug/schema-job' ); ?>

            <?php

                $schema_json = jpkcom_acf_jobs_get_schema_job_posting( post_id: get_the_ID() );

                if ( $schema_json ) {

                    echo '<script type="application/ld+json">' . $schema_json . '</script>';

                }

            ?>

        </main>

    </div>
</div>

<?php
get_footer();
