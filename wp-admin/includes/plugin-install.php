<?php
/**
 * WordPress Plugin Install Administration API
 *
 * @package WordPress
 * @subpackage Administration
 */

/**
 * Retrieve plugin installer pages from WordPress Plugins API.
 *
 * It is possible for a plugin to override the Plugin API result with three
 * filters. Assume this is for plugins, which can extend on the Plugin Info to
 * offer more choices. This is very powerful and must be used with care, when
 * overriding the filters.
 *
 * The first filter, 'plugins_api_args', is for the args and gives the action as
 * the second parameter. The hook for 'plugins_api_args' must ensure that an
 * object is returned.
 *
 * The second filter, 'plugins_api', is the result that would be returned.
 *
 * @since 2.7.0
 *
 * @param string $action
 * @param array|object $args Optional. Arguments to serialize for the Plugin Info API.
 * @return object plugins_api response object on success, WP_Error on failure.
 */
function plugins_api($action, $args = null) {

	if ( is_array( $args ) ) {
		$args = (object) $args;
	}

	if ( ! isset( $args->per_page ) ) {
		$args->per_page = 24;
	}

	if ( ! isset( $args->locale ) ) {
		$args->locale = get_locale();
	}

	/**
	 * Override the Plugin Install API arguments.
	 *
	 * Please ensure that an object is returned.
	 *
	 * @since 2.7.0
	 *
	 * @param object $args   Plugin API arguments.
	 * @param string $action The type of information being requested from the Plugin Install API.
	 */
	$args = apply_filters( 'plugins_api_args', $args, $action );

	/**
	 * Allows a plugin to override the WordPress.org Plugin Install API entirely.
	 *
	 * Please ensure that an object is returned.
	 *
	 * @since 2.7.0
	 *
	 * @param bool|object $result The result object. Default false.
	 * @param string      $action The type of information being requested from the Plugin Install API.
	 * @param object      $args   Plugin API arguments.
	 */
	$res = apply_filters( 'plugins_api', false, $action, $args );

	if ( false === $res ) {
		$url = $http_url = 'http://api.wordpress.org/plugins/info/1.0/';
		if ( $ssl = wp_http_supports( array( 'ssl' ) ) )
			$url = set_url_scheme( $url, 'https' );

		$args = array(
			'timeout' => 15,
			'body' => array(
				'action' => $action,
				'request' => serialize( $args )
			)
		);
		$request = wp_remote_post( $url, $args );

		if ( $ssl && is_wp_error( $request ) ) {
			trigger_error( __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ) . ' ' . __( '(WordPress could not establish a secure connection to WordPress.org. Please contact your server administrator.)' ), headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE );
			$request = wp_remote_post( $http_url, $args );
		}

		if ( is_wp_error($request) ) {
			$res = new WP_Error('plugins_api_failed', __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ), $request->get_error_message() );
		} else {
			$res = maybe_unserialize( wp_remote_retrieve_body( $request ) );
			if ( ! is_object( $res ) && ! is_array( $res ) )
				$res = new WP_Error('plugins_api_failed', __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ), wp_remote_retrieve_body( $request ) );
		}
	} elseif ( !is_wp_error($res) ) {
		$res->external = true;
	}

	/**
	 * Filter the Plugin Install API response results.
	 *
	 * @since 2.7.0
	 *
	 * @param object|WP_Error $res    Response object or WP_Error.
	 * @param string          $action The type of information being requested from the Plugin Install API.
	 * @param object          $args   Plugin API arguments.
	 */
	return apply_filters( 'plugins_api_result', $res, $action, $args );
}

/**
 * Retrieve popular WordPress plugin tags.
 *
 * @since 2.7.0
 *
 * @param array $args
 * @return array
 */
function install_popular_tags( $args = array() ) {
	$key = md5(serialize($args));
	if ( false !== ($tags = get_site_transient('poptags_' . $key) ) )
		return $tags;

	$tags = plugins_api('hot_tags', $args);

	if ( is_wp_error($tags) )
		return $tags;

	set_site_transient( 'poptags_' . $key, $tags, 3 * HOUR_IN_SECONDS );

	return $tags;
}

