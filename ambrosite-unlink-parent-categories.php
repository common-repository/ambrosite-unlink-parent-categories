<?php
/*
Plugin Name: Ambrosite Unlink Parent Categories
Plugin URI: http://www.ambrosite.com/plugins
Description: Unlinks parent categories/taxonomies in category menus and lists. Affects the output of wp_list_categories.
Version: 1.0
Author: J. Michael Ambrosio
Author URI: http://www.ambrosite.com
License: GPL2
*/

/**
 * This filter is priority zero (highest priority), so that other plugins that filter the output
 * of wp_list_categories can still function.
 */
add_filter('wp_list_categories', 'wp_list_categories_unlink_parents', 0, 2);

/**
 * Display or retrieve the HTML list of categories/custom hierarchical taxonomies.
 * Based on wp_list_categories from wp-includes/category-template.php
 * The only reason for replacing wp_list_categories is to make it call the revised Walker_Category class.
 * Otherwise, it operates identically to the core function.
 */
function wp_list_categories_unlink_parents( $output, $args ) {
	$defaults = array(
		'show_option_all' => '', 'show_option_none' => __('No categories'),
		'orderby' => 'name', 'order' => 'ASC',
		'show_last_update' => 0, 'style' => 'list',
		'show_count' => 0, 'hide_empty' => 1,
		'use_desc_for_title' => 1, 'child_of' => 0,
		'feed' => '', 'feed_type' => '',
		'feed_image' => '', 'exclude' => '',
		'exclude_tree' => '', 'current_category' => 0,
		'hierarchical' => true, 'title_li' => __( 'Categories' ),
		'echo' => 1, 'depth' => 0,
		'taxonomy' => 'category'
	);

	$r = wp_parse_args( $args, $defaults );

	if ( !isset( $r['pad_counts'] ) && $r['show_count'] && $r['hierarchical'] )
		$r['pad_counts'] = true;

	if ( isset( $r['show_date'] ) )
		$r['include_last_update_time'] = $r['show_date'];

	if ( true == $r['hierarchical'] ) {
		$r['exclude_tree'] = $r['exclude'];
		$r['exclude'] = '';
	}

	if ( !isset( $r['class'] ) )
		$r['class'] = ( 'category' == $r['taxonomy'] ) ? 'categories' : $r['taxonomy'];

	extract( $r );

	if ( !taxonomy_exists($taxonomy) )
		return false;

	$categories = get_categories( $r );

	$output = '';
	if ( $title_li && 'list' == $style )
			$output = '<li class="' . esc_attr( $class ) . '">' . $title_li . '<ul>';

	if ( empty( $categories ) ) {
		if ( ! empty( $show_option_none ) ) {
			if ( 'list' == $style )
				$output .= '<li>' . $show_option_none . '</li>';
			else
				$output .= $show_option_none;
		}
	} else {
		if( !empty( $show_option_all ) )
			if ( 'list' == $style )
				$output .= '<li><a href="' .  get_bloginfo( 'url' )  . '">' . $show_option_all . '</a></li>';
			else
				$output .= '<a href="' .  get_bloginfo( 'url' )  . '">' . $show_option_all . '</a>';

		if ( empty( $r['current_category'] ) && ( is_category() || is_tax() || is_tag() ) ) {
			$current_term_object = get_queried_object();
			if ( $r['taxonomy'] == $current_term_object->taxonomy )
				$r['current_category'] = get_queried_object_id();
		}

		if ( $hierarchical )
			$depth = $r['depth'];
		else
			$depth = -1; // Flat.

		$r['walker'] = new Walker_Category_Unlink_Parents( $r['taxonomy'] );

		$output .= walk_category_tree( $categories, $depth, $r );
	}

	if ( $title_li && 'list' == $style )
		$output .= '</ul></li>';

	return $output;
}

/**
 * Create HTML list of categories, with unlinked parent categories.
 * Based on Walker_Category class from wp-includes/category-template.php
 *
 * @uses Walker
 */
class Walker_Category_Unlink_Parents extends Walker {
	var $tree_type = 'category';

	var $db_fields = array ('parent' => 'parent', 'id' => 'term_id');
	
	// Define six new member variables to hold the option settings, and the list of all term ids.
	var $option_dummy = 1;
	
	var $option_unlink_current = 0;

	var $option_remove_titles = 0;

	var $option_unlink_specified_only = 0;
	
