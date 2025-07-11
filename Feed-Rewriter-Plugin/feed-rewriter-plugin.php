<?php
/**
 * Plugin Name: Feed Rewriter Plugin
 * Plugin URI: https://github.com/raw-dani/Feed-Rewriter-Plugin
 * Description: Plugin untuk mengambil feed RSS dan menulis ulang konten menggunakan OpenAI
 * Version: 1.0.0
 * Author: Rohmat Ali Wardani
 * Author URI: https://www.linkedin.com/in/rohmat-ali-wardani/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feed-rewriter-plugin
 * Domain Path: /languages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */
// Fungsi untuk membuat halaman pengaturan
function frp_add_settings_page() {
    add_options_page(
        'Feed Rewriter Settings',
        'Feed Rewriter',
        'manage_options',
        'frp_settings',
        'frp_render_settings_page'
    );
}
add_action('admin_menu', 'frp_add_settings_page');

// Render halaman pengaturan
function frp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Feed Rewriter Settings</h1>
        
        <?php
        // Tampilkan pesan sukses jika settings disimpan
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }
        ?>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('frp_settings_group');
            do_settings_sections('frp_settings');
            submit_button('Save Settings');
            ?>
        </form>

        <h2>Manual Execution</h2>
        <form method="post" action="">
            <?php wp_nonce_field('frp_manual_execution', 'frp_nonce'); ?>
            <input type="submit" name="manual_execute" value="Run Now" class="button button-primary" />
            <input type="submit" name="clear_lock" value="Clear Lock" class="button button-secondary" style="margin-left: 10px;" />
        </form>

        <?php
        // Handle clear lock
        if (isset($_POST['clear_lock']) && check_admin_referer('frp_manual_execution', 'frp_nonce')) {
            delete_transient('frp_cron_running');
            echo "<div class='notice notice-success'><p>Cron lock cleared successfully!</p></div>";
        }
        
        // Cek apakah tombol "Run Now" ditekan
        if (isset($_POST['manual_execute']) && check_admin_referer('frp_manual_execution', 'frp_nonce')) {
            // Hapus transient sebelum menjalankan
            delete_transient('frp_cron_running');
            
            // Langsung jalankan fungsi tanpa mengubah status cron
            frp_log_message("Memulai eksekusi manual...");
            frp_rewrite_feed_content();
            echo "<div class='notice notice-success'><p>Manual execution completed. Check logs for details.</p></div>";
        }
        ?>

        <!-- Sidebar Kanan -->
        <div style="flex: 1; border-left: 1px solid #ddd; padding-left: 20px;">
            
            <h2>Generated Articles (Last 20 Posts)</h2>
            <?php frp_display_generated_articles(20); ?>
            
            <h2>Log & Status</h2>
            <div id="frp-log-container">
                <!-- Log akan dimuat di sini -->
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Fungsi untuk memuat log
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

                // Muat log setiap menit
                loadLog();
                setInterval(loadLog, 60000);
            });
        </script>
    </div>
    <?php
}

// endpoint AJAX untuk mengambil log
add_action('wp_ajax_frp_get_log', 'frp_get_log_ajax');
function frp_get_log_ajax() {
    check_ajax_referer('frp_get_log_nonce', '_wpnonce');
    
    // Ambil status cron terbaru
    frp_check_cron_status();
    
    // Ambil log terbaru
    $log_content = frp_display_log(50); // Tampilkan 20 log terakhir
    
    wp_send_json_success(['log' => $log_content]);
}

// cron event untuk pengecekan status
if (!wp_next_scheduled('frp_check_cron_status_event')) {
    wp_schedule_event(time(), 'every_minute', 'frp_check_cron_status_event');
}
add_action('frp_check_cron_status_event', 'frp_check_cron_status');

// Tambahkan interval waktu kustom untuk cron
add_filter('cron_schedules', 'frp_add_cron_intervals');
function frp_add_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => 'Every Minute'
    );
    return $schedules;
}

// Bersihkan cron saat deaktivasi
register_deactivation_hook(__FILE__, 'frp_deactivate_plugin');
function frp_deactivate_plugin() {
    wp_clear_scheduled_hook('frp_check_cron_status_event');
    wp_clear_scheduled_hook('frp_cron_event');
    frp_log_message("Plugin dinonaktifkan dan cron jobs dihentikan.");
}

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

// Hook untuk aktivasi plugin
function frp_on_activation() {
    // Hapus jadwal cron yang ada
    wp_clear_scheduled_hook('frp_cron_event');
    
    // Set status cron sebagai "active" saat plugin diaktifkan
    update_option('frp_cron_status', 'active');
    
    // Jadwalkan ulang cron
    frp_schedule_feed_rewrite(true);
}
register_activation_hook(__FILE__, 'frp_on_activation');

// Hook untuk deaktivasi plugin
function frp_on_deactivation() {
    // Hapus jadwal cron saat plugin dinonaktifkan
    wp_clear_scheduled_hook('frp_cron_event');
    update_option('frp_cron_status', 'inactive');
}
register_deactivation_hook(__FILE__, 'frp_on_deactivation');

// Fungsi utama yang dipanggil oleh cron
add_action('frp_cron_event', 'frp_rewrite_feed_content');

function frp_rewrite_feed_content() {
    // Cek apakah ini eksekusi manual atau cron
    $is_manual = isset($_POST['manual_execute']);
    
    if (!$is_manual) {
        frp_log_message("Memulai eksekusi cron...");
    } else {
        frp_log_message("Memulai eksekusi manual...");
        delete_transient('frp_cron_running');
        frp_log_message("Manual execution - bypassing cron lock.");
    }
    
    // Cek apakah cron sedang berjalan - SKIP CHECK untuk manual execution
    if (!$is_manual) {
        $cron_running = get_transient('frp_cron_running');
        if ($cron_running) {
            frp_log_message("Cron is already running. Skipping this execution.");
            return;
        }
    }

    // Set flag bahwa cron sedang berjalan
    set_transient('frp_cron_running', true, 30 * MINUTE_IN_SECONDS);

    try {
        $cron_status = get_option('frp_cron_status', 'inactive');

        // Cek status cron - SKIP untuk manual execution
        if (!$is_manual && $cron_status === 'inactive') {
            frp_log_message("Cron job tidak dijalankan karena status inactive.");
            delete_transient('frp_cron_running');
            return;
        }

        // Process each feed configuration
        for ($config_num = 1; $config_num <= 5; $config_num++) {
            $feed_url = get_option("frp_feed_url_{$config_num}", '');
            
            // Skip jika feed URL kosong
            if (empty($feed_url)) {
                continue;
            }
            
            $selected_category = get_option("frp_selected_category_{$config_num}", '');
            $cron_interval = get_option("frp_cron_interval_{$config_num}", 60);
            $custom_prompt = get_option("frp_custom_prompt_{$config_num}", 'Rewrite the following content:');
            
            frp_log_message("Processing Feed Configuration #{$config_num}");
            frp_log_message("Feed URL: {$feed_url}");
            frp_log_message("Category: {$selected_category}");
            frp_log_message("Interval: {$cron_interval} minutes");
            
            // Cek apakah sudah waktunya untuk memproses feed ini
            $last_run_key = "frp_last_run_config_{$config_num}";
            $last_run = get_option($last_run_key, 0);
            $current_time = time();
            $interval_seconds = $cron_interval * 60;
            
            if (!$is_manual && ($current_time - $last_run) < $interval_seconds) {
                $time_left = $interval_seconds - ($current_time - $last_run);
                frp_log_message("Config #{$config_num} - Next run in {$time_left} seconds. Skipping.");
                continue;
            }
            
            // Process this feed configuration
            $article_found = frp_process_single_feed($feed_url, $selected_category, $custom_prompt, $config_num);
            
            if ($article_found) {
                // Update last run time untuk config ini
                update_option($last_run_key, $current_time);
                frp_log_message("Config #{$config_num} - Article processed successfully.");
                
                // Jika manual execution, proses semua feed. Jika cron, proses satu saja per execution
                if (!$is_manual) {
                    break;
                }
            }
        }

        // Update waktu terakhir cron berjalan
        update_option('frp_last_cron_run', current_time('mysql'));

    } catch (Exception $e) {
        frp_log_message("Error in cron execution: " . $e->getMessage());
    } finally {
        // Hapus flag cron running
        delete_transient('frp_cron_running');
    }

    if (!$is_manual) {
        frp_check_cron_status();
    }
}

