<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Gemeinsame Frontend-Assets (Formular-CSS, Footer-Links).
 */
final class Assets {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_forms' ] );
	}

	/** Formular-CSS nur laden, wenn die Seite ein Shortcode des Plugins enthält (oder immer bei Footer-Links). */
	public static function enqueue_forms(): void {
		$post = get_post();
		if ( $post instanceof \WP_Post && str_contains( (string) $post->post_content, '[ll16_' ) ) {
			wp_enqueue_style( 'll16-forms', LL16_URL . 'assets/forms.css', [], LL16_VERSION );
		}
	}

	public static function force_forms(): void {
		wp_enqueue_style( 'll16-forms', LL16_URL . 'assets/forms.css', [], LL16_VERSION );
	}
}
