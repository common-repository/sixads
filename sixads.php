<?php
/** 
    * Plugin Name: SixAds 
    * Plugin URI: https://app.sixads.net/
    * Description: Get traffic by displaying free ads in other sites with this plugin
    * Version: 1.06
    * Author: SixAds
    * Text Domain: sixads
    * Tested up to: 7.0.0
    * WC tested up to: 5.2.1
    * WC requires at least: 5.2.1
    * 
    * License: GNU General Public License v3.0
    * License URI: http://www.gnu.org/licenses/gpl-3.0.html
    * Woo: 4688506:e3295d7f5a13e5e46f3f6a4ab499db58
*/

defined( 'ABSPATH' ) or die( 'This is not a wordpress');
defined( 'AUTH_KEY' ) or die( 'No secret key');
defined( 'SECURE_AUTH_KEY' ) or die( 'No secret key');

include_once(dirname(__FILE__).'/common/utils.php');

#Loading the constants
if(file_exists(__DIR__.'/dot_env.php')){
	require_once(__DIR__.'/dot_env.php');
} else {
	require_once(__DIR__.'/constants.php');
}

class SixadsPlugin
{   
    private $name;
    private $auth_url = SIXADS_AUTH_URL;
    private $app_url = SIXADS_APP_URL;
    private $success_page_slug = 'sixads-success';
    private $error_page_slug = 'sixads-error';
    private $sixads_key = 'sixads_api_key';
    private $sixads_menu_slug = 'sixads-menu-tab';
    private $site_url = SIXADS_SITE_URL;
    private $script_slug = 'script';

    // Constructor
    function __construct() {

        $this->name = plugin_basename( __FILE__ );
        
        add_action('admin_enqueue_scripts', array($this, 'register_plugin_styles'));
        add_action('wp_enqueue_scripts', array($this, 'register_plugins_scripts'));

        add_action('admin_menu', array($this, 'free_trafic_menu'));
        add_shortcode('sixads', array($this, 'sixads_shortcode'));

    }

    function _error_not_installed_woo_commerce() {
        
        $error_message = '<p id="sixads-woocomerce-required">' 
            . esc_html__( 'This plugin requires ', 'woocommerce' ) . 
            '<a href="' . esc_url( 'https://wordpress.org/plugins/woocommerce/' ) . '" target="_blank">WooCommerce</a>' 
            . esc_html__( ' plugin to be active.', 'woocommerce' ) . 
        '</p>';

        return $error_message ;
    }

    function _error_bad_response() {
        $error_message = 'Oops something went wrong. Try to activate the plugin later...';

        return $error_message;
    }

    function _error_bad_permalinks() {
        return 'Bad permalink_structure format. Go to store settings and choose other permalink format.';
    }

    function register_plugin_styles() {

		wp_register_style('messages-sixads', plugins_url( '/css/messages-sixads.css' , __FILE__ ));
		wp_enqueue_style('messages-sixads');
    }
    
    function fetch_authentication_parameters() {
        $url = $this->app_url.'/api/v1/woo-auth/';

        $query_args = [
            'shop_url'=> $this->site_url,
            'currency_format' => get_option('woocommerce_currency')
        ];

        $request = wp_remote_get(add_query_arg($query_args, $url));

        if(is_wp_error($request) || '200' != wp_remote_retrieve_response_code($request)) {   
            return array('error' => $this->_error_bad_response());
        } 
        
        return json_decode(wp_remote_retrieve_body($request), true);
    }

    function save_sixads_key($user_id, $key, $secret) {

        $key = SixadsPluginUtils::encryption($user_id . '.' . $key . '.'. $secret);
        update_option($this->sixads_key, $key);

    }

    function get_sixads_key() {
        $key = get_option($this->sixads_key);
        $data = SixadsPluginUtils::encryption($key, 'decrypt');

        return explode('.', $data);
    }

    function activate() {
        // generated a CPT
        // flush rewrite rules
        if(!is_plugin_active( 'woocommerce/woocommerce.php' )) {
            // Deactivate the plugin.
            deactivate_plugins($this->name);
            die(esc_attr($this->_error_not_installed_woo_commerce()));
        }

        if (!get_option('permalink_structure')) {
            die(esc_attr($this->_error_bad_permalinks()));
        }
        
        add_submenu_page(null, 'Success', $this->success_page_slug, 'edit_posts', $this->success_page_slug,  array($this, 'success'));
        add_submenu_page(null, 'Error', $this->error_page_slug, 'edit_posts', $this->error_page_slug,  array($this, 'error'));
        update_option('sixads_installation_started', '1');
    }

