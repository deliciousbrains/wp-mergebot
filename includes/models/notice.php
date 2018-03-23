<?php

/**
 * The Notice Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Notice extends Base_Model implements Database_Model_Interface {

	/**
	 * @var string ID
	 */
	protected $id;

	/**
	 * @var string Notice type class
	 */
	protected $type = 'info';

	/**
	 * @var string Title of notice
	 */
	protected $title = '';

	/**
	 * @var string Notice message
	 */
	protected $message = '';

	/**
	 * @var bool Is notice dismissable?
	 */
	protected $dismissible = true;

	/**
	 * @var bool Display notice inline
	 */
	protected $inline = false;

	/**
	 * @var bool Is notice a flash notice, ie. displayed only once
	 */
	protected $flash = true;

	/**
	 * @var bool Only show the notice to the user who has initiated an action resulting in notice. Otherwise show to all users.
	 */
	protected $only_show_to_user = true;

	/**
	 * @var array A user with these capabilities can see the notice. Can be a callback with the first array item the name of global class instance.
	 */
	protected $user_capabilities = array( 'manage_options' );

	/**
	 * @var bool Only display the notice in the plugin settings page
	 */
	protected $only_show_in_settings = false;

	/**
	 * @var bool Automatically wrap the message in a <p>
	 */
	protected $auto_p = true;

	/**
	 * @var string Extra classes for the notice
	 */
	protected $class = '';

	/**
	 * @var bool Callback to display extra info on notices. Passing a callback automatically handles show/hide toggle.
	 */
	protected $show_callback = false;

	/**
	 * @var array Arguments to pass to the callback.
	 */
	protected $callback_args = array();

	/**
	 * @var bool Notice added in the footer of the settings page
	 */
	protected $footer = false;

	/**
	 * @var int User ID of whoever triggered the notice
	 */
	protected $triggered_by;

	/**
	 * @var int Timestamp of when created
	 */
	protected $created_at;

	/**
	 * Notice constructor.
	 *
	 * @param array $properties
	 */
	public function __construct( array $properties = array() ) {
		parent::__construct( $properties );

		if ( ! isset( $this->id ) ) {
			$this->id = self::get_id( $this->message );
		}
	}

	/**
	 * Create a notice
	 *
	 * @param array $properties
	 *
	 * @return static
	 */
	public static function create( $properties = array() ) {
		$notice = parent::create( $properties );

		$notice->triggered_by = get_current_user_id();
		$notice->created_at   = time();

		return $notice;
	}

	/**
	 * Generate the ID for the notice
	 *
	 * @param array $message
	 *
	 * @return string
	 */
	protected static function get_id( $message ) {
		$notice_prefix = apply_filters( 'mergebot_notice_id_prefix', 'notice-' );

		return $notice_prefix . sha1( $message );
	}

	/**
	 * Save the notice to the database
	 *
	 * @return bool
	 */
	public function save() {
		$notices = self::fetch( $this->triggered_by, $this->only_show_to_user );

		if ( isset( $notices[ $this->id ] ) && $notices[ $this->id ]->message === $this->message ) {
			// Message already exists as is, abort
			return false;
		}

		$notices[ $this->id ] = $this->to_array();

		return $this->update( $notices, $this->triggered_by );
	}

	/**
	 * Update the notice in the database
	 *
	 * @param array $notices
	 * @param int   $user_id
	 *
	 * @return bool
	 */
	protected function update( $notices, $user_id ) {
		if ( $this->only_show_to_user ) {
			$key = self::get_key();

			return $this->update_user_notices( $user_id, $notices, $key );
		}

		return $this->update_notices( $notices );
	}

	/**
	 * Update user notices, remove the record if no notices
	 *
	 * @param int    $user_id
	 * @param array  $notices
	 * @param string $key
	 *
	 * @return bool
	 */
	protected function update_user_notices( $user_id, $notices, $key ) {
		if ( empty( $notices ) ) {
			return delete_user_meta( $user_id, $key );
		}

		return (bool) update_user_meta( $user_id, $key, $notices );
	}

	/**
	 * Update global notices, remove the record if no notices
	 *
	 * @param array $notices
	 *
	 * @return bool
	 */
	protected function update_notices( $notices ) {
		$key = self::get_key();
		if ( empty( $notices ) ) {
			return delete_site_transient( $key );
		}

		return set_site_transient( $key, $notices );
	}

	/**
	 * Delete the notice from the database
	 *
	 * @return bool
	 */
	public function delete() {
		$user_id = get_current_user_id();

		$notices = self::fetch( $user_id, $this->only_show_to_user );

		if ( ! array_key_exists( $this->id, $notices ) ) {
			return false;
		}

		unset( $notices[ $this->id ] );

		return $this->update( $notices, $user_id );
	}

	/**
	 * Delete notice by ID
	 *
	 * @param string $id
	 * @param bool   $exact_match
	 *
	 * @return bool
	 */
	public static function delete_by_id( $id, $exact_match = false ) {
		$notices = self::all();

		$notice = self::find( $id, $notices );

		if ( is_null( $notice ) && false === $exact_match ) {
			$notice = self::search( $id, $notices );
		}

		if ( is_null( $notice ) ) {
			return false;
		}

		return $notice->delete();
	}

	/**
	 * Dismiss notice
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public function dismiss( $user_id ) {
		if ( $this->only_show_to_user ) {
			return $this->delete();
		}

		$dismissed_notices = Notice::all_dismissed_for_user( $user_id );

		if ( in_array( $this->id, $dismissed_notices ) ) {
			// Already dismissed
			return true;
		}

		$dismissed_notices[] = $this->id;

		return $this->update_user_notices( $user_id, $dismissed_notices, self::get_dismissed_key() );
	}

	/**
	 * Fetch all notices from the database
	 *
	 * @param int  $user_id
	 * @param bool $only_show_to_user
	 *
	 * @return mixed
	 */
	public static function fetch( $user_id, $only_show_to_user = true ) {
		if ( $only_show_to_user ) {
			return self::all_for_user( $user_id );
		}

		return self::all_global();
	}

	/**
	 * Get the key used for storing the notices in the database
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	protected static function get_key( $key = 'notices' ) {
		$site_key = md5( network_home_url() );
		// $this->bot->slug()
		$slug = 'mergebot';

		return $slug . '_' . $key . '_' . $site_key;
	}

	/**
	 * Get the key used for storing the dismissed notices in the database
	 *
	 * @return string
	 */
	protected static function get_dismissed_key() {
		return self::get_key( 'dismissed_notices' );
	}

	/**
	 * Get all global notices
	 *
	 * @return array
	 */
	public static function all_global() {
		$notices = get_site_transient( self::get_key() );

		return self::_notices( $notices );
	}

	/**
	 * Get all user specific notices for a user
	 *
	 * @param int $user_id
	 *
	 * @return array
	 */
	public static function all_for_user( $user_id ) {
		return self::all_for_user_by_key( $user_id, self::get_key() );
	}

	/**
	 * Get all notices, global and for the user.
	 *
	 * @return array
	 */
	public static function all() {
		$user_id = get_current_user_id();

		$user_notices = self::all_for_user( $user_id );

		$global_notices = self::all_global();

		return array_merge( $user_notices, $global_notices );
	}

	/**
	 * Get all notieces by user ID for specific notices key
	 *
	 * @param int    $user_id
	 * @param string $key
	 *
	 * @return array
	 */
	protected static function all_for_user_by_key( $user_id, $key ) {
		$notices = get_user_meta( $user_id, $key, true );

		return self::_notices( $notices );
	}

	/**
	 * Check if a notice has been dismissed for the current user
	 *
	 * @param int $user_id
	 *
	 * @return array
	 */
	public static function all_dismissed_for_user( $user_id ) {
		return self::all_for_user_by_key( $user_id, self::get_dismissed_key() );
	}

	/**
	 * Return notices always as an array
	 *
	 * @param mixed $notices
	 *
	 * @return array
	 */
	protected static function _notices( $notices ) {
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		foreach ( $notices as $key => $notice ) {
			if ( is_array( $notice ) ) {
				$notices[ $key ] = new static( $notice );
			}
		}

		return $notices;
	}

	/**
	 * Get a notice by ID
	 *
	 * @param string     $id
	 * @param null|array $notices
	 *
	 * @return array|null
	 */
	public static function find( $id, $notices = null ) {
		if ( is_null( $notices ) ) {
			$notices = self::all();
		}

		if ( array_key_exists( $id, $notices ) ) {
			return $notices[ $id ];
		}

		return null;
	}

	/**
	 * Search for a notice by partial ID
	 *
	 * @param string     $id
	 * @param null|array $notices
	 *
	 * @return array|null
	 */
	public static function search( $id, $notices = null ) {
		if ( is_null( $notices ) ) {
			$notices = self::all();
		}

		foreach ( $notices as $notice_key => $notice ) {
			if ( false !== strpos( $notice_key, $id ) ) {
				return $notice;
			}
		}

		return null;
	}

	/**
	 * Does the notice exist?
	 *
	 * @param mixed $id
	 *
	 * @return bool
	 */
	public static function exists( $id ) {
		return (bool) self::find( $id );
	}

	/**
	 * Is a notice dismissed?
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public function is_dismissed( $user_id ) {
		if ( $this->only_show_to_user ) {
			$notices = self::all_for_user( $user_id );

			return ! isset( $notices[ $this->id ] );
		}

		$dismissed_notices = self::all_dismissed_for_user( $user_id );

		return in_array( $this->id, $dismissed_notices );
	}

}