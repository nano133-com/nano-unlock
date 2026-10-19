<?php
/**
 * "Not now."
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Not now: the node didn't answer or answered with an error (nothing can be proven
 * right now), or no free amount was found for a new checkout. Either way the reader
 * is asked to try again in a moment, and nothing is unlocked.
 */
class Nano_Unlock_Busy extends Exception {
}
