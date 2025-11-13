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

        // Handle both array format and string format for backwards compatibility
        $currency_data = $job_base_salary_group['job_salary_currency'];
        $job_salary_currency = is_array( value: $currency_data ) && isset( $currency_data['label'] ) ? $currency_data['label'] : $currency_data;

        $period_data = $job_base_salary_group['job_salary_period'];
        $job_salary_period = is_array( value: $period_data ) && isset( $period_data['label'] ) ? $period_data['label'] : $period_data;

        echo '<p>';
        echo number_format( num: $job_salary, decimals: 2, decimal_separator: ',', thousands_separator: '.') . ' ' . $job_salary_currency . ' ' . $job_salary_period;
        echo '</p>';

        echo '<hr>';

    }

} ?>
