<?php
/**
 * Plugin Name: BT AutoPost
 * Description: Automatically generate WordPress posts using Claude AI + OpenAI
 * Version: 1.0.0
 * Author: Trushiv
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bt-autopost
 */

if (!defined('ABSPATH')) exit;

define('BTAP_VERSION', '1.0.0');
define('BTAP_PLUGIN_DIR', plugin_dir_path(__FILE__));

register_activation_hook(__FILE__, function () {
    add_option('btap_anthropic_key', '');
    add_option('btap_openai_key', '');
    add_option('btap_post_status', 'draft');
    add_option('btap_post_language', 'English');
    add_option('btap_category_id', '1');
});

add_action('admin_menu', function () {
    add_menu_page(
        'BT AutoPost',
        'BT AutoPost',
        'manage_options',
        'bt-autopost',
        'btap_main_page',
        'dashicons-superhero',
        30
    );
    add_submenu_page(
        'bt-autopost',
        'Generate Post',
        'Generate Post',
        'manage_options',
        'bt-autopost',
        'btap_main_page'
    );
    add_submenu_page(
        'bt-autopost',
        'Settings',
        'Settings',
        'manage_options',
        'bt-autopost-settings',
        'btap_settings_page'
    );
});

add_action('admin_init', function () {
    if (
        isset($_POST['btap_save_settings']) &&
        check_admin_referer('btap_settings_nonce')
    ) {
        update_option('btap_anthropic_key', sanitize_text_field($_POST['btap_anthropic_key']));
        update_option('btap_openai_key', sanitize_text_field($_POST['btap_openai_key']));
        update_option('btap_post_status', sanitize_text_field($_POST['btap_post_status']));
        update_option('btap_post_language', sanitize_text_field($_POST['btap_post_language']));
        update_option('btap_category_id', sanitize_text_field($_POST['btap_category_id']));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Settings saved!</p></div>';
        });
    }
});

add_action('wp_ajax_btap_generate_post', 'btap_handle_generate');

function btap_handle_generate() {
    check_ajax_referer('btap_generate_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $topic = sanitize_text_field($_POST['topic'] ?? '');
    if (empty($topic)) {
        wp_send_json_error(['message' => 'Topic cannot be empty!']);
    }

    $anthropic_key = get_option('btap_anthropic_key');
    $openai_key    = get_option('btap_openai_key');
    $language      = get_option('btap_post_language', 'English');
    $status        = get_option('btap_post_status', 'draft');
    $category_id   = (int) get_option('btap_category_id', 1);

    if (empty($anthropic_key)) {
        wp_send_json_error(['message' => 'Anthropic API key missing — add it in Settings']);
    }
    if (empty($openai_key)) {
        wp_send_json_error(['message' => 'OpenAI API key missing — add it in Settings']);
    }

    $content = btap_generate_content($topic, $anthropic_key, $language);
    if (is_wp_error($content)) {
        wp_send_json_error(['message' => $content->get_error_message()]);
    }

    $image_data = btap_generate_image($content['image_prompt'], $openai_key);
    if (is_wp_error($image_data)) {
        wp_send_json_error(['message' => $image_data->get_error_message()]);
    }

    $media_id = btap_upload_image($image_data, $topic);
    if (is_wp_error($media_id)) {
        wp_send_json_error(['message' => $media_id->get_error_message()]);
    }

    $post_id = wp_insert_post([
        'post_title'    => wp_strip_all_tags($content['title']),
        'post_content'  => wp_kses_post($content['content']),
        'post_excerpt'  => sanitize_text_field($content['excerpt']),
        'post_status'   => $status,
        'post_category' => [$category_id],
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => 'Post could not be created: ' . $post_id->get_error_message()]);
    }

    set_post_thumbnail($post_id, $media_id);

    wp_send_json_success([
        'title'    => $content['title'],
        'status'   => $status,
        'edit_url' => get_edit_post_link($post_id, 'raw'),
        'view_url' => get_permalink($post_id),
        'post_id'  => $post_id,
    ]);
}

