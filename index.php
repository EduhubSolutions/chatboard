<?php

/*
 *
 * Plugin Name: Chat Board by eduhub
 * Plugin URI: https://chatboardapp.com/
 * Description: Smart chat for better support and marketing
 * Version: 1.0.0
 * Author: Eduhub Solutions
 * Author URI: https://eduhub.solutions/
 * © 2024 eduhub.solutions. All rights reserved.
 *
 */

function chatboard_set_admin_menu() {
    add_submenu_page('options-general.php', 'Chat Board', 'Chat Board', 'administrator', 'chat-board', 'chatboard_admin');
}

function chatboard_enqueue_admin() {
    if (key_exists('page', $_GET) && $_GET['page'] == 'chat-board') {
        wp_enqueue_style('chatboard-admin-css', plugin_dir_url(__FILE__) . '/assets/style.css', [], time(), 'all');
    }
}

function chatboard_enqueue() {
    $settings = json_decode(get_option('chatboard-settings'), true);
    if (!$settings || empty($settings['chat-id'])) return false;
    $inline_code = '';
    $lang = '';
    $page_id = get_the_ID();
    $exclusions = [$settings['visibility-ids'], $settings['visibility-post-types'], $settings['visibility-type']];
    $exclusions = [$exclusions[0] ? array_map('trim', explode(',', $exclusions[0])) : [], $exclusions[1] ? array_map('trim', explode(',', $exclusions[1])) : [], $exclusions[2]];

    // Selective chat loading
    if ($exclusions[2] != false && (count($exclusions[0]) && (($exclusions[2] == 'show' && !in_array($page_id, $exclusions[0])) || ($exclusions[2] == 'hide' && in_array($page_id, $exclusions[0]))))) {
        return false;
    }
    if (count($exclusions[1])) {
        $post_type = get_post_type($page_id);
        if ((($exclusions[2] == 'show' && !in_array($post_type, $exclusions[1])) || ($exclusions[2] == 'hide' && in_array($post_type, $exclusions[1])))) {
            return false;
        }
    }

    // Multisite routing
    if (is_multisite() && $settings['multisite-routing']) {
        $inline_code .= 'var SB_DEFAULT_DEPARTMENT = ' . esc_html(get_current_blog_id()) . ';';
    }

    // WordPress users synchronization
    if ($settings['synch-wp-users']) {
        $current_user = wp_get_current_user();
        if ($current_user) {
            $profile_image = get_avatar_url($current_user->ID, ['size' => '500']);
            if (empty($profile_image) || !(strpos($profile_image, '.jpg') || strpos($profile_image, '.png'))) {
                $profile_image = '';
            }
            $inline_code .= 'var SB_DEFAULT_USER = { first_name: "' . esc_html($current_user->user_firstname ? $current_user->user_firstname : $current_user->nickname) . '", last_name: "' . esc_html($current_user->user_lastname) . '", email: "' . esc_html($current_user->user_email) . '", profile_image: "' . esc_html($profile_image) . '", password: "' . esc_html($current_user->user_pass) . '", extra: { "wp-id": [' . esc_html($current_user->ID) . ', "WordPress ID"] }};';
        }
    }

    // Force language
    $language = chatboard_isset($settings, 'force-language');
    if ($language) $language = '&lang=' . esc_html($language);

    wp_enqueue_script('chat-init', 'https://dashboard.chatboardapp.com/account/js/init.js?id=' . esc_html($settings['chat-id']) . $language, ['jquery'], '1.0', true);
    if ($inline_code) wp_add_inline_script('jquery', $inline_code);
}

function chatboard_tickets_shortcode() {
    wp_register_script('chatboard-tickets', '');
    wp_enqueue_script('chatboard-tickets');
    wp_add_inline_script('chatboard-tickets', 'var SB_TICKETS = true;');
    return '<div id="sb-tickets"></div>';
}

