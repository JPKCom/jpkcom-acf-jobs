<?php
/**
 * JPKCom Plugin Updater – GitHub Self-Hosted Updates
 *
 * Namespace: JPKComGitUpdate
 * PHP Version: 8.3+
 * WordPress Version: 6.8+
 */

declare(strict_types=1);

namespace JPKComGitUpdate;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class PluginUpdater {

    /** @var string Plugin slug (directory name) */
    private string $plugin_slug;

    /** @var string Path to main plugin file */
    private string $plugin_file;

    /** @var string Current plugin version */
    private string $current_version;

    /** @var string Remote manifest URL */
    private string $manifest_url;

    /** @var string Cache key for transient */
    private string $cache_key;

    /** @var bool Whether caching is enabled */
    private bool $cache_enabled = true;

    /**
     * Constructor
     *
     * @param string $plugin_file      Absolute path to the main plugin file (__FILE__).
     * @param string $current_version  Current plugin version.
     * @param string $manifest_url     Full URL to the remote JSON manifest.
     */
    public function __construct( string $plugin_file, string $current_version, string $manifest_url ) {
        global $wp_version;

        // Environment check
        if ( version_compare( version1: PHP_VERSION, version2: '8.3', operator: '<' ) || version_compare( version1: $wp_version, version2: '6.8', operator: '<' ) ) {
            return;
        }

        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname( path: plugin_basename( $plugin_file ) );
        $this->current_version = $current_version;
        $this->manifest_url    = $manifest_url;
        $this->cache_key       = 'jpk_git_update_' . md5( string: $this->plugin_slug );

        // Hook into WordPress update system
        add_filter( 'plugins_api', [$this, 'plugin_info'], 20, 3 );
        add_filter( 'site_transient_update_plugins', [$this, 'check_update'] );
        add_action( 'upgrader_process_complete', [$this, 'clear_cache'], 10, 2 );
    }

    /**
     * Fetch and decode the remote manifest file.
     *
     * @return ?object Decoded manifest or null on failure.
     */
    private function get_remote_manifest(): ?object {
        $remote = get_transient( $this->cache_key );

        if ( false === $remote || !$this->cache_enabled ) {
            $response = wp_remote_get( $this->manifest_url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ] );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                return null;
            }

            $remote = json_decode( json: wp_remote_retrieve_body( $response ) );
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            set_transient( $this->cache_key, $remote, DAY_IN_SECONDS );
        }

        return is_object( value: $remote ) ? $remote : null;
    }

    /**
     * Provide detailed plugin info in the “View Details” modal.
     *
     * @param mixed  $result Default response.
     * @param string $action Current action.
     * @param object $args   API request arguments.
     * @return mixed
     */
    public function plugin_info( mixed $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $remote = $this->get_remote_manifest();
        if ( ! $remote ) {
            return $result;
        }

        $sections = [];
        foreach ( ['description','installation','changelog','faq'] as $key ) {
            if ( ! empty($remote->sections->$key ) ) {
                $sections[$key] = wp_kses_post( string: trim( string: $remote->sections->$key ) );
            }
        }

        if ( ! empty( $remote->readme_html ) ) {
            $sections['readme'] = wp_kses_post( $remote->readme_html );
        }

        $info = new \stdClass();
        $info->name             = $remote->name ?? '';
        $info->slug             = $remote->slug ?? $this->plugin_slug;
        $info->version          = $remote->version ?? $this->current_version;
        $info->author           = $remote->author ?? '';
        $info->author_profile   = $remote->author_profile ?? '';

        /**
         * Normalize contributors to WP-compatible array format.
         *
         * Example output:
         * [
         *   "JPKCom" => [
         *     "profile" => "https://profiles.wordpress.org/JPKCom",
         *     "avatar"  => "https://wordpress.org/grav-redirect.php?user=JPKCom&s=36"
         *   ]
         * ]
         */
        $contributors = $remote->contributors ?? [];

        if ( is_object( value: $contributors ) ) {
            $contributors = (array) $contributors;
        } elseif ( is_string( value: $contributors ) ) {
            $contributors = [ $contributors ];
        }

        if ( is_array( value: $contributors ) ) {
            $contributors = array_reduce(
                array: $contributors,
                callback: static function ( array $carry, $item ): array {
                    $username = trim( string: (string) $item );
                    if ( $username === '' ) {
                        return $carry;
                    }

                    $carry[ $username ] = [
                        'profile' => sprintf( format: 'https://profiles.wordpress.org/%s', values: $username ),
                        'avatar'  => sprintf( format: 'https://wordpress.org/grav-redirect.php?user=%s&s=36', values: $username ),
                    ];

                    return $carry;
                },
                initial: []
            );
        } else {
            $contributors = [];
        }

        $info->contributors     = $contributors;


        $info->homepage         = $remote->homepage ?? '';
        $info->download_link    = $remote->download_url ?? '';
        $info->requires         = $remote->requires ?? '6.8';
        $info->tested           = $remote->tested ?? '6.9';
        $info->requires_php     = $remote->requires_php ?? '8.3';
        $info->license          = $remote->license ?? 'GPL-2.0+';
        $info->license_uri      = $remote->license_uri ?? 'http://www.gnu.org/licenses/gpl-2.0.txt';
        
        $tags = $remote->tags ?? [];
        if ( ! is_array( value: $tags ) ) {
            $tags = [$tags];
        }
        $info->tags             = array_map( callback: 'trim', array: $tags );

        $info->network          = $remote->network ?? false;
        $info->requires_plugins = $remote->requires_plugins ?? [];
        $info->text_domain      = $remote->text_domain ?? '';
        $info->domain_path      = $remote->domain_path ?? '';
        $info->last_updated     = $remote->last_updated ?? '';
        $info->sections         = $sections;
        $info->banners          = (array)($remote->banners ?? []);

        return $info;
    }

    /**
     * Check for available plugin updates.
     *
     * @param object $transient WordPress transient data.
     * @return object
     */
    public function check_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_manifest();
        if ( ! $remote || empty( $remote->version ) ) {
            return $transient;
        }

        if ( version_compare( version1: $this->current_version, version2: $remote->version, operator: '<' ) ) {
            $plugin_basename = plugin_basename( $this->plugin_file );

            $update              = new \stdClass();
            $update->slug        = $this->plugin_slug;
            $update->new_version = $remote->version;
            $update->package     = $remote->download_url ?? '';
            $update->tested      = $remote->tested ?? '';
            $update->requires_php = $remote->requires_php ?? '';
            $update->plugin      = $plugin_basename;

            $transient->response[$plugin_basename] = $update;
        }

        return $transient;
    }

    /**
     * Clear cached manifest after a successful update.
     *
     * @param \WP_Upgrader $upgrader WordPress upgrader instance.
     * @param array        $options  Upgrade options.
     */
    public function clear_cache( \WP_Upgrader $upgrader, array $options ): void {
        if ( $this->cache_enabled && $options['action'] === 'update' && $options['type'] === 'plugin' ) {
            delete_transient( $this->cache_key );
        }
    }
}
