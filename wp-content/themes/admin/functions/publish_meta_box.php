<?php

$auto_tweet_meta_scheduled = 'auto_tweet_scheduled';
$auto_tweet_meta_tweeted = 'auto_tweet_tweeted';
$auto_tweet_nonce = 'auto_tweet_nonce';
$auto_tweet_nonce_action = 'auto_tweet_nonce_action';

function admin_tweet_submit_box() {
    global $post, $auto_tweet_meta_scheduled, $auto_tweet_meta_tweeted, $auto_tweet_nonce, $auto_tweet_nonce_action;

    if (get_post_type($post) == 'post') {
        echo '<div class="misc-pub-section misc-pub-section-last" style="border-top: 1px solid #eee;">';

        if(get_post_meta($post->ID, $auto_tweet_meta_tweeted, true) != 'true') {
            wp_nonce_field($auto_tweet_nonce_action, $auto_tweet_nonce);

            if(get_post_meta($post->ID, $auto_tweet_meta_scheduled, true) == 'true') {
                $checked = ' checked';
            } else {
                $checked = '';
            }            

            echo '<input type="checkbox" name="' . $auto_tweet_meta_scheduled . '" id="article_or_box-article" value="true" '. $checked .' /> <label for="article_or_box-article" class="select-it">Tweet on publish</label><br />';
        } else {
            echo 'Already tweeted';
        }

        echo '</div>';
    }
}

function admin_save_post_tweet_pref($post_id) {
    global $auto_tweet_meta_scheduled, $auto_tweet_nonce, $auto_tweet_nonce_action;

    if (!isset($_POST['post_type']))
        return $post_id;

    if (!wp_verify_nonce($_POST[$auto_tweet_nonce], $auto_tweet_nonce_action))
        return $post_id;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) 
        return $post_id;

    if ('post' == $_POST['post_type'] && !current_user_can('edit_post', $post_id))
        return $post_id;
    
    if (!isset($_POST[$auto_tweet_meta_scheduled])) {
        delete_post_meta($post_id, $auto_tweet_meta_scheduled);
        return $post_id;
    } else {
        $mydata = $_POST[$auto_tweet_meta_scheduled];
        update_post_meta($post_id, $auto_tweet_meta_scheduled, $_POST[$auto_tweet_meta_scheduled]);
        return $post_id;
    }
}

require_once(__DIR__ . '/../vendor/autoload.php');
use Abraham\TwitterOAuth\TwitterOAuth;

function admin_twitter_login() {
    $connection = new TwitterOAuth(TWITTER_KEY, TWITTER_SECRET);

    $temporary_credentials = $connection->oauth('oauth/request_token', array("oauth_callback" => TWITTER_CALLBACK));
    $url = $connection->url("oauth/authorize", array("oauth_token" => $temporary_credentials['oauth_token']));

    header('Location: ' . $url); 
    exit;
}

if(isset($_GET['twitter-login']) && is_user_logged_in()) {
    admin_twitter_login();
}

$admin_twitter_oauth_token = 'admin_twitter_oauth_token';
$admin_twitter_oauth_token_secret = 'admin_twitter_oauth_token_secret';
$admin_twitter_screen_name = 'admin_twitter_screen_name';

function admin_set_access_token($oauth_verifier, $oauth_token) {
    global $admin_twitter_oauth_token, $admin_twitter_oauth_token_secret, $admin_twitter_screen_name;

    $connection = new TwitterOAuth(TWITTER_KEY, TWITTER_SECRET);

    $params = array(
        "oauth_verifier" => $oauth_verifier, 
        "oauth_token" => $oauth_token
    );

    $twitter_auth = $connection->oauth("oauth/access_token", $params);

    if(isset($twitter_auth['oauth_token'], $twitter_auth['oauth_token_secret'], $twitter_auth['user_id'], $twitter_auth['screen_name'])) {
        update_option($admin_twitter_oauth_token, $twitter_auth['oauth_token'], true);
        update_option($admin_twitter_oauth_token_secret, $twitter_auth['oauth_token_secret'], true);
        update_option('admin_twitter_user_id', $twitter_auth['user_id'], true);
        update_option($admin_twitter_screen_name, $twitter_auth['screen_name'], true);
    } else {
        return false;
    }
}

if(isset($_GET['oauth_verifier'], $_GET['oauth_token']) && is_user_logged_in()) {
    admin_set_access_token($_GET['oauth_verifier'], $_GET['oauth_token']);
} 

function admin_bitly_login() {
    $url = 'https://bit.ly/oauth/authorize?client_id=' . BITLY_CLIENT_ID . '&redirect_uri=' . BITLY_REDIRECT;
    header('Location: ' . $url); 
    exit;
}

