<?php
/*
Plugin Name: CF Compatability
Plugin URI: http://crowdfavorite.com
Description:  General compatability functions compiled by Crowd Favorite
Version: 1.5.3
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

load_plugin_textdomain('cf-compat');
// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

/**
 * Tell the world we're here
 */
define('CFCOMPAT',true);

/**
 * Pre-2.6 Constant compatibility
 */
if(!defined('WP_CONTENT_URL')) {
	define('WP_CONTENT_URL',get_option('url').'/wp-content');
}
if(!defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR',ABSPATH.'wp-content');
}
if(!defined('WP_PLUGIN_URL')) {
	define('WP_PLUGIN_URL',WP_CONTENT_URL. '/plugins');
}

/**
 * make sure we have an is_admin function
 */ 
if (!function_exists('is_admin_page')) {
	function is_admin_page() {
		if (function_exists('is_admin')) {
			return is_admin();
		}
		if (function_exists('check_admin_referer')) {
			return true;
		}
		else {
			return false;
		}
	}
}

/**
 * Reorder javascript output so that jQuery comes out after Prototype.js
 * use the standard: wp_enqueue_script('jquery'); to load jQuery
 */
if (!function_exists('wp_prototype_before_jquery')) {
	function wp_prototype_before_jquery( $js_array ) {
		if ( false === $jquery = array_search( 'jquery', $js_array ) )
			return $js_array;
		if ( false === $prototype = array_search( 'prototype', $js_array ) )
			return $js_array;
		if ( $prototype < $jquery )
			return $js_array;
		unset($js_array[$prototype]);
		array_splice( $js_array, $jquery, 0, 'prototype' );
		return $js_array;
	}
	add_filter( 'print_scripts_array', 'wp_prototype_before_jquery' );
}

/**
 * define a file_get_contents if there isn't one
 * @param string - path to file to open
 * @return mixed - false if file can't be found or accessed, otherwise contents of specified file
 */
if(!function_exists('file_get_contents')) {
	function file_get_contents($filepath) {
		if(!file_exists($filepath) || !is_readable($filepath)) { return false; }
		$h = fopen($filepath,'r');
		$contents = fread($h,filesize($filepath));
		fclose($h);
		return $contents;
	}
}

/**
 * Normalize line endings to a Unix standard
 * @param string $string - content to be normalized
 * @return string
 */
function cf_normalize_line_endings($string) {
	$find = array(chr(13).chr(10),chr(13),"\r\n","\r");
	$replace = "\n";
	return str_replace($find,$replace,$string);
}

/**
 * WordPress likes to default to using '<br />' as "empty" content for posts and pages
 * The below function will replace '<br />' with a real "empty" string
 * @param string $content - the post or page content
 * @return string
 */
function cf_clear_empty_content($content) {
	if(trim($content) == '<br />') { $content = '';	}
	return $content;
}
add_filter('the_content','cf_clear_empty_content');

/**
 * If there's no excerpt truncate post_content and use it.
 * Use CF_NO_EXCERPT_LENGTH to control length of output
 * If CF_NO_EXCERPT_LENGTH is set to zero then filter will not alter the excerpt at all 
 *
 * @param string $excerpt - Default wordpress pulled excerpt
 * @return string - if excerpt was empty, then a truncated post_content
 */
function cf_get_the_excerpt($excerpt) {
	$excerpt = trim($excerpt);
	if((empty($excerpt) || $excerpt == '<br />') && CF_NO_EXCERPT_LENGTH > 0) {
		global $post;
		$excerpt = strip_tags($post->post_content);
		if(strlen($excerpt) > CF_NO_EXCERPT_LENGTH) {
			$excerpt = substr($excerpt, 0, CF_NO_EXCERPT_LENGTH-1).'&hellip;';
		}
	}
	return $excerpt;
}
/* Have to throw our define at init, so there's no race-condition with plugins loading after defined */
add_action('init', create_function("", "define('CF_NO_EXCERPT_LENGTH', apply_filters('cf_no_excerpt_length', 0));"));
//add_action('get_the_excerpt','cf_get_the_excerpt');

/**
 * Function for trimming text.  This function takes text and length as an input, and returns
 * the text truncated to the nearest word if the length of the text is longer than the length
 *
 * @param string $text - (required) Text to truncate
 * @param string $length - Length of the truncated string to return
 * @return $text - Truncated text being returned
 */
if (!function_exists('cf_trim_text')) {
	function cf_trim_text($text, $length = 250, $before = '', $after = '') {
		// If the text field is empty or is shorter than the $length, there is no need to make it smaller
		
		/* Since servers must have MB module installed for mb_* functions, we're keeping the fallback to non-multibyte functions */
		if (function_exists('mb_strlen')) { // 
			if (empty($text) || mb_strlen($text) <= $length) { return $text; }

			if (mb_strlen($text) > $length) {
				$text = mb_substr($text, 0, $length); // cut string to proper length
				if (mb_strrpos($text, ' ')) { // if we have spaces in text, cut to the last word, not letter
					$text = mb_substr($text, 0, mb_strrpos($text, ' ')); 
				}
			}
		}
		else {
			if (empty($text) || strlen($text) <= $length) { return $text; }

			if (strlen($text) > $length) {
				$text = substr($text, 0, $length); // cut string to proper length
				if (strrpos($text, ' ')) { // if we have spaces in text, cut to the last word, not letter
					$text = substr($text, 0, strrpos($text, ' ')); 
				}
			}
		}
		return $before.$text.$after;
	}
}


