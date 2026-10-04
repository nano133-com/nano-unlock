<?php
/**
 * Integration test for paid-part storage, run inside WordPress:
 *   npx wp-env run cli -- wp eval-file wp-content/nano-unlock-tests/parts-test.php
 * It creates its own posts and deletes them at the end.
 *
 * @package NanoUnlock
 */

// phpcs:ignoreFile -- a test script.

$GLOBALS['nu_fail'] = 0;
function nu_check( $ok, $msg ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $ok ) {
		$GLOBALS['nu_fail']++;
	}
}
function nu_parts( $post_id ) {
	$out = array();
	foreach ( get_post_meta( $post_id ) as $k => $v ) {
		if ( 0 === strpos( $k, Nano_Unlock_Parts::META_PREFIX ) ) {
			$out[ substr( $k, strlen( Nano_Unlock_Parts::META_PREFIX ) ) ] = $v[0];
		}
	}
	return $out;
}
/**
 * The post's content as WordPress shows it: by default on the post's own page (a singular main
 * query for it, where a paid part may show); with $own_page false, as from any other page (CLI's
 * own state: not singular), where the plugin shows only a link to the post.
 */
function nu_render( $post_id, $own_page = true ) {
	global $post, $wp_query, $wp_the_query;
	$saved = array( $wp_query, $wp_the_query );
	if ( $own_page ) {
		$wp_query     = new WP_Query( array( 'p' => $post_id, 'post_type' => 'any' ) );
		$wp_the_query = $wp_query;
	}
	$post = get_post( $post_id );
	setup_postdata( $post );
	$html = apply_filters( 'the_content', $post->post_content );
	wp_reset_postdata();
	list( $wp_query, $wp_the_query ) = $saved;
	return $html;
}
function nu_raw( $post_id ) {
	clean_post_cache( $post_id );
	return get_post( $post_id )->post_content;
}
$made = array();
wp_set_current_user( 1 );

echo "— blocks\n";
$content = '<!-- wp:paragraph --><p>Free intro.</p><!-- /wp:paragraph -->

<!-- wp:group --><div class="wp-block-group"><!-- wp:nano-unlock/paywall {"price":"0.02"} -->
<!-- wp:paragraph --><p>SECRET-ONE is inside a group.</p><!-- /wp:paragraph -->
<!-- /wp:nano-unlock/paywall --></div><!-- /wp:group -->

<!-- wp:nano-unlock/paywall -->
<!-- wp:heading --><h2 class="wp-block-heading">SECRET-TWO heading</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>and a "quoted" \\backslash\\ paragraph</p><!-- /wp:paragraph -->
<!-- /wp:nano-unlock/paywall -->';
$id     = wp_insert_post( wp_slash( array( 'post_title' => 'parts test', 'post_content' => $content, 'post_status' => 'publish' ) ) );
$made[] = $id;
$raw    = nu_raw( $id );
$parts  = nu_parts( $id );
nu_check( false === strpos( $raw, 'SECRET' ), 'post_content holds no paid text' );
nu_check( 2 === substr_count( $raw, '"partId":"' ) && 2 === count( $parts ), 'two parts, each stored under its own id' );
nu_check( false !== strpos( $raw, 'wp:group' ) && false !== strpos( $raw, 'Free intro.' ), 'the free content and the group stay as they were' );
nu_check( 1 === count( array_filter( $parts, function ( $p ) { return false !== strpos( $p, '\\backslash\\' ); } ) ), 'backslashes and quotes survive the move' );

wp_set_current_user( 0 );
$html = nu_render( $id );
nu_check( false === strpos( $html, 'SECRET' ) && false !== strpos( $html, 'nano-unlock--locked' ), 'a reader sees the paywall, not the text' );
$elsewhere = nu_render( $id, false );
nu_check( false === strpos( $elsewhere, 'SECRET' ) && false !== strpos( $elsewhere, 'nano-unlock--elsewhere' ), 'on any other page, a reader gets a link to the post, not the paywall or the text' );
wp_set_current_user( 1 );
$html = nu_render( $id );
nu_check( false !== strpos( $html, 'SECRET-ONE' ) && false !== strpos( $html, 'SECRET-TWO' ), 'an editor sees both stored parts (preview)' );

