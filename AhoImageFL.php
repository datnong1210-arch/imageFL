<?php
/**
 * Plugin Name: AhoVNimageFlashcard
 * Description: Plugin học Flashcard bằng hình ảnh với giao diện thân thiện cho trẻ em, có hệ thống thưởng sao và nhiều hiệu ứng sinh động. Hoạt động độc lập với AhoFLASHCARD.
 * Version: 26.7.0
 * Author: AhoVN & Copilot (UI/UX Refinements, Feature Expansion)
 */

if (!defined('ABSPATH')) exit;

class ImageFlashcardLearning {
    private $table_name;
    private $option_name = 'ifc_options';
    private $lang_option_name = 'ifc_language_packs';
    private $version = '26.7.0';
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'image_flashcards';
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_init', array($this, 'check_and_create_files'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'front_scripts'));
        add_shortcode('image_flashcard', array($this, 'shortcode'));
        
        // AJAX Actions
        add_action('wp_ajax_ifc_get_sets', array($this, 'ajax_get_sets'));
        add_action('wp_ajax_ifc_get_set_details', array($this, 'ajax_get_set_details'));
        add_action('wp_ajax_ifc_save_set', array($this, 'ajax_save_set'));
        add_action('wp_ajax_ifc_update_set_names', array($this, 'ajax_update_set_names'));
        add_action('wp_ajax_ifc_create_set', array($this, 'ajax_create_set'));
        add_action('wp_ajax_ifc_delete_multiple_sets', array($this, 'ajax_delete_multiple_sets'));
        add_action('wp_ajax_ifc_save_options', array($this, 'ajax_save_options'));
        // Language AJAX Actions
        add_action('wp_ajax_ifc_get_lang_packs', array($this, 'ajax_get_lang_packs'));
        add_action('wp_ajax_ifc_save_lang_pack', array($this, 'ajax_save_lang_pack'));
        add_action('wp_ajax_ifc_delete_lang_pack', array($this, 'ajax_delete_lang_pack'));
    }
    
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            cards longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        add_option($this->option_name, ['correct_audio' => '', 'wrong_audio' => '']);
        
        // Default language pack
        $lang_packs = get_option($this->lang_option_name, []);
        $lang_packs['vi'] = [ // Always ensure 'vi' pack is present and correct
            'langName' => 'Tiếng Việt',
            'texts' => $this->get_default_lang_texts()
        ];
        update_option($this->lang_option_name, $lang_packs);

        $this->create_plugin_files();
        update_option('ifc_version', $this->version);
    }

    private function get_default_lang_texts() {
        return [
            'cardCounter' => 'Thẻ',
            'shuffleOrder' => 'Đảo',
            'prevCard' => 'Trước',
            'flipCard' => 'Lật',
            'nextCard' => 'Sau',
            'playQuiz' => 'Chơi Quiz',
            'questionCounter' => 'Câu',
            'exit' => 'Thoát',
            'filter' => 'Ẩn',
            'showBack1' => 'Hiện mặt sau 1',
            'showBack2' => 'Hiện mặt sau 2',
            'showBack3' => 'Hiện mặt sau 3',
            'showBack4' => 'Hiện mặt sau 4',
            'showBackOnFront' => 'Hiện mặt sau',
            'nextQuestion' => 'Câu tiếp',
            'quizComplete' => 'Hoàn thành Quiz!',
            'correct' => 'ĐÚNG',
            'wrong' => 'SAI',
            'totalStars' => 'Tổng số sao',
            'retryQuiz' => 'Làm lại Quiz',
            'backToStudy' => 'Quay lại học',
            'notEnoughCards' => 'Cần ít nhất 4 thẻ có nội dung mặt sau để chơi Quiz.'
        ];
    }

    public function check_and_create_files() {
        $current_version = get_option('ifc_version');
        if (version_compare($current_version, $this->version, '<')) {
            $this->create_plugin_files();
            // On update, re-assert the default Vietnamese pack
            $lang_packs = get_option($this->lang_option_name, []);
            $lang_packs['vi']['texts'] = $this->get_default_lang_texts(); // Update with new keys
            update_option($this->lang_option_name, $lang_packs);
            update_option('ifc_version', $this->version);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page('AhoVNimageFlashcard', 'AhoVNimageFlashcard', 'manage_options', 'ifc-learning', array($this, 'admin_page'), 'dashicons-format-gallery', 31);
    }
    
    public function admin_scripts($hook) {
        if ($hook != 'toplevel_page_ifc-learning') return;
        
        wp_enqueue_media();
        wp_enqueue_style('handsontable-css', 'https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.css', array(), '14.0.0');
        wp_enqueue_script('handsontable-js', 'https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.js', array(), '14.0.0', true);
        wp_enqueue_script('vue-js', 'https://cdn.jsdelivr.net/npm/vue@2.7.14/dist/vue.min.js', array(), '2.7.14', true);

        wp_enqueue_script('ifc-admin-js', plugins_url('ifc-admin.js', __FILE__), array('jquery', 'handsontable-js', 'vue-js'), $this->version, true);
        
        $options = get_option($this->option_name, ['correct_audio' => '', 'wrong_audio' => '']);
        
        wp_localize_script('ifc-admin-js', 'ifcData', [
            'ajax' => admin_url('admin-ajax.php'), 
            'nonce' => wp_create_nonce('ifc_nonce'),
            'options' => $options,
            'default_lang_keys' => array_keys($this->get_default_lang_texts())
        ]);
        wp_enqueue_style('ifc-admin-css', plugins_url('ifc-admin.css', __FILE__), array(), $this->version);
    }
    
    public function front_scripts() {
        global $post;
        if (!is_singular() || !$post || !has_shortcode($post->post_content, 'image_flashcard')) return;
        
        wp_enqueue_script('ifc-front-js', plugins_url('ifc-front.js', __FILE__), array(), $this->version, true);
        
        $options = get_option($this->option_name, ['correct_audio' => '', 'wrong_audio' => '']);
        $lang_packs = get_option($this->lang_option_name, []);

        wp_localize_script('ifc-front-js', 'ifcFrontData', [
            'ajax' => admin_url('admin-ajax.php'), 
            'nonce' => wp_create_nonce('ifc_nonce'),
            'options' => $options,
            'langPacks' => $lang_packs
        ]);
        wp_enqueue_style('ifc-front-css', plugins_url('ifc-front.css', __FILE__), array(), $this->version);
    }
    
    public function admin_page() { include_once(__DIR__ . '/ifc-admin.php'); }
    
    public function ajax_get_sets() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        global $wpdb;
        $items = $wpdb->get_results("SELECT id, name, cards, created_at FROM {$this->table_name} ORDER BY created_at DESC");
        foreach ($items as $item) {
            $cards_data = json_decode($item->cards);
            $item->card_count = is_array($cards_data) ? count($cards_data) : 0;
            unset($item->cards);
        }
        wp_send_json_success($items);
    }

    public function ajax_get_set_details() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) { wp_send_json_error('ID bộ thẻ không hợp lệ.'); }
        global $wpdb;
        $set = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
        if (!$set) { wp_send_json_error('Không tìm thấy bộ thẻ.'); }
        $set->cards = json_decode($set->cards);
        wp_send_json_success($set);
    }

    public function ajax_save_set() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $cards = isset($_POST['cards']) ? json_decode(stripslashes($_POST['cards']), true) : [];
        if (empty($name) || $id <= 0) wp_send_json_error('Dữ liệu không hợp lệ.');
        
        $sanitized_cards = array_map(
            function($card) {
                return [
                    'front' => isset($card['front']) ? sanitize_textarea_field($card['front']) : '',
                    'back1' => isset($card['back1']) ? sanitize_textarea_field($card['back1']) : '',
                    'back1_audio' => isset($card['back1_audio']) ? esc_url_raw($card['back1_audio']) : '',
                    'back2' => isset($card['back2']) ? sanitize_textarea_field($card['back2']) : '',
                    'back2_audio' => isset($card['back2_audio']) ? esc_url_raw($card['back2_audio']) : '',
                    'back3' => isset($card['back3']) ? sanitize_textarea_field($card['back3']) : '',
                    'back3_audio' => isset($card['back3_audio']) ? esc_url_raw($card['back3_audio']) : '',
                    'back4' => isset($card['back4']) ? sanitize_textarea_field($card['back4']) : '',
                    'back4_audio' => isset($card['back4_audio']) ? esc_url_raw($card['back4_audio']) : '',
                ];
            }, 
            $cards
        );

        global $wpdb;
        $wpdb->update($this->table_name, ['name' => $name, 'cards' => wp_json_encode($sanitized_cards, JSON_UNESCAPED_UNICODE)], ['id' => $id]);
        
        wp_send_json_success(['message' => 'Đã lưu thành công bộ thẻ: ' . $name]);
    }

    public function ajax_save_options() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        
        $options = get_option($this->option_name, []);
        $options['correct_audio'] = isset($_POST['correct_audio']) ? esc_url_raw($_POST['correct_audio']) : '';
        $options['wrong_audio'] = isset($_POST['wrong_audio']) ? esc_url_raw($_POST['wrong_audio']) : '';

        update_option($this->option_name, $options);
        wp_send_json_success('Đã lưu tùy chọn âm thanh.');
    }

    public function ajax_update_set_names() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $sets_to_update = isset($_POST['sets']) ? json_decode(stripslashes($_POST['sets']), true) : [];
        if (empty($sets_to_update)) wp_send_json_error('Không có dữ liệu để cập nhật.');
        global $wpdb;
        $updated_count = 0;
        foreach ($sets_to_update as $set) {
            $id = intval($set['id']);
            $name = sanitize_text_field($set['name']);
            if ($id > 0 && !empty($name)) {
                if ($wpdb->update($this->table_name, ['name' => $name], ['id' => $id])) {
                    $updated_count++;
                }
            }
        }
        wp_send_json_success(['message' => "Đã cập nhật tên cho {$updated_count} bộ thẻ."]);
    }

    public function ajax_create_set() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : 'Bộ thẻ mới';
        if (empty($name)) wp_send_json_error('Tên không được trống');
        $default_card = [];
        global $wpdb;
        $wpdb->insert($this->table_name, ['name' => $name, 'cards' => wp_json_encode($default_card, JSON_UNESCAPED_UNICODE)]);
        $id = $wpdb->insert_id;
        if (!$id) wp_send_json_error('Tạo bộ thẻ mới thất bại.');
        $new_set = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
        $new_set->cards = json_decode($new_set->cards);
        wp_send_json_success($new_set);
    }

    public function ajax_delete_multiple_sets() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        if (empty($ids)) wp_send_json_error('Không có bộ thẻ nào được chọn.');
        global $wpdb;
        $ids_format = implode(',', array_fill(0, count($ids), '%d'));
        $query = $wpdb->prepare("DELETE FROM {$this->table_name} WHERE id IN ($ids_format)", ...$ids);
        $result = $wpdb->query($query);
        if ($result === false) wp_send_json_error('Xóa thất bại.');
        wp_send_json_success(['message' => 'Đã xóa ' . $result . ' bộ thẻ.']);
    }

    public function ajax_get_lang_packs() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $lang_packs = get_option($this->lang_option_name, []);
        wp_send_json_success($lang_packs);
    }

    public function ajax_save_lang_pack() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $pack_code = isset($_POST['code']) ? sanitize_key($_POST['code']) : '';
        $pack_data = isset($_POST['pack']) ? json_decode(stripslashes($_POST['pack']), true) : null;

        if ($pack_code === 'vi') {
            wp_send_json_error('Không thể chỉnh sửa gói ngôn ngữ mặc định.');
        }

        if (empty($pack_code) || !is_array($pack_data) || empty($pack_data['langName']) || !is_array($pack_data['texts'])) {
            wp_send_json_error('Dữ liệu gói ngôn ngữ không hợp lệ.');
        }

        $lang_packs = get_option($this->lang_option_name, []);
        
        $sanitized_texts = [];
        foreach($this->get_default_lang_texts() as $key => $default_val) {
            $sanitized_texts[$key] = isset($pack_data['texts'][$key]) ? sanitize_text_field($pack_data['texts'][$key]) : '';
        }

        $lang_packs[$pack_code] = [
            'langName' => sanitize_text_field($pack_data['langName']),
            'texts' => $sanitized_texts
        ];

        update_option($this->lang_option_name, $lang_packs);
        wp_send_json_success(['message' => 'Đã lưu gói ngôn ngữ.']);
    }

    public function ajax_delete_lang_pack() {
        check_ajax_referer('ifc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die();
        $pack_code = isset($_POST['code']) ? sanitize_key($_POST['code']) : '';
        if (empty($pack_code)) wp_send_json_error('Mã ngôn ngữ không hợp lệ.');
        
        if ($pack_code === 'vi') {
            wp_send_json_error('Không thể xóa gói ngôn ngữ mặc định.');
        }

        $lang_packs = get_option($this->lang_option_name, []);
        if (isset($lang_packs[$pack_code])) {
            unset($lang_packs[$pack_code]);
            update_option($this->lang_option_name, $lang_packs);
            wp_send_json_success(['message' => 'Đã xóa gói ngôn ngữ.']);
        } else {
            wp_send_json_error('Không tìm thấy gói ngôn ngữ để xóa.');
        }
    }
    
    public function shortcode($atts) {
        $id = isset($atts['id']) ? intval($atts['id']) : 0;
        if (!$id) return '';
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare("SELECT id, name, cards FROM {$this->table_name} WHERE id = %d", $id));
        if (!$item) return '<!-- Image Flashcard set not found -->';
        
        $cards = json_decode($item->cards, true);
        
        if (!is_array($cards)) { return '<!-- Invalid card data -->'; }

        $cards = array_values(array_filter($cards, function($card) {
            return is_array($card) && !empty(array_filter($card));
        }));

        if (empty($cards)) return '<!-- Image Flashcard set is empty -->';
        
        ob_start(); 
        include(__DIR__ . '/ifc-front.php'); 
        return ob_get_clean();
    }

    private function create_plugin_files() {
        $files_content = [
            'ifc-admin.php' => <<<'EOD'
<?php if (!defined("ABSPATH")) exit; ?>
<div class="wrap" id="ifc-app">
    <!-- Main View -->
    <div v-show="view === 'main'">
        <div class="ifc-header">
            <h1>Quản lý bộ thẻ (Hình ảnh)</h1>
            <div class="ifc-actions">
                <button class="button" @click="view = 'languages'">Quản lý ngôn ngữ</button>
                <button class="button" @click="view = 'options'">Tùy chọn âm thanh</button>
                <button class="button button-primary" @click="createSetAndEdit">Tạo bộ thẻ mới</button>
                <button class="button" v-if="isSetsDirty" @click="saveSetNames">Lưu thay đổi tên</button>
            </div>
        </div>
        <div class="ifc-bulk-actions">
             <div class="ifc-bulk-group">
                <input type="search" v-model="searchQuery" placeholder="Tìm tên bộ thẻ..." @input="handleSearch">
            </div>
            <div class="ifc-bulk-group">
                <input type="number" v-model.number="setsToCreate" min="1" placeholder="Số lượng">
                <button class="button" @click="createMultipleSets">Tạo nhiều bộ</button>
            </div>
            <div class="ifc-bulk-group" v-if="selectedSets.length > 0">
                <button class="button" @click="copySelectedShortcodes">Sao chép Shortcode ({{ selectedSets.length }})</button>
                <button class="button button-danger" @click="deleteSelectedSets">Xóa các bộ đã chọn ({{ selectedSets.length }})</button>
            </div>
             <div class="ifc-bulk-group" v-if="selectedSets.length === 1">
                <button class="button button-primary" @click="editSet(selectedSets[0])">Chỉnh sửa thẻ</button>
            </div>
        </div>
        <div id="ifc-sets-handsontable"></div>
        <div class="ifc-pagination" v-if="!isLoading && sets.length > 0">
             <label>Hiển thị: 
                <select v-model.number="pagination.pageSize" @change="handlePageSizeChange">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
            <button @click="prevPage" :disabled="pagination.currentPage === 1">Trang trước</button>
            <span>Trang {{ pagination.currentPage }} / {{ pagination.totalPages }}</span>
            <button @click="nextPage" :disabled="pagination.currentPage === pagination.totalPages">Trang sau</button>
        </div>
    </div>

    <!-- Card Editor View -->
    <div v-show="view === 'editor'">
        <div class="ifc-header">
            <h1>Chỉnh sửa: {{ currentSet.name }}</h1>
            <div class="ifc-actions">
                 <button class="button" @click="backToList">&larr; Quay lại danh sách</button>
                 <button class="button button-primary" v-if="isCardsDirty" @click="saveSet">Lưu thay đổi</button>
            </div>
        </div>
        <div class="ifc-editor">
            <div class="ifc-editor-header">
                <input type="text" v-model="currentSet.name" class="ifc-set-name-input" @input="isCardsDirty = true">
                <div class="ifc-editor-actions">
                    <span>Shortcode: <code>[image_flashcard id="{{ currentSet.id }}"]</code></span>
                </div>
            </div>
            <div id="ifc-cards-handsontable"></div>
        </div>
    </div>

    <!-- Options View -->
    <div v-show="view === 'options'">
        <div class="ifc-header">
            <h1>Tùy chọn âm thanh Quiz</h1>
             <button class="button" @click="view = 'main'">&larr; Quay lại danh sách</button>
        </div>
        <div class="form-table">
            <div class="form-field">
                <label for="ifc_correct_audio">Âm thanh khi trả lời đúng</label>
                <div class="ifc-media-field">
                    <input type="text" id="ifc_correct_audio" v-model="options.correct_audio">
                    <button class="button" @click="openMediaUploader('correct_audio')">Chọn file</button>
                </div>
                <p class="description">URL đến file âm thanh sẽ phát khi người dùng chọn đúng đáp án trong quiz.</p>
            </div>
            <div class="form-field">
                <label for="ifc_wrong_audio">Âm thanh khi trả lời sai</label>
                 <div class="ifc-media-field">
                    <input type="text" id="ifc_wrong_audio" v-model="options.wrong_audio">
                    <button class="button" @click="openMediaUploader('wrong_audio')">Chọn file</button>
                </div>
                <p class="description">URL đến file âm thanh sẽ phát khi người dùng chọn sai đáp án trong quiz.</p>
            </div>
        </div>
        <p class="submit">
            <button class="button button-primary" @click="saveOptions">Lưu thay đổi</button>
        </p>
    </div>

    <!-- Language Packs View -->
    <div v-show="view === 'languages'">
        <div class="ifc-header">
            <h1>Quản lý ngôn ngữ</h1>
            <button class="button" @click="view = 'main'">&larr; Quay lại danh sách</button>
        </div>
        <div class="ifc-lang-manager">
            <div class="ifc-lang-list">
                <h3>Các gói ngôn ngữ</h3>
                <ul>
                    <li v-for="(pack, code) in langPacks" :key="code" :class="{ active: currentLangPackCode === code }" @click="editLangPack(code)">
                        {{ pack.langName }}
                        <span v-if="code === 'vi'" class="default-chip">Mặc định</span>
                        <button class="button-link-delete" @click.stop="deleteLangPack(code)" v-if="code !== 'vi'">&times;</button>
                    </li>
                </ul>
                <button class="button" @click="addNewLangPack">Thêm ngôn ngữ mới</button>
            </div>
            <div class="ifc-lang-editor" v-if="currentLangPack">
                <div v-if="currentLangPackCode !== 'vi'">
                    <h3>Chỉnh sửa: {{ currentLangPack.langName }} (<code>{{ currentLangPackCode }}</code>)</h3>
                    <div class="form-field">
                        <label>Tên hiển thị</label>
                        <input type="text" v-model="currentLangPack.langName">
                    </div>
                    <hr>
                    <h4>Các chuỗi văn bản</h4>
                    <div class="form-field" v-for="(text, key) in currentLangPack.texts" :key="key">
                        <label :for="'lang-text-' + key"><code>{{ key }}</code></label>
                        <input type="text" :id="'lang-text-' + key" v-model="currentLangPack.texts[key]">
                    </div>
                    <button class="button button-primary" @click="saveLangPack">Lưu gói ngôn ngữ</button>
                </div>
                <div v-else>
                    <h3>{{ currentLangPack.langName }}</h3>
                    <p>Đây là gói ngôn ngữ mặc định và không thể chỉnh sửa. Bạn có thể dùng các chuỗi dưới đây làm tham chiếu để dịch sang ngôn ngữ mới.</p>
                    <ul class="ifc-default-lang-list">
                        <li v-for="(text, key) in currentLangPack.texts" :key="key">
                           <code>{{ key }}</code>: <span>{{ text }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>


    <div id="ifc-loading-overlay" v-if="isLoading"><div class="spinner is-active"></div><p>{{ loadingMessage }}</p></div>
</div>
EOD
,
            'ifc-admin.js' => <<<'EOD'
document.addEventListener("DOMContentLoaded", function () {
    if (!document.getElementById("ifc-app")) return;

    new Vue({
        el: "#ifc-app",
        data: {
            view: 'main', // 'main', 'editor', 'options', 'languages'
            sets: [],
            setsHot: null,
            isSetsDirty: false,
            selectedSets: [],
            currentSetId: null,
            currentSet: { name: '' },
            cardsHot: null,
            isCardsDirty: false,
            isLoading: true,
            loadingMessage: "Đang tải dữ liệu...",
            setsToCreate: 1,
            searchQuery: '',
            pagination: {
                currentPage: 1,
                pageSize: 10,
                totalPages: 1,
                totalItems: 0,
            },
            options: {
                correct_audio: ifcData.options.correct_audio || '',
                wrong_audio: ifcData.options.wrong_audio || ''
            },
            langPacks: {},
            currentLangPack: null,
            currentLangPackCode: '',
        },
        watch: {
            view(newView) {
                if (newView === 'languages') {
                    this.fetchLangPacks();
                }
            }
        },
        computed: {
            filteredSets() {
                if (!this.searchQuery) return this.sets;
                const lowerCaseQuery = this.searchQuery.toLowerCase();
                return this.sets.filter(set => set.name && set.name.toLowerCase().includes(lowerCaseQuery));
            },
            paginatedSets() {
                const start = (this.pagination.currentPage - 1) * this.pagination.pageSize;
                const end = start + this.pagination.pageSize;
                return this.filteredSets.slice(start, end);
            }
        },
        mounted() {
            this.fetchSets();
        },
        methods: {
            showLoader(message) { this.loadingMessage = message; this.isLoading = true; },
            hideLoader() { this.isLoading = false; },
            editSet(setId) {
                this.showLoader('Đang tải dữ liệu bộ thẻ...');
                jQuery.post(ifcData.ajax, { action: "ifc_get_set_details", nonce: ifcData.nonce, id: setId }, (r) => {
                    if (r.success) {
                        this.currentSet = r.data;
                        this.currentSetId = setId;
                        this.view = 'editor';
                        this.isCardsDirty = false;
                        this.$nextTick(() => this.initCardsHot());
                    } else { alert('Lỗi: ' + r.data); }
                }).fail(() => { alert('Lỗi máy chủ khi tải chi tiết bộ thẻ.'); }).always(() => { this.hideLoader(); });
            },
            backToList() {
                if (this.isCardsDirty && !confirm("Bạn có thay đổi chưa lưu. Rời đi?")) return;
                this.view = 'main';
                this.currentSetId = null;
                this.currentSet = { name: '' };
                this.isCardsDirty = false;
                this.$nextTick(() => this.fetchSets());
            },
            fetchSets() {
                this.showLoader("Đang tải danh sách bộ thẻ...");
                jQuery.post(ifcData.ajax, { action: "ifc_get_sets", nonce: ifcData.nonce }, (r) => {
                    if (r.success) {
                        this.sets = r.data.map(set => ({ ...set, selected: false, shortcode: `[image_flashcard id="${set.id}"]` }));
                        this.$nextTick(() => {
                            this.updatePagination();
                            this.initSetsHot();
                        });
                    } else { alert("Lỗi khi tải danh sách bộ thẻ."); }
                }).fail(() => { alert("Lỗi máy chủ khi tải danh sách bộ thẻ."); }).always(() => { this.hideLoader(); });
            },
            initSetsHot() {
                if (this.setsHot) { this.setsHot.destroy(); }
                const container = document.getElementById("ifc-sets-handsontable");
                if (!container) return;
                this.setsHot = new Handsontable(container, {
                    data: this.paginatedSets,
                    rowHeaders: true,
                    colHeaders: ["Chọn", "Tên bộ thẻ", "Số thẻ", "Shortcode", "Ngày tạo"],
                    columns: [
                        { data: 'selected', type: 'checkbox', className: 'htCenter', width: 50 },
                        { data: 'name', type: 'text', width: 300 },
                        { data: 'card_count', type: 'numeric', readOnly: true, width: 80 },
                        { data: 'shortcode', type: 'text', readOnly: true, width: 200 },
                        { data: 'created_at', type: 'date', readOnly: true, dateFormat: 'YYYY-MM-DD HH:mm:ss', width: 150 }
                    ],
                    manualColumnResize: true,
                    wordWrap: false,
                    width: "100%", height: "auto", licenseKey: "non-commercial-and-evaluation",
                    afterChange: (changes, source) => {
                        if (source === 'loadData' || !changes) return;
                        changes.forEach(([row, prop, oldVal, newVal]) => {
                            const dataIndex = ((this.pagination.currentPage - 1) * this.pagination.pageSize) + row;
                            if (prop === 'selected') {
                                this.filteredSets[dataIndex].selected = newVal;
                                this.updateSelectedSets();
                            }
                            if (prop === 'name' && oldVal !== newVal) {
                                this.filteredSets[dataIndex].name = newVal;
                                this.isSetsDirty = true;
                            }
                        });
                    },
                });
            },
            updatePagination() {
                this.pagination.totalItems = this.filteredSets.length;
                this.pagination.totalPages = Math.ceil(this.pagination.totalItems / this.pagination.pageSize);
                if (this.pagination.currentPage > this.pagination.totalPages) {
                    this.pagination.currentPage = this.pagination.totalPages || 1;
                }
                if (this.setsHot) { this.setsHot.loadData(this.paginatedSets); }
            },
            handleSearch() { this.pagination.currentPage = 1; this.updatePagination(); },
            handlePageSizeChange() { this.pagination.currentPage = 1; this.updatePagination(); },
            prevPage() { if (this.pagination.currentPage > 1) { this.pagination.currentPage--; this.updatePagination(); } },
            nextPage() { if (this.pagination.currentPage < this.pagination.totalPages) { this.pagination.currentPage++; this.updatePagination(); } },
            updateSelectedSets() { this.selectedSets = this.sets.filter(s => s.selected).map(s => s.id); },
            saveSetNames(callback) {
                if (!this.isSetsDirty) { if (typeof callback === 'function') callback(); return; }
                const setsToUpdate = this.sets.map(s => ({ id: s.id, name: s.name }));
                this.showLoader("Đang lưu tên các bộ thẻ...");
                jQuery.post(ifcData.ajax, { action: "ifc_update_set_names", nonce: ifcData.nonce, sets: JSON.stringify(setsToUpdate) }, (r) => {
                    this.hideLoader();
                    if (r.success) {
                        this.isSetsDirty = false;
                        this.fetchSets();
                        if (typeof callback === 'function') callback();
                    } else { alert("Lỗi khi lưu tên bộ thẻ."); }
                });
            },
            createSetAndEdit() {
                const name = prompt("Nhập tên cho bộ thẻ mới:", "Bộ thẻ mới");
                if (!name) return;
                this.showLoader("Đang tạo bộ thẻ mới...");
                jQuery.post(ifcData.ajax, { action: "ifc_create_set", nonce: ifcData.nonce, name: name }, (r) => {
                    this.hideLoader();
                    if (r.success) { this.editSet(r.data.id); }
                });
            },
            createMultipleSets() {
                const count = parseInt(this.setsToCreate, 10);
                if (!count || count < 1 || !confirm(`Bạn có chắc muốn tạo ${count} bộ thẻ mới?`)) return;
                this.showLoader(`Đang tạo ${count} bộ thẻ...`);
                let createdCount = 0;
                const promises = Array.from({ length: count }, (_, i) =>
                    jQuery.post(ifcData.ajax, { action: "ifc_create_set", nonce: ifcData.nonce, name: `Bộ thẻ mới ${Date.now() + i}` })
                        .done(r => { if (r.success) createdCount++; })
                );
                Promise.all(promises).then(() => {
                    alert(`Đã tạo thành công ${createdCount} bộ thẻ.`);
                    this.fetchSets();
                });
            },
            deleteSelectedSets() {
                if (this.selectedSets.length === 0 || !confirm(`Bạn có chắc muốn xóa vĩnh viễn ${this.selectedSets.length} bộ thẻ đã chọn?`)) return;
                this.showLoader('Đang xóa...');
                jQuery.post(ifcData.ajax, { action: 'ifc_delete_multiple_sets', nonce: ifcData.nonce, ids: this.selectedSets }, (r) => {
                    this.hideLoader();
                    if (r.success) {
                        this.fetchSets();
                        this.selectedSets = [];
                    } else { alert('Lỗi: ' + (r.data || 'Không thể xóa.')); }
                });
            },
            copySelectedShortcodes() {
                const shortcodes = this.sets.filter(s => this.selectedSets.includes(s.id)).map(s => `[image_flashcard id="${s.id}"]`).join('\n');
                if (!shortcodes) return;
                navigator.clipboard.writeText(shortcodes).then(() => alert('Đã sao chép ' + this.selectedSets.length + ' shortcode.'));
            },
            initCardsHot() {
                if (this.cardsHot) { this.cardsHot.destroy(); }
                const container = document.getElementById("ifc-cards-handsontable");
                if (!container || !this.currentSet) return;
                const cards = this.currentSet.cards && Array.isArray(this.currentSet.cards) ? this.currentSet.cards : [];
                this.cardsHot = new Handsontable(container, {
                    data: cards,
                    rowHeaders: true,
                    colHeaders: ["Mặt trước (Text/URL)", "Mặt sau 1", "Audio 1", "Mặt sau 2", "Audio 2", "Mặt sau 3", "Audio 3", "Mặt sau 4", "Audio 4"],
                    columns: [
                        { data: "front", width: 200 }, 
                        { data: "back1", width: 150 }, { data: "back1_audio", width: 150 }, 
                        { data: "back2", width: 150 }, { data: "back2_audio", width: 150 },
                        { data: "back3", width: 150 }, { data: "back3_audio", width: 150 },
                        { data: "back4", width: 150 }, { data: "back4_audio", width: 150 }
                    ],
                    manualColumnResize: true,
                    wordWrap: false,
                    minSpareRows: 1, width: "100%", height: "auto", licenseKey: "non-commercial-and-evaluation",
                    afterChange: () => { this.isCardsDirty = true; },
                });
            },
            saveSet() {
                if (!this.currentSet || !this.isCardsDirty) return;
                this.showLoader("Đang lưu thẻ...");
                const cardData = this.cardsHot.getSourceData().filter(row => Object.values(row).some(val => val));
                jQuery.post(ifcData.ajax, { action: "ifc_save_set", nonce: ifcData.nonce, id: this.currentSet.id, name: this.currentSet.name, cards: JSON.stringify(cardData) }, (r) => {
                    this.hideLoader();
                    if (r.success) {
                        this.isCardsDirty = false;
                        alert(r.data.message);
                    } else { alert("Lỗi: " + (r.data || "Không thể lưu.")); }
                });
            },
            openMediaUploader(target) {
                const mediaUploader = wp.media({ title: "Chọn file âm thanh", multiple: false, library: { type: 'audio' } });
                mediaUploader.on("select", () => {
                    const attachment = mediaUploader.state().get("selection").first().toJSON();
                    this.options[target] = attachment.url;
                });
                mediaUploader.open();
            },
            saveOptions() {
                this.showLoader("Đang lưu tùy chọn...");
                jQuery.post(ifcData.ajax, { action: 'ifc_save_options', nonce: ifcData.nonce, ...this.options })
                    .done(r => { if (r.success) alert(r.data); })
                    .fail(() => alert('Lỗi khi lưu tùy chọn.'))
                    .always(() => this.hideLoader());
            },
            // Language Pack Methods
            fetchLangPacks() {
                this.showLoader("Đang tải các gói ngôn ngữ...");
                jQuery.post(ifcData.ajax, { action: 'ifc_get_lang_packs', nonce: ifcData.nonce })
                    .done(r => {
                        if (r.success) {
                            this.langPacks = r.data;
                            if (!this.currentLangPackCode && Object.keys(this.langPacks).length > 0) {
                                this.editLangPack('vi'); // Default to showing 'vi'
                            }
                        }
                    })
                    .fail(() => alert('Lỗi khi tải gói ngôn ngữ.'))
                    .always(() => this.hideLoader());
            },
            editLangPack(code) {
                this.currentLangPackCode = code;
                this.currentLangPack = JSON.parse(JSON.stringify(this.langPacks[code])); // Deep copy
            },
            addNewLangPack() {
                const code = prompt("Nhập mã ngôn ngữ mới (ví dụ: en, fr, jp):");
                if (!code || code === 'vi' || this.langPacks[code]) {
                    if (code) alert("Mã ngôn ngữ này không hợp lệ hoặc đã tồn tại.");
                    return;
                }
                const newPack = {
                    langName: `Ngôn ngữ mới (${code})`,
                    texts: {}
                };
                ifcData.default_lang_keys.forEach(key => { newPack.texts[key] = ''; });

                this.$set(this.langPacks, code, newPack);
                this.editLangPack(code);
            },
            saveLangPack() {
                if (this.currentLangPackCode === 'vi') {
                    alert('Không thể chỉnh sửa gói ngôn ngữ mặc định.');
                    return;
                }
                this.showLoader("Đang lưu gói ngôn ngữ...");
                jQuery.post(ifcData.ajax, {
                    action: 'ifc_save_lang_pack',
                    nonce: ifcData.nonce,
                    code: this.currentLangPackCode,
                    pack: JSON.stringify(this.currentLangPack)
                })
                .done(r => { if (r.success) { alert(r.data.message); this.fetchLangPacks(); } else { alert('Lỗi: ' + r.data); }})
                .fail(() => alert('Lỗi máy chủ khi lưu.'))
                .always(() => this.hideLoader());
            },
            deleteLangPack(code) {
                if (code === 'vi') {
                    alert('Không thể xóa gói ngôn ngữ mặc định.');
                    return;
                }
                if (!confirm(`Bạn có chắc muốn xóa gói ngôn ngữ "${this.langPacks[code].langName}"?`)) return;
                this.showLoader("Đang xóa...");
                jQuery.post(ifcData.ajax, { action: 'ifc_delete_lang_pack', nonce: ifcData.nonce, code: code })
                    .done(r => {
                        if (r.success) {
                            alert(r.data.message);
                            this.currentLangPack = null;
                            this.currentLangPackCode = '';
                            this.fetchLangPacks();
                        } else { alert('Lỗi: ' + r.data); }
                    })
                    .fail(() => alert('Lỗi máy chủ khi xóa.'))
                    .always(() => this.hideLoader());
            }
        }
    });
});
EOD
,
            'ifc-admin.css' => <<<'EOD'
#ifc-app{opacity:1;transition:opacity .3s}.ifc-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.ifc-header .ifc-actions{display:flex;gap:10px}.ifc-bulk-actions{margin-bottom:20px;padding:15px;border:1px solid #ddd;background:#f9f9f9;display:flex;flex-wrap:wrap;gap:20px;align-items:center}.ifc-bulk-actions .ifc-bulk-group{display:flex;gap:10px;align-items:center}.ifc-bulk-actions input[type="search"]{width:250px;}#ifc-sets-handsontable, #ifc-cards-handsontable{margin-top: 15px;} .htCore td.htCheckbox, .htCore th.htCheckbox {text-align: center;} .ifc-editor{display:flex;flex-direction:column;height:100%}.ifc-editor-header{display:flex;justify-content:space-between;align-items:center;padding:10px;background:#fff;border:1px solid #ddd;border-bottom:none}.ifc-set-name-input{font-size:1.5em;font-weight:700;border:none;box-shadow:none;padding:5px;flex-grow:1}.ifc-editor-actions{display:flex;gap:10px;align-items:center}#ifc-handsontable{flex-grow:1}.button-danger{background:#dc3545;border-color:#dc3545;color:#fff}.button-danger:hover{background:#c82333;border-color:#bd2130}#ifc-loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.8);z-index:9999;display:flex;flex-direction:column;justify-content:center;align-items:center}#ifc-loading-overlay p{margin-top:10px;font-size:16px;color:#333}.handsontable .htDimmed{color:#888}.ifc-pagination{margin-top:15px;display:flex;justify-content:flex-end;align-items:center;gap:10px;}.form-table .form-field{margin-bottom:20px;}.form-table .ifc-media-field{display:flex;gap:10px;}.form-table .ifc-media-field input{flex-grow:1;}.ifc-lang-manager{display:flex;gap:30px;margin-top:20px}.ifc-lang-list{flex:1}.ifc-lang-editor{flex:2;padding:20px;background:#fff;border:1px solid #ddd}.ifc-lang-list ul{margin:0 0 20px;padding:0;border:1px solid #ddd;background:#fff}.ifc-lang-list li{padding:10px 15px;border-bottom:1px solid #ddd;cursor:pointer;display:flex;justify-content:space-between;align-items:center}.ifc-lang-list li:last-child{border-bottom:none}.ifc-lang-list li:hover{background:#f0f0f0}.ifc-lang-list li.active{background:#e0eaf3;font-weight:700}.ifc-lang-editor .form-field{margin-bottom:15px}.ifc-lang-editor label{display:block;margin-bottom:5px;font-weight:600}.ifc-lang-editor input[type="text"]{width:100%}.default-chip{background-color:#e0e0e0;color:#333;font-size:11px;padding:2px 6px;border-radius:10px;font-weight:normal}.ifc-default-lang-list{list-style:none;padding:0;margin:0}.ifc-default-lang-list li{padding:8px;border-bottom:1px solid #eee}.ifc-default-lang-list li code{color:#c7254e;background-color:#f9f2f4;padding:2px 4px;border-radius:4px}.ifc-default-lang-list li span{margin-left:10px}
EOD
,
            'ifc-front.php' => <<<'EOD'
<?php if (!defined("ABSPATH")) exit; ?>
<div class="ifc-wrap" data-id="<?= esc_attr($item->id) ?>" data-cards='<?= esc_attr(wp_json_encode(array_values($cards))) ?>'>
    <div class="ifc-header">
        <h3 class="ifc-title"><?= esc_html($item->name) ?></h3>
        <div class="ifc-meta-controls">
            <div class="ifc-lang-switcher-wrap" style="display: none;">
                <select class="ifc-lang-switcher"></select>
            </div>
            <div class="ifc-progress">
                <span class="ifc-progress-counter"><span data-lang="cardCounter">Thẻ</span>: <span class="curr">1</span>/<span class="total"><?= count($cards) ?></span></span>
            </div>
            <button class="ifc-btn ifc-shuffle-btn" title="Đảo thứ tự">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                <span class="ifc-btn-text" data-lang="shuffleOrder">Đảo</span>
            </button>
            <button class="ifc-btn ifc-show-back-toggle" title="Hiện mặt sau">
                <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                <span class="ifc-btn-text" data-lang="showBackOnFront">Hiện mặt sau</span>
            </button>
        </div>
    </div>
    <div class="ifc-main-content" style="display: none;">
        <div class="ifc-card">
            <div class="ifc-inner">
                <div class="ifc-face ifc-front">
                    <div class="ifc-content-wrapper">
                        <div class="ifc-front-audio-container"></div>
                        <div class="ifc-image-container"></div>
                        <div class="ifc-audio-container"></div>
                        <div class="ifc-back1-preview" style="display: none;"></div>
                        <div class="ifc-text-content"></div>
                    </div>
                </div>
                <div class="ifc-face ifc-back">
                    <div class="ifc-content-wrapper ifc-back-content"></div>
                </div>
            </div>
        </div>
        <div class="ifc-nav-btns">
            <button class="ifc-btn ifc-prev" title="Trước (←)">
                <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                <span class="ifc-btn-text" data-lang="prevCard">Trước</span>
            </button>
            <button class="ifc-btn ifc-flip" title="Lật (Space)">
                <svg viewBox="0 0 24 24"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z"/></svg>
                <span class="ifc-btn-text" data-lang="flipCard">Lật</span>
            </button>
            <button class="ifc-btn ifc-next" title="Sau (→)">
                <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                <span class="ifc-btn-text" data-lang="nextCard">Sau</span>
            </button>
            <button class="ifc-btn ifc-quiz" title="Chơi Quiz">
                <svg viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                <span class="ifc-btn-text" data-lang="playQuiz">Quiz</span>
            </button>
        </div>
        <div class="ifc-keyboard-hint">Phím tắt: Space=lật thẻ, ←/→=Thẻ trước/Sau</div>
    </div>
    <div class="ifc-quiz-mode" style="display:none;">
        <div class="ifc-quiz-header">
            <div class="ifc-quiz-progress"><span data-lang="questionCounter">Câu</span>: <span class="quiz-curr">1</span>/<span class="quiz-total">0</span></div>
            <div class="ifc-quiz-filter-wrap">
                <button class="ifc-btn ifc-quiz-filter-toggle" title="Ẩn/Hiện các mặt sau">
                    <svg viewBox="0 0 24 24"><path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/></svg>
                    <span class="ifc-btn-text" data-lang="filter">Ẩn</span>
                </button>
                <div class="ifc-quiz-filter-options" style="display: none;">
                    <label><input type="checkbox" name="back_filter" value="back1" checked> <span data-lang="showBack1">Hiện mặt sau 1</span></label>
                    <label><input type="checkbox" name="back_filter" value="back2"> <span data-lang="showBack2">Hiện mặt sau 2</span></label>
                    <label><input type="checkbox" name="back_filter" value="back3"> <span data-lang="showBack3">Hiện mặt sau 3</span></label>
                    <label><input type="checkbox" name="back_filter" value="back4"> <span data-lang="showBack4">Hiện mặt sau 4</span></label>
                </div>
            </div>
            <button class="ifc-btn ifc-quiz-exit" title="Thoát Quiz">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                <span class="ifc-btn-text" data-lang="exit">Thoát</span>
            </button>
        </div>
        <div class="ifc-quiz-question"></div>
        <div class="ifc-quiz-options"></div>
        <button class="ifc-btn ifc-quiz-next" style="display:none;" title="Câu tiếp theo">
             <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
             <span class="ifc-btn-text" data-lang="nextQuestion">Câu tiếp</span>
        </button>
    </div>
    <div class="ifc-quiz-results" style="display:none;">
        <h3 data-lang="quizComplete">Hoàn thành Quiz!</h3>
        <div class="ifc-summary">
             <div class="ifc-stat correct">
                <svg viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>
                <span class="cnt-quiz-correct">0</span> <span data-lang="correct">ĐÚNG</span>
            </div>
            <div class="ifc-stat wrong">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                <span class="cnt-quiz-wrong">0</span> <span data-lang="wrong">SAI</span>
            </div>
        </div>
        <div class="ifc-results-actions">
            <button class="ifc-btn ifc-quiz-restart" title="Làm lại Quiz">
                 <svg viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                <span class="ifc-btn-text" data-lang="retryQuiz">Làm lại Quiz</span>
            </button>
            <button class="ifc-btn ifc-back-flashcard" title="Quay lại học">
                <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                <span class="ifc-btn-text" data-lang="backToStudy">Quay lại học</span>
            </button>
        </div>
    </div>
    <!-- Quiz Result Popup -->
    <div class="ifc-result-popup" style="display: none;">
        <div class="ifc-result-popup-content">
            <span class="ifc-result-popup-icon"></span>
        </div>
    </div>
</div>
EOD
,
            'ifc-front.js' => <<<'EOD'
(function () {
    function shuffle(a) { for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1));[a[i], a[j]] = [a[j], a[i]]; } return a; }
    function isUrl(string) { return typeof string === 'string' && (string.startsWith('http') || string.startsWith('/')); }
    
    // Confetti system
    function createConfetti(container, count = 50) {
        const colors = ['#667eea', '#764ba2', '#f093fb', '#4ade80', '#fbbf24', '#f87171'];
        const confettiContainer = document.createElement('div');
        confettiContainer.className = 'ifc-confetti';
        container.appendChild(confettiContainer);
        
        for (let i = 0; i < count; i++) {
            const piece = document.createElement('div');
            piece.className = 'confetti-piece';
            piece.style.setProperty('--color', colors[Math.floor(Math.random() * colors.length)]);
            piece.style.left = Math.random() * 100 + '%';
            piece.style.setProperty('--duration', (Math.random() * 2 + 2) + 's');
            piece.style.animationDelay = Math.random() * 0.5 + 's';
            confettiContainer.appendChild(piece);
        }
        
        const duration = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--ifc-confetti-duration')) || 4000;
        setTimeout(() => confettiContainer.remove(), duration);
    }
    
    function triggerConfetti(element, size = 'small') {
        const count = size === 'large' ? 100 : 30;
        createConfetti(element, count);
    }

    document.querySelectorAll(".ifc-wrap").forEach(function (w) {
        let originalCards = JSON.parse(w.dataset.cards), cards, idx, isFlipped, quizMode, quizIdx, quizAnswered, quizCorrect, quizWrong, starCount = 0, firstTry, isShuffled = false, showBackOnFront = false;
        const options = ifcFrontData.options || {};
        const langPacks = ifcFrontData.langPacks || {};
        let currentLang = localStorage.getItem('ifc_lang') || Object.keys(langPacks)[0] || 'vi';
        
        const mainContent = w.querySelector(".ifc-main-content"),
            inner = w.querySelector(".ifc-inner"),
            backContentEl = w.querySelector(".ifc-back-content"),
            frontAudioCont = w.querySelector(".ifc-front-audio-container"),
            imgCont = w.querySelector(".ifc-image-container"),
            audioCont = w.querySelector(".ifc-audio-container"),
            frontTxt = w.querySelector(".ifc-text-content"),
            back1Preview = w.querySelector(".ifc-back1-preview"),
            progCtr = w.querySelector(".ifc-progress-counter"),
            curr = w.querySelector(".curr"),
            total = w.querySelector(".total"),
            btnShuffle = w.querySelector(".ifc-shuffle-btn"),
            btnShowBackToggle = w.querySelector(".ifc-show-back-toggle"),
            btnPrev = w.querySelector(".ifc-prev"),
            btnNext = w.querySelector(".ifc-next"),
            btnFlip = w.querySelector(".ifc-flip"),
            btnQuiz = w.querySelector(".ifc-quiz"),
            quizDiv = w.querySelector(".ifc-quiz-mode"),
            quizCurr = w.querySelector('.quiz-curr'),
            quizTotal = w.querySelector('.quiz-total'),
            qQuestion = w.querySelector(".ifc-quiz-question"),
            qOptions = w.querySelector(".ifc-quiz-options"),
            btnQNext = w.querySelector(".ifc-quiz-next"),
            btnQExit = w.querySelector(".ifc-quiz-exit"),
            quizResults = w.querySelector(".ifc-quiz-results"),
            btnQRestart = w.querySelector(".ifc-quiz-restart"),
            btnBackFC = w.querySelector(".ifc-back-flashcard"),
            resultPopup = w.querySelector('.ifc-result-popup'),
            langSwitcherWrap = w.querySelector('.ifc-lang-switcher-wrap'),
            langSwitcher = w.querySelector('.ifc-lang-switcher'),
            quizFilterToggle = w.querySelector('.ifc-quiz-filter-toggle'),
            quizFilterOptions = w.querySelector('.ifc-quiz-filter-options');

        const correctSound = options.correct_audio ? new Audio(options.correct_audio) : null;
        const wrongSound = options.wrong_audio ? new Audio(options.wrong_audio) : null;

        function i18n(key) {
            return langPacks[currentLang]?.texts?.[key] || key;
        }

        function applyLanguage() {
            w.querySelectorAll('[data-lang]').forEach(el => {
                const key = el.dataset.lang;
                el.textContent = i18n(key);
            });
        }

        function setupLangSwitcher() {
            const packKeys = Object.keys(langPacks);
            if (packKeys.length <= 1) {
                langSwitcherWrap.style.display = 'none';
                return;
            }
            langSwitcherWrap.style.display = 'block';
            langSwitcher.innerHTML = '';
            packKeys.forEach(code => {
                const option = document.createElement('option');
                option.value = code;
                option.textContent = langPacks[code].langName;
                option.selected = code === currentLang;
                langSwitcher.appendChild(option);
            });
            langSwitcher.addEventListener('change', (e) => {
                currentLang = e.target.value;
                localStorage.setItem('ifc_lang', currentLang);
                applyLanguage();
            });
        }
        
        function createAudioButton(audioUrl) {
            if (!audioUrl || !isUrl(audioUrl)) return '';
            const audioId = 'audio-' + Math.random().toString(36).substr(2, 9);
            return `<audio id="${audioId}" src="${audioUrl}"></audio>
                    <button class="ifc-audio-play-btn" data-audio-id="${audioId}">
                        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    </button>`;
        }
        
        function getBackContentHTML(card) {
            let html = '';
            for (let i = 1; i <= 4; i++) {
                if (card['back' + i]) {
                    // Check each back face's audio URL individually to only show icon when URL exists
                    const audioUrl = card['back' + i + '_audio'];
                    const audioBtn = (audioUrl && isUrl(audioUrl)) ? createAudioButton(audioUrl) : '';
                    // Audio icon appears above text (flex-direction: column in CSS)
                    html += `<div class="ifc-back-row">${audioBtn}<span>${card['back' + i]}</span></div>`;
                }
            }
            return html;
        }

        function getFilteredBackContentText(card) {
            const checkedFilters = quizFilterOptions ? Array.from(quizFilterOptions.querySelectorAll("input:checked")).map(cb => cb.value) : [];
            // If no filters are checked, default to showing everything to avoid blank options.
            if (checkedFilters.length === 0) {
                 return [card.back1, card.back2, card.back3, card.back4].filter(Boolean).join(" / ");
            }
            return checkedFilters.map(filterKey => card[filterKey]).filter(Boolean).join(" / ");
        }

        function playSound(audioEl, buttonEl) {
            if (audioEl) {
                audioEl.currentTime = 0;
                if (buttonEl) buttonEl.classList.add('playing');
                audioEl.play().catch(e => console.error("Audio play failed:", e));
                audioEl.onended = () => {
                    if (buttonEl) buttonEl.classList.remove('playing');
                };
            }
        }

        function showCard(i) {
            if (i < 0 || i >= cards.length) { idx = Math.max(0, Math.min(i, cards.length - 1)); return; }
            let card = cards[i];
            let frontContent = card.front || "";
            
            imgCont.innerHTML = ""; audioCont.innerHTML = ""; frontTxt.innerHTML = ""; back1Preview.innerHTML = ""; frontAudioCont.innerHTML = "";
            backContentEl.innerHTML = getBackContentHTML(card);
            
            let isImageFront = false;
            let isTextFront = false;
            
            if (isUrl(frontContent)) {
                if (/\.(jpeg|jpg|gif|png|svg|webp)(\?.*)?$/i.test(frontContent)) {
                    imgCont.innerHTML = `<img src="${frontContent}" alt="">`;
                    isImageFront = true;
                } else if (/\.(mp3|wav|ogg)(\?.*)?$/i.test(frontContent)) {
                    audioCont.innerHTML = createAudioButton(frontContent);
                } else {
                    frontTxt.innerHTML = `<span>${frontContent.replace(/\\n/g, "<br>")}</span>`;
                    isTextFront = true;
                }
            } else {
                frontTxt.innerHTML = `<span>${frontContent.replace(/\\n/g, "<br>")}</span>`;
                isTextFront = true;
            }
            
            // Add back1_audio button to front if it exists
            if (card.back1_audio && isUrl(card.back1_audio)) {
                const audioBtn = createAudioButton(card.back1_audio);
                if (isImageFront) {
                    // Audio over image - use overlay style
                    frontAudioCont.innerHTML = audioBtn;
                    frontAudioCont.className = 'ifc-front-audio-container audio-overlay';
                } else if (isTextFront) {
                    // Audio above text
                    frontAudioCont.innerHTML = audioBtn;
                    frontAudioCont.className = 'ifc-front-audio-container audio-above-text';
                } else {
                    frontAudioCont.innerHTML = "";
                }
            }
            
            // Show back1 preview if enabled and front is image
            if (showBackOnFront && isImageFront && card.back1) {
                back1Preview.innerHTML = `<span>${card.back1}</span>`;
                back1Preview.style.display = "block";
            } else {
                back1Preview.style.display = "none";
            }
            
            if (frontTxt.innerHTML.trim() === '') {
                frontTxt.style.flexGrow = '0';
            } else {
                frontTxt.style.flexGrow = '1';
            }

            inner.classList.remove("flipped"); isFlipped = false;
            w.querySelector('.ifc-progress').style.display = "flex"; curr.textContent = i + 1; total.textContent = cards.length;
            btnPrev.disabled = (i === 0); btnNext.disabled = (i === cards.length - 1);
            
            const mainAudioBtn = audioCont.querySelector('.ifc-audio-play-btn');
            if (mainAudioBtn) {
                const audioEl = document.getElementById(mainAudioBtn.dataset.audioId);
                if (audioEl) {
                    mainAudioBtn.onclick = (e) => { e.stopPropagation(); playSound(audioEl, mainAudioBtn); };
                    setTimeout(() => playSound(audioEl, mainAudioBtn), 300);
                }
            }
            
            // Setup front audio button
            const frontAudioBtn = frontAudioCont.querySelector('.ifc-audio-play-btn');
            if (frontAudioBtn) {
                const audioEl = document.getElementById(frontAudioBtn.dataset.audioId);
                if (audioEl) {
                    frontAudioBtn.onclick = (e) => { e.stopPropagation(); playSound(audioEl, frontAudioBtn); };
                }
            }
        }

        function startLearn(cardSet) {
            w.scrollIntoView({ behavior: "smooth", block: "start" });
            idx = 0;
            cards = isShuffled ? shuffle([].concat(cardSet)) : [].concat(cardSet);
            quizMode = false;
            quizResults.style.display = "none"; mainContent.style.display = "flex"; quizDiv.style.display = "none";
            w.querySelector('.ifc-progress').style.display = "flex";
            showCard(idx);
        }

        function startQuiz(cardSet) {
            w.scrollIntoView({ behavior: "smooth", block: "start" });
            const validCards = cardSet.filter(c => c && getFilteredBackContentText(c).trim() !== "");
            if (validCards.length < 4) { alert(i18n('notEnoughCards')); return; }
            quizMode = true; quizIdx = 0; quizCorrect = []; quizWrong = []; starCount = 0;
            cards = isShuffled ? shuffle([].concat(validCards)) : [].concat(validCards);
            mainContent.style.display = "none"; quizDiv.style.display = "flex"; quizResults.style.display = "none";
            w.querySelector('.ifc-progress').style.display = "none";
            quizTotal.textContent = cards.length;
            showQuiz();
        }

        function showQuiz() {
            if (quizIdx >= cards.length) { showQuizResults(); return; }
            quizAnswered = false; firstTry = true;
            btnQNext.style.display = "none";
            quizCurr.textContent = quizIdx + 1;

            let current = cards[quizIdx];
            let qText = current.front || "";
            let correctAnswer = getFilteredBackContentText(current);
            
            let otherCards = cards.filter((c, i) => {
                const otherText = getFilteredBackContentText(c);
                return i !== quizIdx && otherText.trim() !== "" && otherText !== correctAnswer;
            });

            let options = shuffle(otherCards).slice(0, 3).map(c => ({ text: getFilteredBackContentText(c), correct: false }));
            options.push({ text: correctAnswer, correct: true });
            
            let questionHTML = '';
            if (isUrl(qText)) {
                if (/\.(jpeg|jpg|gif|png|svg|webp)(\?.*)?$/i.test(qText)) { questionHTML += `<img src="${qText}" class="quiz-question-image" alt="">`; } 
                else if (/\.(mp3|wav|ogg)(\?.*)?$/i.test(qText)) { questionHTML += createAudioButton(qText); } 
                else { questionHTML += `<span>${qText}</span>`; }
            } else { questionHTML += `<span>${qText}</span>`; }
            qQuestion.innerHTML = questionHTML;
            qOptions.innerHTML = "";

            shuffle(options).forEach((opt, index) => {
                let btn = document.createElement("button");
                btn.className = "ifc-quiz-option"; 
                btn.innerHTML = `<span class="quiz-option-number">${index + 1}</span><span class="quiz-option-text">${opt.text || ""}</span>`;
                btn.dataset.correct = opt.correct;
                btn.onclick = () => { if (!quizAnswered) checkAnswer(btn, opt.correct, current); };
                qOptions.appendChild(btn);
            });
            
            const audioBtn = qQuestion.querySelector('.ifc-audio-play-btn');
            if (audioBtn) {
                const audioEl = document.getElementById(audioBtn.dataset.audioId);
                if (audioEl) {
                    audioBtn.onclick = (e) => { e.stopPropagation(); playSound(audioEl, audioBtn); };
                    setTimeout(() => playSound(audioEl, audioBtn), 300);
                }
            }
        }
        
        function showResultPopup(isCorrect) {
            const iconEl = resultPopup.querySelector('.ifc-result-popup-icon');
            resultPopup.classList.remove('correct', 'wrong');
            
            if (isCorrect) {
                iconEl.innerHTML = '✓';
                resultPopup.classList.add('correct');
                if(correctSound) playSound(correctSound);
                triggerConfetti(w, 'small');
            } else {
                iconEl.innerHTML = '✗';
                resultPopup.classList.add('wrong');
                if(wrongSound) playSound(wrongSound);
            }

            resultPopup.style.display = 'flex';
            setTimeout(() => {
                resultPopup.style.display = 'none';
            }, 1200);
        }

        function checkAnswer(btn, isCorrect, card) {
            showResultPopup(isCorrect);

            if (isCorrect) {
                quizAnswered = true;
                btn.classList.add("correct");
                qOptions.querySelectorAll(".ifc-quiz-option").forEach(b => { 
                    b.disabled = true; 
                    if (b.dataset.correct === "true") b.classList.add("correct");
                });

                if (firstTry) {
                    quizCorrect.push(card);
                    starCount++;
                } else {
                    if (!quizWrong.includes(card)) quizWrong.push(card);
                }
                setTimeout(() => { btnQNext.style.display = "inline-flex"; }, 300);
            } else {
                btn.classList.add("wrong");
                btn.disabled = true;
                if(firstTry) {
                   starCount = Math.max(0, starCount - 1);
                }
                firstTry = false;
            }
        }

        function showQuizResults() {
            quizDiv.style.display = "none"; quizResults.style.display = "block";
            w.querySelector(".cnt-quiz-correct").textContent = quizCorrect.length;
            w.querySelector(".cnt-quiz-wrong").textContent = quizWrong.length;
            triggerConfetti(w, 'large');
        }

        function exitQuiz() { startLearn(originalCards); }

        function init() {
            if (!langPacks[currentLang]) {
                currentLang = Object.keys(langPacks)[0];
                if (!currentLang) return; // No lang packs at all
            }
            setupLangSwitcher();
            applyLanguage();
            if (originalCards && originalCards.length > 0) {
                startLearn(originalCards);
            }
        }

        w.addEventListener('click', function(e) {
            const audioBtn = e.target.closest('.ifc-audio-play-btn');
            if (audioBtn) {
                const audioEl = document.getElementById(audioBtn.dataset.audioId);
                if (audioEl) playSound(audioEl, audioBtn);
            }
        });
        
        inner.addEventListener("click", (e) => { if (!e.target.closest('.ifc-audio-play-btn')) { inner.classList.toggle("flipped"); isFlipped = !isFlipped; } });
        btnPrev.onclick = () => { if(btnPrev.disabled) return; idx--; showCard(idx); };
        btnNext.onclick = () => { if(btnNext.disabled) return; idx++; showCard(idx); };
        btnFlip.onclick = () => { inner.classList.toggle("flipped"); isFlipped = !isFlipped; };
        btnQuiz.onclick = () => startQuiz(originalCards);
        btnQNext.onclick = () => { quizIdx++; showQuiz(); };
        btnQExit.onclick = exitQuiz;
        btnQRestart.onclick = () => startQuiz(originalCards);
        btnBackFC.onclick = exitQuiz;
        
        if(btnShuffle) {
            btnShuffle.addEventListener('click', () => {
                isShuffled = !isShuffled;
                if (isShuffled) {
                    btnShuffle.classList.add('active');
                } else {
                    btnShuffle.classList.remove('active');
                }
                idx = 0;
                startLearn(originalCards);
            });
        }
        
        if(btnShowBackToggle) {
            btnShowBackToggle.addEventListener('click', () => {
                showBackOnFront = !showBackOnFront;
                if (showBackOnFront) {
                    btnShowBackToggle.classList.add('active');
                } else {
                    btnShowBackToggle.classList.remove('active');
                }
                showCard(idx);
            });
        }
        if (quizFilterToggle) {
            quizFilterToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                quizFilterOptions.style.display = quizFilterOptions.style.display === 'none' ? 'block' : 'none';
            });
        }
        if (quizFilterOptions) {
            quizFilterOptions.querySelectorAll('input').forEach(cb => {
                cb.addEventListener('change', () => {
                    startQuiz(originalCards);
                });
            });
        }
        document.addEventListener('click', (e) => {
            if (quizFilterOptions && quizFilterOptions.style.display === 'block' && !quizFilterOptions.contains(e.target) && !quizFilterToggle.contains(e.target)) {
                quizFilterOptions.style.display = 'none';
            }
        });
        
        document.addEventListener("keydown", e => {
            if (!w.contains(document.activeElement) && !w.contains(e.target)) {
                 if (document.querySelector('.ifc-wrap') !== w) return;
            }
            if (e.target.closest('input, textarea')) return;

            if (quizMode) { 
                if (e.key === "ArrowRight" && btnQNext.style.display !== "none") {
                    btnQNext.click(); 
                } else if (['1', '2', '3', '4'].includes(e.key) && !quizAnswered) {
                    const optionButtons = qOptions.querySelectorAll('.ifc-quiz-option:not(:disabled)');
                    const targetButton = Array.from(optionButtons).find(btn => btn.querySelector('.quiz-option-number').textContent === e.key);
                    if (targetButton) {
                        targetButton.click();
                    }
                }
            } 
            else if (mainContent.style.display !== "none") {
                if (e.code === "Space" || e.key === "ArrowUp" || e.key === "ArrowDown") { e.preventDefault(); btnFlip.click(); } 
                else if (e.key === "ArrowLeft") btnPrev.click();
                else if (e.key === "ArrowRight") btnNext.click();
            }
        });

        init();
    });
})();
EOD
,
            'ifc-front.css' => <<<'EOD'
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');

