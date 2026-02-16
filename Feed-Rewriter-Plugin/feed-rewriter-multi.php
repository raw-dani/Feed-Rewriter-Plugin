<?php
/**
 * Plugin Name: Feed Rewriter Multi
 * Plugin URI: https://github.com/raw-dani/Feed-Rewriter-Plugin
 * Description: Plugin untuk mengambil multiple feed RSS dan menulis ulang konten menggunakan OpenAI
 * Version: 2.0.0
 * Author: Rohmat Ali Wardani
 * Author URI: https://www.linkedin.com/in/rohmat-ali-wardani/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feed-rewriter-plugin
 * Domain Path: /languages
 */

// Register schedule 'minute' jika belum ada
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['minute'])) {
        $schedules['minute'] = [
            'interval' => 60,
            'display' => '1 Menit'
        ];
    }
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => 'Every Minute'
        ];
    }
    return $schedules;
});

// Daftarkan cron event jika belum ada
add_action('init', function() {
    if (!wp_next_scheduled('frp_cron_event')) {
        wp_schedule_event(time(), 'minute', 'frp_cron_event');
    }
    if (!wp_next_scheduled('frp_check_cron_status_event')) {
        wp_schedule_event(time(), 'every_minute', 'frp_check_cron_status_event');
    }
});

add_action('frp_cron_event', 'frp_cron_process');
add_action('frp_check_cron_status_event', 'frp_check_cron_status');

// Fungsi untuk mendaftarkan interval cron kustom
function frp_custom_cron_schedules($schedules) {
    $interval_minutes = get_option('frp_cron_interval', 60); // Default 60 menit
    $schedules['frp_custom_interval'] = [
        'interval' => $interval_minutes * 60, // Konversi menit ke detik
        'display'  => sprintf(__('Every %d minutes'), $interval_minutes)
    ];
    return $schedules;
}
add_filter('cron_schedules', 'frp_custom_cron_schedules');

// Fungsi logging sederhana
function frp_log_message($msg, $type = 'info') {
    $log_file = plugin_dir_path(__FILE__) . 'frp_log.txt';
    $timestamp = current_time('Y-m-d H:i:s');
    $source = isset($_POST['manual_execute']) ? '[Manual]' : '[Cron]';
    
    $log_entry = "[$timestamp] $source $msg\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[FeedRewriter] ' . $msg);
    }
}

// Mendapatkan dan menyimpan daftar feed
function frp_get_feeds() {
    $feeds = get_option('frp_feeds', []);
    return is_array($feeds) ? $feeds : [];
}
function frp_save_feeds($feeds) {
    update_option('frp_feeds', $feeds);
}

// Fungsi tambah/edit/delete feed
function frp_add_feed($category, $feed_url, $cron_interval) {
    $feeds = frp_get_feeds();
    $feeds[] = [
        'category' => $category,
        'feed_url' => $feed_url,
        'cron_interval' => $cron_interval,
        'last_run' => '',
    ];
    frp_save_feeds($feeds);
}
function frp_delete_feed($index) {
    $feeds = frp_get_feeds();
    if (isset($feeds[$index])) {
        unset($feeds[$index]);
        $feeds = array_values($feeds);
        frp_save_feeds($feeds);
    }
}
function frp_edit_feed($index, $category, $feed_url, $cron_interval) {
    $feeds = frp_get_feeds();
    if (isset($feeds[$index])) {
        $feeds[$index] = [
            'category' => $category,
            'feed_url' => $feed_url,
            'cron_interval' => $cron_interval,
            'last_run' => $feeds[$index]['last_run'],
        ];
        frp_save_feeds($feeds);
    }
}

// Admin menu dan halaman pengaturan
add_action('admin_menu', function() {
    add_menu_page(
        'Feed Rewriter Settings',
        'Feed Rewriter',
        'manage_options',
        'feed-rewriter',
        'frp_render_admin_page',
        'dashicons-rss',
        30
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Settings',
        'Settings',
        'manage_options',
        'feed-rewriter-settings',
        'frp_render_settings_page'
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Log',
        'Log',
        'manage_options',
        'feed-rewriter-log',
        'frp_render_log_page'
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Statistics',
        'Statistics',
        'manage_options',
        'feed-rewriter-stats',
        'frp_render_stats_page'
    );
});

// Render halaman admin utama untuk mengelola feed
function frp_render_admin_page() {
    if (isset($_POST['frp_save'])) {
        $feeds = [];
        if (isset($_POST['feeds'])) {
            foreach ($_POST['feeds'] as $feed) {
                if (!empty($feed['feed_url'])) {
                    $feeds[] = [
                        'category' => sanitize_text_field($feed['category']),
                        'feed_url' => esc_url_raw($feed['feed_url']),
                        'cron_interval' => sanitize_text_field($feed['cron_interval']),
                        'last_run' => isset($feed['last_run']) ? sanitize_text_field($feed['last_run']) : '',
                    ];
                }
            }
        }
        frp_save_feeds($feeds);
        echo '<div class="updated"><p>Feeds saved.</p></div>';
    }
    $feeds = frp_get_feeds();
    ?>
    <div class="wrap">
        <h1>Feed Rewriter - Manage Feeds</h1>
        
        <form method="post" id="feeds-form">
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Feed URL</th>
                        <th>Cron Interval</th>
                        <th>Last Run</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="feeds-body">
                    <?php foreach ($feeds as $index => $feed): ?>
                    <tr>
                        <td><input type="text" name="feeds[<?php echo $index; ?>][category]" value="<?php echo esc_attr($feed['category']); ?>" /></td>
                        <td><input type="url" name="feeds[<?php echo $index; ?>][feed_url]" value="<?php echo esc_attr($feed['feed_url']); ?>" style="width: 100%;" /></td>
                        <td>
                            <select name="feeds[<?php echo $index; ?>][cron_interval]">
                                <option value="hourly" <?php selected($feed['cron_interval'], 'hourly'); ?>>1 Jam</option>
                                <option value="3 hours" <?php selected($feed['cron_interval'], '3 hours'); ?>>3 Jam</option>
                                <option value="daily" <?php selected($feed['cron_interval'], 'daily'); ?>>1 Hari</option>
                            </select>
                        </td>
                        <td><?php echo !empty($feed['last_run']) ? esc_html($feed['last_run']) : 'Never'; ?></td>
                        <td>
                            <button type="button" class="button remove-row">Hapus</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Template untuk baris baru -->
                    <tr id="new-feed-row" style="display:none;">
                        <td><input type="text" name="feeds[new][category]" value="" /></td>
                        <td><input type="url" name="feeds[new][feed_url]" value="" style="width: 100%;" /></td>
                        <td>
                            <select name="feeds[new][cron_interval]">
                                <option value="hourly">1 Jam</option>
                                <option value="3 hours">3 Jam</option>
                                <option value="daily">1 Hari</option>
                            </select>
                        </td>
                        <td>Never</td>
                        <td>
                            <button type="button" class="button remove-row">Hapus</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p>
                <button type="button" id="add-feed" class="button">Tambah Feed Baru</button>
                <button type="submit" name="frp_save" class="button-primary">Simpan</button>
            </p>
        </form>
        
        <h2>Manual Execution</h2>
        <form method="post" action="">
            <?php wp_nonce_field('frp_manual_execution', 'frp_nonce'); ?>
            <input type="submit" name="manual_execute" value="Run Now" class="button button-primary" />
        </form>

        <?php
        // Cek apakah tombol "Run Now" ditekan
        if (isset($_POST['manual_execute']) && check_admin_referer('frp_manual_execution', 'frp_nonce')) {
            // Langsung jalankan fungsi tanpa mengubah status cron
            frp_log_message("Memulai eksekusi manual...");
            frp_cron_process();
            echo "<div class='notice notice-success'><p>Manual execution completed. Check logs for details.</p></div>";
        }
        
        // Tampilkan status cron
        frp_display_cron_status();
        ?>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hapus baris
            document.querySelectorAll('.remove-row').forEach(function(btn) {
                btn.onclick = function() {
                    this.closest('tr').remove();
                };
            });
            
            // Tambah baris baru
            document.getElementById('add-feed').onclick = function() {
                var template = document.getElementById('new-feed-row');
                var newRow = template.cloneNode(true);
                newRow.style.display = '';
                newRow.id = '';
                
                // Update nama field dengan index baru
                var newIndex = document.querySelectorAll('#feeds-body tr:not(#new-feed-row)').length;
                newRow.querySelectorAll('input, select').forEach(function(input) {
                    var name = input.getAttribute('name');
                    input.setAttribute('name', name.replace('feeds[new]', 'feeds[' + newIndex + ']'));
                });
                
                // Tambahkan event listener untuk tombol hapus
                newRow.querySelector('.remove-row').onclick = function() {
                    this.closest('tr').remove();
                };
                
                // Tambahkan ke tbody
                template.parentNode.insertBefore(newRow, template);
            };
        });
        </script>
    </div>
    <?php
}

