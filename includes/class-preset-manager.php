<?php
/**
 * Preset Manager
 *
 * Centralised CRUD for named review filter presets stored in wp_options as a
 * JSON-encoded array under the 'tp_presets' key.
 *
 * All methods are static — this class is stateless and loaded before the
 * is_admin() block in the bootstrap so both the shortcode renderer and the
 * admin UI can share the same preset-access logic without duplication.
 *
 * Storage format (D-10): wp_options key 'tp_presets' holds a JSON string that
 * decodes to an indexed array of preset objects:
 *
 *   [
 *     { "slug": "my-preset", "keywords": "great, fast", "min_stars": 4, "limit": 10 },
 *     ...
 *   ]
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Preset_Manager {

    /**
     * Return all presets as an indexed array.
     *
     * Reads wp_options, JSON-decodes if stored as string, and always returns
     * a plain array (never false or null).
     *
     * @return array<int, array<string, mixed>> Indexed list of preset arrays.
     */
    public static function get_all(): array {
        $presets = get_option( 'tp_presets', [] );
        if ( is_string( $presets ) ) {
            $presets = json_decode( $presets, true ) ?? [];
        }
        if ( ! is_array( $presets ) ) {
            $presets = [];
        }
        return $presets;
    }

    /**
     * Return a single preset by slug, or null if not found.
     *
     * @param string $slug Sanitized preset slug.
     * @return array<string, mixed>|null Preset array or null.
     */
    public static function get_by_slug( string $slug ): ?array {
        foreach ( self::get_all() as $preset ) {
            if ( isset( $preset['slug'] ) && $preset['slug'] === $slug ) {
                return $preset;
            }
        }
        return null;
    }

    /**
     * Persist an updated presets array to wp_options.
     *
     * JSON-encodes the array and stores it. Returns false if encoding fails
     * (e.g. non-UTF-8 bytes in keyword values) so callers can handle the error
     * instead of silently storing a corrupted value.
     *
     * @param array<int, array<string, mixed>> $presets Indexed presets array.
     * @return bool True on successful write, false if encoding failed.
     */
    public static function save( array $presets ): bool {
        $encoded = wp_json_encode( $presets );
        if ( false === $encoded ) {
            return false;
        }
        update_option( 'tp_presets', $encoded );
        return true;
    }

    /**
     * Delete a preset by slug and persist the result.
     *
     * No-op (returns true) when the slug does not exist — idempotent delete.
     * Returns false only if JSON encoding of the remaining presets fails.
     *
     * @param string $slug Sanitized preset slug to remove.
     * @return bool True on successful write or slug not found, false on encode error.
     */
    public static function delete( string $slug ): bool {
        $presets = self::get_all();
        $presets = array_values( array_filter( $presets, static function( $preset ) use ( $slug ) {
            return $preset['slug'] !== $slug;
        } ) );
        return self::save( $presets );
    }
}
