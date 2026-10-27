<?php
/**
 * package.json for the backups this plugin makes.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Describes the site in the package.json shape All-in-One WP Migration writes and reads.
 */
final class Package_Builder {

	const NAME = 'WPress Restore';

	/**
	 * @param array $site    Facts about the site (addresses, paths, versions, prefix, plugins, theme).
	 * @param array $options password, compression, no_media, no_spam_comments, no_post_revisions, no_cache.
	 * @return array
	 */
	public static function build( array $site, array $options ) {
		$plugins = array();
		foreach ( (array) $site['active_plugins'] as $plugin ) {
			// Neither this plugin nor All-in-One WP Migration is in the backup: each importer keeps its own copy.
			if ( $plugin !== $site['own_plugin'] && 0 !== strpos( $plugin, 'all-in-one-wp-migration' ) ) {
				$plugins[] = $plugin;
			}
		}
		$package = array(
			'SiteURL'         => $site['site_url'],
			'HomeURL'         => $site['home_url'],
			'InternalSiteURL' => $site['internal_site_url'],
			'InternalHomeURL' => $site['internal_home_url'],
		);
		foreach ( array(
			'no_spam_comments'  => 'NoSpamComments',
			'no_post_revisions' => 'NoPostRevisions',
			'no_media'          => 'NoMedia',
			'no_cache'          => 'NoCache',
		) as $option => $flag ) {
			if ( ! empty( $options[ $option ] ) ) {
				$package[ $flag ] = true;
			}
		}
		$package['Plugin']     = array(
			'Name'    => self::NAME,
			'Version' => $site['version'],
		);
		$package['WordPress']  = array(
			'Version'    => $site['wordpress'],
			'Absolute'   => $site['absolute'],
			'Content'    => $site['content'],
			'Plugins'    => $site['plugins_dir'],
			'Themes'     => $site['themes_dirs'],
			'Uploads'    => $site['uploads_dir'],
			'UploadsURL' => $site['uploads_url'],
		);
		$package['Database']   = array(
			'Version' => $site['database'],
			'Charset' => $site['charset'],
			'Collate' => $site['collate'],
			'Prefix'  => $site['prefix'],
		);
		$package['PHP']        = array(
			'Version' => $site['php'],
			'System'  => $site['os'],
			'Integer' => $site['int_size'],
		);
		$package['Plugins']    = $plugins;
		$package['Template']   = $site['template'];
		$package['Stylesheet'] = $site['stylesheet'];
		$package['Uploads']    = $site['upload_path'];
		$package['UploadsURL'] = $site['upload_url_path'];
		$package['Server']     = array(
			'.htaccess'  => base64_encode( (string) $site['htaccess'] ),
			'web.config' => base64_encode( (string) $site['webconfig'] ),
		);
		if ( isset( $options['password'] ) && '' !== $options['password'] ) {
			$package['Encrypted']          = true;
			$package['EncryptedSignature'] = Codec::signature( $options['password'] );
		}
		if ( ! empty( $options['compression'] ) ) {
			$package['Compression'] = array(
				'Enabled' => true,
				'Type'    => $options['compression'],
			);
		}
		return $package;
	}
}
