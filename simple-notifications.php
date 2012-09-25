<?php
/* 
Plugin Name: Simple Notifications
Plugin URI: TBD
Description: Display notifications to your users.
Version: 0.1.alpha-201209
Author: Ryan Imel
Author URI: http://wpcandy.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/


/**
 * Simple Notifications Class
 */
Class RWI_Simple_Notifications {

	/* 
	 * Static property to hold our singleton instance
	 * @var SimpleBadges
	 */
	static $instance = false;


	/*
	 * This is our constructor, which is private to force the use of
	 * getInstance() to make this a singleton
	 * 
	 * @return SimpleBadges
	*/
	public function __construct() {

		add_action( 'init', array( $this, 'create_content_types' ) );
		add_action( 'admin_head', array( $this, 'style' ) );
		add_action( 'wp_head', array( $this, 'style' ) );
		add_filter( 'manage_edit-sn_message_columns', array( $this, 'set_log_columns' ) );
		add_filter( 'manage_sn_message_posts_custom_column', array( $this, 'set_log_custom_columns' ), 10, 2 );
		
		add_action( 'pre_get_posts', array( $this, 'modify_query' ) );
		add_action( 'admin_menu', array( $this, 'permissions_redirect' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 1000 );
		add_action('admin_notices', array( $this, 'display' ) );

		add_action( 'admin_footer', array( $this, 'sledge' ) );
		
	}
	
	
	// add links/menus to the admin bar
	public function admin_bar() {
		global $wp_admin_bar;
		
		$args = array(
			'id'		=> 'simple_notifications',
			'parent'	=> 'top-secondary',
			'title'		=> '<span class="ab-icon"></span>',
			'href'		=> admin_url( 'edit.php?post_type=sn_message' ),
			// 'group'		=> true,
			'meta'		=> array(
				'class'		=> 'active',
				'tabindex'	=> 200,
				'html'		=> '<div class="inside"><div class="wrap">Boomshakalaka.</div></div>'
			)
		);
		
		$wp_admin_bar->add_node( $args );
		
	}


	/**
	 * Receive a notification message, prep it for posting.
	 */
	public function message( $title, $desc, $for = 'everyone', $type = 'banner' ) {

		if ( $title && $desc ) {
			
			if ( $for == 'everyone' ) {
				$for = 1;
				$everyone = true;
			} else {
				$everyone = false;
			}

			$this->post( $title, $desc, $for, $everyone, $type );
		}

	}
	
	
	/**
	 * Post a notification
	 */
	public function post( $title, $desc, $for, $everyone, $type ) {
		
		$args = array(
			'post_title'	=> $title,
			'post_content'	=> $desc,
			'post_status'	=> 'publish',
			'post_type'		=> 'sn_message',
		);

		$post_id = wp_insert_post( $args );

		$this->add_type( $post_id, $type );
		if ( $everyone == true ) {
			$this->add_who_for( $post_id, 'everyone' );
		} else {
			foreach( $for as $piece ) {
				$this->add_who_for( $post_id, $piece );
			}
		}

	}
	
	
	/**
	 * Save the type of notification.
	 */
	private function add_type( $post_id, $type ) {
		add_post_meta( $post_id, '_simplenotifications_type', $type );
	}
	
	
	/**
	 * Get the type of notification.
	 */
	private function get_type( $post_id ) {
		return get_post_meta( $post_id, '_simplenotifications_type' );
	}
	
	
	/**
	 * Our own method to protect the meta format.
	 */
	private function add_who_for( $post_id, $data ) {
		add_post_meta( $post_id, '_simplenotifications_for', $data );
	}
	
	
	/**
	 * Get the "who for" data based on post ID.
	 */
	private function get_who_for( $post_id ) {
		return get_post_meta( $post_id, '_simplenotifications_for' );
	}
	
	
	/**
	 * Make a notification as viewed.
	 */
	private function mark_viewed( $post_id ) {
		update_post_meta( $post_id, '_simplenotifications_viewed', true );
	}
	
	
	/**
	 * Get a view status.
	 */
	private function get_view_status( $post_id ) {
		return get_post_meta( $post_id, '_simplenotifications_viewed' );
	}
	
	
	/**
	 * Package up a notification set to display.
	 */
	public function package() {
		$packages = null;
		
		$notifications = $this->get_notifications();
		
		if ( is_array( $notifications ) ) {
			foreach( $notifications as $notification ) {
				$viewed = $this->get_view_status( $notification['id'] );
				$type = $notification['type'][0];

				if ( $viewed == true )  {
					// Do nothing.
				} else {
					$packages[] = $notification;
				}

			}
		}
		
		return $packages;
	}


	/**
	 * Display the notices.
	 */
	public function display() {
		
		$package = $this->package();
		$id = $package[0]['id'];
		$type = $package[0]['type'][0];
		$message = $package[0]['message'];
		
		if ( ! $package )
			return;
		
		switch ( $type ) {
			case 'banner':
				echo '<div class="updated"><p>' . $message . '</p></div>';
				if ( $type == 'banner' ) { $this->mark_viewed( $id ); }
				break;
			case 'alert':
				echo '<div class="error"><p>' . $message . '</p></div>';
				if ( $type == 'banner' ) { $this->mark_viewed( $id ); }
				break;
		}
		
	}
	
	
	/**
	 * Get a list of notifications.
	 */
	private function get_notifications() {
		
		$notifications = null;
		$user = wp_get_current_user();
		$user_id = $user->ID;
		
		$args = array(
			'numberposts'		=> 100,
			'post_type'			=> 'sn_message',
			'meta_query'		=> array(
				array(
					'relation'	=> 'OR',
					array(
						'key'	=> '_simplenotifications_for',
						'value'	=> $user_id
					),
					array(
						'key'	=> '_simplenotifications_for',
						'value'	=> 'everyone'
					)
				)
			)
		);
		
		$posts = get_posts( $args );
		
		foreach( $posts as $post ) : setup_postdata( $post ); 
			$notifications[] = array(
				'id'		=> $post->ID,
				'title'		=> $post->post_title,
				'message'	=> $post->post_content,
				'type'		=> $this->get_type( $post->ID )
			);
		endforeach;
		
		return $notifications;
	}


	/**
	 * Create the notifications post type we need.
	 */
	public function create_content_types() {

		// Notification message post type
		register_post_type( 'sn_message',
			array(

				'labels' => array(

					'name' => __( 'Notifications' ),
					'singular_name' => __( 'Notification' ),
					'add_new' => __( 'Add New' ),
					'all_items' => __( 'Notifications' ),
					'add_new_item' => __( 'Add New Notification' ),
					'edit_item' => __( 'Edit Notification' ),
					'new_item' => __( 'New Notification' ),
					'view_item' => __( 'View Notification' ),
					'search_items' => __( 'Search Notifications' ),
					'not_found' => __( 'Notifications not found.' ),
					'not_found_in_trash' => __( 'Notifications not found in Trash' ),
					'parent_item_colon' => __( 'Parent Notification' ),
					'menu_name' => __( 'Notifications' )

				),

				'description' => 'Provided by the Simple Notifications plugin.',
				'public' => false,
				'exclude_from_search' => true,	 			
				'publicly_queryable' => false,
				'show_ui' => true,
				'show_in_nav_menus' => true,
				'show_in_admin_bar' => false,
				'show_in_menu'	=> 'index.php',
				'menu_position' => 200,
				'menu_icon' => plugins_url( 'icon.png', __FILE__ ),
				'capabilities' => array(
					'publish_posts' => 'manage_options',
					'edit_posts' => 'read',
					'edit_others_posts' => 'manage_options',
					'delete_posts' => 'manage_options',
					'read_private_posts' => 'manage_options',
					'edit_post' => 'manage_options',
					'delete_post' => 'manage_options',
					'read_post' => 'read'
				),
				'hierarchical' => false,
				'supports'	=> array( 'title', 'editor', 'author' ),
				'has_archive' => false,
				'rewrite'	=> false,
				'can_export' => false,

	 		)
	 	);

	}
	
	
	/**
	 * Sledge for testing purposes.
	 */
	public function sledge( $sledge ) {
		print_r( $sledge );
	}
	
	
	/**
	 * Add styles to the admin head, only sometimes.
	 */
	public function style() {
		global $typenow, $pagenow;
		
		echo '<style>
		#wp-admin-bar-simple_notifications {
			position: relative;
		}
		#wp-admin-bar-simple_notifications .ab-icon {
			background: url(' . plugins_url( 'icon.png', __FILE__ ) . ') center 2px no-repeat;
		}
		#wp-admin-bar-simple_notifications .inside {
			display: none;
			padding: 28px 0 0 0;
			position: absolute;
			right: -1px;
			top: 0;
		}
		#wp-admin-bar-simple_notifications .inside .wrap {
			background: #fff;
			padding: 15px 10px 5px 10px
		}
		#wp-admin-bar-simple_notifications:hover .inside {
			display: block;
		}
		#wp-admin-bar-simple_notifications.active {
			background: #21759b !important;
		}
		#wp-admin-bar-simple_notifications.active .ab-icon {
			background: url(' . plugins_url( 'icon-active.png', __FILE__ ) . ') center 2px no-repeat;
		}';
		
		if ( ( $pagenow == 'edit.php' || $pagenow == 'post.php' ) && $typenow == 'sn_message' ) {
			echo '
			.subsubsub,
			.add-new-h2 {
				display: none;
			}
			.wp-list-table #user {
				width: 30%
			}';
		}
		
		echo '</style>';
		
	}
	
	
	/**
	 * Set new columns for the manage screen.
	 */ 
	public function set_log_columns( $columns ) {

		unset( $columns['date'] );
		unset( $columns['title'] );
		unset( $columns['author'] );
		
		$columns = array_merge( $columns, 
			array(
				//'post_id'	=> __( 'Post ID' ),
				'user'		=> __( 'For' ),
				'message'	=> __( 'Message' ),
			)
		);
		
		if ( ! current_user_can( 'manage_options' ) )
			unset( $columns['user'] );

	    return $columns;

	}
	
	
	/**
	 * Prepare the data for custom columns.
	 */
	public function set_log_custom_columns( $column_name, $post_id ) {
		
		$our_post = get_post( $post_id );
		$meta = $this->get_who_for( $post_id );
		
		if ( $meta == 'everyone' ) {
			$for = 'Everyone';
		} else {
			foreach ( $meta as $piece ) {
				$user = get_user_by( 'id', $piece );
				$for[] = $user->display_name;
			}
		}

	    switch ( $column_name ) {
			
			case 'post_id':
				echo get_the_ID();
				break;
			
			case 'user' :
				echo implode( ", ", $for );
				break;
				
	        case 'message' :
				echo '<strong>' . $our_post->post_title . '</strong><br />' . $our_post->post_content;
	            break;
				
			case 'time' :
				echo human_time_diff( get_the_time( 'U', $post_id ), current_time( 'timestamp' ) ) . ' ago';
				break;

	        default:
	    }
		
	}
	
	
	/**
	 * Modify edit screen query for notification messages.
	 */
	public function modify_query( $query ) {
		global $typenow, $pagenow;
		$user = wp_get_current_user();
		$user_id = $user->ID;
		
		if ( $pagenow == 'edit.php' && $typenow == 'sn_message' ) {
			
			if ( $query->is_main_query() && ! current_user_can( 'manage_options' ) ) {
				
				$query->set( 'meta_value', array( 'everyone', $user_id ) );
				$query->set( 'author', '' );

			}
		}
	}
	
	
	/**
	 * If someone directly requests an edit screen for notification
	 * messages, direct them back home.
	 */
	public function permissions_redirect() {
		$result = stripos( $_SERVER['REQUEST_URI'], 'post-new.php?post_type=sn_message' );
				
		if ( $result !== false ) {
			wp_redirect(get_option('siteurl') . '/wp-admin/index.php?permissions_error=true');
		}
		
	}

}

$SimpleNotifications = new RWI_Simple_Notifications;



add_action( 'init', 'rwi_test_notifications' );

function rwi_test_notifications() {
	global $SimpleNotifications;
	
	$user = wp_get_current_user();
	$user_id = $user->ID;
	$user_id = 1;
	$for = array( 1, 2 );
	$title = 'Test notification.';
	$desc = '<strong>3 alerts</strong> are waiting for your attention.';
	
	if ( ! is_admin() )
		$SimpleNotifications->message( $title, $desc, $for, 'banner' );

}


