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

        /** @var array<int,array{ id:int,type:string,payload:array<string,mixed>,attempts:int,available_at:string }> */
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
		self::$use_memory = false;
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
		'id'           => $id,
		'type'         => $type,
		'payload'      => $payload,
		'attempts'     => 0,
		'created_at'   => $created,
		'available_at' => $created,
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
                $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        self::$use_memory = false;
                        $table = self::table();
                        $sql   = $wpdb->prepare( "SELECT id,type,payload,attempts,created_at FROM {$table} WHERE created_at <= %s ORDER BY created_at ASC, id ASC LIMIT %d", $now, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
                                                'created_at'   => isset( $row['created_at'] ) ? (string) $row['created_at'] : $now,
                                                'available_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : $now,
                                        );
                                }
                                return $jobs;
                        }
                }
                $jobs = array_filter(
                        self::$jobs,
                        static function ( array $job ) use ( $now ) {
                                $available = $job['available_at'] ?? $job['created_at'] ?? $now;
                                return $available <= $now;
                        }
                );

                usort(
                        $jobs,
                        static function ( array $a, array $b ): int {
                                if ( $a['available_at'] === $b['available_at'] ) {
                                        return $a['id'] <=> $b['id'];
                                }

                                return strcmp( $a['available_at'], $b['available_at'] );
                        }
                );

                return array_values( $jobs );
        }

        /**
         * Returns all jobs (pending and failed) from the queue.
         * 
         * @param int $limit Maximum number of jobs to return.
         * @return array<int,array{ id:int,type:string,payload:array<string,mixed>,attempts:int,available_at:string,created_at:string }>
         */
        public static function get_all_jobs( int $limit = 50 ): array {
                global $wpdb;

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_results' ) ) {
                        self::$use_memory = false;
                        $table            = self::table();
                        $sql              = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
                        $rows             = (array) $wpdb->get_results( $sql, 'ARRAY_A' );

                        return array_map(
                                static function ( array $row ): array {
                                        $row['id']       = (int) $row['id'];
                                        $row['attempts'] = (int) $row['attempts'];
                                        $row['payload']  = (array) json_decode( (string) $row['payload'], true );
                                        return $row;
                                },
                                $rows
                        );
                }

                $jobs = self::$jobs;
                usort(
                        $jobs,
                        static function ( array $a, array $b ): int {
                                return $b['id'] <=> $a['id']; // Más recientes primero
                        }
                );
                return array_slice( array_values( $jobs ), 0, $limit );
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

        /** Schedules the next retry time for a job. */
        public static function schedule_retry( int $id, int $delay_seconds ): void {
                global $wpdb;
                $timestamp = function_exists( 'current_time' ) ? current_time( 'timestamp', true ) : time();
                $timestamp = max( 0, (int) $timestamp ) + max( 0, $delay_seconds );
                $next_run  = gmdate( 'Y-m-d H:i:s', $timestamp );

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET created_at = %s WHERE id = %d', $next_run, $id ) );
                        return;
                }

                if ( isset( self::$jobs[ $id ] ) ) {
                        self::$jobs[ $id ]['available_at'] = $next_run;
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

	/**
	 * Returns queue statistics for monitoring.
	 * 
	 * @return array{total:int,pending:int,failed:int,old_jobs:int,avg_attempts:float}
	 */
	public static function get_stats(): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			// In-memory fallback
			$total = count( self::$jobs );
			$failed = array_filter( self::$jobs, fn( $job ) => $job['attempts'] >= 3 );
			$old_jobs = array_filter( self::$jobs, fn( $job ) => strtotime( $job['available_at'] ?? 'now' ) < strtotime( '-1 hour' ) );
			$avg_attempts = $total > 0 ? array_sum( array_column( self::$jobs, 'attempts' ) ) / $total : 0;
			
			return array(
				'total'        => $total,
				'pending'      => $total - count( $failed ),
				'failed'       => count( $failed ),
				'old_jobs'     => count( $old_jobs ),
				'avg_attempts' => round( $avg_attempts, 2 ),
			);
		}

		$table = self::table();
		$stats = $wpdb->get_row( "
			SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN attempts < 3 THEN 1 ELSE 0 END) as pending,
				SUM(CASE WHEN attempts >= 3 THEN 1 ELSE 0 END) as failed,
				SUM(CASE WHEN created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as old_jobs,
				AVG(attempts) as avg_attempts
			FROM {$table}
		" );

		if ( ! $stats ) {
			return array(
				'total'        => 0,
				'pending'      => 0,
				'failed'       => 0,
				'old_jobs'     => 0,
				'avg_attempts' => 0.0,
			);
		}

		// Convert object to array if needed
		$stats = (array) $stats;

		return array(
			'total'        => (int) ( $stats['total'] ?? 0 ),
			'pending'      => (int) ( $stats['pending'] ?? 0 ),
			'failed'       => (int) ( $stats['failed'] ?? 0 ),
			'old_jobs'     => (int) ( $stats['old_jobs'] ?? 0 ),
			'avg_attempts' => round( (float) ( $stats['avg_attempts'] ?? 0 ), 2 ),
		);
	}

	/**
	 * Returns jobs that have failed (attempts >= 3) for retry.
	 * 
	 * @return array<array{ id:int,type:string,payload:array<string,mixed>,attempts:int,created_at:string }>
	 */
	public static function get_failed_jobs(): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array_filter( self::$jobs, fn( $job ) => $job['attempts'] >= 3 );
		}

		$table = self::table();
		$results = $wpdb->get_results( "SELECT * FROM {$table} WHERE attempts >= 3 ORDER BY created_at DESC" );
		if ( ! is_array( $results ) ) {
			return array();
		}
		
		// Convert objects to arrays
		return array_map( fn( $row ) => (array) $row, $results );
	}

	/**
	 * Resets attempts counter for all failed jobs (mass retry).
	 */
	public static function retry_all_failed(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			$count = 0;
			foreach ( self::$jobs as &$job ) {
				if ( $job['attempts'] >= 3 ) {
					$job['attempts'] = 0;
					$count++;
				}
			}
			return $count;
		}

		$table = self::table();
		$affected = $wpdb->query( "UPDATE {$table} SET attempts = 0 WHERE attempts >= 3" );
		return is_numeric( $affected ) ? (int) $affected : 0;
	}
}

/* phpcs:enable */
class_alias( QueueDb::class, 'SII_Boleta_Queue_DB' );
