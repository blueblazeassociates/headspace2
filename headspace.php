<?php
/*
Plugin Name: HeadSpace2
Plugin URI: http://urbangiraffe.com/plugins/headspace2/
Description: Meta-data manager on steroids, allowing complete control over all SEO needs such as keywords/tags, titles, description, stylesheets, and many many other goodies.
Version: 3.6.41
Author: John Godley
Author URI: http://urbangiraffe.com/
============================================================================================================
This software is provided "as is" and any express or implied warranties, including, but not limited to, the
implied warranties of merchantibility and fitness for a particular purpose are disclaimed. In no event shall
the copyright owner or contributors be liable for any direct, indirect, incidental, special, exemplary, or
consequential damages (including, but not limited to, procurement of substitute goods or services; loss of
use, data, or profits; or business interruption) however caused and on any theory of liability, whether in
contract, strict liability, or tort (including negligence or otherwise) arising in any way out of the use of
this software, even if advised of the possibility of such damage.

For full license details see license.txt
============================================================================================================ */

include dirname (__FILE__).'/plugin.php';
include dirname (__FILE__).'/models/headspace.php';
include dirname (__FILE__).'/headspace_library.php';

/**
 * The HeadSpace2 plugin
 *
 * @package HeadSpace2
 **/


class HeadSpace2_Admin extends HeadSpace_Plugin {
	var $types        = null;
	var $last_post_id = 0;

	/**
	 * Constructor sets up page types, starts all filters and actions
	 *
	 * @return void
	 **/
	function HeadSpace2_Admin() {
		$this->register_plugin( 'headspace', __FILE__);

		if (is_admin ()) {
			$this->add_action( 'admin_menu' );
			$this->add_action( 'load-settings_page_headspace', 'admin_head' );
			$this->add_action( 'load-post.php', 'admin_head' );
			$this->add_action( 'load-post-new.php', 'admin_head' );
        	$this->add_action( 'add_meta_boxes' );

			$this->add_action( 'save_post', 'save_tags' );

			$this->add_action( 'edit_category_form' );
			$this->add_action( 'edit_category' );
			add_action( 'edit_term', array( &$this, 'edit_category' ) );

			$this->add_action( 'init', 'init', 15);

			// WP 2.7 hooks
			$this->add_action( 'manage_posts_columns' );
			$this->add_action( 'manage_pages_columns', 'manage_posts_columns' );

			$this->add_action( 'manage_posts_custom_column', 'manage_posts_custom_column', 10, 2);
			$this->add_action( 'manage_pages_custom_column', 'manage_posts_custom_column', 10, 2);

			$this->register_plugin_settings( __FILE__ );

			// Ajax functions
			if ( defined( 'DOING_AJAX' ) ) {
				include_once dirname( __FILE__ ).'/ajax.php';
				$this->ajax = new HeadspaceAjax();
			}
		}
	}

	function print_scripts_array( $scripts ) {
		$farb = array_search( 'farbtastic', $scripts );

		if ( $farb && ( ( isset( $_GET['page'] ) && $_GET['page'] == 'headspace.php') || $this->is_page() || $this->is_post_edit() || $this->is_category_edit() ) )
			unset( $scripts[$farb] );

		return $scripts;
	}

	function plugin_settings ($links)	{
		$settings_link = '<a href="options-general.php?page='.basename( __FILE__ ).'">'.__('Settings', 'headspace').'</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	function manage_posts_columns($columns) {
		$headspace = HeadSpace2::get ();

		$settings  = $headspace->get_current_settings ();

		$simple   = $headspace->modules->get_restricted ($headspace->get_simple_modules (), $settings, 'page' );
		$advanced = $headspace->modules->get_restricted ($headspace->get_advanced_modules (), $settings, 'page' );

		$modules = array_merge ($simple, $advanced);
		if (count ($modules) > 0) {
			foreach ($modules AS $module) {
				if ($module->can_quick_edit ())
					$columns[strtolower (get_class ($module))] = $module->name ();
			}
		}

		return $columns;
	}

	function manage_posts_custom_column($column, $id) {
		$hs2 = HeadSpace2::get ();
		$meta = $hs2->get_post_settings ($id);

		$module = $hs2->modules->get (array ($column), $meta);
		if (count ($module) > 0)
			$module[0]->quick_view ();
	}

	function bulk_edit_custom_box($column_name, $type) {
	}

