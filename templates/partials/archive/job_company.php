<?php
/**
 * Template Partial: job_company
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_company' ) ) { ?>
    <li class="d-block">
        <strong><?php echo __( 'Company', 'jpkcom-acf-jobs' ); ?>:</strong><br>
        <?php
        $companies = get_field( 'job_company' );
        if ( $companies && is_array( value: $companies ) ) {
            $total = count( value: $companies );
            $i = 0;
            foreach ( $companies as $company ) {
                echo get_the_title( $company->ID );
                $i++;
                if ( $i < $total ) {
                    echo ',<br>';
                }
            }
        }
        ?>
    </li>
<?php } ?>