function install_dashboard() {
	?>
	<p><?php printf( __( 'Plugins extend and expand the functionality of WordPress. You may automatically install plugins from the <a href="%1$s">WordPress Plugin Directory</a> or upload a plugin in .zip format via <a href="%2$s">this page</a>.' ), 'https://wordpress.org/plugins/', self_admin_url( 'plugin-install.php?tab=upload' ) ); ?></p>

	<?php display_plugins_table(); ?>

	<h3><?php _e( 'Popular tags' ) ?></h3>
	<p><?php _e( 'You may also browse based on the most popular tags in the Plugin Directory:' ) ?></p>
	<?php

	$api_tags = install_popular_tags();

	echo '<p class="popular-tags">';
	if ( is_wp_error($api_tags) ) {
		echo $api_tags->get_error_message();
	} else {
		//Set up the tags in a way which can be interpreted by wp_generate_tag_cloud()
		$tags = array();
		foreach ( (array)$api_tags as $tag )
			$tags[ $tag['name'] ] = (object) array(
									'link' => esc_url( self_admin_url('plugin-install.php?tab=search&type=tag&s=' . urlencode($tag['name'])) ),
									'name' => $tag['name'],
									'id' => sanitize_title_with_dashes($tag['name']),
									'count' => $tag['count'] );
		echo wp_generate_tag_cloud($tags, array( 'single_text' => __('%s plugin'), 'multiple_text' => __('%s plugins') ) );
	}
	echo '</p><br class="clear" />';
}
add_action( 'install_plugins_featured', 'install_dashboard' );

/**
 * Display search form for searching plugins.
 *
 * @since 2.7.0
 */
function install_search_form( $type_selector = true ) {
	$type = isset($_REQUEST['type']) ? wp_unslash( $_REQUEST['type'] ) : 'term';
	$term = isset($_REQUEST['s']) ? wp_unslash( $_REQUEST['s'] ) : '';
	$input_attrs = '';
	$button_type = 'button screen-reader-text';

	// assume no $type_selector means it's a simplified search form
	if ( ! $type_selector ) {
		$input_attrs = 'class="wp-filter-search" placeholder="' . esc_attr__( 'Search Plugins' ) . '" ';
	}

	?><form class="search-form search-plugins" method="get" action="">
		<input type="hidden" name="tab" value="search" />
		<?php if ( $type_selector ) : ?>
		<select name="type" id="typeselector">
			<option value="term"<?php selected('term', $type) ?>><?php _e('Keyword'); ?></option>
			<option value="author"<?php selected('author', $type) ?>><?php _e('Author'); ?></option>
			<option value="tag"<?php selected('tag', $type) ?>><?php _ex('Tag', 'Plugin Installer'); ?></option>
		</select>
		<?php endif; ?>
		<label><span class="screen-reader-text"><?php _e('Search Plugins'); ?></span>
			<input type="search" name="s" value="<?php echo esc_attr($term) ?>" <?php echo $input_attrs; ?>/>
		</label>
		<?php submit_button( __( 'Search Plugins' ), $button_type, false, false, array( 'id' => 'search-submit' ) ); ?>
	</form><?php
}

/**
 * Upload from zip
 * @since 2.8.0
 *
 * @param integer $page
 */
function install_plugins_upload( $page = 1 ) {
?>
<div class="upload-plugin">
	<p class="install-help"><?php _e('If you have a plugin in a .zip format, you may install it by uploading it here.'); ?></p>
	<form method="post" enctype="multipart/form-data" class="wp-upload-form" action="<?php echo self_admin_url('update.php?action=upload-plugin'); ?>">
		<?php wp_nonce_field( 'plugin-upload'); ?>
		<label class="screen-reader-text" for="pluginzip"><?php _e('Plugin zip file'); ?></label>
		<input type="file" id="pluginzip" name="pluginzip" />
		<?php submit_button( __( 'Install Now' ), 'button', 'install-plugin-submit', false ); ?>
	</form>
</div>
<?php
}
add_action('install_plugins_upload', 'install_plugins_upload', 10, 1);

/**
 * Show a username form for the favorites page
 * @since 3.5.0
 *
 */
