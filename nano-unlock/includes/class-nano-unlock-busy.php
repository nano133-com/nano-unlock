<?php
/**
 * The node's "no".
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * The node didn't answer, or answered with an error: nothing can be proven right now.
 */
class Nano_Unlock_Busy extends Exception {
}
