<?php
/*
Plugin Name: CF Compatability
Plugin URI: http://crowdfavorite.com
Description:  General compatability functions compiled by Crowd Favorite
Version: 1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/
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
		if((empty($excerpt) || $excerpt != '<br />') && CF_NO_EXCERPT_LENGTH > 0) {
			global $post;
			$excerpt = strip_tags($post->post_content);
			if(strlen($excerpt) > CF_NO_EXCERPT_LENGTH) {
				$excerpt = substr($excerpt, 0, CF_NO_EXCERPT_LENGTH-1).'&hellip;';
			}
		}
		return $excerpt;
	}
	define('CF_NO_EXCERPT_LENGTH',0);
	add_action('get_the_excerpt','cf_get_the_excerpt');
	
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
								'get_permalink'
								);
		if(!in_array($type,$functions_allowed)) { return null; }
		
		ob_start();
		$result = call_user_func_array($type,explode(',',$param));
		$buffer = ob_get_clean();
		
		return (!empty($buffer) ? $buffer : $result);
	}
	add_shortcode('cf-call-func','cf_call_func_shortcode');
	
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
	function cf_non_admin_redirect() {
		if(is_admin() && !current_user_can('edit_pages')) {
			wp_redirect(get_bloginfo('url'));
			exit;
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

?>