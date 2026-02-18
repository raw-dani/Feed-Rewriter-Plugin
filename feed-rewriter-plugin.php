<?php
/**
 * Plugin Name: Feed Rewriter Plugin
 * Plugin URI: https://github.com/raw-dani/Feed-Rewriter-Plugin
 * Description: Plugin untuk mengambil feed RSS dan menulis ulang konten menggunakan OpenAI
 * Version: 1.1.0
 * Author: Rohmat Ali Wardani
 * Author URI: https://www.linkedin.com/in/rohmat-ali-wardani/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feed-rewriter-plugin
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ==============================================================================
// SECTION 1: CORE CONSTANTS AND INITIALIZATION
// ==============================================================================

// Define plugin constants
define('FRP_VERSION', '1.2.0');
define('FRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FRP_LOG_FILE', FRP_PLUGIN_DIR . 'frp_log.txt');
define('FRP_MAX_LOG_SIZE', 5242880); // 5MB max log file size
define('FRP_TRANSIENT_PREFIX', 'frp_');
define('FRP_CRON_HOOK', 'frp_main_cron_event');
define('FRP_MAX_FEEDS', 5);

// ==============================================================================
// SECTION 2: ACTIVATION/DEACTIVATION HANDLERS (CONSOLIDATED)
// ==============================================================================

/**
 * Consolidated activation hook - handles all plugin activation logic
 */
function frp_activate_plugin() {
    // Initialize default options
    frp_init_default_options();
    
    // Clear any existing cron schedules to avoid conflicts
    frp_clear_all_cron_schedules();
    
    // Set initial cron status
    update_option('frp_cron_status', 'active');
    
    // Schedule the main cron event
    frp_schedule_main_cron();
    
    // Log activation
    frp_log_message("Plugin activated v" . FRP_VERSION . ". Configure settings and click 'Run Now' to start.");
}
register_activation_hook(__FILE__, 'frp_activate_plugin');

/**
 * Consolidated deactivation hook - handles all plugin cleanup
 */
function frp_deactivate_plugin() {
    // Clear all cron schedules
    frp_clear_all_cron_schedules();
    
    // Set status to inactive
    update_option('frp_cron_status', 'inactive');
    
    frp_log_message("Plugin deactivated - all cron jobs cleared.");
}
register_deactivation_hook(__FILE__, 'frp_deactivate_plugin');

/**
 * Initialize default plugin options
 */
function frp_init_default_options() {
    $defaults = [
        'frp_cron_interval' => 60,
        'frp_cron_status' => 'active',
        'frp_api_key' => '',
        'frp_model' => 'gpt-4o-mini',
        'frp_max_tokens' => 1500,
        'frp_temperature' => 0.5,
        'frp_language' => 'en',
        'frp_fetch_latest_only' => '',
        'frp_ignore_processed_urls' => '1',
        'frp_ignore_no_image' => '1',
        'frp_enable_toc' => 'yes',
        'frp_enable_enhanced_research' => '1',
        'frp_enhanced_max_sources' => 3,
        'frp_keyword_filter' => '',
        'frp_exclude_keyword_filter' => '',
        'frp_post_status' => 'publish', // NEW: Post status selection
        'frp_enable_retry' => '1', // NEW: Enable retry logic
    ];
    
    foreach ($defaults as $key => $value) {
        add_option($key, $value);
    }
}

/**
 * Clear all cron schedules to prevent conflicts
 */
function frp_clear_all_cron_schedules() {
    // Clear main cron
    wp_clear_scheduled_hook(FRP_CRON_HOOK);
    
    // Clear individual feed crons
    for ($i = 1; $i <= FRP_MAX_FEEDS; $i++) {
        wp_clear_scheduled_hook("frp_feed_cron_{$i}");
    }
    
    // Clear status check cron
    wp_clear_scheduled_hook('frp_status_check_cron');
}

/**
 * Schedule the main cron event
 */
function frp_schedule_main_cron() {
    $interval = get_option('frp_cron_interval', 60);
    $interval = max(1, intval($interval));
    
    // Clear existing schedule
    wp_clear_scheduled_hook(FRP_CRON_HOOK);
    
    // Schedule new event
    $next_run = time() + ($interval * 60);
    wp_schedule_event($next_run, 'frp_custom_interval', FRP_CRON_HOOK);
    
    frp_log_message("Main cron scheduled: every {$interval} minutes");
}

// ==============================================================================
// SECTION 3: CRON SCHEDULES REGISTRATION (FIXED)
// ==============================================================================

/**
 * Register custom cron schedules - only register if not already registered
 * Optimized to reduce database queries
 */
function frp_register_cron_schedules($schedules = []) {
    // Only add custom interval if not exists - prevents duplicate registration
    if (empty($schedules)) {
        $interval = get_option('frp_cron_interval', 60);
        $interval = max(1, intval($interval));
        
        $schedules['frp_custom_interval'] = [
            'interval' => $interval * 60,
            'display'  => sprintf(__('Every %d minutes'), $interval)
        ];
    }
    
    // Only add common intervals if not exists
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute')
        ];
    }
    
    if (!isset($schedules['fifteen_minutes'])) {
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * 60,
            'display'  => __('Every 15 Minutes')
        ];
    }
    
    if (!isset($schedules['thirty_minutes'])) {
        $schedules['thirty_minutes'] = [
            'interval' => 30 * 60,
            'display'  => __('Every 30 Minutes')
        ];
    }
    
    return $schedules;
}
add_filter('cron_schedules', 'frp_register_cron_schedules');

// ==============================================================================
// SECTION 4: MAIN CRON HANDLER
// ==============================================================================

/**
 * Main cron event handler - processes all feeds
 */
add_action(FRP_CRON_HOOK, 'frp_process_all_feeds');

/**
 * Process all configured feeds
 */
function frp_process_all_feeds() {
    // Check if already running
    $lock_key = FRP_TRANSIENT_PREFIX . 'cron_running';
    if (get_transient($lock_key)) {
        frp_log_message("Cron already running - skipping this execution");
        return;
    }
    
    // Set lock
    set_transient($lock_key, true, 25 * MINUTE_IN_SECONDS);
    
    // Check cron status
    $status = get_option('frp_cron_status', 'inactive');
    if ($status !== 'active') {
        frp_log_message("Cron is paused - skipping execution");
        delete_transient($lock_key);
        return;
    }
    
    // Validate API key
    $api_key = get_option('frp_api_key');
    if (empty($api_key)) {
        frp_log_message("ERROR: OpenAI API key not configured");
        delete_transient($lock_key);
        return;
    }
    
    try {
        $processed = 0;
        
        // Process each feed configuration
        for ($config_num = 1; $config_num <= FRP_MAX_FEEDS; $config_num++) {
            $result = frp_process_single_feed_v2($config_num);
            if ($result) {
                $processed++;
                // Only process one article per cron run to avoid rate limiting
                break;
            }
        }
        
        // Update last run time
        if ($processed > 0) {
            update_option('frp_last_cron_run', current_time('mysql'));
            frp_log_message("Cron completed - {$processed} article(s) processed");
        } else {
            frp_log_message("Cron completed - no articles processed");
        }
        
    } catch (Exception $e) {
        frp_log_message("ERROR: " . $e->getMessage());
    } finally {
        delete_transient($lock_key);
    }
}

