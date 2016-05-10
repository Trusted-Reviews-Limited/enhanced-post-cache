<?php
/*
Plugin Name: Advanced Post Caching
Description: Cache post queries.
Version: 0.3
Author: Automattic
Author URI: http://automattic.com/
*/

class Advanced_Post_Cache {
	var $cache_group = 'advanced_post_cache_';

	// Flag for temp (within one page load) turning invalidations on and off
	// Used to prevent invalidation during new comment
	var $do_flush_cache = true;

	/* Per cache-clear data */
	var $cache_salt = 0; // Increments the cache group (advanced_post_cache_0, advanced_post_cache_1, ...)

	/* Per query data */
	var $cache_key = ''; // md5 of current SQL query
	var $all_post_ids = false; // IDs of all posts current SQL query returns
	var $found_posts = false; // The result of the FOUND_ROWS() query

	public function __construct() {
		$this->setup_for_blog();

		add_action( 'switch_blog', array( $this, 'setup_for_blog' ), 10, 2 );

		add_action( 'clean_term_cache', array( $this, 'flush_cache' ) );
		add_action( 'clean_post_cache',  array( $this, 'flush_cache' ) );

		add_action( 'wp_updating_comment_count', array( $this, 'dont_clear_advanced_post_cache' ) );
		add_action( 'wp_update_comment_count', array( $this, 'do_clear_advanced_post_cache' ) );

		add_filter( 'split_the_query', '__return_true' );

		add_filter( 'posts_request_ids', array( $this, 'posts_request_ids' ) ); // Short circuits if cached
		add_filter( 'posts_results', array( $this, 'posts_results' ) ); // Collates if cached, primes cache if not

		add_filter( 'post_limits_request', array(
			$this,
			'post_limits_request',
		), 999, 2 ); // Checks to see if we need to worry about found_posts

		add_filter( 'found_posts_query', array( $this, 'found_posts_query' ) ); // Short circuits if cached
		add_filter( 'found_posts', array( $this, 'found_posts' ) ); // Reads from cache if cached, primes cache if not
	}

	public function setup_for_blog( $new_blog_id = false, $previous_blog_id = false ) {
		if ( $new_blog_id && $new_blog_id === $previous_blog_id ) {
			return;
		}

		$this->cache_salt = wp_cache_get( 'cache_incrementors', 'advanced_post_cache' ); // Get and construct current cache group name
		if ( ! is_numeric( $this->cache_salt ) ) {
			$this->set_cache_salt();
		}

	}

	/* Advanced Post Cache API */

	/**
	 * Flushes the cache by incrementing the cache group
	 */
	public function flush_cache() {
		// Cache flushes have been disabled
		if ( ! $this->do_flush_cache ) {
			return;
		}

		// Bail on post preview
		if ( is_admin() && isset( $_POST['wp-preview'] ) && 'dopreview' === $_POST['wp-preview'] ) {
			return;
		}

		// Bail on autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$this->set_cache_salt();
	}

	public function do_clear_advanced_post_cache() {
		$this->do_flush_cache = true;
	}

	public function dont_clear_advanced_post_cache() {
		$this->do_flush_cache = false;
	}

	/* Cache Reading/Priming Functions */

	/**
	 * Determines (by hash of SQL) if query is cached.
	 * If cached: Return query of needed post IDs.
	 * Otherwise: Returns query unchanged.
	 */
	public function posts_request_ids( $sql ) {
		$this->cache_key    = md5( $sql ); // init
		$this->all_post_ids = wp_cache_get( $this->cache_key . $this->cache_salt, $this->cache_group );
		$this->found_posts  = false;

		// Query is cached
		if ( is_array( $this->all_post_ids ) ) {
			$this->found_posts = count( $this->all_post_ids );
			$sql = '';
		}

		return $sql;
	}

	/**
	 * If cached: Collates posts returned by SQL query with posts that are already cached.  Orders correctly.
	 * Otherwise: Primes cache with data for current posts WP_Query.
	 */
	public function posts_results( $posts ) {
		if ( is_array( $this->all_post_ids ) ) { // is cache
			$posts = $this->all_post_ids;
		} else {
			$post_ids = wp_list_pluck( (array) $posts, 'ID' );
			wp_cache_set( $this->cache_key . $this->cache_salt, $post_ids, $this->cache_group );
		}

		return array_map( 'get_post', $posts );
	}

	/**
	 * If $limits is empty, WP_Query never calls the found_rows stuff, so we set $this->found_rows to 'NA'
	 */
	public function post_limits_request( $limits, &$query ) {
		if ( empty( $limits ) || ( isset( $query->query_vars['no_found_rows'] ) && $query->query_vars['no_found_rows'] ) ) {
			$this->found_posts = 'NA';
		} else {
			$this->found_posts = false;
		} // re-init

		return $limits;
	}

	/**
	 * If cached: Blanks SELECT FOUND_ROWS() query.  This data is already stored in cache.
	 * Otherwise: Returns query unchanged.
	 */
	public function found_posts_query( $sql ) {
		// is cached
		if ( $this->found_posts && is_array( $this->all_post_ids ) ) {
			$sql = '';
		}

		return $sql;
	}

	/**
	 * If cached: Returns cached result of FOUND_ROWS() query.
	 * Otherwise: Returs result unchanged
	 */
	public function found_posts( $found_posts ) {
		if ( $this->found_posts && is_array( $this->all_post_ids ) ) {
			$found_posts = (int) $this->found_posts;
		}

		return $found_posts;
	}

	private function set_cache_salt() {
		$this->cache_salt = microtime();
		wp_cache_set( 'cache_incrementors', $this->cache_salt, 'advanced_post_cache' );
	}
}

global $advanced_post_cache_object;
$advanced_post_cache_object = new Advanced_Post_Cache;