/**
 * Shortcode handler to run certain functions inside a shortcode
 * To add more available functions add the function name to the functions_allowed array
 *
 * @example [cf-call-func type="get_permalink" param="5947"]
 * 			Runs: get_permalink(5947);
 * @example [cf-call-func type="my_func" params="1,2"]
 * 			Runs: my_func(1,2);
 * @param array $atts - array of values from the shortcode
 * @return string parse shortcode
 */
function cf_call_func_shortcode($atts) {
	$atts = extract(shortcode_atts(array('type'=>'bloginfo','param'=>'url'),$atts));
	$functions_allowed = array(
							'get_bloginfo',
							'bloginfo',
							'my_test_func',
							'get_permalink',
							'get_category_link'
							);
	if(!in_array($type,$functions_allowed)) { return null; }
	
	ob_start();
	$result = call_user_func_array($type,explode(',',$param));
	$buffer = ob_get_clean();
	
	return (!empty($buffer) ? $buffer : $result);
}
add_shortcode('cf-call-func','cf_call_func_shortcode');

/**
 * Content handler for shortening the amount of content
 * Function inputs some content, and if it needs to it shortens it
 * using the defined length
 * 
 * @param string $content - Content to be shortened
 * @param integer $length - Length of string to be returned
 * @return string
 */
function cf_trim_content($before_content = '',$after_content = '',$content,$length = 250) {
	$content = str_replace(']]>', ']]&gt;', $content);
	$content = preg_replace('/<img[^>]*>/','',$content);
	$content = preg_replace('/\[(.*?)\]/','',$content);
	$content = strip_tags($content);

	if(strlen($content) > $length) {
		$content = substr($content, 0, $length);
		$content = substr($content, 0, strrpos($content, ' '));
	}
	$content = cf_close_opened_tags($content);
	$content = $before_content.$content.$after_content;
	$content = apply_filters('the_content', $content);
	return $content;
}

/**
 * Content handler for shortening the amount of content
 * Function inputs some content, and if it needs to it shortens it
 * using the defined length.  This function will keep shortcodes in place.
 * 
 * @param string $content - Content to be shortened
 * @param integer $length - Length of string to be returned
 * @return string
 */
function cf_trim_content_with_shortcodes($before_content = '',$after_content = '',$content,$length = 250) {
	$content = str_replace(']]>', ']]&gt;', $content);
	$content = preg_replace('/<img[^>]*>/','',$content);
	// $content = preg_replace('/\[(.*?)\]/','',$content);
	// $content = strip_tags($content);

	if(strlen($content) > $length) {
		$content = substr($content, 0, $length);
		$content = substr($content, 0, strrpos($content, ' '));
	}
	$content = cf_close_opened_tags($content);
	$content = $before_content.$content.$after_content;
	$content = apply_filters('the_content', $content);
	return $content;
}

/**
 * Function to close any opened tags in a string
 * Makes no attempt to put them in the proper place, just makes sure that everything closes
 *
 * @param string $string 
 * @return string
 */
function cf_close_opened_tags($string) {
	preg_match_all('/<(\w+)/',$string,$open_tags);
	preg_match_all('/<\/(\w+)/',$string,$close_tags);

	// if open & close match then get out quickly
	if(count($open_tags[1]) == count($close_tags[1])) { 
		return $string;
	}

	// log found open tags
	$tags = array();
	foreach($open_tags[1] as $found) {
		if(!isset($tags[$found])) {
			$tags[$found] = 0;
		}
		$tags[$found]++;
	}

	// process found close tags
	foreach($close_tags[1] as $found) {
		$tags[$found]--;
		if($tags[$found] == 0) { unset($tags[$found]); }
	}

	// feeble attempt to get a semblance of order
	$tags = array_reverse($tags,true);
	foreach($tags as $tagname => $tag_count) {
		if($tag_count) {
			$string .= '</'.$tagname.'>';
		}
	}
	return $string;
}


/**
 * Sort an array by a key within the array_items
 * Items can be arrays or objects, but must all be the same type
 *
 * @example
 * 		$array = array(
 *					'mary' => array('age' => 21),
 * 					'bob' => array('age' => 5),
 *					'justin' => array('age' => 15)
 *					);
 *		$array = cf_sort_by_key($array,'age');
 *		# array is now: bob,justin,mary
 *
 * @param $data - the array of items to work on
 * @param $sort_key - an array key or object member to use as the sort key
 * @param $ascending - wether to sort in reverse/descending order
 * @return array - sorted array
 */
function cf_sort_by_key($data,$sort_key,$ascending=true) {
	$order = $ascending ? '$a,$b' : '$b,$a';
	
	if(is_object(current($data))) { $callback = create_function($order,'return strnatcasecmp($a->'.$sort_key.',$b->'.$sort_key.');'); }
	else { $callback = create_function($order,'return strnatcasecmp($a["'.$sort_key.'"],$b["'.$sort_key.'"]);'); }
	
	uasort($data,$callback);
	return $data;
}

/**
 * if non admin users cannot be in the admin section
 * force redirect any attempts to access the admin pages
 * add this function to an init handler to enforce
 */
