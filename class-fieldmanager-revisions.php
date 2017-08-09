<?php
/**
 * Revisions and Autosaves for previews
 */

class Fieldmanager_Revisions {

	private static $global_hooks_set = false;

	/**
	 * Post type of current revision
	 *
	 * @var array
	 * @access public
	 */
	public $post_type;

	/**
	 * Meta fields to save for revisions.
	 *
	 * @var array
	 * @access public
	 */
	public $meta_fields = array();


	/**
	 * Post meta fields are merged and stored as JSON for the revision diffing
	 * system.
	 *
	 * @access protected
	 * @var string
	 */
	protected $revision_meta_key = '_revision_meta';

	/**
	 * Build the object instance.
	 *
	 * @param array $meta_fields {
	 *     Meta fields to revision. The array's index key is used as the meta
	 *     key, e.g. `'my_meta_key' => 'My Field'`. The array value can either
	 *     be a string or an array. If a string, that becomes the label. If you
	 *     need to set the POST request key, you would instead pass an array
	 *     with `$label` and `$request_key` params (see below for details).
	 *
	 *
	 *     @type string       $label       Field label, for diffing purposes.
	 *     @type string|array $request_key Optional. $_POST[ $request_key ] is
	 *                                     used when saving data. If your FM
	 *                                     field uses `serialize_data => false`,
	 *                                     you need to override this. You can
	 *                                     define hierarchy by passing an array,
	 *                                     e.g. `[ 'foo', 'bar' ]` converts to
	 *                                     `$_POST['foo']['bar']`. If absent,
	 *                                     the array index will be used.
	 * }
	 * @param string $post_type Post type for this instance.
	 */
	public function __construct( $meta_fields, $post_type ) {
		$this->post_type = $post_type;

		foreach ( $meta_fields as $key => $args ) {
			if ( ! is_array( $args ) ) {
				$args = array(
					'label' => $args,
					'request_key' => array( $key ),
				);
			} else {
				if ( ! array_key_exists( 'label', $args ) ) {
					$args['label'] = $key;
				}
				if ( empty( $args['request_key'] ) ) {
					$args['request_key'] = array( $key );
				}
			}
			// Ensure the request key is an array.
			$args['request_key'] = (array) $args['request_key'];
			$this->meta_fields[ $key ] = $args;
		}

		if ( is_admin() ) {
			add_filter( 'wp_save_post_revision_check_for_changes', array( $this, 'bypass_change_check' ), 20, 3 );

			add_action( '_wp_put_post_revision', array( $this, 'save_revision_post_meta' ), 20 );
			add_action( 'post_updated', array( $this, 'save_revision_post_meta' ), 20 );

			add_action( 'save_post', array( $this, 'action__save_post' ) );

			add_action( 'wp_restore_post_revision', array( $this, 'restore_revision' ), 20, 2 );
		}

		add_filter( 'the_preview', array( $this, 'the_preview' ) );

		if ( ! self::$global_hooks_set ) {
			add_filter( '_wp_post_revision_field_' . $this->revision_meta_key, array( $this, 'revision_meta_field' ), 10, 3 );
			add_filter( '_wp_post_revision_fields', array( $this, 'revision_meta_fields' ) );
			self::$global_hooks_set = true;
		}
	}

	/**
	 * Only filter metadata when running a preview. This method ensures that
	 * this plugin doesn't impact normal site performance.
	 *
	 * @param  \WP_Post $post Post object.
	 * @return \WP_Post
	 */
	public function the_preview( $post ) {
		// Only add the filter if this instance matches the post's post type.
		if ( $this->post_type === $post->post_type ) {
			add_filter( 'get_post_metadata', array( $this, 'use_revision_meta' ), 10, 4 );
		}
		return $post;
	}

	/**
	 * Restore previous metadata for revision
	 */
	public function restore_revision( $post_id, $revision_id ) {
		if ( get_post_type( $post_id ) == $this->post_type ) {
			$meta_fields = array_keys( $this->meta_fields );
			$meta_fields[] = $this->revision_meta_key;
			$meta_fields = array_unique( $meta_fields );
			foreach ( $meta_fields as $key ) {
				$meta = get_metadata( 'post', $revision_id, $key );

				if ( ! is_array( $meta ) ) {
					// This case should never happen, but it is theoretically possible.
				} elseif ( empty( $meta ) ) {
					// Do nothing. It's not safe to delete meta if the revision has
					// nothing. Possible situations that would cause that:
					// - A post was updated outside of the admin, e.g. CLI script
					// - A revision existed before the key was set to be revisioned
				} else {
					delete_post_meta( $post_id, $key );
					foreach ( $meta as $value ) {
						add_post_meta( $post_id, $key, $value );
					}
				}
			}
		}
	}

