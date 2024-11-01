<?php

  require_once( dirname(__FILE__) . '/class.syptusclient.php' );

  Class SyptusPush {

      private static function send_publish_notification($data, $post1) {

          $pub_response = array();

          $ID = $post1['ID'];
          $is_syp_asset = get_post_meta($ID, '_syp_asset_id', 1);
          if (!empty($is_syp_asset) && $post1['post_status'] == 'publish') {

              $pub_response['author'] = $post1->post_author;
              $author_id = get_post_field('post_author', $ID);
              $pub_response['email'] = get_the_author_meta('user_email', $author_id);

              $pub_response['title'] = $post1->post_title;
              $pub_response['permalink'] = get_permalink($ID);
              $pub_response['asset_id'] = $is_syp_asset;
              $pub_response['wp_post_id'] = $ID;
              $pub_response['publish_date'] = date('Y-m-d H:i:s');

              self::send_asset_update($pub_response);

              if (isset($_SESSION['_syp_pb_error'])) {
                  unset($data['post_status']);
              }
          }
          return $data;
      }
      
        private static function send_asset_update($data) {

          $auth = SyptusClient::generateToken();
          $url = SyptusClient::_SYP_BASE_URL . SyptusClient::_SYP_PUBLISH_DRAFT;

          $header = SyptusClient::getHTTPRequest($auth['token'], $data);

          $api_response_full = wp_remote_post($url, $header);


          if (is_wp_error($api_response_full)) {

              $error = $api_response_full->get_error_message();
              $msg = 'Code 999 ' . $error;
              $_SESSION['_syp_pb_error'] = urlencode($msg);
          }
          else {
              $api_response = SyptusClient::getAPIResponse($api_response_full);
              if ($api_response['code'] != 200) {
                  $error = $api_response['msg'];
                  $_SESSION['_syp_pb_error'] = urlencode($error);
                  // add_filter('redirect_post_location', array(__CLASS__, 'add_notice_query_var'), 99);
              }
              else {

                  $_SESSION['_syp_pb_ok'] = $data['asset_id'];
              }
          }
      }

  }
  