// Render halaman pengaturan
function frp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Feed Rewriter Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('frp_settings_group');
            do_settings_sections('frp_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Mendaftarkan pengaturan
function frp_register_settings() {
    register_setting('frp_settings_group', 'frp_api_key');
    register_setting('frp_settings_group', 'frp_image_selector');    
    register_setting('frp_settings_group', 'frp_keyword_filter');
    register_setting('frp_settings_group', 'frp_exclude_keyword_filter');
    register_setting('frp_settings_group', 'frp_cron_interval');
    register_setting('frp_settings_group', 'frp_custom_prompt');
    register_setting('frp_settings_group', 'frp_model');
    register_setting('frp_settings_group', 'frp_max_tokens');
    register_setting('frp_settings_group', 'frp_temperature');
    register_setting('frp_settings_group', 'frp_fetch_latest_only');
    register_setting('frp_settings_group', 'frp_language');
    register_setting('frp_settings_group', 'frp_ignore_processed_urls');
    register_setting('frp_settings_group', 'frp_ignore_no_image');
    register_setting('frp_settings_group', 'frp_enable_toc');
    register_setting('frp_settings_group', 'frp_cron_status');

    add_settings_section('frp_main_settings', 'Main Settings', null, 'frp_settings');

    add_settings_field('frp_api_key', 'OpenAI API Key', 'frp_api_key_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_image_selector', 'Image Selector', 'frp_image_selector_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_keyword_filter', 'Keyword Filter', 'frp_keyword_filter_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_exclude_keyword_filter', 'Exclude Keyword Filter', 'frp_exclude_keyword_filter_callback', 'frp_settings', 'frp_main_settings');    
    add_settings_field('frp_cron_interval', 'Default Cron Interval (minutes)', 'frp_cron_interval_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_custom_prompt', 'Custom Prompt', 'frp_custom_prompt_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_model', 'OpenAI Model', 'frp_model_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_max_tokens', 'Max Tokens', 'frp_max_tokens_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_temperature', 'Temperature', 'frp_temperature_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_fetch_latest_only', 'Fetch Latest Articles Only', 'frp_fetch_latest_only_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_language', 'Language', 'frp_language_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_processed_urls', 'Ignore Processed URLs', 'frp_ignore_processed_urls_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_no_image', 'Ignore Articles Without Images', 'frp_ignore_no_image_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_enable_toc', 'Enable Table of Contents', 'frp_enable_toc_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_cron_status', 'Cron Status', 'frp_cron_status_callback', 'frp_settings', 'frp_main_settings');
}
add_action('admin_init', 'frp_register_settings');

// Callback functions for settings fields
function frp_api_key_callback() {
    $api_key = get_option('frp_api_key', '');
    echo "<input type='text' name='frp_api_key' value='" . esc_attr($api_key) . "' class='regular-text' />";
    echo "<p class='description'>Enter your OpenAI API key</p>";
}

function frp_image_selector_callback() {
    $image_selector = get_option('frp_image_selector', '');
    echo "<input type='text' name='frp_image_selector' value='" . esc_attr($image_selector) . "' class='regular-text' />";
    echo "<p class='description'>Enter CSS selector for images (e.g., .entry-content img, article img.featured)</p>";
}

function frp_keyword_filter_callback() {
    $keyword_filter = get_option('frp_keyword_filter', '');
    echo '<textarea name="frp_keyword_filter" rows="3" style="width: 100%;">' . esc_textarea($keyword_filter) . '</textarea>';
    echo '<p class="description">Enter keywords to filter articles, one per line. Articles will be processed if they contain any of these keywords.</p>';
}

function frp_exclude_keyword_filter_callback() {
    $exclude_keyword_filter = get_option('frp_exclude_keyword_filter', '');
    echo '<input type="text" name="frp_exclude_keyword_filter" value="' . esc_attr($exclude_keyword_filter) . '" class="regular-text" />';
    echo '<p class="description">Enter keywords to exclude articles (e.g., "video, sponsored"). Articles containing these words will be skipped.</p>';
}

function frp_cron_interval_callback() {
    $interval_minutes = get_option('frp_cron_interval', 60);
    echo "<input type='number' name='frp_cron_interval' value='" . esc_attr($interval_minutes) . "' min='1' /> minutes";
    echo "<p class='description'>Default interval for feeds that don't specify their own interval</p>";
}

function frp_custom_prompt_callback() {
    $custom_prompt = get_option('frp_custom_prompt', 'Rewrite the following content:');
    echo "<textarea name='frp_custom_prompt' rows='4' cols='50' style='width: 100%;'>" . esc_textarea($custom_prompt) . "</textarea>";
    echo "<p class='description'>Custom prompt for OpenAI to rewrite content</p>";
}

function frp_model_callback() {
    $model = get_option('frp_model', 'gpt-4o-mini');
    echo "<select name='frp_model'>
            <option value='gpt-3.5-turbo' " . selected($model, 'gpt-3.5-turbo', false) . ">GPT-3.5 Turbo</option>
            <option value='gpt-4' " . selected($model, 'gpt-4', false) . ">GPT-4</option>
            <option value='gpt-4o-mini' " . selected($model, 'gpt-4o-mini', false) . ">GPT-4o Mini</option>
          </select>";
}

function frp_max_tokens_callback() {
    $max_tokens = get_option('frp_max_tokens', 500);
    echo "<input type='number' name='frp_max_tokens' value='" . esc_attr($max_tokens) . "' min='0' step='1' />";
    echo "<p class='description'>Set max tokens. Set to 0 for unlimited tokens (default is 500).</p>";
}

function frp_temperature_callback() {
    $temperature = get_option('frp_temperature', 0.5);
    echo "<input type='number' name='frp_temperature' value='" . esc_attr($temperature) . "' min='0' max='2' step='0.1' />";
    echo "<p class='description'>Set the temperature value between 0 and 2 (default is 0.5). Lower values make the output more deterministic.</p>";
}

function frp_fetch_latest_only_callback() {
    $fetch_latest_only = get_option('frp_fetch_latest_only', false);
    echo "<input type='checkbox' name='frp_fetch_latest_only' value='1' " . checked(1, $fetch_latest_only, false) . " /> Only fetch latest articles";
}

function frp_language_callback() {
    $language = get_option('frp_language', 'en');
    echo "<select name='frp_language'>
            <option value='en' " . selected($language, 'en', false) . ">English</option>
            <option value='id' " . selected($language, 'id', false) . ">Bahasa Indonesia</option>
          </select>";
}

function frp_ignore_processed_urls_callback() {
    $ignore_processed_urls = get_option('frp_ignore_processed_urls', '1');
    echo "<input type='checkbox' name='frp_ignore_processed_urls' value='1' " . checked($ignore_processed_urls, '1', false) . " /> Prevent generating the same URL again";
}

function frp_ignore_no_image_callback() {
    $ignore_no_image = get_option('frp_ignore_no_image', '1');
    echo "<input type='checkbox' name='frp_ignore_no_image' value='1' " . checked($ignore_no_image, '1', false) . " /> Do not process articles without images";
}

function frp_enable_toc_callback() {
    $enable_toc = get_option('frp_enable_toc', 'yes');
    echo "<input type='checkbox' name='frp_enable_toc' value='yes' " . checked($enable_toc, 'yes', false) . " /> Enable Table of Contents in generated articles";
}

function frp_cron_status_callback() {
    $cron_status = get_option('frp_cron_status', 'active');
    $is_paused = ($cron_status === 'paused');
    
    echo '<button type="button" id="toggle-cron-status" class="button ' . ($is_paused ? 'button-primary' : '') . '">' . ($is_paused ? 'Resume Cron' : 'Pause Cron') . '</button>';
    echo '<p class="description">Click to pause or resume cron job.</p>';

    // JavaScript for toggling cron status
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#toggle-cron-status').on('click', function() {
                const isPaused = $(this).hasClass('button-primary');
                const action = isPaused ? 'resume' : 'pause';

                $.post(ajaxurl, {
                    action: 'frp_toggle_cron',
                    status: action,
                    _wpnonce: '<?php echo wp_create_nonce("frp_toggle_cron_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        const buttonText = action === 'pause' ? 'Resume Cron' : 'Pause Cron';
                        $('#toggle-cron-status').text(buttonText);
                        $('#toggle-cron-status').toggleClass('button-primary');
                        alert(response.data.message);
                    } else {
                        alert('Failed to change cron status.');
                    }
                });
            });
        });
    </script>
    <?php
}