	var $option_unlink_array = array();
	
	var $all_terms = array();
	
	// Define a constructor to load the option settings from the db, and initialize the term id list.
	function Walker_Category_Unlink_Parents( $taxonomy = 'category' ) {
		$unlink_options = get_option('ambrosite_unlink_parent_cats');

		$this->option_dummy = is_null($unlink_options['dummy']) ? 1 : $unlink_options['dummy'];
		$this->option_unlink_current = $unlink_options['unlink_current'];
		$this->option_remove_titles = $unlink_options['remove_titles'];
		$this->option_unlink_specified_only = $unlink_options['unlink_specified_only'];
		if ( $unlink_options['excats'] )
			$this->option_unlink_array = array_map( 'intval', explode(',', $unlink_options['excats']) );
		$this->all_terms = get_terms( $taxonomy, array( 'hide_empty' => false, 'hierarchical' => true ) );
	}

	function start_lvl(&$output, $depth, $args) {
		if ( 'list' != $args['style'] )
			return;

		$indent = str_repeat("\t", $depth);
		$output .= "$indent<ul class='children'>\n";
	}

	function end_lvl(&$output, $depth, $args) {
		if ( 'list' != $args['style'] )
			return;
			
		$indent = str_repeat("\t", $depth);
		$output .= "$indent</ul>\n";
	}

	function start_el(&$output, $category, $depth, $args) {
		extract($args);
		
		$cat_name = esc_attr( $category->name );
		$cat_name = apply_filters( 'list_cats', $cat_name, $category );
				
//		Begin modified code
		$unlink_current = false;
		if ( $this->option_unlink_current && $category->term_id == $current_category )
			$unlink_current = true;
					
		$link_open = '<a href="' . esc_attr( get_term_link($category) ) . '" ';
		$link_close = '</a>';
		
		if ( _get_term_children($category->term_id, $this->all_terms, $taxonomy) && !$this->option_unlink_specified_only || in_array($category->term_id, $this->option_unlink_array) || $unlink_current ) {
			if ( $this->option_dummy ) {
				$link_open = '<a href="#" style="cursor: default;" ';
			} else {
				$link_open = '<span ';
				$link_close = '</span>';
			}
		}

		$link = $link_open;
		if ( !$this->option_remove_titles ) {
			if ( $use_desc_for_title == 0 || empty($category->description) )
				$link .= 'title="' . esc_attr( sprintf(__( 'View all posts filed under %s' ), $cat_name) ) . '"';
			else
				$link .= 'title="' . esc_attr( strip_tags( apply_filters( 'category_description', $category->description, $category ) ) ) . '"';
		}
		$link .= '>';
		$link .= $cat_name . $link_close;
		
//		End modified code		

		if ( !empty($feed_image) || !empty($feed) ) {
			$link .= ' ';

			if ( empty($feed_image) )
				$link .= '(';

			$link .= '<a href="' . get_term_feed_link( $category->term_id, $category->taxonomy, $feed_type ) . '"';

			if ( empty($feed) ) {
				$alt = ' alt="' . sprintf(__( 'Feed for all posts filed under %s' ), $cat_name ) . '"';
			} else {
				$title = ' title="' . $feed . '"';
				$alt = ' alt="' . $feed . '"';
				$name = $feed;
				$link .= $title;
			}

			$link .= '>';

			if ( empty($feed_image) )
				$link .= $name;
			else
				$link .= "<img src='$feed_image'$alt$title" . ' />';

			$link .= '</a>';

			if ( empty($feed_image) )
				$link .= ')';
		}

		if ( !empty($show_count) )
			$link .= ' (' . intval($category->count) . ')';

		if ( !empty($show_date) )
			$link .= ' ' . gmdate('Y-m-d', $category->last_update_timestamp);

		if ( 'list' == $args['style'] ) {
			$output .= "\t<li";
			$class = 'cat-item cat-item-' . $category->term_id;
			if ( !empty($current_category) ) {
				$_current_category = get_term( $current_category, $category->taxonomy );
				if ( $category->term_id == $current_category )
					$class .=  ' current-cat';
				elseif ( $category->term_id == $_current_category->parent )
					$class .=  ' current-cat-parent';
			}
			$output .=  ' class="' . $class . '"';
			$output .= ">$link\n";
		} else {
			$output .= "\t$link<br />\n";
		}
	}

