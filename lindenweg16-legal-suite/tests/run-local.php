<?php
/**
 * Minimaler Test-Runner ohne PHPUnit/Composer (nur für Umgebungen ohne `composer install`).
 * Aufruf: php tests/run-local.php
 * In CI läuft PHPUnit; dieser Runner führt dieselben Testklassen aus.
 */
namespace PHPUnit\Framework {
	class AssertionFailed extends \Exception {}
	abstract class TestCase {
		public function assertSame( $e, $a, string $m = '' ): void { if ( $e !== $a ) { throw new AssertionFailed( $m . ' erwartet ' . var_export( $e, true ) . ' bekam ' . var_export( $a, true ) ); } }
		public function assertTrue( $a ): void { $this->assertSame( true, $a ); }
		public function assertFalse( $a ): void { $this->assertSame( false, $a ); }
		public function assertNull( $a ): void { $this->assertSame( null, $a ); }
		public function assertEmpty( $a ): void { if ( ! empty( $a ) ) { throw new AssertionFailed( 'nicht leer: ' . var_export( $a, true ) ); } }
		public function assertNotEmpty( $a ): void { if ( empty( $a ) ) { throw new AssertionFailed( 'leer' ); } }
		public function assertNotSame( $e, $a ): void { if ( $e === $a ) { throw new AssertionFailed( 'gleich: ' . var_export( $a, true ) ); } }
		public function assertCount( int $n, $a ): void { $this->assertSame( $n, count( $a ) ); }
		public function assertStringContainsString( string $n, string $h ): void { if ( false === strpos( $h, $n ) ) { throw new AssertionFailed( "'$n' nicht in '$h'" ); } }
		public function assertStringNotContainsString( string $n, string $h ): void { if ( false !== strpos( $h, $n ) ) { throw new AssertionFailed( "'$n' unerwartet in '$h'" ); } }
		public function assertArrayHasKey( $k, array $a ): void { if ( ! array_key_exists( $k, $a ) ) { throw new AssertionFailed( "Schlüssel $k fehlt" ); } }
		public function assertArrayNotHasKey( $k, array $a ): void { if ( array_key_exists( $k, $a ) ) { throw new AssertionFailed( "Schlüssel $k unerwartet" ); } }
	}
}
namespace {
	// WordPress-Funktionen, die die getesteten reinen Klassen berühren.
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }

	spl_autoload_register( function ( $c ) {
		$p = 'Lindenweg16\\Legal\\';
		if ( 0 === strpos( $c, $p ) ) {
			$f = __DIR__ . '/../src/' . str_replace( '\\', '/', substr( $c, strlen( $p ) ) ) . '.php';
			if ( is_file( $f ) ) { require_once $f; }
		}
	} );
	define( 'ABSPATH', '/' );
	$fail = 0; $pass = 0;
	foreach ( glob( __DIR__ . '/Unit/*Test.php' ) as $file ) {
		require_once $file;
		$class = 'Lindenweg16\\Legal\\Tests\\Unit\\' . basename( $file, '.php' );
		foreach ( get_class_methods( $class ) as $m ) {
			if ( 0 !== strpos( $m, 'test_' ) ) { continue; }
			try { ( new $class() )->$m(); ++$pass; echo "ok   $m\n"; }
			catch ( \Throwable $e ) { ++$fail; echo "FAIL $m: " . $e->getMessage() . "\n"; }
		}
	}
	echo "\n$pass bestanden, $fail fehlgeschlagen\n";
	exit( $fail ? 1 : 0 );
}