// Fungsi untuk mengubah status cron melalui Ajax
add_action('wp_ajax_frp_toggle_cron', 'frp_toggle_cron_status');
function frp_toggle_cron_status() {
    check_ajax_referer('frp_toggle_cron_nonce', '_wpnonce');

    $status = sanitize_text_field($_POST['status']);
    
    if ($status === 'pause') {
        update_option('frp_cron_status', 'paused');
        wp_send_json_success(['message' => 'Cron job has been paused.']);
    } elseif ($status === 'resume') {
        update_option('frp_cron_status', 'active');
        wp_send_json_success(['message' => 'Cron job has been resumed.']);
    } else {
        wp_send_json_error(['message' => 'Invalid status.']);
    }
}

// Proses semua feed sesuai jadwal
function frp_cron_process() {
    // Cek status cron
    $cron_status = get_option('frp_cron_status', 'active');
    if ($cron_status === 'paused') {
        frp_log_message("Cron is paused. Skipping execution.");
        return;
    }

    // Cek apakah cron sedang berjalan
    $cron_running = get_transient('frp_cron_running');
    if ($cron_running) {
        frp_log_message("Cron is already running. Skipping this execution.");
        return;
    }

    // Set flag bahwa cron sedang berjalan (berlaku selama 30 menit)
    set_transient('frp_cron_running', true, 30 * MINUTE_IN_SECONDS);

    try {
        $feeds = frp_get_feeds();
        $now = current_time('timestamp');
        $processed_urls = get_option('frp_processed_urls', []);

        foreach ($feeds as &$feed) {
            $last_run = !empty($feed['last_run']) ? strtotime($feed['last_run']) : 0;
            switch ($feed['cron_interval']) {
                case 'hourly':
                    $interval_seconds = 3600;
                    break;
                case '3 hours':
                    $interval_seconds = 3 * 3600;
                    break;
                case 'daily':
                    $interval_seconds = 86400;
                    break;
                default:
                    $interval_seconds = 3600;
            }
            if (empty($feed['last_run']) || ($now - $last_run) >= $interval_seconds) {
                frp_log_message("Processing feed: " . $feed['feed_url']);
                frp_process_feed($feed['feed_url'], $feed['category'], $processed_urls);
                $feed['last_run'] = date('Y-m-d H:i:s', $now);
            }
        }
        frp_save_feeds($feeds);
        
        // Update waktu terakhir cron berjalan
        update_option('frp_last_cron_run', current_time('mysql'));
    } catch (Exception $e) {
        frp_log_message("Error in cron execution: " . $e->getMessage());
    } finally {
        // Hapus flag cron running
        delete_transient('frp_cron_running');
    }
}

// Fungsi untuk memastikan kategori ada
function frp_ensure_category($category_name) {
    $cat_id = get_cat_ID($category_name);
    if ($cat_id == 0) {
        // Kategori belum ada, buat baru
        $cat_id = wp_create_category($category_name);
    }
    return $cat_id;
}

