<?php
/**
 * Plugin Name:       Nano Unlock
 * Description:       Sell part of a post for a few cents in Nano (XNO). Readers pay your address directly; this site checks the payment with a Nano node. No account, no card, no middleman.
 * Version:           0.1.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            nano133
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nano-unlock
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

define( 'NANO_UNLOCK_VERSION', '0.1.1' );
define( 'NANO_UNLOCK_FILE', __FILE__ );
define( 'NANO_UNLOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'NANO_UNLOCK_URL', plugin_dir_url( __FILE__ ) );

require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-blake2b.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-address.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-amount.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-token.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-verifier.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-busy.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-node.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-price.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-limit.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-store.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-payments.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-settings.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-parts.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-render.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock-rest.php';
require_once NANO_UNLOCK_DIR . 'includes/class-nano-unlock.php';

register_activation_hook( __FILE__, array( 'Nano_Unlock', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Nano_Unlock', 'deactivate' ) );
Nano_Unlock::init();