	function end_el(&$output, $page, $depth, $args) {
		if ( 'list' != $args['style'] )
			return;

		$output .= "</li>\n";
	}
}


function ambrosite_unlink_parent_cats_admin() {
	?>
	<div class="wrap">
		<h2 style="margin-bottom: .5em;">Ambrosite Unlink Parent Categories - Options</h2>
		<form method="post" action="options.php">
			<?php
			settings_fields('ambrosite_unlink_parent_cats_options');
			$options = get_option('ambrosite_unlink_parent_cats');
			// Set options to defaults if not previously set
			if ( is_null($options['dummy']) )
				$options['dummy'] = 1; 
			foreach ( array('unlink_current', 'remove_titles', 'unlink_specified_only') as $key ) {
				if ( !array_key_exists($key, $options) )
					$options[$key] = 0;
			}
			if ( !array_key_exists('excats', $options) )
				$options['excats'] = ''; 
			?>
			<fieldset>
			<label><strong>Use Dummy Links:</strong> </label><input name="ambrosite_unlink_parent_cats[dummy]" type="checkbox" value="1" <?php checked('1', $options['dummy']); ?> />
			<p style="margin: .2em 0 1.5em 0;">If you uncheck this box, the plugin will unlink the parent categories by replacing the anchor tags with span tags. In some themes, this may cause problems with CSS styling. In order to fix this, you would need to add an additional selector to any rule that targets the anchor tags (see the plugin FAQ for more information). If you are not experienced in writing CSS selectors, then it is strongly recommended to stick with dummy links.</p>
			</fieldset>
			<fieldset>
			<label><strong>Unlink Current Category:</strong> </label><input name="ambrosite_unlink_parent_cats[unlink_current]" type="checkbox" value="1" <?php checked('1', $options['unlink_current']); ?> />
			<p style="margin: .2em 0 1.5em 0;">Unlink the current category, in addition to the parent categories.</p>
			</fieldset>
			<fieldset>
			<label><strong>Remove Link Titles:</strong> </label><input name="ambrosite_unlink_parent_cats[remove_titles]" type="checkbox" value="1" <?php checked('1', $options['remove_titles']); ?> />
			<p style="margin: .2em 0 1.5em 0;">Remove the title attribute from the links. Stops the tooltip from popping up when the mouse hovers over the menu items.</p>
			</fieldset>
			<fieldset>
			<label><strong>Unlink Specific Categories:</strong> </label><input type="text" name="ambrosite_unlink_parent_cats[excats]" size="40" value="<?php echo $options['excats']; ?>" />
			<p style="margin: .2em 0 1.5em 0;">You can specify which categories you want unlinked, using a comma-separated list of category IDs (example: 3,7,31). If you want <em>only</em> these categories to be unlinked, then check the box below.</p>
			<label>Unlink specific categories <em>only</em>: </label><input name="ambrosite_unlink_parent_cats[unlink_specified_only]" type="checkbox" value="1" <?php checked('1', $options['unlink_specified_only']); ?> />
			</fieldset>
			<p class="submit">
			<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
			</p>
		</form>
	</div>
	<?php	
}

function ambrosite_unlink_parent_cats_addpage() {
	add_options_page('Ambrosite Unlink Parent Categories Options', 'Unlink Parent Categories', 'manage_options', 'ambrosite_unlink_parent_cats', 'ambrosite_unlink_parent_cats_admin');
}
add_action('admin_menu', 'ambrosite_unlink_parent_cats_addpage');

// Sanitize and validate input. Accepts an array, returns a sanitized array.
function ambrosite_unlink_parent_cats_validate($input) {
	foreach ( array('dummy', 'unlink_current', 'remove_titles', 'unlink_specified_only') as $key ) {
		if ( array_key_exists($key, $input) )
			$input[$key] = ( $input[$key] == 1 ) ? 1 : 0;
		else
			$input[$key] = 0;
	}
	
	$input['excats'] = preg_replace('/[^0-9,]/', '', $input['excats']);

	return $input;
}

// Init plugin options to white list the options
function ambrosite_unlink_parent_cats_init(){
	register_setting( 'ambrosite_unlink_parent_cats_options', 'ambrosite_unlink_parent_cats', 'ambrosite_unlink_parent_cats_validate' );
}
add_action('admin_init', 'ambrosite_unlink_parent_cats_init' );
?>