// Fungsi untuk memproses single feed configuration
function frp_process_single_feed($feed_url, $selected_category, $custom_prompt, $config_num) {
    // Ambil pengaturan global
    $fetch_latest_only = get_option('frp_fetch_latest_only', false);
    $ignore_processed_urls = get_option('frp_ignore_processed_urls', false);
    $processed_urls = get_option("frp_processed_urls_{$config_num}", []);
    $ignore_no_image = get_option('frp_ignore_no_image', '1');
    $keyword_filter = get_option('frp_keyword_filter', '');
    $keywords = array_filter(array_map('trim', explode("\n", $keyword_filter)));
    $exclude_keyword_filter = get_option('frp_exclude_keyword_filter', '');
    $exclude_keywords = array_map('trim', explode(',', strtolower($exclude_keyword_filter)));

    frp_log_message("Fetching content from feed URL: " . $feed_url);

    // Mengambil konten dari URL feed
    $response = wp_remote_get($feed_url, [
        'timeout' => 30,
        'user-agent' => 'Mozilla/5.0 (compatible; WordPress Feed Reader)',
        'headers' => [
            'Accept' => 'application/rss+xml, application/xml, text/xml',
            'Accept-Language' => 'en-US,en;q=0.9',
        ]
    ]);

    if (is_wp_error($response)) {
        frp_log_message("Error fetching feed: " . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    frp_log_message("Raw response length: " . strlen($body) . " characters");

    // Bersihkan response body dari karakter yang tidak valid
    $body = frp_clean_xml_response($body);

    try {
        $xml = frp_parse_xml_with_fallback($body);
        
        if ($xml === false) {
            frp_log_message("Failed to parse XML feed after all attempts");
            return false;
        }
        
        $article_found = false;

        // Process RSS feed
        if (isset($xml->channel) && isset($xml->channel->item)) {
            frp_log_message("Detected RSS feed format with " . count($xml->channel->item) . " items");
            
            foreach ($xml->channel->item as $item) {
                $original_title = strip_tags((string)$item->title);
                $link = (string)$item->link;
                $pub_date = date('Y-m-d H:i:s', strtotime((string)$item->pubDate));

                // Extract image URL
                $image_url = frp_extract_image_from_item($item, $link);

                // Extract content
                $content = frp_extract_content_from_item($item, $link);

                if (empty($content)) {
                    frp_log_message("Tidak dapat mengekstrak konten dari artikel: " . $link);
                    continue;
                }

                frp_log_message("Processing article: {$original_title}");
                frp_log_message("Content length: " . strlen($content) . " karakter");

                // Cek filter kata kunci
                if (!empty($keywords)) {
                    $keyword_found = false;
                    foreach ($keywords as $keyword) {
                        if (!empty($keyword) && 
                            (stripos($original_title, $keyword) !== false || 
                            stripos($content, $keyword) !== false)) {
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
                    if (!empty($exclude_keyword) && (stripos($original_title, $exclude_keyword) !== false || 
                        stripos($content, $exclude_keyword) !== false)) {
                        frp_log_message("Skipping article as it contains excluded keyword '{$exclude_keyword}'");
                        $skip_article = true;
                        break;
                    }
                }
                if ($skip_article) continue;

                // Cek artikel yang sudah diproses
                if ($ignore_processed_urls === '1' && in_array($link, $processed_urls)) {
                    frp_log_message("Skipping already processed article");
                    continue;
                }

                // Proses rewrite dengan custom prompt untuk config ini
                frp_log_message("Starting content rewriting process with custom prompt...");
                $new_title_and_content = frp_rewrite_title_and_content_with_prompt($original_title, $content, $custom_prompt);

                if ($new_title_and_content) {
                    // Post ke WordPress
                    $post_id = wp_insert_post([
                        'post_title' => $new_title_and_content['title'],
                        'post_content' => $new_title_and_content['content'],
                        'post_status' => 'publish',
                        'post_type' => 'post',
                        'post_category' => $selected_category ? [$selected_category] : [],
                        'meta_input' => [
                            '_frp_generated' => 1,
                            '_frp_source_feed' => $feed_url,
                            '_frp_source_link' => $link,
                            '_frp_config_num' => $config_num
                        ],
                    ]);

                    if ($post_id) {
                        frp_log_message("Successfully created new post with ID: " . $post_id);
                        
                        // Set featured image jika ada
                        if ($image_url) {
                            frp_set_featured_image($post_id, $image_url, $new_title_and_content['title'], $content);
                        }

                        // Generate dan set tags
                        $tags = frp_generate_tags($new_title_and_content['content']);
                        if ($tags) {
                            wp_set_post_tags($post_id, $tags);
                        }

                        // Update processed URLs untuk config ini
                        $processed_urls[] = $link;
                        update_option("frp_processed_urls_{$config_num}", $processed_urls);
                        update_option("frp_last_processed_date_{$config_num}", $pub_date);
                        
                        $article_found = true;
                        break; // Keluar dari loop setelah satu artikel berhasil diproses
                    } else {
                        frp_log_message("Failed to create new post");
                    }
                } else {
                    frp_log_message("Failed to rewrite content");
                }
            }
        }

        return $article_found;

    } catch (Exception $e) {
        frp_log_message("Error in XML processing for config #{$config_num}: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk extract image dari item
function frp_extract_image_from_item($item, $link) {
    $image_url = '';
    
    // 1. Cek enclosure
    if (isset($item->enclosure)) {
        $image_url = (string)$item->enclosure['url'];
        frp_log_message("Image found from enclosure: " . $image_url);
    }
    
    // 2. Cek description
    if (empty($image_url)) {
        $description = (string)$item->description;
        if (preg_match('/<img[^>]+src="([^"]+)"/', html_entity_decode($description), $matches)) {
            $image_url = $matches[1];
            frp_log_message("Image found from description: " . $image_url);
        }
    }
    
    // 3. Cek content:encoded
    if (empty($image_url)) {
        if (isset($item->children('content', true)->encoded)) {
            $content_encoded = (string)$item->children('content', true)->encoded;
            if (preg_match('/<img[^>]+src="([^"]+)"/', html_entity_decode($content_encoded), $matches)) {
                $image_url = $matches[1];
                frp_log_message("Image found from content:encoded: " . $image_url);
            }
        }
    }
    
    // Bersihkan URL gambar
    if (!empty($image_url)) {
        $image_url = html_entity_decode($image_url);
        $image_url = str_replace('http://', 'https://', $image_url);
        frp_log_message("Final cleaned image URL: " . $image_url);
    }
    
    return $image_url;
}

// Fungsi untuk extract content dari item
function frp_extract_content_from_item($item, $link) {
    $content = '';
    
    // 1. Cek content:encoded terlebih dahulu
    if (isset($item->children('content', true)->encoded)) {
        $content_encoded = (string)$item->children('content', true)->encoded;
        if (!empty($content_encoded)) {
            $content = strip_tags(html_entity_decode($content_encoded));
            $content = preg_replace('/The post .* appeared first on .*\./s', '', $content);
            $content = trim($content);
            frp_log_message("Content extracted from content:encoded. Length: " . strlen($content));
        }
    }
    
    // 2. Jika tidak ada content:encoded, gunakan description
    if (empty($content)) {
        $description = strip_tags(html_entity_decode((string)$item->description));
        $description = preg_replace('/The post .* appeared first on .*\./s', '', $description);
        $content = trim($description);
        frp_log_message("Content extracted from description. Length: " . strlen($content));
    }
    
    // 3. Jika masih kosong atau terlalu pendek, ambil dari URL
    if (empty($content) || strlen($content) < 100) {
        frp_log_message("Content too short, fetching from article URL: " . $link);
        
        $article_response = wp_remote_get($link, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ]
        ]);
        
        if (!is_wp_error($article_response)) {
            $article_html = wp_remote_retrieve_body($article_response);
            
            if (strpos($link, 'bikesportnews.com') !== false) {
                $extracted_content = frp_extract_bikesport_content($article_html);
            } elseif (strpos($link, 'cnnindonesia.com') !== false) {
                $extracted_content = frp_extract_cnn_content($article_html);
            } else {
                $extracted_content = frp_extract_article_content($article_html, $link);
            }
            
            if (!empty($extracted_content)) {
                $content = $extracted_content;
                frp_log_message("Content extracted from article URL. Length: " . strlen($content));
            }
        }
    }
    
    return $content;
}

// Fungsi rewrite dengan custom prompt
function frp_rewrite_title_and_content_with_prompt($title, $content, $custom_prompt) {
    $api_key = get_option('frp_api_key');
    $model = get_option('frp_model', 'gpt-4.1-nano');
    $language = get_option('frp_language', 'en');
    $max_tokens = get_option('frp_max_tokens', 500);
    $temperature = get_option('frp_temperature', 0.5);

    // Buat prompt dengan custom prompt yang diberikan
    $prompt = "{$custom_prompt}\n\n" . 
          "Rewrite the following title and content to make it suitable for publishing. " . 
          "Ensure the title is concise (maximum 65 characters) and engaging, and the content is well-structured without using unnecessary labels like 'Title:', 'Content:', or 'Let's continue...'. " . 
          "The rewritten text should be in " . ($language === 'id' ? 'Bahasa Indonesia' : 'English') . ".\n\n" .
          "Title: {$title}\n\n" .
          "Content:\n{$content}\n\n" .
          "Please provide a polished and publishable title and content.";

    // Request ke OpenAI API
    $endpoint = 'https://api.openai.com/v1/chat/completions';

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
        
        $cleaned_title = frp_clean_text(trim($generated_text[0]), true);
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

// Fungsi untuk schedule multi cron berdasarkan interval terpendek
function frp_schedule_multi_feed_cron() {
    $shortest_interval = 60; // Default 60 menit
    
    // Cari interval terpendek dari semua konfigurasi
    for ($i = 1; $i <= 5; $i++) {
        $feed_url = get_option("frp_feed_url_{$i}", '');
        if (!empty($feed_url)) {
            $interval = get_option("frp_cron_interval_{$i}", 60);
            if ($interval < $shortest_interval) {
                $shortest_interval = $interval;
            }
        }
    }
    
    // Schedule cron dengan interval terpendek
    $event_hook = 'frp_cron_event';
    $existing_schedule = wp_next_scheduled($event_hook);
    
    if ($existing_schedule) {
        wp_clear_scheduled_hook($event_hook);
    }
    
    $next_run = time() + ($shortest_interval * 60);
    wp_schedule_event($next_run, 'frp_custom_interval', $event_hook);
    
    // Update custom interval
    update_option('frp_cron_interval', $shortest_interval);
    
    frp_log_message("Multi-feed cron scheduled with {$shortest_interval} minute interval");
}

// Update cron scheduling saat settings disimpan
add_action('update_option_frp_feed_url_1', 'frp_schedule_multi_feed_cron');
add_action('update_option_frp_feed_url_2', 'frp_schedule_multi_feed_cron');
add_action('update_option_frp_feed_url_3', 'frp_schedule_multi_feed_cron');
add_action('update_option_frp_feed_url_4', 'frp_schedule_multi_feed_cron');
add_action('update_option_frp_feed_url_5', 'frp_schedule_multi_feed_cron');

// Fungsi untuk membersihkan XML response
function frp_clean_xml_response($xml_string) {
    // Hapus BOM (Byte Order Mark) jika ada
    $xml_string = preg_replace('/^\xEF\xBB\xBF/', '', $xml_string);
    
    // Hapus karakter kontrol yang tidak valid dalam XML
    $xml_string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xml_string);
    
    // Hapus whitespace di awal dan akhir
    $xml_string = trim($xml_string);
    
    // Cek apakah string dimulai dengan <?xml atau langsung dengan tag root
    if (!preg_match('/^\s*<\?xml/', $xml_string) && !preg_match('/^\s*<(rss|feed|rdf)/', $xml_string)) {
        frp_log_message("Response doesn't appear to be valid XML. First 200 chars: " . substr($xml_string, 0, 200));
        
        // Coba cari tag XML di dalam response
        if (preg_match('/<\?xml.*?>/s', $xml_string, $matches, PREG_OFFSET_CAPTURE)) {
            $xml_start = $matches[0][1];
            $xml_string = substr($xml_string, $xml_start);
            frp_log_message("Found XML declaration at position " . $xml_start . ", extracting from there");
        } elseif (preg_match('/<(rss|feed|rdf)[^>]*>/s', $xml_string, $matches, PREG_OFFSET_CAPTURE)) {
            $xml_start = $matches[0][1];
            $xml_string = substr($xml_string, $xml_start);
            frp_log_message("Found root XML tag at position " . $xml_start . ", extracting from there");
        }
    }
    
    // Perbaiki encoding issues
    if (!mb_check_encoding($xml_string, 'UTF-8')) {
        $xml_string = mb_convert_encoding($xml_string, 'UTF-8', 'auto');
        frp_log_message("Converted XML encoding to UTF-8");
    }
    
    // Escape karakter HTML entities yang bermasalah dalam XML
    $xml_string = preg_replace('/&(?!(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $xml_string);
    
    return $xml_string;
}

// Fungsi untuk validasi dan fallback parsing XML
function frp_parse_xml_with_fallback($xml_string) {
    // Coba parsing normal terlebih dahulu
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    
    try {
        $xml = new SimpleXMLElement($xml_string);
        frp_log_message("XML parsed successfully with SimpleXMLElement");
        return $xml;
    } catch (Exception $e) {
        frp_log_message("SimpleXMLElement failed: " . $e->getMessage());
        
        // Log libxml errors
        $xml_errors = libxml_get_errors();
        if (!empty($xml_errors)) {
            foreach ($xml_errors as $error) {
                frp_log_message("XML Parse Error: " . trim($error->message) . " at line " . $error->line . ", column " . $error->column);
            }
        }
        
        // Coba dengan DOMDocument sebagai fallback
        try {
            $dom = new DOMDocument();
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            
            if ($dom->loadXML($xml_string)) {
                $xml = simplexml_import_dom($dom);
                if ($xml !== false) {
                    frp_log_message("XML parsed successfully with DOMDocument fallback");
                    return $xml;
                }
            }
        } catch (Exception $dom_e) {
            frp_log_message("DOMDocument fallback also failed: " . $dom_e->getMessage());
        }
        
        // Jika masih gagal, coba bersihkan XML lebih agresif
        frp_log_message("Attempting aggressive XML cleanup");
        $cleaned_xml = frp_aggressive_xml_cleanup($xml_string);
        
        try {
            $xml = new SimpleXMLElement($cleaned_xml);
            frp_log_message("XML parsed successfully after aggressive cleanup");
            return $xml;
        } catch (Exception $final_e) {
            frp_log_message("All XML parsing attempts failed: " . $final_e->getMessage());
            return false;
        }
    }
}

// Fungsi untuk pembersihan XML yang lebih agresif
function frp_aggressive_xml_cleanup($xml_string) {
    // Hapus semua yang ada sebelum deklarasi XML atau tag root
    if (preg_match('/<\?xml.*?\?>/s', $xml_string, $matches, PREG_OFFSET_CAPTURE)) {
        $xml_string = substr($xml_string, $matches[0][1]);
    } elseif (preg_match('/<(rss|feed|rdf)[^>]*>/s', $xml_string, $matches, PREG_OFFSET_CAPTURE)) {
        $xml_string = substr($xml_string, $matches[0][1]);
    }
    
    // Hapus JavaScript dan CSS yang mungkin ada
    $xml_string = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $xml_string);
    $xml_string = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $xml_string);
    
    // Hapus komentar HTML
    $xml_string = preg_replace('/<!--.*?-->/s', '', $xml_string);
    
    // Perbaiki tag yang tidak tertutup dengan benar
    $xml_string = preg_replace('/<([^>]+)>([^<]*)<\/\1>/s', '<$1><![CDATA[$2]]></$1>', $xml_string);
    
    // Hapus karakter yang tidak valid untuk XML
    $xml_string = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x85\xA0-\xFF]/', '', $xml_string);
    
    return $xml_string;
}

// Tambahkan fungsi khusus untuk mengekstrak konten BikeSport News
function frp_extract_bikesport_content($html) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk BikeSport News
    $selectors = [
        "//div[contains(@class, 'entry-content')]//p",
        "//article[contains(@class, 'post')]//p",
        "//div[contains(@class, 'post-content')]//p",
        "//div[contains(@class, 'content')]//p"
    ];
    
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = '';
            foreach ($nodes as $node) {
                // Skip jika paragraf kosong atau hanya berisi spasi
                $text = trim($node->textContent);
                if (!empty($text) && 
                    !preg_match('/^(The post|Share this:|Tags:|Categories:)/i', $text) &&
                    !preg_match('/appeared first on/i', $text)) {
                    $content .= $text . "\n\n";
                }
            }
            
            if (!empty($content)) {
                frp_log_message("Berhasil mengekstrak konten BikeSport News");
                return trim($content);
            }
        }
    }
    
    frp_log_message("Gagal mengekstrak konten BikeSport News dengan selector yang tersedia");
    return '';
}