* { scroll-behavior: smooth; }

:root {
  --ifc-primary: #667eea;
  --ifc-secondary: #764ba2;
  --ifc-accent: #f093fb;
  --ifc-success: #4ade80;
  --ifc-error: #f87171;
  --ifc-warning: #fbbf24;
  --ifc-glass-bg: rgba(255, 255, 255, 0.15);
  --ifc-glass-border: rgba(255, 255, 255, 0.3);
  --ifc-blur: 12px;
  --ifc-radius: 24px;
  --ifc-radius-sm: 16px;
  --ifc-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
  --ifc-shadow-hover: 0 15px 40px rgba(31, 38, 135, 0.25);
  --ifc-confetti-duration: 4000ms;
}

.ifc-wrap { 
  max-width: 550px; margin: 30px auto; padding: 25px; font-family: 'Poppins', sans-serif; 
  background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
  background-size: 200% 200%; animation: gradient-shift 15s ease infinite;
  border-radius: var(--ifc-radius); box-shadow: var(--ifc-shadow); position: relative; overflow: hidden;
}

@keyframes gradient-shift {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

.ifc-wrap::before { 
  content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; 
  background: radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
              radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.15) 0%, transparent 50%);
  pointer-events: none; z-index: 0;
}

.ifc-header { 
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; 
  padding: 0 10px; position: relative; z-index: 2; flex-shrink: 0; flex-wrap: wrap; gap: 10px;
}