echo "— the editor's REST request\n";
$req = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
$req->set_param( 'context', 'edit' );
$res = rest_do_request( $req );
$ed  = $res->get_data()['content']['raw'];
nu_check( false !== strpos( $ed, 'SECRET-ONE' ) && false !== strpos( $ed, 'SECRET-TWO' ), 'the edit context gets the parts back inside their blocks' );
$top    = array_values( array_filter( parse_blocks( $ed ), function ( $b ) { return 'nano-unlock/paywall' === $b['blockName']; } ) );
nu_check( isset( $top[0]['innerBlocks'][0] ) && 'core/heading' === $top[0]['innerBlocks'][0]['blockName'], 'the parts come back as real inner blocks' );
wp_set_current_user( 0 );
nu_check( 401 === rest_do_request( $req )->get_status(), 'the edit context is refused to a visitor' );
$view = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
$vd   = rest_do_request( $view )->get_data();
nu_check( false === strpos( wp_json_encode( $vd ), 'SECRET' ), 'the public REST answer holds no paid text' );
wp_set_current_user( 1 );

echo "— saving from the editor\n";
$before = nu_raw( $id );
wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $ed ) ) );
nu_check( nu_raw( $id ) === $before && nu_parts( $id ) === $parts, 'saving the loaded content unchanged changes nothing' );
wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => str_replace( 'SECRET-ONE', 'SECRET-EDITED', $ed ) ) ) );
nu_check( 1 === count( array_filter( nu_parts( $id ), function ( $p ) { return false !== strpos( $p, 'SECRET-EDITED' ); } ) ) && 2 === count( nu_parts( $id ) ), 'an edited part is stored, under the same id' );
foreach ( wp_get_post_revisions( $id ) as $rev ) {
	nu_check( false === strpos( $rev->post_content, 'SECRET' ), 'revision ' . $rev->ID . ' holds no paid text' );
}
$self_closing = nu_raw( $id );
wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $self_closing ) ) );
nu_check( 2 === count( nu_parts( $id ) ), 'a save without the parts (an autosave, a tool) keeps the stored text' );
$one = serialize_blocks( array_values( array_filter( parse_blocks( $self_closing ), function ( $b ) { return 'nano-unlock/paywall' !== $b['blockName']; } ) ) );
wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $one ) ) );
nu_check( 1 === count( nu_parts( $id ) ), 'a removed part is deleted' );
wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => '<!-- wp:paragraph --><p>No paid parts now.</p><!-- /wp:paragraph -->' ) ) );
nu_check( 0 === count( nu_parts( $id ) ), 'with no paid part left, none is stored' );

echo "— a duplicated block\n";
$dup    = '<!-- wp:nano-unlock/paywall {"partId":"aaaaaaaaaaaa"} --><!-- wp:paragraph --><p>SECRET-A</p><!-- /wp:paragraph --><!-- /wp:nano-unlock/paywall --><!-- wp:nano-unlock/paywall {"partId":"aaaaaaaaaaaa"} --><!-- wp:paragraph --><p>SECRET-B</p><!-- /wp:paragraph --><!-- /wp:nano-unlock/paywall -->';
$id2    = wp_insert_post( wp_slash( array( 'post_title' => 'dup test', 'post_content' => $dup, 'post_status' => 'publish' ) ) );
$made[] = $id2;
$p2     = nu_parts( $id2 );
nu_check( 2 === count( $p2 ) && isset( $p2['aaaaaaaaaaaa'] ), 'the copy gets its own id; the first keeps its id' );