// Tambahkan fungsi khusus untuk mengekstrak konten The Points Guy
function frp_extract_tpg_content($html) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk The Points Guy
    $selectors = [
        "//div[contains(@class, 'post-content')]",
        "//div[contains(@class, 'entry-content')]",
        "//article[contains(@class, 'post')]//div[contains(@class, 'content')]",
        "//main//article//div[contains(@class, 'post-body')]",
        "//div[contains(@class, 'article-content')]",
        "//div[contains(@class, 'post-body')]"
    ];
    
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = '';
            foreach ($nodes as $node) {
                // Ambil semua paragraf dalam node
                $paragraphs = $xpath->query('.//p', $node);
                if ($paragraphs->length > 0) {
                    foreach ($paragraphs as $p) {
                        $text = trim($p->textContent);
                        if (!empty($text) && strlen($text) > 20) { // Skip paragraf yang terlalu pendek
                            $content .= $text . "\n\n";
                        }
                    }
                } else {
                    // Jika tidak ada paragraf, ambil text content langsung
                    $text = trim($node->textContent);
                    if (!empty($text)) {
                        $content .= $text . "\n\n";
                    }
                }
            }
            
            if (!empty($content) && strlen($content) > 200) {
                frp_log_message("Berhasil mengekstrak konten The Points Guy");
                return trim($content);
            }
        }
    }
    
    // Fallback: coba ambil semua paragraf di dalam article atau main
    $fallback_selectors = [
        "//article//p",
        "//main//p",
        "//div[contains(@id, 'content')]//p"
    ];
    
    foreach ($fallback_selectors as $selector) {
        $paragraphs = $xpath->query($selector);
        if ($paragraphs->length > 3) { // Minimal 3 paragraf
            $content = '';
            foreach ($paragraphs as $p) {
                $text = trim($p->textContent);
                if (!empty($text) && strlen($text) > 20) {
                    $content .= $text . "\n\n";
                }
            }
            
            if (strlen($content) > 500) {
                frp_log_message("Berhasil mengekstrak konten TPG dengan fallback selector");
                return trim($content);
            }
        }
    }
    
    frp_log_message("Gagal mengekstrak konten The Points Guy dengan selector yang tersedia");
    return '';
}