// Fungsi utama proses feed
function frp_process_feed($feed_url, $category, &$processed_urls) {
    $response = wp_remote_get($feed_url);
    if (is_wp_error($response)) {
        frp_log_message("Gagal fetch feed: " . $response->get_error_message());
        return;
    }
    $body = wp_remote_retrieve_body($response);
    frp_log_message("Raw feed length: " . strlen($body));
    
    try {
        $xml = new SimpleXMLElement($body);
        $article_found = false;
        $processed_count = 0;

        // Pastikan kategori ada
        $category_id = frp_ensure_category($category);

        // Ambil pengaturan
        $ignore_processed_urls = get_option('frp_ignore_processed_urls', '1');
        $ignore_no_image = get_option('frp_ignore_no_image', '1');
        $keyword_filter = get_option('frp_keyword_filter', '');
        $keywords = array_filter(array_map('trim', explode("\n", $keyword_filter)));
        $exclude_keyword_filter = get_option('frp_exclude_keyword_filter', '');
        $exclude_keywords = array_map('trim', explode(',', strtolower($exclude_keyword_filter)));

        if ($xml->channel) {
            frp_log_message("Detected RSS feed");
            foreach ($xml->channel->item as $item) {
                // Batasi jumlah artikel yang diproses per run
                if ($processed_count >= 5) break;
                
                $title = strip_tags((string)$item->title);
                $link = (string)$item->link;
                $pubDate = date('Y-m-d H:i:s', strtotime((string)$item->pubDate));
                
                // Cek apakah artikel sudah ada berdasarkan judul atau URL
                $existing = get_page_by_title($title, OBJECT, 'post');
                if ($existing) {
                    frp_log_message("Artikel sudah ada dengan judul: " . $title);
                    continue;
                }
                
                // Cek apakah URL sudah diproses sebelumnya
                if ($ignore_processed_urls === '1' && in_array($link, $processed_urls)) {
                    frp_log_message("Skipping already processed URL: " . $link);
                    continue;
                }
                
                // Reset image_url
                $image_url = '';
                
                // 1. Cek enclosure dengan namespace yang benar
                if (isset($item->enclosure)) {
                    $image_url = (string)$item->enclosure['url'];
                    frp_log_message("Image found from enclosure: " . $image_url);
                }
                
                // 2. Jika tidak ada, coba ambil dari description
                if (empty($image_url)) {
                    $description = (string)$item->description;
                    if (preg_match('/<img[^>]+src="([^"]+)"/', html_entity_decode($description), $matches)) {
                        $image_url = $matches[1];
                        frp_log_message("Image found from description: " . $image_url);
                    }
                }
                
                // Bersihkan URL gambar dari karakter HTML entities
                if (!empty($image_url)) {
                    $image_url = html_entity_decode($image_url);
                    // Pastikan URL gambar menggunakan HTTPS
                    $image_url = str_replace('http://', 'https://', $image_url);
                    frp_log_message("Final cleaned image URL: " . $image_url);
                }
                
                // Cek apakah artikel memiliki gambar jika pengaturan ignore_no_image aktif
                if ($ignore_no_image === '1' && empty($image_url)) {
                    frp_log_message("Skipping article without image: " . $title);
                    continue;
                }
                
                // Ambil deskripsi tanpa tag HTML
                $description = strip_tags(html_entity_decode((string)$item->description));
                
                // Ambil content:encoded jika ada
                $content_encoded = '';
                if (isset($item->children('content', true)->encoded)) {
                    $content_encoded = strip_tags(html_entity_decode((string)$item->children('content', true)->encoded));
                }
                
                // Ambil konten lengkap dari URL artikel
                frp_log_message("Fetching full article content from: " . $link);
                $article_content = frp_extract_content_from_url($link);
                
                if (empty($article_content)) {
                    frp_log_message("Tidak dapat mengekstrak konten dari artikel: " . $link);
                    continue;
                }
                
                // Cek filter kata kunci
                if (!empty($keywords)) {
                    $keyword_found = false;
                    foreach ($keywords as $keyword) {
                        if (!empty($keyword) && 
                            (stripos($title, $keyword) !== false || 
                            stripos($article_content, $keyword) !== false)) {
                            $keyword_found = true;
                            frp_log_message("Keyword ditemukan: '$keyword'");
                            break;
                        }
                    }
                    
                    if (!$keyword_found) {
                        frp_log_message("Skip artikel - tidak mengandung kata kunci yang diinginkan");
                        continue;
                    }
                }
                
                // Cek kata kunci yang dikecualikan
                $skip_article = false;
                foreach ($exclude_keywords as $exclude_keyword) {
                    if (!empty($exclude_keyword) && (stripos($title, $exclude_keyword) !== false || 
                        stripos($article_content, $exclude_keyword) !== false)) {
                        frp_log_message("Skipping article as it contains excluded keyword '{$exclude_keyword}'");
                        $skip_article = true;
                        break;
                    }
                }
                if ($skip_article) continue;
                
                // Proses rewrite dengan OpenAI
                frp_log_message("Starting content rewriting process...");
                $new_title_and_content = frp_rewrite_title_and_content($title, $article_content);
                
                if ($new_title_and_content) {
                    // Post ke WordPress
                    $post_id = wp_insert_post([
                        'post_title' => $new_title_and_content['title'],
                        'post_content' => $new_title_and_content['content'],
                        'post_status' => 'publish',
                        'post_type' => 'post',
                        'post_category' => [$category_id],
                        'post_date' => $pubDate,
                        'meta_input' => [
                            '_frp_generated' => 1,
                            '_frp_source_feed' => $feed_url,
                            '_frp_source_url' => $link,
                            '_frp_processed_date' => current_time('mysql')
                        ],
                    ]);
                    
                    if ($post_id) {
                        frp_log_message("Successfully created new post with ID: " . $post_id);
                        
                        // Set featured image jika ada
                        if ($image_url) {
                            frp_set_featured_image($post_id, $image_url, $new_title_and_content['title']);
                        }
                        
                        // Generate dan set tags
                        $tags = frp_generate_tags($new_title_and_content['content']);
                        if ($tags) {
                            wp_set_post_tags($post_id, $tags);
                        }
                        
                        // Update processed URLs
                        $processed_urls[] = $link;
                        update_option('frp_processed_urls', $processed_urls);
                        
                        $article_found = true;
                        $processed_count++;
                    } else {
                        frp_log_message("Failed to create new post");
                    }
                } else {
                    frp_log_message("Failed to rewrite content");
                }
            }
        } else if ($xml->entry) {
            // Proses untuk format Atom
            frp_log_message("Detected Atom feed");
            foreach ($xml->entry as $entry) {
                // Batasi jumlah artikel yang diproses per run
                if ($processed_count >= 5) break;
                
                $title = strip_tags((string)$entry->title);
                $link = '';
                
                // Cari link dalam entry
                foreach ($entry->link as $entryLink) {
                    if ((string)$entryLink['rel'] === 'alternate' || empty($entryLink['rel'])) {
                        $link = (string)$entryLink['href'];
                        break;
                    }
                }
                
                if (empty($link)) {
                    frp_log_message("No valid link found for entry: " . $title);
                    continue;
                }
                
                $pubDate = isset($entry->published) ? date('Y-m-d H:i:s', strtotime((string)$entry->published)) : 
                          (isset($entry->updated) ? date('Y-m-d H:i:s', strtotime((string)$entry->updated)) : current_time('mysql'));
                
                // Implementasi logika yang sama seperti untuk RSS
                // Cek apakah artikel sudah ada berdasarkan judul
                $existing = get_page_by_title($title, OBJECT, 'post');
                if ($existing) {
                    frp_log_message("Artikel sudah ada: " . $title);
                    continue;
                }
                
                // Cek apakah URL sudah diproses sebelumnya
                if ($ignore_processed_urls === '1' && in_array($link, $processed_urls)) {
                    frp_log_message("Skipping already processed URL: " . $link);
                    continue;
                }
                
                // Ambil konten dari feed atau dari link
                $content = '';
                if (isset($entry->content)) {
                    $content = (string)$entry->content;
                } elseif (isset($entry->summary)) {
                    $content = (string)$entry->summary;
                } else {
                    // Jika tidak ada konten di feed, coba ambil dari link
                    $content = frp_extract_content_from_url($link);
                }
                
                if (empty($content)) {
                    frp_log_message("Konten kosong untuk: " . $title);
                    continue;
                }
                
                // Proses rewrite dengan OpenAI
                frp_log_message("Starting content rewriting process...");
                $new_title_and_content = frp_rewrite_title_and_content($title, $content);
                
                if ($new_title_and_content) {
                    // Post ke WordPress
                    $post_id = wp_insert_post([
                        'post_title' => $new_title_and_content['title'],
                        'post_content' => $new_title_and_content['content'],
                        'post_status' => 'publish',
                        'post_type' => 'post',
                        'post_category' => [$category_id],
                        'post_date' => $pubDate,
                        'meta_input' => [
                            '_frp_generated' => 1,
                            '_frp_source_feed' => $feed_url,
                            '_frp_source_url' => $link,
                            '_frp_processed_date' => current_time('mysql')
                        ],
                    ]);
                    
                    if ($post_id) {
                        frp_log_message("Successfully created new post with ID: " . $post_id);
                        
                        // Generate dan set tags
                        $tags = frp_generate_tags($new_title_and_content['content']);
                        if ($tags) {
                            wp_set_post_tags($post_id, $tags);
                        }
                        
                        // Update processed URLs
                        $processed_urls[] = $link;
                        update_option('frp_processed_urls', $processed_urls);
                        
                        $article_found = true;
                        $processed_count++;
                    } else {
                        frp_log_message("Failed to create new post");
                    }
                } else {
                    frp_log_message("Failed to rewrite content");
                }
            }
        }
        
        if (!$article_found) {
            frp_log_message("Tidak ada artikel baru ditemukan di feed: " . $feed_url);
        }
    } catch (Exception $e) {
        frp_log_message("Error parsing feed: " . $e->getMessage());
    }
}