/**
 * Process a single feed configuration (optimized version)
 */
function frp_process_single_feed_v2($config_num) {
    $feed_url = get_option("frp_feed_url_{$config_num}", '');
    
    if (empty($feed_url)) {
        return false;
    }
    
    // Check if it's time to process this feed
    $interval = get_option("frp_cron_interval_{$config_num}", 60);
    $last_run = get_option("frp_last_run_config_{$config_num}", 0);
    
    if (time() - $last_run < ($interval * 60)) {
        return false;
    }
    
    // Get settings
    $category = get_option("frp_selected_category_{$config_num}", '');
    $custom_prompt = get_option("frp_custom_prompt_{$config_num}", 'Rewrite the following content:');
    
    frp_log_message("Processing Feed #{$config_num}: " . substr($feed_url, 0, 40) . "...");
    
    // Fetch feed
    $response = wp_remote_get($feed_url, [
        'timeout' => 15,
        'user-agent' => 'Mozilla/5.0 (WordPress Feed Reader)',
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("Feed fetch error: " . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $xml = @simplexml_load_string($body);
    
    if (!$xml || !isset($xml->channel)) {
        frp_log_message("Invalid feed XML");
        return false;
    }
    
    // Process items - limit to first 10 items for performance
    $processed_urls = get_option("frp_processed_urls_{$config_num}", []);
    $ignore_processed = get_option('frp_ignore_processed_urls', '1');
    $max_items = apply_filters('frp_max_feed_items', 10); // Allow filtering
    
    $item_count = 0;
    foreach ($xml->channel->item as $item) {
        $item_count++;
        if ($item_count > $max_items) {
            frp_log_message("Reached max items limit ({$max_items})");
            break;
        }
        
        $link = (string)$item->link;
        
        // Skip already processed
        if ($ignore_processed === '1' && in_array($link, $processed_urls)) {
            continue;
        }
        
        // Get content
        $content = frp_extract_feed_content($item);
        $title = (string)$item->title;
        
        if (empty($content) || strlen($content) < 100) {
            continue;
        }
        
        // Check keyword filters
        if (!frp_check_keyword_filters($title, $content)) {
            continue;
        }
        
        // Check if enhanced research is enabled - use enhanced rewrite
        if (get_option('frp_enable_enhanced_research', '1') === '1') {
            frp_log_message("  -> Using Enhanced Research Mode for article rewriting");
            $rewritten = frp_rewrite_with_enhanced_research($title, $content, $custom_prompt, $link);
        } else {
            // Regular rewrite
            $rewritten = frp_rewrite_with_openai($title, $content, $custom_prompt);
        }
        
        if (!$rewritten) {
            continue;
        }
        
        // Create post
        $post_id = wp_insert_post([
            'post_title' => $rewritten['title'],
            'post_content' => $rewritten['content'],
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_category' => $category ? [$category] : [],
            'meta_input' => [
                '_frp_generated' => 1,
                '_frp_source_feed' => $feed_url,
                '_frp_source_link' => $link,
                '_frp_config_num' => $config_num
            ],
        ]);
        
        if ($post_id) {
            // Set featured image - first try from feed, then fallback to article URL
            $image_url = frp_extract_feed_image($item);
            
            // If no image from feed, try to get from article URL
            if (empty($image_url)) {
                $image_url = frp_extract_image_from_url($link);
            }
            
            if ($image_url) {
                frp_set_post_thumbnail($post_id, $image_url);
            }
            
            // Generate tags only if enabled (saves API calls)
            $enable_tags = get_option('frp_enable_tags', '1');
            if ($enable_tags === '1') {
                $tags = frp_generate_post_tags($rewritten['content']);
                if (!empty($tags)) {
                    wp_set_post_tags($post_id, $tags);
                }
            }
            
            // Add SEO optimizations
            frp_add_seo_meta($post_id, $rewritten['title'], $rewritten['content'], $image_url);
            
            // Add Schema.org structured data
            frp_add_schema_markup($post_id, $rewritten['title'], $rewritten['content'], $image_url);
            
            // Update processed URLs
            $processed_urls[] = $link;
            update_option("frp_processed_urls_{$config_num}", $processed_urls);
            update_option("frp_last_run_config_{$config_num}", time());
            
            frp_log_message("SUCCESS: Created post #{$post_id}");
            return true;
        }
    }
    
    return false;
}

/**
 * Extract content from feed item
 */
function frp_extract_feed_content($item) {
    $content = '';
    
    // Try content:encoded first
    if (isset($item->children('content', true)->encoded)) {
        $content = strip_tags(html_entity_decode((string)$item->children('content', true)->encoded));
    }
    
    // Fall back to description
    if (empty($content)) {
        $content = strip_tags(html_entity_decode((string)$item->description));
    }
    
    // Clean up
    $content = preg_replace('/The post .* appeared first on .*\./s', '', $content);
    return trim($content);
}

/**
 * Extract image from feed item
 */
function frp_extract_feed_image($item) {
    // Try enclosure
    if (isset($item->enclosure['url'])) {
        return (string)$item->enclosure['url'];
    }
    
    // Try media:content
    if (isset($item->children('media', true)->content)) {
        return (string)$item->children('media', true)->content['url'];
    }
    
    // Try description for img tag
    $desc = (string)$item->description;
    if (preg_match('/<img[^>]+src="([^"]+)"/', $desc, $matches)) {
        return $matches[1];
    }
    
    return '';
}

/**
 * Extract image from article URL (fallback when feed has no image)
 * NEW FEATURE - Fetches the article page and extracts featured image
 */
function frp_extract_image_from_url($article_url) {
    if (empty($article_url)) {
        return '';
    }
    
    frp_log_message("  -> Fetching image from article URL: " . substr($article_url, 0, 40) . "...");
    
    // Fetch the article page
    $response = wp_remote_get($article_url, [
        'timeout' => 15,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("  -> Failed to fetch article: " . $response->get_error_message());
        return '';
    }
    
    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        return '';
    }
    
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($doc);
    
    // Priority 1: Open Graph image (most reliable)
    $og_image = $xpath->query("//meta[@property='og:image']/@content");
    if ($og_image->length > 0) {
        $url = $og_image->item(0)->nodeValue;
        if (!empty($url)) {
            frp_log_message("  -> Found OG Image: " . substr($url, 0, 50) . "...");
            return $url;
        }
    }
    
    // Priority 2: Twitter card image
    $twitter_image = $xpath->query("//meta[@name='twitter:image']/@content");
    if ($twitter_image->length > 0) {
        $url = $twitter_image->item(0)->nodeValue;
        if (!empty($url)) {
            frp_log_message("  -> Found Twitter Image: " . substr($url, 0, 50) . "...");
            return $url;
        }
    }
    
    // Priority 3: Schema.org image
    $schema_image = $xpath->query("//script[@type='application/ld+json']");
    if ($schema_image->length > 0) {
        foreach ($schema_image as $script) {
            $json = $script->nodeValue;
            $data = json_decode($json, true);
            if ($data && isset($data['image'])) {
                $url = is_array($data['image']) ? ($data['image']['url'] ?? '') : $data['image'];
                if (!empty($url)) {
                    frp_log_message("  -> Found Schema Image: " . substr($url, 0, 50) . "...");
                    return $url;
                }
            }
        }
    }
    
    // Priority 4: First large image in content
    $content_images = $xpath->query("//article//img[@src[contains(., '.jpg') or contains(., '.jpeg') or contains(., '.png') or contains(., '.webp')]]");
    if ($content_images->length > 0) {
        foreach ($content_images as $img) {
            $url = $img->getAttribute('src');
            // Skip small images, avatars, icons
            if (strpos($url, 'avatar') === false && 
                strpos($url, 'icon') === false && 
                strpos($url, 'logo') === false) {
                frp_log_message("  -> Found Content Image: " . substr($url, 0, 50) . "...");
                return $url;
            }
        }
    }
    
    // Priority 5: Featured image in common classes
    $featured_selectors = [
        "//div[contains(@class, 'featured')]//img/@src",
        "//div[contains(@class, 'post-thumbnail')]//img/@src",
        "//figure[contains(@class, 'featured')]//img/@src",
    ];
    
    foreach ($featured_selectors as $selector) {
        $images = $xpath->query($selector);
        if ($images->length > 0) {
            $url = $images->item(0)->nodeValue;
            if (!empty($url)) {
                frp_log_message("  -> Found Featured Image: " . substr($url, 0, 50) . "...");
                return $url;
            }
        }
    }
    
    frp_log_message("  -> No image found in article");
    return '';
}

// ==============================================================================
// ENHANCED RESEARCH FEATURE
// ==============================================================================

/**
 * Extract relevant links from an article URL
 * Used for enhanced research mode - OPTIMIZED for low resource usage
 */
function frp_extract_links_from_article($article_url, $max_links = 3) {
    if (empty($article_url)) {
        return [];
    }
    
    // Check cache first to avoid repeated requests
    $cache_key = 'frp_links_' . md5($article_url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        frp_log_message("  -> Using cached links for this article");
        return $cached;
    }
    
    frp_log_message("  -> Extracting related links from article...");
    
    // Fetch the article page - reduced timeout for performance
    $response = wp_remote_get($article_url, [
        'timeout' => 10, // Reduced from 15 for faster failure
        'user-agent' => 'Mozilla/5.0 (WordPress Feed Reader)',
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("  -> Failed to fetch article for links: " . $response->get_error_message());
        return [];
    }
    
    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        return [];
    }
    
    $doc = new DOMDocument();
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    
    $links = [];
    $anchor_tags = $doc->getElementsByTagName('a');
    
    foreach ($anchor_tags as $anchor) {
        $href = $anchor->getAttribute('href');
        
        // Skip empty links, anchors, and javascript
        if (empty($href) || 
            strpos($href, '#') === 0 || 
            strpos($href, 'javascript:') !== false ||
            strpos($href, 'mailto:') !== false) {
            continue;
        }
        
        // Make relative URLs absolute
        if (strpos($href, 'http') !== 0) {
            $base_url = parse_url($article_url);
            $href = $base_url['scheme'] . '://' . $base_url['host'] . $href;
        }
        
        // Skip same domain (internal links)
        $article_domain = parse_url($article_url, PHP_URL_HOST);
        $link_domain = parse_url($href, PHP_URL_HOST);
        
        // Get link text to check relevance
        $link_text = strtolower($anchor->textContent);
        
        // Skip likely navigation/footer links
        if (strpos($link_text, 'menu') !== false ||
            strpos($link_text, 'home') !== false ||
            strpos($link_text, 'contact') !== false ||
            strpos($link_text, 'about') !== false ||
            strpos($link_text, 'privacy') !== false ||
            strpos($link_text, 'terms') !== false) {
            continue;
        }
        
        // Skip social media and common non-content links
        if (strpos($href, 'facebook.com') !== false ||
            strpos($href, 'twitter.com') !== false ||
            strpos($href, 'instagram.com') !== false ||
            strpos($href, 'linkedin.com') !== false ||
            strpos($href, 'youtube.com') !== false) {
            continue;
        }
        
        // This looks like a content link - add it
        if (!in_array($href, $links)) {
            $links[] = $href;
            if (count($links) >= $max_links) {
                break;
            }
        }
    }
    
    frp_log_message("  -> Found " . count($links) . " related links");
    
    // Cache the links for 24 hours to avoid repeated requests
    set_transient($cache_key, $links, 24 * HOUR_IN_SECONDS);
    
    return $links;
}

/**
 * Fetch and extract content from additional sources - OPTIMIZED
 */
function frp_collect_additional_content($urls) {
    $additional_contents = [];
    
    foreach ($urls as $url) {
        frp_log_message("  -> Fetching additional content from: " . substr($url, 0, 40) . "...");
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        
        if (is_wp_error($response)) {
            frp_log_message("  -> Failed to fetch: " . $response->get_error_message());
            continue;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            continue;
        }
        
        $doc = new DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($doc);
        
        // Try to extract main content using common selectors
        $content_selectors = [
            "//article[contains(@class, 'content')]//p",
            "//div[contains(@class, 'entry-content')]//p",
            "//div[contains(@class, 'post-content')]//p",
            "//div[contains(@class, 'article-content')]//p",
            "//main//p",
            "//article//p",
        ];
        
        $content = '';
        foreach ($content_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 3) { // Need at least a few paragraphs
                foreach ($nodes as $node) {
                    $text = trim($node->textContent);
                    // Skip very short paragraphs (likely navigation or ads)
                    if (strlen($text) > 50) {
                        $content .= $text . "\n\n";
                    }
                }
                if (strlen($content) > 200) {
                    break;
                }
            }
        }
        
        if (!empty($content)) {
            $additional_contents[] = [
                'url' => $url,
                'content' => substr($content, 0, 2000) // Limit to 2000 chars
            ];
            frp_log_message("  -> Collected " . strlen($content) . " characters from additional source");
        }
    }
    
    return $additional_contents;
}

/**
 * Rewrite content with enhanced research (collects additional data first)
 */
function frp_rewrite_with_enhanced_research($title, $content, $custom_prompt, $article_url) {
    $api_key = get_option('frp_api_key');
    $model = get_option('frp_model', 'gpt-4o-mini');
    $language = get_option('frp_language', 'en');
    $max_tokens = get_option('frp_max_tokens', 1500);
    $temperature = get_option('frp_temperature', 0.5);
    $max_sources = get_option('frp_enhanced_max_sources', 3);
    
    // Check if enhanced research is enabled
    if (get_option('frp_enable_enhanced_research', '1') !== '1') {
        return frp_rewrite_with_openai($title, $content, $custom_prompt);
    }
    
    frp_log_message("  -> Enhanced Research Mode: ON");
    
    // Extract related links from the article
    $related_links = frp_extract_links_from_article($article_url, $max_sources);
    
    $research_data = "";
    
    // If we found related links, fetch additional content
    if (!empty($related_links)) {
        $additional_contents = frp_collect_additional_content($related_links);
        
        if (!empty($additional_contents)) {
            $research_data = "\n\n=== ADDITIONAL RESEARCH DATA ===\n";
            foreach ($additional_contents as $idx => $additional) {
                $research_data .= "\n[Source " . ($idx + 1) . ": " . $additional['url'] . "]\n";
                $research_data .= $additional['content'] . "\n";
            }
            $research_data .= "=== END RESEARCH DATA ===\n\n";
        }
    }
    
    // Build the enhanced prompt
    $prompt = $custom_prompt . "\n\n" .
        "Create a comprehensive, well-structured article based on the following content AND the additional research data provided. " .
        "The article should be informative, engaging, and suitable for publishing. " .
        "Use the additional research data to enrich the content with more information and insights. " .
        "Ensure the title is concise (max 65 characters) and engaging. " .
        "The content should be well-structured with proper headings (use <h2> for main sections). " .
        "Do NOT use labels like 'Title:' or 'Content:'. " .
        "Write in " . ($language === 'id' ? 'Bahasa Indonesia' : 'English') . ".\n\n" .
        "Title: {$title}\n\n" .
        "Main Content:\n{$content}" .
        $research_data;
    
    // Send to OpenAI
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => (float) $temperature,
            'max_tokens' => $max_tokens > 0 ? (int) $max_tokens : null
        ]),
        'timeout' => 90 // Longer timeout for enhanced research
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("OpenAI API error: " . $response->get_error_message());
        // Fallback to regular rewrite
        return frp_rewrite_with_openai($title, $content, $custom_prompt);
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!isset($body['choices'][0]['message']['content'])) {
        frp_log_message("OpenAI API invalid response - falling back to regular rewrite");
        return frp_rewrite_with_openai($title, $content, $custom_prompt);
    }
    
    $text = $body['choices'][0]['message']['content'];
    $parts = explode("\n\n", $text, 2);
    
    $clean_title = trim($parts[0]);
    $clean_title = preg_replace('/^(Title:|Judul:)\s*/i', '', $clean_title);
    $clean_title = trim($clean_title, '"\'');
    
    $clean_content = isset($parts[1]) ? trim($parts[1]) : '';
    $clean_content = frp_clean_output_content($clean_content);
    
    return [
        'title' => $clean_title,
        'content' => $clean_content
    ];
}

