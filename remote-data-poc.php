<?php
/**
 * Plugin Name:     Remote Data POC
 * Plugin URI:      https://github.com/alleyinteractive/remote-data-poc
 * Description:     A proof of concept for sourcing posts from remote data
 * Author:          Alley Interactive
 * Author URI:      https://www.alleyinteractive.com/
 * Text Domain:     remote-data-poc
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Remote_Data_Poc
 */

namespace Remote_Data_POC;

const POST_TYPE = 'external-post';

/**
 * Register a hidden custom post type.
 */
function data_structures() {
	register_post_type( POST_TYPE, [
		'public'              => true,
		'show_ui'             => false,
		'exclude_from_search' => true,
		'can_export'          => false,
	] );
}
add_action( 'init', __NAMESPACE__ . '\data_structures' );

/**
 * Get a post from the remote API, via slug.
 *
 * @param string $slug Post slug.
 * @return \WP_Error|\WP_Post Post object on success, WP_Error on failure.
 */
function get_post_from_api( $slug ) {
	$response = wp_remote_get( 'https://www.alleyinteractive.com/wp-json/wp/v2/posts?slug=' . $slug );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ) );
	if ( empty( $data[0] ) ) {
		return new \WP_Error( 'not-found', __( 'No posts found', 'remote-data-poc' ) );
	}
	$data = $data[0];

	$post = new \WP_Post( (object) [
		// Prevent WP from trying to inflate this object from cache or the db.
		'filter'            => 'raw',
		// Set the ID to be artificially high to avoid ID collisions with local data.
		'ID'                => 1000000000 + intval( $data->id ),
		'post_type'         => POST_TYPE,
		'post_name'         => $data->slug,
		'post_title'        => $data->title->rendered,
		'post_content'      => $data->content->rendered,
		'post_excerpt'      => $data->excerpt->rendered,
		'post_date'         => $data->date,
		'post_date_gmt'     => $data->date_gmt,
		'post_modified'     => $data->modified,
		'post_modified_gmt' => $data->modified_gmt,
	] );

	// Add the fake post to the object cache for calls to `get_post( $id )`.
	wp_cache_add( $post->ID, $post, 'posts', 10 );

	return $post;
}

/**
 * Intercept WP_Query to get data from the remote API if necessary.
 *
 * @param null      $posts    Null value to override to short-circuit WP_Query.
 * @param \WP_Query $wp_query WP_Query object.
 * @return null|array Array of \WP_Post objects if overriding the query, else null.
 */
function intercept_query( $posts, $wp_query ) {
	if ( (array) $wp_query->get( 'post_type' ) === [ POST_TYPE ] ) {
		$post = get_post_from_api( $wp_query->get( 'name' ) );
		if ( $post instanceof \WP_Post ) {
			$wp_query->set( 'cache_results', false );
			$wp_query->set( 'suppress_filters', true );
			$wp_query->found_posts   = 1;
			$wp_query->max_num_pages = 1;
			return [ $post ];
		}
	}

	return $posts;
}
add_filter( 'posts_pre_query', __NAMESPACE__ . '\intercept_query', 10, 2 );
