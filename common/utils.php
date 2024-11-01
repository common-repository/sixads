<?php 


class SixadsPluginUtils {

    public static function encryption($string, $action='encrypt') {
        // you may change these values to your own
        $secret_key = SECURE_AUTH_KEY;
        $secrete_iv = AUTH_KEY;

        $output = false;
        $encrypt_method = "AES-256-CBC";
        $key = hash('sha256', $secret_key);
        $iv = substr(hash('sha256', $secret_iv), 0, 16);
     
        if($action == 'encrypt') {
            $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
        }
        else if($action == 'decrypt'){
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
        }
     
        return $output;
    }

    public static function generate_nonce()
    {
        $data = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function base64url_encode($data) { 
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); 
    } 

    public static function base64url_decode($data) { 
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT)); 
    }

    public static function instance_hash($user_id, $api_id, $secret, $url, $method='GET', $request_body=false) {
        //for the signature
        $timestamp = (string)time();
        $nonce = self::generate_nonce();

        $data = $api_id . $method . $url . $timestamp . $nonce;

        if ($request_body) {
            //TODO add request body on post
        }
        $hash = self::base64url_encode(hash_hmac('sha256', $data, $secret, true));
        $json = self::base64url_encode(json_encode(array('user_id'=> $user_id, 'nonce' => $nonce, 'timestamp' => $timestamp)));

        return $hash . '.'. $json;
    }

    public static function create_nonce_url($url, $action=-1, $name='_wpnonce') {
        // Creating a nonce url for every post request
        $nonce_url = wp_nonce_url($url, $action, $name);
        $nonce_url = str_replace( '&amp;', '&', $nonce_url);

        return $nonce_url;
    }
}