echo "— shortcodes\n";
$sc     = "Free text.\n\n[nano_unlock price=\"0.01\"]SECRET-SHORT with [b]nested[/b] text[/nano_unlock]\n\nAfter.";
$id3    = wp_insert_post( wp_slash( array( 'post_title' => 'shortcode test', 'post_content' => $sc, 'post_status' => 'publish' ) ) );
$made[] = $id3;
$raw3   = nu_raw( $id3 );
nu_check( false === strpos( $raw3, 'SECRET' ) && 1 === preg_match( '/\[nano_unlock price="0.01" part="[0-9a-f]{12}"\]/', $raw3 ) && false === strpos( $raw3, '[/nano_unlock]' ), 'the shortcode keeps only its tag: ' . trim( substr( $raw3, 12, 50 ) ) );
nu_check( false !== strpos( apply_filters( 'content_edit_pre', $raw3, $id3 ), '[/nano_unlock]' ) && false !== strpos( apply_filters( 'content_edit_pre', $raw3, $id3 ), 'SECRET-SHORT' ), 'the classic editor gets the text back inside the shortcode' );
wp_set_current_user( 0 );
nu_check( false === strpos( nu_render( $id3 ), 'SECRET' ), 'a reader sees the paywall' );
nu_check( false === strpos( apply_filters( 'content_edit_pre', $raw3, $id3 ), 'SECRET' ), 'a visitor gets nothing from the edit filter' );
wp_set_current_user( 1 );
nu_check( false !== strpos( nu_render( $id3 ), 'SECRET-SHORT' ), 'an editor sees it (preview)' );

echo "— search\n";
$q = new WP_Query( array( 's' => 'SECRET-SHORT', 'post_status' => 'publish' ) );
nu_check( 0 === $q->found_posts, 'search does not find paid text' );

echo "— migration of inline parts\n";
global $wpdb;
$inline = '<!-- wp:paragraph --><p>Old post.</p><!-- /wp:paragraph --><!-- wp:nano-unlock/paywall {"price":"0.03"} --><!-- wp:paragraph --><p>SECRET-OLD</p><!-- /wp:paragraph --><!-- /wp:nano-unlock/paywall -->[nano_unlock]SECRET-OLD-SHORT[/nano_unlock]';
$id4    = wp_insert_post( array( 'post_title' => 'old post', 'post_content' => 'placeholder', 'post_status' => 'publish' ) );
$made[] = $id4;
$wpdb->update( $wpdb->posts, array( 'post_content' => $inline, 'post_modified_gmt' => '2020-01-01 00:00:00' ), array( 'ID' => $id4 ) );
$rev    = wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $id4, 'post_status' => 'inherit', 'post_title' => 'old post', 'post_content' => 'placeholder' ) );
$wpdb->update( $wpdb->posts, array( 'post_content' => $inline ), array( 'ID' => $rev ) );
clean_post_cache( $id4 );
clean_post_cache( $rev );
delete_option( Nano_Unlock_Parts::MIGRATED );
$n = Nano_Unlock_Parts::migrate();
nu_check( $n >= 2, "the migration changed the post and its revision ($n)" );
$raw4 = nu_raw( $id4 );
nu_check( false === strpos( $raw4, 'SECRET' ) && 2 === count( nu_parts( $id4 ) ), 'the old post now keeps its two parts in meta' );
nu_check( false === strpos( nu_raw( $rev ), 'SECRET' ) && 0 === count( nu_parts( $rev ) ), 'its revision holds no paid text and stores none' );
nu_check( '2020-01-01 00:00:00' === get_post( $id4 )->post_modified_gmt, 'the modified time is unchanged (old offers stay valid)' );
nu_check( '1' === get_option( Nano_Unlock_Parts::MIGRATED ), 'the migration is marked done' );
nu_check( 0 === Nano_Unlock_Parts::migrate() && nu_raw( $id4 ) === $raw4, 'running it again changes nothing' );
wp_set_current_user( 1 );
$h4 = nu_render( $id4 );
nu_check( false !== strpos( $h4, 'SECRET-OLD' ) && false !== strpos( $h4, 'SECRET-OLD-SHORT' ), 'the migrated parts still render' );

foreach ( $made as $p ) {
	wp_delete_post( $p, true );
}
nu_check( 0 === count( nu_parts( $id ) ) && 0 === count( nu_parts( $id4 ) ), 'deleting a post deletes its parts' );
echo $GLOBALS['nu_fail'] ? "\n{$GLOBALS['nu_fail']} FAILED\n" : "\nall passed\n";
exit( $GLOBALS['nu_fail'] ? 1 : 0 );