.ifc-title { 
  font-size: 22px; color: #fff; font-weight: 700; margin: 0; flex-grow: 1; 
  text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); letter-spacing: 0.5px;
}

.ifc-meta-controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

.ifc-lang-switcher { 
  border-radius: var(--ifc-radius-sm); border: 1px solid var(--ifc-glass-border); 
  padding: 8px 12px; font-family: inherit; font-size: 14px; font-weight: 600;
  background: var(--ifc-glass-bg); backdrop-filter: blur(var(--ifc-blur)); 
  -webkit-backdrop-filter: blur(var(--ifc-blur)); color: #fff;
  transition: all 0.3s ease;
}

.ifc-lang-switcher:hover { background: rgba(255, 255, 255, 0.25); transform: translateY(-2px); }

.ifc-progress { 
  display: flex; align-items: center; 
  background: rgba(255, 255, 255, 0.15);
  background: var(--ifc-glass-bg); 
  backdrop-filter: blur(var(--ifc-blur)); -webkit-backdrop-filter: blur(var(--ifc-blur));
  padding: 8px 16px; border-radius: var(--ifc-radius); font-size: 15px; font-weight: 600; 
  color: #fff; box-shadow: var(--ifc-shadow); border: 1px solid var(--ifc-glass-border);
  transition: all 0.3s ease;
}