/**
 * Check keyword filters
 */
function frp_check_keyword_filters($title, $content) {
    // Check included keywords
    $keywords = get_option('frp_keyword_filter', '');
    if (!empty($keywords)) {
        $keyword_list = array_filter(array_map('trim', explode("\n", $keywords)));
        $found = false;
        
        foreach ($keyword_list as $keyword) {
            if (!empty($keyword) && (stripos($title, $keyword) !== false || stripos($content, $keyword) !== false)) {
                $found = true;
                break;
            }
        }
        
        if (!$found && !empty($keyword_list)) {
            return false;
        }
    }
    
    // Check excluded keywords
    $exclude = get_option('frp_exclude_keyword_filter', '');
    if (!empty($exclude)) {
        $exclude_list = array_map('trim', explode(',', strtolower($exclude)));
        
        foreach ($exclude_list as $word) {
            if (!empty($word) && (stripos($title, $word) !== false || stripos($content, $word) !== false)) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Rewrite content with OpenAI
 */
function frp_rewrite_with_openai($title, $content, $custom_prompt) {
    $api_key = get_option('frp_api_key');
    $model = get_option('frp_model', 'gpt-4o-mini');
    $language = get_option('frp_language', 'en');
    $max_tokens = get_option('frp_max_tokens', 1500);
    $temperature = get_option('frp_temperature', 0.5);
    
    $prompt = "{$custom_prompt}\n\n" .
        "Rewrite the following title and content to make it suitable for publishing. " .
        "Ensure the title is concise (max 65 characters) and engaging. " .
        "The content should be well-structured without labels like 'Title:' or 'Content:'. " .
        "Write in " . ($language === 'id' ? 'Bahasa Indonesia' : 'English') . ".\n\n" .
        "Title: {$title}\n\n" .
        "Content:\n{$content}";
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => (float) $temperature,
            'max_tokens' => $max_tokens > 0 ? (int) $max_tokens : null
        ]),
        'timeout' => 60
    ]);
    
    if (is_wp_error($response)) {
        frp_log_message("OpenAI API error: " . $response->get_error_message());
        return null;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!isset($body['choices'][0]['message']['content'])) {
        frp_log_message("OpenAI API invalid response");
        return null;
    }
    
    $text = $body['choices'][0]['message']['content'];
    $parts = explode("\n\n", $text, 2);
    
    $clean_title = trim($parts[0]);
    $clean_title = preg_replace('/^(Title:|Judul:)\s*/i', '', $clean_title);
    $clean_title = trim($clean_title, '"\'');
    
    $clean_content = isset($parts[1]) ? trim($parts[1]) : '';
    $clean_content = frp_clean_output_content($clean_content);
    
    return [
        'title' => $clean_title,
        'content' => $clean_content
    ];
}

