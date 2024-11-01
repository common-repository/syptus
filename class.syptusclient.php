<?php
require_once dirname(__FILE__) . '/class.syptus.php';
class SyptusClient
{
    const _SYP_BASE_URL          = 'https://app.syptus.com/api/v3'; ///
    const _SYP_SETUP_ENDPOINT    = '/wpsetup';
    const _SYP_PUBLISH_DRAFT     = '/wpdraftpublished';
    const _SYP_PLUGIN_ADMIN_PAGE = 'admin.php?page=syptus-plugin-slug';
    public static function checkToken()
    {
        $response              = array();
        $token                 = WP_REST_Request::get_header('token');
        list($phrase, $digest) = explode(':', $token);
        $secret                = esc_attr(get_option('syptussecret'));
        $digest_new            = md5($phrase . $secret);
        if ($digest_new != $digest) {
            $response['code'] = '403';
            $response['msg']  = 'Not authorized';
            return $response;
        }
        $response['code'] = '200';
        return $response;
    }
    public static function generateToken($client_id = null, $client_secret = null)
    {
        if (!$client_id) {
            $client_id = get_option('syptuscid');
        }
        if (!$client_secret) {
            $client_secret = get_option('syptussecret'); //

        }
        $phrase1           = self::generatePhrase();
        $current_user      = wp_get_current_user();
        $phrase            = $phrase1 . '-' . $current_user->user_login;
        $digest            = md5($client_secret . $phrase);
        $str               = $client_id . ":" . $phrase . ":" . $digest;
        $response['token'] = base64_encode($str);
        $response['pwd']   = $phrase;
        return $response;
    }
    public static function generatePhrase()
    {
        $bytes = random_bytes(20);
        return bin2hex($bytes);
    }
    public static function callSetup($data)
    {
        $out               = array();
        $auth              = self::generateToken($data['syptuscid'], $data['syptussecret']);
        $url               = self::_SYP_BASE_URL . self::_SYP_SETUP_ENDPOINT;
        $header            = self::getHTTPRequest($auth['token']);
        $api_response_full = wp_remote_post($url, $header);
        //check API response
        if (is_wp_error($api_response_full)) {
            $out['error']    = 1;
            $error           = $api_response_full->get_error_message();
            $out['redirect'] = self::_SYP_PLUGIN_ADMIN_PAGE . "&c=" . $data['syptuscid'] . "&success=0&error=" . $error;
            return $out;
        } else {
            $api_response = self::getAPIResponse($api_response_full);
            return self::getRedirectUrl($api_response, self::_SYP_PLUGIN_ADMIN_PAGE, $data['syptuscid']);
        }
    }
    public static function add_notice_query_var($location)
    {
        remove_filter('redirect_post_location', array(
            __CLASS__,
            'add_notice_query_var',
        ), 99);
        return add_query_arg(array(
            '_syp_pb_error' => $_SESSION['_syp_pb_error'],
        ), $location);
    }
    private static function getHTTPRequest($token, $body = null)
    {
        $auth = 'Basic ' . $token;
        $args = array(
            'headers' => array(
                'Authorization' => $auth,
            ),
            'body'    => $body,
        );
        return $args;
    }
    private static function getAPIResponse($response)
    {
        $params = array(); //
        //Part 1
        $ok_code = isset($response['response']['code']) ? $response['response']['code'] : null;
        if (!$ok_code) {
            return self::prepareUserResponse('Syptus CMP API Error: Invalid HTTP Response Code. Please try again later', 1);
        }
        $api_response = isset($response['body']) ? $response['body'] : null;
        if (!$api_response) {
            return self::prepareUserResponse('Syptus CMP API Error: Invalid HTTP Response body. Please try again later', 1);
        }
        //Part 2
        if ($ok_code == 200) {
            $params = json_decode($api_response, true);
            $error  = isset($params['payload']['error']) ? $params['payload']['error'] : null;
            if ($error) {
                //API Call Error
                return self::returnUserResponse($params, 1);
            }
            return self::returnUserResponse($params); //No Error

        } else {
            return self::prepareUserResponse('Syptus CMP API Error: HTTP code not recognized. Please try again later', 1);
        }
    }
    private static function returnUserResponse($params, $error = null)
    {
        $code = isset($params['payload']['code']) ? $params['payload']['code'] : null;
        if (!$code) {
            return self::prepareUserResponse('Syptus CMP API Error: Invalid payload code from Server', 1);
        }
        $msg = isset($params['payload']['msg']) ? $params['payload']['msg'] : null;
        if (!$msg) {
            return self::prepareUserResponse('Syptus CMP API Error: Invalid payload response from Server', 1);
        }
        if ($error) {
            $msg = 'API Call Error Code ' . $code . '-' . $msg;
            return self::prepareUserResponse($msg, 1);
        }
        return self::prepareUserResponse($msg);
    }
    private static function prepareUserResponse($msg, $error = null)
    {
        $user_response = array();
        if ($error) {
            $user_response['code'] = 500;
            $user_response['msg']  = $msg;
            return $user_response;
        }
        $user_response['code'] = 200;
        $user_response['msg']  = $msg;
        return $user_response;
    }
    public static function checkNonce($password)
    {
        list($nonce, $pwd) = explode('-', $password);
        if (!isset($nonce) || empty($nonce)) {
            return false;
        }

        if (!isset($pwd) || empty($pwd)) {
            return false;
        }

        $secret = get_option('syptussecret');
        if (!$secret) {
            return new WP_Error('Invalid plugin setup credentials', __('You do not have permission to perform this operation'), array(
                'status' => 10002,
            ));
        }

        list($usec, $sec) = explode(" ", microtime());
        $t                = round(((float) $usec + (float) $sec) * 1000);
        $k                = $t - 10000;
        $found            = 0;
        while ($k <= $t) {
            $allowed = md5($secret . $k);
            if ($allowed == $nonce) {
                $found = 1;
                break;
            }
            $k++;
        }

        if (!$found) {
            return new WP_Error('Expired token. Please try again later', __('You do not have permission to perform this operation'), array(
                'status' => 10003,
            ));
        }
        return true;
    }
    public static function rest_api_init()
    {
        $controller = new Syptus();
        $controller->register_routes();
    }
    private static function getRedirectUrl($response, $page, $c = null)
    {
        $out['error'] = 0;
        if ($response['code'] == 500) {
            $out['error'] = 1;
            $error        = urlencode($response['msg']);
            if ($c) {
                $out['redirect'] = $page . "&c=" . $c . "&success=0&error=" . $error;
            } else {
                $out['redirect'] = $page . "&success=0&error=" . $error;
            }
        } else {
            $out['error']    = 0;
            $out['redirect'] = $page . "&success=1";
        }
        return $out;
    }