// Fungsi untuk mengekstrak konten dari URL
function frp_extract_content_from_url($url) {
    $response = wp_remote_get($url, [
        'timeout' => 30,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'headers' => [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("Gagal mengambil konten dari URL: " . $response->get_error_message());
        return '';
    }
    
    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        return '';
    }
    
    // Coba load HTML dengan DOMDocument
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Tambahkan ekstraksi konten khusus untuk CNN Indonesia
    if (strpos($url, 'cnnindonesia.com') !== false) {
        return frp_extract_cnn_content($html);
    }
    
    // Array selector untuk berbagai situs
    $selectors = [
        "//article[contains(@class, 'article-content')]",
        "//div[contains(@class, 'article-body')]",
        "//div[contains(@class, 'entry-content')]",
        "//main//article",
        "//div[contains(@class, 'post-content')]",
        "//div[@id='content']",
        "//div[@class='content']",
        "//div[contains(@class, 'detail-text')]",
        "//div[contains(@class, 'content-article')]//p",
        "//div[contains(@class, 'detail_text')]",
        "//div[contains(@class, 'article-content')]"
    ];
    
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = '';
            foreach ($nodes as $node) {
                $content .= $doc->saveHTML($node);
            }
            
            // Bersihkan konten
            $content = frp_clean_text($content);
            
            if (!empty($content)) {
                return $content;
            }
        }
    }
    
    // Jika tidak ada selector yang cocok, ambil paragraf
    $paragraphs = $xpath->query('//p');
    if ($paragraphs->length > 0) {
        $content = '';
        foreach ($paragraphs as $p) {
            // Hanya ambil paragraf yang cukup panjang (menghindari footer, dll)
            if (strlen($p->textContent) > 100) {
                $content .= $doc->saveHTML($p);
            }
        }
        if (!empty($content)) {
            return frp_clean_text($content);
        }
    }
    
    frp_log_message("Tidak dapat menemukan konten dengan selector yang tersedia: " . $url);
    return '';
}

// Fungsi khusus untuk mengekstrak konten CNN Indonesia
function frp_extract_cnn_content($html) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk CNN Indonesia
    $selectors = [
        "//div[contains(@class, 'detail-text')]",
        "//div[contains(@class, 'content-article')]//p",
        "//div[contains(@class, 'detail_text')]",
        "//div[contains(@class, 'article-content')]"
    ];
    
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = '';
            foreach ($nodes as $node) {
                // Skip jika paragraf kosong atau hanya berisi spasi
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $content .= $text . "\n\n";
                }
            }
            
            if (!empty($content)) {
                frp_log_message("Berhasil mengekstrak konten CNN Indonesia");
                return trim($content);
            }
        }
    }
    
    frp_log_message("Gagal mengekstrak konten CNN Indonesia dengan selector yang tersedia");
    return '';
}

// Fungsi untuk membersihkan teks
function frp_clean_text($text, $is_title = false) {
    if ($is_title) {
        // Menghapus kata "Judul" dari title
        $text = preg_replace('/\bJudul\b/i', '', $text); 
        // Membersihkan karakter aneh pada judul
        $text = preg_replace('/[^a-zA-Z0-9\s\p{L}]/u', '', $text); // Menghapus karakter selain huruf, angka, dan spasi
        $text = trim($text); // Menghapus spasi berlebihan
        return $text;
    } else {
        // Hapus script dan style tags
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);
        
        // Menghapus kata "Konten:" atau "Isi:" dari awal konten
        $text = preg_replace('/^(Konten:|Isi:)\s*/i', '', $text);

        // Mengubah format `### Kalimat` menjadi `<h2>Kalimat</h2>`
        $text = preg_replace('/^###\s*(.*)$/m', '<h2>$1</h2>', $text);

        // Mengubah teks di dalam tanda bintang dua `**teks**` menjadi teks bold `<strong>teks</strong>`
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        
        // Hapus atribut yang tidak diinginkan
        $text = preg_replace('/\s(onclick|onload|onerror|onmouseover|onmouseout|style)="[^"]*"/i', '', $text);
        
        // Hapus komentar HTML
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        
        // Mengecek apakah TOC diaktifkan
        $enable_toc = get_option('frp_enable_toc', 'yes');
        
        // Membuat Table of Contents (TOC) berdasarkan <h2> jika diaktifkan
        $toc = [];
        $cleaned_content = $text;

        if ($enable_toc === 'yes') {
            // Mencari semua heading H2 dalam konten
            preg_match_all('/<h2[^>]*>(.*?)<\/h2>/i', $cleaned_content, $matches);
            
            if (!empty($matches[1])) {
                $toc[] = "<div class='frp-toc'><h3>Table of Contents</h3><ul>";
                foreach ($matches[1] as $index => $heading) {
                    // Buat ID untuk setiap heading berdasarkan teks heading
                    $slug = sanitize_title_with_dashes(strip_tags($heading));
                    
                    // Tambahkan item TOC dengan link ke ID heading
                    $toc[] = "<li><a href='#{$slug}'>" . strip_tags($heading) . "</a></li>";
                    
                    // Menambahkan ID pada setiap <h2> di konten
                    $cleaned_content = preg_replace(
                        '/<h2[^>]*>' . preg_quote($heading, '/') . '<\/h2>/i',
                        '<h2 id="' . $slug . '">' . $heading . '</h2>',
                        $cleaned_content,
                        1
                    );
                }
                $toc[] = "</ul></div>";
            }
        }

        // Menggabungkan TOC dan konten jika TOC diaktifkan
        if ($enable_toc === 'yes' && !empty($toc)) {
            $cleaned_content = implode("\n", $toc) . "\n" . $cleaned_content;
        }
        
        return $cleaned_content;
    }
}

// Fungsi untuk menulis ulang judul dan konten dengan OpenAI
function frp_rewrite_title_and_content($title, $content) {
    $api_key = get_option('frp_api_key');
    $custom_prompt = get_option('frp_custom_prompt', 'Rewrite the following title and content:');
    $model = get_option('frp_model', 'gpt-4o-mini');
    $language = get_option('frp_language', 'en');
    $max_tokens = get_option('frp_max_tokens', 500);
    $temperature = get_option('frp_temperature', 0.5);

    if (empty($api_key)) {
        frp_log_message("OpenAI API key is missing.");
        return null;
    }

    // Buat prompt untuk rewrite judul dan konten dengan bahasa yang dipilih
    $prompt = "{$custom_prompt}\n\n" . 
          "Rewrite the following title and content to make it suitable for publishing. " . 
          "Ensure the title is concise (maximum 65 characters) and engaging, and the content is well-structured without using unnecessary labels like 'Title:', 'Content:', or 'Let's continue...'. " . 
          "The rewritten text should be in " . ($language === 'id' ? 'Bahasa Indonesia' : 'English') . ".\n\n" .
          "Title: {$title}\n\n" .
          "Content:\n{$content}\n\n" .
          "Please provide a polished and publishable title and content.";

    // Buat request ke OpenAI API
    $endpoint = 'https://api.openai.com/v1/chat/completions';

    // Jika max tokens diset ke 0, maka tidak akan ditambahkan dalam request
    $request_body = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => (float) $temperature
    ];

    if ($max_tokens > 0) {
        $request_body['max_tokens'] = (int) $max_tokens;
    }

    $response = wp_remote_post($endpoint, [
        'body' => json_encode($request_body),
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        frp_log_message("API request failed: " . $response->get_error_message());
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['choices'][0]['message']['content'])) {
        $generated_text = explode("\n\n", $body['choices'][0]['message']['content'], 2);
        
        // Bersihkan judul dari karakter yang tidak diinginkan
        $cleaned_title = frp_clean_text(trim($generated_text[0]), true);

        // Bersihkan konten dan buat TOC
        $cleaned_content = frp_clean_text(trim($generated_text[1] ?? ''), false);

        return [
            'title' => $cleaned_title,
            'content' => $cleaned_content
        ];
    } else {
        frp_log_message("Error in API response: " . json_encode($body));
        return null;
    }
}

