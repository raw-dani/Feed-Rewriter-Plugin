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
        <form method="post" action="options.php">
            <?php
            settings_fields('frp_settings_group');
            do_settings_sections('frp_settings');
            submit_button();
            ?>
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
        $cron_status = get_option('frp_cron_status', 'inactive');

        // Cek status cron
        if ($cron_status === 'inactive') {
            frp_log_message("Cron job tidak dijalankan karena status inactive.");
            delete_transient('frp_cron_running');
            return;
        }

        // Ambil pengaturan
        $feed_url = get_option('frp_feed_url');
        $feed_urls = array_filter(array_map('trim', explode("\n", $feed_url)));
        $article_created = false;

        $fetch_latest_only = get_option('frp_fetch_latest_only', false);
        $ignore_processed_urls = get_option('frp_ignore_processed_urls', false);
        $processed_urls = get_option('frp_processed_urls', []);
        $ignore_no_image = get_option('frp_ignore_no_image', '1');
        $selected_category = get_option('frp_selected_category');
        $keyword_filter = get_option('frp_keyword_filter', '');
        $keywords = array_filter(array_map('trim', explode("\n", $keyword_filter)));
    
        $exclude_keyword_filter = get_option('frp_exclude_keyword_filter', '');
        $last_processed_date = get_option('frp_last_processed_date', '');
        $exclude_keywords = array_map('trim', explode(',', strtolower($exclude_keyword_filter)));

        if (empty($feed_url)) {
            frp_log_message("Feed URL is empty. Please set it in the settings.");
            return;
        }

        // Log URL yang akan diproses
        frp_log_message("Fetching content from feed URL: " . $feed_url);

        // Mengambil konten dari URL feed
        $response = wp_remote_get($feed_url);

        if (is_wp_error($response)) {
            frp_log_message("Error fetching feed: " . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        frp_log_message("Raw response length: " . strlen($body) . " characters");

        try {
            $xml = new SimpleXMLElement($body);
            $article_found = false;

            // Deteksi dan proses feed berdasarkan format (RSS atau Atom)
            if ($xml->channel) {
                frp_log_message("Detected RSS feed format");
                foreach ($xml->channel->item as $item) {
                    $original_title = strip_tags((string)$item->title);
                    $link = (string)$item->link;
                    $pub_date = date('Y-m-d H:i:s', strtotime((string)$item->pubDate));

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

                    // Ambil deskripsi tanpa tag HTML
                    $description = strip_tags(html_entity_decode((string)$item->description));
                    
                    // Ambil content:encoded jika ada
                    $content_encoded = '';
                    if (isset($item->children('content', true)->encoded)) {
                        $content_encoded = strip_tags(html_entity_decode((string)$item->children('content', true)->encoded));
                    }

                    frp_log_message("Processing article: {$original_title}");
                    frp_log_message("Description length: " . strlen($description));
                    if (!empty($content_encoded)) {
                        frp_log_message("Content:encoded length: " . strlen($content_encoded));
                    }
                    
                    // Jika masih tidak ada, baru coba ambil dari konten artikel
                    if (empty($image_url)) {
                        $article_response = wp_remote_get($link);
                        if (!is_wp_error($article_response)) {
                            $article_html = wp_remote_retrieve_body($article_response);
                            $image_url = frp_extract_article_image($article_html, $link);
                        }
                    }

                    frp_log_message("Image URL found: " . $image_url);
        

                    frp_log_message("Processing article: {$original_title}");
                    
                    // Ambil konten lengkap dari URL artikel
                    frp_log_message("Fetching full article content from: " . $link);
                    
                    $article_response = wp_remote_get($link, [
                        'timeout' => 30,
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                        'headers' => [
                            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                            'Accept-Language' => 'en-US,en;q=0.5',
                        ]
                    ]);
                    
                    if (is_wp_error($article_response)) {
                        frp_log_message("Error mengambil konten artikel: " . $article_response->get_error_message());
                        continue;
                    }
            
                    $article_html = wp_remote_retrieve_body($article_response);
                    // Tambahkan ekstraksi konten khusus untuk CNN Indonesia
                    $content = '';
                    if (strpos($link, 'cnnindonesia.com') !== false) {
                        $content = frp_extract_cnn_content($article_html);
                    } else {
                        $content = frp_extract_article_content($article_html, $link);
                    }
            
                    if (empty($content)) {
                        frp_log_message("Tidak dapat mengekstrak konten dari artikel: " . $link);
                        continue;
                    }
            
                    frp_log_message("Berhasil mengekstrak konten. Panjang: " . strlen($content) . " karakter");
                    
                    // Simpan konten mentah untuk debugging
                    $raw_content_log = plugin_dir_path(__FILE__) . 'raw_article_content.log';
                    file_put_contents($raw_content_log, "=== Article Content from {$link} ===\n\n{$content}");

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

                    // Proses rewrite dengan konten lengkap
                    frp_log_message("Starting content rewriting process...");
                    $new_title_and_content = frp_rewrite_title_and_content($original_title, $content);

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
                                '_frp_source_link' => $link
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

                            // Update processed URLs
                            $processed_urls[] = $link;
                            update_option('frp_processed_urls', $processed_urls);
                            update_option('frp_last_processed_date', $pub_date);
                            
                            $article_found = true;
                            break; // Keluar dari loop setelah satu artikel berhasil diproses
                        } else {
                            frp_log_message("Failed to create new post");
                        }
                    } else {
                        frp_log_message("Failed to rewrite content");
                    }
                }
            } elseif ($xml->entry) {
                // ... kode serupa untuk format Atom ...
            }

            if (!$article_found) {
                frp_log_message("No suitable article found to process");
            }

        } catch (Exception $e) {
            frp_log_message("Error parsing feed XML: " . $e->getMessage());
            $debug_log = plugin_dir_path(__FILE__) . 'debug_raw_feed.log';
            file_put_contents($debug_log, $body);
        }

        // Setelah berhasil memproses satu artikel
        if ($article_found) {
            frp_log_message("Article processed successfully. Next execution will be at next scheduled time.");
            
            // Update waktu terakhir cron berjalan
            update_option('frp_last_cron_run', current_time('mysql'));
        }

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

