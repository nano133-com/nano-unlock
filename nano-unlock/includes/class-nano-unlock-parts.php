<?php
/**
 * Where paid parts are stored.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paid parts live in post meta, never in post_content.
 *
 * With the paid text inside post_content, turning the plugin off would print
 * it for everyone (WordPress shows an unknown block's inner content and a
 * shortcode's text as they are). So on every save, each paid part is moved
 * to its own protected meta entry, `_nano_unlock_part_<id>`, and the post
 * keeps only a self-closing block, `<!-- wp:nano-unlock/paywall
 * {"partId":"…"} /-->`, or a self-closing `[nano_unlock part="…"]`. With the
 * plugin off, WordPress drops the unknown block and shows only the shortcode
 * tag. The post's revisions, search, feeds and the public REST API never
 * hold the paid text either, because none of them reads this meta.
 *
 * Authors keep the normal editor: when a post is opened for editing (the
 * block editor's REST request in the edit context, or the classic editor),
 * the stored parts are put back inside their blocks and shortcodes, and the
 * next save moves them out again. A paid part saved without its content
 * (for example from an autosave, or by a tool that doesn't load it) keeps
 * the stored text for its id.
 */
final class Nano_Unlock_Parts {

	const META_PREFIX = '_nano_unlock_part_';
	const BLOCK       = 'nano-unlock/paywall';
	const MIGRATED    = 'nano_unlock_parts_version';
	const VERSION     = '1';