.ifc-progress:hover {
  background: rgba(255, 255, 255, 0.25); transform: translateY(-2px);
}

.ifc-shuffle-btn, .ifc-show-back-toggle {
  background: rgba(255, 255, 255, 0.15);
  background: var(--ifc-glass-bg);
  backdrop-filter: blur(var(--ifc-blur)); -webkit-backdrop-filter: blur(var(--ifc-blur));
  border: 1px solid var(--ifc-glass-border);
  transition: all 0.3s ease;
}

.ifc-shuffle-btn.active, .ifc-show-back-toggle.active {
  background: linear-gradient(135deg, rgba(102, 126, 234, 0.4), rgba(118, 75, 162, 0.4));
  border-color: rgba(102, 126, 234, 0.6);
  box-shadow: 0 0 15px rgba(102, 126, 234, 0.4);
}

.ifc-shuffle-btn:hover, .ifc-show-back-toggle:hover {
  background: rgba(255, 255, 255, 0.25);
}

.ifc-card { position: relative; perspective: 2000px; width: 100%; height: 350px; margin: 15px 0; }

.ifc-inner { 
  position: relative; width: 100%; height: 100%; 
  transition: transform 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55); 
  transform-style: preserve-3d;
}

.ifc-inner.flipped { transform: rotateY(180deg); }