// hook untuk mengecek status cron secara berkala
add_action('init', 'frp_check_cron_status');

// Tambahkan fungsi khusus untuk mengekstrak konten CNN Indonesia
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

// Fungsi untuk mengubah interval cron
function frp_update_cron_interval($new_interval_minutes) {
    $old_interval = get_option('frp_cron_interval', 60);
    
    if ($new_interval_minutes != $old_interval) {
        update_option('frp_cron_interval', $new_interval_minutes);
        frp_schedule_feed_rewrite(true);
        frp_log_message("Interval cron diperbarui menjadi {$new_interval_minutes} menit");
    }
}

// Hook untuk menyimpan pengaturan
function frp_save_settings() {
    // Cek perubahan interval
    $new_interval = isset($_POST['frp_cron_interval']) ? 
                   intval($_POST['frp_cron_interval']) : 
                   get_option('frp_cron_interval', 1);
    
    frp_update_cron_interval($new_interval);
}
add_action('admin_init', 'frp_save_settings');

// Fungsi untuk menampilkan log dari file
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

// Modifikasi fungsi aktivasi plugin untuk tidak menjalankan generate content
register_activation_hook(__FILE__, 'frp_activate_plugin');
function frp_activate_plugin() {
    // Hanya inisialisasi opsi default
    add_option('frp_cron_interval', 1);
    add_option('frp_cron_status', 'active');
    add_option('frp_processed_urls', array());
    
    // Log aktivasi plugin
    frp_log_message("Plugin diaktifkan. Silakan konfigurasi pengaturan dan klik Save atau Run Now untuk memulai.");
}

// Fungsi untuk menampilkan daftar artikel yang telah digenerate maksimal 20 pos
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

