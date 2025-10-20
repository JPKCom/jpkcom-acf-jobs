<?php
/**
 * Template Partial: job_layout_content
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( have_rows( 'job_layout_content' ) ) : ?>

    <section class="job-layout-content my-5">

        <?php while ( have_rows( 'job_layout_content' ) ) : the_row(); ?>

            <?php if ( get_row_layout() === 'layout_2_col_text_img' ) : ?>

                <?php
                $text_left = get_sub_field( 'text_left' );
                $img_right = get_sub_field( 'img_right' );
                ?>
                <div class="row align-items-center my-5">
                    <div class="col-md-6">
                        <?php echo wp_kses_post( $text_left ); ?>
                    </div>
                    <div class="col-md-6 text-center">
                        <?php if ( !empty( $img_right ) ) : ?>
                            <?php echo wp_get_attachment_image( $img_right['ID'], 'large', false, ['class' => 'img-fluid rounded'] ); ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ( get_row_layout() === 'layout_2_col_img_text' ) : ?>

                <?php
                $img_left = get_sub_field( 'img_left' );
                $text_right = get_sub_field( 'text_right' );
                ?>
                <div class="row align-items-center my-5 flex-row-reverse flex-md-row">
                    <div class="col-md-6 text-center">
                        <?php if ( !empty($img_left) ) : ?>
                            <?php echo wp_get_attachment_image( $img_left['ID'], 'large', false, ['class' => 'img-fluid rounded'] ); ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php echo wp_kses_post( $text_right ); ?>
                    </div>
                </div>

            <?php elseif ( get_row_layout() === 'layout_wysiwyg_full' ) : ?>

                <?php $wysiwyg_full = get_sub_field( 'wysiwyg_full' ); ?>
                <div class="row my-5">
                    <div class="col-12">
                        <?php echo wp_kses_post( $wysiwyg_full ); ?>
                    </div>
                </div>

            <?php endif; ?>

        <?php endwhile; ?>

    </section>

<?php endif; ?>