// Fungsi untuk menghasilkan tag berdasarkan konten menggunakan OpenAI
function frp_generate_tags($content) {
    $openai_api_key = get_option('frp_api_key');
    $selected_model = get_option('frp_model', 'gpt-4o-mini');
    
    if (empty($openai_api_key)) {
        frp_log_message("OpenAI API key is missing.");
        return [];
    }

    $prompt = "Berdasarkan konten berikut:\n\n\"{$content}\"\n\nBuatlah 5 tag yang relevan untuk konten ini. Tulis tag dalam format sederhana seperti ini: 'tag1, tag2, tag3, ...'. Fokus pada kata kunci utama yang relevan dengan konten tersebut.";

    $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $openai_api_key,
    ];

    $data = [
        'model' => $selected_model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant that generates relevant tags for content based on the key topics.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 50,
        'temperature' => 0.5,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => $headers,
        'body' => json_encode($data),
        'timeout' => 20,
    ]);

    // Cek respons API
    if (is_wp_error($response)) {
        frp_log_message("Failed to generate tags: " . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    // Pastikan respons berisi pilihan dan konten yang valid
    if (isset($result['choices'][0]['message']['content'])) {
        $tag_text = $result['choices'][0]['message']['content'];
        $tags = array_map('trim', explode(',', $tag_text));
        return $tags;
    } else {
        frp_log_message("OpenAI response is invalid or empty.");
        return [];
    }
}

// Fungsi untuk mengatur featured image
function frp_set_featured_image($post_id, $image_url, $post_title) {
    if (empty($image_url)) {
        frp_log_message("No image URL provided for featured image");
        return;
    }

    frp_log_message("Attempting to set featured image from URL: " . $image_url);

    // Bersihkan URL gambar
    $image_url = html_entity_decode($image_url);
    
    // Tambahkan konteks stream untuk HTTPS
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]
    ]);

    // Coba ambil gambar dengan konteks yang sudah diatur
    $image_data = @file_get_contents($image_url, false, $context);

    if ($image_data === false) {
        frp_log_message("Failed to download image from URL: " . $image_url);
        return;
    }

    $upload_dir = wp_upload_dir();
    
    // Dapatkan ekstensi file dari URL
    $file_extension = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    if (empty($file_extension)) {
        $file_extension = 'jpg'; // default to jpg if no extension found
    }

    // Buat nama file yang aman
    $filename = sanitize_file_name($post_title . '-' . uniqid() . '.' . $file_extension);

    if (wp_mkdir_p($upload_dir['path'])) {
        $file = $upload_dir['path'] . '/' . $filename;
    } else {
        $file = $upload_dir['basedir'] . '/' . $filename;
    }

    // Simpan file
    if (file_put_contents($file, $image_data)) {
        frp_log_message("Image saved successfully to: " . $file);
        
        // Set attachment
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $post_title,
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        if ($attach_id) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            wp_update_attachment_metadata($attach_id, $attach_data);
            set_post_thumbnail($post_id, $attach_id);
            frp_log_message("Featured image set successfully with ID: " . $attach_id);
        } else {
            frp_log_message("Failed to create attachment for image");
        }
    } else {
        frp_log_message("Failed to save image file");
    }
}