// Mendaftarkan pengaturan untuk multi feed
function frp_register_settings() {
    // Register settings untuk 5 feed configurations
    for ($i = 1; $i <= 5; $i++) {
        register_setting('frp_settings_group', "frp_feed_url_{$i}", [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => ''
        ]);
        register_setting('frp_settings_group', "frp_selected_category_{$i}");
        register_setting('frp_settings_group', "frp_cron_interval_{$i}");
        register_setting('frp_settings_group', "frp_custom_prompt_{$i}");
    }
    
    // Keep existing single settings for backward compatibility
    register_setting('frp_settings_group', 'frp_api_key');
    register_setting('frp_settings_group', 'frp_image_selector');    
    register_setting('frp_settings_group', 'frp_keyword_filter');
    register_setting('frp_settings_group', 'frp_exclude_keyword_filter');
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
    add_settings_section('frp_feed_settings', 'Multi Feed Configuration', null, 'frp_settings');

    // Main settings fields
    add_settings_field('frp_api_key', 'OpenAI API Key', 'frp_api_key_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_image_selector', 'Image Selector', 'frp_image_selector_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_keyword_filter', 'Keyword Filter', 'frp_keyword_filter_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_exclude_keyword_filter', 'Exclude Keyword Filter', 'frp_exclude_keyword_filter_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_model', 'OpenAI Model', 'frp_model_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_max_tokens', 'Max Tokens', 'frp_max_tokens_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_temperature', 'Temperature', 'frp_temperature_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_fetch_latest_only', 'Fetch Latest Articles Only', 'frp_fetch_latest_only_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_language', 'Language', 'frp_language_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_processed_urls', 'Ignore Processed URLs', 'frp_ignore_processed_urls_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_no_image', 'Ignore Articles Without Images', 'frp_ignore_no_image_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_cron_status', 'Pause/Stop Cron', 'frp_cron_status_callback', 'frp_settings', 'frp_main_settings');

    // Multi feed configuration fields
    for ($i = 1; $i <= 5; $i++) {
        add_settings_field("frp_feed_config_{$i}", "Feed Configuration #{$i}", "frp_feed_config_{$i}_callback", 'frp_settings', 'frp_feed_settings');
    }
}
add_action('admin_init', 'frp_register_settings');

// Callback functions untuk multi feed configuration
function frp_feed_config_1_callback() { frp_render_feed_config(1); }
function frp_feed_config_2_callback() { frp_render_feed_config(2); }
function frp_feed_config_3_callback() { frp_render_feed_config(3); }
function frp_feed_config_4_callback() { frp_render_feed_config(4); }
function frp_feed_config_5_callback() { frp_render_feed_config(5); }

function frp_render_feed_config($config_num) {
    $feed_url = get_option("frp_feed_url_{$config_num}", '');
    $selected_category = get_option("frp_selected_category_{$config_num}", '');
    $cron_interval = get_option("frp_cron_interval_{$config_num}", 60);
    $custom_prompt = get_option("frp_custom_prompt_{$config_num}", 'Rewrite the following content:');
    
    $categories = get_categories(['hide_empty' => false]);
    
    echo '<div style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: #f9f9f9;">';
    
    // Feed URL
    echo '<p><label><strong>Feed URL:</strong></label><br>';
    echo '<textarea name="frp_feed_url_' . $config_num . '" rows="2" style="width: 100%; max-width: 600px;">' . esc_textarea($feed_url) . '</textarea></p>';
    
    // Category
    echo '<p><label><strong>Category:</strong></label><br>';
    echo '<select name="frp_selected_category_' . $config_num . '" style="width: 200px;">';
    echo '<option value="">Select Category</option>';
    foreach ($categories as $category) {
        $selected = ($selected_category == $category->term_id) ? 'selected' : '';
        echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
    }
    echo '</select></p>';
    
    // Cron Interval
    echo '<p><label><strong>Cron Interval (minutes):</strong></label><br>';
    echo '<input type="number" name="frp_cron_interval_' . $config_num . '" value="' . esc_attr($cron_interval) . '" min="1" style="width: 100px;" /> minutes</p>';
    
    // Custom Prompt
    echo '<p><label><strong>Custom Prompt:</strong></label><br>';
    echo '<textarea name="frp_custom_prompt_' . $config_num . '" rows="3" style="width: 100%; max-width: 600px;">' . esc_textarea($custom_prompt) . '</textarea></p>';
    
    echo '</div>';
}

function frp_settings_page() {
    ?>
    <div class="wrap">
        <h1>Feed Rewrite Plugin Settings</h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('frp_options_group');
                do_settings_sections('frp_options_group');
                $enable_toc = get_option('frp_enable_toc', 'yes');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Table of Contents</th>
                    <td>
                        <input type="checkbox" id="frp_enable_toc" name="frp_enable_toc" value="yes" <?php checked($enable_toc, 'yes'); ?> />
                        <label for="frp_enable_toc">Enable TOC</label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>

            <?php
            // Tampilkan status terakhir cron berjalan
            $last_cron_run = get_option('frp_last_cron_run');
            if ($last_cron_run) {
                echo '<p><strong>Last cron run:</strong> ' . esc_html($last_cron_run) . '</p>';
                
                // Peringatan jika cron tidak berjalan dalam 24 jam
                $last_cron_timestamp = strtotime($last_cron_run);
                $current_timestamp = current_time('timestamp');
                $time_difference = ($current_timestamp - $last_cron_timestamp) / 3600;

                if ($time_difference > 24) {
                    echo '<p style="color: red;"><strong>Warning:</strong> Cron has not run in the past 24 hours. Please check your cron settings.</p>';
                }
            } else {
                echo '<p><strong>Last cron run:</strong> Not yet run</p>';
            }

            // Tambahkan informasi jadwal cron berikutnya
            $next_scheduled = wp_next_scheduled('frp_cron_event');
            if ($next_scheduled) {
                echo '<p><strong>Next scheduled run:</strong> ' . date('Y-m-d H:i:s', $next_scheduled) . '</p>';
            } else {
                echo '<p><strong>Next scheduled run:</strong> Not scheduled</p>';
            }
            ?>
        </form>
    </div>
    <?php
}

// Fungsi untuk mengubah status cron melalui Ajax
add_action('wp_ajax_frp_toggle_cron', 'frp_toggle_cron_status');
function frp_toggle_cron_status() {
    check_ajax_referer('frp_toggle_cron_nonce', '_wpnonce');

    $status = sanitize_text_field($_POST['status']);
    
    if ($status === 'pause') {
        update_option('frp_cron_status', 'paused');
        
        // Hentikan cron job
        $timestamp = wp_next_scheduled('frp_rewrite_feed_content_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'frp_rewrite_feed_content_event');
        }

        wp_send_json_success(['message' => 'Cron job telah di-pause.']);
    } elseif ($status === 'resume') {
        update_option('frp_cron_status', 'active');
        
        // Jadwalkan ulang cron
        frp_schedule_feed_rewrite(true);
        
        wp_send_json_success(['message' => 'Cron job telah di-resume.']);
    } else {
        wp_send_json_error(['message' => 'Status tidak valid.']);
    }
}

// Callback untuk field image selector
function frp_image_selector_callback() {
    $image_selector = get_option('frp_image_selector', '');
    echo "<input type='text' name='frp_image_selector' value='" . esc_attr($image_selector) . "' class='regular-text' />";
    echo "<p class='description'>Masukkan selector CSS untuk gambar (contoh: .entry-content img, article img.featured)</p>";
}