	function quick_edit_custom_box($column_name, $type) {
	}

	function init() {
		// Allow some customisation over core features
		if (file_exists (dirname (__FILE__).'/settings.php'))
			include dirname (__FILE__).'/settings.php';
		else
		{
			define ('HEADSPACE_MENU', __ ('HeadSpace', 'headspace'));
			define ('HEADSPACE_META', __ ('Meta-data', 'headspace'));
			define ('HEADSPACE_ROLE', 'manage_options' );
		}
	}


	function is_page() {
		if (strpos ($_SERVER['REQUEST_URI'], 'page-new.php') !== false || strpos ($_SERVER['REQUEST_URI'], 'edit-page.php') !== false || strpos ($_SERVER['REQUEST_URI'], 'page.php') !== false)
			return true;
		return false;
	}


	/**
	 * Add HeadSpace menu
	 *
	 * @return void
	 **/

	function admin_menu() {
		if (defined ('HEADSPACE_MANAGE'))
			add_management_page (HEADSPACE_MENU, HEADSPACE_MENU, HEADSPACE_ROLE, basename (__FILE__), array ($this, 'admin_screen'));
		else
			add_options_page (HEADSPACE_MENU, HEADSPACE_MENU, HEADSPACE_ROLE, basename (__FILE__), array ($this, 'admin_screen'));
	}



	/**
	 * Hooks into the WP category display and adds a HS meta data section
	 *
	 * @param category Category to edit
	 * @return void
	 **/

	function edit_category_form($cat) {
		if ( !empty( $cat ) ) {
			if ( !isset( $cat->cat_ID ) ) {
				if ( isset( $cat->term_id ) )
					$cat->cat_ID = $cat->term_id;
				else
					$cat->cat_ID = 0;
			}

			$headspace = HeadSpace2::get ();
			$settings  = $headspace->get_current_settings (get_option( 'headspace_cat_'.$cat->cat_ID));

			$simple   = $headspace->modules->get_restricted ($headspace->get_simple_modules (), $settings, 'category' );
			$advanced = $headspace->modules->get_restricted ($headspace->get_advanced_modules (), $settings, 'category' );

			$this->render_admin( 'edit_category', array ('simple' => $simple, 'advanced' => $advanced));
		}
	}

	function metabox_tags($post) {
		$headspace = HeadSpace2::get ();
		$settings  = $headspace->get_current_settings ();

		$tags = $headspace->modules->get ('hsm_tags' );
		if ($tags !== false)
			$this->render_admin( 'edit_page', array ('post_ID' => $post->ID));
	}

	function metabox($post) {
		$headspace = HeadSpace2::get ();
		$settings  = $headspace->get_current_settings ();

		$simple   = $headspace->modules->get_restricted ($headspace->get_simple_modules (), $settings, 'page' );
		$advanced = $headspace->modules->get_restricted ($headspace->get_advanced_modules (), $settings, 'page' );

		$this->render_admin( 'page-settings-edit', array ('simple' => $simple, 'advanced' => $advanced, 'width' => 140, 'area' => 'page' ) );
	}


	/**
	 * Extract meta-data when saving a post
	 *
	 * @param int $id Post ID
	 * @return void
	 **/

	function save_tags($id) {
		if ( isset( $_POST['headspace'] ) ) {
			$headspace = HeadSpace2::get();
			$headspace->save_post_settings( $id, $headspace->extract_module_settings( $_POST, 'page' ) );
		}
	}


	/**
	 * Extract HS meta data when editing a category
	 *
	 * @param int $id Category ID
	 * @return void
	 **/

	function edit_category( $id ) {
		if ( isset( $_POST['cat_ID'] ) || isset( $_POST['tag_ID'] ) ) {
			$headspace = HeadSpace2::get();
			$settings  = $headspace->extract_module_settings( $_POST, 'category' );

			if ( empty( $settings ) )
				delete_option( 'headspace_cat_'.$id );
			else
				update_option( 'headspace_cat_'.$id, $settings );
		}
	}

	function submenu($inwrap = false) {
		// Decide what to do
		$sub = isset ($_GET['sub']) ? $_GET['sub'] : '';
		if ( !in_array( $sub, array( 'modules', 'site', 'options', 'import' ) ) )
			$sub = '';

		if ($inwrap == true)
			$this->render_admin( 'submenu', array ('sub' => $sub));
		return $sub;
	}