function btap_generate_content($topic, $api_key, $language) {
    $prompt = "You are a professional blog writer. Write a complete WordPress blog post about: \"{$topic}\"\nLanguage: {$language}\nRespond ONLY with valid JSON, no markdown, no backticks:\n{\"title\":\"title here\",\"content\":\"<p>Full HTML min 600 words with h2 headings</p>\",\"excerpt\":\"max 160 chars\",\"image_prompt\":\"detailed image prompt photorealistic no text in image\"}";

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 2000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('claude_error', 'Could not connect to Claude API: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401) return new WP_Error('claude_error', 'Anthropic API key is invalid — check Settings');
    if ($code === 429) return new WP_Error('claude_error', 'Anthropic rate limit — please wait a moment and retry');
    if ($code !== 200) return new WP_Error('claude_error', 'Claude error ' . $code . ': ' . ($body['error']['message'] ?? 'Unknown'));

    $text = $body['content'][0]['text'] ?? '';
    $data = json_decode($text, true);

    if (!$data) {
        preg_match('/\{[\s\S]*\}/', $text, $matches);
        if (empty($matches)) return new WP_Error('claude_error', 'Could not parse Claude response — please retry');
        $data = json_decode($matches[0], true);
    }

    if (empty($data['title']) || empty($data['content'])) {
        return new WP_Error('claude_error', 'Claude response incomplete — please retry');
    }

    return $data;
}

function btap_generate_image($prompt, $api_key) {
    $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'model'   => 'gpt-image-1',
            'prompt'  => $prompt,
            'size'    => '1536x1024',
            'quality' => 'medium',
            'n'       => 1,
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('openai_error', 'Could not connect to OpenAI: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401) return new WP_Error('openai_error', 'OpenAI API key invalid — check platform.openai.com');
    if ($code === 429) return new WP_Error('openai_error', 'OpenAI rate limit — please wait a moment');
    if ($code !== 200) return new WP_Error('openai_error', 'Image error ' . $code . ': ' . ($body['error']['message'] ?? 'Unknown'));

    $b64 = $body['data'][0]['b64_json'] ?? '';
    if (empty($b64)) return new WP_Error('openai_error', 'No image data received — check OpenAI billing');

    return base64_decode($b64);
}

function btap_upload_image($image_data, $topic) {
    $upload = wp_upload_bits(
        'ai-' . sanitize_title($topic) . '-' . time() . '.jpg',
        null,
        $image_data
    );

    if (!empty($upload['error'])) {
        return new WP_Error('upload_error', 'Image upload failed: ' . $upload['error']);
    }

    $filetype = wp_check_filetype($upload['file']);
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($topic),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $upload['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}

function btap_main_page() {
    $anthropic_key = get_option('btap_anthropic_key');
    $openai_key    = get_option('btap_openai_key');
    $has_keys      = !empty($anthropic_key) && !empty($openai_key);
    ?>
    <div class="wrap">
        <h1>BT AutoPost</h1>

        <?php if (!$has_keys): ?>
        <div class="notice notice-warning">
            <p>⚠️ <strong>API Keys missing!</strong> Add your Anthropic and OpenAI keys in <a href="<?php echo admin_url('admin.php?page=bt-autopost-settings'); ?>">Settings</a>.</p>
        </div>
        <?php endif; ?>

        <div class="btap-card" style="background:#fff;padding:24px;border-radius:8px;max-width:600px;box-shadow:0 1px 4px rgba(0,0,0,0.1);margin-top:16px;">
            <table class="form-table">
                <tr>
                    <th><label for="btap_topic">Post Topic</label></th>
                    <td>
                        <input type="text" id="btap_topic" placeholder="e.g. Benefits of Solar Energy in India" style="width:100%;padding:10px;font-size:15px;border:1px solid #ccc;border-radius:4px;" <?php echo !$has_keys ? 'disabled' : ''; ?> />
                        <p class="description">Type a topic — Claude will handle everything else</p>
                    </td>
                </tr>
            </table>

            <div style="margin-top:16px;">
                <button id="btap_generate_btn" class="button button-primary button-large" <?php echo !$has_keys ? 'disabled' : ''; ?>>
                    Generate & Publish Post
                </button>
            </div>

            <div id="btap_progress" style="display:none;margin-top:20px;padding:16px;background:#f0f7ff;border-radius:6px;border-left:4px solid #0073aa;">
                <div id="btap_step" style="font-size:14px;color:#0073aa;font-weight:500;">Starting...</div>
                <div style="margin-top:8px;background:#ddd;border-radius:4px;height:6px;">
                    <div id="btap_bar" style="height:6px;background:#0073aa;border-radius:4px;width:0%;transition:width 0.5s;"></div>
                </div>
            </div>

            <div id="btap_result" style="display:none;margin-top:20px;"></div>
        </div>
    </div>

    <script>
    document.getElementById('btap_generate_btn').addEventListener('click', function() {
        const topic = document.getElementById('btap_topic').value.trim();
        if (!topic) { alert('Please type a topic!'); return; }

        const btn = this;
        const progress = document.getElementById('btap_progress');
        const result = document.getElementById('btap_result');
        const step = document.getElementById('btap_step');
        const bar = document.getElementById('btap_bar');

        btn.disabled = true;
        btn.textContent = '⏳ Generating...';
        progress.style.display = 'block';
        result.style.display = 'none';

        const steps = [
            { msg: '🤖 Generating content with Claude...', pct: 20 },
            { msg: '🎨 Generating AI image...', pct: 50 },
            { msg: '📤 Uploading image to WordPress...', pct: 75 },
            { msg: '📝 Publishing post...', pct: 90 },
        ];

        let i = 0;
        const ticker = setInterval(() => {
            if (i < steps.length) {
                step.textContent = steps[i].msg;
                bar.style.width = steps[i].pct + '%';
                i++;
            }
        }, 8000);

        const formData = new FormData();
        formData.append('action', 'btap_generate_post');
        formData.append('nonce', '<?php echo wp_create_nonce("btap_generate_nonce"); ?>');
        formData.append('topic', topic);

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData,
        })
        .then(r => r.json())
        .then(data => {
            clearInterval(ticker);
            bar.style.width = '100%';

            if (data.success) {
                step.textContent = '🎉 Post ready!';
                result.style.display = 'block';
                result.innerHTML = `
                    <div style="background:#f0fff4;border:1px solid #46b450;border-radius:6px;padding:16px;">
                        <h3 style="margin:0 0 8px;color:#1a1a1a;">✅ Post Successfully Created!</h3>
                        <p style="margin:4px 0;"><strong>Title:</strong> ${data.data.title}</p>
                        <p style="margin:4px 0;"><strong>Status:</strong> ${data.data.status}</p>
                        <div style="margin-top:12px;">
                            <a href="${data.data.edit_url}" class="button button-primary" target="_blank">✏️ Edit Post</a>
                            &nbsp;
                            <a href="${data.data.view_url}" class="button" target="_blank">👁️ View Post</a>
                        </div>
                    </div>`;
            } else {
                step.textContent = '❌ Error';
                bar.style.background = '#dc3232';
                result.style.display = 'block';
                result.innerHTML = `<div style="background:#fff0f0;border:1px solid #dc3232;border-radius:6px;padding:16px;color:#dc3232;"><strong>❌ Error:</strong> ${data.data.message}</div>`;
            }
        })
        .catch(err => {
            clearInterval(ticker);
            step.textContent = '❌ Network Error';
            result.style.display = 'block';
            result.innerHTML = `<div style="background:#fff0f0;border:1px solid #dc3232;border-radius:6px;padding:16px;color:#dc3232;"><strong>❌ Error:</strong> ${err.message}</div>`;
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = '✨ Generate & Publish Post';
        });
    });
    </script>
    <?php
}

function btap_settings_page() {
    $anthropic_key = get_option('btap_anthropic_key', '');
    $openai_key    = get_option('btap_openai_key', '');
    $post_status   = get_option('btap_post_status', 'draft');
    $language      = get_option('btap_post_language', 'English');
    $category_id   = get_option('btap_category_id', '1');
    $categories    = get_categories(['hide_empty' => false]);
    ?>
    <div class="wrap">
        <h1>BT AutoPost — Settings</h1>
        <form method="post">
            <?php wp_nonce_field('btap_settings_nonce'); ?>
            <div class="btap-card" style="background:#fff;padding:24px;border-radius:8px;max-width:600px;box-shadow:0 1px 4px rgba(0,0,0,0.1);margin-top:16px;">
                <h2 style="margin-top:0;">API Keys</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="btap_anthropic_key">Anthropic API Key</label></th>
                        <td>
                            <input type="password" id="btap_anthropic_key" name="btap_anthropic_key" value="<?php echo esc_attr($anthropic_key); ?>" style="width:100%;" placeholder="sk-ant-..." />
                            <p class="description">Get it from <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_openai_key">OpenAI API Key</label></th>
                        <td>
                            <input type="password" id="btap_openai_key" name="btap_openai_key" value="<?php echo esc_attr($openai_key); ?>" style="width:100%;" placeholder="sk-proj-..." />
                            <p class="description">Get it from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></p>
                        </td>
                    </tr>
                </table>

                <h2>Post Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="btap_post_status">Post Status</label></th>
                        <td>
                            <select id="btap_post_status" name="btap_post_status">
                                <option value="draft" <?php selected($post_status, 'draft'); ?>>Draft (publish after review)</option>
                                <option value="publish" <?php selected($post_status, 'publish'); ?>>Publish (go live directly)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_post_language">Language</label></th>
                        <td>
                            <select id="btap_post_language" name="btap_post_language">
                                <?php foreach(['English','Gujarati','Hindi','Marathi','Tamil','Bengali'] as $lang): ?>
                                <option value="<?php echo $lang; ?>" <?php selected($language, $lang); ?>><?php echo $lang; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_category_id">Category</label></th>
                        <td>
                            <select id="btap_category_id" name="btap_category_id">
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php selected($category_id, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="btap_save_settings" class="button button-primary button-large" value="Save Settings" />
                </p>
            </div>
        </form>
    </div>
    <?php
}
