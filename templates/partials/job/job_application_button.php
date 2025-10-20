<?php
/**
 * Template Partial: job_application_button
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_application_show_button' ) ) { ?>
    <?php if ( get_field( 'job_application_button' ) ) { ?>

        <?php
            $job_application_button = get_field('job_application_button');

            if ( $job_application_button && is_array( value: $job_application_button ) ) {

                $button_title  = $job_application_button['title'] ?? '';
                $button_url    = $job_application_button['url'] ?? '';
                $button_target = $job_application_button['target'] ?? '';

                if ( ! empty( $button_url ) && ! empty( $button_title ) ) {

                    echo '<div class="d-grid gap-2">';
                    echo '<a href="' . esc_url( $button_url ) . '" class="btn btn-primary btn-lg"';

                    if ( ! empty( $button_target ) ) {

                        echo ' target="' . esc_attr( $button_target ) . '"';

                    }

                    echo '>';
                    echo esc_html( $button_title );
                    echo '</a>';
                    echo '</div>';
                }
            }
        ?>

    <?php } ?>
<?php } ?>
