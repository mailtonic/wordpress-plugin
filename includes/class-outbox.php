<?php
/**
 * The outbox: every write to mailtonic, queued, sent after the response, retried and logged.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Queue, send, retry and prune API writes.
 */
final class Outbox {
	const TABLE    = 'mailtonic_outbox';
	const LOCK     = 'mailtonic_outbox_lock';
	const BATCH    = 50;
	const BACKOFF  = array( 1, 5, 15, 60, 240 );
	const ATTEMPTS = 6;

	/**
	 * The table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The table's schema for dbDelta.
	 *
	 * @param string $collate Charset and collation.
	 * @return string
	 */
	public static function schema( $collate ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  source varchar(20) NOT NULL,
  reference varchar(191) NOT NULL DEFAULT '',
  method varchar(10) NOT NULL,
  path varchar(500) NOT NULL,
  body longtext NOT NULL,
  idempotency_key varchar(191) NOT NULL,
  depends_on bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
  last_status smallint(5) unsigned DEFAULT NULL,
  last_error_code varchar(64) DEFAULT NULL,
  last_error varchar(500) DEFAULT NULL,
  created_at bigint(20) unsigned NOT NULL,
  finished_at bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY status_next (status,next_attempt_at)
) {$collate};";
	}

	/**
	 * Queues an entry. Nothing is queued without a connection.
	 *
	 * @param array $entry kind, source, reference, method, path (under /api/v2), body, key (the business fact's reference), depends_on (optional entry id sent first).
	 * @return int|false Entry id (the existing one when the fact was already queued), or false.
	 */
	public static function queue( array $entry ) {
		global $wpdb;
		if ( ! Settings::connected() ) {
			return false;
		}
		$key   = substr( 'wp:' . Settings::site_key() . ':' . $entry['kind'] . ':' . $entry['key'], 0, 191 );
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (kind, source, reference, method, path, body, idempotency_key, depends_on, status, attempts, next_attempt_at, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %d, 'pending', 0, %d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry['kind'],
				$entry['source'],
				mb_substr( (string) $entry['reference'], 0, 191 ),
				$entry['method'],
				$entry['path'],
				wp_json_encode( $entry['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				$key,
				isset( $entry['depends_on'] ) ? (int) $entry['depends_on'] : 0,
				time(),
				time()
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! has_action( 'shutdown', array( self::class, 'flush' ) ) ) {
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
		return $id ? $id : false;
	}

	/**
	 * Sends after the visitor's response is finished, when the server allows it.
	 */
	public static function flush() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::run();
	}

	/**
	 * One outbox run: at most 50 due entries, oldest first, under a 4-minute lock.
	 *
	 * @param int|null $now Current time.
	 */
	public static function run( $now = null ) {
		global $wpdb;
		$now = null === $now ? time() : (int) $now;
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 4 * MINUTE_IN_SECONDS );
		try {
			self::expire( $now );
			if ( ! Settings::connected() || Settings::get( 'paused' ) ) {
				return;
			}
			$table = self::table();
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND next_attempt_at <= %d ORDER BY id ASC LIMIT %d", $now, self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				if ( $row['depends_on'] && 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $row['depends_on'] ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					continue;
				}
				if ( 'pause' === self::send( $row, $now ) ) {
					break;
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Sends one entry and records the outcome.
	 *
	 * @param array $row Entry.
	 * @param int   $now Current time.
	 * @return string The new status, or 'pause'.
	 */
	private static function send( array $row, $now ) {
		$response = Api::request( $row['method'], $row['path'], json_decode( $row['body'], true ), 'POST' === $row['method'] ? $row['idempotency_key'] : null );
		$status   = $response['status'];
		$code     = $response['code'];
		$attempts = (int) $row['attempts'] + 1;
		$fields   = array(
			'attempts'        => $attempts,
			'last_status'     => $status ? $status : null,
			'last_error_code' => Api::ok( $response ) ? null : substr( $code, 0, 64 ),
			'last_error'      => Api::ok( $response ) ? null : mb_substr( $response['message'], 0, 500 ),
		);

		if ( Api::ok( $response ) ) {
			$outcome = 'sent';
		} elseif ( 429 === $status ) {
			$retry_after               = (int) wp_remote_retrieve_header( array( 'headers' => $response['headers'] ), 'retry-after' );
			$outcome                   = 'pending';
			$fields['next_attempt_at'] = $now + max( 30, $retry_after );
		} elseif ( 401 === $status || 402 === $status || ( 403 === $status && 'account_suspended' === $code ) ) {
			$reason = 401 === $status ? 'unauthenticated' : ( 402 === $status ? 'subscription_required' : 'account_suspended' );
			Settings::update( array( 'paused' => $reason ) );
			unset( $fields['attempts'] );
			self::write( $row['id'], $fields );
			return 'pause';
		} elseif ( 404 === $status && 'contact_not_found' === $code && 'event' === $row['kind'] ) {
			$outcome = 'skipped';
		} elseif ( 0 === $status || 408 === $status || $status >= 500 || ( 409 === $status && in_array( $code, array( 'idempotency_key_in_use', 'import_running' ), true ) ) ) {
			$outcome = $attempts >= self::ATTEMPTS ? 'failed' : 'pending';
			if ( 'pending' === $outcome ) {
				$fields['next_attempt_at'] = $now + self::BACKOFF[ $attempts - 1 ] * MINUTE_IN_SECONDS;
			}
			if ( ! $status ) {
				$fields['last_error_code'] = 'network_error';
			}
		} else {
			$outcome = 'failed';
		}

		$fields['status'] = $outcome;
		if ( 'pending' !== $outcome ) {
			$fields['finished_at'] = $now;
		}
		self::write( $row['id'], $fields );
		if ( 'pending' !== $outcome ) {
			/**
			 * Fires when an outbox entry is finished (sent, skipped or failed).
			 *
			 * @param array      $row     The entry as it was before sending.
			 * @param string     $outcome sent, skipped or failed.
			 * @param array|null $body    The API's answer.
			 */
			do_action( 'mailtonic_outbox_finished', $row, $outcome, $response['body'] );
		}
		return $outcome;
	}

	/**
	 * Updates an entry.
	 *
	 * @param int   $id     Entry id.
	 * @param array $fields Columns.
	 */
	private static function write( $id, array $fields ) {
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pending entries older than 7 days (for example while paused) become failed.
	 *
	 * @param int $now Current time.
	 */
	private static function expire( $now ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', last_error_code = 'expired', last_error = %s, finished_at = %d WHERE status = 'pending' AND created_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				__( 'Not sent within 7 days.', 'mailtonic' ),
				$now,
				$now - 7 * DAY_IN_SECONDS
			)
		);
	}

	/**
	 * Deletes sent and skipped entries after 7 days and failed ones after 30 days.
	 *
	 * @param int|null $now Current time.
	 */
	public static function prune( $now = null ) {
		global $wpdb;
		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE (status IN ('sent', 'skipped') AND finished_at < %d) OR (status = 'failed' AND finished_at < %d)", $now - 7 * DAY_IN_SECONDS, $now - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sets failed entries pending again: one by id, or all.
	 *
	 * @param int|null $id Entry id, or null for every failed entry.
	 * @return int Entries retried.
	 */
	public static function retry( $id = null ) {
		global $wpdb;
		$table = self::table();
		$now   = time();
		if ( null === $id ) {
			return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = 0, next_attempt_at = %d, finished_at = NULL, created_at = %d WHERE status = 'failed'", $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = 0, next_attempt_at = %d, finished_at = NULL, created_at = %d WHERE status = 'failed' AND id = %d", $now, $now, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Entries matching exact column values, oldest first.
	 *
	 * @param array $where Column => value (id, kind, source, status, reference).
	 * @param int   $limit Maximum rows.
	 * @return array
	 */
	public static function find( array $where = array(), $limit = 0 ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE 1=1';
		$args = array();
		foreach ( array_intersect_key( $where, array_flip( array( 'id', 'kind', 'source', 'status', 'reference' ) ) ) as $column => $value ) {
			$sql   .= " AND {$column} = %s";
			$args[] = (string) $value;
		}
		$sql .= $limit ? ' ORDER BY id DESC LIMIT ' . (int) $limit : ' ORDER BY id ASC';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Failed entries in the last 24 hours.
	 *
	 * @return int
	 */
	public static function recent_failures() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND finished_at >= %d", time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes pending entries whose body holds an email (privacy eraser).
	 *
	 * @param string $email Email.
	 * @return int Rows deleted.
	 */
	public static function erase_pending( $email ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'pending' AND body LIKE %s", '%' . $wpdb->esc_like( trim( wp_json_encode( $email, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), '"' ) ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
