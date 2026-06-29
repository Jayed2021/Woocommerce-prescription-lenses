<?php
/**
 * Plugin Name: WooCommerce Prescription Lens Options
 * Description: Adds a modern prescription lens selection flow for WooCommerce eyewear stores.
 * Version: 0.5.3
 * Author: Codex
 * Text Domain: wc-prescription-lens-options
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_Prescription_Lens_Options
{
    private const VERSION = '0.5.3';
    private const OPTION = 'wclo_settings';
    private const POST_TYPE = 'wclo_lens_package';
    private const PRODUCT_OVERRIDE = '_wclo_lens_override';
    private const PRODUCT_FRAME_TYPE = '_wclo_frame_type';
    private const CART_KEY = 'wclo_lens_selection';
    private const MENU_SLUG = 'wclo-dashboard';
    private const ACCOUNT_ENDPOINT = 'prescriptions';
    private const CLEANUP_HOOK = 'wclo_cleanup_prescription_files';

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_lens_package_post_type']);
        add_action('init', [self::class, 'register_account_endpoint']);
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
        add_action('wp_enqueue_scripts', [self::class, 'frontend_assets']);
        add_action('add_meta_boxes', [self::class, 'add_lens_package_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'save_lens_package_meta']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'lens_package_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'render_lens_package_column'], 10, 2);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_product_options_general_product_data', [self::class, 'render_product_fields']);
            add_action('woocommerce_admin_process_product_object', [self::class, 'save_product_fields']);
            add_action('woocommerce_after_add_to_cart_button', [self::class, 'render_lens_selector']);
            add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_lens_selection'], 10, 3);
            add_filter('woocommerce_add_cart_item_data', [self::class, 'add_cart_item_data'], 10, 3);
            add_action('woocommerce_add_to_cart', [self::class, 'add_linked_add_on_products_to_cart'], 20, 6);
            add_action('woocommerce_before_calculate_totals', [self::class, 'apply_cart_item_prices']);
            add_filter('woocommerce_get_item_data', [self::class, 'render_cart_item_data'], 10, 2);
            add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_order_item_data'], 10, 4);
            add_action('woocommerce_after_order_itemmeta', [self::class, 'render_admin_prescription_file_link'], 10, 3);
            add_filter('woocommerce_account_menu_items', [self::class, 'account_menu_items']);
            add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', [self::class, 'render_account_prescriptions']);
            add_action('wp_ajax_wclo_scan_prescription', [self::class, 'scan_prescription_ajax']);
            add_action('wp_ajax_nopriv_wclo_scan_prescription', [self::class, 'scan_prescription_ajax']);
        } else {
            add_action('admin_notices', [self::class, 'missing_woocommerce_notice']);
        }

        add_action(self::CLEANUP_HOOK, [self::class, 'cleanup_old_prescription_files']);
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function activate(): void
    {
        self::register_lens_package_post_type();
        self::register_account_endpoint();
        if (!get_option(self::OPTION)) {
            update_option(self::OPTION, self::default_settings());
        }
        self::seed_default_packages();
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        flush_rewrite_rules();
    }

    public static function register_lens_package_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Lens Packages', 'wc-prescription-lens-options'),
                'singular_name' => __('Lens Package', 'wc-prescription-lens-options'),
                'add_new_item' => __('Add Lens Package', 'wc-prescription-lens-options'),
                'edit_item' => __('Edit Lens Package', 'wc-prescription-lens-options'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => self::MENU_SLUG,
            'menu_position' => 57,
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }

    public static function register_admin_menu(): void
    {
        add_menu_page(
            __('Lens Options', 'wc-prescription-lens-options'),
            __('Lens Options', 'wc-prescription-lens-options'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [self::class, 'render_dashboard_page'],
            'dashicons-visibility',
            56
        );

        add_submenu_page(self::MENU_SLUG, __('Dashboard', 'wc-prescription-lens-options'), __('Dashboard', 'wc-prescription-lens-options'), 'manage_woocommerce', self::MENU_SLUG, [self::class, 'render_dashboard_page']);
        add_submenu_page(self::MENU_SLUG, __('Prescription Methods', 'wc-prescription-lens-options'), __('Prescription Methods', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-prescription-methods', [self::class, 'render_prescription_methods_page']);
        add_submenu_page(self::MENU_SLUG, __('Add-ons', 'wc-prescription-lens-options'), __('Add-ons', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-add-ons', [self::class, 'render_add_ons_page']);
        add_submenu_page(self::MENU_SLUG, __('Display Rules', 'wc-prescription-lens-options'), __('Display Rules', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-display-rules', [self::class, 'render_display_rules_page']);
        add_submenu_page(self::MENU_SLUG, __('Text & Translation', 'wc-prescription-lens-options'), __('Text & Translation', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-text', [self::class, 'render_text_page']);
        add_submenu_page(self::MENU_SLUG, __('Orders / Prescriptions', 'wc-prescription-lens-options'), __('Orders / Prescriptions', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-prescriptions', [self::class, 'render_prescriptions_page']);
        add_submenu_page(self::MENU_SLUG, __('Settings', 'wc-prescription-lens-options'), __('Settings', 'wc-prescription-lens-options'), 'manage_woocommerce', 'wclo-settings', [self::class, 'render_settings_page']);
    }

    public static function admin_assets(string $hook): void
    {
        if (strpos($hook, 'wclo') === false && get_post_type() !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_style('wclo-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('wclo-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], self::VERSION, true);

        if (class_exists('WooCommerce')) {
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
        }
    }

    public static function frontend_assets(): void
    {
        wp_register_style('wclo-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], self::VERSION);
        wp_register_script('wclo-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', [], self::VERSION, true);
    }

    public static function missing_woocommerce_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('WooCommerce Prescription Lens Options requires WooCommerce to be active.', 'wc-prescription-lens-options') . '</p></div>';
    }

    public static function add_lens_package_meta_boxes(): void
    {
        add_meta_box('wclo-package-details', __('Lens Package Details', 'wc-prescription-lens-options'), [self::class, 'render_lens_package_meta_box'], self::POST_TYPE, 'normal', 'high');
    }

    public static function render_lens_package_meta_box(WP_Post $post): void
    {
        wp_nonce_field('wclo_save_package', 'wclo_package_nonce');
        $meta = self::package_meta($post->ID);
        ?>
        <div class="wclo-grid">
            <?php self::text_field('price', __('Price', 'wc-prescription-lens-options'), $meta['price'], 'number', '0.01'); ?>
            <?php self::select_field('type', __('Lens type', 'wc-prescription-lens-options'), $meta['type'], [
                'clear' => __('Clear lens', 'wc-prescription-lens-options'),
                'blue_cut' => __('Blue cut lens', 'wc-prescription-lens-options'),
                'photochromic' => __('Photochromic lens', 'wc-prescription-lens-options'),
                'sunglass' => __('Sunglass lens', 'wc-prescription-lens-options'),
            ]); ?>
            <?php self::text_field('index', __('Internal lens index', 'wc-prescription-lens-options'), $meta['index']); ?>
            <?php self::select_field('availability', __('Available for', 'wc-prescription-lens-options'), $meta['availability'], [
                'both' => __('Eyeglasses and sunglasses', 'wc-prescription-lens-options'),
                'eyeglass' => __('Eyeglasses only', 'wc-prescription-lens-options'),
                'sunglass' => __('Sunglasses only', 'wc-prescription-lens-options'),
            ]); ?>
            <?php self::select_field('requires_prescription', __('Requires prescription', 'wc-prescription-lens-options'), $meta['requires_prescription'], [
                'yes' => __('Yes', 'wc-prescription-lens-options'),
                'no' => __('No', 'wc-prescription-lens-options'),
            ]); ?>
            <?php self::select_field('recommended', __('Recommended badge', 'wc-prescription-lens-options'), $meta['recommended'], [
                'no' => __('No', 'wc-prescription-lens-options'),
                'yes' => __('Yes', 'wc-prescription-lens-options'),
            ]); ?>
            <?php self::select_field('active', __('Active', 'wc-prescription-lens-options'), $meta['active'], [
                'yes' => __('Yes', 'wc-prescription-lens-options'),
                'no' => __('No', 'wc-prescription-lens-options'),
            ]); ?>
            <?php self::text_field('sort_order', __('Sort order', 'wc-prescription-lens-options'), $meta['sort_order'], 'number', '1'); ?>
        </div>
        <p>
            <label for="wclo_description"><strong><?php esc_html_e('Customer description', 'wc-prescription-lens-options'); ?></strong></label>
            <textarea id="wclo_description" name="wclo_description" rows="3" class="large-text"><?php echo esc_textarea($meta['description']); ?></textarea>
        </p>
        <p>
            <label for="wclo_included"><strong><?php esc_html_e('Included benefits/coatings', 'wc-prescription-lens-options'); ?></strong></label>
            <textarea id="wclo_included" name="wclo_included" rows="2" class="large-text" placeholder="<?php esc_attr_e('Anti-reflective, UV protection, Scratch resistant', 'wc-prescription-lens-options'); ?>"><?php echo esc_textarea($meta['included']); ?></textarea>
        </p>
        <p>
            <label for="wclo_color_options"><strong><?php esc_html_e('Lens color choices', 'wc-prescription-lens-options'); ?></strong></label>
            <textarea id="wclo_color_options" name="wclo_color_options" rows="2" class="large-text" placeholder="<?php esc_attr_e('Gray, Brown, Green', 'wc-prescription-lens-options'); ?>"><?php echo esc_textarea($meta['color_options']); ?></textarea>
            <span class="description"><?php esc_html_e('Optional. Use one color per comma for sunglass, tinted, or color lenses.', 'wc-prescription-lens-options'); ?></span>
        </p>
        <?php self::render_child_lens_options_repeater($meta['child_options']); ?>
        <?php
    }

    private static function render_child_lens_options_repeater(array $options): void
    {
        $rows = $options ?: [[]];
        ?>
        <div class="wclo-repeater" data-wclo-repeater="child-options">
            <div class="wclo-repeater-heading">
                <div>
                    <h3><?php esc_html_e('Child lens options', 'wc-prescription-lens-options'); ?></h3>
                    <p><?php esc_html_e('Use these fields for Multicoated, Hardcoated, branded blue cut lenses, and similar child choices. Leave empty if this package should be selected directly.', 'wc-prescription-lens-options'); ?></p>
                </div>
                <button type="button" class="button" data-wclo-add-row><?php esc_html_e('Add child lens', 'wc-prescription-lens-options'); ?></button>
            </div>
            <div class="wclo-repeater-rows" data-wclo-rows>
                <?php foreach ($rows as $index => $row) : ?>
                    <?php self::render_child_lens_option_row(is_array($row) ? $row : [], (int) $index); ?>
                <?php endforeach; ?>
            </div>
            <template data-wclo-template>
                <?php self::render_child_lens_option_row([], '__INDEX__'); ?>
            </template>
        </div>
        <?php
    }

    private static function render_child_lens_option_row(array $row, $index): void
    {
        $name = (string) ($row['name'] ?? '');
        $regular = (string) ($row['regular_price'] ?? '');
        $sale = (string) ($row['sale_price'] ?? '');
        $features = (string) ($row['features'] ?? '');
        $details = (string) ($row['description'] ?? '');
        $active = (string) ($row['active'] ?? 'yes');
        $sort = (string) ($row['sort_order'] ?? '10');
        $colors = (string) ($row['color_options'] ?? '');
        ?>
        <div class="wclo-repeater-row">
            <div class="wclo-row-actions">
                <button type="button" class="button-link wclo-move" data-wclo-move="up"><?php esc_html_e('Up', 'wc-prescription-lens-options'); ?></button>
                <button type="button" class="button-link wclo-move" data-wclo-move="down"><?php esc_html_e('Down', 'wc-prescription-lens-options'); ?></button>
                <button type="button" class="button-link-delete" data-wclo-remove-row><?php esc_html_e('Remove', 'wc-prescription-lens-options'); ?></button>
            </div>
            <div class="wclo-row-grid">
                <label><?php esc_html_e('Name', 'wc-prescription-lens-options'); ?><input type="text" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php esc_attr_e('Multicoated', 'wc-prescription-lens-options'); ?>"></label>
                <label><?php esc_html_e('Regular price', 'wc-prescription-lens-options'); ?><input type="number" step="0.01" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][regular_price]" value="<?php echo esc_attr($regular); ?>"></label>
                <label><?php esc_html_e('Sale price', 'wc-prescription-lens-options'); ?><input type="number" step="0.01" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][sale_price]" value="<?php echo esc_attr($sale); ?>"></label>
                <label><?php esc_html_e('Active', 'wc-prescription-lens-options'); ?><select name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][active]"><option value="yes"<?php selected($active, 'yes'); ?>><?php esc_html_e('Yes', 'wc-prescription-lens-options'); ?></option><option value="no"<?php selected($active, 'no'); ?>><?php esc_html_e('No', 'wc-prescription-lens-options'); ?></option></select></label>
                <label><?php esc_html_e('Sort order', 'wc-prescription-lens-options'); ?><input type="number" step="1" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][sort_order]" value="<?php echo esc_attr($sort); ?>"></label>
                <label><?php esc_html_e('Color choices', 'wc-prescription-lens-options'); ?><input type="text" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][color_options]" value="<?php echo esc_attr($colors); ?>" placeholder="<?php esc_attr_e('Gray, Brown', 'wc-prescription-lens-options'); ?>"></label>
                <label class="wclo-wide"><?php esc_html_e('Feature bullets', 'wc-prescription-lens-options'); ?><textarea rows="2" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][features]" placeholder="<?php esc_attr_e('Anti-reflective, UV protection', 'wc-prescription-lens-options'); ?>"><?php echo esc_textarea($features); ?></textarea></label>
                <label class="wclo-wide"><?php esc_html_e('Details popup text', 'wc-prescription-lens-options'); ?><textarea rows="2" name="wclo_child_options[<?php echo esc_attr((string) $index); ?>][description]"><?php echo esc_textarea($details); ?></textarea></label>
            </div>
        </div>
        <?php
    }

    public static function save_lens_package_meta(int $post_id): void
    {
        if (!isset($_POST['wclo_package_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wclo_package_nonce'])), 'wclo_save_package')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = ['price', 'type', 'index', 'availability', 'requires_prescription', 'recommended', 'active', 'sort_order', 'description', 'included', 'color_options'];
        foreach ($fields as $field) {
            $key = 'wclo_' . $field;
            $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $value = in_array($field, ['description', 'included', 'color_options'], true) ? sanitize_textarea_field($value) : sanitize_text_field($value);
            update_post_meta($post_id, '_' . $key, $value);
        }

        $child_options = isset($_POST['wclo_child_options']) && is_array($_POST['wclo_child_options'])
            ? self::sanitize_child_options(wp_unslash($_POST['wclo_child_options']))
            : [];
        update_post_meta($post_id, '_wclo_child_options', $child_options);
    }

    public static function lens_package_columns(array $columns): array
    {
        $columns['wclo_price'] = __('Price', 'wc-prescription-lens-options');
        $columns['wclo_type'] = __('Type', 'wc-prescription-lens-options');
        $columns['wclo_active'] = __('Active', 'wc-prescription-lens-options');
        return $columns;
    }

    public static function render_lens_package_column(string $column, int $post_id): void
    {
        $meta = self::package_meta($post_id);
        if ($column === 'wclo_price') {
            echo wp_kses_post(function_exists('wc_price') ? wc_price((float) $meta['price']) : (string) $meta['price']);
        } elseif ($column === 'wclo_type') {
            echo esc_html($meta['type']);
        } elseif ($column === 'wclo_active') {
            echo esc_html($meta['active']);
        }
    }

    public static function render_product_fields(): void
    {
        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => self::PRODUCT_OVERRIDE,
            'label' => __('Lens options', 'wc-prescription-lens-options'),
            'options' => [
                'global' => __('Use global display rules', 'wc-prescription-lens-options'),
                'yes' => __('Force enable', 'wc-prescription-lens-options'),
                'no' => __('Force disable', 'wc-prescription-lens-options'),
            ],
            'desc_tip' => true,
            'description' => __('Controls whether the Select Lens button appears on this product.', 'wc-prescription-lens-options'),
        ]);
        woocommerce_wp_select([
            'id' => self::PRODUCT_FRAME_TYPE,
            'label' => __('Frame type', 'wc-prescription-lens-options'),
            'options' => [
                'eyeglass' => __('Eyeglass', 'wc-prescription-lens-options'),
                'sunglass' => __('Sunglass', 'wc-prescription-lens-options'),
            ],
        ]);
        echo '</div>';
    }

    public static function save_product_fields(WC_Product $product): void
    {
        $override = isset($_POST[self::PRODUCT_OVERRIDE]) ? sanitize_key(wp_unslash($_POST[self::PRODUCT_OVERRIDE])) : 'global';
        $frame_type = isset($_POST[self::PRODUCT_FRAME_TYPE]) ? sanitize_key(wp_unslash($_POST[self::PRODUCT_FRAME_TYPE])) : 'eyeglass';
        $product->update_meta_data(self::PRODUCT_OVERRIDE, in_array($override, ['global', 'yes', 'no'], true) ? $override : 'global');
        $product->update_meta_data(self::PRODUCT_FRAME_TYPE, in_array($frame_type, ['eyeglass', 'sunglass'], true) ? $frame_type : 'eyeglass');
    }

    public static function render_lens_selector(): void
    {
        global $product;
        if (!$product instanceof WC_Product || !self::product_is_eligible($product)) {
            return;
        }

        $settings = self::settings();
        if ($settings['enabled'] !== 'yes') {
            return;
        }

        $packages = self::frontend_packages((string) $product->get_meta(self::PRODUCT_FRAME_TYPE, true));
        if (!$packages) {
            return;
        }

        wp_enqueue_style('wclo-frontend');
        wp_enqueue_script('wclo-frontend');

        $payload = [
            'productId' => $product->get_id(),
            'productName' => $product->get_name(),
            'basePrice' => (float) wc_get_price_to_display($product),
            'currency' => html_entity_decode(get_woocommerce_currency_symbol()),
            'settings' => [
                'primaryColor' => $settings['primary_color'],
                'whatsappNumber' => $settings['whatsapp_number'],
                'allowScan' => $settings['method_scan'],
                'ocrProvider' => $settings['ocr_provider'],
                'allowUploads' => $settings['method_upload'],
                'allowManual' => $settings['method_manual'],
                'allowWhatsapp' => $settings['method_whatsapp'],
                'allowFrameOnly' => $settings['allow_frame_only'],
                'submitCloseDelay' => max(200, min(10000, (int) $settings['submit_close_delay'])),
            ],
            'ajax' => [
                'url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wclo_scan_prescription'),
            ],
            'text' => self::frontend_text($settings),
            'packages' => $packages,
            'addOns' => self::frontend_add_ons($settings),
        ];
        $style = sprintf(
            '--wclo-accent:%s;--wclo-button-bg:%s;--wclo-button-text:%s;--wclo-radius:%dpx;--wclo-width:%dpx;--wclo-desc-size:%dpx;',
            esc_attr(sanitize_hex_color($settings['primary_color']) ?: '#007c89'),
            esc_attr(sanitize_hex_color($settings['button_color']) ?: '#050808'),
            esc_attr(sanitize_hex_color($settings['button_text_color']) ?: '#ffffff'),
            max(0, min(40, (int) $settings['modal_radius'])),
            max(320, min(1400, (int) $settings['modal_width'])),
            max(10, min(18, (int) $settings['description_font_size']))
        );
        ?>
        <div class="wclo-selector<?php echo $settings['modal_fullscreen'] === 'yes' ? ' wclo-selector-fullscreen' : ''; ?>" data-wclo-root data-wclo-config="<?php echo esc_attr(wp_json_encode($payload)); ?>" style="<?php echo esc_attr($style); ?>">
            <input type="hidden" name="wclo_lens_payload" data-wclo-payload value="">
            <input type="file" name="wclo_prescription_file" data-wclo-prescription-file accept="image/*,application/pdf,.jpg,.jpeg,.png,.pdf" capture="environment" hidden>
            <button type="button" class="button wclo-open-button" data-wclo-open>
                <?php echo esc_html($settings['button_text']); ?>
            </button>
            <p class="wclo-inline-help"><?php echo esc_html($settings['button_help']); ?></p>
            <div class="wclo-summary" data-wclo-summary hidden></div>
            <div class="wclo-modal" data-wclo-modal hidden></div>
        </div>
        <?php
    }

    public static function validate_lens_selection(bool $passed, int $product_id, int $quantity): bool
    {
        $product = wc_get_product($product_id);
        $settings = self::settings();
        if (!$product instanceof WC_Product || !self::product_is_eligible($product) || $settings['require_lens_selection'] !== 'yes') {
            return $passed;
        }

        $payload = isset($_POST['wclo_lens_payload']) ? trim((string) wp_unslash($_POST['wclo_lens_payload'])) : '';
        if ($payload === '') {
            wc_add_notice(__('Please select lens options before adding this frame to cart.', 'wc-prescription-lens-options'), 'error');
            return false;
        }

        return $passed;
    }

    public static function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id): array
    {
        if (!empty($cart_item_data['_wclo_is_lens_addon'])) {
            return $cart_item_data;
        }

        $payload = isset($_POST['wclo_lens_payload']) ? json_decode((string) wp_unslash($_POST['wclo_lens_payload']), true) : null;
        if (!is_array($payload) || empty($payload['usage'])) {
            return $cart_item_data;
        }

        $selection = self::sanitize_selection($payload);
        if ($selection['usage'] === 'frame_only') {
            $cart_item_data[self::CART_KEY] = $selection;
            $product = wc_get_product($variation_id ?: $product_id);
            if ($product instanceof WC_Product) {
                $cart_item_data['wclo_base_price'] = (float) $product->get_price('edit');
            }
            $cart_item_data['unique_key'] = md5(wp_json_encode($selection) . microtime());
            return $cart_item_data;
        }

        if (!empty($_FILES['wclo_prescription_file']['name'])) {
            $upload = self::handle_prescription_upload($_FILES['wclo_prescription_file']);
            if ($upload) {
                $selection['prescription_file_url'] = $upload['url'];
                $selection['prescription_file_path'] = $upload['path'];
                $selection['prescription_file_name'] = $upload['name'];
            }
        }

        $cart_item_data[self::CART_KEY] = $selection;
        $product = wc_get_product($variation_id ?: $product_id);
        if ($product instanceof WC_Product) {
            $cart_item_data['wclo_base_price'] = (float) $product->get_price('edit');
        }
        $cart_item_data['unique_key'] = md5(wp_json_encode($selection) . microtime());
        return $cart_item_data;
    }

    public static function apply_cart_item_prices(WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['_wclo_is_lens_addon'])) {
                continue;
            }

            if (empty($cart_item[self::CART_KEY]) || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
                continue;
            }

            $selection = $cart_item[self::CART_KEY];
            $base_price = isset($cart_item['wclo_base_price'])
                ? (float) $cart_item['wclo_base_price']
                : (float) $cart_item['data']->get_price('edit');
            $cart_item['data']->set_price($base_price + (float) ($selection['price_delta'] ?? 0));
        }
    }

    public static function add_linked_add_on_products_to_cart(string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data): void
    {
        if (!empty($cart_item_data['_wclo_is_lens_addon']) || empty($cart_item_data[self::CART_KEY]['add_ons']) || !WC()->cart) {
            return;
        }

        foreach ($cart_item_data[self::CART_KEY]['add_ons'] as $add_on) {
            $addon_product_id = absint($add_on['product_id'] ?? 0);
            if ($addon_product_id <= 0) {
                continue;
            }
            WC()->cart->add_to_cart($addon_product_id, max(1, $quantity), 0, [], [
                '_wclo_is_lens_addon' => 'yes',
                '_wclo_addon_for' => $cart_item_key,
                '_wclo_addon_label' => sanitize_text_field((string) ($add_on['name'] ?? '')),
            ]);
        }
    }

    public static function render_cart_item_data(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['_wclo_is_lens_addon'])) {
            $item_data[] = [
                'key' => __('Lens add-on', 'wc-prescription-lens-options'),
                'value' => wc_clean((string) ($cart_item['_wclo_addon_label'] ?? __('Linked to lens selection', 'wc-prescription-lens-options'))),
            ];
            return $item_data;
        }

        if (empty($cart_item[self::CART_KEY])) {
            return $item_data;
        }

        foreach (self::selection_rows($cart_item[self::CART_KEY]) as $label => $value) {
            if ($value !== '') {
                $item_data[] = ['key' => $label, 'value' => wc_clean($value)];
            }
        }

        return $item_data;
    }

    public static function save_order_item_data(WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order): void
    {
        if (!empty($values['_wclo_is_lens_addon'])) {
            $item->add_meta_data('_wclo_is_lens_addon', 'yes', true);
            $item->add_meta_data('_wclo_addon_for', sanitize_text_field((string) ($values['_wclo_addon_for'] ?? '')), true);
            if (!empty($values['_wclo_addon_label'])) {
                $item->add_meta_data(__('Lens add-on label', 'wc-prescription-lens-options'), sanitize_text_field((string) $values['_wclo_addon_label']), true);
            }
            return;
        }

        if (empty($values[self::CART_KEY])) {
            return;
        }

        foreach (self::selection_rows($values[self::CART_KEY]) as $label => $value) {
            if ($value !== '') {
                $item->add_meta_data($label, $value, true);
            }
        }
        if (!empty($values[self::CART_KEY]['prescription_file_path'])) {
            $item->add_meta_data('_wclo_prescription_file_path', sanitize_text_field((string) $values[self::CART_KEY]['prescription_file_path']), true);
        }
        $item->add_meta_data('_wclo_selection', wp_json_encode($values[self::CART_KEY]), true);
    }

    public static function render_admin_prescription_file_link(int $item_id, WC_Order_Item $item, $product = null): void
    {
        if (!is_admin() || !$item instanceof WC_Order_Item_Product) {
            return;
        }
        $selection = self::item_selection($item);
        $url = (string) ($selection['prescription_file_url'] ?? $item->get_meta(__('Prescription file URL', 'wc-prescription-lens-options')));
        if ($url === '') {
            return;
        }
        echo '<p class="wclo-order-prescription-file"><strong>' . esc_html__('Prescription file:', 'wc-prescription-lens-options') . '</strong> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Open uploaded file', 'wc-prescription-lens-options') . '</a></p>';
    }

    public static function render_dashboard_page(): void
    {
        self::maybe_save_settings();
        $pending = self::prescription_order_count();
        $packages = wp_count_posts(self::POST_TYPE);
        ?>
        <div class="wrap wclo-admin">
            <h1><?php esc_html_e('Lens Options', 'wc-prescription-lens-options'); ?></h1>
            <div class="wclo-cards">
                <div class="wclo-card"><strong><?php echo esc_html((string) $pending); ?></strong><span><?php esc_html_e('Orders with lens selections', 'wc-prescription-lens-options'); ?></span></div>
                <div class="wclo-card"><strong><?php echo esc_html((string) ($packages->publish ?? 0)); ?></strong><span><?php esc_html_e('Active lens package records', 'wc-prescription-lens-options'); ?></span></div>
                <div class="wclo-card"><strong><?php echo esc_html(self::settings()['enabled'] === 'yes' ? __('On', 'wc-prescription-lens-options') : __('Off', 'wc-prescription-lens-options')); ?></strong><span><?php esc_html_e('Frontend lens selector', 'wc-prescription-lens-options'); ?></span></div>
            </div>
            <p><?php esc_html_e('Use the sidebar sections to manage packages, prescription methods, add-ons, display rules, and customer-facing text.', 'wc-prescription-lens-options'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::POST_TYPE)); ?>"><?php esc_html_e('Add Lens Package', 'wc-prescription-lens-options'); ?></a></p>
        </div>
        <?php
    }

    public static function render_settings_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Settings', 'wc-prescription-lens-options'));
        self::setting_select('enabled', __('Enable plugin', 'wc-prescription-lens-options'), $settings['enabled'], ['yes' => __('Yes', 'wc-prescription-lens-options'), 'no' => __('No', 'wc-prescription-lens-options')]);
        self::setting_text('button_text', __('Button text', 'wc-prescription-lens-options'), $settings['button_text']);
        self::setting_text('button_help', __('Button helper text', 'wc-prescription-lens-options'), $settings['button_help']);
        self::setting_text('primary_color', __('Accent color', 'wc-prescription-lens-options'), $settings['primary_color'], 'color');
        self::setting_text('button_color', __('Primary button color', 'wc-prescription-lens-options'), $settings['button_color'], 'color');
        self::setting_text('button_text_color', __('Primary button text color', 'wc-prescription-lens-options'), $settings['button_text_color'], 'color');
        self::setting_text('modal_radius', __('Modal corner radius in px', 'wc-prescription-lens-options'), $settings['modal_radius'], 'number');
        self::setting_text('modal_width', __('Modal max width in px', 'wc-prescription-lens-options'), $settings['modal_width'], 'number');
        self::setting_select('modal_fullscreen', __('Use full-screen lens flow', 'wc-prescription-lens-options'), $settings['modal_fullscreen'], ['yes' => __('Yes', 'wc-prescription-lens-options'), 'no' => __('No', 'wc-prescription-lens-options')]);
        self::setting_text('description_font_size', __('Card description font size in px', 'wc-prescription-lens-options'), $settings['description_font_size'], 'number');
        self::setting_text('submit_close_delay', __('Loading modal fallback close delay in ms', 'wc-prescription-lens-options'), $settings['submit_close_delay'], 'number');
        self::setting_text('whatsapp_number', __('WhatsApp number', 'wc-prescription-lens-options'), $settings['whatsapp_number']);
        self::setting_select('require_lens_selection', __('Require lens selection on eligible products', 'wc-prescription-lens-options'), $settings['require_lens_selection'], ['yes' => __('Yes', 'wc-prescription-lens-options'), 'no' => __('No', 'wc-prescription-lens-options')]);
        self::setting_select('allow_frame_only', __('Allow frame only option', 'wc-prescription-lens-options'), $settings['allow_frame_only'], ['yes' => __('Yes', 'wc-prescription-lens-options'), 'no' => __('No', 'wc-prescription-lens-options')]);
        self::settings_form_close();
    }

    public static function render_prescription_methods_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Prescription Methods', 'wc-prescription-lens-options'));
        self::setting_select('method_scan', __('Scan prescription upload beta', 'wc-prescription-lens-options'), $settings['method_scan'], ['no' => __('Disabled', 'wc-prescription-lens-options'), 'yes' => __('Enabled', 'wc-prescription-lens-options')]);
        self::setting_select('ocr_provider', __('OCR provider', 'wc-prescription-lens-options'), $settings['ocr_provider'], [
            'disabled' => __('Disabled', 'wc-prescription-lens-options'),
            'ocr_space' => __('OCR.space', 'wc-prescription-lens-options'),
        ]);
        self::setting_text('ocr_space_api_key', __('OCR.space API key', 'wc-prescription-lens-options'), $settings['ocr_space_api_key'], 'password');
        self::setting_text('ocr_language', __('OCR language code', 'wc-prescription-lens-options'), $settings['ocr_language']);
        echo '<p class="description">' . esc_html__('OCR pre-fills prescription fields for customer review. Use OCR.space language codes such as eng. Bengali prescriptions may still need manual entry.', 'wc-prescription-lens-options') . '</p>';
        self::setting_select('method_upload', __('Upload prescription photo/PDF', 'wc-prescription-lens-options'), $settings['method_upload'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_select('method_manual', __('Type prescription manually', 'wc-prescription-lens-options'), $settings['method_manual'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_select('method_whatsapp', __('Send later on WhatsApp', 'wc-prescription-lens-options'), $settings['method_whatsapp'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_text('upload_types', __('Allowed upload extensions', 'wc-prescription-lens-options'), $settings['upload_types']);
        self::setting_text('upload_size_mb', __('Max upload size in MB', 'wc-prescription-lens-options'), $settings['upload_size_mb'], 'number');
        self::setting_select('cleanup_uploads', __('Delete old unattached prescription files', 'wc-prescription-lens-options'), $settings['cleanup_uploads'], ['no' => __('No', 'wc-prescription-lens-options'), 'yes' => __('Yes', 'wc-prescription-lens-options')]);
        self::setting_text('cleanup_days', __('Delete unattached files older than days', 'wc-prescription-lens-options'), $settings['cleanup_days'], 'number');
        echo '<p class="description">' . esc_html__('Uploaded prescriptions are stored under uploads/woocommerce-prescriptions/year/month. Cleanup only removes files that are not referenced by prescription order metadata.', 'wc-prescription-lens-options') . '</p>';
        self::settings_form_close();
    }

    public static function render_add_ons_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Add-ons', 'wc-prescription-lens-options'));
        echo '<tr><th scope="row">' . esc_html__('WooCommerce product add-ons', 'wc-prescription-lens-options') . '</th><td>';
        $product_add_ons = (array) ($settings['product_add_ons'] ?? []);
        if (!$product_add_ons && !empty($settings['product_add_ons_text'])) {
            $product_add_ons = self::legacy_product_add_ons_to_meta((string) $settings['product_add_ons_text']);
        }
        self::render_product_add_ons_repeater($product_add_ons);
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Legacy price add-ons', 'wc-prescription-lens-options') . '</th><td>';
        echo '<p class="description">' . esc_html__('Backwards-compatible rows only. Prefer product add-ons above for clean cart, order, and REST API data. Format: key | Name | Price | Description', 'wc-prescription-lens-options') . '</p>';
        echo '<textarea class="large-text code" rows="6" id="wclo_add_ons_text" name="wclo_settings[add_ons_text]">' . esc_textarea((string) $settings['add_ons_text']) . '</textarea>';
        echo '</td></tr>';
        self::settings_form_close();
    }

    private static function render_product_add_ons_repeater(array $add_ons): void
    {
        $rows = self::sanitize_product_add_ons($add_ons);
        ?>
        <div class="wclo-repeater" data-wclo-repeater="product-addons">
            <input type="hidden" name="wclo_settings[product_add_ons_present]" value="1">
            <div class="wclo-repeater-heading">
                <div>
                    <p><?php esc_html_e('Search by product name, SKU, or ID. Selected add-ons are added as separate WooCommerce cart/order line items.', 'wc-prescription-lens-options'); ?></p>
                </div>
                <button type="button" class="button" data-wclo-add-row><?php esc_html_e('Add product add-on', 'wc-prescription-lens-options'); ?></button>
            </div>
            <div class="wclo-repeater-rows" data-wclo-rows>
                <?php foreach ($rows as $index => $row) : ?>
                    <?php self::render_product_add_on_row($row, (int) $index); ?>
                <?php endforeach; ?>
            </div>
            <template data-wclo-template>
                <?php self::render_product_add_on_row([], '__INDEX__'); ?>
            </template>
        </div>
        <?php
    }

    private static function render_product_add_on_row(array $row, $index): void
    {
        $product_id = absint($row['product_id'] ?? 0);
        $product = $product_id && function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $label = (string) ($row['label'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $active = (string) ($row['active'] ?? 'yes');
        $sort = (string) ($row['sort_order'] ?? '10');
        $price = $product instanceof WC_Product && function_exists('wc_price') ? wc_price((float) wc_get_price_to_display($product)) : '';
        ?>
        <div class="wclo-repeater-row">
            <div class="wclo-row-actions">
                <button type="button" class="button-link wclo-move" data-wclo-move="up"><?php esc_html_e('Up', 'wc-prescription-lens-options'); ?></button>
                <button type="button" class="button-link wclo-move" data-wclo-move="down"><?php esc_html_e('Down', 'wc-prescription-lens-options'); ?></button>
                <button type="button" class="button-link-delete" data-wclo-remove-row><?php esc_html_e('Remove', 'wc-prescription-lens-options'); ?></button>
            </div>
            <div class="wclo-row-grid">
                <label class="wclo-wide"><?php esc_html_e('Product', 'wc-prescription-lens-options'); ?>
                    <select class="wc-product-search" style="width: 100%;" name="wclo_settings[product_add_ons][<?php echo esc_attr((string) $index); ?>][product_id]" data-placeholder="<?php esc_attr_e('Search product by name, SKU, or ID', 'wc-prescription-lens-options'); ?>" data-action="woocommerce_json_search_products_and_variations" data-security="<?php echo esc_attr(wp_create_nonce('search-products')); ?>" data-allow_clear="true">
                        <?php if ($product instanceof WC_Product) : ?>
                            <option value="<?php echo esc_attr((string) $product_id); ?>" selected><?php echo esc_html($product->get_name() . ' (#' . $product_id . ')'); ?></option>
                        <?php endif; ?>
                    </select>
                </label>
                <div class="wclo-product-price" data-wclo-product-price>
                    <span><?php esc_html_e('Product price', 'wc-prescription-lens-options'); ?></span>
                    <strong><?php echo $price !== '' ? wp_kses_post($price) : esc_html__('Select a product and save to show price.', 'wc-prescription-lens-options'); ?></strong>
                </div>
                <label><?php esc_html_e('Label override', 'wc-prescription-lens-options'); ?><input type="text" name="wclo_settings[product_add_ons][<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr($label); ?>"></label>
                <label><?php esc_html_e('Active', 'wc-prescription-lens-options'); ?><select name="wclo_settings[product_add_ons][<?php echo esc_attr((string) $index); ?>][active]"><option value="yes"<?php selected($active, 'yes'); ?>><?php esc_html_e('Yes', 'wc-prescription-lens-options'); ?></option><option value="no"<?php selected($active, 'no'); ?>><?php esc_html_e('No', 'wc-prescription-lens-options'); ?></option></select></label>
                <label><?php esc_html_e('Sort order', 'wc-prescription-lens-options'); ?><input type="number" step="1" name="wclo_settings[product_add_ons][<?php echo esc_attr((string) $index); ?>][sort_order]" value="<?php echo esc_attr($sort); ?>"></label>
                <label class="wclo-wide"><?php esc_html_e('Description override', 'wc-prescription-lens-options'); ?><textarea rows="2" name="wclo_settings[product_add_ons][<?php echo esc_attr((string) $index); ?>][description]"><?php echo esc_textarea($description); ?></textarea></label>
            </div>
        </div>
        <?php
    }

    public static function render_display_rules_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Display Rules', 'wc-prescription-lens-options'));
        self::setting_select('display_rule_mode', __('Display behavior', 'wc-prescription-lens-options'), $settings['display_rule_mode'], [
            'hide_when_matches' => __('Show on all products except when this field matches', 'wc-prescription-lens-options'),
            'show_when_matches' => __('Only show when this field matches', 'wc-prescription-lens-options'),
            'show_all' => __('Show on all products', 'wc-prescription-lens-options'),
        ]);
        self::setting_text('attribute_name', __('Product attribute/meta key', 'wc-prescription-lens-options'), $settings['attribute_name']);
        self::setting_select('attribute_compare', __('Match type', 'wc-prescription-lens-options'), $settings['attribute_compare'], [
            'equals' => __('Equals one of these values', 'wc-prescription-lens-options'),
            'contains' => __('Contains this text', 'wc-prescription-lens-options'),
        ]);
        self::setting_text('attribute_value', __('Match value', 'wc-prescription-lens-options'), $settings['attribute_value']);
        echo '<p class="description">' . esc_html__('Use a product attribute slug or product meta key. Multiple match values can be separated with commas. Product-level force-enable and force-disable settings still take priority.', 'wc-prescription-lens-options') . '</p>';
        self::settings_form_close();
    }

    public static function register_account_endpoint(): void
    {
        add_rewrite_endpoint(self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);
        if (get_option('wclo_rewrite_version') !== self::VERSION) {
            flush_rewrite_rules(false);
            update_option('wclo_rewrite_version', self::VERSION);
        }
    }

    public static function render_text_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Text & Translation', 'wc-prescription-lens-options'));
        foreach (self::text_keys() as $key => $label) {
            self::setting_text($key, $label, $settings[$key]);
        }
        self::settings_form_close();
    }

    public static function render_prescriptions_page(): void
    {
        $orders = self::recent_lens_orders();
        ?>
        <div class="wrap wclo-admin">
            <h1><?php esc_html_e('Orders / Prescriptions', 'wc-prescription-lens-options'); ?></h1>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Order', 'wc-prescription-lens-options'); ?></th><th><?php esc_html_e('Customer', 'wc-prescription-lens-options'); ?></th><th><?php esc_html_e('Lens Details', 'wc-prescription-lens-options'); ?></th><th><?php esc_html_e('Date', 'wc-prescription-lens-options'); ?></th></tr></thead>
                <tbody>
                <?php if (!$orders) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No lens orders found yet.', 'wc-prescription-lens-options'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $order) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_id()); ?></a></td>
                        <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
                        <td><?php echo wp_kses_post(self::order_lens_summary($order)); ?></td>
                        <td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function account_menu_items(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);
        $items[self::ACCOUNT_ENDPOINT] = __('Prescriptions', 'wc-prescription-lens-options');
        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function render_account_prescriptions(): void
    {
        if (!is_user_logged_in() || !function_exists('wc_get_orders')) {
            echo '<p>' . esc_html__('No prescriptions found.', 'wc-prescription-lens-options') . '</p>';
            return;
        }

        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $selection = self::item_selection($item);
                if (!$selection) {
                    continue;
                }
                $rows[] = [
                    'order' => $order,
                    'item' => $item,
                    'selection' => $selection,
                ];
            }
        }

        echo '<h3>' . esc_html__('My Prescriptions', 'wc-prescription-lens-options') . '</h3>';
        if (!$rows) {
            echo '<p>' . esc_html__('No prescriptions found yet.', 'wc-prescription-lens-options') . '</p>';
            return;
        }

        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'wc-prescription-lens-options') . '</th>';
        echo '<th>' . esc_html__('Frame', 'wc-prescription-lens-options') . '</th>';
        echo '<th>' . esc_html__('Lens', 'wc-prescription-lens-options') . '</th>';
        echo '<th>' . esc_html__('Prescription', 'wc-prescription-lens-options') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $order = $row['order'];
            $selection = $row['selection'];
            $file_url = (string) ($selection['prescription_file_url'] ?? '');
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_view_order_url()) . '">#' . esc_html((string) $order->get_id()) . '</a></td>';
            echo '<td>' . esc_html($row['item']->get_name()) . '</td>';
            echo '<td>' . esc_html((string) ($selection['package_name'] ?? '')) . '</td>';
            echo '<td>' . ($file_url !== '' ? '<a href="' . esc_url($file_url) . '" target="_blank" rel="noopener">' . esc_html__('View file', 'wc-prescription-lens-options') . '</a>' : esc_html(ucfirst(str_replace('_', ' ', (string) ($selection['prescription_method'] ?? ''))))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function default_settings(): array
    {
        return [
            'enabled' => 'yes',
            'button_text' => __('Select Lens', 'wc-prescription-lens-options'),
            'button_help' => __('Prescription, blue cut, photochromic and sunglass lenses available.', 'wc-prescription-lens-options'),
            'primary_color' => '#007c89',
            'button_color' => '#050808',
            'button_text_color' => '#ffffff',
            'modal_radius' => '18',
            'modal_width' => '960',
            'modal_fullscreen' => 'yes',
            'description_font_size' => '12',
            'submit_close_delay' => '2500',
            'whatsapp_number' => '',
            'require_lens_selection' => 'no',
            'allow_frame_only' => 'yes',
            'display_rule_mode' => 'hide_when_matches',
            'attribute_name' => 'prescription-ready',
            'attribute_compare' => 'equals',
            'attribute_value' => 'No',
            'method_upload' => 'yes',
            'method_scan' => 'no',
            'ocr_provider' => 'disabled',
            'ocr_space_api_key' => '',
            'ocr_language' => 'eng',
            'method_manual' => 'yes',
            'method_whatsapp' => 'yes',
            'upload_types' => 'jpg,jpeg,png,pdf',
            'upload_size_mb' => '5',
            'cleanup_uploads' => 'no',
            'cleanup_days' => '365',
            'product_add_ons' => [],
            'product_add_ons_text' => '',
            'add_ons_text' => "cleaning_kit | Cleaning kit | 250 | Microfiber cloth and cleaning spray\nexpress | Express processing | 500 | Prioritized lens processing",
            'text_step_usage' => __('How do you want to use this frame?', 'wc-prescription-lens-options'),
            'text_step_prescription' => __('How would you like to add your prescription?', 'wc-prescription-lens-options'),
            'text_step_lens' => __('Choose your lens type', 'wc-prescription-lens-options'),
            'text_step_addons' => __('Add anything extra?', 'wc-prescription-lens-options'),
            'text_step_review' => __('Review your lens selection', 'wc-prescription-lens-options'),
            'text_prescription' => __('Prescription Lens', 'wc-prescription-lens-options'),
            'text_non_prescription' => __('Non-Prescription Lens', 'wc-prescription-lens-options'),
            'text_frame_only' => __('Frame Only', 'wc-prescription-lens-options'),
            'text_upload' => __('Upload Prescription', 'wc-prescription-lens-options'),
            'text_manual' => __('Type Prescription', 'wc-prescription-lens-options'),
            'text_whatsapp' => __('Send Later on WhatsApp', 'wc-prescription-lens-options'),
            'text_continue' => __('Continue', 'wc-prescription-lens-options'),
            'text_back' => __('Previous Step', 'wc-prescription-lens-options'),
            'text_add_to_cart' => __('Add to Cart', 'wc-prescription-lens-options'),
            'text_submit_title' => __('Thanks, your lens selection is being added.', 'wc-prescription-lens-options'),
            'text_submit_message' => __('Please wait while the cart updates.', 'wc-prescription-lens-options'),
            'text_submit_button' => __('Adding to cart...', 'wc-prescription-lens-options'),
        ];
    }

    private static function settings(): array
    {
        $settings = (array) get_option(self::OPTION, []);
        if ($settings && !isset($settings['display_rule_mode'])) {
            $settings['display_rule_mode'] = 'show_when_matches';
        }
        if ($settings && !isset($settings['attribute_compare'])) {
            $settings['attribute_compare'] = 'equals';
        }

        return wp_parse_args($settings, self::default_settings());
    }

    private static function maybe_save_settings(): void
    {
        if (!isset($_POST['wclo_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wclo_settings_nonce'])), 'wclo_save_settings')) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = self::settings();
        if (isset($_POST['wclo_settings']['product_add_ons_present']) && !isset($_POST['wclo_settings']['product_add_ons'])) {
            $settings['product_add_ons'] = [];
        }
        foreach ($settings as $key => $value) {
            if (isset($_POST['wclo_settings'][$key])) {
                $incoming = wp_unslash($_POST['wclo_settings'][$key]);
                if ($key === 'product_add_ons') {
                    $settings[$key] = is_array($incoming) ? self::sanitize_product_add_ons($incoming) : [];
                } else {
                    $settings[$key] = is_array($incoming) ? self::sanitize_deep_text($incoming) : sanitize_textarea_field($incoming);
                }
            }
        }
        update_option(self::OPTION, $settings);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Lens settings saved.', 'wc-prescription-lens-options') . '</p></div>';
    }

    private static function sanitize_deep_text(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $clean[sanitize_key((string) $key)] = is_array($item) ? self::sanitize_deep_text($item) : sanitize_text_field((string) $item);
        }
        return $clean;
    }

    private static function product_is_eligible(WC_Product $product): bool
    {
        $override = (string) $product->get_meta(self::PRODUCT_OVERRIDE, true);
        if ($override === 'yes') {
            return true;
        }
        if ($override === 'no') {
            return false;
        }

        $settings = self::settings();
        $mode = (string) $settings['display_rule_mode'];
        if ($mode === 'show_all') {
            return true;
        }

        $key = trim((string) $settings['attribute_name']);
        $match_value = trim((string) $settings['attribute_value']);
        if ($key === '') {
            return $mode === 'hide_when_matches';
        }

        $matches = self::product_rule_value_matches($product, $key, $match_value, (string) $settings['attribute_compare']);

        if ($mode === 'hide_when_matches') {
            return !$matches;
        }

        return $matches;
    }

    private static function product_rule_value_matches(WC_Product $product, string $key, string $match_value, string $compare): bool
    {
        if ($match_value === '') {
            return false;
        }

        $value = self::product_rule_value($product, $key);
        if ($value === '') {
            return false;
        }

        $value = strtolower(trim($value));
        $match_value = strtolower(trim($match_value));

        if ($compare === 'contains') {
            return strpos($value, $match_value) !== false;
        }

        $product_values = array_filter(array_map('trim', preg_split('/\s*,\s*|\s*\|\s*/', $value) ?: []));
        $match_values = array_filter(array_map('trim', preg_split('/\s*,\s*/', $match_value) ?: []));

        return (bool) array_intersect($product_values, $match_values);
    }

    private static function product_rule_value(WC_Product $product, string $key): string
    {
        $keys = [$key];
        if (strpos($key, 'pa_') !== 0) {
            $keys[] = 'pa_' . $key;
        }

        foreach (array_unique($keys) as $candidate) {
            $value = (string) $product->get_attribute($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        foreach (array_unique($keys) as $candidate) {
            $value = $product->get_meta($candidate, true);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    private static function package_meta(int $post_id): array
    {
        return [
            'price' => (string) get_post_meta($post_id, '_wclo_price', true),
            'type' => (string) get_post_meta($post_id, '_wclo_type', true) ?: 'clear',
            'index' => (string) get_post_meta($post_id, '_wclo_index', true),
            'availability' => (string) get_post_meta($post_id, '_wclo_availability', true) ?: 'both',
            'requires_prescription' => (string) get_post_meta($post_id, '_wclo_requires_prescription', true) ?: 'no',
            'recommended' => (string) get_post_meta($post_id, '_wclo_recommended', true) ?: 'no',
            'active' => (string) get_post_meta($post_id, '_wclo_active', true) ?: 'yes',
            'sort_order' => (string) get_post_meta($post_id, '_wclo_sort_order', true) ?: '10',
            'description' => (string) get_post_meta($post_id, '_wclo_description', true),
            'included' => (string) get_post_meta($post_id, '_wclo_included', true),
            'color_options' => (string) get_post_meta($post_id, '_wclo_color_options', true),
            'options_text' => (string) get_post_meta($post_id, '_wclo_options_text', true),
            'child_options' => self::child_options_meta($post_id),
        ];
    }

    private static function child_options_meta(int $post_id): array
    {
        $stored = get_post_meta($post_id, '_wclo_child_options', true);
        if (is_array($stored)) {
            return self::sanitize_child_options($stored);
        }

        $legacy = (string) get_post_meta($post_id, '_wclo_options_text', true);
        return $legacy !== '' ? self::legacy_lens_options_to_meta($legacy) : [];
    }

    private static function frontend_packages(string $frame_type): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'meta_value_num',
            'meta_key' => '_wclo_sort_order',
            'order' => 'ASC',
        ]);

        $packages = [];
        foreach ($posts as $post) {
            $meta = self::package_meta($post->ID);
            if ($meta['active'] !== 'yes') {
                continue;
            }
            if ($meta['availability'] !== 'both' && $frame_type !== '' && $meta['availability'] !== $frame_type) {
                continue;
            }
            $packages[] = [
                'id' => $post->ID,
                'name' => get_the_title($post),
                'description' => $meta['description'],
                'price' => (float) $meta['price'],
                'type' => $meta['type'],
                'index' => $meta['index'],
                'requiresPrescription' => $meta['requires_prescription'] === 'yes',
                'recommended' => $meta['recommended'] === 'yes',
                'included' => array_filter(array_map('trim', explode(',', $meta['included']))),
                'colorOptions' => array_values(array_filter(array_map('trim', explode(',', $meta['color_options'])))),
                'options' => self::frontend_child_options($meta['child_options']),
            ];
        }
        return $packages;
    }

    private static function legacy_lens_options_to_meta(string $text): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $text);
        $options = [];
        foreach ($rows as $index => $row) {
            $parts = array_map('trim', explode('|', $row));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            $regular = (float) ($parts[1] ?? 0);
            if (isset($parts[2]) && is_numeric($parts[2])) {
                $sale = (float) $parts[2];
                $features = (string) ($parts[3] ?? '');
                $description = (string) ($parts[4] ?? '');
                $active = (string) ($parts[5] ?? 'yes');
                $sort = (string) ($parts[6] ?? (($index + 1) * 10));
                $colors = (string) ($parts[7] ?? '');
            } else {
                $sale = 0;
                $features = (string) ($parts[2] ?? '');
                $description = '';
                $active = (string) ($parts[3] ?? 'yes');
                $sort = (string) ($parts[4] ?? (($index + 1) * 10));
                $colors = (string) ($parts[5] ?? '');
            }

            $options[] = [
                'name' => sanitize_text_field($parts[0]),
                'regular_price' => $regular,
                'sale_price' => $sale,
                'features' => sanitize_textarea_field($features),
                'description' => sanitize_textarea_field($description),
                'active' => self::truthy_yes_no($active),
                'sort_order' => (int) $sort,
                'color_options' => sanitize_text_field($colors),
            ];
        }

        return self::sanitize_child_options($options);
    }

    private static function frontend_child_options(array $rows): array
    {
        $rows = self::sanitize_child_options($rows);
        $options = [];
        foreach ($rows as $index => $row) {
            if (($row['active'] ?? 'yes') !== 'yes' || (string) ($row['name'] ?? '') === '') {
                continue;
            }
            $regular = (float) ($row['regular_price'] ?? 0);
            $sale = (float) ($row['sale_price'] ?? 0);
            $final = $sale > 0 && $sale < $regular ? $sale : $regular;
            $options[] = [
                'id' => sanitize_key(sanitize_title((string) $row['name']) . '-' . $index),
                'name' => (string) $row['name'],
                'regularPrice' => $regular,
                'salePrice' => $sale > 0 && $sale < $regular ? $sale : 0,
                'price' => $final,
                'features' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['features'] ?? ''))))),
                'description' => (string) ($row['description'] ?? ''),
                'sortOrder' => (int) ($row['sort_order'] ?? (($index + 1) * 10)),
                'colorOptions' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['color_options'] ?? ''))))),
            ];
        }

        usort($options, static function (array $a, array $b): int {
            return ($a['sortOrder'] <=> $b['sortOrder']) ?: strnatcasecmp($a['name'], $b['name']);
        });

        return $options;
    }

    private static function sanitize_child_options(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = sanitize_text_field((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $clean[] = [
                'name' => $name,
                'regular_price' => (float) ($row['regular_price'] ?? 0),
                'sale_price' => (float) ($row['sale_price'] ?? 0),
                'features' => sanitize_textarea_field((string) ($row['features'] ?? '')),
                'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
                'active' => self::truthy_yes_no((string) ($row['active'] ?? 'yes')),
                'sort_order' => (int) ($row['sort_order'] ?? 10),
                'color_options' => sanitize_text_field((string) ($row['color_options'] ?? '')),
            ];
        }

        usort($clean, static function (array $a, array $b): int {
            return ((int) $a['sort_order'] <=> (int) $b['sort_order']) ?: strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $clean;
    }

    private static function truthy_yes_no(string $value): string
    {
        return in_array(strtolower(trim($value)), ['no', 'inactive', '0', 'false'], true) ? 'no' : 'yes';
    }

    private static function frontend_add_ons(array $settings): array
    {
        $add_ons = [];

        $product_rows = self::sanitize_product_add_ons((array) ($settings['product_add_ons'] ?? []));
        if (!$product_rows && !empty($settings['product_add_ons_text'])) {
            $product_rows = self::legacy_product_add_ons_to_meta((string) $settings['product_add_ons_text']);
        }

        foreach ($product_rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            if ($product_id <= 0) {
                continue;
            }
            if (($row['active'] ?? 'yes') !== 'yes') {
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product instanceof WC_Product || !$product->is_purchasable()) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $add_ons[] = [
                'key' => 'product_' . $product_id,
                'name' => $label !== '' ? $label : $product->get_name(),
                'price' => (float) wc_get_price_to_display($product),
                'description' => (string) ($row['description'] ?? ''),
                'productId' => $product_id,
                'isProduct' => true,
                'sortOrder' => (int) ($row['sort_order'] ?? 10),
            ];
        }

        $rows = preg_split('/\r\n|\r|\n/', (string) $settings['add_ons_text']);
        foreach ($rows as $row) {
            $parts = array_map('trim', explode('|', $row));
            if (count($parts) < 3 || $parts[0] === '') {
                continue;
            }
            $add_ons[] = [
                'key' => sanitize_key($parts[0]),
                'name' => $parts[1],
                'price' => (float) $parts[2],
                'description' => $parts[3] ?? '',
                'productId' => 0,
                'isProduct' => false,
                'sortOrder' => 100,
            ];
        }

        usort($add_ons, static function (array $a, array $b): int {
            return ((int) ($a['sortOrder'] ?? 100) <=> (int) ($b['sortOrder'] ?? 100)) ?: strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $add_ons;
    }

    private static function legacy_product_add_ons_to_meta(string $text): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $text);
        $add_ons = [];
        foreach ($rows as $row) {
            $parts = array_map('trim', explode('|', $row));
            if (empty($parts[0])) {
                continue;
            }
            $add_ons[] = [
                'product_id' => absint($parts[0]),
                'label' => sanitize_text_field((string) ($parts[1] ?? '')),
                'description' => sanitize_textarea_field((string) ($parts[2] ?? '')),
                'active' => self::truthy_yes_no((string) ($parts[3] ?? 'yes')),
                'sort_order' => (int) ($parts[4] ?? 10),
            ];
        }
        return self::sanitize_product_add_ons($add_ons);
    }

    private static function sanitize_product_add_ons(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product_id = absint($row['product_id'] ?? 0);
            if ($product_id <= 0) {
                continue;
            }
            $clean[] = [
                'product_id' => $product_id,
                'label' => sanitize_text_field((string) ($row['label'] ?? '')),
                'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
                'active' => self::truthy_yes_no((string) ($row['active'] ?? 'yes')),
                'sort_order' => (int) ($row['sort_order'] ?? 10),
            ];
        }

        usort($clean, static function (array $a, array $b): int {
            return ((int) $a['sort_order'] <=> (int) $b['sort_order']) ?: ((int) $a['product_id'] <=> (int) $b['product_id']);
        });

        return $clean;
    }

    private static function frontend_text(array $settings): array
    {
        $text = [];
        foreach (self::text_keys() as $key => $label) {
            $text[$key] = $settings[$key];
        }
        return $text;
    }

    private static function text_keys(): array
    {
        return [
            'text_step_usage' => __('Usage step title', 'wc-prescription-lens-options'),
            'text_step_prescription' => __('Prescription step title', 'wc-prescription-lens-options'),
            'text_step_lens' => __('Lens step title', 'wc-prescription-lens-options'),
            'text_step_addons' => __('Add-ons step title', 'wc-prescription-lens-options'),
            'text_step_review' => __('Review step title', 'wc-prescription-lens-options'),
            'text_prescription' => __('Prescription option label', 'wc-prescription-lens-options'),
            'text_non_prescription' => __('Non-prescription option label', 'wc-prescription-lens-options'),
            'text_frame_only' => __('Frame only option label', 'wc-prescription-lens-options'),
            'text_upload' => __('Upload method label', 'wc-prescription-lens-options'),
            'text_manual' => __('Manual method label', 'wc-prescription-lens-options'),
            'text_whatsapp' => __('WhatsApp method label', 'wc-prescription-lens-options'),
            'text_continue' => __('Continue button label', 'wc-prescription-lens-options'),
            'text_back' => __('Back button label', 'wc-prescription-lens-options'),
            'text_add_to_cart' => __('Add to cart button label', 'wc-prescription-lens-options'),
            'text_submit_title' => __('Adding-to-cart title', 'wc-prescription-lens-options'),
            'text_submit_message' => __('Adding-to-cart message', 'wc-prescription-lens-options'),
            'text_submit_button' => __('Adding-to-cart button label', 'wc-prescription-lens-options'),
        ];
    }

    private static function sanitize_selection(array $payload): array
    {
        $selection = [
            'usage' => sanitize_key($payload['usage'] ?? ''),
            'prescription_method' => sanitize_key($payload['prescriptionMethod'] ?? ''),
            'package_id' => absint($payload['packageId'] ?? 0),
            'package_name' => sanitize_text_field($payload['packageName'] ?? ''),
            'option_id' => sanitize_key($payload['optionId'] ?? ''),
            'option_name' => sanitize_text_field($payload['optionName'] ?? ''),
            'lens_index' => sanitize_text_field($payload['lensIndex'] ?? ''),
            'lens_color' => sanitize_text_field($payload['lensColor'] ?? ''),
            'lens_regular_price' => (float) ($payload['lensRegularPrice'] ?? 0),
            'lens_sale_price' => (float) ($payload['lensSalePrice'] ?? 0),
            'lens_final_price' => (float) ($payload['lensFinalPrice'] ?? 0),
            'customer_whatsapp' => sanitize_text_field($payload['customerWhatsapp'] ?? ''),
            'add_ons' => [],
            'manual' => [],
            'customer_note' => sanitize_textarea_field($payload['customerNote'] ?? ''),
            'price_delta' => 0,
        ];

        $selection['price_delta'] = (float) $selection['lens_final_price'];

        if (!empty($payload['addOns']) && is_array($payload['addOns'])) {
            foreach ($payload['addOns'] as $add_on) {
                $is_product = !empty($add_on['isProduct']) || absint($add_on['productId'] ?? 0) > 0;
                $price = (float) ($add_on['price'] ?? 0);
                $selection['add_ons'][] = [
                    'name' => sanitize_text_field($add_on['name'] ?? ''),
                    'price' => $price,
                    'product_id' => absint($add_on['productId'] ?? 0),
                    'is_product' => $is_product,
                ];
                if (!$is_product) {
                    $selection['price_delta'] += $price;
                }
            }
        }

        if (!empty($payload['manual']) && is_array($payload['manual'])) {
            foreach ($payload['manual'] as $key => $value) {
                $selection['manual'][sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        return $selection;
    }

    private static function handle_prescription_upload(array $file): ?array
    {
        $settings = self::settings();
        $max_bytes = max(1, (int) $settings['upload_size_mb']) * 1024 * 1024;
        if (!empty($file['size']) && (int) $file['size'] > $max_bytes) {
            wc_add_notice(__('Prescription file is larger than the allowed upload size.', 'wc-prescription-lens-options'), 'error');
            return null;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $settings['upload_types']))));
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($allowed && !in_array($extension, $allowed, true)) {
            wc_add_notice(__('Prescription file type is not allowed.', 'wc-prescription-lens-options'), 'error');
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        add_filter('upload_dir', [self::class, 'prescription_upload_dir']);
        $upload = wp_handle_upload($file, ['test_form' => false]);
        remove_filter('upload_dir', [self::class, 'prescription_upload_dir']);
        if (!empty($upload['error'])) {
            wc_add_notice($upload['error'], 'error');
            return null;
        }

        return [
            'url' => esc_url_raw($upload['url']),
            'path' => sanitize_text_field((string) ($upload['file'] ?? '')),
            'name' => sanitize_file_name((string) $file['name']),
        ];
    }

    public static function scan_prescription_ajax(): void
    {
        check_ajax_referer('wclo_scan_prescription', 'nonce');

        $settings = self::settings();
        if (($settings['method_scan'] ?? 'no') !== 'yes' || ($settings['ocr_provider'] ?? 'disabled') !== 'ocr_space') {
            wp_send_json_error(['message' => __('Prescription scanning is not enabled.', 'wc-prescription-lens-options')], 400);
        }

        $api_key = trim((string) ($settings['ocr_space_api_key'] ?? ''));
        if ($api_key === '') {
            wp_send_json_error(['message' => __('OCR API key is missing.', 'wc-prescription-lens-options')], 400);
        }

        if (empty($_FILES['wclo_ocr_file']['tmp_name']) || !is_uploaded_file($_FILES['wclo_ocr_file']['tmp_name'])) {
            wp_send_json_error(['message' => __('Please choose a prescription image first.', 'wc-prescription-lens-options')], 400);
        }

        $file = $_FILES['wclo_ocr_file'];
        $settings = self::settings();
        $max_bytes = max(1, (int) $settings['upload_size_mb']) * 1024 * 1024;
        if (!empty($file['size']) && (int) $file['size'] > $max_bytes) {
            wp_send_json_error(['message' => __('Prescription file is larger than the allowed upload size.', 'wc-prescription-lens-options')], 400);
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $settings['upload_types']))));
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($allowed && !in_array($extension, $allowed, true)) {
            wp_send_json_error(['message' => __('Prescription file type is not allowed.', 'wc-prescription-lens-options')], 400);
        }

        $bytes = file_get_contents((string) $file['tmp_name']);
        if ($bytes === false) {
            wp_send_json_error(['message' => __('Could not read the uploaded prescription.', 'wc-prescription-lens-options')], 400);
        }

        $mime = function_exists('mime_content_type') ? mime_content_type((string) $file['tmp_name']) : 'image/jpeg';
        $response = wp_remote_post('https://api.ocr.space/parse/image', [
            'timeout' => 45,
            'body' => [
                'apikey' => $api_key,
                'language' => sanitize_key((string) ($settings['ocr_language'] ?: 'eng')),
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'scale' => 'true',
                'OCREngine' => '2',
                'base64Image' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            wp_send_json_error(['message' => __('OCR service returned an unreadable response.', 'wc-prescription-lens-options')], 500);
        }

        if (!empty($body['IsErroredOnProcessing'])) {
            $message = is_array($body['ErrorMessage'] ?? null) ? implode(' ', $body['ErrorMessage']) : (string) ($body['ErrorMessage'] ?? __('OCR failed.', 'wc-prescription-lens-options'));
            wp_send_json_error(['message' => $message], 500);
        }

        $text = '';
        foreach (($body['ParsedResults'] ?? []) as $result) {
            $text .= "\n" . (string) ($result['ParsedText'] ?? '');
        }
        $text = trim($text);

        wp_send_json_success([
            'text' => $text,
            'fields' => self::parse_ocr_prescription_text($text),
            'message' => __('Scan complete. Please review the fields before continuing.', 'wc-prescription-lens-options'),
        ]);
    }

    private static function parse_ocr_prescription_text(string $text): array
    {
        $normalized = strtoupper(str_replace(["\r", "\t", '−', '–', '—'], ["\n", ' ', '-', '-', '-'], $text));
        $normalized = preg_replace('/[ ]+/', ' ', $normalized) ?: $normalized;

        $fields = [];
        $right = self::parse_eye_values($normalized, ['OD', 'RIGHT', 'R']);
        $left = self::parse_eye_values($normalized, ['OS', 'LEFT', 'L']);

        if ($right) {
            $fields['right_sph'] = $right[0] ?? '';
            $fields['right_cyl'] = $right[1] ?? '';
            $fields['right_axis'] = $right[2] ?? '';
        }
        if ($left) {
            $fields['left_sph'] = $left[0] ?? '';
            $fields['left_cyl'] = $left[1] ?? '';
            $fields['left_axis'] = $left[2] ?? '';
        }
        if (preg_match('/\bPD\b[^0-9+-]*(\d{2,3}(?:\.\d{1,2})?)/', $normalized, $match)) {
            $fields['pd'] = $match[1];
        }

        return array_filter($fields, static function ($value) {
            return $value !== '';
        });
    }

    private static function parse_eye_values(string $text, array $labels): array
    {
        foreach ($labels as $label) {
            if (!preg_match('/\b' . preg_quote($label, '/') . '\b(.{0,80})/s', $text, $segment_match)) {
                continue;
            }
            preg_match_all('/[+-]?\d{1,3}(?:\.\d{1,2})?/', $segment_match[1], $numbers);
            $values = $numbers[0] ?? [];
            if (count($values) >= 3) {
                return array_slice($values, 0, 3);
            }
        }
        return [];
    }

    public static function prescription_upload_dir(array $dirs): array
    {
        $subdir = '/woocommerce-prescriptions/' . gmdate('Y') . '/' . gmdate('m');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url'] = $dirs['baseurl'] . $subdir;
        return $dirs;
    }

    private static function item_selection(WC_Order_Item_Product $item): array
    {
        $raw = (string) $item->get_meta('_wclo_selection');
        $selection = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($selection) ? $selection : [];
    }

    public static function cleanup_old_prescription_files(): void
    {
        $settings = self::settings();
        if (($settings['cleanup_uploads'] ?? 'no') !== 'yes') {
            return;
        }

        $upload_dir = wp_get_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']) . 'woocommerce-prescriptions';
        if (!is_dir($base_dir)) {
            return;
        }

        $retention = max(30, (int) ($settings['cleanup_days'] ?? 365)) * DAY_IN_SECONDS;
        $cutoff = time() - $retention;
        $referenced = self::referenced_prescription_paths();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = wp_normalize_path($file->getPathname());
            if ($file->getMTime() >= $cutoff || isset($referenced[$path])) {
                continue;
            }
            wp_delete_file($path);
        }
    }

    private static function referenced_prescription_paths(): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $paths = [];
        $orders = wc_get_orders(['limit' => -1, 'return' => 'objects']);
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $selection = self::item_selection($item);
                $path = (string) ($selection['prescription_file_path'] ?? $item->get_meta('_wclo_prescription_file_path'));
                if ($path !== '') {
                    $paths[wp_normalize_path($path)] = true;
                }
            }
        }
        return $paths;
    }

    private static function selection_rows(array $selection): array
    {
        $add_ons = [];
        $product_add_ons = [];
        foreach ($selection['add_ons'] ?? [] as $add_on) {
            if (!empty($add_on['is_product']) || !empty($add_on['product_id'])) {
                if (!empty($add_on['name'])) {
                    $product_add_ons[] = (string) $add_on['name'];
                }
                continue;
            }
            $price = function_exists('wc_price') ? wp_strip_all_tags(wc_price((float) $add_on['price'])) : (string) $add_on['price'];
            $add_ons[] = $add_on['name'] . ' (' . html_entity_decode($price) . ')';
        }

        $manual = [];
        foreach ($selection['manual'] ?? [] as $key => $value) {
            if ($value !== '') {
                $manual[] = strtoupper(str_replace('_', ' ', $key)) . ': ' . $value;
            }
        }

        return [
            __('Lens usage', 'wc-prescription-lens-options') => ucfirst(str_replace('_', ' ', (string) ($selection['usage'] ?? ''))),
            __('Prescription method', 'wc-prescription-lens-options') => ucfirst(str_replace('_', ' ', (string) ($selection['prescription_method'] ?? ''))),
            __('Customer WhatsApp', 'wc-prescription-lens-options') => (string) ($selection['customer_whatsapp'] ?? ''),
            __('Lens category', 'wc-prescription-lens-options') => (string) ($selection['package_name'] ?? ''),
            __('Lens option', 'wc-prescription-lens-options') => (string) ($selection['option_name'] ?? ''),
            __('Lens index', 'wc-prescription-lens-options') => (string) ($selection['lens_index'] ?? ''),
            __('Lens color', 'wc-prescription-lens-options') => (string) ($selection['lens_color'] ?? ''),
            __('Lens regular price', 'wc-prescription-lens-options') => isset($selection['lens_regular_price']) ? html_entity_decode(wp_strip_all_tags(wc_price((float) $selection['lens_regular_price']))) : '',
            __('Lens sale price', 'wc-prescription-lens-options') => !empty($selection['lens_sale_price']) ? html_entity_decode(wp_strip_all_tags(wc_price((float) $selection['lens_sale_price']))) : '',
            __('Final lens price', 'wc-prescription-lens-options') => isset($selection['lens_final_price']) ? html_entity_decode(wp_strip_all_tags(wc_price((float) $selection['lens_final_price']))) : '',
            __('Lens add-ons', 'wc-prescription-lens-options') => implode(', ', $add_ons),
            __('Linked product add-ons', 'wc-prescription-lens-options') => implode(', ', $product_add_ons),
            __('Manual prescription', 'wc-prescription-lens-options') => implode('; ', $manual),
            __('Prescription file URL', 'wc-prescription-lens-options') => (string) ($selection['prescription_file_url'] ?? ''),
            __('Prescription file name', 'wc-prescription-lens-options') => (string) ($selection['prescription_file_name'] ?? ''),
            __('Lens notes', 'wc-prescription-lens-options') => (string) ($selection['customer_note'] ?? ''),
        ];
    }

    private static function seed_default_packages(): void
    {
        if (get_posts(['post_type' => self::POST_TYPE, 'numberposts' => 1, 'post_status' => 'any'])) {
            return;
        }

        $defaults = [
            ['Clear Lens', 'Everyday clear prescription lens.', 'clear', '1.56', '0', 'Anti-reflective, UV protection', 'no', '10', ''],
            ['Blue Cut Lens', 'Comfortable lens for phone, computer and indoor use.', 'blue_cut', '1.56', '800', 'Blue cut filter, Anti-reflective, UV protection', 'yes', '20', ''],
            ['Thin Blue Cut Lens', 'Better choice for medium or higher power prescriptions.', 'blue_cut', '1.60', '1500', 'Thinner profile, Blue cut filter, Anti-reflective', 'yes', '30', ''],
            ['Photochromic Lens', 'Clear indoors and darkens outdoors.', 'photochromic', '1.56', '1800', 'UV protection, Outdoor darkening', 'no', '40', 'Gray, Brown'],
            ['Sunglass Lens', 'Permanent tinted lens for outdoor use.', 'sunglass', '1.56', '1200', 'Tint, UV protection', 'no', '50', 'Gray, Brown, Green'],
        ];

        foreach ($defaults as $package) {
            $post_id = wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $package[0],
            ]);
            if ($post_id) {
                update_post_meta($post_id, '_wclo_description', $package[1]);
                update_post_meta($post_id, '_wclo_type', $package[2]);
                update_post_meta($post_id, '_wclo_index', $package[3]);
                update_post_meta($post_id, '_wclo_price', $package[4]);
                update_post_meta($post_id, '_wclo_included', $package[5]);
                update_post_meta($post_id, '_wclo_requires_prescription', 'no');
                update_post_meta($post_id, '_wclo_recommended', $package[6]);
                update_post_meta($post_id, '_wclo_active', 'yes');
                update_post_meta($post_id, '_wclo_availability', 'both');
                update_post_meta($post_id, '_wclo_sort_order', $package[7]);
                update_post_meta($post_id, '_wclo_color_options', $package[8]);
            }
        }
    }

    private static function settings_form_open(string $title): void
    {
        echo '<div class="wrap wclo-admin"><h1>' . esc_html($title) . '</h1><form method="post">';
        wp_nonce_field('wclo_save_settings', 'wclo_settings_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
    }

    private static function settings_form_close(): void
    {
        echo '</tbody></table>';
        submit_button(__('Save Changes', 'wc-prescription-lens-options'));
        echo '</form></div>';
    }

    private static function setting_text(string $key, string $label, string $value, string $type = 'text'): void
    {
        echo '<tr><th scope="row"><label for="wclo_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" type="' . esc_attr($type) . '" id="wclo_' . esc_attr($key) . '" name="wclo_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }

    private static function setting_textarea(string $key, string $label, string $value, int $rows): void
    {
        echo '<tr><th scope="row"><label for="wclo_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><textarea class="large-text code" rows="' . esc_attr((string) $rows) . '" id="wclo_' . esc_attr($key) . '" name="wclo_settings[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea></td></tr>';
    }

    private static function setting_select(string $key, string $label, string $value, array $options): void
    {
        echo '<tr><th scope="row"><label for="wclo_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><select id="wclo_' . esc_attr($key) . '" name="wclo_settings[' . esc_attr($key) . ']">';
        foreach ($options as $option_value => $option_label) {
            echo '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select></td></tr>';
    }

    private static function text_field(string $key, string $label, string $value, string $type = 'text', string $step = ''): void
    {
        echo '<p><label for="wclo_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><input class="widefat" type="' . esc_attr($type) . '" ' . ($step !== '' ? 'step="' . esc_attr($step) . '"' : '') . ' id="wclo_' . esc_attr($key) . '" name="wclo_' . esc_attr($key) . '" value="' . esc_attr($value) . '"></p>';
    }

    private static function select_field(string $key, string $label, string $value, array $options): void
    {
        echo '<p><label for="wclo_' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><select class="widefat" id="wclo_' . esc_attr($key) . '" name="wclo_' . esc_attr($key) . '">';
        foreach ($options as $option_value => $option_label) {
            echo '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select></p>';
    }

    private static function prescription_order_count(): int
    {
        return count(self::recent_lens_orders());
    }

    private static function recent_lens_orders(): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $orders = wc_get_orders(['limit' => 20, 'orderby' => 'date', 'order' => 'DESC']);
        return array_values(array_filter($orders, static function ($order) {
            foreach ($order->get_items() as $item) {
                if ($item->get_meta('_wclo_selection')) {
                    return true;
                }
            }
            return false;
        }));
    }

    private static function order_lens_summary(WC_Order $order): string
    {
        $lines = [];
        foreach ($order->get_items() as $item) {
            $package = $item->get_meta(__('Lens category', 'wc-prescription-lens-options')) ?: $item->get_meta(__('Lens package', 'wc-prescription-lens-options'));
            $option = $item->get_meta(__('Lens option', 'wc-prescription-lens-options'));
            if ($package) {
                $lines[] = esc_html($item->get_name() . ': ' . $package . ($option ? ' - ' . $option : ''));
            }
        }
        return implode('<br>', $lines);
    }
}

register_activation_hook(__FILE__, ['WC_Prescription_Lens_Options', 'activate']);
register_deactivation_hook(__FILE__, ['WC_Prescription_Lens_Options', 'deactivate']);
add_action('plugins_loaded', ['WC_Prescription_Lens_Options', 'boot'], 20);
