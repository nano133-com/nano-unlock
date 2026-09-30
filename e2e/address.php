<?php
/**
 * Prints a valid nano_ address made from a label (test addresses only: nobody holds their keys).
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../nano-unlock/includes/class-nano-unlock-blake2b.php';
require __DIR__ . '/../nano-unlock/includes/class-nano-unlock-address.php';
echo Nano_Unlock_Address::from_public_key( hash( 'sha256', 'nano-unlock test ' . ( $argv[1] ?? 'site' ), true ) );
