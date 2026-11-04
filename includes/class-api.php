<?php
/**
 * Thin client of mailtonic API v2 over the WordPress HTTP API.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * API v2 requests. Calls go only to the connection's app URL.
 */
final class Api {

	/**
	 * Sends one request.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $path    Path under /api/v2, for example /me.
	 * @param array|null  $body    JSON body.
	 * @param string|null $idem    Idempotency key (POST only).
	 * @param string|null $key     API key (default: the stored key).
	 * @param string|null $app_url App URL (default: the stored URL).
	 * @return array { status: int (0 on network error), body: array|null, headers: array, code: string, message: string }
	 */
	public static function request( $method, $path, $body = null, $idem = null, $key = null, $app_url = null ) {
		$user_agent = 'mailtonic-wordpress/' . Plugin::VERSION . '; ' . home_url();
		$headers    = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => $user_agent,
			'Authorization' => 'Bearer ' . ( null === $key ? Settings::api_key() : $key ),
		);
		if ( 'POST' === $method && $idem ) {
			$headers['Idempotency-Key'] = $idem;
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'user-agent'  => $user_agent,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( ( null === $app_url ? Settings::app_url() : $app_url ) . '/api/v2' . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'body'    => null,
				'headers' => array(),
				'code'    => 'network_error',
				'message' => $response->get_error_message(),
			);
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$error   = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';
		if ( ! empty( $error['fields'] ) && is_array( $error['fields'] ) ) {
			foreach ( $error['fields'] as $field => $problems ) {
				$message .= ' ' . $field . ': ' . implode( ' ', (array) $problems );
			}
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => is_array( $decoded ) ? $decoded : null,
			'headers' => $response['headers'],
			'code'    => isset( $error['code'] ) ? (string) $error['code'] : '',
			'message' => trim( $message ),
		);
	}

	/**
	 * Whether a response is 2xx.
	 *
	 * @param array $response Response from request().
	 * @return bool
	 */
	public static function ok( array $response ) {
		return $response['status'] >= 200 && $response['status'] < 300;
	}
}