    public static function clear_session()
    {
        if (isset($_GET['private_post_token']) && $_GET['private_post_token'] == get_option('syptussecret')) {
            wp_logout();
        }
    }

    public static function register_session()
    {
        if (!session_id()) {
            session_start();
        }

        if (isset($_GET['private_post_token']) && isset($_GET['login_user']) && $_GET['private_post_token'] == get_option('syptussecret')) {

            if (!is_user_logged_in()) {
                $user_login = $_GET['login_user'];
                $user       = get_userdatabylogin($user_login);
                wp_set_current_user($user->ID, $user_login);
                //wp_set_auth_cookie($user->ID);
            }

        }

        if (isset($_GET['auth_token']) && $_GET['auth_token'] != get_option('syptussecret')) {
            wp_logout();
        }

    }
    public static function syp_focus_keyword()
    {
        $screens = get_post_types();
        foreach ($screens as $screen) {
            add_meta_box('syp_focus_keyword_id', // Unique ID
                'Syptus Primary Keyword', // Box title
                'SyptusClient::syp_focus_keyword_html', // Content callback, must be of type callable
                $screen, // Post type
                "side", "high", null);
        }
    }
    public static function syp_focus_keyword_html($post)
    {
        $value = get_post_meta($post->ID, '_syp_focus_kw', true);
        ?>
<style type="text/css">
ul.keysuggestons li {
display: inline-block;
background: #afacac;
padding: 2px 10px;
border-radius: 21px;
margin-left: 5px;
color: white;
cursor: pointer;
}
input#_syp_focus_kw::-webkit-calendar-picker-indicator {
    display: none;
}
</style>
<p>
    <label for="_syp_focus_kw">Enter Keyword here</label>
    <br>
    <input autocomplete="off" list="supsuggestions"  type="text" style="width:100%" name="_syp_focus_kw" id="_syp_focus_kw" class="regular-text" value="<?php echo $value; ?>">
    <datalist id="supsuggestions">
    <?php $allkeywords = SyptusClient::get_meta_values('_syp_focus_kw');foreach ($allkeywords as $keyword) {
            echo "<option value='" . $keyword . "'>";

        }?>
        </datalist>
    </p>
    <?php
}
    public static function get_meta_values($key = '')
    {
        global $wpdb;
        if (empty($key)) {
            return;
        }

        $r = $wpdb->get_col($wpdb->prepare("
    SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
    WHERE pm.meta_key = %s
    ", $key));
        return $r;
    }
    public static function save_syp_focus_keyword($post_id)
    {
        if (array_key_exists('_syp_focus_kw', $_POST)) {
            update_post_meta($post_id, '_syp_focus_kw', $_POST['_syp_focus_kw']);
        }
    }
    public static function update_messages($messages)
    {
        if (isset($_SESSION['_syp_pb_error'])) {
            $messages['post'][1] = '';
            $messages['post'][6] = '';
            $error               = urldecode($_SESSION['_syp_pb_error']);
            echo "<div class='error' style='font-size:18px;padding:15px'>$error</div>";
            unset($_SESSION['_syp_pb_error']);
        }
        if (isset($_SESSION['_syp_pb_ok'])) {
            $messages['post'][1] = '';
            $msg                 = 'Post status changed successfully. Also published status change to Syptus for Asset Id ' . $_SESSION['_syp_pb_ok'];
            echo "<div class='updated' style='font-size:18px;padding:15px'>$msg</div>";
            unset($_SESSION['_syp_pb_ok']);
        }
        return $messages;
    }
    /**
     * Called every time the user saves plugin settings
     * Generates initial credentials and sends the response to Syptus.
     * Intercepts response from Syptus and either saves to db or
     * shows error
     */
    public static function setup()
    {
        //check nonce
        if (!isset($_POST['_syp_wp_nonce']) || !wp_verify_nonce($_POST['_syp_wp_nonce'], '_syp_update_settings')) {
            echo 'Invalid nonce';
            exit;
        }
        $s     = $_POST['syptussecret'];
        $c     = $_POST['syptuscid'];
        $error = self::check_form_input();
        if ($error) {
            $str = SyptusClient::_SYP_PLUGIN_ADMIN_PAGE . "&s=" . $s . "&c=" . $c . "&success=0&error=" . urlencode($error);
            wp_redirect(admin_url($str));
            exit;
        }
        $data['syptussecret'] = $_POST['syptussecret'];
        $data['syptuscid']    = $_POST['syptuscid'];
        $response             = SyptusClient::callSetup($data);
        if ($response['error'] == 0) {
            self::update_settings($data['syptussecret'], $data['syptuscid'], $response['pwd']);
        }
        wp_redirect(admin_url($response['redirect']));
        exit;
    }
    private static function update_settings($secret, $cid, $pwd)
    {
        if (!get_option('syptussecret')) {
            add_option('syptussecret', $secret);
        } else {
            update_option('syptussecret', $secret);
        }
        if (!get_option('syptuscid')) {
            add_option('syptuscid', $cid);
        } else {
            update_option('syptuscid', $cid);
        }
        list($password, $user_name) = explode("-", $pwd);
        if (!get_option('syptuspwd')) {
            add_option('syptuspwd', $password);
        } else {
            update_option('syptuspwd', $password);
        }
    }
    public static function get_registered_routes()
    {
        $routes = array(
            "/syptus/publishdraft",
            "/syptus/create_post",
            "/syptus/get_count_wp_posts",
            "/syptus/get_wp_posts",
            "/syptus/get_all_post_types",
            "/syptus/get_all_categories",
        );
        return $routes;
    }
    public static function syptus_plugin_page()
    {
        ?>
    <div class="wrap">
        <form method='POST' action="<?php echo admin_url('admin-post.php'); ?>">
            <?php
settings_fields('syptus-plugin-settings');
        do_settings_sections('syptus-plugin-settings');
        ?>
            <h1>Syptus Content Marketing Platform</h1>
            <?php
if ("1" == $_GET['success']) {
            echo "<div class='success' style='padding:20px;color:#ffffff;font-size:22px;font-size:bold;background-color:green;'>Settings saved successfully</div>";
            $secret = get_option('syptussecret');
            $cid    = get_option('syptuscid');
        } elseif (isset($_GET['error'])) {
            echo "<div class='success' style='padding:20px;color:#ffffff;font-size:22px;font-size:bold;background-color:red;'>" . $_GET['error'] . "</div>";
            $secret = esc_attr($_GET['s']);
            $cid    = esc_attr($_GET['c']);
        } else {
            $secret = get_option('syptussecret');
            $cid    = get_option('syptuscid');
            if ($secret && $cid) {
                ?>
            <div class="updated notice">
                <p>Syptus CMP is connected</p>
            </div>
            <?php
}
        }
        ?>
            <p>Please enter your Syptus Account credentials which can be obtained from the Wordpress Application configuration section of your Syptus Account. Please reach out to support@syptus.com if you are facing issues with this setup.
                <table>
                    <tr><td width='40%'><h2>Syptus Client Id</h2>
                        <div>Please enter the Syptus Client Id from your Wordpress configuration settings within Syptus</div></td><td width='60%'><input type="text" placeholder="Enter your Syptus Client ID" name="syptuscid" value="<?php echo $cid; ?>" size="60" /></td></tr>
                        <tr><td width='40%'><h2>Syptus Client Secret</h2>
                            <div>Please enter the Syptus Client Secret from your Wordpress configuration settings within Syptus</div></td><td width='60%'><input type="text" placeholder="Enter your Syptus Client Secret" name="syptussecret" value="<?php echo $secret; ?>" size="60" /></td></tr>
                        </table>
                        <input type="hidden" name="action" value="syptus_setup_hook">
                        <?php
wp_nonce_field('_syp_update_settings', '_syp_wp_nonce');
        submit_button();
        ?>
                    </form>
                </div>
                <?php
}
    /**
     * Plugin settings update form sanitization
     * @return string
     */
    private static function check_form_input()
    {
        if (empty($_POST['syptussecret']) && empty($_POST['syptuscid'])) {
            return 'Please enter Client ID and Secret';
        }
        if (empty($_POST['syptussecret'])) {
            return "Please enter Client Secret";
        }
        if (empty($_POST['syptuscid'])) {
            return "Please enter Client ID";
        }
    }
}