function cf_non_admin_redirect($capability = 'edit_posts') {
	$below_threshold = (is_admin() && !current_user_can($capability));
	$below_threshold = apply_filters('cf_non_admin_threshold', $below_threshold);
	// if the user has the right capabilities, or this is the flash uploader,
	// let it thorugh
	if (!$below_threshold || basename($_SERVER['SCRIPT_NAME']) == 'async-upload.php') {
		return true;
	}
	else {
		$requested_page = strtolower(basename($_SERVER['SCRIPT_NAME']));
		//Adding Filter for allowing non-editor-level users access
		//to specific pages
		if (!in_array($requested_page, apply_filters('cf_non_admin_allowed_pages', array()))) {
			/* Adding filter of where to dump users upon redirect */
			wp_redirect(apply_filters('cf_non_admin_redirect_to', get_bloginfo('url')));
			exit;
		}
	}
}

/**
 * Generic singleton parent class
 * php4 compatible, 'cause, well, you know
 *
 * child class needs to define a &get_instance() method that calls:
 *		return parent::__get_instance('child_class_name');
 */
class singleton_interface {
	
	function singleton_interface() {}
	
	function &__get_instance($classname) {
		static $instances = array();
		if (!isset($instances[$classname])) {
			$instances[$classname] = new $classname();
		}
		return $instances[$classname];
	}
	
	function &get_instance() {
		trigger_error('singleton_interface::get_instance() cannot be called directly. Override from child class.', E_USER_ERROR);
	}
}

/**
 * JSON ENCODE for PHP < 5.2.0
 * Checks if json_encode is not available and defines json_encode
 * to use php_json_encode in its stead
 * Works on iteratable objects as well - stdClass is iteratable, so all WP objects are gonna be iteratable
 */ 
if(!function_exists('cf_json_encode')) {
	function cf_json_encode($data) {
		if(function_exists('json_encode')) { return json_encode($data); }
		else { return cfjson_encode($data); }
	}
	
	function cfjson_encode_string($str) {
		if(is_bool($str)) { 
			return $str ? 'true' : 'false'; 
		}
	
		return str_replace(
			array(
				'"'
				, '/'
				, "\n"
				, "\r"
			)
			, array(
				'\"'
				, '\/'
				, '\n'
				, '\r'
			)
			, $str
		);
	}

	function cfjson_encode($arr) {
		$json_str = '';
		if (is_array($arr)) {
			$pure_array = true;
			$array_length = count($arr);
			for ( $i = 0; $i < $array_length ; $i++) {
				if (!isset($arr[$i])) {
					$pure_array = false;
					break;
				}
			}
			if ($pure_array) {
				$json_str = '[';
				$temp = array();
				for ($i=0; $i < $array_length; $i++) {
					$temp[] = sprintf("%s", cfjson_encode($arr[$i]));
				}
				$json_str .= implode(',', $temp);
				$json_str .="]";
			}
			else {
				$json_str = '{';
				$temp = array();
				foreach ($arr as $key => $value) {
					$temp[] = sprintf("\"%s\":%s", $key, cfjson_encode($value));
				}
				$json_str .= implode(',', $temp);
				$json_str .= '}';
			}
		}
		else if (is_object($arr)) {
			$json_str = '{';
			$temp = array();
			foreach ($arr as $k => $v) {
				$temp[] = '"'.$k.'":'.cfjson_encode($v);
			}
			$json_str .= implode(',', $temp);
			$json_str .= '}';
		}
		else if (is_string($arr)) {
			$json_str = '"'. cfjson_encode_string($arr) . '"';
		}
		else if (is_numeric($arr)) {
			$json_str = $arr;
		}
		else if (is_bool($arr)) {
			$json_str = $arr ? 'true' : 'false';
		}
		else {
			$json_str = '"'. cfjson_encode_string($arr) . '"';
		}
		return $json_str;
	}
}

/**
 * Returns a date range - for auto-updating the copyright date in a site footer.
 */
if (!function_exists('cf_get_copyright_date')) {
	function cf_get_copyright_date($year = 0) {
		$output = '';
		if ($year == 0) {
			$output = date('Y');
		}
		else {
			$output = $year;
			if (date('Y') > $year) {
				$output .= '-'.date('Y');
			}
		}
		return $output;
	}
}

/**
 * Echoes a date range - for auto-updating the copyright date in a site footer.
 */
if (!function_exists('cf_copyright_date')) {
	function cf_copyright_date($year = 0) {
		echo cf_get_copyright_date($year);
	}
}

/**
 * Shortcode for displaying a date range - for auto-updating the copyright date in a site footer.
 */
if (!function_exists('cf_copyright_date_shortcode')) {
	function cf_copyright_date_shortcode($atts) {
		$atts = extract(shortcode_atts(array('year' => 0),$atts));
		return cf_get_copyright_date($year);
	}
	add_shortcode('cf_copyright_date','cf_copyright_date_shortcode');
}

/**
 * return a formatted phone number
 * Only accounts for formatting a 10 digit phone string/int
 *
 * @param string/int $num - number to format
 * @param string $format - PCRE replacement string
 * @return string
 */
function cf_format_phone($num,$format='($1) $2-$3') {
        $clean = preg_replace('/[^0-9]/','',$num);
        return preg_replace('/([\w]{3})([\w]{3})([\w]{4})/',$format,$clean);
}

/**
 * Convert an array of data into a plain HTML table
 * does not variable row length handling, and only handles a 2D array
 * Args can contain supplementary information to include in the output
 *	- table_id - DOM id to apply to the table
 *	- table_class - CSS class to apply to the table
 *	- tr_class - CSS class to apply to table rows
 *
 * @TODO attach a class to empty tds
 *
 * @param array $data
 * @param bool/array $header_row_from - build a header row from first row's values
 * @param array $args - see above
 * @return string - HTML
 */
