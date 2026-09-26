<?php
/**
 * Master controller for Zeko Shop.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop. */
final class Zeko_Shop {

	/**
	 * Instance.
	 *
	 * @var ?self Instance.
	 */
	private static ?self $instance = null;

	/**
	 * Db.
	 *
	 * @var ?Zeko_Shop_DB Db.
	 */
	private ?Zeko_Shop_DB $db = null;

	/**
	 * Ajax.
	 *
	 * @var ?Zeko_Shop_Ajax Ajax.
	 */
	private ?Zeko_Shop_Ajax $ajax = null;

	/**
	 * Frontend.
	 *
	 * @var ?Zeko_Shop_Frontend Frontend.
	 */
	private ?Zeko_Shop_Frontend $frontend = null;

	/**
	 * Programs.
	 *
	 * @var ?Zeko_Shop_Programs Programs.
	 */
	private ?Zeko_Shop_Programs $programs = null;

	/**
	 * Sessions.
	 *
	 * @var ?Zeko_Shop_Sessions Sessions.
	 */
	private ?Zeko_Shop_Sessions $sessions = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_show_dependency_notice' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Boot once zeko-pay is available.
	 */
	public function boot(): void {
		if ( ! class_exists( 'Zeko_Pay' ) || ! class_exists( 'Zeko_Pay_Ledger' ) ) {
			return;
		}

		$this->load_dependencies();

		$this->db       = new Zeko_Shop_DB();
		$this->frontend = new Zeko_Shop_Frontend( $this->db );
		$this->ajax     = new Zeko_Shop_Ajax( $this->db );

		$this->frontend->init();
		$this->ajax->init();

		$emails = new Zeko_Shop_Emails( $this->db );
		$emails->init();

		$rest = new Zeko_Shop_Rest( $this->db );
		$rest->init();

		if ( class_exists( 'Zeko_Shop_Programs' ) ) {
			$this->programs = new Zeko_Shop_Programs( $this->db );
			$this->programs->init();
		}

		if ( class_exists( 'Zeko_Shop_Sessions' ) ) {
			$this->sessions = new Zeko_Shop_Sessions( $this->db );
			$this->sessions->init();
		}

		if ( is_admin() && class_exists( 'Zeko_Shop_Admin' ) ) {
			$admin = new Zeko_Shop_Admin( $this->db );
			$admin->init();
		}

		add_action( 'init', array( $this, 'on_init' ), 5 );
		add_action( 'init', array( $this, 'flush_rewrites_late' ), 999 );

		// Notification source registry.
		add_filter( 'zeko_register_notification_sources', array( $this, 'register_notification_source' ) );
	}

	/**
	 * Register Shop as a notification source for the core bell.
	 *
	 * @param array $sources Sources.
	 */
	public function register_notification_source( array $sources ): array {
		$sources['shop'] = array(
			'table'       => $this->db->table_notifications(),
			'type_column' => 'type',
			'has_title'   => true,
			'has_message' => true,
			'icon'        => 'cart',
			'label'       => __( 'Shop', 'zeko-shop' ),
		);
		return $sources;
	}

	/**
	 * Show admin notice if Zeko Pay is not active.
	 */
	public function maybe_show_dependency_notice(): void {
		if ( class_exists( 'Zeko_Pay' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Zeko Shop requires the Zeko Pay plugin to be installed and active.', 'zeko-shop' );
		echo '</p></div>';
	}

	/**
	 * Load required includes.
	 */
	private function load_dependencies(): void {
		$files = array(
			'class-zeko-shop-db.php',
			'class-zeko-shop-totals.php',
			'class-zeko-shop-checkout.php',
			'class-zeko-shop-frontend.php',
			'class-zeko-shop-ajax.php',
			'class-zeko-shop-emails.php',
			'class-zeko-shop-rest.php',
			'class-zeko-shop-programs.php',
			'class-zeko-shop-sessions.php',
			'admin/class-zeko-shop-admin.php',
		);

		foreach ( $files as $file ) {
			$path = ZEKO_SHOP_PLUGIN_PATH . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		// Migration support — register WooCommerce migrator.
		$base_file = ZEKO_SHOP_PLUGIN_PATH . '../zeko-core/includes/class-zeko-migrator-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}
		$migrator_file = ZEKO_SHOP_PLUGIN_PATH . 'includes/migrator/class-zeko-migrate-woo.php';
		if ( file_exists( $migrator_file ) ) {
			require_once $migrator_file;
			add_filter(
				'zbp_available_migrators',
				function ( array $migrators ): array {
					$migrators['woo'] = array(
						'class'   => 'Zeko_Migrate_Woo',
						'label'   => 'WooCommerce',
						'package' => 'zeko-shop',
					);
					return $migrators;
				}
			);
		}
	}

	/**
	 * Initialize on init priority 5 — create tables on version change.
	 */
	public function on_init(): void {
		$installed = get_option( 'zeko_shop_db_version', '0' );
		if ( version_compare( $installed, ZEKO_SHOP_DB_VERSION, '<' ) ) {
			$this->db->create_tables();
			update_option( 'zeko_shop_db_version', ZEKO_SHOP_DB_VERSION );
		}
	}

	/**
	 * Flush rewrite rules when the plugin version changes.
	 */
	public function flush_rewrites_late(): void {
		$installed = get_option( 'zeko_shop_plugin_version', '0' );
		if ( version_compare( $installed, ZEKO_SHOP_VERSION, '<' ) ) {
			flush_rewrite_rules();
			update_option( 'zeko_shop_plugin_version', ZEKO_SHOP_VERSION );
		}
	}

	/**
	 * Get the DB layer.
	 */
	public function get_db(): Zeko_Shop_DB {
		return $this->db;
	}

	/**
	 * Get the frontend handler.
	 */
	public function get_frontend(): Zeko_Shop_Frontend {
		return $this->frontend;
	}

	/**
	 * Get the program bridge (may be null if dependencies are missing).
	 */
	public function get_programs(): ?Zeko_Shop_Programs {
		return $this->programs;
	}

	/**
	 * Get the session bridge (may be null if dependencies are missing).
	 */
	public function get_sessions(): ?Zeko_Shop_Sessions {
		return $this->sessions;
	}
}
