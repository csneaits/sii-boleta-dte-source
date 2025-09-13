<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

/**
 * Minimal in-memory log storage used for tests.
 */
class LogDb {
	/** @var array<int, array{track_id:string,status:string,response:string}> */
	private static array $entries = array();

	public static function add_entry( string $track_id, string $status, string $response ): void {
		self::$entries[] = array(
			'track_id' => $track_id,
			'status'   => $status,
			'response' => $response,
		);
	}

	/**
	 * Returns pending track IDs with status 'sent'.
	 *
	 * @return array<int,string>
	 */
	public static function get_pending_track_ids( int $limit = 50 ): array {
		$ids = array();
		foreach ( array_reverse( self::$entries ) as $entry ) {
			if ( 'sent' === $entry['status'] ) {
				$ids[] = $entry['track_id'];
			}
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
		return $ids;
	}

	public static function install(): void {}
}

class_alias( LogDb::class, 'SII_Boleta_Log_DB' );
