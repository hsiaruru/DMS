<?php
/**
 * Controls and Manages PageLines Extension
 *
 *
 *
 * @author		PageLines
 * @copyright	2011 PageLines
 */

class PageLinesRegister {

	function __construct() {
		$this->username = get_pagelines_credentials( 'user' );
		$this->password = get_pagelines_credentials( 'pass' );
	}
	/**
	 *  Scans THEMEDIR/sections recursively for section files and auto loads them.
	 *  Child section folder also scanned if found and dependencies resolved.
	 *
	 *  Section files MUST include a class header and optional depends header.
	 *
	 *  Example section header:
	 *
	 *	Section: BrandNav Section
	 *	Author: PageLines
	 *	Description: Branding and Nav Inline
	 *	Version: 1.0.0
	 *	Class Name: BrandNav
	 *	Depends: PageLinesNav
	 *
	 *  @package PageLines Framework
	 *  @subpackage Config
	 *  @since 2.0
	 *
	 */
	function pagelines_register_sections( $reset = null, $echo = null ){

		global $pl_section_factory;

		if ( $reset )
			delete_transient( 'pagelines_sections_cache' );

		$section_dirs = pl_get_section_dirs();

		/**
		* If cache exists load into $sections array
		* If not populate array and prime cache
		*/
		if ( ! $sections = get_transient( 'pagelines_sections_cache' ) ) {

			foreach ( $section_dirs as $type => $dir )
				$sections[ $type ] = $this->get_sections( $dir, $type );

			// check for deps within the main parent sections, load last if found.
			foreach ( $sections['parent'] as $key => $section ) {

				if ( !empty( $section['depends'] ) ) {
					unset( $sections['parent'][ $key ] );
					$sections['parent'][ $key ] = $section;
				}
			}
			/**
			* TODO switch this to activation/deactivation interface
			* TODO better idea, clear cached vars on settings save.
			*/
			set_transient( 'pagelines_sections_cache', $sections, 86400 );
		}

		if ( $echo )
			return $sections;

		// filter main array containing child and parent and any custom sections
		$sections = apply_filters( 'pagelines_section_admin', $sections );
		$disabled = pl_get_disabled_sections();

		foreach ( $sections as $type ) {

			if ( !is_array( $type ) )
				continue;

			foreach ( $type as $section ) {

				if ( ! isset( $section['loadme'] ) )
					$section['loadme'] = false;

				if ( 'parent' == $section['type'] || ! is_multisite() )
					$section['loadme'] = true;
				
				/**
				* Checks to see if we are a child section, if so disable the parent
				* Also if a parent section and disabled, skip.
				*/
				if ( 'parent' != $section['type'] && isset( $sections['parent'][ $section['class'] ] ) )
					$disabled['parent'][ $section['class'] ] = true;

				if ( isset( $disabled[ $section['type'] ][ $section['class'] ] ) && ! $section['persistant'] )
					continue;

				// consolidate array vars
				$dep = ( 'parent' != $section['type'] && $section['depends'] ) ? $section['depends'] : null;
				$parent_dep = isset( $sections['parent'][ $section['depends'] ] ) ? $sections['parent'][ $section['depends'] ] : null;

				$dep_data = array(
					'base_dir'  => isset( $parent_dep['base_dir'] )		? $parent_dep['base_dir']	: null,
					'base_url'  => isset( $parent_dep['base_url'] )		? $parent_dep['base_url']	: null,
					'base_file' => isset( $parent_dep['base_file'] )	? $parent_dep['base_file']	: null,
					'name'		=> isset( $parent_dep['name'] )			? $parent_dep['name']		: null
				);

				$section_data = array(
					'base_dir'  => $section['base_dir'],
					'base_url'  => $section['base_url'],
					'base_file' => $section['base_file'],
					'name'		=> $section['name']
				);

				if ( isset( $dep ) && $section['loadme'] ) { // do we have a dependency?
					if ( !class_exists( $dep ) && is_file( $dep_data['base_file'] ) ) {
						include( $dep_data['base_file'] );
						$pl_section_factory->register( $dep, $dep_data );
					}
					// dep loaded...
					if ( !class_exists( $section['class'] ) && class_exists( $dep ) && is_file( $section['base_file'] ) ) {
						include( $section['base_file'] );
						$pl_section_factory->register( $section['class'], $section_data );
					}
				} else {
					if ( !class_exists( $section['class'] ) && is_file( $section['base_file'] ) && ! isset( $disabled['parent'][$section['depends']] ) ) {
						include( $section['base_file'] );
						$pl_section_factory->register( $section['class'], $section_data );
					}
				}
			}
		}
		pagelines_register_hook('pagelines_register_sections'); // Hook
	}
	/**
	 *
	 * Helper function
	 * Returns array of section files.
	 * @return array of php files
	 * @author Simon Prosser
	 **/
	function get_sections( $dir, $type ) {

		if ( 'parent' != $type && ! is_dir( $dir ) )
			return;

		if ( is_multisite() )
			$store_sections = $this->get_latest_cached( 'sections' );

		$default_headers = array(
			'External'		=> 'External',
			'Demo'			=> 'Demo',
			'tags'			=> 'Tags',
			'version'		=> 'Version',
			'author'		=> 'Author',
			'authoruri'		=> 'Author URI',
			'section'		=> 'Section',
			'description'	=> 'Description',
			'classname'		=> 'Class Name',
			'depends'		=> 'Depends',
			'workswith'		=> 'workswith',
			'isolate'		=> 'isolate',
			'edition'		=> 'edition',
			'cloning'		=> 'cloning',
			'failswith'		=> 'failswith',
			'tax'			=> 'tax',
			'persistant'	=> 'Persistant',
			'format'		=> 'Format',
			'classes'		=> 'Classes',
			'filter'		=> 'Filter',
			'loading'		=> 'Loading'
		);

		$sections = array();

		// setup out directory iterator.
		// symlinks were only supported after 5.3.1
		// so we need to check first ;)
		$it = ( strnatcmp( phpversion(), '5.3.1' ) >= 0 )
			? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::FOLLOW_SYMLINKS		) , RecursiveIteratorIterator::SELF_FIRST )
			: new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveIteratorIterator::CHILD_FIRST	)
		);

		foreach ( $it as $fullFileName => $fileSPLObject ) {

			if ( basename( $fullFileName ) == PL_EXTEND_SECTIONS_PLUGIN )
				continue;

			if ( 'php' != pathinfo( $fileSPLObject->getFilename(), PATHINFO_EXTENSION ) )
				continue;

			$base_url = null;
			$base_dir = null;
			$load     = true;
			$price    = '';
			$uid      = '';
			$headers  = get_file_data( $fullFileName, $default_headers );

			// If no pagelines class headers ignore this file.
			// beyond this point $fullFileName should refer to a section.php
			if ( !$headers['classname'] )
				continue;
			
			preg_match( '#[\/|\-]sections[\/|\\\]([^\/|\\\]+)#', $fullFileName, $out );
			$folder = sprintf( '/%s', $out[1] );
			
			// base values
			$version  = $headers['version'] ? $headers['version'] : PL_CORE_VERSION;
			$base_dir = PL_SECTIONS . $folder;
			$base_url = PL_SECTION_ROOT . $folder;

			if ( 'child' == $type ) {
				$base_url =  PL_EXTEND_URL . $folder;
				$base_dir =  PL_EXTEND_DIR . $folder;
			}
			if ( 'custom' == $type ) {
				$base_url =  get_stylesheet_directory_uri()  . '/sections' . $folder;
				$base_dir =  get_stylesheet_directory()  . '/sections' . $folder;
			}

			/*
			* Look for custom dirs.
			*/
			if ( !in_array( $type, array('custom','child','parent') ) ) {

				// Ok so we're a plugin then.. if not active then bypass.

				$check = str_replace('\\', '/', $fullFileName); // must convert backslashes before preg_match
				preg_match( '#\/sections\/([^\/]+)#', $check, $out );

				if ( ! isset( $out[1] ) )
					continue;

				$section_slug = $type;
				$file  = basename( $dir );
				$pfile = sprintf( '%s/%s.php', $section_slug, $section_slug );

				if ( ! is_plugin_active( $pfile ) )
					continue;

				$url  = plugins_url( $type );

				$base_url = sprintf( '%s/%s/%s', untrailingslashit( $url ), $file, $section_slug );
				$base_dir = sprintf( '%s/%s/', untrailingslashit( $dir ), $section_slug );
			}


			// do we need to load this section?
			if ( 'child' == $type && is_multisite() ) {
				$load      = false;
				$slug      = basename( $folder );
				$purchased = ( isset( $store_sections->$slug->purchased ) ) ? $store_sections->$slug->purchased : '';
				$plus      = ( isset( $store_sections->$slug->plus_product ) ) ? $store_sections->$slug->plus_product : '';
				$price     = ( isset( $store_sections->$slug->price ) ) ? $store_sections->$slug->price : '';
				$uid       = ( isset( $store_sections->$slug->uid ) ) ? $store_sections->$slug->uid : '';

				if ( 'purchased' === $purchased )
					$load = true;
				elseif ( $plus && pagelines_check_credentials( 'plus' ) )
					$load = true;
				else {
					$disabled = pl_get_disabled_sections();

					if ( ! isset( $disabled['child'][ $headers['classname'] ] ) )
						$load = true;
				}
			}

			if ( $load )
				$purchased = 'purchased';

			$sections[ $headers['classname'] ] = array(
				'class'			=> $headers['classname'],
				'depends'		=> $headers['depends'],
				'type'			=> $type,
				'tags'			=> $headers['tags'],
				'author'		=> $headers['author'],
				'version'		=> $version,
				'authoruri'		=> ( isset( $headers['authoruri'] ) ) ? $headers['authoruri'] : '',
				'description'	=> $headers['description'],
				'name'			=> $headers['section'],
				'base_url'		=> $base_url,
				'base_dir'		=> $base_dir,
				'base_file'		=> $fullFileName,
				'workswith'		=> ( $headers['workswith'] ) ? array_map( 'trim', explode( ',', $headers['workswith'] ) ) : '',
				'isolate'		=> ( $headers['isolate'] ) ? array_map( 'trim', explode( ',', $headers['isolate'] ) ) : '',
				'edition'		=> $headers['edition'],
				'cloning'		=> ( 'true' === $headers['cloning'] ) ? true : '',
				'failswith'		=> ( $headers['failswith'] ) ? array_map( 'trim', explode( ',', $headers['failswith'] ) ) : '',
				'tax'			=> $headers['tax'],
				'demo'			=> $headers['Demo'],
				'external'		=> $headers['External'],
				'persistant'	=> $headers['persistant'],
				'format'		=> $headers['format'],
				'classes'		=> $headers['classes'],
				'screenshot'	=> ( is_file( $base_dir . '/thumb.png' ) ) ? $base_url . '/thumb.png' : '',
				'less'			=> ( is_file( $base_dir . '/color.less' ) || is_file( $base_dir . '/style.less' ) ) ? true : false,
				'loadme'		=> $load,
				'price'			=> $price,
				'purchased'		=> $purchased,
				'uid'			=> $uid,
				'filter'		=> $headers['filter'],
				'loading'		=> $headers['loading']
			);

		}
		return $sections;
	}

	function register_sidebars() {

		if ( !pl_deprecate_v2() ) {
		
			// This array contains the sidebars in the correct order.
			$sidebars = array(
				'sb_primary' => array(
					'name'	=>	__( 'Primary Sidebar', 'pagelines' ),
					'description'	=>	__( 'The main widgetized sidebar.', 'pagelines')
				),
				'sb_secondary' => array(
					'name'	=>	sprintf( '%s%s', __( 'Secondary Sidebar', 'pagelines' ), ( !VPRO ) ? ' (Pro Only)' : '' ),
					'description'	=>	__( 'The secondary widgetized sidebar for the theme.', 'pagelines')
				),
				'sb_tertiary' => array(
					'name'	=>	__( 'Tertiary Sidebar', 'pagelines' ),
					'description'	=>	__( 'A 3rd widgetized sidebar for the theme that can be used in standard sidebar templates.', 'pagelines')
				),
				'sb_universal' => array(
					'name'	=>	__( 'Universal Sidebar', 'pagelines' ),
					'description'	=>	__( 'A universal widgetized sidebar', 'pagelines'),
					'pro'	=> true
				),
				'sb_fullwidth' => array(
					'name'	=>	__( 'Full Width Sidebar', 'pagelines' ),
					'description'	=>	__( 'Shows full width widgetized sidebar.', 'pagelines')
				),
				'sb_content' => array(
					'name'	=>	__( 'Content Sidebar', 'pagelines' ),
					'description'	=>	__( 'Displays a widgetized sidebar inside the main content area. Set it up in the widgets panel.', 'pagelines')
				),
			);

			foreach ( $sidebars as $key => $sidebar ) {
				if ( isset( $sidebar['pro'] ) && ! VPRO )
					continue;
				pagelines_register_sidebar( pagelines_standard_sidebar( $sidebar['name'], $sidebar['description'] ) );
			}
		}
	}

	/**
	* Simple cache.
	* @return object
	*/
	function get_latest_cached( $type, $flush = null ) {

		$url = trailingslashit( PL_API . $type );
		$options = array(
			'body' => array(
				'username'	=>	( $this->username != '' ) ? $this->username : false,
				'password'	=>	( $this->password != '' ) ? $this->password : false,
				'flush'		=>	$flush
			)
		);

		if ( false === ( $api_check = get_transient( 'pagelines_extend_' . $type ) ) ) {

			// ok no transient, we need an update...

			$response = pagelines_try_api( $url, $options );

			if ( $response !== false ) {

				// ok we have the data parse and store it

				$api = wp_remote_retrieve_body( $response );
				set_transient( 'pagelines_extend_' . $type, true, 86400 );
				update_option( 'pagelines_extend_' . $type, $api );
			}

		}
		$api = get_option( 'pagelines_extend_' . $type, false );

		if( ! $api )
			return __( '<h2>Unable to fetch from API</h2>', 'pagelines' );

		return json_decode( $api );
	}

} // PageLinesRegister

function pl_get_section_dirs() {

	$section_dirs = array(
		'child'  => PL_EXTEND_DIR,
		'parent' => PL_SECTIONS
	);

	$theme_sections_dir = PL_CHILD_DIR . '/sections';

	if ( is_child_theme() && is_dir( $theme_sections_dir ) )
		$section_dirs['custom'] = $theme_sections_dir;

	// load v3 section/plugins...

	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

	foreach ( get_plugins() as $plugin => $data ) {

		$slug = dirname( $plugin );
		$path = path_join( WP_PLUGIN_DIR, "$slug/sections" );

		if ( is_dir( $path ) )
			$section_dirs[ $slug ] = $path;
	}

	return apply_filters( 'pagelines_sections_dirs', $section_dirs );
}

function pl_get_disabled_sections() {

	// get all section types - including any added/removed by filters
	$types = array_keys( (array) pl_get_section_dirs() );
	// make sure the base type keys are all there even if they were filtered out
	$types = array_merge( array('child','parent','custom'), $types );

	$d = array();
	foreach ( $types as $type )
		$d[ $type ] = array();

	$saved = get_option( 'pagelines_sections_disabled', array() );

	return wp_parse_args( $saved, $d );
}