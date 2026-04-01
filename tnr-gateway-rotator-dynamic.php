<?php
/**
 * Plugin Name: TNR Development Studio Gateway Rotator
 * Description: Dynamically discovers WooCommerce payment gateways and controls checkout visibility using three states: Invisible, Visible (Rotating), and Always Visible.
 * Version: 2.1.0
 * Author: TNR Development Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNR_Gateway_Rotator_Dynamic {
	const OPTION_KEY         = 'TNR_gateway_rotator_dynamic_settings';
	const ACTIVE_GATEWAY_KEY = 'TNR_gateway_rotator_dynamic_active_gateway';
	const ROTATE_LOCK_PREFIX = 'TNR_gateway_rotator_dynamic_rotated_';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_TNR_gateway_rotator_dynamic_save', array( $this, 'handle_settings_save' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 9999 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'rotate_after_successful_order' ), 20, 3 );
	}

	private function get_registered_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return array();
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		return is_array( $gateways ) ? $gateways : array();
	}

	private function get_registered_gateway_map() {
		$map      = array();
		$gateways = $this->get_registered_gateways();

		foreach ( $gateways as $gateway_id => $gateway_obj ) {
			$map[ $gateway_id ] = array(
				'id'          => $gateway_id,
				'title'       => isset( $gateway_obj->title ) ? $gateway_obj->title : $gateway_id,
				'enabled_raw' => isset( $gateway_obj->enabled ) ? $gateway_obj->enabled : '',
				'class'       => is_object( $gateway_obj ) ? get_class( $gateway_obj ) : '',
			);
		}

		return $map;
	}

	public function get_settings() {
		$defaults = array(
			'kill_switch' => 0,
			'gateways'    => array(),
		);

		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings   = wp_parse_args( $settings, $defaults );
		$registered = $this->get_registered_gateway_map();

		foreach ( $registered as $gateway_id => $gateway_data ) {
			if ( empty( $settings['gateways'][ $gateway_id ] ) || ! is_array( $settings['gateways'][ $gateway_id ] ) ) {
				$settings['gateways'][ $gateway_id ] = array(
					'admin_label' => $gateway_data['title'],
					'state'       => 'always_visible', // safer default
					'weight'      => 100,
				);
			} else {
				$settings['gateways'][ $gateway_id ] = wp_parse_args(
					$settings['gateways'][ $gateway_id ],
					array(
						'admin_label' => $gateway_data['title'],
						'state'       => 'always_visible',
						'weight'      => 100,
					)
				);
			}
		}

		return $settings;
	}

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Gateway Rotator Dynamic',
			'Gateway Rotator Dynamic',
			'manage_woocommerce',
			'TNR-gateway-rotator-dynamic',
			array( $this, 'render_admin_page' )
		);
	}

	public function handle_settings_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'TNR_gateway_rotator_dynamic_save_action', 'TNR_gateway_rotator_dynamic_nonce' );

		$current_settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current_settings ) ) {
			$current_settings = array();
		}

		$current_gateways = isset( $current_settings['gateways'] ) && is_array( $current_settings['gateways'] )
			? $current_settings['gateways']
			: array();

		$posted_gateways = isset( $_POST['gateways'] ) ? (array) wp_unslash( $_POST['gateways'] ) : array();
		$kill_switch     = ! empty( $_POST['kill_switch'] ) ? 1 : 0;

		$new_gateways = array();

		foreach ( $posted_gateways as $gateway_id => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$gateway_id = sanitize_text_field( $gateway_id );

			$existing = isset( $current_gateways[ $gateway_id ] ) && is_array( $current_gateways[ $gateway_id ] )
				? $current_gateways[ $gateway_id ]
				: array();

			$state = isset( $row['state'] ) ? sanitize_text_field( $row['state'] ) : ( isset( $existing['state'] ) ? $existing['state'] : 'always_visible' );

			if ( ! in_array( $state, array( 'invisible', 'rotating', 'always_visible' ), true ) ) {
				$state = 'always_visible';
			}

			$new_gateways[ $gateway_id ] = array(
				'admin_label' => isset( $row['admin_label'] ) ? sanitize_text_field( $row['admin_label'] ) : ( isset( $existing['admin_label'] ) ? sanitize_text_field( $existing['admin_label'] ) : $gateway_id ),
				'state'       => $state,
				'weight'      => isset( $row['weight'] ) ? max( 0, absint( $row['weight'] ) ) : ( isset( $existing['weight'] ) ? absint( $existing['weight'] ) : 100 ),
			);
		}

		$settings = array(
			'kill_switch' => $kill_switch,
			'gateways'    => $new_gateways,
		);

		update_option( self::OPTION_KEY, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'TNR-gateway-rotator-dynamic',
					'updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_rotating_gateways( $settings, $available_gateways ) {
		$usable = array();

		if ( empty( $settings['gateways'] ) || ! is_array( $settings['gateways'] ) ) {
			return $usable;
		}

		foreach ( $settings['gateways'] as $gateway_id => $gateway_settings ) {
			if ( empty( $available_gateways[ $gateway_id ] ) ) {
				continue;
			}

			if ( empty( $gateway_settings['state'] ) || $gateway_settings['state'] !== 'rotating' ) {
				continue;
			}

			if ( absint( $gateway_settings['weight'] ) < 1 ) {
				continue;
			}

			$usable[ $gateway_id ] = $gateway_settings;
		}

		return $usable;
	}

	private function get_always_visible_gateways( $settings, $available_gateways ) {
		$usable = array();

		if ( empty( $settings['gateways'] ) || ! is_array( $settings['gateways'] ) ) {
			return $usable;
		}

		foreach ( $settings['gateways'] as $gateway_id => $gateway_settings ) {
			if ( empty( $available_gateways[ $gateway_id ] ) ) {
				continue;
			}

			if ( empty( $gateway_settings['state'] ) || $gateway_settings['state'] !== 'always_visible' ) {
				continue;
			}

			$usable[ $gateway_id ] = $gateway_settings;
		}

		return $usable;
	}

	private function choose_weighted_gateway( $rotating_gateways ) {
		if ( empty( $rotating_gateways ) ) {
			return '';
		}

		$total_weight = 0;

		foreach ( $rotating_gateways as $gateway_settings ) {
			$total_weight += max( 0, absint( $gateway_settings['weight'] ) );
		}

		if ( $total_weight < 1 ) {
			return '';
		}

		$rand = wp_rand( 1, $total_weight );
		$roll = 0;

		foreach ( $rotating_gateways as $gateway_id => $gateway_settings ) {
			$roll += max( 0, absint( $gateway_settings['weight'] ) );

			if ( $rand <= $roll ) {
				return $gateway_id;
			}
		}

		$keys = array_keys( $rotating_gateways );
		return reset( $keys );
	}

	private function get_current_active_gateway( $settings, $available_gateways ) {
		$rotating_gateways = $this->get_rotating_gateways( $settings, $available_gateways );

		if ( empty( $rotating_gateways ) ) {
			return '';
		}

		$current = get_option( self::ACTIVE_GATEWAY_KEY, '' );

		if ( $current && isset( $rotating_gateways[ $current ] ) ) {
			return $current;
		}

		$new_gateway = $this->choose_weighted_gateway( $rotating_gateways );

		if ( $new_gateway ) {
			update_option( self::ACTIVE_GATEWAY_KEY, $new_gateway );
		}

		return $new_gateway;
	}

	public function filter_available_gateways( $available_gateways ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $available_gateways;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) {
			return $available_gateways;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['kill_switch'] ) ) {
			return $available_gateways;
		}

		$always_visible = $this->get_always_visible_gateways( $settings, $available_gateways );
		$active_gateway = $this->get_current_active_gateway( $settings, $available_gateways );

		$allowed_ids = array_keys( $always_visible );

		if ( $active_gateway ) {
			$allowed_ids[] = $active_gateway;
		}

		$allowed_ids = array_values( array_unique( array_filter( $allowed_ids ) ) );

		// Safety net: if nothing is configured, do not nuke checkout.
		if ( empty( $allowed_ids ) ) {
			return $available_gateways;
		}

		foreach ( $available_gateways as $gateway_id => $gateway_obj ) {
			if ( ! in_array( $gateway_id, $allowed_ids, true ) ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		return $available_gateways;
	}

	public function rotate_after_successful_order( $order_id, $posted_data, $order ) {
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['kill_switch'] ) ) {
			return;
		}

		$order_gateway_id = $order->get_payment_method();

		if ( empty( $order_gateway_id ) ) {
			return;
		}

		if ( empty( $settings['gateways'][ $order_gateway_id ] ) ) {
			return;
		}

		$gateway_settings = $settings['gateways'][ $order_gateway_id ];

		if ( empty( $gateway_settings['state'] ) || $gateway_settings['state'] !== 'rotating' ) {
			return;
		}

		$lock_key = self::ROTATE_LOCK_PREFIX . absint( $order_id );

		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, 60 );

		$registered_gateways = $this->get_registered_gateways();
		$rotating_gateways   = $this->get_rotating_gateways( $settings, $registered_gateways );

		if ( empty( $rotating_gateways ) ) {
			return;
		}

		$next_gateway = $this->choose_weighted_gateway( $rotating_gateways );

		if ( ! empty( $next_gateway ) ) {
			update_option( self::ACTIVE_GATEWAY_KEY, $next_gateway );
		}
	}

	public function render_admin_page() {
		$settings       = $this->get_settings();
		$registered_map = $this->get_registered_gateway_map();
		$current_active = get_option( self::ACTIVE_GATEWAY_KEY, '' );
		$rotating_live  = $this->get_rotating_gateways( $settings, $this->get_registered_gateways() );
		$always_live    = $this->get_always_visible_gateways( $settings, $this->get_registered_gateways() );
		?>
		<div class="wrap">
			<h1>Peptira Gateway Rotator Dynamic</h1>

			<?php if ( isset( $_GET['updated'] ) && $_GET['updated'] === 'true' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Gateway Rotator settings saved.</p>
				</div>
			<?php endif; ?>

			<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:18px 22px; margin:20px 0; max-width:1200px;">
				<h2 style="margin-top:0;">Live Status</h2>
				<p><strong>Global kill switch:</strong> <?php echo ! empty( $settings['kill_switch'] ) ? 'ON (plugin bypassed)' : 'OFF'; ?></p>
				<p><strong>Current active rotating gateway:</strong> <code><?php echo esc_html( $current_active ? $current_active : 'None set yet' ); ?></code></p>
				<p><strong>Rotating gateways right now:</strong> <?php echo esc_html( implode( ', ', array_keys( $rotating_live ) ) ?: 'None' ); ?></p>
				<p><strong>Always visible gateways right now:</strong> <?php echo esc_html( implode( ', ', array_keys( $always_live ) ) ?: 'None' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="TNR_gateway_rotator_dynamic_save">
				<?php wp_nonce_field( 'TNR_gateway_rotator_dynamic_save_action', 'TNR_gateway_rotator_dynamic_nonce' ); ?>

				<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:18px 22px; margin:20px 0; max-width:1200px;">
					<h2 style="margin-top:0;">Global Controls</h2>
					<label style="font-size:14px;">
						<input type="checkbox" name="kill_switch" value="1" <?php checked( ! empty( $settings['kill_switch'] ) ); ?>>
						Turn off the plugin completely and let WooCommerce behave normally
					</label>
				</div>

				<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:18px 22px; margin:20px 0; max-width:1200px;">
					<h2 style="margin-top:0;">Gateway Controls</h2>

					<table class="widefat striped" style="border-radius:8px; overflow:hidden;">
						<thead>
							<tr>
								<th style="width:180px;">Gateway ID</th>
								<th style="width:220px;">Admin Label</th>
								<th style="width:220px;">Woo Label</th>
								<th style="width:180px;">State</th>
								<th style="width:90px;">Weight</th>
								<th>Registered Class</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $registered_map as $gateway_id => $gateway_data ) :
								$row = $settings['gateways'][ $gateway_id ];
							?>
								<tr>
									<td><code><?php echo esc_html( $gateway_id ); ?></code></td>
									<td>
										<input
											type="text"
											name="gateways[<?php echo esc_attr( $gateway_id ); ?>][admin_label]"
											value="<?php echo esc_attr( $row['admin_label'] ); ?>"
											class="regular-text"
											style="width:100%;"
										>
									</td>
									<td><?php echo esc_html( $gateway_data['title'] ); ?></td>
									<td>
										<select name="gateways[<?php echo esc_attr( $gateway_id ); ?>][state]" style="width:100%;">
											<option value="invisible" <?php selected( $row['state'], 'invisible' ); ?>>Invisible</option>
											<option value="rotating" <?php selected( $row['state'], 'rotating' ); ?>>Visible (Rotating)</option>
											<option value="always_visible" <?php selected( $row['state'], 'always_visible' ); ?>>Always Visible</option>
										</select>
									</td>
									<td>
										<input
											type="number"
											name="gateways[<?php echo esc_attr( $gateway_id ); ?>][weight]"
											value="<?php echo esc_attr( absint( $row['weight'] ) ); ?>"
											min="0"
											step="1"
											style="width:90px;"
										>
									</td>
									<td><code><?php echo esc_html( $gateway_data['class'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:14px; color:#50575e;">
						Use <strong>Visible (Rotating)</strong> for gateways that should participate in weighted rotation.
						Use <strong>Always Visible</strong> for gateways that should always appear alongside the current rotating gateway.
						Use <strong>Invisible</strong> to hide a gateway completely.
						Weight only matters for gateways set to <strong>Visible (Rotating)</strong>.
					</p>
				</div>

				<p class="submit">
					<button type="submit" name="TNR_gateway_rotator_dynamic_save" value="1" class="button button-primary">Save Settings</button>
				</p>
			</form>
		</div>
		<?php
	}
}

new TNR_Gateway_Rotator_Dynamic();