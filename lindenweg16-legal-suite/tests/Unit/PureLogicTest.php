<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Tests\Unit;

use Lindenweg16\Legal\Checkout\Selection;
use Lindenweg16\Legal\Consent\Module as Consent;
use Lindenweg16\Legal\Consent\Rewriter;
use Lindenweg16\Legal\Core\ServiceRegistry;
use Lindenweg16\Legal\Forms\Retention;
use Lindenweg16\Legal\Legal\Linter;
use Lindenweg16\Legal\Legal\Template;
use Lindenweg16\Legal\Monitor\Contacts;
use Lindenweg16\Legal\Product\Gpsr;
use Lindenweg16\Legal\Security\Headers;
use Lindenweg16\Legal\Withdrawal\Token;
use PHPUnit\Framework\TestCase;

final class PureLogicTest extends TestCase {

	private function rules(): array {
		return [
			[ 'id' => 'jp', 'patterns' => [ 'stats.wp.com' ], 'mode' => 'remove' ],
			[ 'id' => 'maps', 'patterns' => [ 'google.com/maps' ], 'mode' => 'gate', 'label' => 'Google Maps' ],
			[ 'id' => 'att', 'patterns' => [ 'sourcebuster' ], 'mode' => 'gate' ],
		];
	}

	public function test_rewriter_removes_and_gates_scripts(): void {
		$html = '<script src="https://stats.wp.com/e.js"></script><script>var sourcebuster=1;</script><script src="/ok.js"></script>';
		$out  = Rewriter::rewrite( $html, $this->rules() );
		$this->assertStringNotContainsString( 'stats.wp.com', $out );
		$this->assertStringContainsString( 'type="text/plain" data-ll16-service="att"', $out );
		$this->assertStringContainsString( '<script src="/ok.js"></script>', $out );
	}

	public function test_rewriter_turns_iframe_into_two_click_placeholder(): void {
		$out = Rewriter::rewrite( '<iframe src="https://www.google.com/maps/embed?x=1" width="600"></iframe>', $this->rules() );
		$this->assertStringNotContainsString( ' src="https://www.google.com', $out );
		$this->assertStringContainsString( 'data-ll16-load="maps"', $out );
		$this->assertStringContainsString( 'data-ll16-src="https://www.google.com/maps/embed?x=1"', $out );
	}

	public function test_rewriter_keeps_unrelated_and_skips_marked_scripts(): void {
		$html = '<script data-ll16-skip src="https://stats.wp.com/x.js"></script><script type="application/ld+json">{"a":"stats.wp.com"}</script>';
		$this->assertSame( $html, Rewriter::rewrite( $html, $this->rules() ) );
	}

	public function test_template_renders_and_escapes(): void {
		$this->assertSame( 'Hallo &lt;b&gt;', Template::render( 'Hallo {{x}}', [ 'x' => '<b>' ] ) );
		$this->assertSame( '<b>', Template::render( '{{html_x}}', [ 'html_x' => '<b>' ] ) );
		$this->assertSame( 'ja', Template::render( '{{#if a}}ja{{/if}}{{#if !a}}nein{{/if}}', [ 'a' => true ] ) );
		$this->assertSame( 'nein', Template::render( '{{#if a}}ja{{/if}}{{#if !a}}nein{{/if}}', [ 'a' => '' ] ) );
	}

	public function test_unresolved_placeholder_is_caught_by_linter(): void {
		$out = Template::render( 'Tel {{phone}}', [] );
		$this->assertNotEmpty( Linter::check( $out ) );
		$this->assertEmpty( Linter::check( '<p>Sauberer Text.</p>' ) );
		$this->assertNotEmpty( Linter::check( 'Verwende die Testkarte 4000' ) );
		$this->assertNotEmpty( Linter::check( 'sven@ex.comlindenweg16.de' ) );
	}

	public function test_token_roundtrip_and_tamper(): void {
		$t = Token::sign( [ 'name' => 'A' ], 1000, 'secret' );
		$v = Token::verify( $t, 'secret' );
		$this->assertSame( 'A', $v['p']['name'] );
		$this->assertNull( Token::verify( $t, 'other' ) );
		$this->assertNull( Token::verify( 'x.' . explode( '.', $t )[1], 'secret' ) );
		$this->assertFalse( Token::fresh( 1000, 1001 ) );
		$this->assertTrue( Token::fresh( 1000, 1010 ) );
		$this->assertFalse( Token::fresh( 1000, 1000 + 4000 ) );
	}

	public function test_contacts_detect_deviations(): void {
		$m    = [ 'email' => 'sven@lindenweg16.de', 'phone' => '01567373047' ];
		$html = '<p>Tel 0156 7373047, sven@lindenweg16.de</p><script>var a="x@y.de"</script>';
		$this->assertSame( [], Contacts::deviations( $html, $m ) );
		$bad = Contacts::deviations( '<p>Tel +49 5037 12345, sven@ex.comlindenweg16.de</p>', $m );
		$this->assertCount( 2, $bad );
		$this->assertSame( '01567373047', Contacts::normalize_phone( '+49 1567 373047' ) );
	}

