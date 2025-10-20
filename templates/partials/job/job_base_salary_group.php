<?php
/**
 * Template Partial: job_base_salary_group
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php if ( get_field( 'job_base_salary_group' ) ) {

    $job_base_salary_group = get_field( 'job_base_salary_group' );

    if ( $job_base_salary_group['job_salary'] ) {

        echo '<h3 class="fs-4">' . __( 'Basic salary', 'jpkcom-acf-jobs' ) . '</h3>';

        $job_salary = $job_base_salary_group['job_salary'];

        $job_salary_currency = $job_base_salary_group['job_salary_currency']['label'];
        $job_salary_period = $job_base_salary_group['job_salary_period']['label'];

        echo '<p>';
        echo number_format( num: $job_salary, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' ' . $job_salary_currency . ' ' . $job_salary_period;
        echo '</p>';

        echo '<hr>';

    }

} ?>