function cf_array_to_html_table($data,$header_row_from=false,$args=array()) {
	$table = '';
	$header_data = false; 
	
	$defaults = array(
			'table_id' => null,
			'table_class' => null,
			'tr_class' => null,
			//'empty_td_class' => null
		);
	extract(array_merge($defaults,$args));

	if($header_row_from == 'first') {
		$header_data = array_shift($data);
	}
	elseif($header_row_from = 'keys') {
		$header_data = array_keys(current($data));
	}
	elseif(is_array($header_row_from)) {
		// balance header values if they count doesn't match the 2D array item count
		if(count($header_row_from) != count(current($data))){
			$difference = count(current($data)) - count($header_row_from);
			if($difference > 0) {
				array_merge($header_row_from,array_fill(0,$difference,'&nbsp;'));
			}
			else {
				$header_row_from = array_slice($header_row_from,0,$difference);
			}
		}
		$header_data = $header_row_from;
	}
	
	$table .= '<table'.($table_id != null ? ' id="'.$table_id.'"' : null).($table_class != null ? ' class="'.$table_class.'"' : null).'>';
	if($header_data) {
		$table .= '<thead>'.'<tr><th>'.implode('</th><th>',$header_data).'</th></tr>'.'</thead>';
	}
	$table .= '<tbody>';
	foreach($data as $row) {
		if(!is_array($row)) { continue; } 
		$table .= '<tr'.($tr_class != null ? ' class="'.$tr_class.'"' : '').'><td>'.implode('</td><td>',$row).'</td></tr>'; 
	}
	$table .= '</tbody>'.
			  '</table>';

	return $table;
}

/**
 * Function for getting the id of a page by its slug
 *
 * @param string $page_name - Slug of the page
 * @return $page->ID - ID of the page slug passed in
 */
function cf_get_page_by_slug($page_name){
	global $wpdb;
	$page = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name = '".$page_name."'");
	return $page;
}

/**
 * functionality for showing all the wordpress queries in page footer
 */
// uncomment the next two lines to do query debugging
// define('SAVEQUERIES',false);
// define('CF_QUERY_DEBUG',false)
if(defined('CF_QUERY_DEBUG') && CF_QUERY_DEBUG) {
	add_action('wp_footer','cf_saved_queries_output');
	add_action('admin_footer','cf_saved_queries_output');
	/**
	 * Output all the page's queries at the bottom of the page
	 * Use define('CF_QUERY_DEBUG',true) to activate
	 */
	function cf_saved_queries_output() {
		global $wpdb;
		if(defined('SAVEQUERIES') && SAVEQUERIES) {
			echo '<ul style="text-align: left; font-size: 12px; list-style: disc outside; padding-left: 15px; margin-left: 10px">';
			foreach($wpdb->queries as $query) {
				echo '<li style="margin-bottom: 10px;">'.$query[0].'</li>';
			}
			echo '</ul>';
		}
	}
}

/** 
 * 
 * CF Compat Option Export Functions
 * 
 */

function cf_menu_items() {
	if (current_user_can('manage_options')) {
		add_management_page(
			__('CF Site Options', 'cf-compat'),
			__('CF Site Options', 'cf-compat'),
			'manage_options',
			basename(__FILE__),
			'cf_site_options'
		);
	}
}
add_action('admin_menu','cf_menu_items');

function cf_request_handler() {
	if (current_user_can('manage_options')) {
		$blogurl = '';
		if (is_ssl()) {
			$blogurl = str_replace('http://','https://',get_bloginfo('wpurl'));
		}
		else {
			$blogurl = get_bloginfo('wpurl');
		}				
		if (isset($_POST['cf_action']) && $_POST['cf_action'] != '') {
			switch ($_POST['cf_action']) {
				case 'cf_import_options':
					if (isset($_POST['cf_import']) && !empty($_POST['cf_import'])) {
						$import = unserialize(urldecode($_POST['cf_import']));
						cf_import_process($import,true);
					}
					break;
			}
		}
	}
}
add_action('init', 'cf_request_handler');

function cf_import_process($options) {
	foreach ($options as $key => $option) {
		$imported = false;
		if (is_a($option,'stdClass')) {
			$option_name = $option->option_name;
			$option_value = $option->option_value;
		}
		else {
			$option_name = $option['option_name'];
			$option_value = $option['option_value'];
		}
		$imported = update_option($option_name, $option_value);
		print('Option Name: '.$option_name.' || Imported: '.$imported.'<br />');
	}
}

function cf_site_options_head() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function() {
			jQuery("#cf-export-checkall").click(function() {
				var checked_status = this.checked;
				jQuery("input[@type=checkbox]").each(function() {
					this.checked = checked_status;
				});
			});	
		});
	</script>
	<?php
}
add_action('admin_head','cf_site_options_head');

function cf_site_options_nav($page = '') {
	$blogurl = '';
	if (is_ssl()) {
		$blogurl = str_replace('http://','https://',get_bloginfo('wpurl'));
	}
	else {
		$blogurl = get_bloginfo('wpurl');
	}		
	
	switch ($page) {
		case 'import':
			$import_text = ' class="current"';
			break;
		case 'export':
		case 'export_list':
			$export_text = ' class="current"';
			break;
		default:
			$import_text = ' class="current"';
			break;
	}
	
	$nav .= '
		<ul class="subsubsub">
			<li>
				<a href="'.trailingslashit($blogurl).'wp-admin/tools.php?page=cf-compat.php&cf_page=import"'.$import_text.'>'.__('Import','cf-compat').'</a> |
			</li>
			<li>
				<a href="'.trailingslashit($blogurl).'wp-admin/tools.php?page=cf-compat.php&cf_page=export"'.$export_text.'>'.__('Export','cf-compat').'</a>
			</li>
		</ul>
	';
	return $nav;
}