.ifc-face { 
  position: absolute; width: 100%; height: 100%; 
  -webkit-backface-visibility: hidden; backface-visibility: hidden; 
  display: flex; align-items: center; justify-content: center; box-sizing: border-box; 
  border-radius: var(--ifc-radius); 
  box-shadow: 0 8px 32px rgba(31, 38, 135, 0.2), 0 2px 8px rgba(0, 0, 0, 0.1);
  font-size: 24px; text-align: center; padding: 20px; overflow: hidden;
  background: rgba(255, 255, 255, 0.15);
  background: var(--ifc-glass-bg); backdrop-filter: blur(15px); 
  -webkit-backdrop-filter: blur(15px); border: 1px solid var(--ifc-glass-border);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.ifc-face:hover { 
  box-shadow: 0 15px 45px rgba(31, 38, 135, 0.3), 0 4px 12px rgba(0, 0, 0, 0.15);
}

.ifc-front { 
  background: linear-gradient(135deg, rgba(102, 126, 234, 0.3), rgba(118, 75, 162, 0.3));
  color: #fff; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.ifc-back { 
  background: linear-gradient(135deg, rgba(118, 75, 162, 0.3), rgba(240, 147, 251, 0.3));
  color: #fff; transform: rotateY(180deg); text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.ifc-content-wrapper { 
  display: flex; flex-direction: column; align-items: center; justify-content: center; 
  gap: 15px; width: 100%; height: 100%; overflow: hidden; padding: 15px; box-sizing: border-box;
  position: relative;
}

.ifc-front-audio-container { 
  display: none;
}

.ifc-front-audio-container.audio-overlay { 
  position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
  z-index: 10; display: flex; align-items: center; justify-content: center;
}

.ifc-front-audio-container.audio-overlay .ifc-audio-play-btn {
  background: linear-gradient(135deg, rgba(251, 191, 36, 0.8), rgba(245, 158, 11, 0.8));
  backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  box-shadow: 0 4px 20px rgba(251, 191, 36, 0.6);
}

.ifc-front-audio-container.audio-overlay .ifc-audio-play-btn:hover {
  background: linear-gradient(135deg, rgba(251, 191, 36, 0.95), rgba(245, 158, 11, 0.95));
}

.ifc-front-audio-container.audio-above-text { 
  display: flex; align-items: center; justify-content: center;
  order: -1; width: 100%;
}

.ifc-image-container { 
  width: 100%; height: auto; max-height: 100%; display: flex; align-items: center; 
  justify-content: center; position: relative;
}

.ifc-image-container img { 
  max-width: 100%; max-height: 100%; border-radius: var(--ifc-radius-sm); 
  object-fit: contain; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.ifc-back1-preview {
  width: 100%; text-align: center; padding: 10px 15px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: var(--ifc-radius-sm);
  font-size: 16px; font-weight: 600; color: #fff;
  border: 1px dashed rgba(255, 255, 255, 0.4);
  margin-top: -5px;
  animation: slide-in 0.3s ease-out;
}

.ifc-back1-preview span {
  display: block; word-break: break-word;
}

.ifc-text-content { 
  flex-grow: 1; display: flex; align-items: center; justify-content: center; width: 100%;
}

.ifc-text-content span { 
  display: block; max-width: 100%; word-break: break-word; font-weight: 600; 
  letter-spacing: 0.3px;
}

.ifc-audio-play-btn { 
  background: linear-gradient(135deg, var(--ifc-warning), #f59e0b); 
  border: none; width: 56px; height: 56px; border-radius: 50%; cursor: pointer; 
  display: flex; align-items: center; justify-content: center; 
  box-shadow: 0 4px 15px rgba(251, 191, 36, 0.4); 
  transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55); 
  flex-shrink: 0; position: relative; overflow: hidden;
}

.ifc-audio-play-btn::before {
  content: ''; position: absolute; top: 50%; left: 50%; 
  width: 0; height: 0; border-radius: 50%; 
  background: rgba(255, 255, 255, 0.5);
  transform: translate(-50%, -50%); transition: width 0.6s, height 0.6s;
}

.ifc-audio-play-btn:hover { 
  transform: scale(1.15); box-shadow: 0 6px 25px rgba(251, 191, 36, 0.6);
}

.ifc-audio-play-btn:active::before {
  width: 120%; height: 120%; transition: width 0s, height 0s;
}

.ifc-audio-play-btn svg { width: 26px; height: 26px; fill: #fff; margin-left: 4px; }

.ifc-audio-play-btn.playing {
  animation: audio-pulse 1.5s ease-in-out infinite;
  box-shadow: 0 0 20px rgba(251, 191, 36, 0.8), 0 0 40px rgba(251, 191, 36, 0.6);
}

@keyframes audio-pulse {
  0%, 100% { transform: scale(1); box-shadow: 0 4px 15px rgba(251, 191, 36, 0.4); }
  50% { transform: scale(1.1); box-shadow: 0 0 25px rgba(251, 191, 36, 0.9), 0 0 50px rgba(251, 191, 36, 0.7); }
}

.ifc-back-content { align-items: center; justify-content: center; }

.ifc-back-content .ifc-back-row { 
  display: flex; flex-direction: column; align-items: center; justify-content: center; width: 95%; 
  margin: 8px 0; font-size: 20px; gap: 10px; animation: slide-in 0.5s ease-out;
}

@keyframes slide-in {
  from { opacity: 0; transform: translateX(-20px); }
  to { opacity: 1; transform: translateX(0); }
}

.ifc-back-content .ifc-back-row span { flex-grow: 1; text-align: center; font-weight: 600; }

.ifc-main-content { display: flex; flex-direction: column; position: relative; z-index: 1; }

.ifc-nav-btns { 
  display: flex; justify-content: space-around; align-items: center; 
  margin-top: 25px; flex-shrink: 0; gap: 12px; flex-wrap: wrap;
}

.ifc-btn { 
  padding: 12px 24px; border: none; border-radius: var(--ifc-radius-sm); 
  cursor: pointer; font-size: 15px; font-weight: 700; 
  transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55); 
  display: inline-flex; align-items: center; justify-content: center; gap: 8px; 
  font-family: 'Poppins', sans-serif; 
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15); 
  background: rgba(255, 255, 255, 0.15);
  background: var(--ifc-glass-bg); backdrop-filter: blur(var(--ifc-blur)); 
  -webkit-backdrop-filter: blur(var(--ifc-blur)); 
  border: 1px solid var(--ifc-glass-border); color: #fff;
  position: relative; overflow: hidden; min-width: 44px; min-height: 44px;
}

.ifc-btn::before {
  content: ''; position: absolute; top: 50%; left: 50%; 
  width: 0; height: 0; border-radius: 50%; 
  background: rgba(255, 255, 255, 0.3); 
  transform: translate(-50%, -50%); transition: width 0.6s, height 0.6s;
}

.ifc-btn:hover { 
  transform: scale(1.05) translateY(-2px); 
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3); 
  background: rgba(255, 255, 255, 0.25);
}

