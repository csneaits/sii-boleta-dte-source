<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared */

/**
 * Persistent queue storage used for pending DTE or RVD jobs.
 *
 * When a WordPress database is available, jobs are stored in the custom table
 * `sii_boleta_dte_queue`. During unit tests or environments without a database
 * connection an in-memory fallback is used instead so behaviour remains
 * deterministic.
 */
class QueueDb {
	public const TABLE = 'sii_boleta_dte_queue';

	/** @var array<int,array{ id:int,type:string,payload:array<string,mixed>,attempts:int,created_at:string }> */
	private static array $jobs      = array();
	private static int $auto_inc    = 1;
	private static bool $use_memory = true;

	/** Returns full table name with WP prefix. */
	private static function table(): string {
		global $wpdb;
		$prefix = is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ? $wpdb->prefix : 'wp_';
		return $prefix . self::TABLE;
	}

	/** Creates the queue table or resets in-memory store. */
	public static function install(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			self::$jobs       = array();
			self::$auto_inc   = 1;
			self::$use_memory = true;
			return;
		}
		$table           = self::table();
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(20) NOT NULL,
payload longtext NOT NULL,
attempts smallint unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY type (type)
) {$charset_collate};";
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		} elseif ( method_exists( $wpdb, 'query' ) ) {
			$wpdb->query( $sql );
		}
	}

	/**
	 * Enqueues a new job in the queue.
	 *
	 * @param string               $type    Job type.
	 * @param array<string,mixed>  $payload Arbitrary job data.
	 */
	public static function enqueue( string $type, array $payload ): int {
		global $wpdb;
		$created = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
			$json   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			$result = $wpdb->insert(
				self::table(),
				array(
					'type'       => $type,
					'payload'    => $json,
					'attempts'   => 0,
					'created_at' => $created,
				)
			);
			if ( false !== $result ) {
				self::$use_memory = false;
				return (int) $wpdb->insert_id;
			}
		}
		self::$use_memory  = true;
		$id                = self::$auto_inc++;
		self::$jobs[ $id ] = array(
			'id'         => $id,
			'type'       => $type,
			'payload'    => $payload,
			'attempts'   => 0,
			'created_at' => $created,
		);
		return $id;
	}

	/**
	 * Retrieves pending jobs.
	 *
	 * @return array<int,array{ id:int,type:string,payload:array<string,mixed>,attempts:int }>
	 */
	public static function get_pending_jobs( int $limit = 20 ): array {
		global $wpdb;
		if ( ! self::$use_memory && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table = self::table();
			$sql   = $wpdb->prepare( "SELECT id,type,payload,attempts FROM {$table} ORDER BY id ASC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $sql, 'ARRAY_A' );
			if ( is_array( $rows ) ) {
				$jobs = array();
				foreach ( $rows as $row ) {
					$payload = json_decode( (string) $row['payload'], true );
					$jobs[]  = array(
						'id'       => (int) $row['id'],
						'type'     => (string) $row['type'],
						'payload'  => is_array( $payload ) ? $payload : array(),
						'attempts' => (int) $row['attempts'],
					);
				}
				return $jobs;
			}
		}
		return array_values( self::$jobs );
	}

		/** Increments the attempts counter for a job. */
	public static function increment_attempts( int $id ): void {
			global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET attempts = attempts + 1 WHERE id = %d', $id ) );
				return;
		}
		if ( isset( self::$jobs[ $id ] ) ) {
				++self::$jobs[ $id ]['attempts'];
		}
	}

		/** Resets the attempts counter for a job. */
	public static function reset_attempts( int $id ): void {
			global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET attempts = 0 WHERE id = %d', $id ) );
				return;
		}
		if ( isset( self::$jobs[ $id ] ) ) {
				self::$jobs[ $id ]['attempts'] = 0;
		}
	}

	/** Deletes a job from the queue. */
	public static function delete( int $id ): void {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			return;
		}
		unset( self::$jobs[ $id ] );
	}

	/** Clears all jobs (used in tests). */
	public static function purge(): void {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
			return;
		}
		self::$jobs     = array();
		self::$auto_inc = 1;
	}
}

/* phpcs:enable */
class_alias( QueueDb::class, 'SII_Boleta_Queue_DB' );
