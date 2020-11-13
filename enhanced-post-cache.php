<?php
/*
Plugin Name: Enhanced Post Cache
Description: Cache posts queries to not repeat same queries.
Version: 1.0
Author: TimeInc
Author URI: http://timeincuk.com/
*/

class Enhanced_Post_Cache {
	// IDs of all posts current SQL query returns
	public $all_post_ids = false;
	public $cache_salt = false;

	private $do_flush_cache = true;
	private $cache_queries = true;
	private $cache_group = 'enhanced_post_cache';
	private $found_posts = 0;
	private $cache_key = '';
	private $last_result = array();

	public function __construct() {
		$this->setup_for_blog();

		add_action( 'switch_blog', array( $this, 'setup_for_blog' ), 10, 2 );

		add_action( 'clean_term_cache', array( $this, 'flush_cache' ) );
		add_action( 'clean_post_cache',  array( $this, 'clean_post_cache' ), 10, 2 );

		add_action( 'deleted_post_meta', array( $this, 'update_post_meta' ), 10, 2 );
		add_action( 'updated_post_meta', array( $this, 'update_post_meta' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'update_post_meta' ), 10, 2 );

		add_action( 'wp_updating_comment_count', array( $this, 'dont_clear_advanced_post_cache' ) );
		add_action( 'wp_update_comment_count', array( $this, 'do_clear_advanced_post_cache' ) );

		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

		add_filter( 'posts_request_ids', array( $this, 'posts_request_ids' ) );
		add_filter( 'posts_results', array( $this, 'posts_results' ), 10, 2 );
	}

	public function setup_for_blog( $new_blog_id = false, $previous_blog_id = false ) {
		if ( $new_blog_id && (int) $new_blog_id === (int) $previous_blog_id ) {
			return;
		}

		$this->cache_salt = wp_cache_get( 'cache_incrementors', 'advanced_post_cache' );

		if ( false === $this->cache_salt ) {
			$this->set_cache_salt();
		}
	}

	public function clean_post_cache( $post_id, $post ) {
		if ( ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post ) ) {
			$this->flush_cache();
		}
	}

	public function update_post_meta( $ignored, $post_id ) {
		$post = get_post( $post_id );
		$this->clean_post_cache( $post_id, $post );
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
	 * Pre Get Posts
	 *
	 * An easy way to switch off the plugin for any specific query
	 *
	 * @see WP_Query::get_posts (pre_get_posts action)
	 *
	 * @param WP_Query $wp_query The WP_Query instance
	 */
	public function pre_get_posts( $wp_query ) {
		$this->cache_queries = apply_filters( 'use_enhanced_post_cache', true, $wp_query );
		add_filter( 'split_the_query', function() { return $this->cache_queries; } );
	}

	/**
	 * Post Results Ids
	 *
	 * Tries to search if the current query is cached:
	 * If cached: stop the normal execution by emptying $sql and flushing $wpdb
	 * If not cached: returns to the normal WP_Query execution
	 *
	 * @see WP_Query::get_posts (post_request_ids filter)
	 *
	 * @param string $sql Query to be executed
	 * @return string $sql empty string (if cached) or same query (if not cached)
	 */
	public function posts_request_ids( $sql ) {
		if ( ! $this->cache_queries ) {
			return $sql;
		}

		global $wpdb;

		$query = $sql;
		// Check if method existing before using it for backwards compat
		if( method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
			// Remove placeholders, as they would break the cache key for searches.
			$query = $wpdb->remove_placeholder_escape( $query );
		}
		$this->cache_key   = md5( $query );
		$this->found_posts = 0;
		$cache             = wp_cache_get( $this->cache_key . $this->cache_salt, $this->cache_group );

		if ( ! empty( $cache ) && is_array( $cache ) ) {
			$this->last_result  = $wpdb->last_result;
			$wpdb->last_result  = array();
			$sql                = '';
			$this->found_posts  = $cache['found_posts'];
			$this->all_post_ids = $cache['post_ids'];
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
		if ( ! $this->cache_queries ) {
			return $posts;
		}

		global $wpdb;

		if ( $this->is_cached() ) {
			$this->prime_post_caches( $this->all_post_ids, $wp_query->query_vars['update_post_term_cache'], $wp_query->query_vars['update_post_meta_cache'] );

			$posts = array_map( 'get_post', $this->all_post_ids );
			$wp_query->found_posts = $this->found_posts;
			$wpdb->last_result = $this->last_result;
			$this->last_result = array();
		} else {
			$post_ids = wp_list_pluck( (array) $posts, 'ID' );
			$value = array(
				'post_ids'    => $post_ids,
				'found_posts' => $wp_query->found_posts,
			);
			wp_cache_set( $this->cache_key . $this->cache_salt, $value, $this->cache_group );
		}

		if ( $wp_query->query_vars['posts_per_page'] > -1 ) {
			$wp_query->max_num_pages = ceil( $wp_query->found_posts / $wp_query->query_vars['posts_per_page'] );
		}

		return $posts;
	}

	/**
	 * Copy of _prime_post_caches which is a private function.
	 *
	 * @see update_post_caches()
	 * @see _prime_post_caches()
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $ids               ID list.
	 * @param bool  $update_term_cache Optional. Whether to update the term cache. Default true.
	 * @param bool  $update_meta_cache Optional. Whether to update the meta cache. Default true.
	 */
	private function prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
		global $wpdb;

		$non_cached_ids = $this->get_non_cached_ids( $ids, 'posts' );
		if ( ! empty( $non_cached_ids ) ) {
			$fresh_posts = $wpdb->get_results( sprintf( "SELECT $wpdb->posts.* FROM $wpdb->posts WHERE ID IN (%s)", implode( ',', $non_cached_ids ) ) );

			update_post_caches( $fresh_posts, 'any', $update_term_cache, $update_meta_cache );
		}
	}

	/**
	 * Copy of the _get_non_cached_ids function in core that checks
	 * to see if the wp_cache_get_multiple function exists.
	 *
	 * @see _get_non_cached_ids()
	 *
	 * @param int[]  $object_ids Array of IDs.
	 * @param string $cache_key  The cache bucket to check against.
	 * @return int[] Array of IDs not present in the cache.
	 */
	private function get_non_cached_ids( $object_ids, $cache_key ) {
		$clean = array();
		if ( function_exists( 'wp_cache_get_multiple' ) ) {
			$cache_values = wp_cache_get_multiple( $object_ids, $cache_key );
			foreach ( $cache_values as $id => $value ) {
				if ( ! $value ) {
					$non_cached_ids[] = (int) $id;
				}
			}
		} else {
			foreach ( $object_ids as $id ) {
				$id = (int) $id;
				if ( ! wp_cache_get( $id, $cache_key ) ) {
					$clean[] = $id;
				}
			}
		}

		return $clean;
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
			&& ! (isset( $_POST['wp-preview'] ) && 'dopreview' === $_POST['wp-preview']);
	}
}

global $enhanced_post_cache_object;
$enhanced_post_cache_object = new Enhanced_Post_Cache;