	public function test_phone_is_not_merged_with_following_heading_number(): void {
		$m = [ 'email' => 'a@b.de', 'phone' => '01567373047' ];
		$this->assertSame( [], Contacts::deviations( '<p>Telefon: 01567373047</p><h2>2. Deine Rechte</h2>', $m ) );
	}

	public function test_dates_are_not_phone_numbers(): void {
		$m = [ 'email' => 'a@b.de', 'phone' => '01567373047' ];
		$this->assertSame( [], Contacts::deviations( '<p>Stand: 04.11.2026 und 01.02.26, Tel 01567373047</p>', $m ) );
	}

	public function test_gpsr_and_claims(): void {
		$this->assertCount( 4, Gpsr::missing( [] ) );
		$this->assertSame( [], Gpsr::missing( array_fill_keys( array_keys( Gpsr::REQUIRED ), 'x' ) ) );
		$this->assertSame( [ 'handgemacht' ], Gpsr::claims( 'Unsere HANDGEMACHTEN Haken', [ 'handgemacht', 'bester' ] ) );
	}

	public function test_required_selection(): void {
		$attrs = [ 'pa_farbe' => [ 'is_variation' => true, 'options' => [ 'anthrazit', 'weiss' ] ], 'holz' => [ 'is_variation' => true, 'options' => [ 'Fichte', 'Ahorn' ] ], 'x' => [ 'is_variation' => false, 'options' => [ 'a', 'b' ] ] ];
		$this->assertSame( [ 'pa_farbe', 'holz' ], Selection::missing( $attrs, [] ) );
		$this->assertSame( [ 'holz' ], Selection::missing( $attrs, [ 'attribute_pa_farbe' => 'weiss' ] ) );
		$this->assertSame( [], Selection::missing( $attrs, [ 'attribute_pa_farbe' => 'weiss', 'attribute_holz' => 'Fichte' ] ) );
	}

	public function test_security_headers(): void {
		$h = Headers::build( [ 'hsts_max_age' => 604800 ], true );
		$this->assertSame( 'max-age=604800', $h['Strict-Transport-Security'] );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $h );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', Headers::build( [], false ) );
	}

	public function test_retention_end_of_year_logic(): void {
		$order = (int) strtotime( '2020-03-01 UTC' );
		$this->assertFalse( Retention::expired( $order, 8, (int) strtotime( '2028-12-31 UTC' ) ) );
		$this->assertTrue( Retention::expired( $order, 8, (int) strtotime( '2029-01-02 UTC' ) ) );
	}

	public function test_consent_rules_by_service_state(): void {
		$s = [
			'stripe' => [ 'name' => 'Stripe', 'category' => 'necessary', 'context' => 'checkout', 'active' => true, 'patterns' => [ 'js.stripe.com' ] ],
			'maps'   => [ 'name' => 'Maps', 'category' => 'preferences', 'context' => 'always', 'active' => true, 'patterns' => [ 'google.com/maps' ] ],
			'jp'     => [ 'name' => 'JP', 'category' => 'statistics', 'context' => 'always', 'active' => false, 'patterns' => [ 'stats.wp.com' ] ],
		];
		$shop = Consent::rules( $s, false );
		$byid = array_column( $shop, 'mode', 'id' );
		$this->assertSame( 'remove', $byid['stripe'] );   // außerhalb Warenkorb/Kasse nicht laden
		$this->assertSame( 'gate', $byid['maps'] );
		$this->assertSame( 'remove', $byid['jp'] );
		$pay = array_column( Consent::rules( $s, true ), 'mode', 'id' );
		$this->assertArrayNotHasKey( 'stripe', $pay );     // an der Kasse erlaubt
	}

	public function test_registry_version_changes_only_for_optional_services(): void {
		$a = [ 'n' => [ 'category' => 'necessary' ] ];
		$b = $a + [ 'm' => [ 'category' => 'preferences' ] ];
		$this->assertSame( ServiceRegistry::version( $a ), ServiceRegistry::version( $a + [ 'x' => [ 'category' => 'necessary' ] ] ) );
		$this->assertNotSame( ServiceRegistry::version( $a ), ServiceRegistry::version( $b ) );
		$this->assertSame( [ 'necessary', 'preferences' ], ServiceRegistry::categories_in_use( $b ) );
	}

	public function test_agb_names_the_checkout_button(): void {
		$agb = (string) file_get_contents( __DIR__ . '/../../templates/agb.html' );
		$this->assertStringContainsString( \Lindenweg16\Legal\Checkout\Module::BUTTON, $agb );
	}
}