if(isset($_GET['bitly-login']) && is_user_logged_in()) {
    admin_bitly_login();
}

$admin_bitly_access_token = 'admin_bitly_access_token';

function admin_bitly_callback() {
    global $admin_bitly_access_token;

    require_once(__DIR__ . '/../vendor/bitly.php');

    $results = bitly_oauth_access_token(
        $_REQUEST['code'],
        BITLY_REDIRECT,
        BITLY_CLIENT_ID,
        BITLY_CLIENT_SECRET
    );

    if(isset($results['access_token'], $results['login'], $results['apiKey'])) {
        update_option($admin_bitly_access_token, $results['access_token'], true);
        update_option('admin_bitly_login', $results['login'], true);
        update_option('admin_bitly_apiKey', $results['apiKey'], true);
    } else {
        return false;
    }
}

if(isset($_GET['code']) && is_user_logged_in()) {
    admin_bitly_callback();
}

$admin_tweet_meta = 'admin_tweet_meta';

function admin_return_post_tweet($post_id, $bitly) {
    global $admin_tweet_meta, $admin_twitter_screen_name;

    $tweet = get_post_meta($post_id, $admin_tweet_meta, true);
    $tweet_limit = 140;

    if(strlen($tweet) > 10 && strlen($tweet) <= $tweet_limit) {
        return false;
    } else {
        $title = get_the_title($post_id);
        $title_count = strlen($title);
        $tweet_append = ' ' . $bitly;
        $tweet_append_count = strlen($tweet_append);

        $total_count = $title_count + $tweet_append_count;

        if($total_count > $tweet_limit) {
            $reduce_by = $total_count - $tweet_limit + 3;
            $title = substr($title, 0, -$reduce_by) . '...';

            $tweet = $title . $tweet_append;
        } else {
            $tweet = $title . $tweet_append;
        }

        return $tweet;
    }
}

function admin_send_tweet($post_id) {
    global $admin_bitly_access_token, $admin_twitter_oauth_token, $admin_twitter_oauth_token_secret;

    require_once(__DIR__ . '/../vendor/bitly.php');

    $params = array();
    $params['access_token'] = get_option($admin_bitly_access_token);
    $params['longUrl'] = LIVE_BLOG_URL . 'posts/' . get_post_field('post_name', $post_id);
    $results = bitly_get('shorten', $params);

    if(isset($results['data']['url'])) {
        $connection = new TwitterOAuth(
            TWITTER_KEY, 
            TWITTER_SECRET, 
            get_option($admin_twitter_oauth_token), 
            get_option($admin_twitter_oauth_token_secret)
        );

        $status = admin_return_post_tweet($post_id, $results['data']['url']);
        $statues = $connection->post("statuses/update", ["status" => $status]);

        if($connection->getLastHttpCode() == 200) {
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

function admin_tweet_future_post($post_id) {
    global $auto_tweet_meta_tweeted, $auto_tweet_meta_scheduled;

    $tweeted = get_post_meta($post_id, $auto_tweet_meta_tweeted, true);
    $scheduled = get_post_meta($post_id, $auto_tweet_meta_scheduled, true);

    if($tweeted != 'true' && $scheduled == 'true') {
        admin_send_tweet($post_id);
        update_post_meta($post_id, $auto_tweet_meta_tweeted, 'true');
    } else {
        return false;
    }
}

function admin_test_connections() {
    global $admin_twitter_oauth_token, $admin_twitter_oauth_token_secret, $admin_bitly_access_token;

    if(!isset($_SESSION['twitter_set'])) {
        $connection = new TwitterOAuth(
            TWITTER_KEY, 
            TWITTER_SECRET, 
            get_option($admin_twitter_oauth_token), 
            get_option($admin_twitter_oauth_token_secret)
        );

        $statuses = $connection->get("search/tweets", ["q" => "twitterapi", "count" => 1, ]);

        if($connection->getLastHttpCode() != 200) {
            print '<div class="notice notice-error is-dismissible"><p>You are not connected to twitter. <a href="/?twitter-login">Connect here</a></p></div>';
        } else {
            $_SESSION['twitter_set'] = true;
        }
    }

    if(!isset($_SESSION['bitly_set'])) {
        require_once(__DIR__ . '/../vendor/bitly.php');

        $params = array();
        $params['access_token'] = get_option($admin_bitly_access_token);
        $results = bitly_get('user/info', $params);

        if($results['status_code'] != 200) {
            print '<div class="notice notice-error is-dismissible"><p>You are not connected to Bitly. <a href="/?bitly-login">Connect here</a></p></div>';
        } else {
            $_SESSION['bitly_set'] = true;
        }
    }
}

admin_test_connections();