/**
 * Clean output content - add TOC, formatting, etc. (Google SEO Optimized)
 */
function frp_clean_output_content($content) {
    // Convert markdown
    $content = preg_replace('/^###\s+(.*)$/m', '<h2>$1</h2>', $content);
    $content = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $content);
    
    // Add TOC if enabled - Google SEO Optimized Version
    if (get_option('frp_enable_toc', 'yes') === 'yes') {
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/i', $content, $matches);
        
        if (!empty($matches[1])) {
            // Add smooth scroll CSS to content
            $smooth_scroll_css = '
<style>
.frp-toc { background: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 8px; }
.frp-toc h3 { margin-top: 0; margin-bottom: 15px; font-size: 18px; }
.frp-toc ul { margin: 0; padding-left: 20px; }
.frp-toc li { margin-bottom: 8px; }
.frp-toc a { color: #0066cc; text-decoration: none; }
.frp-toc a:hover { text-decoration: underline; }
html { scroll-behavior: smooth; }
</style>
';
            
            // Build TOC with proper semantic HTML and ARIA for accessibility
            $toc_id = 'frp-toc-' . uniqid();
            $toc = '<nav aria-label="Table of Contents" id="' . $toc_id . '" class="frp-toc">';
            $toc .= '<h3>Table of Contents</h3>';
            $toc .= '<ul>';
            
            // Build schema.org structured data for navigation
            $toc_items = [];
            
            foreach ($matches[1] as $index => $heading) {
                $slug = sanitize_title_with_dashes(strip_tags($heading));
                // Ensure unique IDs
                $slug = $slug . '-' . $index;
                $heading_text = strip_tags($heading);
                
                $toc .= '<li><a href="#' . esc_attr($slug) . '">' . esc_html($heading_text) . '</a></li>';
                
                // Add ID to heading
                $content = preg_replace(
                    '/(<h2[^>]*>)(' . preg_quote($heading_text, '/') . ')(<\/h2>)/i',
                    '$1 id="' . esc_attr($slug) . '">$2$3',
                    $content,
                    1
                );
                
                // Add to schema items
                $toc_items[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $heading_text,
                    'item' => '#' . $slug
                ];
            }
            
            $toc .= '</ul></nav>';
            
            // Prepend smooth scroll CSS
            $content = $smooth_scroll_css . $toc . "\n" . $content;
            
            // Store TOC schema for later output (will be added in schema function)
            // This prevents conflict with existing NewsArticle schema
            global $frp_toc_schema;
            $frp_toc_schema = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'itemListElement' => $toc_items,
                'description' => 'Table of Contents for this article'
            ];
        }
    }
    
    return $content;
}

