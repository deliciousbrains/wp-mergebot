<?php

namespace DeliciousBrains\Mergebot\App\API;

class API_Exception extends \Exception {

	/**
	 * @var int
	 */
	public static $API_noKeySupplied = 101;

	/**
	 * @var int
	 */
	public static $API_HTTPRequest = 102;

	/**
	 * @var int
	 */
	public static $API_JSONDecode = 103;

	/**
	 * @var int
	 */
	public static $API_APIReturn = 104;

	/**
	 * @var mixed
	 */
	protected $error_data;

	/**
	 * API_Exception constructor.
	 *
	 * @param string         $message
	 * @param int            $code
	 * @param null           $error_data
	 * @param Exception|null $previous
	 */
	public function __construct( $message, $code = 0, $error_data = null, Exception $previous = null ) {
		$this->error_data = $error_data;

		// make sure everything is assigned properly
		parent::__construct( $message, $code, $previous );
	}

	/**
	 * @return mixed
	 */
	public function getErrorData() {
		return $this->error_data;
	}
}