function cf_site_options() {
	if (isset($_POST['cf_action']) && $_POST['cf_action'] == 'cf_export_options') {
		$page = 'export_list';
	}
	else if (isset($_GET['cf_page'])) {
		$page = $_GET['cf_page'];
	}
	print('
	<div class="wrap">
	');
	screen_icon();
	print('
		<h2>CF Import/Export Site Options</h2>
		'.cf_site_options_nav($page));
		if (isset($page)) {
			switch ($page) {
				case 'import':
					cf_import_options();
					break;
				case 'export':
					cf_export_options();
					break;
				case 'export_list':
					cf_export_options_list();
					break;
			}
		}
		else {
			cf_import_options();
		}
	print('
	</div>
	');
}

function cf_import_options() {
	print('
		<form action="" method="post" id="cf-import-options">
			<table class="widefat">
				<thead>
					<tr>
						<th scope="col">'.__('Enter Data from Export', 'cf-compat').'</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
							<textarea name="cf_import" rows="15" style="width:100%;"></textarea>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit" style="border-top: none;">
				<input type="hidden" name="cf_action" value="cf_import_options" />
				<input type="submit" name="submit" id="cf-submit" class="button-primary button" value="'.__('Import Options', 'cf-compat').'" />
			</p>
		</form>
	');
}

function cf_export_options() {
	global $wpdb;
	
	$query = "SELECT * FROM $wpdb->options";
	$results = $wpdb->get_results($query);
	
	print('
		<form action="" method="post" id="cf-export-options">		
			<table class="widefat post fixed">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-cb check-column"><input type="checkbox" id="cf-export-checkall" /></th>
						<th scope="col" class="manage-column column-title" width="300px">'.__('Option Name', 'cf-compat').'</th>
						<th scope="col" class="manage-column column-title">'.__('Value', 'cf-compat').'</th>
					</tr>
				</thead>
				<tbody>
	');
	foreach ($results as $result) {
		print('
					<tr>
						<td style="text-align: center;">
							<input type="checkbox" name="cfexport[]" value="'.$result->option_id.'" />
						</td>
						<td>
							'.$result->option_name.'
						</td>
						<td>
							<div style="width:600px;">
								'.$result->option_value.'
							</div>
						</td>
					</tr>
		');
	}
	print('
				</tbody>
			</table>
			<p class="submit" style="border-top: none;">
				<input type="hidden" name="cf_action" value="cf_export_options" />
				<input type="submit" name="submit" id="cf-submit" class="button-primary button" value="'.__('Export Selected Options', 'cf-compat').'" />
			</p>
		</form>
	');
}

function cf_export_options_list() {
	if (!isset($_POST['cfexport']) || empty($_POST['cfexport'])) { return false; }
	global $wpdb;
	
	$options = $_POST['cfexport'];
	$export = array();
	foreach ($options as $option_id) {
		$query = "SELECT * FROM $wpdb->options WHERE option_id LIKE $option_id";
		$results = $wpdb->get_results($query);
		$export[] = array(
			'option_name' => $results[0]->option_name,
			'option_value' => maybe_unserialize($results[0]->option_value)
		);
		//print_r(unserialize($results[0]->option_value));
	}
	$export = urlencode(serialize($export));
	
	print('
		<table class="widefat">
			<thead>
				<tr>
					<th scope="col">'.__('Export Data', 'cf-compat').'</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>
						'.__('Copy the date in this text area, and paste it into the import text area of the blog you need these options for.','cf-compat').'
						<textarea name="cf_export" rows="15" style="width:100%;">'.$export.'</textarea>
					</td>
				</tr>
			</tbody>
		</table>		
	');
}

/**
 * Build simple relative dates
 * Doesn't go too deep in to specificity as that is rarely needed
 *
 * @author http://snipplr.com/view/4912/relative-time/
 * @param string $date - date to evaluate
 * @param string $pre - default 'about' - what to put before the time output
 * @param string $post - default 'ago' - what to put after the time output
 * @param int $full_date_cutoff - default 4, how old a date should be until it gets formatted as a date string
 * @param string $format - format for date output past 4 weeks
 * @param string $pre_format - default '' - what to put before the date out past 4 weeks
 * @return string
 */
function cf_relative_time_ago($date,$pre='about',$post='ago',$full_date_cutoff=4,$format='F j, Y',$pre_format, $gmt = false) {
	$pre .= ' ';
	$post = ' '.$post;
	$pre_format = ' ';

	if ($gmt) {
		$now = gmmktime();
	}
	else {
		$orig_tz = date_default_timezone_get();
		date_default_timezone_set(get_option('timezone_string'));
		$now = time();
	}

	if(!is_numeric($date)) { 
		$date = strtotime($date); 
	}

	// seconds
	$diff = $now - $date;
	if ($diff < 60){ 
		return sprintf('%1$s%2$s%3$s', $pre, sprintf(
			_n('%d second', '%d seconds', $diff), $diff), $post);
	}
	
	// minutes
	$diff = round($diff/60);
	if ($diff < 60) { 
		return sprintf('%1$s%2$s%3$s', $pre, sprintf(
			_n('%d minute', '%d minutes', $diff), $diff), $post);
	}
	
	// hours
	$diff = round($diff/60);
	if ($diff < 24) {
		return sprintf('%1$s%2$s%3$s', $pre, sprintf(
			_n('%d hour', '%d hours', $diff), $diff), $post);
	}
	
	// days
	$diff = round($diff/24);
	if ($diff < 7) { 
		return sprintf('%1$s%2$s%3$s', $pre, sprintf(
			_n('%d day', '%d days', $diff), $diff), $post);
	}
	
	// weeks
	$diff = round($diff/7);
	if ($diff <= $full_date_cutoff) { 
		return sprintf('%1$s%2$s%3$s', $pre, sprintf(
			_n('%d week', '%d weeks', $diff), $diff), $post);
	}

	// actual date string if farther than 4 weeks ago
	$ago = $pre_format . mysql2date($format, date('Y-m-d H:i:s', $date));

	if (!$gmt) {
		date_default_timezone_set($orig_tz);
	}
	return $ago;
}

/**
 * Since our friends on the WordPress core team thought that this function was too dangerous for us 
 * poor developers to use, it is included here so we can use it.
 *
 * @param string $start 
 * @param string $num 
 * @return array
 */
function cf_get_blog_list( $start = 0, $num = 10 ) {
	global $wpdb;

	$blogs = get_site_option( "blog_list" );
	$update = false;
	if ( is_array( $blogs ) ) {
		if ( ( $blogs['time'] + 60 ) < time() ) { // cache for 60 seconds.
			$update = true;
		}
	} else {
		$update = true;
	}

	if ( $update == true ) {
		unset( $blogs );
		$blogs = $wpdb->get_results( $wpdb->prepare("SELECT blog_id, domain, path FROM $wpdb->blogs WHERE site_id = %d AND public = '1' AND archived = '0' AND mature = '0' AND spam = '0' AND deleted = '0' ORDER BY registered DESC", $wpdb->siteid), ARRAY_A );

		foreach ( (array) $blogs as $details ) {
			$blog_list[ $details['blog_id'] ] = $details;
			$blog_list[ $details['blog_id'] ]['postcount'] = $wpdb->get_var( "SELECT COUNT(ID) FROM " . $wpdb->base_prefix . $details['blog_id'] . "_posts WHERE post_status='publish' AND post_type='post'" );
		}
		unset( $blogs );
		$blogs = $blog_list;
		update_site_option( "blog_list", $blogs );
	}

	if ( false == is_array( $blogs ) )
		return array();

	if ( $num == 'all' )
		return array_slice( $blogs, $start, count( $blogs ) );
	else
		return array_slice( $blogs, $start, $num );
}


/**
 * cf_tiny_mce - This function inserts TinyMCE JS code into the page so we can convert textareas into TinyMCE editing areas.  For this to work, the action listed
 * in the "action to add" section below needs to be added to the plugin being written.  Also, the textarea needs to have a class of "cf_tiny_mce" for the TinyMCE
 * JS to be added.
 *
 * -- action to add: add_action('admin_print_footer_scripts', 'cf_tiny_mce', 25);
 * @return void
 */
function cf_tiny_mce( $teeny = false ) {
	global $concatenate_scripts, $compress_scripts, $tinymce_version;

	if ( ! user_can_richedit() )
		return;

	$baseurl = includes_url('js/tinymce');

	$mce_locale = ( '' == get_locale() ) ? 'en' : strtolower( substr(get_locale(), 0, 2) ); // only ISO 639-1

	/*
	The following filter allows localization scripts to change the languages displayed in the spellchecker's drop-down menu.
	By default it uses Google's spellchecker API, but can be configured to use PSpell/ASpell if installed on the server.
	The + sign marks the default language. More information:
	http://wiki.moxiecode.com/index.php/TinyMCE:Plugins/spellchecker
	*/
	$mce_spellchecker_languages = apply_filters('mce_spellchecker_languages', '+English=en,Danish=da,Dutch=nl,Finnish=fi,French=fr,German=de,Italian=it,Polish=pl,Portuguese=pt,Spanish=es,Swedish=sv');

	if ( $teeny ) {
		$plugins = apply_filters( 'teeny_mce_plugins', array('safari', 'inlinepopups', 'media', 'autosave') );
		$ext_plugins = '';
	} else {
		$plugins = array( 'safari', 'inlinepopups', 'spellchecker', 'paste', 'tabfocus' );

		/*
		The following filter takes an associative array of external plugins for TinyMCE in the form 'plugin_name' => 'url'.
		It adds the plugin's name to TinyMCE's plugins init and the call to PluginManager to load the plugin.
		The url should be absolute and should include the js file name to be loaded. Example:
		array( 'myplugin' => 'http://my-site.com/wp-content/plugins/myfolder/mce_plugin.js' )
		If the plugin uses a button, it should be added with one of the "$mce_buttons" filters.
		*/
		$mce_external_plugins = apply_filters('mce_external_plugins', array());

		$ext_plugins = '';
		if ( ! empty($mce_external_plugins) ) {

			/*
			The following filter loads external language files for TinyMCE plugins.
			It takes an associative array 'plugin_name' => 'path', where path is the
			include path to the file. The language file should follow the same format as
			/tinymce/langs/wp-langs.php and should define a variable $strings that
			holds all translated strings.
			When this filter is not used, the function will try to load {mce_locale}.js.
			If that is not found, en.js will be tried next.
			*/
			$mce_external_languages = apply_filters('mce_external_languages', array());

			$loaded_langs = array();
			$strings = '';

			if ( ! empty($mce_external_languages) ) {
				foreach ( $mce_external_languages as $name => $path ) {
					if ( @is_file($path) && @is_readable($path) ) {
						include_once($path);
						$ext_plugins .= $strings . "\n";
						$loaded_langs[] = $name;
					}
				}
			}

			foreach ( $mce_external_plugins as $name => $url ) {

				if ( is_ssl() ) $url = str_replace('http://', 'https://', $url);

				$plugins[] = '-' . $name;

				$plugurl = dirname($url);
				$strings = $str1 = $str2 = '';
				if ( ! in_array($name, $loaded_langs) ) {
					$path = str_replace( WP_PLUGIN_URL, '', $plugurl );
					$path = WP_PLUGIN_DIR . $path . '/langs/';

					if ( function_exists('realpath') )
						$path = trailingslashit( realpath($path) );

					if ( @is_file($path . $mce_locale . '.js') )
						$strings .= @file_get_contents($path . $mce_locale . '.js') . "\n";

					if ( @is_file($path . $mce_locale . '_dlg.js') )
						$strings .= @file_get_contents($path . $mce_locale . '_dlg.js') . "\n";

					if ( 'en' != $mce_locale && empty($strings) ) {
						if ( @is_file($path . 'en.js') ) {
							$str1 = @file_get_contents($path . 'en.js');
							$strings .= preg_replace( '/([\'"])en\./', '$1' . $mce_locale . '.', $str1, 1 ) . "\n";
						}

						if ( @is_file($path . 'en_dlg.js') ) {
							$str2 = @file_get_contents($path . 'en_dlg.js');
							$strings .= preg_replace( '/([\'"])en\./', '$1' . $mce_locale . '.', $str2, 1 ) . "\n";
						}
					}

					if ( ! empty($strings) )
						$ext_plugins .= "\n" . $strings . "\n";
				}

				$ext_plugins .= 'tinyMCEPreInit.load_ext("' . $plugurl . '", "' . $mce_locale . '");' . "\n";
				$ext_plugins .= 'tinymce.PluginManager.load("' . $name . '", "' . $url . '");' . "\n";
			}
		}
	}

	$plugins = implode($plugins, ',');

	if ( $teeny ) {
		$mce_buttons = apply_filters( 'teeny_mce_buttons', array('bold, italic, underline, blockquote, separator, strikethrough, bullist, numlist,justifyleft, justifycenter, justifyright, undo, redo, link, unlink') );
		$mce_buttons = implode($mce_buttons, ',');
		$mce_buttons_2 = $mce_buttons_3 = $mce_buttons_4 = '';
	} else {
		$mce_buttons = apply_filters('mce_buttons', array('bold', 'italic', 'strikethrough', '|', 'bullist', 'numlist', 'blockquote', '|', 'justifyleft', 'justifycenter', 'justifyright', '|', 'link', 'unlink', '|', 'spellchecker', '|', 'code' ));
		$mce_buttons = implode($mce_buttons, ',');

		$mce_buttons_2 = apply_filters('mce_buttons_2', array('formatselect', 'underline', 'justifyfull', 'forecolor', '|', 'pastetext', 'pasteword', 'removeformat', '|', 'media', 'charmap', '|', 'outdent', 'indent', '|', 'undo', 'redo' ));
		$mce_buttons_2 = implode($mce_buttons_2, ',');

		$mce_buttons_3 = apply_filters('mce_buttons_3', array());
		$mce_buttons_3 = implode($mce_buttons_3, ',');

		$mce_buttons_4 = apply_filters('mce_buttons_4', array());
		$mce_buttons_4 = implode($mce_buttons_4, ',');
	}
	$no_captions = ( apply_filters( 'disable_captions', '' ) ) ? true : false;

	// TinyMCE init settings
	$initArray = array (
		'mode' => 'specific_textareas',
		'editor_selector' => 'cf_tiny_mce',
		'width' => '100%',
		'theme' => 'advanced',
		'skin' => 'wp_theme',
		'theme_advanced_buttons1' => "$mce_buttons",
		'theme_advanced_buttons2' => "$mce_buttons_2",
		'theme_advanced_buttons3' => "$mce_buttons_3",
		'theme_advanced_buttons4' => "$mce_buttons_4",
		'language' => "$mce_locale",
		'spellchecker_languages' => "$mce_spellchecker_languages",
		'theme_advanced_toolbar_location' => 'top',
		'theme_advanced_toolbar_align' => 'left',
		'theme_advanced_statusbar_location' => 'bottom',
		'theme_advanced_resizing' => true,
		'theme_advanced_resize_horizontal' => false,
		'dialog_type' => 'modal',
		'relative_urls' => false,
		'remove_script_host' => false,
		'convert_urls' => false,
		'apply_source_formatting' => false,
		'remove_linebreaks' => true,
		'gecko_spellcheck' => true,
		'entities' => '38,amp,60,lt,62,gt',
		'accessibility_focus' => true,
		'tabfocus_elements' => 'major-publishing-actions',
		'media_strict' => false,
		'save_callback' => '',
		'wpeditimage_disable_captions' => $no_captions,
		'plugins' => "$plugins"
	);

	$mce_css = trim(apply_filters('mce_css', ''), ' ,');

	if ( ! empty($mce_css) )
		$initArray['content_css'] = "$mce_css";

	// For people who really REALLY know what they're doing with TinyMCE
	// You can modify initArray to add, remove, change elements of the config before tinyMCE.init
	// Setting "valid_elements", "invalid_elements" and "extended_valid_elements" can be done through "tiny_mce_before_init".
	// Best is to use the default cleanup by not specifying valid_elements, as TinyMCE contains full set of XHTML 1.0.
	if ( $teeny ) {
		$initArray = apply_filters('teeny_mce_before_init', $initArray);
	} else {
		$initArray = apply_filters('tiny_mce_before_init', $initArray);
	}

	if ( empty($initArray['theme_advanced_buttons3']) && !empty($initArray['theme_advanced_buttons4']) ) {
		$initArray['theme_advanced_buttons3'] = $initArray['theme_advanced_buttons4'];
		$initArray['theme_advanced_buttons4'] = '';
	}

	if ( ! isset($concatenate_scripts) )
		script_concat_settings();

	$language = $initArray['language'];
	$zip = $compress_scripts ? 1 : 0;

	/**
	 * Deprecated
	 *
	 * The tiny_mce_version filter is not needed since external plugins are loaded directly by TinyMCE.
	 * These plugins can be refreshed by appending query string to the URL passed to mce_external_plugins filter.
	 * If the plugin has a popup dialog, a query string can be added to the button action that opens it (in the plugin's code).
	 */
	$version = apply_filters('tiny_mce_version', '');
	$version = 'ver=' . $tinymce_version . $version;

	if ( 'en' != $language )
		include_once(ABSPATH . WPINC . '/js/tinymce/langs/wp-langs.php');

	$mce_options = '';
	foreach ( $initArray as $k => $v )
	    $mce_options .= $k . ':"' . $v . '", ';

	$mce_options = rtrim( trim($mce_options), '\n\r,' ); ?>

<script type="text/javascript">
/* <![CDATA[ */
tinyMCEPreInit = {
	base : "<?php echo $baseurl; ?>",
	suffix : "",
	query : "<?php echo $version; ?>",
	mceInit : {<?php echo $mce_options; ?>},
	load_ext : function(url,lang){var sl=tinymce.ScriptLoader;sl.markDone(url+'/langs/'+lang+'.js');sl.markDone(url+'/langs/'+lang+'_dlg.js');}
};
/* ]]> */
</script>

<?php
	if ( $concatenate_scripts )
		echo "<script type='text/javascript' src='$baseurl/wp-tinymce.php?c=$zip&amp;$version'></script>\n";
	else
		echo "<script type='text/javascript' src='$baseurl/tiny_mce.js?$version'></script>\n";

	if ( 'en' != $language && isset($lang) )
		echo "<script type='text/javascript'>\n$lang\n</script>\n";
	else
		echo "<script type='text/javascript' src='$baseurl/langs/wp-langs-en.js?$version'></script>\n";
?>

<script type="text/javascript">
/* <![CDATA[ */
<?php if ( $ext_plugins ) echo "$ext_plugins\n"; ?>
<?php if ( $concatenate_scripts ) { ?>
tinyMCEPreInit.go();
<?php } else { ?>
(function(){var t=tinyMCEPreInit,sl=tinymce.ScriptLoader,ln=t.mceInit.language,th=t.mceInit.theme,pl=t.mceInit.plugins;sl.markDone(t.base+'/langs/'+ln+'.js');sl.markDone(t.base+'/themes/'+th+'/langs/'+ln+'.js');sl.markDone(t.base+'/themes/'+th+'/langs/'+ln+'_dlg.js');tinymce.each(pl.split(','),function(n){if(n&&n.charAt(0)!='-'){sl.markDone(t.base+'/plugins/'+n+'/langs/'+ln+'.js');sl.markDone(t.base+'/plugins/'+n+'/langs/'+ln+'_dlg.js');}});})();
<?php } ?>
tinyMCE.init(tinyMCEPreInit.mceInit);
/* ]]> */
</script>
<?php
}

/**
 * Retrieve post meta field for multiple posts.
 *
 * @uses $wpdb
 * @link http://codex.wordpress.org/Function_Reference/get_post_meta
 *
 * @param mixed $post_ids Post ID or array of post IDS.
 * @param string $key The meta key to retrieve.
 * @param bool $single Whether to return a single value.
 * @return mixed Will be an array if $single is false. Will be value of meta data field if $single
 *  is true.
 */
function cf_get_post_meta($post_ids, $key, $single = false) {
	// if just one, pass through normal call
	if (is_array($post_ids) && count($post_ids) == 1) {
		$post_ids = $post_ids[0];
	}
	if (!is_array($post_ids)) {
		return get_post_meta($post_ids, $key, $single);
	}
	else {
		global $wpdb;
		$post_ids = array_unique(array_map('intval', $post_ids));
		$sql = $wpdb->prepare("
				SELECT post_id, meta_value
				FROM $wpdb->postmeta
				WHERE meta_key = '%s'
				AND post_id IN (".implode(',', $post_ids).")
			", 
			$key
		);
		$results = $wpdb->get_results($sql);
		$data = array();
		if (is_array($results) && count($results)) {
			foreach ($results as $result) {
				$data['post_'.$result->post_id] = maybe_unserialize($result->meta_value);
			}
		}
		return apply_filters('cf_get_post_meta_values', $data);
	}
}


?>