    function activated($plugin) {
        if( $plugin == plugin_basename( __FILE__ ) ) {

            $auth_params = $this->fetch_authentication_parameters();

            if ($auth_params['error']) {
                $query_string = http_build_query(array('error' => $auth_params['error']));
                $nonce_url = SixadsPluginUtils::create_nonce_url(menu_page_url($this->error_page_slug, false) . '&' . $query_string);
                wp_redirect($nonce_url);
                exit();
            } else {

                //save secrets to database
                $this->save_sixads_key($auth_params['user_id'], $auth_params['key'], $auth_params['secret']);

                //add aditional parameters for authentication
                $auth_params['callback_url'] = $this->app_url . '/' . $auth_params['callback_url'];
                $auth_params['return_url'] = SixadsPluginUtils::create_nonce_url(menu_page_url($this->success_page_slug, false));

                //creating a nonce url
                $nonce_url = SixadsPluginUtils::create_nonce_url($this->auth_url . '?' . http_build_query($auth_params), "auth");
                wp_redirect($nonce_url);
                exit();
            }
        }
    }

    function deactivate() {
        // flush rewrite rules
        $url = $this->app_url.'/api/v1/woo-uninstall/';

        $sixads_key = $this->get_sixads_key();
        $instance = SixadsPluginUtils::instance_hash($sixads_key[0], $sixads_key[1], $sixads_key[2], '/api/v1/woo-uninstall/', 'POST');

        $request = wp_remote_post($url, array('headers' => array('X-INSTANCE' => $instance)));
        if(is_wp_error($request) || '200' != wp_remote_retrieve_response_code($request))
        {   
            include_once(dirname(__FILE__).'/error_uninstall.php');
        }
        delete_option('sixads_installation_started');
    }

    function uninstall() {
        $this->deactivate();
    }

    function free_trafic_menu() {

        if(!empty($_SERVER["PHP_SELF"])){
            $PHP_SELF = filter_var($_SERVER["PHP_SELF"], FILTER_SANITIZE_STRING);
            if (get_option('sixads_installation_started') == '1' && strpos($PHP_SELF, 'plugins.php') !== false) {
                deactivate_plugins($this->name, true);
                delete_option('sixads_installation_started');
                return;
            }
        }

        add_menu_page('Six Ads', 'Six Ads', 'edit_posts', $this->sixads_menu_slug, array($this, sixads_privacy_policy), 'dashicons-media-spreadsheet');
        add_submenu_page($this->sixads_menu_slug, 'Dashboard', 'To dashboard', 'edit_posts', $this->sixads_menu_slug, array($this, redirect_to_app_menu));
        add_submenu_page($this->sixads_menu_slug, 'Privacy', 'Privacy policy', 'edit_posts', 'sixads-privacy', array($this, sixads_privacy_policy));
        add_submenu_page(null, 'Success', $this->success_page_slug, 'edit_posts', $this->success_page_slug,  array($this, 'success'));
        add_submenu_page(null, 'Error', $this->error_page_slug, 'edit_posts', $this->error_page_slug,  array($this, 'error'));
    }

    function sixads_privacy_policy(){
        include_once(dirname(__FILE__).'/sixads_privacy_policy.php');
    }

    function redirect_to_app_menu() {
        $sixads_key = $this->get_sixads_key();
        $instance = SixadsPluginUtils::instance_hash($sixads_key[0], $sixads_key[1], $sixads_key[2], '/api/v1/woo-login/');

        $app_url = $this->app_url . '/api/v1/woo-login/?' . http_build_query(array('instance' => $instance));
        wp_redirect($app_url);
        exit();
    }

    function  success() {
        if (check_admin_referer()) {
            if(!empty($_GET['success'])){
                if ($_GET['success'] == '1') {
                    delete_option('sixads_installation_started');
                    include_once(dirname(__FILE__).'/successfull.php');
                } else {
                    include_once(dirname(__FILE__).'/error_activate.php');
                    deactivate_plugins($this->name, true);
                }
            } else {
                include_once(dirname(__FILE__).'/error_unauthenticated.php');
            }
        }
    }

    function  error() {
        if (check_admin_referer()) {
            if(!empty($_GET['error'])) {
                $error = filter_var($_GET['error'], FILTER_SANITIZE_STRING);
                include_once(dirname(__FILE__).'/error.php');
                deactivate_plugins($this->name, true);
            }
        } else {
            include_once(dirname(__FILE__).'/error_unauthenticated.php');
        }
    }

    function register_plugins_scripts() {
        $app_url = $this->app_url;
        $site_url = $this->site_url;

        wp_enqueue_script('sixads-script', "$app_url/sixads.js?shop=$site_url", $in_footer=true);
        wp_enqueue_script('sixads-script-hover', "$app_url/sixads.js?shop=$site_url&hover=1", $in_footer=true);
    }

    function sixads_shortcode() {
        return "<div id='sixads-list'></div>";
    }
}

// Creating the plugin class
if (class_exists( 'SixadsPlugin' )) {
    $sixads_plugin = new SixadsPlugin();
}

// Usage upon activation
add_action('activated_plugin', array($sixads_plugin, 'activated'));
// Activation
register_activation_hook(__FILE__, array($sixads_plugin, 'activate'));
// Deactivation
register_deactivation_hook(__FILE__, array($sixads_plugin, 'deactivate'));
