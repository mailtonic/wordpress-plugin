<?php
/**
 * Thrown by the test suite in place of a redirect, so admin handlers stop where they would exit.
 *
 * @package Mailtonic
 */

/**
 * A redirect captured in a test.
 */
class Mailtonic_Redirect_Exception extends Exception {
	/**
	 * The redirect target.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location Target URL.
	 */
	public function __construct( $location ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = $location;
	}
}
