<?php
	class BelboonAdvertiserTrackingPlugin {
		const
			DEBUG = false,
			DEBUG_PARAM = 'bb_debug',
			ADMIN_SLUG = 'belboon',
			ADMIN_SLUG_CAT_MAPPING = 'belboon-cat-mapping',
			PLUGIN_DIR = 'belboon-advertiser-tracking',
			BB_CONTACT_MAIL = 'technical-support@belboon.com',
			API_URL = 'https://webservice.belboon.com/api/default-plugin.json?version='.BELBOON_TACKING_VERSION.'&apiKey=',
			API_URL_ADVERTISERS = 'https://webservice.belboon.com/api/plugin/advertisers.json?apiKey=',
			API_CALL_INVALID = 'Access denied!',
			API_TRY_LIMIT = 10,
			CONTAINER_TAGS_URL = 'containertags.belboon.com',
			CONTAINER_TAG_START = 'start',
			CONTAINER_TAG_CATEGORY = 'category',
			CONTAINER_TAG_PRODUCT = 'product',
			CONTAINER_TAG_BASKET = 'basket',
			CONTAINER_TAG_CHECKOUT = 'checkout',
			FIELD_UPDATE_API = 'bb_update_api',
			FIELD_EMAIL = 'bb_customer_email',
			CAT_MAPPING_POST_KEY = 'bb_cat_mapping',
			API_KEY_POST_KEY = 'bb_api_key',
			API_KEY_SELECT_POST_KEY = 'bb_api_key_select',
			API_KEY_UPDATE_POST_KEY = 'bb_api_key_update',
			BB_ADMIN_POST_SUBMIT = 'bb_admin_submit',
			BB_ADMIN_SELECT_POST_SUBMIT = 'bb_admin_select_submit',
			BB_ADMIN_SETUP_POST_SUBMIT = 'bb_admin_setup_submit',
			BB_ADMIN_NONCE = 'bb_admin_nonce',
			BB_ADMIN_SELECT_NONCE = 'bb_admin_select_nonce',
			BB_ADMIN_SETUP_NONCE = 'bb_admin_setup_nonce',
			OPTION_USE_CONTAINER_TAG_POST_KEY = 'bb_use_container_tag_update',
			OPTION_GROUP = 'bb_tracking',
			OPTION_IS_ACTIVE = 'bb_is_active',
			OPTION_MID = 'bb_mid',
			OPTION_PLUGIN_VERSION = 'bb_plugin_version',
			OPTION_ADVERTISER_ID = 'bb_advertiser_id',
			OPTION_ADVERTISER_NAME = 'bb_advertiser_name',
			OPTION_API_KEY = 'bb_api_key',
			OPTION_API_RESPONSE = 'bb_api_response',
			OPTION_API_KEY_VALID = 'bb_api_key_valid',
			OPTION_TRACKING_DOMAIN = 'bb_tracking_domain',
			OPTION_USE_CONTAINER_TAG = 'bb_use_container_tag',
			OPTION_CAT_MAPPING = 'bb_cat_mapping',
			TRACKING_ALREADY_IMPLEMENTED = 'BB_TRACKING_ALREADY_IMPLEMENTED',
			COOKIE_NAME = 'bb_trc',
			COOKIE_VALID_DAYS = 30,
			USE_COOKIES = true,
			USE_SESSION = true,
			URL_PARAMS = [
				'iclid',
				'cli',
				'belboon'
			],
			BASKET_TRACKING = true,
			PIXEL_VERSION = 4.25,
			NUMBER_DECIMALS = 2,
			TRACKING_TYPE_GENERAL = 'general',
			TRACKING_CAT = 'default',
			BASKET_CAT = 'basket',
			CONVERSION_TARGET = 'sale',
			CONVERSION_TARGET_BKNV = 'bknv', // Bestandskunde ohne Gutschein
			CONVERSION_TARGET_BKV = 'bkv',   // Bestandskunde mit Gutschein
			CONVERSION_TARGET_NKNV = 'nknv', // Neukunde ohne Gutschein
			CONVERSION_TARGET_NKV = 'nkv',   // Neukunde mit Gutschein
			GDPR_DEFAULT = 1,
			SITE_ID = 'check-out';

		protected $wpdb, $path;

		private $show_advertiser_select = false;
		private $advertisers = [];
		private $has_api_error = false;

		public function __construct(\wpdb $wpdb, $path) {
			$this->setWpdb($wpdb);
			$this->setPath($path);
		}

		public function initPlugin() {
			if (!wp_next_scheduled('belboon_ping_api')) {
				wp_schedule_event(time(), 'weekly', 'belboon_ping_api');
			}

			// general
			add_action('plugins_loaded', [$this, 'updateCheck']);
			add_action('init', [$this, 'loadTextdomain']);
			add_filter('plugin_action_links_' . self::PLUGIN_DIR . '/' . self::PLUGIN_DIR .'.php', [$this, 'addPluginSettingsLink']);
			add_filter('cron_schedules', [$this, 'addWeeklyCronJobSchedule']);
			add_action('belboon_ping_api', [$this, 'pingBelboonApi']);

			// admin
			add_action('admin_init', [$this, 'registerSettings']);
			add_action('admin_init', [$this, 'adminPostInit']);
			add_action('admin_menu', [$this, 'setAdminMenu']);
			add_action('admin_enqueue_scripts', [$this, 'adminEnqueueStyles']);
			add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
			add_action('admin_notices', [$this, 'showAdminNotice']);

			// frontend
			add_action('init', [$this, 'parseUrlParams']);
			add_action('woocommerce_thankyou', [$this, 'checkoutIntegration'], 10);
			// this is for custom checkout pages
			add_action('wp_footer', [$this, 'checkoutIntegrationFooter'], 10);
			add_action('wp_footer', [$this, 'containerTagIntegrationFooter'], 10);
			add_action('wp_footer', [$this, 'onPageTagIntegrationFooter'], 10);

			// track by key
			if(isset($_GET['key']) && substr($_GET['key'],0,9) === 'wc_order_') {
				add_action('wp_footer', [$this, 'trackByKeyParam'], 10);
			}

			// debug
			if($this->showDebug()) {
				add_action('wp_head', [$this, 'addDebugToHead']);
				add_action('woocommerce_view_order', [$this, 'checkoutIntegration'], 10);
			}
		}

		public function updateCheck() {
			if (get_site_option(self::OPTION_PLUGIN_VERSION) !== BELBOON_TACKING_VERSION) {
				$this->getDataFromAPI();

				update_option(self::OPTION_PLUGIN_VERSION, BELBOON_TACKING_VERSION);
			}
		}

		public function addDebugToHead() {
			if($this->showDebug()) {
				print '<pre>';
				echo '<strong>Belboon Tracking Settings:</strong>'.PHP_EOL;
				echo '<strong>==== Version: ====</strong>'.PHP_EOL;
				var_dump(get_site_option(self::OPTION_PLUGIN_VERSION));
				echo '<strong>==== Click Id: ====</strong>'.PHP_EOL;
				var_dump($this->getClickId());
				echo '<strong>==== Mid: ====</strong>'.PHP_EOL;
				var_dump($this->getMid());
				echo '<strong>==== Advertiser Id: ====</strong>'.PHP_EOL;
				var_dump($this->getAdvertiserId());
				echo '<strong>==== Tracking Domain: ====</strong>'.PHP_EOL;
				var_dump($this->getTrackingDomain());
				echo '<strong>==== Basket Tracking: ====</strong>'.PHP_EOL;
				var_dump($this->useBaseketTracking());
				echo '<strong>==== Container Tag: ====</strong>'.PHP_EOL;
				var_dump($this->useContainerTag());
				print '</pre>';
			}
		}

		public function loadTextdomain(){
			load_plugin_textdomain(BELBOON_TACKING_TEXTDOMAIN, false, self::PLUGIN_DIR . '/languages/');
		}

		public function addWeeklyCronJobSchedule($schedules) {
			$schedules['weekly'] = array(
				'interval' => 604800,
				'display' => __('Once Weekly')
			);
			return $schedules;
		}

		public function pingBelboonApi() {
			$this->getDataFromAPI(false);
		}

		public function addPluginSettingsLink($links) {
			$path = 'admin.php?page=' . BelboonAdvertiserTrackingPlugin::ADMIN_SLUG;
			$url = admin_url($path);

			$settings_link = '<a href="'.$url.'">Settings</a>';
			array_unshift($links, $settings_link);
			return $links;
		}

		public function trackingIsAcitve() {
			return $this->isApiKeyValid() && $this->getAdvertiserId() !== '' && $this->getTrackingDomain() !== '';
		}

		public function showAdminNotice() {
			// Show Notice if Advertiser ID is not ste
			if(
				$this->getAdvertiserId() === '' &&
				(
					isset($_GET['page']) === false ||
					$_GET['page'] !== self::ADMIN_SLUG
				)
			) {
				echo $this->getView('admin_notice');
			}
		}

		public function sendNewCustmerRequest() {
			$success = false;

			if(
				is_admin() === true &&
				isset($_POST) === true &&
				isset($_POST[self::FIELD_EMAIL]) === true
			) {
				$customer_email = filter_var($_POST[self::FIELD_EMAIL], FILTER_VALIDATE_EMAIL);

				if(
					$customer_email !== false && $customer_email !== ''
				) {
					$template = sprintf(
						'<p>Hallo belboon Sales Team,</p>'.
						'<p>es liegt eine neue Advertiser Anfrage über das WooCommerce Plugin vor:</p>'.
						'<p>'.
							'E-Mail: %1$s<br/>'.
							'Shop Url: %2$s<br/>'.
						'</p>',
						$customer_email, // 01
						get_site_url() // 02
					);

					$headers = array();
					$headers[] = 'Content-Type: text/html; charset=UTF-8';

					$success = wp_mail(self::BB_CONTACT_MAIL, '[WC Plugin] Neue Advertiser Anfrage', $template, $headers);
				}
			}

			return $success;
		}

		public function adminPostInit() {
			// save category mapping
			if(isset($_POST) && isset($_POST[self::CAT_MAPPING_POST_KEY])) {
				$this->updateCategoryMapping($_POST[self::CAT_MAPPING_POST_KEY]);
			}

			if(
				isset($_POST)
				&& isset($_POST[self::BB_ADMIN_SETUP_POST_SUBMIT])
				&& isset($_POST[self::BB_ADMIN_SETUP_NONCE. '_field'])
				&& wp_verify_nonce($_POST[self::BB_ADMIN_SETUP_NONCE. '_field'], self::BB_ADMIN_SETUP_NONCE)
				&& isset($_POST[self::API_KEY_POST_KEY])
			) {
				$this->getAdvertiserDataFromAPI(sanitize_text_field($_POST[self::API_KEY_POST_KEY]));
			}

			if(
				isset($_POST)
				&& isset($_POST[self::BB_ADMIN_SELECT_POST_SUBMIT])
				&& isset($_POST[self::BB_ADMIN_SELECT_NONCE. '_field'])
				&& wp_verify_nonce($_POST[self::BB_ADMIN_SELECT_NONCE. '_field'], self::BB_ADMIN_SELECT_NONCE)
				&& isset($_POST[self::API_KEY_SELECT_POST_KEY])
			) {
				$this->getDataFromAPI(true, sanitize_text_field($_POST[self::API_KEY_SELECT_POST_KEY]));
			}

			if(
				isset($_POST)
				&& isset($_POST[self::BB_ADMIN_POST_SUBMIT])
				&& isset($_POST[self::BB_ADMIN_NONCE. '_field'])
				&& wp_verify_nonce($_POST[self::BB_ADMIN_NONCE. '_field'], self::BB_ADMIN_NONCE)
				&& (isset($_POST[self::API_KEY_UPDATE_POST_KEY]) || isset($_POST[self::OPTION_USE_CONTAINER_TAG_POST_KEY]))
			) {
				$this->adminFormSubmit();
			}
		}

		public function registerSettings() {
			$args = array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => NULL,
			);

			register_setting(self::OPTION_GROUP, self::OPTION_IS_ACTIVE, [
				'type' => 'boolean',
				'default' => false,
			]);
			register_setting(self::OPTION_GROUP, self::OPTION_USE_CONTAINER_TAG, [
				'type' => 'boolean',
				'default' => true,
			]);

			register_setting(self::OPTION_GROUP, self::OPTION_MID, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_ADVERTISER_ID, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_ADVERTISER_NAME, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_API_KEY, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_API_KEY_VALID, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_API_RESPONSE, $args);
			register_setting(self::OPTION_GROUP, self::OPTION_TRACKING_DOMAIN, $args);

			register_setting(self::OPTION_GROUP, self::OPTION_CAT_MAPPING, [
				'type' => 'array',
				'default' => NULL,
			]);
		}

		public function setAdminMenu() {
			add_menu_page(__('Belboon Tracking', BELBOON_TACKING_TEXTDOMAIN), __('Belboon Tracking', BELBOON_TACKING_TEXTDOMAIN), 'manage_options', self::ADMIN_SLUG, array($this, 'getAdminPage'), 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyB2ZXJzaW9uPSIxLjEiIGlkPSJFYmVuZV8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCIKCSB2aWV3Qm94PSIwIDAgNjQgNjQiIHN0eWxlPSJlbmFibGUtYmFja2dyb3VuZDpuZXcgMCAwIDY0IDY0OyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+CjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+Cgkuc3Qwe2ZpbGw6I0ZGRkZGRjt9Cjwvc3R5bGU+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0xMi4zLDM0LjFjMi45LTMuMyw2LjQtNC45LDEwLjUtNC45czcuNiwxLjQsMTAuMyw0LjVjMi43LDIuOSw0LjMsNi40LDQuMywxMC41cy0xLjQsNy42LTQuMywxMC41CglzLTYuMiw0LjUtMTAuMyw0LjVzLTcuNi0xLjctMTAuNS01LjJ2NC4zSDhWMTkuNmg0LjNWMzQuMUwxMi4zLDM0LjF6IE0yMi42LDU1LjFjMi45LDAsNS40LTEuMiw3LjQtMy4zYzEuOS0yLjEsMy4xLTQuNywzLjEtNy42CgljMC0yLjktMS01LjQtMi45LTcuNmMtMS45LTIuMS00LjUtMy4zLTcuNC0zLjNzLTUuNiwxLTcuNiwyLjljLTEuOSwyLjEtMy4xLDQuNy0zLjEsNy42czEsNS42LDMuMSw3LjhDMTcuMSw1NCwxOS43LDU1LjEsMjIuNiw1NS4xCgl6Ii8+CjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik04LDguN2gxMS43YzE3LjksMCwzMi41LDE0LjYsMzIuNSwzMi41YzAsNi40LTEuNywxMi4yLTUuMSwxNy4zaDQuNWMyLjctNS4xLDQuNS0xMS4xLDQuNS0xNy4zCgljMC0yMC0xNi4zLTM2LjMtMzYuMy0zNi4zSDhDOCw0LjgsOCw4LjcsOCw4Ljd6Ii8+Cjwvc3ZnPgo=', 58);

			if($this->trackingIsAcitve() && $this->useBaseketTracking()) {
				add_submenu_page(self::ADMIN_SLUG, __('Belboon Tracking: Category Mapping', BELBOON_TACKING_TEXTDOMAIN), __('Category Mapping', BELBOON_TACKING_TEXTDOMAIN), 'manage_options', self::ADMIN_SLUG_CAT_MAPPING, array($this, 'getCatMappingPage'));
			}
		}

		public function getAdminPage() {
			$template = 'admin';
			if($this->getApiKey() === '' || $this->isApiKeyValid() === false) {
				$template = 'admin_setup';
			}

			if($this->isShowAdvertiserSelect() !== false && empty($this->getAdvertisers()) === false) {
				$template = 'admin_select';
			}

			echo $this->getView(
				$template
			);
		}

		public function getCatMappingPage() {
			$template = 'cat_mapping';
			$categoryMapping = $this->getCategoryMapping();
			$api_response = $this->getApiResponse();
			$trackingCategories = $this->getApiTrackingCategories();

			$productCategories = get_categories([
				'taxonomy' => 'product_cat'
			]);

			echo $this->getView(
				$template, [
					'productCategories' => $productCategories,
					'trackingCategories' => $trackingCategories,
					'categoryMapping' => $categoryMapping
				]
			);
		}

		public function adminEnqueueScripts() {
			wp_enqueue_script(self::PLUGIN_DIR . '-admin-script', plugins_url('../assets/js/belboon.js', __FILE__), ['jquery'], BELBOON_TACKING_VERSION);
		}

		public function adminEnqueueStyles() {
			wp_enqueue_style(self::PLUGIN_DIR . '-admin-style', plugins_url('../assets/css/belboon.css', __FILE__), [], BELBOON_TACKING_VERSION);
		}

		public function parseUrlParams() {
			$urlParams = self::URL_PARAMS;

			$sanitize_key_raw = null;
			foreach ($urlParams as $param) {
				if (isset($_GET[$param])) {
					$sanitize_key_raw = $_GET[$param];
					break;
				}
			}

			if ($sanitize_key_raw === null) {
				return;
			}

			$sanitized_key = sanitize_key($sanitize_key_raw);

			if (empty($sanitized_key)) {
				return;
			}

			$urlparts = parse_url(home_url());
			$domain = $urlparts['host'];

			if(strlen($sanitized_key) > 0) {
				if($this->useCookies()) {
					setcookie(self::COOKIE_NAME, $sanitized_key, time() + (86400 * self::COOKIE_VALID_DAYS), COOKIEPATH, $domain, is_ssl(), true);
				}

				if($this->useSession()) {
					if(!session_id()) {
						session_start();
					}

					$_SESSION[self::COOKIE_NAME] = $sanitized_key;
				}
			}
		}

		public function getClickId() {
			if($this->useCookies() && isset($_COOKIE[self::COOKIE_NAME]) === true) {
				return $_COOKIE[self::COOKIE_NAME];
			}

			if($this->useSession() && isset($_SESSION[self::COOKIE_NAME]) === true) {
				return $_SESSION[self::COOKIE_NAME];
			}

			return '';
		}

		public function trackByKeyParam() {
			$order_key = sanitize_text_field($_GET['key']);
			$order_id = wc_get_order_id_by_order_key($order_key);

			 if($order_id !== null && !defined(self::TRACKING_ALREADY_IMPLEMENTED)) {
				 if($this->showDebug()) {
					 print '<pre>';
					 var_dump('Tracking Code inserted in Footer by Key Param');
					 print '</pre>';
				 }

				 $this->checkoutIntegration($order_id);
			 }
		}

		public function checkoutIntegrationFooter() {
			// Check if tracking code was already inserted
			if(is_order_received_page() && !defined(self::TRACKING_ALREADY_IMPLEMENTED)) {
				if($this->showDebug()) {
					print '<pre>';
					var_dump('Tracking Code inserted in Footer');
					print '</pre>';
				}
				global $wp;

				$order_id = $wp->query_vars['order-received'];
				$this->checkoutIntegration($order_id);
			}
		}

		public function onPageTagIntegrationFooter() {
			$mid = $this->getMid();

			if(
				$this->useContainerTag() === true
				&& $mid !== ''
			) {
				$html = $this->getView('on_page_code', [
					'mid' => $mid,
					'sid' => $this->getSiteId(),
					'tracking_domain' => $this->getTrackingDomain(),
					'advertiser_id' => $this->getAdvertiserId()
				]);

				if($this->showDebug()) {
					echo '<pre>';
					echo htmlspecialchars($html);
					echo '</pre>';
				} else {
					echo $html;
				}
			}
		}

		public function containerTagIntegrationFooter() {
			$mid = $this->getMid();

			if(
				$this->useContainerTag() === true
				&& $mid !== ''
			) {
				$html = '';
				$page = '';
				$params = [];

				if(is_front_page()) {
					$page = self::CONTAINER_TAG_START;
				}

				if(is_product_category()) {
					$page = self::CONTAINER_TAG_CATEGORY;

					$params = [
						'categoryId' => get_the_ID()
					];
				}

				if(is_product()) {
					global $product;

//					echo '<pre class="pre-dump">';
//					var_dump($product);
//					echo '</pre>';

					// according to the docs this must be an array
					$params['products'] = [
						[
							'id' => $product->get_id(),
							'price' => $this->getProductPriceWithoutTax($product)
						]
					];

					$page = self::CONTAINER_TAG_PRODUCT;
				}

				if(is_cart()) {
					$_wc_cart = WC()->cart;
					$page = self::CONTAINER_TAG_BASKET;

					if($_wc_cart->is_empty() === false) {
						$params['orderProducts'] = [];
						foreach($_wc_cart->get_cart() as $cart_item_key => $cart_item) {
							$params['orderProducts'][] = [
								'qty' => $cart_item['quantity'],
								'id' => $cart_item['product_id'],
								'price' => $this->getProductPriceWithoutTax($cart_item['data'])
							];
						}
					}
				}

				if(
					// is_checkout()
					is_order_received_page()
				) {
					global  $wp;

					$order_id = $wp->query_vars['order-received'];
					$data = $this->collectData($order_id);

					$params['orderId'] = $order_id;
					$params['orderTransactionAmount'] = $data['orv'];
					$params['orderProducts'] = [];
					foreach($data['basket'] as $product) {
						$params['orderProducts'][] = [
							'id' => $product['pid'],
							'price' => $product['pri'],
							'qty' => $product['qty'],
						];
					}

					$page = self::CONTAINER_TAG_CHECKOUT;
				}

				if($page !== '') {
					$html .= $this->getView('containertags/'.$page, ['params' => $params]);
					$html .= $this->getView('containertags/basic', [
						'mid' => $mid,
						'page' => $page
					]);

					if($this->showDebug()) {
						echo '<pre>';
						echo htmlspecialchars($html);
						echo '</pre>';
					} else {
						echo $html;
					}
				}
			}
		}

		public function checkoutIntegration($order_id) {
			if(!defined(self::TRACKING_ALREADY_IMPLEMENTED)) {
				define(self::TRACKING_ALREADY_IMPLEMENTED, true);
			}

			if($this->trackingIsAcitve() === true) {
				$data = $this->collectData($order_id);

				if($this->showDebug()) {
					print '<pre>';
					var_dump($data);
					var_dump('======= TRACKING URL =============');
					var_dump($this->generateTrackingUrl($data));
					print '</pre>';
				}

				$this->doServer2ServerCall($data);

				$data['pixel_url'] 			= $this->getPixelUrl($data);
				$data['tracking_domain'] 	= $this->getTrackingDomain();
				$data['advertiser_id'] 		= $this->getAdvertiserId();

				$template = 'thankyou';
				if($this->useBaseketTracking()) {
					$template = 'thankyou_basket';
				}

				$tracking_html = $this->getView($template, $data);

				if($this->showDebug()) {
					print '<pre>';
					var_dump('========= TRACKING HTML ===========');
					echo htmlentities($tracking_html);
					print '</pre>';
				}

				echo $tracking_html;
			}
		}

		private function getPixelVersion() { return self::PIXEL_VERSION; }
		private function getTrackingCat($product_id = null) {
			$trackingCat = self::TRACKING_CAT;

			if($product_id === self::TRACKING_TYPE_GENERAL) {
				if($this->useBaseketTracking()) {
					$trackingCat = self::BASKET_CAT;
				}
			}

			if(is_int($product_id)) {
				$trackingCategories = [];
				$productCategoryIds = wc_get_product_term_ids($product_id, 'product_cat');
				$mapping = $this->getCategoryMapping();

				if(empty($productCategoryIds)) {
					$trackingCategories = [ self::TRACKING_CAT ];
				} else {
					foreach($productCategoryIds as $productCategoryId) {
						$trackingCategories[] = $this->getTrackingCategoryByProductCategoryId($productCategoryId, $mapping);
					}
				}

				$trackingCat = $trackingCategories[0];
			}

			return apply_filters('belboon_tracking_category', $trackingCat, $product_id);
		}
		private function getConversionTarget($order) {
			$isNewCustomer = $this->isNewCustomer($order->get_data()['billing']['email']);
			$hasCouponCodes = !empty($order->get_coupon_codes());

			if($isNewCustomer && $hasCouponCodes) {
				return self::CONVERSION_TARGET_NKV; // Neukunde mit Gutschein
			} else if ($isNewCustomer && !$hasCouponCodes) {
				return self::CONVERSION_TARGET_NKNV; // Neukunde ohne Gutschein
			} else if(!$isNewCustomer && $hasCouponCodes) {
				return self::CONVERSION_TARGET_BKV; // Bestandskunde mit Gutschein
			} else if(!$isNewCustomer && !$hasCouponCodes) {
				return self::CONVERSION_TARGET_BKNV; // Bestandskunde ohne Gutschein
			} else {
				return self::CONVERSION_TARGET;
			}
		}
		private function getSiteId() { return self::SITE_ID; }
		private function getGdpr() { return self::GDPR_DEFAULT; }
		private function useCookies() { return self::USE_COOKIES; }
		private function useSession() { return self::USE_SESSION; }
		private function useBaseketTracking() {
			$api_response = $this->getApiResponse();

			if(isset($api_response->trackingCategory)) {
				foreach ($api_response->trackingCategory as $trackingCat) {
					if($trackingCat->alias === self::BASKET_CAT) { return true; }
				}
			}

			return false;
		}

		public function getTrackingIsActive() {
			$is_active = esc_attr(get_option(self::OPTION_IS_ACTIVE));

			if($is_active === '') {
				$is_active = false;
			} else if($is_active === '1') {
				$is_active = true;
			}

			return $is_active;
		}

		public function useContainerTag() {
			$is_active = esc_attr(get_option(self::OPTION_USE_CONTAINER_TAG));

			if($is_active === '') {
				$is_active = false;
			} else if($is_active === '1') {
				$is_active = true;
			}

			return $is_active;
		}

		public function getAdvertiserId() {
			$advertiser_id = esc_attr(get_option(self::OPTION_ADVERTISER_ID));

			if($advertiser_id === false) {
				$advertiser_id = '';
			}

			return $advertiser_id;
		}

		public function getAdvertiserName() {
			$advertiser_name = esc_attr(get_option(self::OPTION_ADVERTISER_NAME));

			if($advertiser_name === false) {
				$advertiser_name = '';
			}

			return $advertiser_name;
		}

		public function getMid() {
			$mid = esc_attr(get_option(self::OPTION_MID));

			if($mid === false) {
				$mid = '';
			}

			return $mid;
		}

		public function getApiKey() {
			$api_key = esc_attr(get_option(self::OPTION_API_KEY));

			if($api_key === false) {
				$api_key = '';
			}

			return $api_key;
		}

		public function isApiKeyValid() {
			$api_key_valid = esc_attr(get_option(self::OPTION_API_KEY_VALID));

			return $api_key_valid == 1;
		}

		public function getApiResponse() {
			$api_response = get_option(self::OPTION_API_RESPONSE);

			return $this->unserialize($api_response);
		}

		public function getApiTrackingCategories() {
			$api_response = $this->getApiResponse();
			$trackingCategories = [];

			if(!isset($api_response->trackingCategory)) {
				$trackingCategories = [self::TRACKING_CAT => 'Default'];
			} else {
				foreach ($api_response->trackingCategory as $trackingCat) {
					if($trackingCat->alias !== self::BASKET_CAT) {
						$trackingCategories[$trackingCat->alias] = $trackingCat->name;
					}
				}
			}

			return $trackingCategories;
		}

		public function getCategoryMapping() {
			$category_mapping = get_option(self::OPTION_CAT_MAPPING);

			return $this->unserialize($category_mapping);
		}

		public function getTrackingDomain() {
			$tracking_domain = untrailingslashit(esc_attr(get_option(self::OPTION_TRACKING_DOMAIN)));

			return $tracking_domain;
		}

		public function isShowAdvertiserSelect(): bool {
			return $this->show_advertiser_select;
		}

		public function setShowAdvertiserSelect(bool $show_advertiser_select): void {
			$this->show_advertiser_select = $show_advertiser_select;
		}

		public function getAdvertisers(): array {
			return $this->advertisers;
		}

		public function setAdvertisers(array $advertisers): void {
			$this->advertisers = $advertisers;
		}

		public function getHasApiError(): bool {
			return $this->has_api_error;
		}

		private function getCurrentUrl() {
			global $wp;

			$current_url = home_url($wp->request);

			return $current_url;
		}

		private function formatPrice($price) {
			return number_format((float) $price, self::NUMBER_DECIMALS, '.', '');
		}

		private function getProductPriceWithoutTax($product) {
			return $this->formatPrice(wc_get_price_excluding_tax($product));
		}

		private function collectData($order_id) {
			$order = wc_get_order($order_id);

			$order_currency = $order->get_currency();
			$order_value_net = $this->formatPrice($order->get_total() - $order->get_total_tax() - $order->get_total_shipping());
			$discount_value_net = $this->formatPrice($order->get_discount_total());
			$customer_id = $order->get_customer_id();
			$hashed_email = md5($order->get_data()['billing']['email']);

			$now = new Datetime();

			$return_array = [
				'tst' => $now->format('U'),
				'trc' => $this->getTrackingCat(self::TRACKING_TYPE_GENERAL),
				'ctg' => $this->getConversionTarget($order),
				'sid' => $this->getSiteId(),
				'cid' => $order_id,
				'orv' => $order_value_net,
				'orc' => $order_currency,
				//> 'hrf' => $this->getCurrentUrl(),
				'gdpr' => $this->getGdpr(),
				//'gdpr_consent' => '!!gdpr_consent!!',
				'ver' => $this->getPixelVersion(),
				'cli' => $this->getClickId(),
				'csi' => $hashed_email,
				'pmt' => $order->get_payment_method(),
				'csn' => $this->isNewCustomer($order->get_data()['billing']['email']),
				'dsc' => implode(',', $order->get_coupon_codes()),
				'dsv' => $discount_value_net,
				'ovd' => $discount_value_net,
			];

			// use Basket Tracking can be 'false' due to the API Response
			// but the container tag implementaion needs this data as well
			if($this->useBaseketTracking() || $this->useContainerTag()) {
				$basket = [];
				$basket_index = 1;
				foreach($order->get_items() as $item) {
					$product = $item->get_product();

					/*
					//Example of detailed shopping basket information:
					//id : Unique position Id (int)
					//pid: Unique product Id
					//sku: Stock Keeping Unit
					//prn: Product name
					//brn: Brand name
					//prc: Product category hierarchy, using '.' as delimiter. Example: 'Women.Clothing.Shoes'
					//pri: Product price
					//qty: Quantity of units in this position
					//dsv: Discount of this position
					//shp: Shipping costs of this position
					//tax: Tax of this position
					//trc: Category for commission
					basket : [{
						  ✅ "id":${POSITION_ID},
						  ✅ "pid":"${PRODUCT_ID}",
						  ✅ "sku":"${PRODUCT_SKU}",
						  ✅ "prn":"${PRODUCT_NAME}",
						  ❌ "brn":"${PRODUCT_BRAND}",
						  ✅ "prc":"${PRODUCT_HIERARCHY}",
						  ✅ "pri":${PRODUCT_PRICE},
						  ✅ "qty":${PRODUCT_QUANTITY},
						  "dsv":${PRODUCT_DISCOUNT},
						  "shp":${PRODUCT_SHIPPING},
						  ✅ "tax":${PRODUCT_TAX},
						  ⚠️ "trc":"${TRACKING_CATEGORY}"
					  }],
					 */

					$basket[] = array(
						'id' => $basket_index,
						'pid' => $item['product_id'],
						'sku' => $item['variation_id'],
						'prn' => $item['name'],
						// 'prc' => $this->getHirachicalProductCatChain($product['product_id']),
						'prc' => [],
						'pri' => $this->getProductPriceWithoutTax($product),
						'qty' => $item['quantity'],
						// 'dsv' => '${PRODUCT_DISCOUNT}',
						// 'shp' => '${PRODUCT_SHIPPING}',
						'tax' => $item['total_tax'],
						'trc' => $this->getTrackingCat($item['product_id']),
					);

					$basket_index++;

//					if($this->showDebug()) {
//						print '<pre>';
//						var_dump('-------------');
//						var_dump($product['name']);
//						var_dump($product['product_id']);
//						var_dump($product);
//						var_dump(get_the_terms($product['product_id'], 'product_cat'));
//						var_dump('❗️');
//						var_dump($this->getHirachicalProductCatChain($product['product_id']));
//						var_dump('##########################');
//						print '</pre>';
//					}
				}

				$return_array['basket'] = $basket;
			}

			return $return_array;
		}

		public function generateTrackingUrl($data) {
			$urlParams = $data;
			if ($this->useBaseketTracking() === true && isset($data['basket']) === true) {
				$basket = $data['basket'];
				unset($urlParams['basket']);
				unset($urlParams['ovd']);

				$basketItemsStrings = [];
				foreach($basket as $basketItem) {
					$basketInnerStrings = [];
					foreach ($basketItem as $key => $value) {
						$bskValueStr = '';

						$bskValueStr .= '"'.$key.'"';
						$bskValueStr .= ':';
						if (is_array($value)) {
							$bskValueStr .= '"'.implode('.',$value).'"';
						} elseif(is_float($value) || is_int($value)) {
							$bskValueStr .= $value;
						} else {
							$bskValueStr .= '"'.$value.'"';
						}

						$basketInnerStrings[] = $bskValueStr;
					}

					$basketItemsStrings [] = '{'. implode(',', $basketInnerStrings ) .'}';
				}
				$bskStr = '['. implode(',', $basketItemsStrings ) .']';
				$urlParams['bsk'] = $bskStr;
				$urlParams['typ'] = 's';
			}

			return 'https://' . $this->getTrackingDomain() . '/ts/' . $this->getAdvertiserId() . '/tsa?'.http_build_query($urlParams);
		}

		public function getPixelUrl($data) {
			$data['typ'] = 'i';

			return $this->generateTrackingUrl($data);
		}

		private function doServer2ServerCall($data) {
			$data['typ'] = 's';

			$server2sererUrl = $this->generateTrackingUrl($data);

			if ($this->showDebug()) {
				print '<pre>';
				var_dump('========== SERVER TO SERVER CALL ==========');
				echo htmlentities($server2sererUrl);
				print '</pre>';
			}

			wp_remote_get($server2sererUrl);
		}

		private function getHirachicalProductCatChain($id) {
			$return_array = [];
			$taxonomy = 'product_cat';

			$terms_ids = wp_get_post_terms($id, $taxonomy);

			// Loop though terms ids (product categories)
			foreach($terms_ids as $term_id) {
				$term_names = []; // Initialising category array

				// Loop through product category ancestors
				foreach(get_ancestors($term_id, $taxonomy) as $ancestor_id){
					// Add the ancestors term names to the category array
					$term_names[] = get_term($ancestor_id, $taxonomy)->name;
				}
				// Add the product category term name to the category array
				$term_names[] = get_term($term_id, $taxonomy)->name;

				// Add the formatted ancestors with the product category to main array
				$return_array[] = implode('.', $term_names);
			}

			return $return_array;
		}

		public function getPath() {
			return $this->path;
		}

		public function setPath($path) {
			$this->path = $path;

			return $this;
		}

		public function getWpdb() {
			return $this->wpdb;
		}

		public function setWpdb($wpdb) {
			$this->wpdb = $wpdb;

			return $this;
		}

		public function serialize($data) {
			return maybe_serialize($data);
		}

		public function unserialize($data) {
			return maybe_unserialize($data);
		}

		public function getAdvertiserDataFromAPI($apiKey = '') {
			if ($apiKey !== '') {
				$index = 1;
				while($index <= self::API_TRY_LIMIT) {
					$post_response = wp_remote_get(BelboonAdvertiserTrackingPlugin::API_URL_ADVERTISERS . $apiKey);

					if (!is_wp_error($post_response)) {
						break;
					}

					$index++;
				}

				$body = wp_remote_retrieve_body($post_response);
				$body = json_decode($body);

				if (is_array($body) && empty($body) === false) {
					// if we only have one matching advertiser we can directly select it
					if (count($body) === 1 && isset($body->wsApiKey)) {
						$this->getDataFromAPI(true, $apiKey);
					} else {
						$this->setAdvertisers($body);
						$this->setShowAdvertiserSelect(true);
					}
				} else {
					$this->getDataFromAPI(true, $apiKey);
				}
			}
		}

		public function getDataFromAPI($doUpdate = true, $apiKey = '') {
			$apiKeyToUse = $apiKey ?? $this->getApiKey();

			if($apiKeyToUse !== '') {
				$index = 1;
				while($index <= self::API_TRY_LIMIT) {
					$post_response = wp_remote_get(BelboonAdvertiserTrackingPlugin::API_URL . $apiKeyToUse);

					if (!is_wp_error($post_response)) {
						break;
					}

					$index++;
				}

				$body = wp_remote_retrieve_body($post_response);
				$body = json_decode($body);

				if($doUpdate === true) {
					if($body === null || $body === BelboonAdvertiserTrackingPlugin::API_CALL_INVALID) {
						update_option(self::OPTION_API_KEY_VALID, false);
						$this->has_api_error = true;
					} else {
						// if an api is provided as param we also need to safe it
						if($apiKey !== '') {
							update_option(self::OPTION_API_KEY, $apiKey);
						}

						update_option(self::OPTION_API_KEY_VALID, true);
						update_option(self::OPTION_API_RESPONSE, $this->serialize($body));
						if(isset($body->trackingId)) {
							update_option(self::OPTION_ADVERTISER_ID, $body->trackingId);
						}
						if(isset($body->name)) {
							update_option(self::OPTION_ADVERTISER_NAME, $body->name);
						}
						if(isset($body->mid)) {
							update_option(self::OPTION_MID, $body->mid);
						}
						if(isset($body->trackingDomain)) {
							update_option(self::OPTION_TRACKING_DOMAIN, $body->trackingDomain);
						}
					}
				}
			}
		}

		private function adminFormSubmit() {
			$apiKey = sanitize_text_field($_POST[self::API_KEY_UPDATE_POST_KEY]);
			$useContainerTag = sanitize_text_field($_POST[self::OPTION_USE_CONTAINER_TAG_POST_KEY] ?? 0);

			if($apiKey === '') {
				update_option(self::OPTION_API_KEY, '');
				update_option(self::OPTION_API_KEY_VALID, false);
			} elseif($this->getApiKey() !== $apiKey) {
				// only check if the api key changed
				$this->getDataFromAPI(true, $apiKey);
			}

			if (isset($useContainerTag) && $useContainerTag === '1') {
				update_option(self::OPTION_USE_CONTAINER_TAG, true);
			} else {
				update_option(self::OPTION_USE_CONTAINER_TAG, false);
			}
		}

		private function updateCategoryMapping($mapping) {
			update_option(self::OPTION_CAT_MAPPING, $mapping);
		}

		private function getOrdersByUserId($user_id = null) {
			return wc_get_orders([
				'customer' => $user_id
			]);
		}

		private function isNewCustomer($user_id = null) {
			if($user_id === 0) {
				return true;
			}

			return count($this->getOrdersByUserId($user_id)) === 1;
		}

		public function getTrackingCategoryByProductCategoryId($id, $mapping = null) {
			if($mapping === null) {
				$mapping = $this->getCategoryMapping();
			}

			return $mapping[$id] ?? self::TRACKING_CAT;
		}

		public function getView($target, $params = array()) {
			$params['belboon_plugin'] = $this;
			extract($params);

			$file = $this->getPath() . 'view/' . $target . '.phtml';

			ob_start();

			if (file_exists($file)) {
				include $file;
			} else {
				include $this->getPath() . 'view/404.phtml';
			}

			return ob_get_clean();
		}

		private function showDebug() {
			if(self::DEBUG === true) {
				return isset($_GET[self::DEBUG_PARAM]);
			}

			return false;
		}
	}
