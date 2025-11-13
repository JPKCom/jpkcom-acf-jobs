<?php
/**
 * Helper functions for ACF field rendering and formatting
 *
 * @package   JPKCom_ACF_Jobs
 * @since     1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_render_acf_fields' ) ) {

    /**
     * Renders all ACF fields of a post with Bootstrap 5 markup and smart icons
     *
     * Automatically detects field types and renders them with appropriate styling:
     * - Images: Responsive with rounded corners
     * - WYSIWYG/Textarea: Light background container
     * - Relationships/Post Objects: Linked post titles
     * - True/False: Badge indicators
     * - Repeater: Responsive tables
     * - Groups: Nested definition lists
     *
     * @since 1.0.0
     *
     * @global WP_Post $post Current post object.
     *
     * @param string $post_type Optional. Post type for field group query. Default empty (uses current post type).
     * @return void
     */
    function jpkcom_render_acf_fields( string $post_type = '' ): void {

        global $post;
        if ( ! $post ) return;

        $post_type = $post_type ?: get_post_type( $post );
        $fields = get_fields( $post->ID );

        if ( ! $fields ) {

            echo '<p class="text-muted">Keine weiteren Informationen vorhanden.</p>';
            return;

        }

        echo '<dl class="row">';

        foreach ( $fields as $key => $value ) {

            if ( empty( $value ) ) continue;

            // Skip ACF internal fields (they start with _)
            if ( str_starts_with( haystack: $key, needle: '_' ) ) continue;

            // Get field object - works with both field names and field keys
            $acf_field = function_exists( function: 'get_field_object' ) ? get_field_object( $key, $post->ID ) : null;
            $type      = $acf_field['type'] ?? '';

            // Get label - use field object first, then helper function (handles both names and keys)
            $label = $acf_field['label'] ?? jpkcom_get_acf_field_label( field_name_or_key: $key, post_type: $post_type );

            // 🧠 Icon-Mapping by Field Type
            $icons = [
                'text'        => '📝',
                'email'       => '✉️',
                'url'         => '🔗',
                'number'      => '🔢',
                'date'        => '📅',
                'date_picker' => '📅',
                'time'        => '⏰',
                'image'       => '🖼️',
                'file'        => '📎',
                'wysiwyg'     => '🖋️',
                'textarea'    => '🖋️',
                'repeater'    => '📋',
                'group'       => '🧩',
                'true_false'  => '✅',
                'select'      => '🎚️',
                'checkbox'    => '☑️',
                'radio'       => '🔘',
                'relationship'=> '🔗',
                'post_object' => '📄',
                'user'        => '👤',
                'taxonomy'    => '🏷️'
            ];

            $icon = $icons[$type] ?? '🔹';

            echo '<dt class="col-sm-3 fw-bold">' . $icon . ' ' . esc_html( $label ) . ':</dt>';
            echo '<dd class="col-sm-9">';

            // === Typ-basierte Ausgabe ===
            switch ( $type ) {

                case 'image':
                    if ( is_array( value: $value ) && isset( $value['url'] ) ) {

                        echo '<img src="' . esc_url( $value['url'] ) . '" alt="' . esc_attr( $label ) . '" class="img-fluid rounded mb-3 shadow-sm">';

                    }
                    break;

                case 'wysiwyg':
                case 'textarea':
                    echo '<div class="border rounded p-3 bg-light-subtle mb-3">' . wp_kses_post( $value ) . '</div>';
                    break;

                case 'relationship':
                case 'post_object':
                    $posts = is_array( value: $value ) ? $value : [ $value ];

                    foreach ( $posts as $related_post ) {

                        if ( is_object( value: $related_post ) ) {

                            echo '<a href="' . esc_url( get_permalink( $related_post->ID ) ) . '" class="text-decoration-none d-block mb-1">' . esc_html( $related_post->post_title ) . '</a>';

                        }

                    }
                    break;

                case 'true_false':
                    echo $value ? '<span class="badge bg-success">Ja</span>' : '<span class="badge bg-secondary">Nein</span>';
                    break;

                case 'repeater':
                    if ( is_array( value: $value ) && ! empty( $value ) ) {

                        echo '<div class="table-responsive mb-3">';
                        echo '<table class="table table-sm table-striped table-hover table-bordered align-middle">';

                        if ( isset( $value[0] ) && is_array( value: $value[0] ) ) {

                            echo '<thead class="table-light"><tr>';
                            foreach ( array_keys( array: $value[0] ) as $sub_key ) {

                                echo '<th>' . esc_html( jpkcom_get_acf_field_label( field_name_or_key: $sub_key, post_type: $post_type ) ) . '</th>';
                            }

                            echo '</tr></thead>';
                        }

                        echo '<tbody>';

                        foreach ( $value as $row ) {

                            echo '<tr>';

                            foreach ( $row as $col ) {

                                if ( is_array( value: $col ) ) $col = implode( separator: ', ', array: array_filter( array: $col ) );
                                echo '<td>' . esc_html( $col ) . '</td>';

                            }

                            echo '</tr>';

                        }

                        echo '</tbody></table></div>';

                    }
                    break;

                case 'group':
                    if ( is_array( value: $value ) ) {

                        echo '<dl class="row border rounded p-3 mb-3 bg-light-subtle">';

                        foreach ( $value as $sub_key => $sub_val ) {

                            if ( empty( $sub_val ) ) continue;
                            echo '<dt class="col-sm-4 small text-muted">' . esc_html( jpkcom_get_acf_field_label( field_name_or_key: $sub_key, post_type: $post_type ) ) . '</dt>';
                            echo '<dd class="col-sm-8 small">' . esc_html( is_array( value: $sub_val ) ? implode( separator: ', ', array: $sub_val ) : $sub_val ) . '</dd>';

                        }

                        echo '</dl>';

                    }
                    break;

                default:
                    if ( is_array( value: $value ) ) {

                        echo '<ul class="list-group mb-3">';

                        foreach ( $value as $item ) {

                            if ( is_array( value: $item ) ) {

                                $flat_value = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                                echo '<li class="list-group-item small text-break"><code>' . esc_html( $flat_value ) . '</code></li>';

                            } else {

                                echo '<li class="list-group-item small text-break">' . esc_html( (string) $item ) . '</li>';

                            }

                        }

                        echo '</ul>';

                    } else {

                        echo wp_kses_post( (string) $value );

                    }
                    break;

            }

            echo '</dd>';
        
        }

        echo '</dl>';
    
    }
}