/**
 * Add TOC Schema.org markup to wp_head (separate from Article schema to avoid conflicts)
 */
function frp_add_toc_schema_markup() {
    global $frp_toc_schema;
    
    if (!empty($frp_toc_schema)) {
        echo '<script type="application/ld+json">' . json_encode($frp_toc_schema) . '</script>' . "\n";
    }
}
add_action('wp_head', 'frp_add_toc_schema_markup', 5); // Run early to avoid conflicts

/**
 * Generate tags for post
 */
function frp_generate_post_tags($content) {
    $api_key = get_option('frp_api_key');
    $model = get_option('frp_model', 'gpt-4o-mini');
    
    if (empty($api_key) || strlen($content) < 50) {
        return [];
    }
    
    $prompt = "Based on this content:\n\n" . substr($content, 0, 1000) . "\n\nGenerate 5 relevant tags. Format: tag1, tag2, tag3, tag4, tag5";
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 50,
            'temperature' => 0.5
        ]),
        'timeout' => 20
    ]);
    
    if (is_wp_error($response)) {
        return [];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['choices'][0]['message']['content'])) {
        $tag_text = $body['choices'][0]['message']['content'];
        return array_map('trim', explode(',', $tag_text));
    }
    
    return [];
}

/**
 * Set featured image for post
 */
function frp_set_post_thumbnail($post_id, $image_url) {
    if (empty($image_url)) {
        return;
    }
    
    // Validate URL
    $image_url = html_entity_decode(trim($image_url));
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        return;
    }
    
    // Download image
    $image_data = @file_get_contents($image_url, false, stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0'],
        'ssl' => ['verify_peer' => false]
    ]));
    
    if (!$image_data) {
        // Try curl
        if (function_exists('curl_init')) {
            $ch = curl_init($image_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $image_data = curl_exec($ch);
            curl_close($ch);
        }
    }
    
    if (!$image_data) {
        return;
    }
    
    $upload_dir = wp_upload_dir();
    $ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
    if (empty($ext)) {
        $ext = 'jpg';
    }
    
    $filename = sanitize_file_name("frp-" . uniqid() . "." . $ext);
    $file_path = $upload_dir['path'] . '/' . $filename;
    
    if (file_put_contents($file_path, $image_data)) {
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => get_the_title($post_id),
            'post_status' => 'inherit'
        ];
        
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        
        if ($attach_id) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $metadata = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $metadata);
            set_post_thumbnail($post_id, $attach_id);
        }
    }
}

// ==============================================================================
// SECTION 5: ADMIN MENU AND SETTINGS
// ==============================================================================

/**
 * Add admin menu
 */
function frp_add_admin_menu() {
    add_options_page(
        'Feed Rewriter',
        'Feed Rewriter',
        'manage_options',
        'frp_settings',
        'frp_render_settings_page'
    );
}
add_action('admin_menu', 'frp_add_admin_menu');

/**
 * Render settings page
 */