// Mendaftarkan pengaturan
function frp_register_settings() {
    register_setting('frp_settings_group', 'frp_api_key');
    register_setting('frp_settings_group', 'frp_feed_url');
    register_setting('frp_settings_group', 'frp_image_selector');    
    register_setting('frp_settings_group', 'frp_keyword_filter');
    register_setting('frp_settings_group', 'frp_exclude_keyword_filter');
    register_setting('frp_settings_group', 'frp_cron_interval');
    register_setting('frp_settings_group', 'frp_custom_prompt');
    register_setting('frp_settings_group', 'frp_model');
    register_setting('frp_settings_group', 'frp_max_tokens'); // Opsi max tokens
    register_setting('frp_settings_group', 'frp_temperature'); // Opsi temperature
    register_setting('frp_settings_group', 'frp_fetch_latest_only');
    register_setting('frp_settings_group', 'frp_language');
    register_setting('frp_settings_group', 'frp_ignore_processed_urls');
    register_setting('frp_settings_group', 'frp_ignore_no_image');
    register_setting('frp_settings_group', 'frp_category');
    register_setting('frp_settings_group', 'frp_selected_category');
    register_setting('frp_settings_group', 'frp_enable_toc'); // Mengaktifkan atau menonaktifkan TOC
    register_setting('frp_settings_group', 'frp_cron_status');

    add_settings_section('frp_main_settings', 'Main Settings', null, 'frp_settings');

    add_settings_field('frp_api_key', 'OpenAI API Key', 'frp_api_key_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_feed_url', 'Feed URL', 'frp_feed_url_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field(
        'frp_image_selector', 
        'Image Selector', 
        'frp_image_selector_callback', 
        'frp_settings', 
        'frp_main_settings'
    );
    add_settings_field('frp_keyword_filter', 'Keyword Filter', 'frp_keyword_filter_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_exclude_keyword_filter', 'Exclude Keyword Filter', 'frp_exclude_keyword_filter_callback', 'frp_settings', 'frp_main_settings');    
    add_settings_field('frp_cron_interval', 'Cron Interval (jam)', 'frp_cron_interval_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_custom_prompt', 'Custom Prompt', 'frp_custom_prompt_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_model', 'OpenAI Model', 'frp_model_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_max_tokens', 'Max Tokens', 'frp_max_tokens_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_temperature', 'Temperature', 'frp_temperature_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_fetch_latest_only', 'Fetch Latest Articles Only', 'frp_fetch_latest_only_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_language', 'Language', 'frp_language_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_processed_urls', 'Ignore Processed URLs', 'frp_ignore_processed_urls_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_ignore_no_image', 'Ignore Articles Without Images', 'frp_ignore_no_image_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_category', 'Category', 'frp_category_callback', 'frp_settings', 'frp_main_settings');
    add_settings_field('frp_cron_status', 'Pause/Stop Cron', 'frp_cron_status_callback', 'frp_settings', 'frp_main_settings');
}
add_action('admin_init', 'frp_register_settings');

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
    echo "<textarea name='frp_feed_url' rows='5' style='width: 100%;'>" . esc_textarea($feed_url) . "</textarea>";
    echo "<p class='description'>Masukkan URL feed, satu URL per baris</p>";
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


// Fungsi untuk membersihkan teks dari karakter yang tidak diinginkan
function frp_clean_text($text, $is_title = false) {
    if ($is_title) {
        // Menghapus kata "Judul" dari title
        $text = preg_replace('/\bJudul\b/i', '', $text); 
        // Membersihkan karakter aneh pada judul
        $text = preg_replace('/[^a-zA-Z0-9\s\p{L}]/u', '', $text); // Menghapus karakter selain huruf, angka, dan spasi
        $text = trim($text); // Menghapus spasi berlebihan
        return $text;
    } else {
        // Menghapus kata "Konten:" atau "Isi:" dari awal konten
        $text = preg_replace('/^(Konten:|Isi:)\s*/i', '', $text);

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

// Modifikasi fungsi ekstraksi gambar
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

// Fungsi untuk mengatur featured image dengan nama file dan alt berdasarkan judul
function frp_set_featured_image($post_id, $image_url, $post_title, $content) {
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

// // Hapus jadwal saat plugin dinonaktifkan
// function frp_deactivate_plugin() {
//     $timestamp = wp_next_scheduled('frp_rewrite_feed_content_event');
//     if ($timestamp) {
//         wp_unschedule_event($timestamp, 'frp_rewrite_feed_content_event');
//     }
//     frp_log_message("Cron job unscheduled upon plugin deactivation.");
// }
// register_deactivation_hook(__FILE__, 'frp_deactivate_plugin');

// Fungsi helper untuk ekstraksi konten
function frp_extract_article_content($html, $url) {
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Selector khusus untuk motorsport.com
    if (strpos($url, 'motorsport.com') !== false) {
        // Coba ambil konten dari artikel motorsport.com
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
                    frp_log_message("Berhasil mengekstrak konten");
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
        "//div[contains(@class, 'post-content')]"
    ];
    
    foreach ($default_selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
    }
    
    frp_log_message("Tidak dapat menemukan konten dengan selector yang tersedia: " . $url);
    return '';
}