// Menambahkan pengaturan untuk Pause atau Stop Cron
function frp_cron_status_callback() {
    $cron_status = get_option('frp_cron_status', 'active');
    $is_paused = ($cron_status === 'paused');
    
    echo '<button type="button" id="toggle-cron-status" class="button ' . ($is_paused ? 'button-primary' : '') . '">' . ($is_paused ? 'Resume Cron' : 'Pause Cron') . '</button>';
    echo '<p class="description">Klik tombol untuk pause atau resume cron job.</p>';

    // JavaScript untuk mengubah status cron tanpa reload halaman
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
                        alert('Gagal mengubah status cron.');
                    }
                });
            });
        });
    </script>
    <?php
}

function frp_exclude_keyword_filter_callback() {
    $exclude_keyword_filter = get_option('frp_exclude_keyword_filter', '');
    echo '<input type="text" name="frp_exclude_keyword_filter" value="' . esc_attr($exclude_keyword_filter) . '" class="regular-text" />';
    echo '<p class="description">Enter keywords to exclude articles (e.g., "video, sponsored"). Articles containing these words will be skipped.</p>';
}

function frp_keyword_filter_callback() {
    $keyword_filter = get_option('frp_keyword_filter', '');
    echo '<textarea name="frp_keyword_filter" rows="3" style="width: 100%;">' . esc_textarea($keyword_filter) . '</textarea>';
    echo '<p class="description">Masukkan kata kunci untuk filter artikel, satu kata kunci per baris. Artikel akan diproses jika mengandung salah satu kata kunci.</p>';
}

// Fungsi untuk mengatur max tokens
function frp_max_tokens_callback() {
    $max_tokens = get_option('frp_max_tokens', 500); // Default 500
    echo "<input type='number' name='frp_max_tokens' value='{$max_tokens}' min='0' step='1' />";
    echo "<p class='description'>Set max tokens. Set to 0 for unlimited tokens (default is 500).</p>";
}

// Fungsi untuk mengatur temperature
function frp_temperature_callback() {
    $temperature = get_option('frp_temperature', 0.5); // Default 0.5
    echo "<input type='number' name='frp_temperature' value='{$temperature}' min='0' max='2' step='0.1' />";
    echo "<p class='description'>Set the temperature value between 0 and 2 (default is 0.7). Lower values make the output more deterministic.</p>";
}

// Tambahkan di fungsi frp_register_settings()
function frp_category_callback() {
    $categories = get_categories(['hide_empty' => false]); // Ambil semua kategori, termasuk yang kosong
    $selected_category = get_option('frp_selected_category');
    echo '<select name="frp_selected_category">';
    echo '<option value="">Select Category</option>';
    foreach ($categories as $category) {
        $selected = ($selected_category == $category->term_id) ? 'selected' : '';
        echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
    }
    echo '</select>';
}

function frp_ignore_processed_urls_callback() {
    $ignore_processed_urls = get_option('frp_ignore_processed_urls', '1');
    echo "<input type='checkbox' name='frp_ignore_processed_urls' value='1' " . checked($ignore_processed_urls, '1', false) . " /> Prevent generating the same URL again.";
}

function frp_ignore_no_image_callback() {
    $ignore_no_image = get_option('frp_ignore_no_image', '1');
    echo "<input type='checkbox' name='frp_ignore_no_image' value='1' " . checked($ignore_no_image, '1', false) . " /> Do not process articles without images.";
}

function frp_language_callback() {
    $language = get_option('frp_language', 'en');
    echo "<select name='frp_language'>
            <option value='en' " . selected($language, 'en', false) . ">English</option>
            <option value='id' " . selected($language, 'id', false) . ">Bahasa Indonesia</option>
          </select>";
}

function frp_feed_url_callback() {
    $feed_url = get_option('frp_feed_url', '');
    echo "<textarea name='frp_feed_url' rows='5' style='width: 100%; max-width: 600px;'>" . esc_textarea($feed_url) . "</textarea>";
    echo "<p class='description'>Masukkan URL feed, satu URL per baris</p>";
    
    // Debug: tampilkan nilai saat ini
    if (!empty($feed_url)) {
        echo "<p><small><strong>Current value:</strong> " . esc_html($feed_url) . "</small></p>";
    }
}

function frp_api_key_callback() {
    $api_key = get_option('frp_api_key', '');
    echo "<input type='text' name='frp_api_key' value='" . esc_attr($api_key) . "' />";
}

function frp_cron_interval_callback() {
    $interval_minutes = get_option('frp_cron_interval', 60);
    echo "<input type='number' name='frp_cron_interval' value='" . esc_attr($interval_minutes) . "' min='1' /> menit";
    echo "<p class='description'>Masukkan interval dalam menit (minimal 1 menit)</p>";
}

function frp_custom_prompt_callback() {
    $custom_prompt = get_option('frp_custom_prompt', 'Rewrite the following content:');
    echo "<textarea name='frp_custom_prompt' rows='4' cols='50'>" . esc_textarea($custom_prompt) . "</textarea>";
}

function frp_model_callback() {
    $model = get_option('frp_model', 'gpt-4o-mini');
    echo "<select name='frp_model'>
            <option value='gpt-3.5-turbo' " . selected($model, 'gpt-3.5-turbo', false) . ">GPT-3.5 Turbo</option>
            <option value='gpt-4' " . selected($model, 'gpt-4', false) . ">GPT-4</option>
            <option value='gpt-4o-mini' " . selected($model, 'gpt-4o-mini', false) . ">gpt-4o-mini</option>
            <option value='gpt-4.1-nano' " . selected($model, 'gpt-4.1-nano', false) . ">gpt-4.1-nano</option>
          </select>";
}

function frp_validate_settings($input) {
    // Pastikan interval minimal 1 menit
    if (isset($input['frp_cron_interval'])) {
        $input['frp_cron_interval'] = max(1, intval($input['frp_cron_interval']));
    }
    return $input;
}
add_filter('pre_update_option_frp_cron_interval', 'frp_validate_settings');

// Fungsi callback untuk pengaturan "fetch_latest_only"
function frp_fetch_latest_only_callback() {
    $fetch_latest_only = get_option('frp_fetch_latest_only', false);
    echo "<input type='checkbox' name='frp_fetch_latest_only' value='1' " . checked(1, $fetch_latest_only, false) . " /> Hanya ambil artikel terbaru";
}

// Fungsi untuk menghasilkan tag berdasarkan konten menggunakan OpenAI, sesuai model yang dipilih
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

