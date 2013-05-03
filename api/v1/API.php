<?php

namespace WP_JSON_API;

if ( ! defined( 'MAX_POSTS_PER_PAGE' ) )
	define( 'MAX_POSTS_PER_PAGE', 10 );

require_once( __DIR__ . '/../API_Base.php' );

class APIv1 extends API_Base {

	protected $version = '1';

	public function __construct( \Slim\Slim $app ) {
		parent::__construct( $app );
		$this->registerRoute( 'GET', 'posts(/:id)', array( $this, 'get_posts' ) );
		$this->registerRoute( 'GET', 'users(/:id)', array( $this, 'get_users' ) );
		$this->registerRoute( 'GET', 'taxonomies(/:name)', array( $this, 'get_taxonomies' ) );
		$this->registerRoute( 'GET', 'taxonomies/:name/terms(/:term_id)', array( $this, 'get_terms' ) );
		$this->registerRoute( 'GET', 'rewrite_rules', array( $this, 'get_rewrite_rules' ) );
	}

	public function get_posts( $id = null ) {

		$found = 0;
		$posts = array();

		$request = $this->app->request();
		$args = $request->get();
		$wp_query_posts = $this->get_post_query( $request, $id );

		if ( $wp_query_posts->have_posts() ) {
			$found = $wp_query_posts->found_posts;
			foreach ( $wp_query_posts->posts as $query_post ) {
				$posts[] = $this->format_post( $query_post );
			}
		}

		return ! empty( $args['include_found'] ) ? compact( 'found', 'posts' ) : compact( 'posts' );
	}

	/**
	 * @param \Slim\Http\Request $request
	 * @param int $id
	 * @return WP_Query
	 */
	public function get_post_query( \Slim\Http\Request $request, $id = null ) {

		$force_public_post_stati = function( $wp_query ){
			$qv = &$wp_query->query_vars;

			$invalid_status = false;

			// verify post_status query var exists
			if ( !isset( $qv['post_status'] ) )
				$qv['post_status'] = '';

			// gets rid of non public stati
			if ( !empty( $qv['post_status'] ) ) {
				$non_public_stati = array_values( get_post_stati( array( 'public' => false ) ) );

				$qv['post_status'] = (array)$qv['post_status'];

				// noting count before and after to check if a non valid status was specified
				$before_count = count( $qv['post_status'] );
				$qv['post_status'] = array_diff( (array)$qv['post_status'], $non_public_stati );
				$after_count = count( $qv['post_status'] );

				$invalid_status = ( $before_count !== $after_count );
			}

			// validates status is an actual status
			$post_stati = get_post_stati();
			$qv['post_status'] = array_intersect( $post_stati, (array)$qv['post_status'] );

			// if no post status is set and and invalid status was specified
			// we want to return no results
			if ( empty( $qv['post_status'] ) && $invalid_status ) {
				add_filter( 'posts_request', function() {
					return '';
				});
			}
		};

		add_action('parse_query', $force_public_post_stati );

		$args = $request->get();

		$defaults = array(
			'found_posts' => false,
		);

		if ( ! is_null( $id ) ) {
			$args['p'] = (int)$id;
			$single_post_query = new \WP_Query( array_merge( $defaults, $args ) );
			remove_action('parse_query', $force_public_post_stati );
			return $single_post_query;
		}

		if ( isset( $args['taxonomy'] ) && is_array( $args['taxonomy'] ) ) {
			$args['tax_query'] = array(
				'relation' => 'OR',
			);

			foreach ( $args['taxonomy'] as $key => $value ) {
				$args['tax_query'][] = array(
					'taxonomy' => $key,
					'terms' => is_array( $value ) ? $value : array(),
					'field' => 'term_id',
				);
			}
		}

		if ( isset( $args['after'] ) ) {
			$date = date('Y-m-d', strtotime( $args['after'] ) );
			add_filter( 'posts_where', function( $where ) use ( $date ) {
				$where .= " AND post_date > '$date'";
				return $where;
			} );
		}

		if ( isset( $args['before'] ) ) {
			$date = date('Y-m-d', strtotime( $args['before'] ) );
			add_filter( 'posts_where', function( $where ) use ( $date ) {
				$where .= " AND post_date < '$date'";
				return $where;
			} );
		}

		if ( isset( $args['author'] ) ) {
			// WordPress only allows a single author to be excluded. We are not
			// allowing any author exculsions to be accepted.
			$r = array_filter( (array)$args['author'], function( $author ) {
				return $author > 0;
			} );
			$args['author'] = implode( ',', $r );
		}

		if ( isset( $args['cat'] ) ) {
			$args['cat'] = implode( ',', (array)$args['cat'] );
		}

		$valid_orders = array(
			'none',
			'ID',
			'author',
			'title',
			'name',
			'date',
			'modified',
			'parent',
			'rand',
			'comment_count',
			'menu_order',
			'post__in',
		);

		if ( isset( $args['orderby'] ) ) {
			$r = array_filter( (array)$args['orderby'], function( $orderby ) use ($valid_orders) {
				return in_array( $orderby, $valid_orders );
			} );
			$args['orderby'] = implode( ' ', $r );
		}

		if ( isset( $args['per_page'] ) ) {
			if ( $args['per_page'] >= 1 and $args['per_page'] <= MAX_POSTS_PER_PAGE ) {
				$args['posts_per_page'] = (int)$args['per_page'];
			}
		}

		if ( isset ( $args['paged'] ) ) {
			$args['found_posts'] = true;
		}

		$get_posts = new \WP_Query( array_merge( $defaults, $args ) );
		remove_action('parse_query', $force_public_post_stati );
		return $get_posts;
	}