function frp_render_settings_page() {
    // Handle form submission
    if (isset($_POST['frp_run_now']) && check_admin_referer('frp_manual_run')) {
        delete_transient(FRP_TRANSIENT_PREFIX . 'cron_running');
        frp_log_message("=== MANUAL EXECUTION STARTED ===");
        frp_process_all_feeds();
        frp_log_message("=== MANUAL EXECUTION COMPLETED ===");
        echo '<div class="notice notice-success"><p>Manual execution completed.</p></div>';
    }
    
    if (isset($_POST['frp_clear_lock']) && check_admin_referer('frp_manual_run')) {
        delete_transient(FRP_TRANSIENT_PREFIX . 'cron_running');
        frp_log_message("Lock cleared manually");
        echo '<div class="notice notice-success"><p>Lock cleared.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Feed Rewriter Settings</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('frp_settings_group'); ?>
            <?php do_settings_sections('frp_settings'); ?>
            <?php submit_button('Save Settings'); ?>
        </form>
        
        <hr>
        
        <h2>Manual Execution</h2>
        <form method="post">
            <?php wp_nonce_field('frp_manual_run'); ?>
            <p>
                <button type="submit" name="frp_run_now" class="button button-primary">Run Now</button>
                <button type="submit" name="frp_clear_lock" class="button button-secondary" style="margin-left: 10px;">Clear Lock</button>
            </p>
        </form>
        
        <hr>
        
        <h2>Status</h2>
        <p><strong>Last cron run:</strong> <?php echo get_option('frp_last_cron_run', 'Never'); ?></p>
        
        <?php
        $next_run = wp_next_scheduled(FRP_CRON_HOOK);
        if ($next_run) {
            echo '<p><strong>Next scheduled:</strong> ' . date('Y-m-d H:i:s', $next_run) . '</p>';
        }
        ?>
        
        <hr>
        
        <h2>Recent Logs</h2>
        <?php echo frp_display_log(30); ?>
        
        <hr>
        
        <h2>Generated Articles (Last 20)</h2>
        <?php echo frp_display_generated_articles(); ?>
    </div>
    <?php
}

/**
 * Display recent log entries
 */
function frp_display_log($max_lines = 30) {
    if (!file_exists(FRP_LOG_FILE)) {
        return '<p>No logs yet.</p>';
    }
    
    $lines = file(FRP_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -$max_lines);
    $lines = array_reverse($lines);
    
    $output = '<div style="max-height: 400px; overflow-y: auto; background: #f5f5f5; padding: 15px; border: 1px solid #ddd; font-family: monospace; font-size: 11px;">';
    
    foreach ($lines as $line) {
        $color = '#333';
        if (strpos($line, 'SUCCESS') !== false) $color = '#28a745';
        elseif (strpos($line, 'ERROR') !== false) $color = '#dc3545';
        elseif (strpos($line, '=== ') !== false) $color = '#6f42c1';
        
        $output .= '<div style="color: ' . $color . '; margin-bottom: 2px;">' . esc_html($line) . '</div>';
    }
    
    $output .= '</div>';
    return $output;
}

/**
 * Display generated articles
 */
function frp_display_generated_articles() {
    $query = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => 20,
        'meta_key' => '_frp_generated',
        'meta_value' => '1',
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
    
    if (!$query->have_posts()) {
        return '<p>No generated articles yet.</p>';
    }
    
    $output = '<ul style="max-height: 300px; overflow-y: auto;">';
    while ($query->have_posts()) {
        $query->the_post();
        $output .= '<li><a href="' . get_permalink() . '" target="_blank">' . get_the_title() . '</a> (' . get_the_date() . ')</li>';
    }
    wp_reset_postdata();
    $output .= '</ul>';
    
    return $output;
}

// ==============================================================================
// SECTION 6: SETTINGS REGISTRATION
// ==============================================================================

/**
 * Register plugin settings
 */
function frp_register_settings() {
    // Main settings
    register_setting('frp_settings_group', 'frp_api_key');
    register_setting('frp_settings_group', 'frp_model');
    register_setting('frp_settings_group', 'frp_max_tokens');
    register_setting('frp_settings_group', 'frp_temperature');
    register_setting('frp_settings_group', 'frp_language');
    register_setting('frp_settings_group', 'frp_keyword_filter');
    register_setting('frp_settings_group', 'frp_exclude_keyword_filter');
    register_setting('frp_settings_group', 'frp_ignore_processed_urls');
    register_setting('frp_settings_group', 'frp_ignore_no_image');
    register_setting('frp_settings_group', 'frp_enable_toc');
    register_setting('frp_settings_group', 'frp_enable_tags');
    register_setting('frp_settings_group', 'frp_enable_enhanced_research');
    register_setting('frp_settings_group', 'frp_enhanced_max_sources');
    register_setting('frp_settings_group', 'frp_cron_status');
    register_setting('frp_settings_group', 'frp_cron_interval');
    
    // Feed configurations
    for ($i = 1; $i <= FRP_MAX_FEEDS; $i++) {
        register_setting('frp_settings_group', "frp_feed_url_{$i}");
        register_setting('frp_settings_group', "frp_selected_category_{$i}");
        register_setting('frp_settings_group', "frp_cron_interval_{$i}");
        register_setting('frp_settings_group', "frp_custom_prompt_{$i}");
    }
    
    // Settings sections
    add_settings_section('frp_main', 'Main Settings', null, 'frp_settings');
    add_settings_section('frp_feeds', 'Feed Configurations', null, 'frp_settings');
    
    // Main settings fields
    add_settings_field('frp_api_key', 'OpenAI API Key', 'frp_api_key_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_model', 'Model', 'frp_model_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_language', 'Language', 'frp_language_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_max_tokens', 'Max Tokens', 'frp_max_tokens_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_temperature', 'Temperature', 'frp_temperature_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_keyword_filter', 'Keyword Filter', 'frp_keyword_filter_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_exclude_keyword_filter', 'Exclude Keywords', 'frp_exclude_keyword_filter_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_ignore_processed_urls', 'Skip Processed URLs', 'frp_ignore_processed_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_enable_toc', 'Enable TOC', 'frp_enable_toc_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_enable_tags', 'Auto Generate Tags', 'frp_enable_tags_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_enable_enhanced_research', 'Enhanced Research Mode', 'frp_enhanced_research_field', 'frp_settings', 'frp_main');
    add_settings_field('frp_cron_status', 'Cron Status', 'frp_cron_status_field', 'frp_settings', 'frp_main');
    
    // Feed configuration fields
    for ($i = 1; $i <= FRP_MAX_FEEDS; $i++) {
        add_settings_field("frp_feed_{$i}", "Feed #{$i}", function() use ($i) { frp_feed_config_field($i); }, 'frp_settings', 'frp_feeds');
    }
}
add_action('admin_init', 'frp_register_settings');

// Settings field callbacks
function frp_api_key_field() {
    $val = get_option('frp_api_key', '');
    echo '<input type="password" name="frp_api_key" value="' . esc_attr($val) . '" class="regular-text" />';
    echo '<p class="description">Enter your OpenAI API key</p>';
}

function frp_model_field() {
    $val = get_option('frp_model', 'gpt-4o-mini');
    echo '<select name="frp_model">';
    // Most Economical
    echo '<option value="gpt-4o-mini" ' . selected($val, 'gpt-4o-mini', false) . '>GPT-4o Mini (Most Economical - $0.15/1M input)</option>';
    echo '<option value="gpt-4.1-nano" ' . selected($val, 'gpt-4.1-nano', false) . '>GPT-4.1 Nano - $0.10/1M input</option>';
    echo '<option value="gpt-3.5-turbo-0125" ' . selected($val, 'gpt-3.5-turbo-0125', false) . '>GPT-3.5 Turbo (0125) - $0.50/1M input</option>';
    // Mid-Range
    echo '<option value="gpt-4o" ' . selected($val, 'gpt-4o', false) . '>GPT-4o - $2.50/1M input</option>';
    echo '<option value="gpt-4o-2024-05-13" ' . selected($val, 'gpt-4o-2024-05-13', false) . '>GPT-4o (May 2024) - $2.50/1M input</option>';
    echo '<option value="gpt-4.1-mini" ' . selected($val, 'gpt-4.1-mini', false) . '>GPT-4.1 Mini - $0.80/1M input</option>';
    // Premium
    echo '<option value="gpt-4-turbo" ' . selected($val, 'gpt-4-turbo', false) . '>GPT-4 Turbo - $10/1M input</option>';
    echo '<option value="gpt-4" ' . selected($val, 'gpt-4', false) . '>GPT-4 - $30/1M input</option>';
    // Latest Models
    echo '<option value="gpt-4.1" ' . selected($val, 'gpt-4.1', false) . '>GPT-4.1 - $10/1M input</option>';
    echo '<option value="gpt-4.5-preview" ' . selected($val, 'gpt-4.5-preview', false) . '>GPT-4.5 Preview - $75/1M input</option>';
    // GPT-5 Series
    echo '<option value="gpt-5-mini" ' . selected($val, 'gpt-5-mini', false) . '>GPT-5 Mini - $0.15/1M input</option>';
    echo '<option value="gpt-5" ' . selected($val, 'gpt-5', false) . '>GPT-5 - $2.00/1M input</option>';
    echo '</select>';
    echo '<p class="description">Pilih model sesuai budget. GPT-4o Mini sangat ekonomis dan cocok untuk rewrite artikel.</p>';
}

function frp_language_field() {
    $val = get_option('frp_language', 'en');
    echo '<select name="frp_language">';
    echo '<option value="en" ' . selected($val, 'en', false) . '>English</option>';
    echo '<option value="id" ' . selected($val, 'id', false) . '>Bahasa Indonesia</option>';
    echo '</select>';
}

function frp_max_tokens_field() {
    $val = get_option('frp_max_tokens', 500);
    echo '<input type="number" name="frp_max_tokens" value="' . esc_attr($val) . '" min="0" />';
    echo '<p class="description">0 for unlimited</p>';
}

function frp_temperature_field() {
    $val = get_option('frp_temperature', 0.5);
    echo '<input type="number" name="frp_temperature" value="' . esc_attr($val) . '" min="0" max="2" step="0.1" />';
}

function frp_keyword_filter_field() {
    $val = get_option('frp_keyword_filter', '');
    echo '<textarea name="frp_keyword_filter" rows="3" class="regular-text">' . esc_textarea($val) . '</textarea>';
    echo '<p class="description">One keyword per line. Articles must contain at least one.</p>';
}

function frp_exclude_keyword_filter_field() {
    $val = get_option('frp_exclude_keyword_filter', '');
    echo '<input type="text" name="frp_exclude_keyword_filter" value="' . esc_attr($val) . '" class="regular-text" />';
    echo '<p class="description">Comma-separated. Articles with these words will be skipped.</p>';
}

function frp_ignore_processed_field() {
    $val = get_option('frp_ignore_processed_urls', '1');
    echo '<input type="checkbox" name="frp_ignore_processed_urls" value="1" ' . checked($val, '1', false) . ' /> Skip already processed URLs';
}

function frp_enable_toc_field() {
    $val = get_option('frp_enable_toc', 'yes');
    echo '<input type="checkbox" name="frp_enable_toc" value="yes" ' . checked($val, 'yes', false) . ' /> Generate Table of Contents';
}

function frp_enable_tags_field() {
    $val = get_option('frp_enable_tags', '1');
    echo '<input type="checkbox" name="frp_enable_tags" value="1" ' . checked($val, '1', false) . ' /> Auto generate tags using AI (saves API credits if disabled)';
}

function frp_enhanced_research_field() {
    $val = get_option('frp_enable_enhanced_research', '1');
    $max_sources = get_option('frp_enhanced_max_sources', 3);
    echo '<input type="checkbox" name="frp_enable_enhanced_research" value="1" ' . checked($val, '1', false) . ' /> Enable Enhanced Research Mode';
    echo '<p class="description">When enabled, plugin will collect additional content from related links in the article to create more comprehensive posts. More API calls but better content quality.</p>';
    echo '<p><label>Max additional sources:</label> ';
    echo '<input type="number" name="frp_enhanced_max_sources" value="' . esc_attr($max_sources) . '" min="1" max="5" style="width: 60px;" /> ';
    echo '(1-5 sources, more = higher API cost)</p>';
}

function frp_cron_status_field() {
    $val = get_option('frp_cron_status', 'active');
    echo '<select name="frp_cron_status">';
    echo '<option value="active" ' . selected($val, 'active', false) . '>Active</option>';
    echo '<option value="paused" ' . selected($val, 'paused', false) . '>Paused</option>';
    echo '</select>';
}

function frp_feed_config_field($num) {
    $url = get_option("frp_feed_url_{$num}", '');
    $cat = get_option("frp_selected_category_{$num}", '');
    $interval = get_option("frp_cron_interval_{$num}", 60);
    $prompt = get_option("frp_custom_prompt_{$num}", 'Rewrite the following content:');
    
    $categories = get_categories(['hide_empty' => false]);
    
    echo '<div style="background: #f9f9f9; padding: 15px; margin: 10px 0; border: 1px solid #ddd;">';
    echo '<p><label>Feed URL:</label><br><textarea name="frp_feed_url_' . $num . '" rows="2" class="regular-text">' . esc_textarea($url) . '</textarea></p>';
    echo '<p><label>Category:</label><br><select name="frp_selected_category_' . $num . '"><option value="">Select</option>';
    foreach ($categories as $c) {
        echo '<option value="' . $c->term_id . '" ' . selected($cat, $c->term_id, false) . '>' . esc_html($c->name) . '</option>';
    }
    echo '</select></p>';
    echo '<p><label>Interval (minutes):</label><br><input type="number" name="frp_cron_interval_' . $num . '" value="' . esc_attr($interval) . '" min="1" style="width: 80px;" /></p>';
    echo '<p><label>Custom Prompt:</label><br><textarea name="frp_custom_prompt_' . $num . '" rows="2" class="regular-text">' . esc_textarea($prompt) . '</textarea></p>';
    echo '</div>';
}

// ==============================================================================
// SECTION 7: AJAX HANDLERS
// ==============================================================================

add_action('wp_ajax_frp_get_logs', 'frp_ajax_get_logs');
function frp_ajax_get_logs() {
    check_ajax_referer('frp_ajax_nonce', '_wpnonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    wp_send_json_success(['log' => frp_display_log(20)]);
}

// ==============================================================================
// SECTION 8: SEO OPTIMIZATION FUNCTIONS
// ==============================================================================

/**
 * Add SEO meta tags to post
 */
function frp_add_seo_meta($post_id, $title, $content, $image_url = '') {
    // Generate excerpt
    $excerpt = wp_strip_all_tags(strip_shortcodes($content));
    $excerpt = wp_trim_words($excerpt, 30, '');
    
    // Update post excerpt
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $excerpt);
    
    // Set focus keyword from title
    $focus_keyword = sanitize_title($title);
    update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
    
    // Open Graph meta tags
    update_post_meta($post_id, '_og_title', $title);
    update_post_meta($post_id, '_og_description', $excerpt);
    if ($image_url) {
        update_post_meta($post_id, '_og_image', $image_url);
    }
    
    // Twitter Card meta
    update_post_meta($post_id, '_twitter_card_type', 'summary_large_image');
    
    frp_log_message("  -> SEO meta tags added");
}

/**
 * Add Schema.org structured data (Article/NewsArticle)
 */
function frp_add_schema_markup($post_id, $title, $content, $image_url = '') {
    $post = get_post($post_id);
    $author = get_the_author_meta('display_name', $post->post_author);
    $site_name = get_bloginfo('name');
    
    // Get primary category
    $categories = get_the_category($post_id);
    $primary_category = !empty($categories) ? $categories[0]->name : 'Article';
    
    // Build schema data
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $title,
        'image' => $image_url ? [$image_url] : [],
        'datePublished' => get_the_date('c', $post_id),
        'dateModified' => get_the_modified_date('c', $post_id),
        'author' => [
            '@type' => 'Person',
            'name' => $author
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $site_name,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => get_site_icon_url()
            ]
        ],
        'description' => wp_trim_words(strip_tags($content), 30, ''),
        'articleSection' => $primary_category
    ];
    
    // Add JSON-LD to post meta
    $schema_json = json_encode($schema);
    update_post_meta($post_id, '_frp_schema_markup', $schema_json);
    
    // Add schema to wp_head
    add_action('wp_head', function() use ($post_id, $schema_json) {
        echo '<script type="application/ld+json">' . $schema_json . '</script>' . "\n";
    });
    
    frp_log_message("  -> Schema.org markup added");
}

