<?php

  require_once( dirname(__FILE__) . '../../class.syptusclient.php' );

  Class SyptusSetup {

      public static function add_hooks() {

          $registered_routes = SyptusClient::get_registered_routes();

          $uri = $_SERVER['REQUEST_URI'];

          if (!empty($uri) && in_array($uri, $registered_routes)) {

              add_action('wp_insert_post_data', 'Syptus::filter_post_images', 99, 2);
          }
          else {
              add_action('rest_api_init', 'SyptusClient::rest_api_init');
              add_action('admin_menu', function() {
                  add_options_page('Syptus CMP', 'Syptus CMP', 'administrator', 'syptus-plugin-slug', 'SyptusClient::syptus_plugin_page');
              });

              add_action('admin_init', function() {
                  register_setting('syptus-plugin-settings', 'syptussecret');
                  register_setting('syptus-plugin-settings', 'syptuscid');
              });
              add_action('template_redirect', 'SyptusClient::clear_session');
              add_action('admin_post_syptus_setup_hook', 'SyptusClient::setup');
              add_action('init', 'SyptusClient::register_session');
              add_action('add_meta_boxes', 'SyptusClient::syp_focus_keyword');
              add_action('save_post', 'SyptusClient::save_syp_focus_keyword');
              add_filter('post_updated_messages', 'SyptusClient::update_messages');
              
          }
      }

  }
  