.ifc-btn:active { 
  transform: scale(0.98); 
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.ifc-btn:active::before {
  width: 200%; height: 200%; transition: width 0s, height 0s;
}

.ifc-btn:disabled { 
  opacity: 0.4; cursor: not-allowed; transform: none; 
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.ifc-btn svg { width: 22px; height: 22px; fill: currentColor; }

.ifc-btn-text { display: none; }

.ifc-prev, .ifc-next { 
  background: linear-gradient(135deg, #3b82f6, #2563eb); 
  color: white; border: 1px solid rgba(59, 130, 246, 0.3);
}

.ifc-flip { 
  background: linear-gradient(135deg, #f59e0b, #d97706); 
  color: white; border: 1px solid rgba(245, 158, 11, 0.3);
}

.ifc-quiz, .ifc-quiz-restart { 
  background: linear-gradient(135deg, var(--ifc-primary), var(--ifc-secondary)); 
  color: white; border: 1px solid rgba(102, 126, 234, 0.3);
}

.ifc-quiz-exit, .ifc-back-flashcard, .ifc-quiz-filter-toggle { 
  background: linear-gradient(135deg, #64748b, #475569); 
  color: white; border: 1px solid rgba(100, 116, 139, 0.3);
}

.ifc-quiz-exit, .ifc-quiz-filter-toggle { padding: 10px 18px; }

.ifc-quiz-next { 
  background: linear-gradient(135deg, var(--ifc-success), #22c55e); 
  color: white; border: 1px solid rgba(74, 222, 128, 0.3);
}

.ifc-keyboard-hint { 
  text-align: center; margin-top: 12px; font-size: 12px; color: rgba(255, 255, 255, 0.6); 
  opacity: 0.7; font-weight: 400; letter-spacing: 0.3px;
}

.ifc-quiz-mode, .ifc-quiz-results { 
  padding: 20px; position: relative; z-index: 2; display: flex; flex-direction: column;
  animation: fade-in 0.5s ease-out;
}

@keyframes fade-in {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.ifc-quiz-header { 
  display: flex; justify-content: space-between; align-items: center; 
  margin-bottom: 15px; gap: 10px; flex-wrap: wrap;
}

.ifc-quiz-progress { 
  font-size: 15px; font-weight: 700; 
  background: var(--ifc-glass-bg); backdrop-filter: blur(var(--ifc-blur)); 
  -webkit-backdrop-filter: blur(var(--ifc-blur));
  padding: 8px 16px; border-radius: var(--ifc-radius); color: #fff; 
  white-space: nowrap; box-shadow: var(--ifc-shadow); 
  border: 1px solid var(--ifc-glass-border); position: relative; overflow: hidden;
}

.ifc-quiz-progress::before {
  content: ''; position: absolute; left: 0; bottom: 0; height: 3px; 
  background: linear-gradient(90deg, var(--ifc-primary), var(--ifc-accent)); 
  width: 0%; transition: width 0.5s ease;
}

.ifc-quiz-filter-wrap { position: relative; }

.ifc-quiz-filter-options { 
  position: absolute; top: 100%; right: 0; 
  background: var(--ifc-glass-bg); backdrop-filter: blur(15px); 
  -webkit-backdrop-filter: blur(15px);
  border: 1px solid var(--ifc-glass-border); border-radius: var(--ifc-radius-sm); 
  padding: 15px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25); 
  z-index: 100; min-width: 220px; margin-top: 8px;
  animation: dropdown-slide 0.3s ease-out;
}

@keyframes dropdown-slide {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.ifc-quiz-filter-options label { 
  display: block; padding: 10px 14px; cursor: pointer; border-radius: 10px; 
  transition: all 0.2s ease; color: #fff; font-weight: 600; font-size: 14px;
}

.ifc-quiz-filter-options label:hover { 
  background: rgba(255, 255, 255, 0.2); transform: translateX(4px);
}

.quiz-question-image { 
  max-width: 100%; max-height: 220px; border-radius: var(--ifc-radius-sm); 
  object-fit: contain; margin-bottom: 15px; 
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.ifc-quiz-question { 
  font-size: 20px; margin-bottom: 20px; padding: 20px; 
  background: var(--ifc-glass-bg); backdrop-filter: blur(15px); 
  -webkit-backdrop-filter: blur(15px);
  border-radius: var(--ifc-radius); text-align: center; color: #fff; 
  border: 2px solid var(--ifc-glass-border); min-height: 160px; 
  display: flex; align-items: center; justify-content: center; flex-direction: column;
  box-shadow: var(--ifc-shadow); font-weight: 600;
}

.ifc-quiz-question span { 
  display: block; max-width: 100%; word-break: break-word; letter-spacing: 0.3px;
}

.ifc-quiz-options { 
  display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0;
}

.ifc-quiz-option { 
  padding: 14px 18px; border: 2px solid var(--ifc-glass-border); 
  border-radius: var(--ifc-radius-sm); 
  background: var(--ifc-glass-bg); backdrop-filter: blur(var(--ifc-blur)); 
  -webkit-backdrop-filter: blur(var(--ifc-blur));
  cursor: pointer; font-size: 15px; text-align: left; 
  transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55); 
  font-weight: 600; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); 
  display: flex; align-items: center; gap: 12px; color: #fff;
  opacity: 0; animation: option-appear 0.4s ease-out forwards;
}

.ifc-quiz-option:nth-child(1) { animation-delay: 0.1s; }
.ifc-quiz-option:nth-child(2) { animation-delay: 0.2s; }
.ifc-quiz-option:nth-child(3) { animation-delay: 0.3s; }
.ifc-quiz-option:nth-child(4) { animation-delay: 0.4s; }

@keyframes option-appear {
  from { opacity: 0; transform: translateY(20px) scale(0.9); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

.ifc-quiz-option:hover { 
  transform: translateY(-5px) scale(1.02); 
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2); 
  background: rgba(255, 255, 255, 0.25); border-color: rgba(255, 255, 255, 0.5);
}

.quiz-option-number { 
  background: linear-gradient(135deg, var(--ifc-warning), #f59e0b); 
  color: white; border-radius: 50%; width: 32px; height: 32px; 
  display: inline-flex; align-items: center; justify-content: center; 
  font-weight: bold; flex-shrink: 0; 
  box-shadow: 0 2px 8px rgba(251, 191, 36, 0.4);
}

.ifc-quiz-option.correct { 
  border-color: var(--ifc-success) !important; 
  background: linear-gradient(135deg, rgba(74, 222, 128, 0.3), rgba(34, 197, 94, 0.3)) !important; 
  color: #fff !important; animation: correct-bounce 0.6s ease-out;
}

@keyframes correct-bounce {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.1); }
}

.ifc-quiz-option.wrong { 
  border-color: var(--ifc-error); 
  background: linear-gradient(135deg, rgba(248, 113, 113, 0.3), rgba(239, 68, 68, 0.3)); 
  color: #fff; animation: shake 0.5s ease-out;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-10px); }
  75% { transform: translateX(10px); }
}

.ifc-quiz-option:disabled { cursor: not-allowed; }

.ifc-quiz-option:disabled:not(.correct) { 
  background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.5); 
  opacity: 0.6;
}

.ifc-quiz-results { animation: results-appear 0.6s ease-out; }

@keyframes results-appear {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}

.ifc-quiz-results h3 { 
  text-align: center; font-size: 36px; color: #fff; margin-bottom: 25px; 
  text-shadow: 0 2px 15px rgba(0, 0, 0, 0.3); font-weight: 700; 
  letter-spacing: 1px;
}

.ifc-summary { display: flex; gap: 20px; justify-content: center; margin: 25px 0; flex-wrap: wrap; }

.ifc-stat { 
  padding: 18px 30px; border-radius: var(--ifc-radius-sm); font-size: 18px; 
  font-weight: 700; display: flex; align-items: center; gap: 12px; 
  box-shadow: var(--ifc-shadow); backdrop-filter: blur(var(--ifc-blur)); 
  -webkit-backdrop-filter: blur(var(--ifc-blur));
  border: 1px solid var(--ifc-glass-border);
}

.ifc-stat.correct { 
  background: linear-gradient(135deg, rgba(74, 222, 128, 0.3), rgba(34, 197, 94, 0.3)); 
  color: #fff;
}

.ifc-stat.wrong { 
  background: linear-gradient(135deg, rgba(248, 113, 113, 0.3), rgba(239, 68, 68, 0.3)); 
  color: #fff;
}

.ifc-stat svg { width: 28px; height: 28px; fill: currentColor; }

.ifc-results-actions { 
  display: flex; justify-content: center; gap: 15px; margin-top: 25px; flex-wrap: wrap;
}

.ifc-result-popup { 
  position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
  display: flex; align-items: center; justify-content: center; 
  background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); 
  -webkit-backdrop-filter: blur(4px); z-index: 10000;
}

.ifc-result-popup-content { animation: popup-scale 0.3s ease-out forwards; }

.ifc-result-popup-icon { 
  font-size: 160px; line-height: 1; display: flex; align-items: center; 
  justify-content: center; width: 220px; height: 220px; border-radius: 50%; 
  color: white; font-weight: 700; 
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.ifc-result-popup.correct .ifc-result-popup-icon { 
  background: linear-gradient(135deg, var(--ifc-success), #22c55e); 
  animation: popup-correct-icon 1.2s ease-out forwards;
}

.ifc-result-popup.wrong .ifc-result-popup-icon { 
  background: linear-gradient(135deg, var(--ifc-error), #ef4444); 
  animation: popup-wrong-icon 1.2s ease-out forwards;
}

@keyframes popup-scale { 
  from { transform: scale(0.5); opacity: 0; } 
  to { transform: scale(1); opacity: 1; } 
}

@keyframes popup-correct-icon { 
  0% { transform: scale(0.5); opacity: 0; } 
  30% { transform: scale(1.2); opacity: 1; } 
  50% { transform: scale(1); } 
  100% { opacity: 0; transform: scale(1.5); } 
}

@keyframes popup-wrong-icon { 
  0%, 20%, 40%, 60%, 80%, 100% { transform: translateX(0); } 
  10%, 50% { transform: translateX(-15px); } 
  30%, 70% { transform: translateX(15px); } 
  0% { opacity: 0; } 
  10% { opacity: 1; } 
  90% { opacity: 1; } 
  100% { opacity: 0; } 
}

.ifc-confetti { 
  position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
  pointer-events: none; z-index: 9999;
}

.confetti-piece { 
  position: absolute; width: 10px; height: 10px; 
  background: var(--color); opacity: 0; 
  animation: confetti-fall var(--duration) ease-out forwards;
}

@keyframes confetti-fall {
  0% { transform: translate3d(0, 0, 0) rotate(0deg); opacity: 1; }
  100% { transform: translate3d(0, 1000px, 0) rotate(720deg); opacity: 0; }
}

@media(max-width: 500px) { 
  .ifc-wrap { border-radius: 0; margin: 0; padding: 20px; }
  .ifc-title { font-size: 18px; }
  .ifc-header { flex-wrap: wrap; justify-content: center; gap: 10px; }
  .ifc-quiz-options { grid-template-columns: 1fr; }
  .ifc-quiz-option { padding: 12px 16px; }
  .ifc-btn { padding: 10px 18px; font-size: 14px; }
  .ifc-card { height: 300px; }
}
EOD
        ];

        foreach ($files_content as $filename => $content) {
            $filepath = __DIR__ . '/' . $filename;
            if (!file_exists(dirname($filepath))) { mkdir(dirname($filepath), 0755, true); }
            file_put_contents($filepath, $content);
        }
    }
}

new ImageFlashcardLearning();
?>