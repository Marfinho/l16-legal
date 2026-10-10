<?php
// Nur Testumgebung: Mails abfangen statt senden.
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	if ( get_option( 'll16_test_mail_fail' ) ) {
		return false;
	}
	file_put_contents( WP_CONTENT_DIR . '/mail.log', wp_json_encode( [ 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'], 'attachments' => $atts['attachments'] ] ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
// HTTPS simulieren, wenn der Test es anfordert.
if ( ! empty( $_SERVER['HTTP_X_TEST_HTTPS'] ) ) {
	$_SERVER['HTTPS'] = 'on';
}
// Simuliert Fremdplugins, die ihre Skripte auf JEDER Seite ausgeben (Stripe, PayPal, reCAPTCHA, Jetpack-Stats).
if ( get_option( 'll16_test_inject_third_party' ) ) {
	add_action( 'wp_footer', function () {
		echo '<script src="https://js.stripe.com/v3/"></script><script src="https://www.paypal.com/sdk/js?client-id=test"></script><script src="https://www.google.com/recaptcha/api.js?render=x"></script><script src="https://stats.wp.com/e-test.js"></script>';
	}, 1 );
	add_action( 'wp_enqueue_scripts', function () {
		wp_enqueue_script( 'fake-stripe', 'https://js.stripe.com/v3/?enq=1', [], null, true );
		wp_enqueue_script( 'fake-recaptcha', 'https://www.google.com/recaptcha/api.js?enq=1', [], null, true );
	} );
}