	public function get_users( $id = null ) {
		$users = array();
		if ( $id ) {
			$users[] = self::format_user( get_user_by( 'id', $id ) );
			return compact( 'users' );
		}

		return WP_API_BASE . '/users/' . $id;
	}

	public function get_taxonomies( $name = null ) {
		$args = array(
			'public' => true,
		);

		if ( ! is_null( $name ) ) {
			$args['name'] = $name;
		}

		$t = get_taxonomies( $args, 'object' );
		$args = $this->app->request()->get();
		$taxonomies = array();
		foreach ( $t as $taxonomy ) {
			if ( isset( $args['in'] ) ) {
				if ( ! in_array( $taxonomy->name, (array)$args['in'] ) ) {
					continue;
				}
			}

			if ( isset( $args['post_type'] ) ) {
				if ( 0 === count( array_intersect( $taxonomy->object_type, (array)$args['post_type'] ) ) ) {
					continue;
				}
			}

			$taxonomies[] = $this->format_taxonomy( $taxonomy );
		}

		return compact( 'taxonomies' );
	}

	public function get_terms( $name, $term_id = null ) {
		return WP_API_BASE . '/taxonomies/' . $name . '/terms/' . $term_id;
	}

	/**
	 * @param $taxonomy
	 * @return Array
	 */
	public function format_taxonomy( $taxonomy ) {
		return array(
			'name'         => $taxonomy->name,
			'post_types'   => $taxonomy->object_type,
			'hierarchical' => $taxonomy->hierarchical,
			'query_var'    => $taxonomy->query_var,
			'labels'       => array(
				'name'          => $taxonomy->labels->name,
				'singular_name' => $taxonomy->labels->singular_name,
			),
			'meta'         => (object)array(),
		);
	}

	public function format_term() {

	}

