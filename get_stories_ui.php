<?php
/**
 * Functions related to the user interface for fetching stories from the API
 */

/**
 * This will turn on the update story column for all posts
 * yes, this is intentionally commented out
 *
add_filter('manage_posts_columns', 'nprstory_update_column');

function nprstory_update_column($defaults){
	$pull_post_type = DS_NPR_API::nprstory_get_pull_post_type();
  global $post_type;
  if($post_type == $pull_post_type) {
		$defaults['update_story'] = 'Update Story';
  }
	return $defaults;
}
/**/

// Add the update story column to the page listing the posts for the pull-type
add_filter( 'manage_edit-' . DS_NPR_API::nprstory_get_pull_post_type() . '_columns', 'nprstory_add_new_story_columns');
function nprstory_add_new_story_columns( $cols ) {
	$cols['update_story'] = 'Update Story';
	return $cols;
}

add_action( 'manage_posts_custom_column', 'nprstory_update_column_content', 10, 2 );
function nprstory_update_column_content ( $column_name, $post_ID ) {
	if ( $column_name == 'update_story' ) {
		$retrieved = get_post_meta( $post_ID, NPR_RETRIEVED_STORY_META_KEY, true );
		if ($retrieved) {
			$api_id = get_post_meta( $post_ID, NPR_STORY_ID_META_KEY, TRUE );
      $pull_post_type = DS_NPR_API::nprstory_get_pull_post_type();
      $post_type_arg = ($pull_post_type != 'post') ? "&post_type=$pull_post_type" : '';
			echo ( '<a href="' . admin_url( 'edit.php?page=get-npr-stories&story_id=' .$api_id . $post_type_arg ) . '"> Update </a>' );
		}
	}
}

//add the bulk action to the dropdown on the post admin page
add_action( 'admin_footer-edit.php', 'nprstory_bulk_action_update_dropdown' );
function nprstory_bulk_action_update_dropdown() {
	$pull_post_type = DS_NPR_API::nprstory_get_pull_post_type();
    global $post_type;
    if( $post_type == $pull_post_type ) {
    ?>
    <script type="text/javascript">
      jQuery(document).ready(function() {
        jQuery('<option>').val('updateNprStory').text('<?php _e('Update NPR Story')?>').appendTo("select[name='action']");
        jQuery('<option>').val('updateNprStory').text('<?php _e('Update NPR Story')?>').appendTo("select[name='action2']");
      });
    </script>
    <?php
    }
}

//do the new bulk action
add_action( 'load-edit.php', 'nprstory_bulk_action_update_action' );
function nprstory_bulk_action_update_action() {
  // 1. get the action
  $wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
  $action = $wp_list_table->current_action();

  switch( $action ) {
    // 3. Perform the action
    case 'updateNprStory':

      // make sure ids are submitted.  depending on the resource type, this may be 'media' or 'ids'
        if ( isset( $_REQUEST['post'] ) ) {
            $post_ids = array_map( 'intval', $_REQUEST['post'] );
        }

        $exported = 0;
        foreach( $post_ids as $post_id ) {
            $api_id = get_post_meta( $post_id, NPR_STORY_ID_META_KEY, TRUE );
			
			// don't run API queries for posts that have no ID
			// @todo: why do some posts have no ID
			// @todo: oh, it's only imported drafts that don't have an ID
			if ( !empty( $api_id ) ) {
				$api = new NPRAPIWordpress();
				$params = array( 'id' => $api_id, 'apiKey' => get_option( 'ds_npr_api_key' ) );
				$api->request( $params, 'query', get_option( 'ds_npr_api_pull_url' ) );
				$api->parse();
				if ( empty( $api->message ) || $api->message->level != 'warning' ){
					nprstory_error_log( 'updating story for API ID='.$api_id );
					$story = $api->update_posts_from_stories();
				}
			} else {
			}
        }

      // build the redirect url
      //$sendback = add_query_arg( array('exported' => $exported, 'ids' => join(',', $post_ids) ), $sendback );
        break;
        default:
            return;
    }

    // ...

    // 4. Redirect client
    //wp_redirect($sendback);
    //exit();
}

function nprstory_get_stories() {
    global $is_IE;
    $api_key =  get_option( 'ds_npr_api_key' );
    $pull_url = get_option( 'ds_npr_api_pull_url' );
?>
        <div class="wrap">
            <h2>Get NPR Stories</h2>
            <?php if ( ! $api_key ) : nprstory_show_message ('You do not currently have an API Key set.  <a href="' . admin_url('options-general.php?page=ds_npr_api') . '">Set your API Key here.</a>', TRUE);?>
            <?php endif;
						if ( ! $pull_url ) : nprstory_show_message ('You do not currently have an API Pull URL set.  <a href="' . admin_url('options-general.php?page=ds_npr_api') . '">Set your API Pull URL here.</a>', TRUE);?>
            <?php endif;

			// Get the story ID from the URL, then paste it into the input's value field with esc_attr
            $story_id = '';
            if ( ( isset( $_POST ) and isset( $_POST[ 'story_id' ] ) ) || ( isset( $_GET) && isset( $_GET['story_id'] ) ) ) {
                if ( ! empty( $_POST['story_id'] ) ){
                	$story_id = $_POST['story_id'];
                }
                if ( ! empty($_GET['story_id'] ) ){
                	$story_id = $_GET['story_id'];
                }
            }
			?>

            <div style="float: left;">
                <form action="" method="POST">
                    Enter an NPR Story ID or URL: <input type="text" name="story_id" value="<?php echo esc_attr($story_id)?>" />
					<?php wp_nonce_field('nprstory_nonce_story_id', 'nprstory_nonce_story_id_field'); ?>
                    <input type="submit" name='createDaft' value="Create Draft" />
                    <input type="submit" name='publishNow' value="Publish Now" />
                </form>
            </div>

       </div>
        <?php
}
