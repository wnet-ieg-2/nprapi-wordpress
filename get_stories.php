<?php
/**
 * The class DS_NPR_API and related functions for getting stories from the API
 */

require_once( NPRSTORY_PLUGIN_DIR . 'get_stories_ui.php' );
require_once( NPRSTORY_PLUGIN_DIR . 'classes/NPRAPIWordpress.php');

class DS_NPR_API {
	var $created_message = '';

	/**
	 * What is the post type that pulled stories should be created as?
	 *
	 * @return string The post type
	 */
	public static function nprstory_get_pull_post_type() {
		$pull_post_type = get_option( 'ds_npr_pull_post_type' );
		if ( empty( $pull_post_type ) ) {
			$pull_post_type = 'post';
		}
		return $pull_post_type;
	}

	/**
	 * The cron job to pull stories from the API
	 */
	public static function nprstory_cron_pull() {
		// here we should get the list of IDs/full urls that need to be checked hourly
		//because this is run on cron, and may be fired off by an non-admin, we need to load a bunch of stuff
		require_once( ABSPATH . 'wp-admin/includes/file.php');
		require_once( ABSPATH . 'wp-admin/includes/media.php');

		// This is debug code. It may be save future devs some time; please keep it around.
		/*
		$now = gmDate("D, d M Y G:i:s O ");
		error_log("right now the time is -- ".$now); // debug use
		*/

		// here we go.
		$num =  get_option( 'ds_npr_num' );
		for ($i=0; $i<$num; $i++ ) {
			$api = new NPRAPIWordpress();
			$q = 'ds_npr_query_' . $i;
			$query_string = get_option( $q );
			if ( ! empty( $query_string ) ) {
				nprstory_error_log( 'Cron '. $i . ' querying NPR API for ' . $query_string );
				//if the query string contains the pull url and 'query', just make request from the API
				if ( stristr( $query_string, get_option( 'ds_npr_api_pull_url' ) ) && stristr( $query_string,'query' ) ) {
					$api->query_by_url( $query_string );
				} else {
					/*
					 * If the string doesn't contain the base URL, try to query using an ID
					 * but only if the query string is not a URL in its own right.
					 */
					if ( stristr( $query_string, 'http:' ) || stristr( $query_string, 'https:' ) ) {
						error_log( sprintf(
							'Not going to run query because the query string %1$s contains http: or https: and is not pointing to the pullURL %2$s',
							var_export( $query_string, true ),
							var_export( get_option( 'ds_npr_api_pull_url' ), true )
						) ); // debug use
					} else {
						$params = array ('id' => $query_string, 'apiKey' => get_option( 'ds_npr_api_key' ));
						$api->request( $params, 'query', get_option( 'ds_npr_api_pull_url' ) );
					}
				}
				$api->parse();
                try {
                    if ( empty( $api->message ) || $api->message->level != 'warning' ) {
                        //check the publish flag and send that along.
                        $pub_flag = FALSE;
                        $pub_option = get_option('ds_npr_query_publish_'.$i);
                        if ( $pub_option == 'Publish' ) {
                            $pub_flag = TRUE;
                        }
                        $story = $api->update_posts_from_stories($pub_flag, $i);
                    } else {
                        if ( empty($story) ) {
                            error_log('NPR Story API: not going to save story.  Query '. $query_string .' returned an error '.$api->message->id. ' error'); // debug use
                        }
                    }
                }
                catch (Exception $e) {
                    error_log('NPR Story API: error in ' .  __FUNCTION__ . ' like this :'. $e); // debug use
                }
			}
		}
	}

	/**
	 * Function to convert an alleged NPR story URL or ID into a story ID, then request it
	 */
    public function load_page_hook() {
		// find the input that is allegedly a story id
		// We validate these later
        if ( isset( $_POST ) && isset( $_POST[ 'story_id' ] ) ) {
            $story_id =  $_POST[ 'story_id' ] ;
            if ( isset( $_POST['publishNow'] ) ){
            	$publish = true;
            }
            if ( isset($_POST['createDaft'] ) ){
            	$publish = false;
            }
			if ( ! check_admin_referer('nprstory_nonce_story_id', 'nprstory_nonce_story_id_field') ) {
				wp_die(
					__('Nonce did not verify in DS_NPR_API::load_page_hook. Are you sure you should be doing this?'),
					__('NPR Story API Error'),
					403
				);
			}
        } else if ( isset( $_GET['story_id']) && isset( $_GET['create_draft'] ) ) {
            $story_id = $_GET['story_id'];
        }

		// if the current user shouldn't be doing this, fail
		if ( ! current_user_can('edit_posts') ) {
			wp_die(
				__('You do not have permission to edit posts, and therefore you do not have permission to pull posts from the NPR API'),
				__('NPR Story API Error'),
				403
			);
		}

		// try to get the ID of the story from the URL
        if ( isset( $story_id ) ) {
            //check to see if we got an ID or a URL
            if ( is_numeric( $story_id ) ) {
                if (strlen($story_id) >= 8) {
                    $story_id = $story_id;
				}
            } else {
                // url format: /yyyy/mm/dd/id
				// url format: /blogs/name/yyyy/mm/dd/id
				$story_id = preg_replace( '/https?\:\/\/[^\s\/]*npr\.org\/((([^\/]*\/){3,5})([0-9]{8,12}))\/.*/', '$4', $story_id );
				if ( ! is_numeric( $story_id ) ) {
				    // url format: /templates/story/story.php?storyId=id
					$story_id = preg_replace( '/https?\:\/\/[^\s\/]*npr\.org\/([^&\s\<]*storyId\=([0-9]+)).*/', '$2', $story_id );
				}
            }
		}

		// Don't do anything if $story_id isn't an ID
		if ( isset( $story_id ) && is_numeric( $story_id ) ) {
			// start the API class
            // todo: check that the API key is actually set
            $api = new NPRAPIWordpress();

            $params = array( 'id' => $story_id, 'apiKey' => get_option( 'ds_npr_api_key' ) );
            $api->request( $params, 'query', get_option( 'ds_npr_api_pull_url' ) );
            $api->parse();

            if ( empty( $api->message ) || $api->message->level != 'warning') {
                $post_id = $api->update_posts_from_stories($publish);
                if ( ! empty( $post_id ) ) {
                    //redirect to the edit page if we just updated one story
                    $post_link = admin_url( 'post.php?action=edit&post=' . $post_id );
                    wp_redirect( $post_link );
            	}
            } else {
                if ( empty($story) ) {
                    $xml = simplexml_load_string( $api->xml );
                    nprstory_show_message('Error retrieving story for id = ' . $story_id . '<br> API error ='.$api->message->id . '<br> API Message ='. $xml->message->text , TRUE);
                    error_log('Not going to save the return from query for story_id='. $story_id .', we got an error='.$api->message->id. ' from the NPR Story API'); // debug use
                    return;
	            }
            }
        }
    }

	/**
	 * Class constructor that hooks up the menu and the "Get NPR Stories" page action.
	 */
    public function __construct() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
        add_action( 'load-posts_page_get-npr-stories', array( $this, 'load_page_hook' ) );
    }

	/**
	 * Register the admin menu for "Get NPR Stories"
	 */
    public function admin_menu() {
        add_posts_page( 'Get NPR Stories', 'Get NPR Stories', 'edit_posts', 'get-npr-stories',   'nprstory_get_stories' );
    }

}

new DS_NPR_API;
