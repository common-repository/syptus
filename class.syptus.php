<?php

require_once dirname(__FILE__) . '/class.syptusclient.php';

class Syptus extends WP_REST_Controller
{

    const origin         = '';
    const rest_namespace = 'syptus';

    public function register_routes()
    {
        register_rest_route(self::rest_namespace, '/publishdraft', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'postDraft'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/create_post', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_post_ext'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/get_count_wp_posts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_count_wp_posts'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/get_wp_posts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_wp_posts'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/get_single_post', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_single_post'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/get_all_post_types', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_all_post_types'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));

        register_rest_route(self::rest_namespace, '/get_all_categories', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_all_categories'),
            'permission_callback' => array($this, 'user_sign_in'),
        ));
    }

    public function create_post_ext($request)
    {

        $payload = $request->get_params();

        if (isset($payload['create_new'])) {

            $payload['body'] = self::reprocess_post_content($payload['body'], $payload['title']);
            $user            = get_user_by('login', $payload['post_author']);
            if ($user) {
                $current_user_id = $user->ID;
            } else {
                $current_user_id = get_current_user_id();
            }

            // params for new post
            $my_post = array(
                'post_type'    => $payload['post_type'],
                'post_title'   => $payload['title'],
                'post_content' => $payload['body'],
                'post_status'  => $payload['doc_status'],
                'post_author'  => $current_user_id,
            );
            // post insertion

            $new_id = wp_insert_post($my_post);

            if (!$new_id) {
                $data['code']    = 500;
                $data['message'] = 'Error creating post';
                return $data;
            }

            //add post taxonomies if any
            $post_category = isset($payload['post_category']) ? $payload['post_category'] : null;
            if ($post_category) {
                $post_category = json_decode(base64_decode($post_category));

                foreach ($post_category as $arr) {
                    $taxonomy = $arr->t_name;
                    $val      = $arr->t_val;
//                      $data['code'] = 500;
                    //                      $data['message'] = $taxonomy;
                    //                      return $data;

                    wp_set_object_terms($new_id, $val, $taxonomy);
                }
            }
            //add post meta to distinguish this post from other ones created within wordpress
            add_post_meta($new_id, '_syp_asset_id', $payload['_syp_asset_id'], 1);

//add yoast meta if any

            if (isset($payload['seo_title'])) {
                update_post_meta($new_id, '_yoast_wpseo_title', $payload['seo_title']);
            }
            if (isset($payload['focus_keyword'])) {
                update_post_meta($new_id, '_yoast_wpseo_focuskw', $payload['focus_keyword']);
            }
            if (isset($payload['meta_description'])) {
                update_post_meta($new_id, '_yoast_wpseo_metadesc', $payload['meta_description']);
            }

// if featured image set - set as featured image
            if ($payload['featured_image']) {
                self::set_featured_image($new_id, $payload['featured_image']);
            }
            // set output id
            $out_post_id = $new_id;
            // set message
            $data['message']      = 'Post Created';
            $data['email']        = get_the_author_meta('user_email', $current_user_id);
            $data['permalink']    = get_permalink($new_id);
            $data['wp_post_id']   = $new_id;
            $data['publish_date'] = date('Y-m-d H:i:s');
            $data['code']         = 200;
            $data['post_id']      = $out_post_id;
        } else {

            // params for post update
            if (isset($payload['post_id'])) {
                $my_post = array(
                    'ID'         => $payload['post_id'],
                    'post_title' => $payload['title'],
                );
                wp_update_post($my_post);
            }

            if (isset($payload['seo_title'])) {
                update_post_meta($payload['post_id'], '_yoast_wpseo_title', $payload['seo_title']);
            }
            if (isset($payload['focus_keyword'])) {
                update_post_meta($payload['post_id'], '_yoast_wpseo_focuskw', $payload['focus_keyword']);
            }
            if (isset($payload['meta_description'])) {
                update_post_meta($payload['post_id'], '_yoast_wpseo_metadesc', $payload['meta_description']);
            }
            // update featured image
            if ($payload['featured_image']) {
                self::set_featured_image($payload['post_id'], $payload['featured_image']);
            }
            // update post if updates
            if ($payload['post_id']) {
                $out_post_id = $payload['post_id'];
            }
            // init message
            $data['message'] = 'Post Updated';
        }

        // set final return
        if (!$out_post_id) {
            $data['code']    = 500;
            $data['message'] = 'Error creating post';
        } else {
            $data['code']    = 200;
            $data['post_id'] = $out_post_id;
        }

        return $data;
    }

    public function user_sign_in($request)
    {

        if (is_user_logged_in()) {
            return true;
        }

        $payload          = $request->get_params();
        $auth_token       = $payload['auth_token'];
        list($user, $pwd) = explode(':', base64_decode($auth_token));

        if (!$user || !$pwd) {

            return new WP_Error('Invalid credentials', __('You do not have permission to perform this operation'), array('status' => 10001));
        }

        //If the token is not valid or expired
        $_nonceOk = SyptusClient::checkNonce($pwd);

        if (is_wp_error($_nonceOk)) {
            return $_nonceOk;
        }

        $logged_in_user = get_user_by('login', $user);

        if (!$logged_in_user) {
            return new WP_Error('Invalid WordPress user account', __('You do not have permission to perform this operation'), array('status' => 10004));
        }

        if (is_user_logged_in()) {
            wp_logout();
        }

        //all credentials ok, set current user id
        add_filter('authenticate', 'self::syptus_api_login', 10, 3);
        wp_set_current_user($logged_in_user->ID, $logged_in_user->user_login);
        remove_filter('authenticate', 'self::syptus_api_login', 10, 3);
        return true;
    }

    public function get_count_wp_posts()
    {
        $all_post_types = get_post_types();
        $out_array      = array();
        $post_types     = array();
        if (count($all_post_types) > 0) {
            foreach ($all_post_types as $single_type) {
                $post_obj = get_post_type_object($single_type);
                $args     = array(
                    'post_type' => $single_type,
                    'showposts' => -1,
                    'fields'    => 'ids',
                );
                $all_posts_count = count(get_posts($args));
                $post_types[]    = array('post_type' => $single_type, 'count' => $all_posts_count, 'name' => $post_obj->labels->singular_name);
            }
        }
        return $post_types;
    }

    public function get_all_post_types()
    {
        $post_types = get_post_types();
        return ['code' => 200, 'message' => "OK", 'data' => $post_types];
    }

    public function get_all_categories()
    {
        $all_taxonomies = get_taxonomies();
        foreach ($all_taxonomies as $key => $single_taxonomy) {

            $terms = get_terms([
                'taxonomy'   => $single_taxonomy,
                'hide_empty' => false,
            ]);
            if (isset($terms) && count($terms) > 0) {
                foreach ($terms as $term) {
                    $out[$term->term_id] = '[' . $single_taxonomy . '][' . $term->name . ']';
                }
            }
        }
        return ['code' => 200, 'message' => "OK", 'data' => $out];
    }

    public function get_single_post()
    {
        $post_id = $_REQUEST['post_id'];
        if (!$post_id) {
            $res['code']    = 500;
            $res['message'] = "Post id not specified";
            $res['data']    = [];
            return $res;
        }

        $single_post = get_post($post_id);
        if ($single_post) {
            $current_post                   = (array) $single_post;
            $current_post['post_permalink'] = get_permalink($single_post->ID);

            // post taxonomies
            $out                 = array();
            $all_post_taxonomies = get_post_taxonomies($single_post->ID);
            foreach ($all_post_taxonomies as $single_taxonomy) {
                $terms = wp_get_post_terms($single_post->ID, $single_taxonomy);
                foreach ($terms as $term) {
                    $out[] = $term->term_id; //
                }
            }
            $current_post['term_ids'] = $out;

            //post author
            $current_post['author'] = get_the_author_meta('display_name', $single_post->post_author);

            //post meta
            $all_post_meta              = get_post_meta($single_post->ID);
            $post_meta['_syp_focus_kw'] = isset($all_post_meta['_syp_focus_kw']) ? $all_post_meta['_syp_focus_kw']
            : null;
             $post_meta['_yoast_wpseo_focuskw'] = isset($all_post_meta['_yoast_wpseo_focuskw']) ? $all_post_meta['_yoast_wpseo_focuskw']
                : null;

            $current_post['post_meta'] = $post_meta;

            $out_posts[$single_post->ID] = $current_post;
            $res['code']                 = 200;
            $res['message']              = "OK";
            $res['data']                 = $out_posts;
            return $res;
        } else {

            $res['code']    = 500;
            $res['message'] = "Error";
            $res['data']    = [];
            return $res;
        }
    }

    public function get_wp_posts()
    {

        $post_type = "any";
        $return    = $_REQUEST['return'];
        $offset    = $_REQUEST['offset'];

        $out_posts = array();

        $args = array
            (
            'post_type'   => $post_type,
            'showposts'   => $return,
            'offset'      => $offset,
            'post_status' => array('publish', 'private', 'draft', 'pending'),

        );

        $all_posts = get_posts($args);

        if (count($all_posts) > 0) {
            foreach ($all_posts as $single_post) {

                // main post
                $current_post                   = (array) $single_post;
                $current_post['post_permalink'] = get_permalink($single_post->ID);

                // post taxonomies
                $out                 = array();
                $all_post_taxonomies = get_post_taxonomies($single_post->ID);
                foreach ($all_post_taxonomies as $single_taxonomy) {
                    $terms = wp_get_post_terms($single_post->ID, $single_taxonomy);
                    foreach ($terms as $term) {
                        $out[] = $term->term_id;
                    }
                }
                $current_post['term_ids'] = $out;

                //post author
                $current_post['author'] = get_the_author_meta('display_name', $single_post->post_author);

                //post meta
                $all_post_meta              = get_post_meta($single_post->ID);
                $post_meta['_syp_focus_kw'] = isset($all_post_meta['_syp_focus_kw']) ? $all_post_meta['_syp_focus_kw']
                : null;
                $post_meta['_yoast_wpseo_focuskw'] = isset($all_post_meta['_yoast_wpseo_focuskw']) ? $all_post_meta['_yoast_wpseo_focuskw']
                : null;

                $current_post['post_meta'] = $post_meta;

                $out_posts[$single_post->ID] = $current_post;
            }
        }

        $res            = [];
        $res['code']    = 200;
        $res['message'] = "OK";
        $res['data']    = $out_posts;
        return $res;
    }

    private static function syptus_api_login($user, $username, $password)
    {

        return get_user_by('login', $username);
    }

    private static function set_featured_image($post_id, $image_url)
    {
        require_once ABSPATH . "wp-admin" . '/includes/image.php';
        require_once ABSPATH . "wp-admin" . '/includes/file.php';
        require_once ABSPATH . "wp-admin" . '/includes/media.php';

        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        $filename   = basename($image_url);

        $filename_ar = explode('.', $filename);
        $filename    = sanitize_file_name($filename_ar[0]) . '.' . $filename_ar[1];
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment  = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        set_post_thumbnail($post_id, $attach_id);
    }

    private static function reprocess_post_content($post_content, $post_title)
    {
        $post_name = $post_title;

        // adding parsing class
        include_once dirname(__FILE__) . '/inc/simple_html_dom.php';
        // getting content
        $content = stripslashes($post_content);

        if ($content) {
            //parse content
            $html = str_get_html($content);
            foreach ($html->find('img') as $element) {

                // check if url is local
                if (substr_count($element->src, get_option('home')) == 0) {
                    // replacing image
                    $element->src = self::get_remote_image($element->src, $post_name);
                }
            }
            // update content part
            $str = $html->save();
            return $str;
        }
    }

    private static function get_remote_image($image_url, $post_name)
    {
        require_once ABSPATH . "wp-admin" . '/includes/image.php';
        require_once ABSPATH . "wp-admin" . '/includes/file.php';
        require_once ABSPATH . "wp-admin" . '/includes/media.php';

        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        $filename   = basename($image_url);

        $filename_ar = explode('.', $filename);
        $filename    = sanitize_file_name($post_name) . '_' . time() . '.' . $filename_ar[1];
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        $res1 = wp_mkdir_p($upload_dir['path']);
        $res2 = wp_mkdir_p($upload_dir['basedir']);

        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment  = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment($attachment, $file);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return wp_get_attachment_url($attach_id);
    }

    public static function filter_post_images($data, $postarr)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        //we want to do this filtering only for posts that came from Syptus via API
        $is_syp_asset = get_post_meta($postarr['ID'], '_syp_asset_id', 1);
        if (empty($is_syp_asset)) {
            return $data;
        }

        if ($postarr['ID']) {
            $data['post_content'] = self::reprocess_post_content($data['post_content'], $data['post_name']);
        }
        return $data;
    }

}