function install_plugins_favorites_form() {
	$user = ! empty( $_GET['user'] ) ? wp_unslash( $_GET['user'] ) : get_user_option( 'wporg_favorites' );
	?>
	<p class="install-help"><?php _e( 'If you have marked plugins as favorites on WordPress.org, you can browse them here.' ); ?></p>
	<form method="get" action="">
		<input type="hidden" name="tab" value="favorites" />
		<p>
			<label for="user"><?php _e( 'Your WordPress.org username:' ); ?></label>
			<input type="search" id="user" name="user" value="<?php echo esc_attr( $user ); ?>" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Get Favorites' ); ?>" />
		</p>
	</form>
	<?php
}

/**
 * Display plugin content based on plugin list.
 *
 * @since 2.7.0
 */
function display_plugins_table() {
	global $wp_list_table;

	switch ( current_filter() ) {
		case 'install_plugins_favorites' :
			if ( empty( $_GET['user'] ) && ! get_user_option( 'wporg_favorites' ) ) {
				return;
			}
			break;
		case 'install_plugins_recommended' :
			echo '<p>' . __( 'These suggestions are based on the plugins you and other users have installed.' ) . '</p>';
			break;
	}

	?>
	<form id="plugin-filter" action="" method="post">
		<?php $wp_list_table->display(); ?>
	</form>
	<?php
}
add_action( 'install_plugins_search',      'display_plugins_table' );
add_action( 'install_plugins_popular',     'display_plugins_table' );
add_action( 'install_plugins_recommended', 'display_plugins_table' );
add_action( 'install_plugins_new',         'display_plugins_table' );
add_action( 'install_plugins_beta',        'display_plugins_table' );
add_action( 'install_plugins_favorites',   'display_plugins_table' );

/**
 * Determine the status we can perform on a plugin.
 *
 * @since 3.0.0
 */
function install_plugin_install_status($api, $loop = false) {
	// This function is called recursively, $loop prevents further loops.
	if ( is_array($api) )
		$api = (object) $api;

	// Default to a "new" plugin
	$status = 'install';
	$url = false;

	/*
	 * Check to see if this plugin is known to be installed,
	 * and has an update awaiting it.
	 */
	$update_plugins = get_site_transient('update_plugins');
	if ( isset( $update_plugins->response ) ) {
		foreach ( (array)$update_plugins->response as $file => $plugin ) {
			if ( $plugin->slug === $api->slug ) {
				$status = 'update_available';
				$update_file = $file;
				$version = $plugin->new_version;
				if ( current_user_can('update_plugins') )
					$url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . $update_file), 'upgrade-plugin_' . $update_file);
				break;
			}
		}
	}

	if ( 'install' == $status ) {
		if ( is_dir( WP_PLUGIN_DIR . '/' . $api->slug ) ) {
			$installed_plugin = get_plugins('/' . $api->slug);
			if ( empty($installed_plugin) ) {
				if ( current_user_can('install_plugins') )
					$url = wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . $api->slug), 'install-plugin_' . $api->slug);
			} else {
				$key = array_keys( $installed_plugin );
				$key = array_shift( $key ); //Use the first plugin regardless of the name, Could have issues for multiple-plugins in one directory if they share different version numbers
				if ( version_compare($api->version, $installed_plugin[ $key ]['Version'], '=') ){
					$status = 'latest_installed';
				} elseif ( version_compare($api->version, $installed_plugin[ $key ]['Version'], '<') ) {
					$status = 'newer_installed';
					$version = $installed_plugin[ $key ]['Version'];
				} else {
					//If the above update check failed, Then that probably means that the update checker has out-of-date information, force a refresh
					if ( ! $loop ) {
						delete_site_transient('update_plugins');
						wp_update_plugins();
						return install_plugin_install_status($api, true);
					}
				}
			}
		} else {
			// "install" & no directory with that slug
			if ( current_user_can('install_plugins') )
				$url = wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . $api->slug), 'install-plugin_' . $api->slug);
		}
	}
	if ( isset($_GET['from']) )
		$url .= '&amp;from=' . urlencode( wp_unslash( $_GET['from'] ) );

	return compact('status', 'url', 'version');
}

