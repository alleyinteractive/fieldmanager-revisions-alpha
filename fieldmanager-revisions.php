<?php

/*
	Plugin Name: Fieldmanager Revisions (Alpha)
	Plugin URI: https://github.com/alleyinteractive/fieldmanager-revisions-alpha
	Description: Revision and Preview Postmeta. NOTE: This will change and will not have long-term support.
	Version: 0.0.1
	Author: Alley Interactive
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require_once( __DIR__ . '/class-fieldmanager-revisions.php' );


/**
 * Get the current post type in the WordPress Admin.
 */
function _fieldmanager_get_current_post_type() {
	global $post, $typenow, $current_screen;

	if ( $post && $post->post_type ) {
		// we have a post so we can just get the post type from that
		return $post->post_type;
	} elseif ( $typenow ) {
		// check the global $typenow - set in admin.php
		return $typenow;
	} elseif ( $current_screen && ! empty( $current_screen->post_type ) ) {
		// check the global $current_screen object - set in sceen.php
		return $current_screen->post_type;
	} elseif ( isset( $_REQUEST['post_type'] ) ) {
		// lastly check the post_type querystring
		return sanitize_key( $_REQUEST['post_type'] );
	}

	//we do not know the post type!
	return null;
}


/**
 * Deep clean a nested array.
 *
 * @param array $struct
 * @return array cleaned $struct
 */
function _fieldmanager_sanitize_deep( $struct ) {
	if ( is_object( $struct ) ) {
		$object = (array) $struct;
	} elseif ( ! is_array( $struct ) ) {
		// This plugin doesn't know how data needs to be sanitized, so we have
		// to assume it has HTML markup and sanitize it that way.
		return wp_kses_post( $struct );
	}

	$new_struct = array();
	foreach ( $struct as $k => &$v ) {
		$new_struct[ sanitize_text_field( $k ) ] = _fieldmanager_sanitize_deep( $v );
	}

	return $new_struct;
}


/**
 * Define the revision fields via filter.
 */
function fieldmanager_define_revisioned_fields() {
	/**
	 * Define the fields (post meta) which should be revisioned.
	 *
	 * Here's an example of how you might use this filter:
	 *
	 *     add_filter( 'fieldmanager_revision_fields', function( $fields ) {
	 *         $fields['post']['_thumbnail_id'] = 'Thumbnail';
	 *         return $fields;
	 *     } );
	 *
	 * @param array Revisioned fields as {post type} => (array) {meta keys}.
	 */
	$fields = apply_filters( 'fieldmanager_revision_fields', array() );
	foreach ( $fields as $post_type => $post_meta ) {
		new Fieldmanager_Revisions( $post_meta, $post_type );
	}
}
add_action( 'wp_loaded', 'fieldmanager_define_revisioned_fields' );
