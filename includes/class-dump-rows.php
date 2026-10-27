<?php
/**
 * Which rows go into a dump, and how they change on the way.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Picks and changes the rows of a dump as All-in-One WP Migration does, so that either importer
 * restores it: ai1wm's private settings stay out, the theme and active plugins are left to
 * package.json, and prefix-built names carry the placeholder. Caches and sessions, which both
 * importers drop, stay out too.
 */
final class Dump_Rows {

	/** All-in-One WP Migration's own settings, which stay on each site. */
	const PRIVATE_OPTIONS = array( 'ai1wm_status', 'ai1wm_secret_key', 'ai1wm_auth_user', 'ai1wm_auth_password', 'ai1wm_auth_header', 'ai1wm_backups_labels', 'ai1wm_sites_links' );

	/** Starts of the option names both importers drop: caches and sessions. */
	const CACHE_OPTIONS = array( '_transient_', '_site_transient_', '_wc_session_', '_wpallimport_session_' );

	/** WordPress tables whose rows are filtered or changed. */
	const CORE = array( 'options', 'usermeta', 'comments', 'commentmeta', 'posts', 'postmeta' );

	/** Dump bytes a query should bring. */
	const BATCH_BYTES = 2097152;

	const MAX_BATCH = 5000;

	/**
	 * Which of the WordPress tables this is, or null for any other table.
	 *
	 * @param string $table  Table name.
	 * @param string $prefix Site prefix.
	 * @param bool   $fold   Whether the server compares table names without case.
	 * @return string|null Name without the prefix.
	 */
	public static function core_table( $table, $prefix, $fold ) {
		foreach ( self::CORE as $core ) {
			if ( $fold ? 0 === strcasecmp( $table, $prefix . $core ) : $table === $prefix . $core ) {
				return $core;
			}
		}
		return null;
	}

	/**
	 * The rows of a table that go into the backup, as a WHERE condition.
	 *
	 * @param string|null $core    What core_table() says of the table.
	 * @param string      $prefix  Site prefix.
	 * @param array       $options no_spam_comments, no_post_revisions.
	 * @return string "" for every row.
	 */
	public static function filter( $core, $prefix, array $options ) {
		switch ( $core ) {
			case 'options':
				$where = "`option_name` NOT IN ('" . implode( "', '", self::PRIVATE_OPTIONS ) . "')";
				foreach ( self::CACHE_OPTIONS as $start ) {
					$where .= ' AND LEFT(`option_name`, ' . strlen( $start ) . ") <> '" . $start . "'";
				}
				return $where;
			case 'comments':
				return empty( $options['no_spam_comments'] ) ? '' : "`comment_approved` <> 'spam'";
			case 'commentmeta':
				return empty( $options['no_spam_comments'] ) ? '' : '`comment_id` IN (SELECT `comment_ID` FROM `' . $prefix . "comments` WHERE `comment_approved` <> 'spam')";
			case 'posts':
				return empty( $options['no_post_revisions'] ) ? '' : "`post_type` <> 'revision'";
			case 'postmeta':
				return empty( $options['no_post_revisions'] ) ? '' : '`post_id` IN (SELECT `ID` FROM `' . $prefix . "posts` WHERE `post_type` <> 'revision')";
		}
		return '';
	}

	/**
	 * What changes the rows of a table on their way into the dump.
	 *
	 * @param string|null $core    What core_table() says of the table.
	 * @param string[]    $columns Column names, in order.
	 * @param string      $prefix  Site prefix.
	 * @return callable|null Takes and returns a row as a list of values; null when rows stay as they are.
	 */
	public static function changer( $core, array $columns, $prefix ) {
		if ( 'options' === $core ) {
			$name  = array_search( 'option_name', $columns, true );
			$value = array_search( 'option_value', $columns, true );
			if ( false === $name || false === $value ) {
				return null;
			}
			return static function ( array $row ) use ( $name, $value, $prefix ) {
				if ( 'active_plugins' === $row[ $name ] ) {
					$row[ $value ] = 'a:0:{}';
				} elseif ( 'template' === $row[ $name ] || 'stylesheet' === $row[ $name ] ) {
					$row[ $value ] = '';
				}
				$row[ $name ] = Dump_Format::option_name( $row[ $name ], $prefix );
				return $row;
			};
		}
		if ( 'usermeta' === $core ) {
			$key = array_search( 'meta_key', $columns, true );
			if ( false === $key ) {
				return null;
			}
			return static function ( array $row ) use ( $key, $prefix ) {
				if ( null !== $row[ $key ] ) {
					$row[ $key ] = Dump_Format::meta_key( $row[ $key ], $prefix );
				}
				return $row;
			};
		}
		return null;
	}

	/**
	 * Rows to fetch next, so a query brings about BATCH_BYTES of dump: large rows are fetched a
	 * few at a time and never fill PHP's memory.
	 *
	 * @param int $batch Rows the last query asked for.
	 * @param int $bytes Dump bytes they made.
	 * @return int
	 */
	public static function next_batch( $batch, $bytes ) {
		$fit = $bytes > 0 ? (int) ( $batch * self::BATCH_BYTES / $bytes ) : PHP_INT_MAX;
		return max( 1, min( self::MAX_BATCH, $batch * 4, $fit ) );
	}
}