	/**
	 * Checks the current theme footer.php and header.php to ensure it contains the appropriate function calls* to allow HS to work.  Hopefully this will reduce support questions regarding this
	 */
	function check_theme_files() {
		$base = get_template_directory ();

		$messages = array ();
		if (file_exists ($base.DIRECTORY_SEPARATOR.'header.php')) {
			$theme_data = implode ('', file ($base.DIRECTORY_SEPARATOR.'header.php'));

			if (strpos ($theme_data, 'wp_head') === false)
				$messages[] = __ ('<code>wp_head</code> was not found in <code>header.php</code> (<a href="http://codex.wordpress.org/Hook_Reference/wp_head">documentation</a>)' );
		}

		if (file_exists ($base.DIRECTORY_SEPARATOR.'footer.php')) {
			$theme_data = implode ('', file ($base.DIRECTORY_SEPARATOR.'footer.php'));

			if (strpos ($theme_data, 'wp_footer') === false)
				$messages[] = __ ('<code>wp_footer</code> was not found in <code>footer.php</code> (<a href="http://codex.wordpress.org/Theme_Development">documentation</a>)' );
		}

		if (count ($messages) > 0) {
			$msg = '';
			foreach ($messages AS $message)
				$msg .= '<li>'.$message.'</li>';

			$this->render_error ('<p>There are some issues with your theme that may prevent HeadSpace functioning correctly.</p><ol>'.$msg.'</oi>' );
		}
	}


	/**
	 * Choose which admin screen is displayed as well as displaying RSS version feed
	 *
	 * @return void
	 **/

	function admin_screen() {
		global $wp_version;
		if (get_option( 'headspace_version') != 10) {
			include dirname (__FILE__).'/models/upgrade.php';

			HS_Upgrade::upgrade (get_option( 'headspace_version'), 10);
		}

		// Decide what to do
		$sub = $this->submenu ();

		$this->check_theme_files ();

		// Display screen
		if ($sub == '')
			$this->admin_settings ();
		else if ($sub == 'options')
			$this->admin_options ();
		else if ($sub == 'keywords')
			$this->admin_keywords ();
		else if ($sub == 'import')
			$this->admin_import ();
		else if ($sub == 'site')
			$this->admin_site ();
		else if ($sub == 'modules')
			$this->admin_modules ();
	}

	function get_options() {
		$options = get_option( 'headspace_options' );
		if ($options === false)
			$options = array ();

		$defaults = array
		(
			'inherit' => true,
			'excerpt' => true,
			'debug'   => false,
		);

		foreach ($defaults AS $key => $value) {
			if (!isset ($options[$key]))
				$options[$key] = $value;
		}

		return $options;
	}

	/**
	 * Display the settings screen
	 *
	 * @return void
	 **/

	function admin_settings() {
		$headspace = HeadSpace2::get ();

		$this->render_admin( 'page-settings', array( 'types' => $headspace->get_types() ) );
	}


	/**
	 * Display the options screen
	 *
	 * @return void
	 **/

	function admin_options() {
		// Save
		if (isset ($_POST['save']) && check_admin_referer ('headspace-update_options')) {
			$options = $this->get_options ();
			$options['inherit'] = isset ($_POST['inherit']) ? true : false;
			$options['debug']   = isset ($_POST['debug']) ? true : false;
			$options['excerpt'] = isset ($_POST['excerpt']) ? true : false;

			update_option( 'headspace_options', $options);
			$this->render_message (__ ('Your options have been updated', 'headspace'));
		}
		else if (isset ($_POST['delete']) && check_admin_referer ('headspace-delete_plugin')) {
			include dirname (__FILE__).'/models/upgrade.php';

			HS_Upgrade::remove (__FILE__);
			$this->render_message (__ ('HeadSpace has been removed', 'headspace'));
		}

		$this->render_admin( 'options', array ('options' => $this->get_options ()));
	}


	function admin_modules() {
		$headspace = HeadSpace2::get ();

		$simple   = $headspace->modules->get ($headspace->get_simple_modules ());
		$advanced = $headspace->modules->get ($headspace->get_advanced_modules ());

		$this->render_admin( 'page-modules', array ('simple' => $simple, 'advanced' => $advanced, 'disabled' => $headspace->modules->get_disabled ($simple, $advanced)));
	}