	/**
	 * Format post data
	 * @param \WP_Post $post
	 * @return Array Formatted post data
	 */
	public function format_post( \WP_Post $post ) {
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$media = array();
		$meta = array();

		// get direct post attachments
		$attachments = get_posts( array(
			'post_parent' => $post->ID,
			'post_mime_type' => 'image',
			'post_type' => 'attachment',
		) );
		foreach ( $attachments as $attachment ) {
			$media[$attachment->ID] = self::format_image_media_item( $attachment );
		}

		// check post content for gallery shortcode
		if ( $gallery_data = $this->get_gallery_data( $post ) ) {
			$gallery_meta = array();
			foreach ( $gallery_data as $gallery_key => $gallery ) {
				$gallery_id = ! empty( $gallery['id'] ) ? intval( $gallery['id'] ) : $post->ID;
				$order      = strtoupper( $gallery['order'] );
				$orderby    = implode( ' ', $gallery['orderby'] );
				$include    = ! empty( $gallery['include'] ) ? $gallery['include'] : array();

				if ( ! empty( $gallery['ids'] ) ) {
					// 'ids' is explicitly ordered, unless you specify otherwise.
					if ( empty( $gallery['orderby'] ) ) {
						$orderby = 'post__in';
					}
					$include = $gallery['ids'];
				}

				if ( ! empty( $gallery['order'] ) && 'RAND' == strtoupper( $gallery['order'] ) ) {
					$orderby = 'none';
				}

				$attachments_args = array(
					'post_type' => 'attachment',
					'post_mime_type' => 'image',
					'order' => $order,
					'orderby' => $orderby,
				);
				$attachments = array();
				if ( ! empty( $include ) ) {
					$attachments_args = array_merge( $attachments_args, array(
						'include' => $include,
					) );
					$_attachments = get_posts( $attachments_args );

					foreach ( $_attachments as $key => $val ) {
						$attachments[$val->ID] = $_attachments[$key];
					}
				} elseif ( !empty($exclude) ) {
					$attachments_args = array_merge( $attachments_args, array(
						'post_parent' => $gallery_id,
						'exclude' => $exclude,
					) );
					$attachments = get_children( $attachments_args );
				} else {
					$attachments_args = array_merge( $attachments_args, array(
						'post_parent' => $gallery_id,
					) );
					$attachments = get_children( $attachments_args );
				}

				$ids = array();
				foreach ( $attachments as $attachment ) {
					$media[$attachment->ID] = self::format_image_media_item( $attachment );
					$ids[] = $attachment->ID;
				}

				$gallery_meta[] = array(
					'ids' => $ids,
					'orderby' => $gallery['orderby'],
					'order' => $order,
				);
			}

			$meta['gallery'] = $gallery_meta;
		}

		if ( $thumbnail_id = get_post_thumbnail_id( $post->ID ) ) {
			$meta['featured_image'] = (int)$thumbnail_id;
		}

		$data = array(
			'id'               => $post->ID,
			'id_str'           => (string)$post->ID,
			'type'             => $post->post_type,
			'permalink'        => get_permalink( $post ),
			'parent'           => $post->post_parent,
			'parent_str'       => (string)$post->post_parent,
			'date'             => get_post_time( 'c', true, $post ),
			'modified'         => get_post_modified_time( 'c', true, $post ),
			'status'           => $post->post_status,
			'comment_status'   => $post->comment_status,
			'comment_count'    => (int)$post->comment_count,
			'menu_order'       => $post->menu_order,
			'title'            => $post->post_title,
			'name'             => $post->post_name,
			'excerpt_raw'      => $post->post_excerpt,
			'excerpt'          => apply_filters( 'the_excerpt', get_the_excerpt() ),
			'content_raw'      => $post->post_content,
			'content'          => apply_filters( 'the_content', get_the_content() ),
			'content_filtered' => $post->post_content_filtered,
			'mime_type'        => $post->post_mime_type,
			'meta'             => (object)$meta,
			'media'            => array_values( $media ),
		);

		wp_reset_postdata();

		return $data;
	}

	public function get_gallery_data( \WP_Post $post ) {
		global $shortcode_tags;

		if ( !isset( $shortcode_tags['gallery'] ) )
			return array();

		// setting shortcode tags to 'gallery' only
		$backup_shortcode_tags = $shortcode_tags;
		$shortcode_tags = array( 'gallery' => $shortcode_tags['gallery'] );
		$pattern = get_shortcode_regex();
		$shortcode_tags = $backup_shortcode_tags;

		$matches = array();
		preg_match_all( "/$pattern/s", $post->post_content, $matches );

		$gallery_data = array();
		foreach ( $matches[3] as $gallery_args ) {
			$attrs = shortcode_parse_atts( $gallery_args );
			$gallery_data[] = self::parse_gallery_attrs( $attrs );
		}

		return $gallery_data;
	}

