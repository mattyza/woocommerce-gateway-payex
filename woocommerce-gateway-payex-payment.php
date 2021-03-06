<?php
/*
Plugin Name: WooCommerce PayEx Payments Gateway
Plugin URI: http://payex.com/
Description: Provides a Credit Card Payment Gateway through PayEx for WooCommerce.
Version: 2.1.0
Author: AAIT Team
Author URI: http://aait.se/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires at least: 3.1
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

require_once dirname( __FILE__ ) . '/vendor/autoload.php';

class WC_Payex_Payment {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Activation
		register_activation_hook( __FILE__, __CLASS__ . '::install' );

		// Actions
		add_action( 'init', array( $this, 'create_credit_card_post_type' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment' ) );

		// Add admin menu
		add_action( 'admin_menu', array( &$this, 'admin_menu' ), 99 );

		// Payment fee
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fee' ) );

		// Add statuses for payment complete
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array(
			$this,
			'add_valid_order_statuses'
		), 10, 2 );

		// Add MasterPass button to Cart Page
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'add_mp_button_to_cart' ) );

		// Add MasterPass button to Product Page
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'add_mp_button_to_product_page' ) );

		// Check is MasterPass Purchase
		add_action( 'template_redirect', array( $this, 'check_mp_purchase' ) );

		// PayEx Credit Card: Payment Method Change Callback
		add_action( 'template_redirect', array( $this, 'check_payment_method_changed' ) );

		// Add Upgrade Notice
		if ( version_compare( get_option( 'woocommerce_payex_version', '1.0.0' ), '2.0.0', '<' ) ) {
			add_action( 'admin_notices', __CLASS__ . '::upgrade_notice' );
		}
		add_action( 'admin_notices', __CLASS__ . '::admin_notices' );

		// Add SSN Checkout Field
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'before_checkout_billing_form' ) );
		add_action( 'wp_ajax_payex_process_ssn', array( $this, 'ajax_payex_process_ssn' ) );
		add_action( 'wp_ajax_nopriv_payex_process_ssn', array( $this, 'ajax_payex_process_ssn' ) );
	}

	/**
	 * Install
	 */
	public static function install() {
		if ( ! get_option( 'woocommerce_payex_version' ) ) {
			add_option( 'woocommerce_payex_version', '2.0.0' );
		}
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_payex_payment' ) ) . '">' . __( 'PayEx Settings', 'woocommerce-gateway-payex-payment' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Localization
		load_plugin_textdomain( 'woocommerce-gateway-payex-payment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Includes
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-abstract.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-payment.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-bankdebit.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-invoice.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-factoring.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-wywallet.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-masterpass.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payex-swish.php' );
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payex-credit-cards.php' );
	}

	/**
	 * Admin Notices
	 */
	public static function admin_notices() {
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		$list = array(
			'payex', 'payex_bankdebit', 'payex_invoice',
			'payex_factoring', 'payex_wywallet', 'payex_masterpass',
			'payex_swish'
		);

		foreach ($list as $item) {
			if ( isset( $available_gateways[$item] ) ) {
				$gateway = $available_gateways[$item];
				$settings = $gateway->settings;
				if ( empty( $settings['account_no'] ) || empty( $settings['encrypted_key'] ) ) {
					echo '<div class="error"><p>' . sprintf( __( 'PayEx Payments for WooCommerce is almost ready. To get started <a href="%s">connect your PayEx account</a>.', 'woocommerce-gateway-payex-payment' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gateway->id ) ) ) . '</p></div>';
					break;
				}
			}
		}
	}

	/**
	 * Upgrade Notice
	 */
	public static function upgrade_notice() {
		if ( current_user_can( 'update_plugins' ) ) {
			?>
			<div id="message" class="error">
				<p>
					<?php
					echo esc_html__( 'Warning! WooCommerce PayEx Payments plugin requires to update the database structure.', 'woocommerce-gateway-payex-payment' );
					echo ' ' . sprintf( esc_html__( 'Please click %s here %s to start upgrade.', 'woocommerce-gateway-payex-payment' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-payex-upgrade' ) ) . '">', '</a>' );
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Upgrade Page
	 */
	public static function upgrade_page() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Run Database Update
		include_once( dirname( __FILE__ ) . '/includes/class-wc-payex-update.php' );
		WC_Payex_Update::update();

		echo esc_html__( 'Upgrade finished.', 'woocommerce-gateway-payex-payment' );
	}

	/**
	 * Add Scripts
	 */
	public function add_scripts() {
		$mp_settings = get_option( 'woocommerce_payex_masterpass_settings' );
		if ( $mp_settings['enabled'] === 'yes' ) {
			wp_enqueue_style( 'wc-gateway-payex-masterpass', plugins_url( '/assets/css/masterpass.css', __FILE__ ), array(), false, 'all' );
		}

		$factoring_settings = get_option( 'woocommerce_payex_factoring_settings' );
		if ( isset( $factoring_settings['checkout_field'] ) && $factoring_settings['checkout_field'] === 'yes' ) {
			wp_enqueue_script( 'wc-payex-addons-ssn', plugins_url( '/assets/js/ssn.js', __FILE__ ), array( 'wc-checkout' ), false, true );
		}
	}

	/**
	 * Register the gateways for use
	 */
	public function register_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Payex_Payment';
		$methods[] = 'WC_Gateway_Payex_Bankdebit';
		$methods[] = 'WC_Gateway_Payex_Invoice';
		$methods[] = 'WC_Gateway_Payex_Factoring';
		$methods[] = 'WC_Gateway_Payex_Wywallet';
		$methods[] = 'WC_Gateway_Payex_MasterPass';
		$methods[] = 'WC_Gateway_Payex_Swish';

		return $methods;
	}

	/**
	 * Add fee when selected payment method
	 */
	public function add_cart_fee() {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Get Current Payment Method
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$default            = get_option( 'woocommerce_default_gateway' );

		if ( ! $default ) {
			$default = current( array_keys( $available_gateways ) );
		}

		$current         = WC()->session->get( 'chosen_payment_method', $default );
		$current_gateway = $available_gateways[ $current ];

		// Fee feature in Invoice and Factoring modules
		if ( ! in_array( $current_gateway->id, array( 'payex_invoice', 'payex_factoring' ) ) ) {
			return;
		}

		// Is Fee is not specified
		if ( abs( $current_gateway->fee ) < 0.01 ) {
			return;
		}

		// Add Fee
		$fee_title = $current_gateway->id === 'payex_invoice' ? __( 'Invoice Fee', 'woocommerce-gateway-payex-payment' ) : __( 'Factoring Fee', 'woocommerce-gateway-payex-payment' );
		WC()->cart->add_fee( $fee_title, $current_gateway->fee, ( $current_gateway->fee_is_taxable === 'yes' ), $current_gateway->fee_tax_class );
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param $statuses
	 * @param $order
	 *
	 * @return array
	 */
	public function add_valid_order_statuses( $statuses, $order ) {
		if ( strpos( $order->payment_method, 'payex' ) !== false ) {
			$statuses = array_merge( $statuses, array( 'processing', 'completed' ) );
		}

		return $statuses;
	}

	/**
	 * Provide Credit Card Post Type
	 */
	public function create_credit_card_post_type() {
		register_post_type( 'payex_credit_card',
			array(
				'labels'       => array(
					'name' => __( 'Credit Cards', 'woocommerce-gateway-payex-payment' )
				),
				'public'       => false,
				'show_ui'      => false,
				'map_meta_cap' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => false,
			)
		);
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		$order              = wc_get_order( $order_id );
		$transaction_status = get_post_meta( $order_id, '_payex_transaction_status', true );
		if ( empty( $transaction_status ) ) {
			return;
		}

		// Get Payment Gateway
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		/** @var WC_Gateway_Payex_Abstract $gateway */
		$gateway = $gateways[ $order->payment_method ];
		if ( $gateway && (string) $transaction_status === '3' ) {
			// Get Additional Values
			$additionalValues = '';
			if ( $gateway->id === 'payex_factoring' ) {
				$additionalValues = 'FINANCINGINVOICE_ORDERLINES=' . urlencode( $gateway->getInvoiceExtraPrintBlocksXML( $order ) );
			}

			// Init PayEx
			$gateway->getPx()->setEnvironment( $gateway->account_no, $gateway->encrypted_key, $gateway->testmode === 'yes' );

			// Call PxOrder.Capture5
			$params = array(
				'accountNumber'     => '',
				'transactionNumber' => $order->get_transaction_id(),
				'amount'            => round( 100 * $order->order_total ),
				'orderId'           => $order->id,
				'vatAmount'         => 0,
				'additionalValues'  => $additionalValues
			);
			$result = $gateway->getPx()->Capture5( $params );
			if ( $result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK' ) {
				$gateway->log( 'PxOrder.Capture5:' . $result['errorCode'] . '(' . $result['description'] . ')' );

				$message = sprintf( __( 'PayEx error: %s', 'woocommerce-gateway-payex-payment' ), $result['errorCode'] . ' (' . $result['description'] . ')' );
				$order->update_status( 'on-hold', $message );
				WC_Admin_Meta_Boxes::add_error( $message );

				return;
			}

			update_post_meta( $order->id, '_payex_transaction_status', $result['transactionStatus'] );
			$order->add_order_note( sprintf( __( 'Transaction captured. Transaction Id: %s', 'woocommerce-gateway-payex-payment' ), $result['transactionNumber'] ) );
			$order->payment_complete( $result['transactionNumber'] );
		}
	}

	/**
	 * Capture payment when the order is changed from on-hold to cancelled
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order              = wc_get_order( $order_id );
		$transaction_status = get_post_meta( $order_id, '_payex_transaction_status', true );
		if ( empty( $transaction_status ) ) {
			return;
		}

		// Get Payment Gateway
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		/** @var WC_Gateway_Payex_Abstract $gateway */
		$gateway = $gateways[ $order->payment_method ];
		if ( $gateway && (string) $transaction_status === '3' ) {
			// Call PxOrder.Cancel2
			$params = array(
				'accountNumber'     => '',
				'transactionNumber' => $order->get_transaction_id()
			);
			$result = $gateway->getPx()->Cancel2( $params );
			if ( $result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK' ) {
				$gateway->log( 'PxOrder.Cancel2:' . $result['errorCode'] . '(' . $result['description'] . ')' );

				$message = sprintf( __( 'PayEx error: %s', 'woocommerce-gateway-payex-payment' ), $result['errorCode'] . ' (' . $result['description'] . ')' );
				$order->update_status( 'on-hold', $message );
				WC_Admin_Meta_Boxes::add_error( $message );

				return;
			}

			update_post_meta( $order->id, '_payex_transaction_status', $result['transactionStatus'] );
			$order->add_order_note( sprintf( __( 'Transaction canceled. Transaction Id: %s', 'woocommerce-gateway-payex-payment' ), $result['transactionNumber'] ) );
		}
	}

	/**
	 * Provide Admin Menu items
	 */
	public function admin_menu() {
		// Add Upgrade Page
		global $_registered_pages;
		$hookname = get_plugin_page_hookname( 'wc-payex-upgrade', '' );
		if ( ! empty( $hookname ) ) {
			add_action( $hookname, __CLASS__ . '::upgrade_page' );
		}
		$_registered_pages[ $hookname ] = true;
	}

	/**
	 * Add MasterPass Button to Cart page
	 */
	public function add_mp_button_to_cart() {
		$mp_settings = get_option( 'woocommerce_payex_masterpass_settings' );
		if ( $mp_settings['display_cart_button'] === 'yes' && $mp_settings['enabled'] === 'yes' ) {
			wc_get_template(
				'masterpass/cart-button.php',
				array(
					'image'       => esc_url( plugins_url( '/assets/images/masterpass-button.png', __FILE__ ) ),
					'description' => $mp_settings['description'],
					'link'        => esc_url( add_query_arg( 'mp_from_cart_page', 1, get_permalink() ) )
				),
				'',
				dirname( __FILE__ ) . '/templates/'
			);
		}
	}

	/**
	 * Add MasterPass Button to Single Product page
	 */
	public function add_mp_button_to_product_page() {
		$mp_settings = get_option( 'woocommerce_payex_masterpass_settings' );
		if ( $mp_settings['display_pp_button'] === 'yes' && $mp_settings['enabled'] === 'yes' ) {
			wc_get_template(
				'masterpass/product-button.php',
				array(
					'image'       => esc_url( plugins_url( '/assets/images/masterpass-button.png', __FILE__ ) ),
					'description' => $mp_settings['description'],
					'link'        => esc_url( add_query_arg( 'mp_from_product_page', 1, get_permalink() ) )
				),
				'',
				dirname( __FILE__ ) . '/templates/'
			);
		}
	}

	/**
	 * Check for MasterPass purchase from cart page
	 **/
	public function check_mp_purchase() {
		// Check for MasterPass purchase from cart page
		if ( isset( $_GET['mp_from_cart_page'] ) && $_GET['mp_from_cart_page'] === '1' ) {
			$gateway = new WC_Gateway_Payex_MasterPass;
			$gateway->masterpass_button_action();
		}

		// Check for MasterPass purchase from product page
		if ( isset( $_POST['mp_from_product_page'] ) && $_POST['mp_from_product_page'] === '1' ) {
			$gateway = new WC_Gateway_Payex_MasterPass;
			$gateway->masterpass_button_action();
		}
	}

	/**
	 * Payment Method Change Callback
	 */
	public function check_payment_method_changed() {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( isset( $gateways['payex'] ) ) {
			/** @var WC_Gateway_Payex_Payment $gateway */
			$gateway = $gateways['payex'];
			if ( $gateway->enabled === 'yes' ) {
				$gateway->check_payment_method_changed();
			}
		}
	}

	/**
	 * Hook before_checkout_billing_form
	 *
	 * @param $checkout
	 */
	public function before_checkout_billing_form( $checkout ) {
		$factoring_settings = get_option( 'woocommerce_payex_factoring_settings' );
		if ( isset( $factoring_settings['checkout_field'] ) && $factoring_settings['checkout_field'] === 'yes' ) {
			echo '<div id="payex_ssn">';
			woocommerce_form_field( 'payex_ssn', array(
				'type'        => 'text',
				'class'       => array( 'payex-ssn-class form-row-wide' ),
				'label'       => __( 'Social Security Number', 'woocommerce-gateway-payex-payment' ),
				'placeholder' => __( 'Social Security Number', 'woocommerce-gateway-payex-payment' ),
			), $checkout->get_value( 'payex_ssn' ) );

			echo '<input type="button" class="button alt" name="woocommerce_checkout_payex_ssn" id="payex_ssn_button" value="' . __( 'Get Profile', 'woocommerce-gateway-payex-payment' ) . '" />';
			echo '</div>';
		}
	}

	/**
	 * Ajax Hook
	 */
	public function ajax_payex_process_ssn() {
		// Init PayEx
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( ! $gateways['payex_factoring'] ) {
			wp_send_json_error( array( 'message' => __( 'Financing Invoice method is inactive', 'woocommerce-gateway-payex-payment' ) ) );
			exit();
		}

		/** @var WC_Gateway_Payex_Factoring $gateway */
		$gateway = $gateways['payex_factoring'];

		if ( empty( $_POST['billing_country'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select country', 'woocommerce-gateway-payex-payment' ) ) );
			exit();
		}

		if ( empty( $_POST['billing_postcode'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter postcode', 'woocommerce-gateway-payex-payment' ) ) );
			exit();
		}

		// Init PayEx
		$gateway->getPx()->setEnvironment( $gateway->account_no, $gateway->encrypted_key, $gateway->testmode === 'yes' );

		// Call PxOrder.GetAddressByPaymentMethod
		$params = array(
			'accountNumber' => '',
			'paymentMethod' => $_POST['billing_country'] === 'SE' ? 'PXFINANCINGINVOICESE' : 'PXFINANCINGINVOICENO',
			'ssn'           => trim( $_POST['social_security_number'] ),
			'zipcode'       => trim( $_POST['billing_postcode'] ),
			'countryCode'   => trim( $_POST['billing_country'] ),
			'ipAddress'     => trim( $_SERVER['REMOTE_ADDR'] )
		);
		$result = $gateway->getPx()->GetAddressByPaymentMethod( $params );
		if ( $result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK' ) {
			if ( preg_match( '/\bInvalid parameter:SocialSecurityNumber\b/i', $result['description'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid Social Security Number', 'woocommerce-gateway-payex-payment' ) ) );
				exit();
			}

			wp_send_json_error( array( 'message' => $result['errorCode'] . '(' . $result['description'] . ')' ) );
			exit();
		}

		// Parse name field
		$parser = new \FullNameParser();
		$name   = $parser->parse_name( $result['name'] );

		$output = array(
			'first_name' => $name['fname'],
			'last_name'  => $name['lname'],
			'address_1'  => $result['streetAddress'],
			'address_2'  => ! empty( $result['coAddress'] ) ? 'c/o ' . $result['coAddress'] : '',
			'postcode'   => $result['zipCode'],
			'city'       => $result['city'],
			'country'    => $result['countryCode']
		);
		wp_send_json_success( $output );
		exit();
	}
}

new WC_Payex_Payment();
