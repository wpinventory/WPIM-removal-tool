<?php


/**
 * Plugin Name:    WP Inventory Removal Tool
 * Plugin URI:    http://www.wpinventory.com
 * Description:    Removes all traces of WP Inventory from your database
 * Version:       1.0.2
 * Author:        WP Inventory Manager
 * Author URI:    http://www.wpinventory.com/
 * Text Domain:    wpinventory
 *
 */
class WPIMRemoval {
	private $tables = [
		'item',
		// Added in a db version to update to UTF-8
		'item_backup',
		'label',
		'category',
		'image',
		'media',
		'status',
		// AIM tables
		'aim_type',
		'aim_type_field',
		'aim_inventory_to_type',
		'aim_inventory_field_value',
		// Ledger Tables
		'ledger',
		'ledger_item_cost',
		'ledger_invoice',
		'ledger_invoice_line',
		'ledger_to_invoice',
		// Payments
		'payments',
		// Locations
		'loc_inventory_to_location',
		'loc_location',
		'loc_transfer',
	];

	private $options = [
		'wpinventory_settings',
		'wpim_license',
		'wpim_imported_categories',
		'wpim_imported_items',
		'wpim_legacy_item_map',
		'wpim_legacy_category_map',
		'widget_wpinventory_categories_widget',
		'widget_wpinventory_latest_items_widget'
	];

