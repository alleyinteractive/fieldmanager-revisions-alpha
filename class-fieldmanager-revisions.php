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
	 * Post meta fields are merged and stored as JSON for the revision diffing system.
	 * @var string
	 * @access protected
	 */
	protected $revision_meta_key = '_revision_meta';

	/**
	 * Constructor.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct( $meta_fields, $post_type ) {
		if ( is_admin() ) {
			$this->post_type = $post_type;
			$this->meta_fields = $meta_fields;

			add_filter( 'wp_save_post_revision_check_for_changes', array( $this, 'bypass_change_check' ), 20, 3 );

			add_action( '_wp_put_post_revision', array( $this, 'save_revision_post_meta' ), 20 );
			add_action( 'post_updated', array( $this, 'save_revision_post_meta' ), 20 );
			add_action( 'save_post', array( $this, 'action__save_post' ) );

			add_action( 'wp_restore_post_revision', array( $this, 'restore_revision' ), 20, 2 );

			add_action( 'admin_action_editpost', array( $this, 'set_preview_fields' ) );
		}

		if ( ! self::$global_hooks_set ) {
			add_filter( '_wp_post_revision_field_' . $this->revision_meta_key, array( $this, 'revision_meta_field' ), 10, 3 );
			add_filter( '_wp_post_revision_fields', array( $this, 'revision_meta_fields' ) );
			self::$global_hooks_set = true;
		}
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
				$meta = get_metadata( 'post', $revision_id, $key, true );

				if ( false === $meta ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $meta );
				}
			}
		}
	}

	/**
	 * Save the post meta options field for revisions
	 */
	public function save_revision_post_meta( $post_id ) {
		$parent_id = wp_is_post_revision( $post_id );
		if ( $parent_id && get_post_type( $parent_id ) == $this->post_type ) {
			$meta = array();
			foreach ( $this->meta_fields as $key => $label ) {
				if ( isset( $_POST[ $key ] ) ) {
					$value = _fieldmanager_sanitize_deep( $_POST[ $key ] );
					$meta[ $key ] = $value;
					update_metadata( 'post', $post_id, $key, $value );
				} elseif ( $value = get_post_meta( $parent_id, $key, true ) ) {
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
	 * This fires on save_post to store the revision_meta for diffing and autosaves.
	 *
	 * @param  int $post_id Post ID
	 * @return void
	 */
	public function action__save_post( $post_id ) {
		if ( get_post_type( $post_id ) != $this->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$meta = array();
		foreach ( $this->meta_fields as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$meta[ $key ] = _fieldmanager_sanitize_deep( $_POST[ $key ] );
			} elseif ( $value = get_post_meta( $post_id, $key, true ) ) {
				$meta[ $key ] = $value;
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
	function revision_meta_fields( $fields ) {
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
		return json_encode( $value, $JSON_PRETTY_PRINT );
	}

	/**
	 * Since our post meta is not POSTed, previews where the meta changed don't
	 * register as being modified, therefore the autosave or revision doesn't
	 * save. This method checks to see if we're previewing, and sets that POST
	 * data so changes are recognized.
	 *
	 * @return void
	 */
	public function set_preview_fields() {
		// Check if this is our post type
		if ( empty( $_POST['post_type'] ) || $this->post_type != $_POST['post_type'] ) {
			return;
		}

		// Make sure we're trying to preview
		if ( empty( $_POST['wp-preview'] ) || $_POST['wp-preview'] != 'dopreview' ) {
			return;
		}

		global $post_id;
		$meta = array();
		foreach ( $this->meta_fields as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = _fieldmanager_sanitize_deep( $_POST[ $key ] );
				$meta[ $key ] = $value;
			} elseif ( $value = get_post_meta( $post_id, $key, true ) ) {
				$meta[ $key ] = $value;
			}
		}

		if ( ! empty( $meta ) && ! isset( $_POST[ $this->revision_meta_key ] ) ) {
			$_POST[ $this->revision_meta_key ] = $this->to_json( $meta );
		}
	}
}