// ==============================================================================
// SECTION 9: UTILITY FUNCTIONS
// ==============================================================================

/**
 * Enhanced logging function with rotation
 */
function frp_log_message($message) {
    static $last_message = '';
    static $last_time = 0;
    
    // Prevent duplicate messages within 30 seconds
    if ($message === $last_message && (time() - $last_time) < 30) {
        return;
    }
    
    $last_message = $message;
    $last_time = time();
    
    // Check log file size - rotate if too large
    if (file_exists(FRP_LOG_FILE) && filesize(FRP_LOG_FILE) > FRP_MAX_LOG_SIZE) {
        // Keep only last 50% of log
        $lines = file(FRP_LOG_FILE);
        $keep = array_slice($lines, -floor(count($lines) / 2));
        file_put_contents(FRP_LOG_FILE, implode('', $keep));
    }
    
    $timestamp = current_time('mysql');
    $source = defined('DOING_CRON') && DOING_CRON ? '[CRON]' : '[MANUAL]';
    $entry = "[{$timestamp}] {$source} {$message}\n";
    
    file_put_contents(FRP_LOG_FILE, $entry, FILE_APPEND);
}

/**
 * Reschedule cron when settings change
 */
function frp_on_settings_change() {
    if (get_option('frp_cron_status', 'active') === 'active') {
        frp_schedule_main_cron();
    } else {
        wp_clear_scheduled_hook(FRP_CRON_HOOK);
    }
}
add_action('update_option_frp_cron_interval', 'frp_on_settings_change');
add_action('update_option_frp_cron_status', 'frp_on_settings_change');
add_action('update_option_frp_feed_url_1', 'frp_on_settings_change');
add_action('update_option_frp_feed_url_2', 'frp_on_settings_change');
add_action('update_option_frp_feed_url_3', 'frp_on_settings_change');
add_action('update_option_frp_feed_url_4', 'frp_on_settings_change');
add_action('update_option_frp_feed_url_5', 'frp_on_settings_change');