// Fungsi untuk mengekstrak gambar dari artikel
function frp_extract_article_image($html, $url) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Ambil selector kustom dari pengaturan
    $custom_selector = get_option('frp_image_selector', '');
    
    if (!empty($custom_selector)) {
        // Ubah selector CSS ke format XPath
        $selectors = explode(',', $custom_selector);
        foreach ($selectors as $selector) {
            $selector = trim($selector);
            // Konversi selector CSS sederhana ke XPath
            $xpath_selector = strtr($selector, [
                '.' => "//*[contains(@class, '",
                '#' => "//*[@id='",
                ' ' => "']//",
                '[' => "'][@",
                ']' => "]"
            ]) . "']//img";
            
            $images = $xpath->query($xpath_selector);
            if ($images->length > 0) {
                return $images->item(0)->getAttribute('src');
            }
        }
    }
    
        // Fallback ke selector default jika tidak ada gambar ditemukan
        $default_selectors = [
            "//meta[@property='og:image']/@content",
            "//article//img[1]/@src",
            "//div[contains(@class, 'entry-content')]//img[1]/@src",
            "//div[contains(@class, 'post-content')]//img[1]/@src"
        ];
        
        foreach ($default_selectors as $selector) {
            $images = $xpath->query($selector);
            if ($images->length > 0) {
                return $images->item(0)->value;
            }
        }
        
        return '';
    }
    
    // Fungsi untuk menampilkan log dari file
    function frp_render_log_page() {
        $log_file = plugin_dir_path(__FILE__) . 'frp_log.txt';
        ?>
        <div class="wrap">
            <h1>Feed Rewriter Log</h1>
            <?php
            if (file_exists($log_file)) {
                $log_entries = array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                $display_logs = array_slice($log_entries, 0, 100); // Tampilkan 100 log terakhir
                
                echo '<div style="max-height: 500px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ccc;">';
                if (!empty($display_logs)) {
                    echo '<ul style="margin: 0; padding-left: 20px;">';
                    foreach ($display_logs as $log) {
                        echo '<li>' . esc_html($log) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No log entries available.</p>';
                }
                echo '</div>';
                
                // Tambahkan tombol untuk membersihkan log
                echo '<form method="post">';
                wp_nonce_field('frp_clear_log_nonce', 'frp_clear_log_nonce');
                echo '<p><button type="submit" name="frp_clear_log" class="button">Clear Log</button></p>';
                echo '</form>';
                
                // Proses pembersihan log
                if (isset($_POST['frp_clear_log']) && check_admin_referer('frp_clear_log_nonce', 'frp_clear_log_nonce')) {
                    file_put_contents($log_file, '');
                    echo '<div class="notice notice-success"><p>Log has been cleared.</p></div>';
                    echo '<script>window.location.reload();</script>';
                }
            } else {
                echo '<p>Log file not found.</p>';
            }
            ?>
        </div>
        <?php
    }
    
    // Fungsi untuk menampilkan statistik
    function frp_render_stats_page() {
        global $wpdb;
        
        // Hitung jumlah post per kategori
        $categories = get_categories(['hide_empty' => false]);
        $stats = [];
        
        foreach ($categories as $cat) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->posts p
                JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id
                JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE p.post_type = 'post' AND p.post_status = 'publish'
                AND tt.taxonomy = 'category' AND tt.term_id = %d",
                $cat->term_id
            ));
            
            $stats[] = [
                'category' => $cat->name,
                'count' => $count
            ];
        }
        
        // Hitung jumlah artikel yang dihasilkan oleh plugin
        $generated_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $wpdb->posts p
            JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND pm.meta_key = '_frp_generated' AND pm.meta_value = '1'"
        );
        
        // Hitung jumlah artikel yang dihasilkan per hari dalam 30 hari terakhir
        $daily_stats = $wpdb->get_results(
            "SELECT DATE(p.post_date) as post_date, COUNT(*) as count
            FROM $wpdb->posts p
            JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            AND pm.meta_key = '_frp_generated' AND pm.meta_value = '1'
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(p.post_date)
            ORDER BY p.post_date DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>Feed Rewriter Statistics</h1>
            
            <div style="display: flex; margin-bottom: 20px;">
                <div style="flex: 1; margin-right: 20px;">
                    <h2>Total Generated Articles</h2>
                    <div style="font-size: 24px; font-weight: bold; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; text-align: center;">
                        <?php echo esc_html($generated_count); ?>
                    </div>
                </div>
                
                <div style="flex: 1;">
                    <h2>Articles Generated (Last 30 Days)</h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($daily_stats)) {
                                foreach ($daily_stats as $stat) {
                                    echo '<tr>';
                                    echo '<td>' . esc_html($stat->post_date) . '</td>';
                                    echo '<td>' . esc_html($stat->count) . '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="2">No data available</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <h2>Articles by Category</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Post Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $stat): ?>
                    <tr>
                        <td><?php echo esc_html($stat['category']); ?></td>
                        <td><?php echo esc_html($stat['count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    // Fungsi untuk menampilkan status cron
    function frp_display_cron_status() {
        $cron_status = get_option('frp_cron_status', 'active');
        $next_run = wp_next_scheduled('frp_cron_event');
        
        echo '<div class="frp-cron-status" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">';
        echo '<h3>Cron Status</h3>';
        
        if ($cron_status === 'paused') {
            echo '<p style="color: #d63638;"><strong>Status:</strong> Paused</p>';
        } else {
            echo '<p style="color: #00a32a;"><strong>Status:</strong> Active</p>';
        }
        
        if ($next_run) {
            $time_diff = $next_run - time();
            if ($time_diff < 0) {
                echo '<p style="color: #d63638;"><strong>Next Run:</strong> Scheduled but ' . abs(round($time_diff / 60)) . ' minutes late</p>';
            } else {
                echo '<p><strong>Next Run:</strong> ' . date('Y-m-d H:i:s', $next_run) . ' (' . round($time_diff / 60) . ' minutes from now)</p>';
            }
        } else {
            echo '<p style="color: #d63638;"><strong>Next Run:</strong> Not scheduled. Please reactivate the plugin.</p>';
        }
        
        // Tampilkan waktu terakhir cron berjalan
        $last_cron_run = get_option('frp_last_cron_run');
        if ($last_cron_run) {
            echo '<p><strong>Last Run:</strong> ' . esc_html($last_cron_run) . '</p>';
        } else {
            echo '<p><strong>Last Run:</strong> Never</p>';
        }
        
        echo '</div>';
    }
    
    // Fungsi untuk mengecek status cron
    function frp_check_cron_status() {
        static $last_status_check = 0;
        $check_interval = 300; // Cek setiap 5 menit
        
        if ((time() - $last_status_check) < $check_interval) {
            return;
        }
        
        $event_hook = 'frp_cron_event';
        $next_scheduled = wp_next_scheduled($event_hook);
        
        if ($next_scheduled) {
            $time_left = $next_scheduled - time();
            if ($time_left > 0) {
                $minutes = floor($time_left / 60);
                $seconds = $time_left % 60;
                frp_log_message("Time until next cron execution: {$minutes}m {$seconds}s");
                $last_status_check = time();
            }
        }
    }
    
    // Fungsi untuk menampilkan daftar artikel yang telah digenerate
    function frp_display_generated_articles($max_posts = 20) {
        $args = [
            'post_type' => 'post',
            'posts_per_page' => $max_posts,
            'meta_query' => [
                [
                    'key' => '_frp_generated',
                    'value' => '1',
                    'compare' => '='
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
    
        $query = new WP_Query($args);
    
        echo '<div style="max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ccc;">';
        if ($query->have_posts()) {
            echo '<ul>';
            while ($query->have_posts()) {
                $query->the_post();
                echo '<li><a href="' . get_permalink() . '" target="_blank">' . get_the_title() . '</a> (' . get_the_date() . ')</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No generated articles found.</p>';
        }
        wp_reset_postdata();
        echo '</div>';
    }
    
    // Fungsi untuk menambahkan meta box di halaman edit post
    function frp_add_meta_box() {
        add_meta_box(
            'frp_meta_box',
            'Feed Rewriter Info',
            'frp_render_meta_box',
            'post',
            'side',
            'high'
        );
    }
    add_action('add_meta_boxes', 'frp_add_meta_box');
    
    // Render meta box
    function frp_render_meta_box($post) {
        $source_url = get_post_meta($post->ID, '_frp_source_url', true);
        $processed_date = get_post_meta($post->ID, '_frp_processed_date', true);
        $source_feed = get_post_meta($post->ID, '_frp_source_feed', true);
        
        echo '<p><strong>Source URL:</strong><br>';
        if (!empty($source_url)) {
            echo '<a href="' . esc_url($source_url) . '" target="_blank">' . esc_url($source_url) . '</a>';
        } else {
            echo 'Not available';
        }
        echo '</p>';
        
        echo '<p><strong>Source Feed:</strong><br>';
        echo !empty($source_feed) ? esc_html($source_feed) : 'Not available';
        echo '</p>';
        
        echo '<p><strong>Processed Date:</strong><br>';
        echo !empty($processed_date) ? esc_html($processed_date) : 'Not available';
        echo '</p>';
    }
    
    // Fungsi untuk membersihkan saat plugin dinonaktifkan
    function frp_deactivate() {
        $timestamp = wp_next_scheduled('frp_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'frp_cron_event');
        }
        
        $timestamp = wp_next_scheduled('frp_check_cron_status_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'frp_check_cron_status_event');
        }
        
        frp_log_message("Plugin deactivated and cron jobs stopped.");
    }
    register_deactivation_hook(__FILE__, 'frp_deactivate');
    
    // Fungsi untuk inisialisasi plugin saat diaktifkan
    function frp_activate() {
        // Inisialisasi opsi default
        add_option('frp_cron_interval', 60);
        add_option('frp_cron_status', 'active');
        add_option('frp_processed_urls', array());
        add_option('frp_model', 'gpt-4o-mini');
        add_option('frp_temperature', 0.5);
        add_option('frp_max_tokens', 500);
        add_option('frp_language', 'en');
        add_option('frp_enable_toc', 'yes');
        add_option('frp_ignore_processed_urls', '1');
        add_option('frp_ignore_no_image', '1');
        
        // Log aktivasi plugin
        frp_log_message("Plugin activated. Please configure settings and add feeds.");
    }
    register_activation_hook(__FILE__, 'frp_activate');
    
    // Endpoint AJAX untuk mengambil log
    add_action('wp_ajax_frp_get_log', 'frp_get_log_ajax');
    function frp_get_log_ajax() {
        check_ajax_referer('frp_get_log_nonce', '_wpnonce');
        
        // Ambil status cron terbaru
        frp_check_cron_status();
        
        // Ambil log terbaru
        $log_content = frp_display_log(50); // Tampilkan 50 log terakhir
        
        wp_send_json_success(['log' => $log_content]);
    }
    
    // Fungsi untuk menampilkan log
function frp_display_log($max_logs = 100) {
    $log_file = plugin_dir_path(__FILE__) . 'frp_log.txt';
    $output = '';

    if (file_exists($log_file)) {
        $log_entries = array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $display_logs = array_slice($log_entries, 0, $max_logs);

        $output .= '<div style="max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ccc;">';
        if (!empty($display_logs)) {
            $output .= '<ul style="margin: 0; padding-left: 20px;">';
            foreach ($display_logs as $log) {
                $output .= '<li>' . esc_html($log) . '</li>';
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No log entries available.</p>';
        }
        $output .= '</div>';
    } else {
        $output .= '<p>Log file not found.</p>';
    }

    return $output;
}

// Fungsi untuk mencatat log
if (!function_exists('frp_log_message')) {
    function frp_log_message($message, $type = 'info') {
        static $last_message = '';
        static $last_timestamp = 0;
        
        // Hindari duplikasi pesan dalam 60 detik
        if ($message === $last_message && (time() - $last_timestamp) < 60) {
            return;
        }
        
        $log_file = plugin_dir_path(__FILE__) . 'frp_log.txt';
        $timestamp = current_time('Y-m-d H:i:s');
        $source = isset($_POST['manual_execute']) ? '[Manual]' : '[Cron]';
        
        $log_entry = "[$timestamp] $source $message\n";
        
        $last_message = $message;
        $last_timestamp = time();
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}

// Tambahkan halaman admin untuk plugin
add_action('admin_menu', function() {
    add_menu_page(
        'Feed Rewriter Settings',
        'Feed Rewriter',
        'manage_options',
        'feed-rewriter',
        'frp_render_admin_page',
        'dashicons-rss'
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Settings',
        'Settings',
        'manage_options',
        'feed-rewriter',
        'frp_render_admin_page'
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Log',
        'Log',
        'manage_options',
        'feed-rewriter-log',
        'frp_render_log_page'
    );
    
    add_submenu_page(
        'feed-rewriter',
        'Feed Rewriter Statistics',
        'Statistics',
        'manage_options',
        'feed-rewriter-stats',
        'frp_render_stats_page'
    );
});

// Render halaman admin utama
if (!function_exists('frp_render_admin_page')) {
    function frp_render_admin_page() {
        // Cek apakah tombol "Run Now" ditekan
        if (isset($_POST['manual_execute']) && check_admin_referer('frp_manual_execution', 'frp_nonce')) {
            frp_log_message("Starting manual execution...");
            frp_cron_process();
            echo "<div class='notice notice-success'><p>Manual execution completed. Check logs for details.</p></div>";
        }
        
        ?>
        <div class="wrap">
            <h1>Feed Rewriter Settings</h1>
            
            <?php frp_display_cron_status(); ?>
            
            <div style="display: flex; margin-top: 20px;">
                <div style="flex: 2; margin-right: 20px;">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('frp_settings_group');
                        do_settings_sections('frp_settings');
                        submit_button();
                        ?>
                    </form>
                    
                    <h2>Manage Feed Sources</h2>
                    <form method="post" id="feeds-form">
                        <?php wp_nonce_field('frp_save_feeds', 'frp_feeds_nonce'); ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Feed URL</th>
                                    <th>Cron Interval</th>
                                    <th>Last Run</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="feeds-body">
                                <?php 
                                $feeds = frp_get_feeds();
                                foreach ($feeds as $index => $feed): 
                                ?>
                                <tr>
                                    <td><input type="text" name="feeds[<?php echo $index; ?>][category]" value="<?php echo esc_attr($feed['category']); ?>" /></td>
                                    <td><input type="url" name="feeds[<?php echo $index; ?>][feed_url]" value="<?php echo esc_attr($feed['feed_url']); ?>" /></td>
                                    <td>
                                        <select name="feeds[<?php echo $index; ?>][cron_interval]">
                                            <option value="hourly" <?php selected($feed['cron_interval'], 'hourly'); ?>>1 Hour</option>
                                            <option value="3 hours" <?php selected($feed['cron_interval'], '3 hours'); ?>>3 Hours</option>
                                            <option value="daily" <?php selected($feed['cron_interval'], 'daily'); ?>>1 Day</option>
                                        </select>
                                    </td>
                                    <td><?php echo !empty($feed['last_run']) ? esc_html($feed['last_run']) : 'Never'; ?></td>
                                    <td>
                                        <button type="button" class="button remove-row">Remove</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Template for new row -->
                                <tr id="new-feed-row" style="display:none;">
                                    <td><input type="text" name="feeds[new][category]" value="" /></td>
                                    <td><input type="url" name="feeds[new][feed_url]" value="" /></td>
                                    <td>
                                        <select name="feeds[new][cron_interval]">
                                            <option value="hourly">1 Hour</option>
                                            <option value="3 hours">3 Hours</option>
                                            <option value="daily">1 Day</option>
                                        </select>
                                    </td>
                                    <td>Never</td>
                                    <td>
                                        <button type="button" class="button remove-row">Remove</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p>
                            <button type="button" id="add-feed" class="button">Add New Feed</button>
                            <button type="submit" name="frp_save_feeds" class="button-primary">Save Feeds</button>
                        </p>
                    </form>
                    
                    <h2>Manual Execution</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('frp_manual_execution', 'frp_nonce'); ?>
                        <input type="submit" name="manual_execute" value="Run Now" class="button button-primary" />
                    </form>
                </div>
                
                <div style="flex: 1; border-left: 1px solid #ddd; padding-left: 20px;">
                    <h2>Generated Articles (Last 20 Posts)</h2>
                    <?php frp_display_generated_articles(20); ?>
                    
                    <h2>Log & Status</h2>
                    <div id="frp-log-container">
                        <!-- Log will be loaded here -->
                    </div>
                </div>
            </div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Function to load log
                    function loadLog() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'frp_get_log',
                                _wpnonce: '<?php echo wp_create_nonce("frp_get_log_nonce"); ?>'
                            },
                            success: function(response) {
                                if(response.success) {
                                    $('#frp-log-container').html(response.data.log);
                                }
                            }
                        });
                    }
                    
                    // Load log every minute
                    loadLog();
                    setInterval(loadLog, 60000);
                    
                    // Remove row
                    $(document).on('click', '.remove-row', function() {
                        $(this).closest('tr').remove();
                    });
                    
                    // Add new row
                    $('#add-feed').on('click', function() {
                        var template = $('#new-feed-row');
                        var newRow = template.clone();
                        newRow.removeAttr('id').show();
                        
                        // Update field names with new index
                        var newIndex = $('#feeds-body tr:not(#new-feed-row)').length;
                        newRow.find('input, select').each(function() {
                            var name = $(this).attr('name');
                            $(this).attr('name', name.replace('feeds[new]', 'feeds[' + newIndex + ']'));
                        });
                        
                        // Add to table before template row
                        template.before(newRow);
                    });
                });
            </script>
        </div>
        <?php
        
        // Process feed saving
        if (isset($_POST['frp_save_feeds']) && check_admin_referer('frp_save_feeds', 'frp_feeds_nonce')) {
            $feeds = [];
            if (isset($_POST['feeds'])) {
                foreach ($_POST['feeds'] as $feed) {
                    if (!empty($feed['feed_url'])) {
                        $feeds[] = [
                            'category' => sanitize_text_field($feed['category']),
                            'feed_url' => esc_url_raw($feed['feed_url']),
                            'cron_interval' => sanitize_text_field($feed['cron_interval']),
                            'last_run' => isset($feed['last_run']) ? sanitize_text_field($feed['last_run']) : '',
                        ];
                    }
                }
            }
            frp_save_feeds($feeds);
            echo '<div class="updated"><p>Feeds saved.</p></div>';
        }
    }
}
// Add custom CSS for admin
add_action('admin_head', function() {
    ?>
    <style>
        .frp-cron-status {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
        }
        .frp-toc {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .frp-toc h3 {
            margin-top: 0;
        }
        .frp-toc ul {
            margin-left: 20px;
        }
    </style>
    <?php
});

// Initialize the plugin
add_action('init', function() {
    // Create log file if it doesn't exist
    $log_file = plugin_dir_path(__FILE__) . 'frp_log.txt';
    if (!file_exists($log_file)) {
        file_put_contents($log_file, "[" . current_time('Y-m-d H:i:s') . "] Plugin initialized\n");
    }
    
    // Check cron status
    frp_check_cron_status();
});