	/**
	 * Parts moved out by the save in progress, written once the post has an id.
	 *
	 * @var array{id: int, parts: array<string, string>, keep: string[]}|null
	 */
	private static $pending = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'wp_insert_post', array( __CLASS__, 'store' ), 10, 3 );
		add_filter( 'content_edit_pre', array( __CLASS__, 'for_classic_editor' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_hooks' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );
	}

	/**
	 * The meta key of a part.
	 *
	 * @param string $id The part's id.
	 * @return string
	 */
	public static function key( $id ) {
		return self::META_PREFIX . $id;
	}

	/**
	 * A stored part's content, or null.
	 *
	 * @param int    $post_id The post.
	 * @param string $id      The part's id.
	 * @return string|null
	 */
	public static function get( $post_id, $id ) {
		if ( ! self::valid_id( $id ) || ! metadata_exists( 'post', $post_id, self::key( $id ) ) ) {
			return null;
		}
		return (string) get_post_meta( $post_id, self::key( $id ), true );
	}

	/**
	 * Whether content has a paid part in any form.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function mentions( $content ) {
		return false !== strpos( $content, '<!-- wp:' . self::BLOCK ) || false !== strpos( $content, '[nano_unlock' );
	}

	/**
	 * Moves the paid parts out of $content.
	 *
	 * @param string   $content The content.
	 * @param array    $parts   Filled with id => paid content, for the parts that carried content.
	 * @param string[] $keep    Filled with the ids of parts that carried none (their stored text stays).
	 * @return string The content with only self-closing paid parts.
	 */
	public static function extract( $content, &$parts, &$keep ) {
		$parts = array();
		$keep  = array();
		if ( false !== strpos( $content, '<!-- wp:' . self::BLOCK ) ) {
			$changed = false;
			$blocks  = self::extract_blocks( parse_blocks( $content ), $parts, $keep, $changed );
			if ( $changed ) {
				$content = serialize_blocks( $blocks );
			}
		}
		if ( false !== strpos( $content, '[nano_unlock' ) ) {
			$content = preg_replace_callback(
				'/' . get_shortcode_regex( array( 'nano_unlock' ) ) . '/',
				function ( $m ) use ( &$parts, &$keep ) {
					if ( '[' === $m[1] && ']' === $m[6] ) {
						return $m[0]; // An escaped [[nano_unlock]]: text, not a paid part.
					}
					$atts = shortcode_parse_atts( $m[3] );
					$want = is_array( $atts ) && isset( $atts['part'] ) ? (string) $atts['part'] : '';
					if ( '' === trim( (string) $m[5] ) ) {
						if ( self::valid_id( $want ) ) {
							$keep[] = $want;
						}
						return $m[0];
					}
					$id           = self::fresh_id( $want, $parts );
					$parts[ $id ] = (string) $m[5];
					return '[nano_unlock' . self::without_part( $m[3] ) . ' part="' . $id . '"]';
				},
				$content
			);
		}
		return $content;
	}

	/**
	 * Puts stored parts back into $content, for editing.
	 *
	 * @param string $content The stored content.
	 * @param int    $post_id The post.
	 * @return string
	 */
	public static function inject( $content, $post_id ) {
		if ( ! self::mentions( (string) $content ) ) {
			return $content;
		}
		if ( false !== strpos( $content, '<!-- wp:' . self::BLOCK ) ) {
			$changed = false;
			$blocks  = self::inject_blocks( parse_blocks( $content ), (int) $post_id, $changed );
			if ( $changed ) {
				$content = serialize_blocks( $blocks );
			}
		}
		if ( false !== strpos( $content, '[nano_unlock' ) ) {
			$content = preg_replace_callback(
				'/' . get_shortcode_regex( array( 'nano_unlock' ) ) . '/',
				function ( $m ) use ( $post_id ) {
					if ( ( '[' === $m[1] && ']' === $m[6] ) || '' !== trim( (string) $m[5] ) ) {
						return $m[0];
					}
					$atts = shortcode_parse_atts( $m[3] );
					$text = is_array( $atts ) && isset( $atts['part'] ) ? self::get( (int) $post_id, (string) $atts['part'] ) : null;
					return null === $text ? $m[0] : '[nano_unlock' . rtrim( $m[3] ) . ']' . $text . '[/nano_unlock]';
				},
				$content
			);
		}
		return $content;
	}

	/**
	 * The block-tree half of extract().
	 *
	 * @param array[]  $blocks  Parsed blocks.
	 * @param array    $parts   Parts found.
	 * @param string[] $keep    Ids kept without content.
	 * @param bool     $changed Set when a block changed.
	 * @return array[]
	 */
	private static function extract_blocks( array $blocks, &$parts, &$keep, &$changed ) {
		foreach ( $blocks as $i => $block ) {
			if ( self::BLOCK === $block['blockName'] ) {
				$want = isset( $block['attrs']['partId'] ) ? (string) $block['attrs']['partId'] : '';
				if ( empty( $block['innerBlocks'] ) ) {
					if ( self::valid_id( $want ) ) {
						$keep[] = $want;
					}
					continue;
				}
				$id                       = self::fresh_id( $want, $parts );
				$parts[ $id ]             = serialize_blocks( $block['innerBlocks'] );
				$block['attrs']['partId'] = $id;
				$block['innerBlocks']     = array();
				$block['innerHTML']       = '';
				$block['innerContent']    = array();
				$blocks[ $i ]             = $block;
				$changed                  = true;
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::extract_blocks( $block['innerBlocks'], $parts, $keep, $changed );
			}
		}
		return $blocks;
	}

	/**
	 * The block-tree half of inject().
	 *
	 * @param array[] $blocks  Parsed blocks.
	 * @param int     $post_id The post.
	 * @param bool    $changed Set when a block changed.
	 * @return array[]
	 */
	private static function inject_blocks( array $blocks, $post_id, &$changed ) {
		foreach ( $blocks as $i => $block ) {
			if ( self::BLOCK === $block['blockName'] ) {
				$text = empty( $block['innerBlocks'] ) && isset( $block['attrs']['partId'] ) ? self::get( $post_id, (string) $block['attrs']['partId'] ) : null;
				if ( null === $text ) {
					continue;
				}
				$inner   = array_values(
					array_filter(
						parse_blocks( $text ),
						function ( $b ) {
							return null !== $b['blockName'] || '' !== trim( $b['innerHTML'] );
						}
					)
				);
				$content = array( "\n" );
				foreach ( $inner as $unused ) {
					$content[] = null;
					$content[] = "\n";
				}
				$block['innerBlocks']  = $inner;
				$block['innerHTML']    = str_repeat( "\n", count( $inner ) + 1 );
				$block['innerContent'] = $content;
				$blocks[ $i ]          = $block;
				$changed               = true;
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::inject_blocks( $block['innerBlocks'], $post_id, $changed );
			}
		}
		return $blocks;
	}

	/**
	 * Every save: move the paid parts out of the content. A revision (or an autosave) keeps none at all.
	 *
	 * @param array $data    The slashed post data.
	 * @param array $postarr The raw post array.
	 * @return array
	 */
	public static function on_save( $data, $postarr ) {
		$content  = wp_unslash( (string) $data['post_content'] );
		$revision = 'revision' === $data['post_type'];
		if ( ! self::mentions( $content ) ) {
			// No paid part left: an update forgets the stored ones.
			if ( ! $revision && ! empty( $postarr['ID'] ) ) {
				self::$pending = array(
					'id'    => (int) $postarr['ID'],
					'parts' => array(),
					'keep'  => array(),
				);
			}
			return $data;
		}
		$parts                = array();
		$keep                 = array();
		$data['post_content'] = wp_slash( self::extract( $content, $parts, $keep ) );
		if ( ! $revision ) {
			self::$pending = array(
				'id'    => empty( $postarr['ID'] ) ? 0 : (int) $postarr['ID'],
				'parts' => $parts,
				'keep'  => $keep,
			);
		}
		return $data;
	}

	/**
	 * After a save: store the parts under the post, and delete the ones it no longer has.
	 *
	 * @param int     $post_id The post.
	 * @param WP_Post $post    The post.
	 * @param bool    $update  Whether an existing post was updated.
	 */
	public static function store( $post_id, $post, $update ) {
		if ( null === self::$pending || 'revision' === $post->post_type ) {
			return;
		}
		$pending       = self::$pending;
		self::$pending = null;
		// Only the save that moved them out: an update of that post, or the new post just made.
		if ( $pending['id'] ? (int) $post_id !== $pending['id'] : $update ) {
			return;
		}
		foreach ( $pending['parts'] as $id => $text ) {
			update_post_meta( $post_id, self::key( $id ), wp_slash( $text ) );
		}
		$live = array_merge( array_map( 'strval', array_keys( $pending['parts'] ) ), $pending['keep'] );
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( 0 === strpos( $key, self::META_PREFIX ) && ! in_array( substr( $key, strlen( self::META_PREFIX ) ), $live, true ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * The classic editor: show the stored parts inside their shortcodes.
	 *
	 * @param string $content The content being edited.
	 * @param int    $post_id The post.
	 * @return string
	 */
	public static function for_classic_editor( $content, $post_id = 0 ) {
		return $post_id && current_user_can( 'edit_post', $post_id ) ? self::inject( $content, $post_id ) : $content;
	}

	/**
	 * The block editor reads posts through the REST API in the edit context.
	 */
	public static function rest_hooks() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			add_filter( "rest_prepare_{$type}", array( __CLASS__, 'for_block_editor' ), 10, 3 );
		}
	}

	/**
	 * Only in the edit context, and only for someone who can edit the post: the raw content with its parts.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WP_Post          $post     The post.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public static function for_block_editor( $response, $post, $request ) {
		if ( 'edit' !== $request['context'] || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content']['raw'] ) ) {
			$data['content']['raw'] = self::inject( $data['content']['raw'], $post->ID );
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Moves inline paid parts of existing posts (and strips them from revisions), once per version.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED ) !== self::VERSION ) {
			self::migrate();
		}
	}

	/**
	 * The migration. Safe to run again: content that holds no paid text is left as it is.
	 * It writes the database directly, so it neither makes revisions nor changes the modified time.
	 *
	 * @return int How many posts (and revisions) changed.
	 */
	public static function migrate() {
		global $wpdb;
		$changed = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-time migration over post content.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_parent, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_content LIKE %s",
				'%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK ) . '%',
				'%' . $wpdb->esc_like( '[nano_unlock' ) . '%'
			)
		);
		foreach ( $rows as $row ) {
			$parts   = array();
			$keep    = array();
			$content = self::extract( $row->post_content, $parts, $keep );
			if ( ! $parts ) {
				continue;
			}
			if ( 'revision' !== $row->post_type ) {
				foreach ( $parts as $id => $text ) {
					update_post_meta( (int) $row->ID, self::key( $id ), wp_slash( $text ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above; the cache is cleared right after.
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $row->ID ) );
			clean_post_cache( (int) $row->ID );
			++$changed;
		}
		update_option( self::MIGRATED, self::VERSION );
		return $changed;
	}

	/**
	 * Deletes every stored part (uninstall with "delete data").
	 */
	public static function delete_all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::META_PREFIX ) . '%' ) );
	}

	/**
	 * A part id: 12 lowercase hex characters.
	 *
	 * @param string $id The id.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{12}$/', $id );
	}

	/**
	 * $want if it is a valid id not used yet in this save, or a new one (a duplicated block gets its own).
	 *
	 * @param string $want  The id the part carries.
	 * @param array  $parts Parts found so far in this save.
	 * @return string
	 */
	private static function fresh_id( $want, array $parts ) {
		if ( self::valid_id( $want ) && ! isset( $parts[ $want ] ) ) {
			return $want;
		}
		do {
			$id = bin2hex( random_bytes( 6 ) );
		} while ( isset( $parts[ $id ] ) );
		return $id;
	}

	/**
	 * A shortcode's attribute text without its part="…".
	 *
	 * @param string $atts The attribute text.
	 * @return string
	 */
	private static function without_part( $atts ) {
		return rtrim( (string) preg_replace( '/\s*\bpart\s*=\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '', $atts ) );
	}
}
