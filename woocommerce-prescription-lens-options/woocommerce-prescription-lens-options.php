<?php
/**
 * Plugin Name: WooCommerce Prescription Lens Options
 * Description: Adds a modern prescription lens selection flow for WooCommerce eyewear stores.
 * Version: 0.1.0
 * Author: Codex
 * Text Domain: wc-prescription-lens-options
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_Prescription_Lens_Options
{
    private const VERSION = '0.1.0';
    private const OPTION = 'wclo_settings';
    private const POST_TYPE = 'wclo_lens_package';
    private const PRODUCT_OVERRIDE = '_wclo_lens_override';
    private const PRODUCT_FRAME_TYPE = '_wclo_frame_type';
    private const CART_KEY = 'wclo_lens_selection';
    private const MENU_SLUG = 'wclo-dashboard';

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_lens_package_post_type']);
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
            add_action('woocommerce_before_calculate_totals', [self::class, 'apply_cart_item_prices']);
            add_filter('woocommerce_get_item_data', [self::class, 'render_cart_item_data'], 10, 2);
            add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_order_item_data'], 10, 4);
        } else {
            add_action('admin_notices', [self::class, 'missing_woocommerce_notice']);
        }
    }

    public static function activate(): void
    {
        self::register_lens_package_post_type();
        if (!get_option(self::OPTION)) {
            update_option(self::OPTION, self::default_settings());
        }
        self::seed_default_packages();
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

        $fields = ['price', 'type', 'index', 'availability', 'requires_prescription', 'recommended', 'active', 'sort_order', 'description', 'included'];
        foreach ($fields as $field) {
            $key = 'wclo_' . $field;
            $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $value = in_array($field, ['description', 'included'], true) ? sanitize_textarea_field($value) : sanitize_text_field($value);
            update_post_meta($post_id, '_' . $key, $value);
        }
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
                'allowUploads' => $settings['method_upload'],
                'allowManual' => $settings['method_manual'],
                'allowWhatsapp' => $settings['method_whatsapp'],
                'allowFrameOnly' => $settings['allow_frame_only'],
            ],
            'text' => self::frontend_text($settings),
            'packages' => $packages,
            'addOns' => self::frontend_add_ons($settings),
        ];
        ?>
        <div class="wclo-selector" data-wclo-root data-wclo-config="<?php echo esc_attr(wp_json_encode($payload)); ?>" style="--wclo-accent: <?php echo esc_attr($settings['primary_color']); ?>">
            <input type="hidden" name="wclo_lens_payload" data-wclo-payload value="">
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

    public static function render_cart_item_data(array $item_data, array $cart_item): array
    {
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
        if (empty($values[self::CART_KEY])) {
            return;
        }

        foreach (self::selection_rows($values[self::CART_KEY]) as $label => $value) {
            if ($value !== '') {
                $item->add_meta_data($label, $value, true);
            }
        }
        $item->add_meta_data('_wclo_selection', wp_json_encode($values[self::CART_KEY]), true);
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
        self::setting_text('primary_color', __('Accent color', 'wc-prescription-lens-options'), $settings['primary_color']);
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
        self::setting_select('method_upload', __('Upload prescription photo/PDF', 'wc-prescription-lens-options'), $settings['method_upload'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_select('method_manual', __('Type prescription manually', 'wc-prescription-lens-options'), $settings['method_manual'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_select('method_whatsapp', __('Send later on WhatsApp', 'wc-prescription-lens-options'), $settings['method_whatsapp'], ['yes' => __('Enabled', 'wc-prescription-lens-options'), 'no' => __('Disabled', 'wc-prescription-lens-options')]);
        self::setting_text('upload_types', __('Allowed upload extensions', 'wc-prescription-lens-options'), $settings['upload_types']);
        self::setting_text('upload_size_mb', __('Max upload size in MB', 'wc-prescription-lens-options'), $settings['upload_size_mb'], 'number');
        self::settings_form_close();
    }

    public static function render_add_ons_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Add-ons', 'wc-prescription-lens-options'));
        echo '<p>' . esc_html__('Use one add-on per line in this format: key | Name | Price | Description', 'wc-prescription-lens-options') . '</p>';
        self::setting_textarea('add_ons_text', __('Add-on rows', 'wc-prescription-lens-options'), $settings['add_ons_text'], 8);
        self::settings_form_close();
    }

    public static function render_display_rules_page(): void
    {
        self::maybe_save_settings();
        $settings = self::settings();
        self::settings_form_open(__('Display Rules', 'wc-prescription-lens-options'));
        self::setting_text('attribute_name', __('Product attribute/meta key', 'wc-prescription-lens-options'), $settings['attribute_name']);
        self::setting_text('attribute_value', __('Required value', 'wc-prescription-lens-options'), $settings['attribute_value']);
        echo '<p class="description">' . esc_html__('Default rule checks whether a product attribute or product meta value equals the required value. Each product can also force-enable or force-disable lens options from the product edit page.', 'wc-prescription-lens-options') . '</p>';
        self::settings_form_close();
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

    private static function default_settings(): array
    {
        return [
            'enabled' => 'yes',
            'button_text' => __('Select Lens', 'wc-prescription-lens-options'),
            'button_help' => __('Prescription, blue cut, photochromic and sunglass lenses available.', 'wc-prescription-lens-options'),
            'primary_color' => '#007c89',
            'whatsapp_number' => '',
            'require_lens_selection' => 'no',
            'allow_frame_only' => 'yes',
            'attribute_name' => 'prescription_lens_available',
            'attribute_value' => 'yes',
            'method_upload' => 'yes',
            'method_manual' => 'yes',
            'method_whatsapp' => 'yes',
            'upload_types' => 'jpg,jpeg,png,pdf',
            'upload_size_mb' => '5',
            'add_ons_text' => "cleaning_kit | Cleaning kit | 250 | Microfiber cloth and cleaning spray\nexpress | Express processing | 500 | Prioritized lens processing",
            'text_step_usage' => __('How do you want to use this frame?', 'wc-prescription-lens-options'),
            'text_step_prescription' => __('How would you like to add your prescription?', 'wc-prescription-lens-options'),
            'text_step_lens' => __('Choose your lens package', 'wc-prescription-lens-options'),
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
        ];
    }

    private static function settings(): array
    {
        return wp_parse_args((array) get_option(self::OPTION, []), self::default_settings());
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
        foreach ($settings as $key => $value) {
            if (isset($_POST['wclo_settings'][$key])) {
                $incoming = wp_unslash($_POST['wclo_settings'][$key]);
                $settings[$key] = is_array($incoming) ? array_map('sanitize_text_field', $incoming) : sanitize_textarea_field($incoming);
            }
        }
        update_option(self::OPTION, $settings);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Lens settings saved.', 'wc-prescription-lens-options') . '</p></div>';
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
        $key = trim((string) $settings['attribute_name']);
        $required = strtolower(trim((string) $settings['attribute_value']));
        if ($key === '') {
            return false;
        }

        $value = (string) $product->get_attribute($key);
        if ($value === '') {
            $value = (string) $product->get_meta($key, true);
        }

        return strtolower(trim($value)) === $required;
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
        ];
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
            ];
        }
        return $packages;
    }

    private static function frontend_add_ons(array $settings): array
    {
        $rows = preg_split('/\r\n|\r|\n/', (string) $settings['add_ons_text']);
        $add_ons = [];
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
            ];
        }
        return $add_ons;
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
        ];
    }

    private static function sanitize_selection(array $payload): array
    {
        $selection = [
            'usage' => sanitize_key($payload['usage'] ?? ''),
            'prescription_method' => sanitize_key($payload['prescriptionMethod'] ?? ''),
            'package_id' => absint($payload['packageId'] ?? 0),
            'package_name' => sanitize_text_field($payload['packageName'] ?? ''),
            'lens_index' => sanitize_text_field($payload['lensIndex'] ?? ''),
            'add_ons' => [],
            'manual' => [],
            'customer_note' => sanitize_textarea_field($payload['customerNote'] ?? ''),
            'price_delta' => (float) ($payload['priceDelta'] ?? 0),
        ];

        if (!empty($payload['addOns']) && is_array($payload['addOns'])) {
            foreach ($payload['addOns'] as $add_on) {
                $selection['add_ons'][] = [
                    'name' => sanitize_text_field($add_on['name'] ?? ''),
                    'price' => (float) ($add_on['price'] ?? 0),
                ];
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
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (!empty($upload['error'])) {
            wc_add_notice($upload['error'], 'error');
            return null;
        }

        return [
            'url' => esc_url_raw($upload['url']),
            'name' => sanitize_file_name((string) $file['name']),
        ];
    }

    private static function selection_rows(array $selection): array
    {
        $add_ons = [];
        foreach ($selection['add_ons'] ?? [] as $add_on) {
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
            __('Lens package', 'wc-prescription-lens-options') => (string) ($selection['package_name'] ?? ''),
            __('Lens index', 'wc-prescription-lens-options') => (string) ($selection['lens_index'] ?? ''),
            __('Lens add-ons', 'wc-prescription-lens-options') => implode(', ', $add_ons),
            __('Manual prescription', 'wc-prescription-lens-options') => implode('; ', $manual),
            __('Prescription file', 'wc-prescription-lens-options') => (string) ($selection['prescription_file_url'] ?? ''),
            __('Lens notes', 'wc-prescription-lens-options') => (string) ($selection['customer_note'] ?? ''),
        ];
    }

    private static function seed_default_packages(): void
    {
        if (get_posts(['post_type' => self::POST_TYPE, 'numberposts' => 1, 'post_status' => 'any'])) {
            return;
        }

        $defaults = [
            ['Clear Lens', 'Everyday clear prescription lens.', 'clear', '1.56', '0', 'Anti-reflective, UV protection', 'no', '10'],
            ['Blue Cut Lens', 'Comfortable lens for phone, computer and indoor use.', 'blue_cut', '1.56', '800', 'Blue cut filter, Anti-reflective, UV protection', 'yes', '20'],
            ['Thin Blue Cut Lens', 'Better choice for medium or higher power prescriptions.', 'blue_cut', '1.60', '1500', 'Thinner profile, Blue cut filter, Anti-reflective', 'yes', '30'],
            ['Photochromic Lens', 'Clear indoors and darkens outdoors.', 'photochromic', '1.56', '1800', 'UV protection, Outdoor darkening', 'no', '40'],
            ['Sunglass Lens', 'Permanent tinted lens for outdoor use.', 'sunglass', '1.56', '1200', 'Tint, UV protection', 'no', '50'],
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
            $package = $item->get_meta(__('Lens package', 'wc-prescription-lens-options'));
            if ($package) {
                $lines[] = esc_html($item->get_name() . ': ' . $package);
            }
        }
        return implode('<br>', $lines);
    }
}

register_activation_hook(__FILE__, ['WC_Prescription_Lens_Options', 'activate']);
add_action('plugins_loaded', ['WC_Prescription_Lens_Options', 'boot'], 20);
