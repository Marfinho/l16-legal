<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Monitor;

use Lindenweg16\Legal\Core\Masterdata;

/**
 * M9 – Compliance-Monitor: Dashboard, WP-CLI, täglicher Cron mit E-Mail bei Fehlern.
 */
final class Module {

	public static function init(): void {
		add_action( 'll16_daily', [ self::class, 'daily' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'll16-legal check', [ self::class, 'cli' ] );
		}
	}

	/** @return array<int,array{id:string,label:string,status:string,detail:string}> */
	public static function run(): array {
		$results = ( new Checks() )->run_all();
		update_option( 'll16_last_check', [ 'time' => gmdate( 'c' ), 'results' => $results ], false );
		return $results;
	}

	/**
	 * @param array<int,array{status:string}> $results
	 */
	public static function summary( array $results ): array {
		$c = [ 'ok' => 0, 'warn' => 0, 'fail' => 0 ];
		foreach ( $results as $r ) {
			++$c[ $r['status'] ];
		}
		return $c;
	}

	public static function daily(): void {
		$results = self::run();
		$fails   = array_filter( $results, static fn( $r ) => 'fail' === $r['status'] );
		if ( [] === $fails ) {
			return;
		}
		$lines = array_map( static fn( $r ) => '✗ ' . $r['label'] . ( $r['detail'] ? ': ' . $r['detail'] : '' ), $fails );
		wp_mail( (string) Masterdata::get( 'email' ), '[Lindenweg16] Compliance-Monitor: ' . count( $fails ) . ' Fehler', implode( "\n\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=ll16-legal&tab=monitor' ) );
	}

	/**
	 * Führt alle Prüfungen aus. Exit-Code 1 bei Fehlern.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (Standard) oder json.
	 *
	 * @param string[]            $args
	 * @param array<string,string> $assoc
	 */
	public static function cli( array $args, array $assoc ): void {
		$results = self::run();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			foreach ( $results as $r ) {
				\WP_CLI::line( sprintf( '%-5s %s%s', strtoupper( $r['status'] ), $r['label'], $r['detail'] ? ' – ' . $r['detail'] : '' ) );
			}
		}
		$s = self::summary( $results );
		if ( $s['fail'] > 0 ) {
			\WP_CLI::error( sprintf( '%d Fehler, %d Warnungen.', $s['fail'], $s['warn'] ) );
		}
		\WP_CLI::success( sprintf( 'Keine Fehler (%d Warnungen).', $s['warn'] ) );
	}
}
