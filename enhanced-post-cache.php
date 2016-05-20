<?php
/*
Plugin Name: Enhanced Post Cache
Description: Cache posts queries to not repeat same queries.
Version: 1.0
Author: TimeInc
Author URI: http://timeincuk.com/
*/

class Enhanced_Post_Cache {

	private $do_flush_cache = true;
	private $cache_group = 'advanced_post_cache';
	private $found_posts = 0;
	private $cache_key = '';

	public $cache_salt = 0; // Increments the cache group (advanced_post_cache_0, advanced_post_cache_1, ...)
	public $all_post_ids = false; // IDs of all posts current SQL query returns

	public function __construct() {
		$this->setup_for_blog();

		add_action( 'switch_blog', array( $this, 'setup_for_blog' ), 10, 2 );

		add_action( 'clean_term_cache', array( $this, 'flush_cache' ) );
		add_action( 'clean_post_cache',  array( $this, 'flush_cache' ) );

		add_action( 'wp_updating_comment_count', array( $this, 'dont_clear_advanced_post_cache' ) );
		add_action( 'wp_update_comment_count', array( $this, 'do_clear_advanced_post_cache' ) );

		add_filter( 'split_the_query', '__return_true' );

		add_filter( 'posts_request_ids', array( $this, 'posts_request_ids' ) ); // Short circuits if cached
		add_filter( 'posts_results', array( $this, 'posts_results' ), 10, 2 ); // Collates if cached, primes cache if not
	}

	public function setup_for_blog( $new_blog_id = false, $previous_blog_id = false ) {
		if ( $new_blog_id && $new_blog_id === $previous_blog_id ) {
			return;
		}

		$this->cache_salt = wp_cache_get( 'cache_incrementors', 'advanced_post_cache' );

		if ( ! is_numeric( $this->cache_salt ) ) {
			$this->set_cache_salt();
		}
	}

	public function flush_cache() {
		if ( $this->needs_cache_clear() ) {
		    $this->set_cache_salt();
		}
	}

	public function do_clear_advanced_post_cache() {
		$this->do_flush_cache = true;
	}

	public function dont_clear_advanced_post_cache() {
		$this->do_flush_cache = false;
	}

	/**
	 * Determines (by hash of SQL) if query is cached.
	 * If cached: Return query of needed post IDs.
	 * Otherwise: Returns query unchanged.
	 */
	public function posts_request_ids( $sql ) {
		global $wpdb;
		$this->cache_key = md5( $sql );
		$this->found_posts = 0;
		$this->all_post_ids = wp_cache_get( $this->cache_key . $this->cache_salt, $this->cache_group );

		if ( $this->is_cached() ) {
			$wpdb->flush();
			$sql = '';
			$this->found_posts = wp_cache_get( 'found_' . $this->cache_key . $this->cache_salt, $this->cache_group );
		}

		return $sql;
	}

	/**
	 * Post Results
	 *
	 * If is cached, we have posts cached for the current query, then filter running
	 * `get_posts` on every cached id, otherwise cache the original ids and return the original result
	 *
	 * @see WP_Query::get_posts (post_results filter)
	 *
	 * @param array $posts array of WP_Post elements
	 * @return array $posts array of WP_Post elements
	 */
	public function posts_results( $posts, $wp_query ) {
		if ( $this->is_cached() ) {
			$posts = array_map( 'get_post', $this->all_post_ids );
			$wp_query->found_posts = $this->found_posts;
		} else {
			$post_ids = wp_list_pluck( (array) $posts, 'ID' );

			wp_cache_set( $this->cache_key . $this->cache_salt, $post_ids, $this->cache_group );
			wp_cache_set( 'found_' . $this->cache_key . $this->cache_salt, $wp_query->found_posts, $this->cache_group );
		}

		if ( $wp_query->query_vars['posts_per_page'] > -1 ) {
			$wp_query->max_num_pages = ceil( $wp_query->found_posts / $wp_query->query_vars['posts_per_page'] );
		}

		return $posts;
	}

	private function is_cached() {
		return is_array( $this->all_post_ids );
	}

	private function set_cache_salt() {
		$this->cache_salt = microtime();
		wp_cache_set( 'cache_incrementors', $this->cache_salt, 'advanced_post_cache' );
	}

	private function needs_cache_clear() {
		return $this->do_flush_cache
		    && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			&& ! is_admin()
			&& ! (isset( $_POST['wp-preview'] ) && 'dopreview' === $_POST['wp-preview']);
	}
}

global $enhanced_post_cache_object;
$enhanced_post_cache_object = new Enhanced_Post_Cache;