// Fungsi untuk menulis ulang judul dan konten dengan opsi bahasa
function frp_rewrite_title_and_content($title, $content) {
    $api_key = get_option('frp_api_key');
    $custom_prompt = get_option('frp_custom_prompt', 'Rewrite the following title and content:');
    $model = get_option('frp_model', 'gpt-4o-mini');
    $language = get_option('frp_language', 'en');
    $max_tokens = get_option('frp_max_tokens', 500); // Default 500
    $temperature = get_option('frp_temperature', 0.5); // Default 0.5

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
        $generated_text = $body['choices'][0]['message']['content'];
        
        // Split berdasarkan double newline untuk memisahkan title dan content
        $parts = explode("\n\n", $generated_text, 2);
        
        // Ambil bagian pertama sebagai title
        $raw_title = trim($parts[0]);
        
        // Bersihkan title dari berbagai prefix yang mungkin muncul
        $title_prefixes = [
            '### Title:',
            '## Title:',
            '# Title:',
            'Title:',
            'TITLE:',
            '### Judul:',
            '## Judul:',
            '# Judul:',
            'Judul:',
            'JUDUL:'
        ];
        
        foreach ($title_prefixes as $prefix) {
            if (stripos($raw_title, $prefix) === 0) {
                $raw_title = trim(substr($raw_title, strlen($prefix)));
                break;
            }
        }
        
        // Bersihkan title dari karakter markdown lainnya
        $raw_title = preg_replace('/^#+\s*/', '', $raw_title); // Hapus # di awal
        $raw_title = preg_replace('/^\*+\s*/', '', $raw_title); // Hapus * di awal
        $raw_title = trim($raw_title);
        
        // Ambil bagian kedua sebagai content (jika ada)
        $raw_content = isset($parts[1]) ? trim($parts[1]) : '';
        
        // Jika tidak ada content terpisah, coba split dengan newline tunggal
        if (empty($raw_content)) {
            $single_parts = explode("\n", $generated_text, 2);
            if (count($single_parts) > 1) {
                $raw_content = trim($single_parts[1]);
            }
        }
        
        // Bersihkan judul dan konten
        $cleaned_title = frp_clean_text($raw_title, true);
        $cleaned_content = frp_clean_text($raw_content, false);

        frp_log_message("Original generated title: " . $raw_title);
        frp_log_message("Cleaned title: " . $cleaned_title);

        return [
            'title' => $cleaned_title,
            'content' => $cleaned_content
        ];
    } else {
        frp_log_message("Error in API response: " . json_encode($body));
        return null;
    }
}

// Fungsi untuk membersihkan teks dari karakter yang tidak diinginkan
function frp_clean_text($text, $is_title = false) {
    if ($is_title) {
        // Hapus berbagai prefix yang mungkin muncul di title
        $title_patterns = [
            '/^(###?\s*)?Title:\s*/i',
            '/^(###?\s*)?Judul:\s*/i',
            '/^#+\s*/',  // Hapus markdown headers
            '/^\*+\s*/', // Hapus bullet points
            '/^-+\s*/',  // Hapus dashes
        ];
        
        foreach ($title_patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        // Hapus quotes di awal dan akhir jika ada
        $text = trim($text, '"\'');
        
        // Hapus karakter yang tidak diinginkan tapi pertahankan karakter khusus yang valid
        $text = preg_replace('/[^\p{L}\p{N}\s\-\?\!\.\,\:\;\(\)]/u', '', $text);
        
        // Bersihkan spasi berlebihan
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    } else {
        // Untuk content, hapus prefix "Content:" atau "Isi:"
        $text = preg_replace('/^(###?\s*)?(Content|Isi|Konten):\s*/i', '', $text);

        // Mengubah format `### Kalimat` menjadi `<h2>Kalimat</h2>`
        $text = preg_replace('/^###\s*(.*)$/m', '<h2>$1</h2>', $text);

        // Mengubah teks di dalam tanda bintang dua `**teks**` menjadi teks bold `<strong>teks</strong>`
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);

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


// Fungsi untuk generate Table of Contents
function frp_generate_toc($content) {
    preg_match_all('/<h([2-3])[^>]*>(.*?)<\/h[2-3]>/i', $content, $matches, PREG_SET_ORDER);
    
    if (empty($matches)) {
        return $content;
    }
    
    $toc = '<div class="frp-toc"><h3>Table of Contents</h3><ul>';
    $updated_content = $content;
    
    foreach ($matches as $match) {
        $level = $match[1];
        $heading_text = strip_tags($match[2]);
        $slug = sanitize_title_with_dashes($heading_text);
        
        // Add TOC item
        $indent = $level == '3' ? 'style="margin-left: 20px;"' : '';
        $toc .= '<li ' . $indent . '><a href="#' . $slug . '">' . $heading_text . '</a></li>';
        
        // Add ID to heading in content
        $old_heading = $match[0];
        $new_heading = '<h' . $level . ' id="' . $slug . '">' . $match[2] . '</h' . $level . '>';
        $updated_content = str_replace($old_heading, $new_heading, $updated_content);
    }
    
    $toc .= '</ul></div>';
    
    // Insert TOC after first paragraph
    $paragraphs = explode('</p>', $updated_content, 2);
    if (count($paragraphs) > 1) {
        return $paragraphs[0] . '</p>' . $toc . $paragraphs[1];
    }
    
    return $toc . $updated_content;
}

// Update fungsi frp_extract_article_image untuk menangani gambar TPG dengan lebih baik
function frp_extract_article_image($html, $url) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk The Points Guy
    if (strpos($url, 'thepointsguy.com') !== false) {
        $tpg_selectors = [
            "//meta[@property='og:image']/@content",
            "//div[contains(@class, 'wp-caption')]//img/@src",
            "//figure[contains(@class, 'wp-caption')]//img/@src",
            "//div[contains(@class, 'post-content')]//img[1]/@src",
            "//article//img[contains(@class, 'wp-image')]/@src",
            "//div[contains(@class, 'entry-content')]//img[1]/@src"
        ];
        
        foreach ($tpg_selectors as $selector) {
            $images = $xpath->query($selector);
            if ($images->length > 0) {
                $image_url = $images->item(0)->value;
                // Pastikan URL gambar valid dan bukan placeholder
                if (!empty($image_url) && 
                    !strpos($image_url, 'placeholder') && 
                    !strpos($image_url, 'default') &&
                    (strpos($image_url, '.jpg') || strpos($image_url, '.jpeg') || strpos($image_url, '.png') || strpos($image_url, '.webp'))) {
                    frp_log_message("TPG image found with selector: " . $selector);
                    return $image_url;
                }
            }
        }
    }
    
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
                ' ' => "')]//",
                '[' => "'][@",
                ']' => "]"
            ]) . "')]//img";
            
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
        "//div[contains(@class, 'post-content')]//img[1]/@src",
        "//main//img[1]/@src"
    ];
    
    foreach ($default_selectors as $selector) {
        $images = $xpath->query($selector);
        if ($images->length > 0) {
            $image_url = $images->item(0)->value;
            if (!empty($image_url)) {
                return $image_url;
            }
        }
    }
    
    frp_log_message("No image found for URL: " . $url);
    return '';
}

