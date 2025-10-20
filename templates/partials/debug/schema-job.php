<?php
/**
 * Debug Partial: Job Schema JSON-LD
 */

// Exit if accessed directly
defined( constant_name: 'ABSPATH' ) || exit;
?>

<?php
if ( ! current_user_can( 'manage_options' ) ) {

    return;

}

$schema_json = jpkcom_acf_jobs_get_schema_job_posting( post_id: get_the_ID() );

if ( ! $schema_json ) {

    echo '<p class="text-danger fw-bold">' . __( 'No schema generated for this job.', 'jpkcom-acf-jobs' ) . '</p>';

    return;

}

$required_fields = [
    'title',
    'description',
    'datePosted',
    'validThrough',
    'employmentType',
    'hiringOrganization',
    'jobLocation'
];

$missing_fields = [];

$schema_array = json_decode( json: $schema_json, associative: true );

if ( json_last_error() !== JSON_ERROR_NONE ) {

    echo '<p class="text-danger fw-bold">' . __( 'Error parsing JSON-LD schema!', 'jpkcom-acf-jobs' ) . 'Fehler beim Parsen des JSON-LD Schemas!</p>';

    $schema_array = [];

}

foreach ( $required_fields as $field ) {

    if ( empty( $schema_array[ $field ] ) ) {

        $missing_fields[] = $field;

    }

}
?>

<div class="jpkcomacf-jobs--schema-debug p-3 mb-4 rounded border bg-light">

    <?php if ( ! empty( $missing_fields ) ) : ?>

        <p class="text-danger fw-bold">
            <?php echo __( 'Missing mandatory fields', 'jpkcom-acf-jobs' ); ?>: <?php echo esc_html( implode( separator: ', ', array: $missing_fields ) ); ?>
        </p>

    <?php else : ?>

        <p class="text-success fw-bold"><?php echo __( 'All required fields present', 'jpkcom-acf-jobs' ); ?> ✅</p>

    <?php endif; ?>

    <h5>Job Schema (JSON-LD)</h5>
    <pre style="overflow-x:auto;"><?php echo esc_html( $schema_json ); ?></pre>
</div>