	function admin_site() {
		$headspace = HeadSpace2::get ();

		$this->render_admin( 'site-modules', array ('site' => $headspace->site));
	}

	function admin_import() {
		include dirname (__FILE__).'/models/importer.php';

		$importmanager = new HS_ImportManager ();

		if ((isset ($_POST['import']) || isset ($_POST['import_cleanup'])) && check_admin_referer ('headspace-import')) {
			$importer = $importmanager->get ($_POST['importer']);
			$count    = $importer->import ();

			if (isset ($_POST['import_cleanup']))
				$importer->cleanup ();

			$this->render_message (sprintf (__ ('%d items were imported from %s', 'headspace'), $count, $importer->name ()));
		}

		$this->render_admin( 'import', array ('modules' => $importmanager->available ()));
	}

	function add_meta_boxes() {
		add_meta_box( 'headspacestuff', __ ('HeadSpace', 'headspace'), array (&$this, 'metabox'), 'post', 'normal', 'high' );
		add_meta_box( 'headspacestuff', __ ('HeadSpace', 'headspace'), array (&$this, 'metabox'), 'page', 'normal', 'high' );
		add_meta_box( 'tagsdiv',        __ ('Tags', 'headspace'),      array (&$this, 'metabox_tags'), 'page', 'side', 'high' );
	}

	function admin_head() {
		global $wp_scripts;

		// Rejig the localization
		if ($this->is_page ())
			$wp_scripts->registered['page']->extra['l10n'] = $wp_scripts->registered['post']->extra['l10n'];

		// We need to do this because the WP-Ecommerce plugin inserts some JS that interferes with HeadSpace
		if (isset ($wp_scripts->registered['ui-tabs']) && strpos ($_SERVER['REQUEST_URI'], 'headspace.php') !== false)
			unset ($wp_scripts->registered['ui-tabs']);

		if ($this->is_page () || $this->is_post_edit ()) {
			wp_enqueue_script( 'headspace', plugin_dir_url( __FILE__ ).'/js/headspace.js', array ('jquery-form'), $this->version ());
			wp_enqueue_script( 'headspace-tags', plugin_dir_url( __FILE__ ).'/js/headspace-tags.js', array ('headspace'), $this->version ());
		}
		else
			wp_enqueue_script( 'headspace', plugin_dir_url( __FILE__ ).'/js/headspace.js' , array ('jquery-form', 'jquery-ui-sortable'), $this->version ());

		if ( ( isset ($_GET['page']) && $_GET['page'] == 'headspace.php') || $this->is_page () || $this->is_category_edit () || $this->is_post_edit () ) {
			wp_enqueue_style( 'headspace', plugin_dir_url( __FILE__ ).'admin.css' );
		}

		wp_localize_script( 'headspace', 'hs2', array(
			'headspace_delete' => plugins_url( '/images/delete.png', $this->base_url() ),
		) );
	}

	function base_url() {
		return __FILE__;
	}

	function is_category_edit() {
		if (strpos ($_SERVER['REQUEST_URI'], 'categories.php') || strpos( $_SERVER['REQUEST_URI'], 'taxonomy=category' ) )
			return true;
		return false;
	}

	function is_post_edit() {
		if (strpos ($_SERVER['REQUEST_URI'], 'post.php') !== false || strpos ($_SERVER['REQUEST_URI'], 'post-new.php') !== false)
			return true;
		return false;
  }

	function version() {
		$plugin_data = implode ('', file (__FILE__));

		if (preg_match ('|Version:(.*)|i', $plugin_data, $version))
			return trim ($version[1]);
		return '';
	}
}

// Thematic compat
function hs_child_headspace_doctitle() {
     return wp_title( '', false) ;
}

function hs_child_meta_head_cleaning() {
	return true;
}

add_filter( 'thematic_seo', 'hs_child_meta_head_cleaning' );
add_filter( 'thematic_doctitle','hs_child_headspace_doctitle' );


/**
 * Instantiate the plugin
 *
 * @global
 **/

$headspace2 = new HeadSpace2_Admin;


/**
 * Template function todisplay tags
 *
 * @return void
 **/

function the_head_tags() {
	$headspace = HeadSpace2::get ();

	$settings = $headspace->get_current_settings ();
	echo $headspace->capture ('tags', array ('tags' => explode (',', $settings['keywords'])));
}