// Update fungsi frp_set_featured_image untuk menangani gambar TPG dengan lebih baik
function frp_set_featured_image($post_id, $image_url, $post_title, $content) {
    // Validasi URL gambar terlebih dahulu
    $validated_url = frp_validate_image_url($image_url);
    if (!$validated_url) {
        frp_log_message("Image URL validation failed: " . $image_url);
        return;
    }
    
    $image_url = $validated_url;
    frp_log_message("Attempting to set featured image from URL: " . $image_url);

    // Bersihkan URL gambar
    $image_url = html_entity_decode($image_url);
    
    // Untuk gambar TPG, pastikan menggunakan URL yang optimal
    if (strpos($image_url, 'thepointsguy.freetls.fastly.net') !== false) {
        // Tambahkan parameter untuk optimasi gambar jika belum ada
        if (strpos($image_url, '?') === false) {
            $image_url .= '?fit=1280,960';
        }
    }
    
    // Tambahkan konteks stream untuk HTTPS dengan user agent yang lebih baik
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 5
        ]
    ]);

    // Coba ambil gambar dengan konteks yang sudah diatur
    $image_data = @file_get_contents($image_url, false, $context);

    if ($image_data === false) {
        frp_log_message("Failed to download image from URL: " . $image_url);
        
        // Coba dengan cURL sebagai fallback
        $image_data = frp_download_image_with_curl($image_url);
        if ($image_data === false) {
            frp_log_message("cURL fallback also failed for image: " . $image_url);
            return;
        }
    }

    $upload_dir = wp_upload_dir();
    
    // Dapatkan ekstensi file dari URL
    $file_extension = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    if (empty($file_extension)) {
        // Deteksi dari content type jika memungkinkan
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->buffer($image_data);
        
        switch ($mime_type) {
            case 'image/jpeg':
                $file_extension = 'jpg';
                break;
            case 'image/png':
                $file_extension = 'png';
                break;
            case 'image/gif':
                $file_extension = 'gif';
                break;
            case 'image/webp':
                $file_extension = 'webp';
                break;
            default:
                $file_extension = 'jpg'; // default fallback
        }
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

// Fungsi fallback untuk download gambar menggunakan cURL
function frp_download_image_with_curl($image_url) {
    if (!function_exists('curl_init')) {
        frp_log_message("cURL not available");
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $image_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
        'Pragma: no-cache'
    ]);
    
    $image_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($image_data === false || $http_code >= 400) {
        frp_log_message("cURL download failed. HTTP Code: " . $http_code . ", Error: " . $error);
        return false;
    }
    
    frp_log_message("cURL download successful. HTTP Code: " . $http_code);
    return $image_data;
}

// Fungsi untuk membersihkan konten dari feed sebelum dikirim ke OpenAI
function frp_clean_feed_content($content) {
    // Hapus shortcode WordPress yang mungkin ada
    $content = preg_replace('/\[.*?\]/', '', $content);
    
    // Hapus link yang berlebihan
    $content = preg_replace('/\bhttps?:\/\/[^\s]+/i', '', $content);
    
    // Hapus referensi ke gambar atau caption
    $content = preg_replace('/\b(image|photo|picture|caption|credit|getty images|shutterstock)\b/i', '', $content);
    
    // Hapus karakter HTML entities yang tersisa
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Hapus multiple spaces dan newlines
    $content = preg_replace('/\s+/', ' ', $content);
    $content = preg_replace('/\n+/', "\n", $content);
    
    // Trim whitespace
    $content = trim($content);
    
    return $content;
}

// Fungsi untuk mencatat log
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


// Fungsi untuk menjadwalkan ulang cron berdasarkan interval
function frp_schedule_feed_rewrite($force_reschedule = false) {
    static $is_scheduling = false;
    
    if ($is_scheduling) {
        return;
    }
    
    $is_scheduling = true;
    
    try {
        $event_hook = 'frp_cron_event';
        $existing_schedule = wp_next_scheduled($event_hook);
        $interval_minutes = get_option('frp_cron_interval', 60) * 60; // Konversi ke detik
        
        if (!$existing_schedule || ($force_reschedule && $existing_schedule)) {
            wp_clear_scheduled_hook($event_hook);
            $next_run = time() + $interval_minutes;
            wp_schedule_event($next_run, 'frp_custom_interval', $event_hook);
            frp_log_message("Cron dijadwalkan untuk: " . date('Y-m-d H:i:s', $next_run));
        }
    } finally {
        $is_scheduling = false;
    }
}

// Hapus dan perbaiki hook-hook yang ada
function frp_init_hooks() {
    // Hapus hook yang mungkin menyebabkan pemanggilan berulang
    remove_action('wp', 'frp_schedule_feed_rewrite');
    remove_action('init', 'frp_check_cron_status');
    
    // Tambahkan hook yang diperlukan
    add_action('frp_cron_event', 'frp_rewrite_feed_content');
}
add_action('init', 'frp_init_hooks');

// fungsi untuk mengecek dan log status cron
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
            frp_log_message("Waktu tersisa hingga eksekusi cron berikutnya: {$minutes}m {$seconds}d");
            $last_status_check = time();
        }
    }
}

// Jalankan penjadwalan saat plugin diaktifkan
register_activation_hook(__FILE__, 'frp_schedule_feed_rewrite');

add_action('wp', 'frp_schedule_feed_rewrite');

// Hubungkan dengan fungsi utama
add_action('frp_rewrite_feed_content_event', 'frp_rewrite_feed_content');

// Update fungsi frp_extract_article_content untuk menangani TPG dengan lebih baik
function frp_extract_article_content($html, $url) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk The Points Guy
    if (strpos($url, 'thepointsguy.com') !== false) {
        return frp_extract_tpg_content($html);
    }
    
    // Selector khusus untuk motorsport.com
    if (strpos($url, 'motorsport.com') !== false) {
        $selectors = [
            "//div[contains(@class, 'ms-article-content')]//p",
            "//div[contains(@class, 'text-content')]//p",
            "//div[contains(@class, 'article-content')]//p"
        ];
        
        foreach ($selectors as $selector) {
            $paragraphs = $xpath->query($selector);
            if ($paragraphs->length > 0) {
                $content = '';
                foreach ($paragraphs as $p) {
                    $content .= $p->textContent . "\n\n";
                }
                if (!empty($content)) {
                    frp_log_message("Berhasil mengekstrak konten motorsport.com");
                    return trim($content);
                }
            }
        }
    }
    
    // Selector default untuk situs lain
    $default_selectors = [
        "//article[contains(@class, 'article-content')]",
        "//div[contains(@class, 'article-body')]",
        "//div[contains(@class, 'entry-content')]",
        "//main//article",
        "//div[contains(@class, 'post-content')]",
        "//div[contains(@class, 'content')]//p"
    ];
    
    foreach ($default_selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content = '';
            foreach ($nodes as $node) {
                $text = trim($node->textContent);
                if (!empty($text)) {
                    $content .= $text . "\n\n";
                }
            }
            if (strlen($content) > 200) {
                frp_log_message("Content extracted with selector: " . $selector);
                return trim($content);
            }
        }
    }
    
    frp_log_message("Tidak dapat menemukan konten dengan selector yang tersedia: " . $url);
    return '';
}

// Tambahkan fungsi untuk membersihkan dan memvalidasi URL gambar
function frp_validate_image_url($image_url) {
    if (empty($image_url)) {
        return false;
    }
    
    // Bersihkan URL
    $image_url = html_entity_decode($image_url);
    $image_url = trim($image_url);
    
    // Pastikan URL valid
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        frp_log_message("Invalid image URL: " . $image_url);
        return false;
    }
    
    // Cek ekstensi file gambar
    $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $url_parts = parse_url($image_url);
    $path_info = pathinfo($url_parts['path']);
    
    if (isset($path_info['extension'])) {
        $extension = strtolower($path_info['extension']);
        if (!in_array($extension, $valid_extensions)) {
            frp_log_message("Invalid image extension: " . $extension);
            return false;
        }
    }
    
    // Cek apakah URL mengandung parameter gambar (untuk URL dinamis)
    if (strpos($image_url, '?') !== false) {
        $query_params = parse_url($image_url, PHP_URL_QUERY);
        if (strpos($query_params, 'fit=') !== false || 
            strpos($query_params, 'w=') !== false || 
            strpos($query_params, 'h=') !== false) {
            // URL dengan parameter resize, kemungkinan valid
            return $image_url;
        }
    }
    
    return $image_url;
}