if ( ! function_exists( function: 'acf_get_field_label' ) ) {
    /**
     * Get ACF field label by field key or field name
     *
     * Attempts to retrieve the field label from ACF. If not found,
     * returns a formatted fallback based on the field name/key.
     *
     * @since 1.0.0
     *
     * @param string $field_key_or_name Field key (e.g., 'field_abc123') or field name (e.g., 'job_title').
     * @return string Field label or formatted fallback string.
     */
    function acf_get_field_label( string $field_key_or_name ): string {

        if ( function_exists( function: 'acf_get_field' ) ) {

            $field = acf_get_field( $field_key_or_name );

            if ( $field && ! empty( $field['label'] ) ) {

                return $field['label'];

            }

        }

        // Fallback: remove field_ prefix and format nicely
        $clean_name = str_starts_with( haystack: $field_key_or_name, needle: 'field_' )
            ? substr( string: $field_key_or_name, offset: 6 )
            : $field_key_or_name;

        return ucfirst( string: str_replace( search: '_', replace: ' ', subject: $clean_name ) );

    }

}


if ( ! function_exists( function: 'jpkcom_get_acf_field_label' ) ) {
    /**
     * Get ACF field label with enhanced search capabilities
     *
     * Searches for field labels in this order:
     * 1. Direct field key lookup (if starts with 'field_')
     * 2. Search through field groups by post type
     * 3. Search through sub_fields (repeater/group fields)
     * 4. Fallback to formatted field name
     *
     * @since 1.0.0
     *
     * @param string $field_name_or_key Field name (e.g., 'job_title') or field key (e.g., 'field_abc123').
     * @param string $post_type         Optional. Post type for context-specific field group search. Default empty.
     * @return string Field label or formatted fallback string.
     */
    function jpkcom_get_acf_field_label( string $field_name_or_key, string $post_type = '' ): string {

        // Try to get field directly by key first (most reliable)
        if ( function_exists( function: 'acf_get_field' ) && str_starts_with( haystack: $field_name_or_key, needle: 'field_' ) ) {

            $field = acf_get_field( $field_name_or_key );

            if ( $field && ! empty( $field['label'] ) ) {

                return $field['label'];

            }

        }

        // Search through field groups for field name or key
        if ( function_exists( function: 'acf_get_field_groups' ) && function_exists( function: 'acf_get_fields' ) ) {

            $groups = acf_get_field_groups( ['post_type' => $post_type] );

            foreach ( $groups as $group ) {

                $fields = acf_get_fields( $group['key'] );

                if ( $fields ) {

                    foreach ( $fields as $field ) {

                        // Check both field name and field key
                        if ( $field['name'] === $field_name_or_key || $field['key'] === $field_name_or_key ) {

                            return $field['label'];

                        }

                        // Also check sub_fields for repeater/group fields
                        if ( ! empty( $field['sub_fields'] ) && is_array( value: $field['sub_fields'] ) ) {

                            foreach ( $field['sub_fields'] as $sub_field ) {

                                if ( $sub_field['name'] === $field_name_or_key || $sub_field['key'] === $field_name_or_key ) {

                                    return $sub_field['label'];

                                }

                            }

                        }

                    }

                }

            }

        }

        // Fallback: generic label name - remove field_ prefix if present
        $clean_name = str_starts_with( haystack: $field_name_or_key, needle: 'field_' )
            ? substr( string: $field_name_or_key, offset: 6 )
            : $field_name_or_key;

        return ucfirst( string: str_replace( search: '_', replace: ' ', subject: $clean_name ) );
    }

}