	public static function parse_gallery_attrs( $gallery_attrs ) {

		$clean_val = function( $val ) {
			$trimmed = trim( $val );
			return ( is_numeric( $trimmed ) ? (int)$trimmed : $trimmed );
		};

		$params = array(
			'id',
			'ids',
			'orderby',
			'order',
			'include',
			'exclude',
		);
		$array_params = array(
			'ids',
			'orderby',
			'include',
			'exclude',
		);

		if ( empty( $gallery_attrs['order'] ) ) {
			$gallery_attrs['order'] = 'ASC';
		}
		if ( empty( $gallery_attrs['orderby'] ) ) {
			$gallery_attrs['orderby'] = 'menu_order, ID';
		}

		$gallery = array();
		foreach ( $params as $param ) {
			if ( !empty( $gallery_attrs[$param] ) ) {
				if ( in_array( $param, $array_params ) ) {
					$gallery_param_array = explode( ',', $gallery_attrs[$param] );
					$gallery_param_array = array_map( $clean_val, $gallery_param_array );
					$gallery[$param] = $gallery_param_array;
				}
				else {
					$gallery[$param] = $clean_val( $gallery_attrs[$param] );
				}
			}
		}

		return $gallery;
	}

	/**
	 * Format user data
	 * @param \WP_User $user
	 * @return Array Formatted user data
	 */
	public function format_user( \WP_User $user ) {

		$data = array(
			'id' => $user->ID,
			'id_str' => (string)$user->ID,
			'nicename' => $user->data->user_nicename,
			'display_name' => $user->data->display_name,
			'user_url' => $user->data->user_url,

			'posts_url' => 'http=>//example.com/author/john-doe/',
			'avatar' => array(
				array(
					'url' => 'http=>//1.gravatar.com/avatar/7a10459e7210f3bbaf2a75351255d9a3?s=64',
					'width' => 64,
					'height' => 64,
				),
			),
			'meta' => array()
		);

		return $data;
	}

	public function get_rewrite_rules() {
		$base_url = home_url( '/' );
		$rewrite_rules = array();

		$rules = get_option( 'rewrite_rules', array() );
		foreach ( $rules as $regex => $query ) {
			$patterns = array( '|index\.php\?&?|', '|\$matches\[(\d+)\]|' );
			$replacements = array( '', '\$$1');

			$rewrite_rules[] = array(
				'regex' => $regex,
				'query_expression' => preg_replace( $patterns, $replacements, $query ),
			);
		}

		return compact( 'base_url', 'rewrite_rules' );
	}

	/**
	 * @param \WP_Post $post
	 * @return Array
	 */
	public static function format_image_media_item( \WP_Post $post ) {
		$meta = wp_get_attachment_metadata( $post->ID );

		if ( isset( $meta['sizes'] ) and is_array( $meta['sizes'] ) ) {
			$upload_dir = wp_upload_dir();

			$sizes = array(
				array(
					'height' => $meta['height'],
					'name'   => 'full',
					'url'    => trailingslashit( $upload_dir['baseurl'] ) . $meta['file'],
					'width'  => $meta['width'],
				),
			);

			$attachment_upload_date = dirname($meta['file']);

			foreach ( $meta['sizes'] as $size => $data ) {
				$sizes[] = array(
					'height' => $data['height'],
					'name'   => $size,
					'url'    => trailingslashit( $upload_dir['baseurl'] ) . trailingslashit( $attachment_upload_date ) . $data['file'],
					'width'  => $data['width'],
				);
			}
		}

		return array(
			'id'        => $post->ID,
			'id_str'    => (string)$post->ID,
			'mime_type' => $post->post_mime_type, 
			'alt_text'  => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'sizes'     => $sizes,
		);
	}

}
