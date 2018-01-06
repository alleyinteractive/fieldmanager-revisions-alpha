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
	 * Constructor.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct( $meta_fields, $post_type ) {
		$this->post_type = $post_type;
		$this->meta_fields = $meta_fields;

		if ( is_admin() ) {
			add_filter( 'wp_save_post_revision_check_for_changes', array( $this, 'bypass_change_check' ), 20, 3 );

			add_action( '_wp_put_post_revision', array( $this, 'save_revision_post_meta' ), 20 );
			add_action( 'post_updated', array( $this, 'save_revision_post_meta' ), 20 );

			add_action( 'save_post', array( $this, 'action__save_post' ) );

			add_action( 'wp_restore_post_revision', array( $this, 'restore_revision' ), 20, 2 );

			add_filter( 'wp_get_revision_ui_diff', array( $this, 'reformat_revisions_ui' ), 10, 3 );
		}

		add_filter( 'the_preview', array( $this, 'the_preview' ) );

		if ( ! self::$global_hooks_set ) {
			add_filter( '_wp_post_revision_field_' . $this->revision_meta_key, array( $this, 'revision_meta_field' ), 10, 3 );
			add_filter( '_wp_post_revision_fields', array( $this, 'revision_meta_fields' ) );
			self::$global_hooks_set = true;
		}
	}

	public function the_preview( $post ) {
		add_filter( 'get_post_metadata', array( $this, 'use_revision_meta' ), 10, 4 );
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
	 * This fires on save_post to store the revision_meta for diffing and
	 * autosaves.
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
	 * Render individual meta field diffs
	 *
	 * @param array   $diffs        Revision UI fields. Each item is an array of id, name and diff.
	 * @param WP_Post $compare_from The revision post to compare from.
	 * @param WP_Post $compare_to   The revision post to compare to.
	 * @return array
	 */
	public function reformat_revisions_ui( array $diffs, WP_Post $compare_from, WP_Post $compare_to ) : array {
		$meta_revisions     = '';
		$revisions_diff_key = null;

		foreach ( $diffs as $diff_key => $diff ) {
			if ( $diff['id'] !== $this->revision_meta_key ) {
				continue;
			}

			$revisions_diff_key = $diff_key;

			foreach ( $this->meta_fields as $key => $args ) {
				$meta_revisions .= $this->diff_single_meta( $key, $compare_from, $compare_to, $args );
			}
		}

		if ( ! is_null( $revisions_diff_key ) && ! empty( $meta_revisions ) ) {
			$diffs[ $revisions_diff_key ]['diff'] = $meta_revisions;
		}

		return $diffs;
	}

	/**
	 * Render diff of single meta key
	 *
	 * @param string       $key Meta key.
	 * @param WP_Post      $compare_from The revision post to compare from.
	 * @param WP_Post      $compare_to The revision post to compare to.
	 * @param array|string $render_args Display argument.
	 * @return string
	 */
	private function diff_single_meta( string $key, WP_Post $compare_from, WP_Post $compare_to, $render_args ) : string {
		if ( is_string( $render_args ) ) {
			$render_args = [
				'label' => $render_args,
			];
		}

		$diff = '';

		$old_value = is_object( $compare_from ) ? get_metadata( 'post', $compare_from->ID, $key, true ) : array();
		$new_value = is_object( $compare_to ) ? get_metadata( 'post', $compare_to->ID, $key, true ) : array();

		if ( $old_value !== $new_value ) {
			$diff .= '<h4>' . esc_html( $render_args['label'] ) . '</h4>';

			$diff_args = array(
				'show_split_view' => true,
			);
			$diff_args = apply_filters( 'revision_text_diff_options', $diff_args, $key, $compare_from, $compare_to );

			if ( isset( $render_args['display_callback'] ) && is_callable( $render_args['display_callback'] ) ) {
				$old_value = call_user_func( $render_args['display_callback'], $old_value, $key );
				$new_value = call_user_func( $render_args['display_callback'], $new_value, $key );

				$diff .= $this->build_diff_row( $old_value, $new_value, $diff_args );
			} else {
				if ( ! is_int( $old_value ) && ! is_string( $old_value ) ) {
					$old_value = $this->to_json( $old_value );
				}

				if ( ! is_int( $new_value ) && ! is_string( $new_value ) ) {
					$new_value = $this->to_json( $new_value );
				}

				$diff .= wp_text_diff( $old_value, $new_value, $diff_args );
			}
		}

		return $diff;
	}

	/**
	 * Display custom diff in expected format
	 *
	 * @see wp_text_diff()
	 *
	 * @param string $left_string Left diff.
	 * @param string $right_string Right diff.
	 * @param array  $args Optional. Display arguments.
	 * @return string
	 */
	private function build_diff_row( string $left_string, string $right_string, array $args = [] ) : string {
		$args = wp_parse_args( $args, [
			'show_split_view' => false,
			'title'           => null,
			'title_left'      => null,
			'title_right'     => null,
		] );

		$row = "<table class='diff'>\n";

		if ( ! empty( $args['show_split_view'] ) ) {
			$row .= "<col class='content diffsplit left' /><col class='content diffsplit middle' /><col class='content diffsplit right' />";
		} else {
			$row .= "<col class='content' />";
		}

		if ( ! empty( $args['title'] ) || ! empty( $args['title_left'] ) || ! empty( $args['title_right'] ) ) {
			$row .= '<thead>';
		}

		if ( ! empty( $args['title'] ) ) {
			$row .= "<tr class='diff-title'><th colspan='4'>{$args['title']}</th></tr>\n";
		}

		if ( ! empty( $args['title_left'] ) || ! empty( $args['title_right'] ) ) {
			$row .= "<tr class='diff-sub-title'>\n";
			$row .= "\t<td></td><th>{$args['title_left']}</th>\n";
			$row .= "\t<td></td><th>{$args['title_right']}</th>\n";
			$row .= "</tr>\n";
		}

		if ( ! empty( $args['title'] ) || ! empty( $args['title_left'] ) || ! empty( $args['title_right'] ) ) {
			$row .= "</thead>\n";
		}

		$row .= "<tbody>\n<tr>\n";
		$row .= "\t<td>{$left_string}</td>\n";
		$row .= "\t<td>&nbsp;</td>\n";
		$row .= "\t<td>{$right_string}</td>\n";
		$row .= "</tr>\n</tbody>\n";
		$row .= '</table>';

		return $row;
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
		if ( $this->post_type != get_post_type( $object_id ) ) {
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