	/**
	 * Recursively crawl an array to get a nested array value.
	 *
	 * Given an array like `[ 'foo' => [ 'bar' => [ 'bat' => 37 ] ] ]`, if you
	 * want to get the value `37` out of it, you would call
	 * `$this->get_nested_array_value( $arr, [ 'foo', 'bar', 'bat' ] )`. This is
	 * a helper for crawling $_POST for nested meta keys.
	 *
	 * @param  array $arr  Array to crawl through.
	 * @param  array $keys Array of keys to crawl.
	 * @return mixed       Nested value if found, null if not.
	 */
	protected function get_nested_array_value( $arr, $keys ) {
		$key = ! empty( $keys ) ? array_shift( $keys ) : null;
		if ( $key && array_key_exists( $key, $arr ) ) {
			return ! empty( $keys ) ? $this->get_nested_array_value( $arr[ $key ], $keys ) : $arr[ $key ];
		}
		return null;
	}

	/**
	 * Save the post meta options field for revisions.
	 */
	public function save_revision_post_meta( $post_id ) {
		$parent_id = wp_is_post_revision( $post_id );
		if ( $parent_id && get_post_type( $parent_id ) == $this->post_type ) {
			$meta = array();
			foreach ( $this->meta_fields as $key => $args ) {
				$raw_request_value = $this->get_nested_array_value( $_POST, $args['request_key'] );
				if ( isset( $raw_request_value ) ) {
					$value = _fieldmanager_sanitize_deep( $raw_request_value );
					$meta[ $key ] = $value;
					update_metadata( 'post', $post_id, $key, $value );
				} else {
					$value = get_post_meta( $parent_id, $key, true );
					$meta[ $key ] = $value;
					update_metadata( 'post', $post_id, $key, $value );
				}
			}
			if ( ! empty( $meta ) ) {
				update_metadata( 'post', $post_id, $this->revision_meta_key, $this->to_json( $meta ) );
			}
		}
	}

	/**
	 * This fires on save_post to store the revision_meta for diffing and
	 * autosaves.
	 *
	 * @param  int $post_id Post ID
	 */
	public function action__save_post( $post_id ) {
		if ( get_post_type( $post_id ) !== $this->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$meta = array();
		foreach ( $this->meta_fields as $key => $args ) {
			$raw_request_value = $this->get_nested_array_value( $_POST, $args['request_key'] );
			if ( isset( $raw_request_value ) ) {
				$meta[ $key ] = _fieldmanager_sanitize_deep( $raw_request_value );
			} else {
				$meta[ $key ] = get_post_meta( $post_id, $key, true );
			}
		}
		if ( ! empty( $meta ) ) {
			update_post_meta( $post_id, $this->revision_meta_key, $this->to_json( $meta ) );
		}
	}

	/**
	 * Always save a revision when updating.
	 */
	public function bypass_change_check( $check_for_changes, $last_revision, $post ) {
		if ( _fieldmanager_get_current_post_type() == $this->post_type ) {
			return false;
		}
		return $check_for_changes;
	}

	/**
	 * The names of meta fields
	 */
	public function revision_meta_fields( $fields ) {
		$fields[ $this->revision_meta_key ] = __( 'Meta Fields', 'fieldmanager-revisions' );
		return $fields;
	}

	/**
	 * Get previous metadata for revision
	 */
	public function revision_meta_field( $value, $field, $revision ) {
		return get_metadata( 'post', $revision->ID, $this->revision_meta_key, true );
	}

	/**
	 * Convert a variable to pretty-printed json.
	 *
	 * @param  mixed $value Data to json encode.
	 * @return string JSON string.
	 */
	public function to_json( $value = '' ) {
		$JSON_PRETTY_PRINT = defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT : null;
		return wp_json_encode( $value, $JSON_PRETTY_PRINT );
	}

	/**
	 * Intercept `get_post_meta()` calls to use the revisioned meta instead of
	 * the original post's meta, when appropriate.
	 *
	 * @see get_post_meta()
	 *
	 * @param  mixed $return The current return value. Unless something else is
	 *                       intervening, this should be `null`.
	 * @param  int $object_id {@see get_post_meta()}
	 * @param  string $meta_key {@see get_post_meta()}
	 * @param  bool $single This is ultimately ignored.
	 * @return array The value of $single is ignored, so this will always return
	 *               an array.
	 */
	public function use_revision_meta( $return, $object_id, $meta_key, $single ) {
		// If the value has already been manipualted, abort
		if ( null !== $return ) {
			return $return;
		}

		// Make sure that this class should handle this meta key
		if ( ! isset( $this->meta_fields[ $meta_key ] ) ) {
			return $return;
		}

		// Make sure this isn't an autosave
		if ( wp_is_post_autosave( $object_id ) ) {
			return $return;
		}

		// Make sure that the post type is for this object
		if ( $this->post_type !== get_post_type( $object_id ) ) {
			return $return;
		}

		// Get the latest autosave
		$preview = wp_get_post_autosave( $object_id );
		if ( ! is_object( $preview ) ) {
			return $return;
		}

		// With the autosave in-hand, get the metadata using that ID. Note that
		// we're ignoring $single here, because the rest of get_post_meta() will
		// handle that for us.
		$result = get_metadata( 'post', $preview->ID, $meta_key );

		return $result;
	}

}
