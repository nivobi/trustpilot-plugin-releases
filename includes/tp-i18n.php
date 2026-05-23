<?php
/**
 * Language helpers.
 *
 * Two-language switcher driven by the `tp_language` wp_option:
 *   - tp_lang()     → 'da' (default) or 'en'
 *   - tp_t($en,$da) → returns the appropriate string
 *   - tp_number()   → integer formatted per language
 *   - tp_decimal()  → float formatted per language
 *   - tp_time_ago() → wraps human_time_diff() with Danish unit substitution
 *
 * Loaded first by the bootstrap so any class file may call these helpers
 * at top level if needed.
 *
 * @package TrustpilotReviews
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'tp_lang' ) ) {
    function tp_lang(): string {
        $lang = get_option( 'tp_language', 'da' );
        return $lang === 'en' ? 'en' : 'da';
    }
}

if ( ! function_exists( 'tp_t' ) ) {
    function tp_t( string $en, string $da ): string {
        return tp_lang() === 'en' ? $en : $da;
    }
}

if ( ! function_exists( 'tp_number' ) ) {
    function tp_number( int $n ): string {
        return tp_lang() === 'en'
            ? number_format( $n, 0, '.', ',' )
            : number_format( $n, 0, ',', '.' );
    }
}

if ( ! function_exists( 'tp_decimal' ) ) {
    function tp_decimal( float $f, int $places = 1 ): string {
        return tp_lang() === 'en'
            ? number_format( $f, $places, '.', ',' )
            : number_format( $f, $places, ',', '.' );
    }
}

if ( ! function_exists( 'tp_time_ago' ) ) {
    function tp_time_ago( int $timestamp ): string {
        $diff = human_time_diff( $timestamp, current_time( 'timestamp' ) );
        if ( tp_lang() === 'da' ) {
            $diff = strtr( $diff, [
                'seconds' => 'sekunder', 'second' => 'sekund',
                'minutes' => 'minutter', 'minute' => 'minut',
                'hours'   => 'timer',    'hour'   => 'time',
                'days'    => 'dage',     'day'    => 'dag',
                'weeks'   => 'uger',     'week'   => 'uge',
                'months'  => 'måneder',  'month'  => 'måned',
                'years'   => 'år',       'year'   => 'år',
            ] );
        }
        return $diff;
    }
}
