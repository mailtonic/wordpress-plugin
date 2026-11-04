<?php
/**
 * HTTP fake on WordPress's pre-request filter: scripted answers per method and path,
 * every request recorded, and a request with no scripted answer fails the test.
 *
 * @package Mailtonic
 */

use PHPUnit\Framework\Assert;

/**
 * The fake mailtonic API.
 */
class Mailtonic_Fake {
	/**
	 * Scripted answers keyed by "METHOD path"; each key holds a queue whose last answer repeats.
	 *
	 * @var array
	 */
	private $answers = array();

	/**
	 * Recorded requests.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Installs the fake.
	 */
	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Scripts an answer. $status may be 'timeout' for a network error.
	 * A path with a query string matches only that query; without one it matches any query.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path, for example /api/v2/me.
	 * @param int|string $status  Status code or 'timeout'.
	 * @param mixed      $body    Body, JSON-encoded unless a string.
	 * @param array      $headers Response headers.
	 * @return $this
	 */
	public function on( $method, $path, $status, $body = null, $headers = array() ) {
		$this->answers[ strtoupper( $method ) . ' ' . $path ][] = compact( 'status', 'body', 'headers' );
		return $this;
	}

	/**
	 * Replaces any scripted answers for the key with this one.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path.
	 * @param int|string $status  Status.
	 * @param mixed      $body    Body.
	 * @param array      $headers Headers.
	 * @return $this
	 */
	public function only( $method, $path, $status, $body = null, $headers = array() ) {
		unset( $this->answers[ strtoupper( $method ) . ' ' . $path ] );
		return $this->on( $method, $path, $status, $body, $headers );
	}

	/**
	 * Recorded requests, optionally only those for a method and path.
	 *
	 * @param string|null $method Method filter.
	 * @param string|null $path   Path filter (without query).
	 * @return array
	 */
	public function requests( $method = null, $path = null ) {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $method, $path ) {
					return ( null === $method || $r['method'] === $method ) && ( null === $path || $r['path'] === $path );
				}
			)
		);
	}

	/**
	 * The last recorded request.
	 *
	 * @return array|null
	 */
	public function last() {
		return $this->requests ? end( $this->requests ) : null;
	}

	/**
	 * Forgets recorded requests.
	 */
	public function reset() {
		$this->requests = array();
	}

	/**
	 * The pre_http_request callback.
	 *
	 * @param mixed  $pre  Earlier answer.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public function answer( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$parts   = wp_parse_url( $url );
		$path    = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query   = isset( $parts['query'] ) ? $parts['query'] : '';
		$method  = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$body    = isset( $args['body'] ) && is_string( $args['body'] ) && '' !== $args['body'] ? json_decode( $args['body'], true ) : null;
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();

		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'path'    => $path,
			'query'   => $query,
			'headers' => $headers,
			'body'    => $body,
			'args'    => $args,
		);

		$key = $method . ' ' . $path . ( '' !== $query ? '?' . $query : '' );
		if ( empty( $this->answers[ $key ] ) ) {
			$key = $method . ' ' . $path;
		}
		if ( empty( $this->answers[ $key ] ) ) {
			Assert::fail( "Unscripted request: {$method} {$url}" );
		}
		$answer = count( $this->answers[ $key ] ) > 1 ? array_shift( $this->answers[ $key ] ) : $this->answers[ $key ][0];

		if ( 'timeout' === $answer['status'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $answer['headers'] ),
			'body'     => is_string( $answer['body'] ) ? $answer['body'] : wp_json_encode( $answer['body'] ),
			'response' => array(
				'code'    => $answer['status'],
				'message' => get_status_header_desc( $answer['status'] ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
