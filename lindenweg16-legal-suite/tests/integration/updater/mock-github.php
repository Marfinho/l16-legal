<?php
// Mock der GitHub-API für Update-Tests (php -S 127.0.0.1:8201 updater/mock-github.php, MOCK_DIR gesetzt).
$dir  = getenv( 'MOCK_DIR' );
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
file_put_contents( $dir . '/requests.log', json_encode( [ 'path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'accept' => $_SERVER['HTTP_ACCEPT'] ?? '' ] ) . "\n", FILE_APPEND );
if ( '/repos/Marfinho/l16-legal/releases/latest' === $path ) {
	$f = $dir . '/release.json';
	if ( ! is_file( $f ) ) { http_response_code( 404 ); echo '{"message":"Not Found"}'; return; }
	header( 'Content-Type: application/json' );
	readfile( $f );
	return;
}
if ( preg_match( '#^/(?:dl|repos/Marfinho/l16-legal/releases/assets)/([^/]+)$#', $path, $m ) ) {
	$f = $dir . '/files/' . basename( $m[1] );
	if ( is_file( $f ) ) { header( 'Content-Type: application/octet-stream' ); readfile( $f ); return; }
}
http_response_code( 404 );