/**
 * Display plugin information in dialog box form.
 *
 * @since 2.7.0
 */
function install_plugin_information() {
	global $tab;

	if ( empty( $_REQUEST['plugin'] ) ) {
		return;
	}

	$api = plugins_api( 'plugin_information', array(
		'slug' => wp_unslash( $_REQUEST['plugin'] ),
		'is_ssl' => is_ssl(),
		'fields' => array( 'banners' => true, 'reviews' => true )
	) );

	if ( is_wp_error( $api ) ) {
		wp_die( $api );
	}

	$plugins_allowedtags = array(
		'a' => array( 'href' => array(), 'title' => array(), 'target' => array() ),
		'abbr' => array( 'title' => array() ), 'acronym' => array( 'title' => array() ),
		'code' => array(), 'pre' => array(), 'em' => array(), 'strong' => array(),
		'div' => array( 'class' => array() ), 'span' => array( 'class' => array() ),
		'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
		'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
		'img' => array( 'src' => array(), 'class' => array(), 'alt' => array() )
	);

	$plugins_section_titles = array(
		'description'  => _x( 'Description',  'Plugin installer section title' ),
		'installation' => _x( 'Installation', 'Plugin installer section title' ),
		'faq'          => _x( 'FAQ',          'Plugin installer section title' ),
		'screenshots'  => _x( 'Screenshots',  'Plugin installer section title' ),
		'changelog'    => _x( 'Changelog',    'Plugin installer section title' ),
		'reviews'      => _x( 'Reviews',      'Plugin installer section title' ),
		'other_notes'  => _x( 'Other Notes',  'Plugin installer section title' )
	);

	// Sanitize HTML
	foreach ( (array) $api->sections as $section_name => $content ) {
		$api->sections[$section_name] = wp_kses( $content, $plugins_allowedtags );
	}

	foreach ( array( 'version', 'author', 'requires', 'tested', 'homepage', 'downloaded', 'slug' ) as $key ) {
		if ( isset( $api->$key ) ) {
			$api->$key = wp_kses( $api->$key, $plugins_allowedtags );
		}
	}

	$_tab = esc_attr( $tab );

	$section = isset( $_REQUEST['section'] ) ? wp_unslash( $_REQUEST['section'] ) : 'description'; // Default to the Description tab, Do not translate, API returns English.
	if ( empty( $section ) || ! isset( $api->sections[ $section ] ) ) {
		$section_titles = array_keys( (array) $api->sections );
		$section = array_shift( $section_titles );
	}

	iframe_header( __( 'Plugin Install' ) );

	$_with_banner = '';

	if ( ! empty( $api->banners ) && ( ! empty( $api->banners['low'] ) || ! empty( $api->banners['high'] ) ) ) {

		$_with_banner = 'with-banner'; // Hacked By DianCLoud
		$low  = empty( $api->banners['low'] ) ? my_agent_url($api->banners['high']) : my_agent_url($api->banners['low']); 
		$high = empty( $api->banners['high'] ) ? my_agent_url($api->banners['low']) : my_agent_url($api->banners['high']);
		?>
		<style type="text/css">
			#plugin-information-title.with-banner {
				background-image: url( <?php echo esc_url( $low ); ?> );
			}
			@media only screen and ( -webkit-min-device-pixel-ratio: 1.5 ) {
				#plugin-information-title.with-banner {
					background-image: url( <?php echo esc_url( $high ); ?> );
				}
			}
		</style>
		<?php
	}

	echo '<div id="plugin-information-scrollable">';
	echo "<div id='{$_tab}-title' class='{$_with_banner}'><div class='vignette'></div><h2>{$api->name}</h2></div>";
	echo "<div id='{$_tab}-tabs' class='{$_with_banner}'>\n";

	foreach ( (array) $api->sections as $section_name => $content ) {
		if ( 'reviews' === $section_name && ( empty( $api->ratings ) || 0 === array_sum( (array) $api->ratings ) ) ) {
			continue;
		}

		if ( isset( $plugins_section_titles[ $section_name ] ) ) {
			$title = $plugins_section_titles[ $section_name ];
		} else {
			$title = ucwords( str_replace( '_', ' ', $section_name ) );
		}

		$class = ( $section_name === $section ) ? ' class="current"' : '';
		$href = add_query_arg( array('tab' => $tab, 'section' => $section_name) );
		$href = esc_url( $href );
		$san_section = esc_attr( $section_name );
		echo "\t<a name='$san_section' href='$href' $class>$title</a>\n";
	}

	echo "</div>\n";

	?>
	<div id="<?php echo $_tab; ?>-content" class='<?php echo $_with_banner; ?>'>
	<div class="fyi">
		<ul>
		<?php if ( ! empty( $api->version ) ) { ?>
			<li><strong><?php _e( 'Version:' ); ?></strong> <?php echo $api->version; ?></li>
		<?php } if ( ! empty( $api->author ) ) { ?>
			<li><strong><?php _e( 'Author:' ); ?></strong> <?php echo links_add_target( $api->author, '_blank' ); ?></li>
		<?php } if ( ! empty( $api->last_updated ) ) { ?>
			<li><strong><?php _e( 'Last Updated:' ); ?></strong> <span title="<?php echo $api->last_updated; ?>">
				<?php printf( __( '%s ago' ), human_time_diff( strtotime( $api->last_updated ) ) ); ?>
			</span></li>
		<?php } if ( ! empty( $api->requires ) ) { ?>
			<li><strong><?php _e( 'Requires WordPress Version:' ); ?></strong> <?php printf( __( '%s or higher' ), $api->requires ); ?></li>
		<?php } if ( ! empty( $api->tested ) ) { ?>
			<li><strong><?php _e( 'Compatible up to:' ); ?></strong> <?php echo $api->tested; ?></li>
		<?php } if ( ! empty( $api->downloaded ) ) { ?>
			<li><strong><?php _e( 'Downloaded:' ); ?></strong> <?php printf( _n( '%s time', '%s times', $api->downloaded ), number_format_i18n( $api->downloaded ) ); ?></li>
		<?php } if ( ! empty( $api->slug ) && empty( $api->external ) ) { ?>
			<li><a target="_blank" href="https://wordpress.org/plugins/<?php echo $api->slug; ?>/"><?php _e( 'WordPress.org Plugin Page &#187;' ); ?></a></li>
		<?php } if ( ! empty( $api->homepage ) ) { ?>
			<li><a target="_blank" href="<?php echo esc_url( $api->homepage ); ?>"><?php _e( 'Plugin Homepage &#187;' ); ?></a></li>
		<?php } if ( ! empty( $api->donate_link ) && empty( $api->contributors ) ) { ?>
			<li><a target="_blank" href="<?php echo esc_url( $api->donate_link ); ?>"><?php _e( 'Donate to this plugin &#187;' ); ?></a></li>
		<?php } ?>
		</ul>
		<?php if ( ! empty( $api->rating ) ) { ?>
		<h3><?php _e( 'Average Rating' ); ?></h3>
		<?php wp_star_rating( array( 'rating' => $api->rating, 'type' => 'percent', 'number' => $api->num_ratings ) ); ?>
		<small><?php printf( _n( '(based on %s rating)', '(based on %s ratings)', $api->num_ratings ), number_format_i18n( $api->num_ratings ) ); ?></small>
		<?php }

		if ( ! empty( $api->ratings ) && array_sum( (array) $api->ratings ) > 0 ) {
			foreach( $api->ratings as $key => $ratecount ) {
				// Avoid div-by-zero.
				$_rating = $api->num_ratings ? ( $ratecount / $api->num_ratings ) : 0;
				?>
				<div class="counter-container">
					<span class="counter-label"><a href="https://wordpress.org/support/view/plugin-reviews/<?php echo $api->slug; ?>?filter=<?php echo $key; ?>"
						target="_blank"
						title="<?php echo esc_attr( sprintf( _n( 'Click to see reviews that provided a rating of %d star', 'Click to see reviews that provided a rating of %d stars', $key ), $key ) ); ?>"><?php printf( _n( '%d star', '%d stars', $key ), $key ); ?></a></span>
					<span class="counter-back">
						<span class="counter-bar" style="width: <?php echo 92 * $_rating; ?>px;"></span>
					</span>
					<span class="counter-count"><?php echo number_format_i18n( $ratecount ); ?></span>
				</div>
				<?php
			}
		}
		if ( ! empty( $api->contributors ) ) { ?>
			<h3><?php _e( 'Contributors' ); ?></h3>
			<ul class="contributors">
				<?php
				foreach ( (array) $api->contributors as $contrib_username => $contrib_profile ) {
					if ( empty( $contrib_username ) && empty( $contrib_profile ) ) {
						continue;
					}
					if ( empty( $contrib_username ) ) {
						$contrib_username = preg_replace( '/^.+\/(.+)\/?$/', '\1', $contrib_profile );
					}
					$contrib_username = sanitize_user( $contrib_username );
					if ( empty( $contrib_profile ) ) {
						echo "<li><img src='https://wordpress.org/grav-redirect.php?user={$contrib_username}&amp;s=36' width='18' height='18' />{$contrib_username}</li>";
					} else {
						echo "<li><a href='{$contrib_profile}' target='_blank'><img src='https://wordpress.org/grav-redirect.php?user={$contrib_username}&amp;s=36' width='18' height='18' />{$contrib_username}</a></li>";
					}
				}
				?>
			</ul>
			<?php if ( ! empty( $api->donate_link ) ) { ?>
				<a target="_blank" href="<?php echo esc_url( $api->donate_link ); ?>"><?php _e( 'Donate to this plugin &#187;' ); ?></a>
			<?php } ?>
		<?php } ?>
	</div>
	<div id="section-holder" class="wrap">
	<?php
		if ( ! empty( $api->tested ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $api->tested ) ), $api->tested, '>' ) ) {
			echo '<div class="notice notice-warning"><p>' . __('<strong>Warning:</strong> This plugin has <strong>not been tested</strong> with your current version of WordPress.') . '</p></div>';
		} else if ( ! empty( $api->requires ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $api->requires ) ), $api->requires, '<' ) ) {
			echo '<div class="notice notice-warning"><p>' . __('<strong>Warning:</strong> This plugin has <strong>not been marked as compatible</strong> with your version of WordPress.') . '</p></div>';
		}

		foreach ( (array) $api->sections as $section_name => $content ) {
			$content = links_add_base_url( $content, 'https://wordpress.org/plugins/' . $api->slug . '/' );
			$content = links_add_target( $content, '_blank' );

			$san_section = esc_attr( $section_name );

			$display = ( $section_name === $section ) ? 'block' : 'none';

			echo "\t<div id='section-{$san_section}' class='section' style='display: {$display};'>\n";
			echo $content;
			echo "\t</div>\n";
		}
	echo "</div>\n";
	echo "</div>\n";
	echo "</div>\n"; // #plugin-information-scrollable
	echo "<div id='$tab-footer'>\n";
	if ( ! empty( $api->download_link ) && ( current_user_can( 'install_plugins' ) || current_user_can( 'update_plugins' ) ) ) {
		$status = install_plugin_install_status( $api );
		switch ( $status['status'] ) {
			case 'install':
				if ( $status['url'] ) {
					echo '<a class="button button-primary right" href="' . $status['url'] . '" target="_parent">' . __( 'Install Now' ) . '</a>';
				}
				break;
			case 'update_available':
				if ( $status['url'] ) {
					echo '<a class="button button-primary right" href="' . $status['url'] . '" target="_parent">' . __( 'Install Update Now' ) .'</a>';
				}
				break;
			case 'newer_installed':
				echo '<a class="button button-primary right disabled">' . sprintf( __( 'Newer Version (%s) Installed'), $status['version'] ) . '</a>';
				break;
			case 'latest_installed':
				echo '<a class="button button-primary right disabled">' . __( 'Latest Version Installed' ) . '</a>';
				break;
		}
	}
	echo "</div>\n";

	iframe_footer();
	exit;
}
add_action('install_plugins_pre_plugin-information', 'install_plugin_information');