	const MENU_SLUG = 'wpim_remover';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_links' ] );
	}

	public function admin_menu() {
		add_submenu_page( 'tools.php', 'WP Inventory Remover', 'WP Inventory Remover', 'manage_options', self::MENU_SLUG, [
			$this,
			'remover'
		] );
	}

	public function plugin_links( $links ) {
		$links['tool'] = '<a href="' . admin_url( 'tools.php?page=' . self::MENU_SLUG ) . '">Removal Tool</a>';

		return $links;
	}

	public function remover() {
		echo '<div class="wrap wpim-wrap wpim-removal-wrap">';
		echo '<h2>WP Inventory Removal Tool</h2>';

		if ( isset( $_POST['remove_all'] ) ) {
			$errors = [];
			if ( ! isset( $_POST['acknowledge_items'] ) ) {
				$errors[] = 'You must acknowledge that all items will be removed by checking the box below.';
			}

			if ( ! isset( $_POST['acknowledge_categories'] ) ) {
				$errors[] = 'You must acknowledge that all categories will be removed by checking the box below.';
			}

			if ( ! isset( $_POST['acknowledge_settings'] ) ) {
				$errors[] = 'You must acknowledge that all settings will be removed by checking the box below.';
			}

			if ( 'remove all' != strtolower( $_POST['acknowledge'] ) ) {
				$errors[] = 'You must confirm that you want to remove all items by typing <strong>REMOVE ALL</strong> in the box below.';
			}

			if ( $errors ) {
				echo '<div class="error"><p>' . implode( '</p><p>', $errors ) . '</p></div>';
			} else {
				$this->process_tables( TRUE );
			}

		}

		if ( $this->process_tables() ) {
			echo '<div class="error"><p>WP Inventory data exists in the database.</p></div>';
		} else {
			echo '<div class="updated"><p>WP Inventory data does not exist in the database.</p></div>';
		}


		echo '<form method="post" style="margin-top: 25px; margin-left: 100px;">';
		echo '<p><input type="checkbox" name="acknowledge_items" id="items"><label for="items">I acknowledge that all inventory items will be permanently removed.</label></p>';
		echo '<p><input type="checkbox" name="acknowledge_categories" id="categories"><label for="categories">I acknowledge that all inventory categories will be permanently removed.</label></p>';
		echo '<p><input type="checkbox" name="acknowledge_settings" id="settings"><label for="settings">I acknowledge that I cannot undo this removal.  It is irreversible.</label></p>';
		echo '<p>To confirm you understand, please type the word <strong>REMOVE ALL</strong> into this box: <input type="text" name="acknowledge"></p>';
		echo '<div style="margin-top: 25px;"><input type="submit" name="remove_all" value="Remove WP Inventory" class="button button-red" /></div>';
		echo '</form>';

		echo <<<EOD
<style>
.wp-core-ui .button-red {
	background-color: #9B2124;
	background-image: -webkit-gradient(linear, left top, left bottom, from(#C5292E), to(#9B2124));
	background-image: -webkit-linear-gradient(top, #C5292E, #9B2124);
	background-image:    -moz-linear-gradient(top, #C5292E, #9B2124);
	background-image:     -ms-linear-gradient(top, #C5292E, #9B2124);
	background-image:      -o-linear-gradient(top, #C5292E, #9B2124);
	background-image:   linear-gradient(to bottom, #C5292E, #9B2124);
	border-color: #9B2124;
	border-bottom-color: #8D1F21;
	-webkit-box-shadow: inset 0 1px 0 rgba(120,200,230,0.5);
 	box-shadow: inset 0 1px 0 rgba(120,200,230,0.5);
 	color: #fff;
	text-decoration: none;
	text-shadow: 0 1px 0 rgba(0,0,0,0.1);
}

.wp-core-ui .button-red.hover,
.wp-core-ui .button-red:hover,
.wp-core-ui .button-red.focus,
.wp-core-ui .button-red:focus {
	background-color: #B72629;
	background-image: -webkit-gradient(linear, left top, left bottom, from(#D22E30), to(#9B2124));
	background-image: -webkit-linear-gradient(top, #D22E30, #9B2124);
	background-image:    -moz-linear-gradient(top, #D22E30, #9B2124);
	background-image:     -ms-linear-gradient(top, #D22E30, #9B2124);
	background-image:      -o-linear-gradient(top, #D22E30, #9B2124);
	background-image:   linear-gradient(to bottom, #D22E30, #9B2124);
	border-color: #7F1C1F;
	-webkit-box-shadow: inset 0 1px 0 rgba(120,200,230,0.6);
 	box-shadow: inset 0 1px 0 rgba(120,200,230,0.6);
	color: #fff;
	text-shadow: 0 -1px 0 rgba(0,0,0,0.3);
}
</style>
EOD;

	}

	public function process_tables( $remove = FALSE ) {
		global $table_prefix, $wpdb;

		if ( $remove ) {
			$this->delete_default_media();
		}

		$count = 0;
		foreach ( $this->tables AS $table ) {
			$table = "{$table_prefix}wpinventory_{$table}";

			$exists = ( $table == $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) );

			if ( $exists ) {
				if ( $remove ) {
					$wpdb->query( "DROP TABLE {$table}" );
					$count ++;
				} else {
					return TRUE;
				}
			}

		}

		foreach ( $this->options AS $option ) {
			$exists = (bool) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = '{$option}'" );

			if ( $exists ) {
				if ( $remove ) {
					delete_option( $option );
					$count ++;
				} else {
					return TRUE;
				}
			}
		}
	}

	/**
	 * On initial install, WPIM adds items, and images and media.
	 * This ensures that the media / images added, if still present, get
	 * deleted.
	 */
	public function delete_default_media() {
		$data = get_option( 'wpim_default_data' );

		if ( empty( $data ) ) {
			return;
		}

		global $wpdb, $table_prefix;

		$image_table = "{$table_prefix}wpinventory_image";
		$media_table = "{$table_prefix}wpinventory_media";

		if ( ! empty( $data['media'] ) && empty( $data['images'] ) ) {
			$data['images'] = array_slice( $data['media'], 0, 18 );
			// keep only those IDs not put in the images array
			$data['media'] = array_diff( $data['media'], $data['images'] );
		}

		foreach ( $data['images'] AS $image_id ) {
			$image = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$image_table} WHERE image_id = %d", $image_id ) );
			$wpdb->delete( $image_table, array( 'image_id' => $image_id ) );

			if ( ! empty( $image->post_id ) ) {
				wp_delete_attachment( $image->post_id, TRUE );
			}
		}

		$dir = wp_upload_dir();

		foreach ( $data['media'] AS $media_id ) {
			$media = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$media_table} WHERE media_id = %d", $media_id ) );
			$wpdb->delete( $media_table, array( 'media_id' => $media_id ) );

			if ( ! empty( $media->media ) ) {

				$path = $media->media;

				if ( 0 === strpos( $path, $dir['baseurl'] . '/' ) ) {
					$path = substr( $path, strlen( $dir['baseurl'] . '/' ) );
				}

				$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s", $path ) );
				if ( ! empty( $post_id ) ) {
					wp_delete_attachment( $post_id, TRUE );
				}
			}
		}

		delete_option( 'wpim_default_data' );
	}
}

new WPIMRemoval();