function chatboard_articles_shortcode() {
    $settings = json_decode(get_option('chatboard-settings'), true);
    $api_token = chatboard_isset($settings, 'api-token');

    // Determine the parameters based on the URL
    $category = get_query_var('category');
    $article_id = get_query_var('article_id');
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    ob_start(); // Start output buffering

    ?>
    <div id="sb-articles">
        <?php
        // Prepare the API URL based on the rewritten URLs
        $api_url = 'https://dashboard.chatboardapp.com/script/include/api.php';
        if (!empty($category)) {
            $api_url .= '?category=' . urlencode($category);
        } elseif (!empty($article_id)) {
            $api_url .= '?article_id=' . urlencode($article_id);
        } elseif (!empty($search)) {
            $api_url .= '?search=' . urlencode($search);
        }

        // Initialize cURL
        $ch = curl_init($api_url);
        $parameters = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Chat Board',
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query([
                'token' => $api_token,
                'function' => 'init-articles'
            ])
        ];
        curl_setopt_array($ch, $parameters);

        // Execute the cURL request and close it
        $response = curl_exec($ch);
        curl_close($ch);

        // Output the response
        echo $response;
        ?>
    </div>
    <?php

    return ob_get_clean(); // Return the output buffer content
}

function chatboard_isset($array, $key, $default = '') {
    return !empty($array) && isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

function chatboard_admin() { 
    if (isset($_POST['chatboard_submit'])) {
        $chat_id = $_POST['chatboard-chat-id'];
        $api_token = $_POST['chatboard-api-token']; // Add this line to capture the token

        if (!isset($_POST['sb_nonce']) || !wp_verify_nonce($_POST['sb_nonce'], 'sb-nonce')) die('nonce-check-failed'); 
        
        // Sanitize and store the token along with other settings
        $settings = [
            'chat-id' => sanitize_text_field($chat_id),
            'api-token' => sanitize_text_field($api_token), // Save the token
            'multisite-routing' => sanitize_text_field(chatboard_isset($_POST, 'chatboard-multisite-routing', false)),
            'visibility-type' => sanitize_text_field($_POST['chatboard-visibility-type']),
            'visibility-ids' => sanitize_text_field($_POST['chatboard-visibility-ids']),
            'visibility-post-types' => sanitize_text_field($_POST['chatboard-visibility-post-types']),
            'synch-wp-users' => sanitize_text_field(chatboard_isset($_POST, 'chatboard-synch-wp-users', false)),
            'force-language' => sanitize_text_field($_POST['chatboard-force-language'])
        ];
        update_option('chatboard-settings', json_encode($settings));
    }
    
    $settings = json_decode(get_option('chatboard-settings'), true);
    $force_language = chatboard_isset($settings, 'force-language');
?>
<form method="post" action="">
<div class="wrap">
    <h1>Chat Board Settings</h1>
	<p>For more information on installation and setup, visit <a href="https://docs.chatboardapp.com">docs.chatboardapp.com</a></p>
	<ul id="main-menu">
		<li>
		1. To embed the articles widget, just add the following shortcode: <b>[chatboard-articles]</b>
		</li>
		<li>
		2. To embed the tickets widget, just add the following shortcode: <b>[chatboard-tickets]</b>
		</li>
		<li>
		3. If you don't see Articles sub pages, please visit <b>Settings > Permalinks</b>, and click "Save Changes" to manually flush rewrite rules.
		</li>
	</ul>
    <div class="postbox-container">
        <table class="form-table chatboard-table">
            <tbody>
                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-chat-id">Chat ID</label>
                    </th>
                    <td>
                        <input type="text" id="chatboard-chat-id" name="chatboard-chat-id" value="<?php echo esc_html(chatboard_isset($settings, 'chat-id')) ?>" />
                        <br />
                        <p class="description">Enter the embed code or the ID attribute. Get it from <a target="_blank" href="https://dashboard.chatboardapp.com/account/?tab=installation">here</a>. Pricing <a target="_blank" href="https://chatboardapp.com/pricing/">here</a>.</p>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-api-token">API Token</label>
                    </th>
                    <td>
                        <input type="text" id="chatboard-api-token" name="chatboard-api-token" value="<?php echo esc_html(chatboard_isset($settings, 'api-token')) ?>" />
                        <br />
                        <p class="description">Enter your API token here for the Chat Board integration from <a target="_blank" href="https://dashboard.chatboardapp.com/account/?tab=installation">here</a>.</p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-multisite-routing">Multisite routing</label>
                    </th>
                    <td>
                        <input type="checkbox" id="chatboard-multisite-routing" name="chatboard-multisite-routing" <?php if (chatboard_isset($settings, 'multisite-routing')) echo 'checked' ?> />
                        <br />
                        <p class="description">
                            Automatically route the conversations of each website to the department with the same ID of the WordPress website.
                            This setting requires a WordPress Multisite installation and Chat Board departments with the same IDs of the WordPress websites.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-visibility-type">Visibility</label>
                    </th>
                    <td>
                        <label>Type</label>
                        <select id="chatboard-visibility-type" name="chatboard-visibility-type">
                            <option value=""></option>
                            <option value="show" <?php if (chatboard_isset($settings, 'visibility-type') == 'show') echo 'selected' ?>>Show</option>
                            <option value="hide" <?php if (chatboard_isset($settings, 'visibility-type') == 'hide') echo 'selected' ?>>Hide</option>
                        </select>
                        <br />
                        <label>Page IDs</label>
                        <input type="text" id="chatboard-visibility-ids" name="chatboard-visibility-ids" value="<?php echo esc_html(chatboard_isset($settings, 'visibility-ids')) ?>" />
                        <br />
                        <label>Post Type slugs</label>
                        <input type="text" id="chatboard-visibility-post-types" name="chatboard-visibility-post-types" value="<?php echo esc_html(chatboard_isset($settings, 'visibility-post-types')) ?>" />
                        <br />
                        <p class="description">
                            Choose where to display the chat. Insert the values separated by commas.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-synch-wp-users">Synchronize WordPress users</label>
                    </th>
                    <td>
                        <input type="checkbox" id="chatboard-synch-wp-users" name="chatboard-synch-wp-users" <?php if (chatboard_isset($settings, 'synch-wp-users')) echo 'checked' ?> />
                        <br />
                        <p class="description">
                          Sync logged in WordPress users with Chat Board.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="chatboard-force-language">Force language</label>
                    </th>
                    <td>
                        <select id="chatboard-force-language" name="chatboard-force-language">
                            <option value="" <?php if (empty($force_language)) echo 'selected' ?>>Disabled</option>
                            <option value="ar" <?php if ($force_language == 'ar') echo 'selected' ?>>Arabic</option>
                            <option value="bg" <?php if ($force_language == 'bg') echo 'selected' ?>>Bulgarian</option>
                            <option value="cs" <?php if ($force_language == 'cs') echo 'selected' ?>>Czech</option>
                            <option value="da" <?php if ($force_language == 'da') echo 'selected' ?>>Danish</option>
                            <option value="de" <?php if ($force_language == 'de') echo 'selected' ?>>German</option>
                            <option value="el" <?php if ($force_language == 'el') echo 'selected' ?>>Greek</option>
                            <option value="es" <?php if ($force_language == 'es') echo 'selected' ?>>Spanish</option>
                            <option value="et" <?php if ($force_language == 'et') echo 'selected' ?>>Estonian</option>
                            <option value="fa" <?php if ($force_language == 'fa') echo 'selected' ?>>Persian</option>
                            <option value="fi" <?php if ($force_language == 'fi') echo 'selected' ?>>Finnish</option>
                            <option value="fr" <?php if ($force_language == 'fr') echo 'selected' ?>>French</option>
                            <option value="he" <?php if ($force_language == 'he') echo 'selected' ?>>Hebrew</option>
                            <option value="hi" <?php if ($force_language == 'hi') echo 'selected' ?>>Hindi</option>
                            <option value="hr" <?php if ($force_language == 'hr') echo 'selected' ?>>Croatian</option>
                            <option value="hu" <?php if ($force_language == 'hu') echo 'selected' ?>>Hungarian</option>
                            <option value="am" <?php if ($force_language == 'am') echo 'selected' ?>>Armenian</option>
                            <option value="id" <?php if ($force_language == 'id') echo 'selected' ?>>Indonesian</option>
                            <option value="it" <?php if ($force_language == 'it') echo 'selected' ?>>Italian</option>
                            <option value="ja" <?php if ($force_language == 'ja') echo 'selected' ?>>Japanese</option>
                            <option value="ka" <?php if ($force_language == 'ka') echo 'selected' ?>>Georgian</option>
                            <option value="ko" <?php if ($force_language == 'ko') echo 'selected' ?>>Korean</option>
                            <option value="mk" <?php if ($force_language == 'mk') echo 'selected' ?>>Macedonian</option>
                            <option value="mn" <?php if ($force_language == 'mn') echo 'selected' ?>>Mongolian</option>
                            <option value="my" <?php if ($force_language == 'my') echo 'selected' ?>>Burmese</option>
                            <option value="nl" <?php if ($force_language == 'nl') echo 'selected' ?>>Dutch</option>
                            <option value="no" <?php if ($force_language == 'no') echo 'selected' ?>>Norwegian</option>
                            <option value="pl" <?php if ($force_language == 'pl') echo 'selected' ?>>Polish</option>
                            <option value="pt" <?php if ($force_language == 'pt') echo 'selected' ?>>Portuguese</option>
                            <option value="ro" <?php if ($force_language == 'ro') echo 'selected' ?>>Romanian</option>
                            <option value="ru" <?php if ($force_language == 'ru') echo 'selected' ?>>Russian</option>
                            <option value="sk" <?php if ($force_language == 'sk') echo 'selected' ?>>Slovak</option>
                            <option value="sl" <?php if ($force_language == 'sl') echo 'selected' ?>>Slovenian</option>
                            <option value="sq" <?php if ($force_language == 'sq') echo 'selected' ?>>Albanian</option>
                            <option value="sr" <?php if ($force_language == 'sr') echo 'selected' ?>>Serbian</option>
                            <option value="su" <?php if ($force_language == 'su') echo 'selected' ?>>Sundanese</option>
                            <option value="sv" <?php if ($force_language == 'sv') echo 'selected' ?>>Swedish</option>
                            <option value="th" <?php if ($force_language == 'th') echo 'selected' ?>>Thai</option>
                            <option value="tr" <?php if ($force_language == 'tr') echo 'selected' ?>>Turkish</option>
                            <option value="uk" <?php if ($force_language == 'uk') echo 'selected' ?>>Ukrainian</option>
                            <option value="vi" <?php if ($force_language == 'vi') echo 'selected' ?>>Vietnamese</option>
                            <option value="zh" <?php if ($force_language == 'zh') echo 'selected' ?>>Chinese</option>
                        </select>
                        <br />
                        <p class="description">
                            Force the chat to ignore the language preferences, and to use always the same language.
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <input type="hidden" name="sb_nonce" id="sb_nonce" value="<?php echo wp_create_nonce('sb-nonce') ?>">
            <input type="submit" class="button-primary" name="chatboard_submit" value="Save changes" />
        </p>
    </div>
</div>
</form>
<?php }

function chatboard_script_id_fix($tag, $handle, $src) {
    if ('chat-init' === $handle) {
        $tag = '<script id="chat-init" src="' . esc_url(str_replace(['%3F', '%3D'], ['?', '='], $src)) . '"></script>';
    }
    return $tag;
}

function chatboard_custom_rewrite_rules() {
    add_rewrite_rule('^articles/?$', 'index.php?pagename=articles', 'top');
    add_rewrite_rule('^articles/category/([^/]+)/?$', 'index.php?pagename=articles&category=$matches[1]', 'top');
    add_rewrite_rule('^articles/([^/]+)/?$', 'index.php?pagename=articles&article_id=$matches[1]', 'top');
}
add_action('init', 'chatboard_custom_rewrite_rules');

// Ensure the query variables are recognized by WordPress
function chatboard_query_vars($vars) {
    $vars[] = 'category';
    $vars[] = 'article_id';
    return $vars;
}
add_filter('query_vars', 'chatboard_query_vars');

// Flush rewrite rules on activation to apply the new rules
function chatboard_rewrite_flush() {
    chatboard_custom_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'chatboard_rewrite_flush');

add_action('admin_menu', 'chatboard_set_admin_menu');
add_action('network_admin_menu', 'chatboard_set_admin_menu');
add_action('admin_enqueue_scripts', 'chatboard_enqueue_admin');
add_action('wp_enqueue_scripts', 'chatboard_enqueue');
add_filter('script_loader_tag', 'chatboard_script_id_fix', 10, 3);
add_shortcode('chatboard-tickets', 'chatboard_tickets_shortcode');
add_shortcode('chatboard-articles', 'chatboard_articles_shortcode');

?>