<?php
/**
 * Mini-Testframework für Integrationstests (läuft via `wp eval-file` im echten WordPress).
 */
$GLOBALS['ll16t'] = [ 'pass' => 0, 'fail' => 0, 'section' => '' ];

function section( string $name ): void {
	$GLOBALS['ll16t']['section'] = $name;
	echo "\n== {$name} ==\n";
}

function t( string $name, $cond, string $detail = '' ): void {
	if ( $cond ) {
		++$GLOBALS['ll16t']['pass'];
		echo "  ok    {$name}\n";
	} else {
		++$GLOBALS['ll16t']['fail'];
		echo "  FAIL  {$name}" . ( $detail ? "  -> {$detail}" : '' ) . "\n";
	}
}

function finish(): void {
	$g = $GLOBALS['ll16t'];
	echo "\n{$g['pass']} bestanden, {$g['fail']} fehlgeschlagen\n";
	exit( $g['fail'] ? 1 : 0 );
}

/** HTTP gegen die eigene Instanz, mit Cookie-Jar, ohne Redirects. */
function http( string $method, string $path, $body = [], array &$jar = [], array $headers = [] ): array {
	$cookies = [];
	foreach ( $jar as $k => $v ) {
		$cookies[] = new WP_Http_Cookie( [ 'name' => $k, 'value' => $v ] );
	}
	$res = wp_remote_request(
		home_url( $path ),
		[ 'method' => $method, 'timeout' => 40, 'redirection' => 0, 'body' => $body, 'cookies' => $cookies, 'headers' => $headers, 'sslverify' => false ]
	);
	if ( is_wp_error( $res ) ) {
		return [ 'code' => 0, 'body' => $res->get_error_message(), 'headers' => [], 'cookies' => [] ];
	}
	foreach ( wp_remote_retrieve_cookies( $res ) as $c ) {
		$jar[ $c->name ] = $c->value;
	}
	return [
		'code'    => (int) wp_remote_retrieve_response_code( $res ),
		'body'    => (string) wp_remote_retrieve_body( $res ),
		'headers' => wp_remote_retrieve_headers( $res ),
		'cookies' => wp_remote_retrieve_cookies( $res ),
	];
}

function hidden( string $html, string $name ): string {
	return preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
}

function mails(): array {
	$f = WP_CONTENT_DIR . '/mail.log';
	if ( ! is_file( $f ) ) {
		return [];
	}
	return array_map( fn( $l ) => json_decode( $l, true ), array_filter( explode( "\n", (string) file_get_contents( $f ) ) ) );
}

function clear_mails(): void {
	@unlink( WP_CONTENT_DIR . '/mail.log' );
}

function make_page( string $slug, string $title, string $content ): int {
	kses_remove_filters();
	$existing = get_page_by_path( $slug );
	$args     = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ];
	if ( $existing ) {
		$args['ID'] = $existing->ID;
	}
	$id = wp_insert_post( $args );
	return (int) $id;
}

/** Rate-Limit- und Wiederholungsschutz-Transients zurücksetzen (Tests sind sonst nicht wiederholbar). */
function reset_ratelimits(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ll16\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ll16\\_rl\\_%' OR option_name LIKE '\\_transient\\_ll16\\_wd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ll16\\_wd\\_%'" );
	wp_cache_flush();
}