if ( ! function_exists( function: 'jpkcom_human_readable_relative_date' ) ) {
    /**
     * Convert timestamp to human-readable relative date string
     *
     * Converts Unix timestamps into relative date strings like:
     * - "Published today"
     * - "Published yesterday"
     * - "Published 3 days ago"
     * - "Published 2 weeks ago"
     * - "Published 5 months ago"
     * - "Published 2 years ago"
     *
     * All strings are translatable via the 'jpkcom-acf-jobs' text domain.
     *
     * @since 1.0.0
     *
     * @param int $timestamp Unix timestamp to convert.
     * @return string Translated relative date string.
     */
    function jpkcom_human_readable_relative_date( int $timestamp ): string {

        $time_difference = current_time( 'U' ) - $timestamp;  // Calculate the time difference between now and the timestamp
        $seconds_in_a_day = 86400;  // Number of seconds in a day

        if ( $time_difference < 0 ) {

            return __( 'Published in the future', 'jpkcom-acf-jobs' );  // Handle future dates

        } elseif ( $time_difference < $seconds_in_a_day ) {

            return __( 'Published today', 'jpkcom-acf-jobs' );  // Handle same-day dates

        } elseif ( $time_difference < 2 * $seconds_in_a_day ) {

            return __( 'Published yesterday', 'jpkcom-acf-jobs' );  // Handle one-day-old dates

        } elseif ( $time_difference < 7 * $seconds_in_a_day ) {

            $days = floor( num: $time_difference / $seconds_in_a_day );  // Calculate full days ago
            return __( 'Published', 'jpkcom-acf-jobs' ) . ' ' . $days . ' ' . ( $days == 1 ? __( 'day', 'jpkcom-acf-jobs' ) : __( 'days', 'jpkcom-acf-jobs' ) ) . ' ' . __( 'ago', 'jpkcom-acf-jobs' );  // Handle dates within the last week

        } elseif ( $time_difference < 30 * $seconds_in_a_day ) {

            $weeks = floor( num: $time_difference / ( 7 * $seconds_in_a_day ) );  // Calculate full weeks ago
            return __( 'Published', 'jpkcom-acf-jobs' ) . ' ' . $weeks . ' ' . ( $weeks == 1 ? __( 'week', 'jpkcom-acf-jobs' ) : __( 'weeks', 'jpkcom-acf-jobs' ) ) . ' ' . __( 'ago', 'jpkcom-acf-jobs' );  // Handle dates within the last month

        } elseif ( $time_difference < 365 * $seconds_in_a_day ) {

            $months = floor( num: $time_difference / ( 30 * $seconds_in_a_day ) );  // Calculate full months ago
            return __( 'Published', 'jpkcom-acf-jobs' ) . ' ' . $months . ' ' . ( $months == 1 ? __( 'month', 'jpkcom-acf-jobs' ) : __( 'months', 'jpkcom-acf-jobs' ) ) . ' ' . __( 'ago', 'jpkcom-acf-jobs' );  // Handle dates within the last year

        } else {

            $years = floor( num: $time_difference / ( 365 * $seconds_in_a_day ) );  // Calculate full years ago
            return __( 'Published', 'jpkcom-acf-jobs' ) . ' ' . $years . ' ' . ( $years == 1 ? __( 'year', 'jpkcom-acf-jobs' ) : __( 'years', 'jpkcom-acf-jobs' ) ) . ' ' . __( 'ago', 'jpkcom-acf-jobs' );  // Handle dates older than a year

        }

    }
}