// ==============================================================================
// SECTION 9: ADMIN NOTICES & SYSTEM CHECK
// ==============================================================================

/**
 * Check WordPress timeout and server settings
 */
function frp_check_system_requirements() {
    $issues = [];
    $recommendations = [];
    
    // Check PHP timeout
    $php_timeout = ini_get('max_execution_time');
    if ($php_timeout > 0 && $php_timeout < 60) {
        $issues[] = "PHP max_execution_time is only {$php_timeout} seconds. Recommended: 60+ seconds for article generation.";
    }
    
    // Check WordPress timeout
    $wp_timeout = defined('WP_HTTP_REQUEST_TIMEOUT') ? WP_HTTP_REQUEST_TIMEOUT : 5;
    if ($wp_timeout < 30) {
        $issues[] = "WordPress HTTP request timeout is {$wp_timeout} seconds. Recommended: 30+ seconds for API calls.";
    }
    
    // Check memory limit
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
    if ($memory_bytes < 64 * 1024 * 1024) { // Less than 64MB
        $issues[] = "PHP memory_limit is {$memory_limit}. Recommended: 256MB for optimal performance.";
    }
    
    // Check if cron is working
    if (!wp_next_scheduled(FRP_CRON_HOOK)) {
        $issues[] = "Cron job is not scheduled. Please save settings to fix.";
    }
    
    // Recommendations
    if (get_option('frp_enable_enhanced_research', '1') === '1') {
        $recommendations[] = "Enhanced Research Mode is ON - requires more processing time. Keep cron interval at 30+ minutes.";
    }
    
    $max_tokens = get_option('frp_max_tokens', 1500);
    if ($max_tokens > 2000) {
        $recommendations[] = "High max_tokens ({$max_tokens}) may take longer to generate. Consider using GPT-4o Mini for faster results.";
    }
    
    return [
        'issues' => $issues,
        'recommendations' => $recommendations
    ];
}

/**
 * Show admin notices for configuration warnings
 */
function frp_admin_notices() {
    // Only show on plugin settings page
    if (!isset($_GET['page']) || $_GET['page'] !== 'frp_settings') {
        return;
    }
    
    // Check API key
    if (empty(get_option('frp_api_key'))) {
        echo '<div class="notice notice-warning"><p><strong>Feed Rewriter:</strong> Please configure your OpenAI API key to start generating articles.</p></div>';
    }
    
    // Check if feeds are configured
    $has_feeds = false;
    for ($i = 1; $i <= FRP_MAX_FEEDS; $i++) {
        if (!empty(get_option("frp_feed_url_{$i}"))) {
            $has_feeds = true;
            break;
        }
    }
    
    if (!$has_feeds) {
        echo '<div class="notice notice-info"><p><strong>Feed Rewriter:</strong> No feed URLs configured. Add at least one feed URL below to start.</p></div>';
    }
    
    // Check cron status
    if (get_option('frp_cron_status') === 'paused') {
        echo '<div class="notice notice-warning"><p><strong>Feed Rewriter:</strong> Cron is currently paused. Click "Run Now" for manual execution.</p></div>';
    }
    
    // System requirements check
    $system_check = frp_check_system_requirements();
    
    // Show issues as errors
    if (!empty($system_check['issues'])) {
        echo '<div class="notice notice-error"><p><strong>⚠️ System Requirements Warning:</strong></p><ul>';
        foreach ($system_check['issues'] as $issue) {
            echo '<li>' . esc_html($issue) . '</li>';
        }
        echo '</ul></div>';
    }
    
    // Show recommendations as warnings
    if (!empty($system_check['recommendations'])) {
        echo '<div class="notice notice-info"><p><strong>💡 Recommendations:</strong></p><ul>';
        foreach ($system_check['recommendations'] as $rec) {
            echo '<li>' . esc_html($rec) . '</li>';
        }
        echo '</ul></div>';
    }
    
    // Show success notice if everything is fine
    if (empty($system_check['issues']) && $has_feeds && !empty(get_option('frp_api_key'))) {
        echo '<div class="notice notice-success"><p><strong>✅ Feed Rewriter:</strong> System is ready! All requirements met.</p></div>';
    }
}
add_action('admin_notices', 'frp_admin_notices');

// ==============================================================================
// SECTION 10: WORDPRESS HOOK INITIALIZATION
// ==============================================================================

/**
 * Initialize plugin on WordPress init
 */
function frp_init() {
    // cron_schedules filter is already registered at file load
    // This function can be used for future initialization if needed
}
add_action('init', 'frp_init', 1);
