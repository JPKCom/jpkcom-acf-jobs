<?php
/**
 * Helper functions
 */

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( function: 'jpkcom_render_acf_fields' ) ) {

    /**
     * Renders all ACF fields of a post with Bootstrap 5 markup and smart icons.
     *
     * @param string $post_type Optional post type for field group query. If empty, current_post_type is used.
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

            $acf_field = function_exists( function: 'get_field_object' ) ? get_field_object( $key, $post->ID ) : null;
            $type      = $acf_field['type'] ?? '';
            $label     = $acf_field['label'] ?? jpkcom_get_acf_field_label( field_name: $key, post_type: $post_type );

            // 🧠 Icon-Mapping nach Feldtyp
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

                                echo '<th>' . esc_html( jpkcom_get_acf_field_label( field_name: $sub_key, post_type: $post_type ) ) . '</th>';
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
                            echo '<dt class="col-sm-4 small text-muted">' . esc_html( jpkcom_get_acf_field_label( field_name: $sub_key, post_type: $post_type ) ) . '</dt>';
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

    function acf_get_field_label( $field_key ): mixed {

        $field = acf_get_field( $field_key );

        return $field['label'] ?? ucfirst( string: str_replace( search: '_', replace: ' ', subject: $field_key ) );

    }

}


if ( ! function_exists( function: 'jpkcom_get_acf_field_label' ) ) {
    /**
     * Returns the ACF field label based on the field name.
     *
     * @param string $field_name
     * @param string $post_type Optional - for better context
     * @return string
     */
    function jpkcom_get_acf_field_label( string $field_name, string $post_type = '' ): string {

        if ( function_exists( function: 'acf_get_field_groups' ) && function_exists( function: 'acf_get_fields' ) ) {

            $groups = acf_get_field_groups( ['post_type' => $post_type] );

            foreach ( $groups as $group ) {

                $fields = acf_get_fields( $group['key'] );

                if ( $fields ) {

                    foreach ( $fields as $field ) {

                        if ( $field['name'] === $field_name ) {

                            return $field['label'];

                        }

                    }

                }

            }

        }

        // Fallback: generic label name
        return ucfirst( string: str_replace( search: '_', replace: ' ', subject: $field_name ) );
    }

}


if ( ! function_exists( function: 'jpkcom_human_readable_relative_date' ) ) {
    /**
     * Converts a timestamp into a human-readable relative date string
     *
     * @param int $timestamp The timestamp to convert
     * @return string The human-readable relative date string
     */
    function jpkcom_human_readable_relative_date( $timestamp ): mixed {

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
