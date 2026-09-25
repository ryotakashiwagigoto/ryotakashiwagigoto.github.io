<?php
/**
 * Re-renders the researchmap blocks inside the exported static site.
 *
 * The WordPress theme wraps each [researchmap] block in
 *   <!--rm:type=…;lang=…;heading=…--> … <!--/rm-->
 * This script fetches fresh data from researchmap with the very same
 * rendering code (scripts/researchmap.php, a copy of the theme's
 * inc/researchmap.php) and swaps the blocks in place.
 *
 * If any request to researchmap fails, nothing is changed and the
 * exported snapshot is published as is.
 *
 * Usage: php scripts/refresh-researchmap.php site
 */

// ---- Minimal stand-ins for the WordPress functions the renderer uses ----
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['rm_cache']  = array();
$GLOBALS['rm_errors'] = array();

function get_transient( $k ) { return $GLOBALS['rm_cache'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['rm_cache'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['rm_cache'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) {
	$s = (string) $s;
	return preg_match( '#^(https?:|mailto:|/)#i', $s ) ? esc_html( $s ) : '';
}
function is_wp_error( $x ) { return is_array( $x ) && isset( $x['error'] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function shortcode_atts( $defaults, $atts, $tag = '' ) {
	$out = $defaults;
	foreach ( (array) $atts as $k => $v ) {
		if ( array_key_exists( $k, $defaults ) ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}
function add_shortcode() {}
function add_action() {}
function current_user_can() { return false; }

function wp_remote_get( $url, $args = array() ) {
	// Test hook: read responses from local JSON files instead of the network.
	$fixtures = getenv( 'RM_FIXTURES' );
	if ( $fixtures ) {
		preg_match( '#/([a-z_]+)\?#', $url, $m );
		$file = rtrim( $fixtures, '/' ) . '/' . $m[1] . '.json';
		if ( ! is_file( $file ) ) {
			$GLOBALS['rm_errors'][] = $url;
			return array( 'error' => 'missing fixture' );
		}
		return array( 'code' => 200, 'body' => file_get_contents( $file ) );
	}

	$ctx  = stream_context_create(
		array(
			'http' => array(
				'timeout'       => $args['timeout'] ?? 15,
				'ignore_errors' => true,
				'header'        => "Accept: application/json\r\nUser-Agent: ryota-goto-site-builder\r\n",
			),
		)
	);
	$body = @file_get_contents( $url, false, $ctx );
	$code = 0;
	foreach ( $http_response_header ?? array() as $h ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) {
			$code = (int) $m[1];
		}
	}
	if ( false === $body || 200 !== $code ) {
		$GLOBALS['rm_errors'][] = $url . ' (HTTP ' . $code . ')';
		return array( 'error' => 'request failed' );
	}
	return array( 'code' => $code, 'body' => $body );
}

require __DIR__ . '/researchmap.php';

// ---- Find exported pages that contain researchmap blocks ----
$root = $argv[1] ?? 'site';
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "No such directory: $root\n" );
	exit( 1 );
}

$pattern = '#<!--rm:type=([a-z_,]+);lang=(ja|en);heading=(yes|no)-->.*?<!--/rm-->#s';
$pages   = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'html' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$html = file_get_contents( $file->getPathname() );
	if ( preg_match( $pattern, $html ) ) {
		$pages[ $file->getPathname() ] = $html;
	}
}
if ( ! $pages ) {
	echo "No researchmap blocks found; nothing to refresh.\n";
	exit( 0 );
}

// ---- Render everything first; only write if every request succeeded ----
$updated = array();
foreach ( $pages as $path => $html ) {
	$updated[ $path ] = preg_replace_callback(
		$pattern,
		static function ( $m ) {
			return goto_rm_shortcode(
				array(
					'type'    => $m[1],
					'lang'    => $m[2],
					'heading' => $m[3],
				)
			);
		},
		$html
	);
}

if ( $GLOBALS['rm_errors'] ) {
	echo "::warning::researchmap could not be reached; publishing the exported snapshot unchanged.\n";
	foreach ( array_unique( $GLOBALS['rm_errors'] ) as $e ) {
		echo "  - $e\n";
	}
	exit( 0 );
}

foreach ( $updated as $path => $html ) {
	file_put_contents( $path, $html );
	echo "Refreshed: $path\n";
}
