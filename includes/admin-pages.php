<?php
/**
 * Admin Pages and Settings
 *
 * Registers admin pages under the Jobs post type menu and handles settings.
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.3.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

/**
 * Register admin menu pages
 *
 * @since 1.3.0
 * @return void
 */
add_action( 'admin_menu', function(): void {

    // Settings page
    add_submenu_page(
        'edit.php?post_type=job',
        __( 'Options', 'jpkcom-acf-jobs' ),
        __( 'Options', 'jpkcom-acf-jobs' ),
        'manage_options',
        'jpkcom-acf-job-options',
        'jpkcom_acf_jobs_options_page'
    );

}, 20 );

/**
 * Register settings
 *
 * @since 1.3.0
 * @return void
 */
add_action( 'admin_init', function(): void {

    // Register settings group
    register_setting(
        'jpkcom_acf_job_options',
        'jpkcom_acf_job_disable_archive',
        [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]
    );

    register_setting(
        'jpkcom_acf_job_options',
        'jpkcom_acf_job_archive_redirect_url',
        [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'esc_url_raw',
        ]
    );

    // Add settings section
    add_settings_section(
        'jpkcom_acf_job_archive_section',
        __( 'Archive Settings', 'jpkcom-acf-jobs' ),
        function() {
            echo '<p>' . esc_html__( 'Configure the job archive page behavior.', 'jpkcom-acf-jobs' ) . '</p>';
        },
        'jpkcom-acf-job-options'
    );

    // Disable archive field
    add_settings_field(
        'jpkcom_acf_job_disable_archive',
        __( 'Disable Job Archive', 'jpkcom-acf-jobs' ),
        'jpkcom_acf_jobs_disable_archive_field',
        'jpkcom-acf-job-options',
        'jpkcom_acf_job_archive_section'
    );

    // Archive redirect URL field
    add_settings_field(
        'jpkcom_acf_job_archive_redirect_url',
        __( 'Archive Redirect URL', 'jpkcom-acf-jobs' ),
        'jpkcom_acf_jobs_redirect_url_field',
        'jpkcom-acf-job-options',
        'jpkcom_acf_job_archive_section'
    );

} );

/**
 * Render disable archive checkbox field
 *
 * @since 1.3.0
 * @return void
 */
function jpkcom_acf_jobs_disable_archive_field(): void {
    $value = get_option( 'jpkcom_acf_job_disable_archive', false );
    ?>
    <label for="jpkcom_acf_job_disable_archive">
        <input
            type="checkbox"
            id="jpkcom_acf_job_disable_archive"
            name="jpkcom_acf_job_disable_archive"
            value="1"
            <?php checked( $value, true ); ?>
        >
        <?php echo esc_html__( 'Redirect all access to the job archive page (/jobs/)', 'jpkcom-acf-jobs' ); ?>
    </label>
    <p class="description">
        <?php echo esc_html__( 'When enabled, visitors will be redirected from the archive page. Single job pages remain accessible.', 'jpkcom-acf-jobs' ); ?>
    </p>
    <?php
}

/**
 * Render archive redirect URL field
 *
 * @since 1.3.0
 * @return void
 */
function jpkcom_acf_jobs_redirect_url_field(): void {
    $value = get_option( 'jpkcom_acf_job_archive_redirect_url', '' );
    ?>
    <input
        type="url"
        id="jpkcom_acf_job_archive_redirect_url"
        name="jpkcom_acf_job_archive_redirect_url"
        value="<?php echo esc_attr( $value ); ?>"
        class="regular-text"
        placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
    >
    <p class="description">
        <?php echo esc_html__( 'Optional: Specify a custom redirect URL. If empty, redirects to the homepage. Only applies when archive is disabled.', 'jpkcom-acf-jobs' ); ?>
    </p>
    <?php
}

/**
 * Render Options admin page
 *
 * @since 1.3.0
 * @return void
 */
function jpkcom_acf_jobs_options_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Check if settings were saved
    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error(
            'jpkcom_acf_job_messages',
            'jpkcom_acf_job_message',
            __( 'Settings saved successfully.', 'jpkcom-acf-jobs' ),
            'success'
        );
    }

    settings_errors( 'jpkcom_acf_job_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'jpkcom_acf_job_options' );
            do_settings_sections( 'jpkcom-acf-job-options' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
