<?php

if ( !class_exists('w3p_image_from_object') ) {

	class w3p_image_from_object {

		/*
		 * The provided attachment object must, at least, have ID, post_title, post_excerpt, post_type, post_mime_type and guid.
		 * v 1.0 - 2012-06
		 * By Grégory Viguier - www.screenfeed.fr
		 *
		 * A helper to build a less greedy query
		 * get_results()
		 *
		 * The following functions are quite similar to the original ones in WordPress.
		 * The only big difference is they don't take an attachment id as parameter, but an attachment object.
		 * image_downsize()
		 * get_image_tag()
		 * image_get_intermediate_size()
		 * wp_get_attachment_image_src()
		 * wp_get_attachment_image()
		 * wp_get_attachment_link()
		 * get_attached_file()
		 * wp_get_attachment_metadata()
		 * wp_get_attachment_url()
		 * wp_get_attachment_thumb_file()
		 * wp_get_attachment_thumb_url()
		 * wp_attachment_is_image()
		 * get_attachment_link()
		 */


		/*
		 * Construct the query: helper to get the attachments and reorder them
		 * @var (array)    $ids:         ids of the attachments.
		 * @var (constant) $output_type: output type, like in the real $wpdb->get_results(), but only OBJECT and OBJECT_K (default).
		 * @var (bool)     $order:       if true (default), results are ordered the same as the ids array. $order will be set to false if $output_type != OBJECT_K.
		 */
		function get_results( $ids = array(), $output_type = OBJECT_K, $order = true ) {
			if ( !is_array($ids) || !count($ids) )
				return (object)array();

			$ids			= array_map('intval', $ids);
			$output_type	= in_array($output_type, array(OBJECT, OBJECT_K)) ? $output_type : OBJECT_K;
			$order			= $output_type != OBJECT_K ? false : (bool) $order;

			global $wpdb;
			$sql = "
				SELECT p.*, ma.meta_value as _wp_attached_file, mb.meta_value as _wp_attachment_image_alt, mc.meta_value as _wp_attachment_metadata
				FROM $wpdb->posts p
				LEFT OUTER JOIN $wpdb->postmeta ma
				ON p.ID = ma.post_id
				AND ma.meta_key = '_wp_attached_file'
				LEFT OUTER JOIN $wpdb->postmeta mb
				ON p.ID = mb.post_id
				AND mb.meta_key = '_wp_attachment_image_alt'
				LEFT OUTER JOIN $wpdb->postmeta mc
				ON p.ID = mc.post_id
				AND mc.meta_key = '_wp_attachment_metadata'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type LIKE 'image/%'
				AND (p.ID = ".implode(" OR p.ID = ", $ids).")";
			$imgs = $wpdb->get_results( $sql, $output_type );

			// Reorder with the same order than $ids. Only available for OBJECT_K.
			if ( $order && $wpdb->num_rows ) {
				$out = array();
				foreach( $ids as $id ) {
					if ( !isset($imgs[$id]) )
						continue;
					$out[] = $imgs[$id];
				}
				return $out;
			}

			return $imgs;
		}


		// From includes/media.php
		function image_downsize( $attachment, $size = 'medium' ) {

			if ( !self::wp_attachment_is_image($attachment) )
				return false;

			$img_url = self::wp_get_attachment_url($attachment);
			$meta = self::wp_get_attachment_metadata($attachment);
			$width = $height = 0;
			$is_intermediate = false;
			$img_url_basename = wp_basename($img_url);

			// plugins can use this to provide resize services
			if ( $out = apply_filters('image_downsize', false, $attachment->ID, $size) )
				return $out;

			// try for a new style intermediate size
			if ( $intermediate = self::image_get_intermediate_size($attachment, $size) ) {
				$img_url = str_replace($img_url_basename, $intermediate['file'], $img_url);
				$width = $intermediate['width'];
				$height = $intermediate['height'];
				$is_intermediate = true;
			}
			elseif ( $size == 'thumbnail' ) {
				// fall back to the old thumbnail
				if ( ($thumb_file = self::wp_get_attachment_thumb_file($attachment)) && $info = getimagesize($thumb_file) ) {
					$img_url = str_replace($img_url_basename, wp_basename($thumb_file), $img_url);
					$width = $info[0];
					$height = $info[1];
					$is_intermediate = true;
				}
			}
			if ( !$width && !$height && isset($meta['width'], $meta['height']) ) {
				// any other type: use the real image
				$width = $meta['width'];
				$height = $meta['height'];
			}

			if ( $img_url) {
				// we have the actual image size, but might need to further constrain it if content_width is narrower
				list( $width, $height ) = image_constrain_size_for_editor( $width, $height, $size );

				return array( $img_url, $width, $height, $is_intermediate );
			}
			return false;

		}


		function get_image_tag( $attachment, $alt, $title, $align, $size='medium' ) {

			list( $img_src, $width, $height ) = self::image_downsize($attachment, $size);
			$hwstring = image_hwstring($width, $height);

			$class = 'align' . esc_attr($align) .' size-' . esc_attr($size) . ' wp-image-' . $attachment->ID;
			$class = apply_filters('get_image_tag_class', $class, $attachment->ID, $align, $size);

			$html = '<img src="' . esc_attr($img_src) . '" alt="' . esc_attr($alt) . '" title="' . esc_attr($title).'" '.$hwstring.'class="'.$class.'" />';

			$html = apply_filters( 'get_image_tag', $html, $attachment->ID, $alt, $title, $align, $size );

			return $html;
		}


		function image_get_intermediate_size( $post, $size='thumbnail' ) {
			if ( !is_array( $imagedata = self::wp_get_attachment_metadata( $post ) ) )
				return false;

			// get the best one for a specified set of dimensions
			if ( is_array($size) && !empty($imagedata['sizes']) ) {
				foreach ( $imagedata['sizes'] as $_size => $data ) {
					// already cropped to width or height; so use this size
					if ( ( $data['width'] == $size[0] && $data['height'] <= $size[1] ) || ( $data['height'] == $size[1] && $data['width'] <= $size[0] ) ) {
						$file = $data['file'];
						list($width, $height) = image_constrain_size_for_editor( $data['width'], $data['height'], $size );
						return compact( 'file', 'width', 'height' );
					}
					// add to lookup table: area => size
					$areas[$data['width'] * $data['height']] = $_size;
				}
				if ( !$size || !empty($areas) ) {
					// find for the smallest image not smaller than the desired size
					ksort($areas);
					foreach ( $areas as $_size ) {
						$data = $imagedata['sizes'][$_size];
						if ( $data['width'] >= $size[0] || $data['height'] >= $size[1] ) {
							// Skip images with unexpectedly divergent aspect ratios (crops)
							// First, we calculate what size the original image would be if constrained to a box the size of the current image in the loop
							$maybe_cropped = image_resize_dimensions($imagedata['width'], $imagedata['height'], $data['width'], $data['height'], false );
							// If the size doesn't match within one pixel, then it is of a different aspect ratio, so we skip it, unless it's the thumbnail size
							if ( 'thumbnail' != $_size && ( !$maybe_cropped || ( $maybe_cropped[4] != $data['width'] && $maybe_cropped[4] + 1 != $data['width'] ) || ( $maybe_cropped[5] != $data['height'] && $maybe_cropped[5] + 1 != $data['height'] ) ) )
								continue;
							// If we're still here, then we're going to use this size
							$file = $data['file'];
							list($width, $height) = image_constrain_size_for_editor( $data['width'], $data['height'], $size );
							return compact( 'file', 'width', 'height' );
						}
					}
				}
			}

			if ( is_array($size) || empty($size) || empty($imagedata['sizes'][$size]) )
				return false;

			$data = $imagedata['sizes'][$size];
			// include the full filesystem path of the intermediate file
			if ( empty($data['path']) && !empty($data['file']) ) {
				$file_url = self::wp_get_attachment_url( $post );
				$data['path'] = path_join( dirname($imagedata['file']), $data['file'] );
				$data['url'] = path_join( dirname($file_url), $data['file'] );
			}
			return $data;
		}


		function wp_get_attachment_image_src( $attachment, $size='thumbnail', $icon = false ) {

			// get a thumbnail or intermediate image if there is one
			if ( $image = self::image_downsize($attachment, $size) )
				return $image;

			$src = false;

			if ( $icon && $src = wp_mime_type_icon($attachment->ID) ) {
				$icon_dir = apply_filters( 'icon_dir', ABSPATH . WPINC . '/images/crystal' );
				$src_file = $icon_dir . '/' . wp_basename($src);
				@list($width, $height) = getimagesize($src_file);
			}
			if ( $src && $width && $height )
				return array( $src, $width, $height );
			return false;
		}


		function wp_get_attachment_image( $attachment, $size = 'thumbnail', $attr = '' ) {

			if ( empty($attachment) )
				return;

			$html = '';
			$image = self::wp_get_attachment_image_src($attachment, $size);
			if ( $image ) {
				list($src, $width, $height) = $image;
				$hwstring = image_hwstring($width, $height);
				if ( is_array($size) )
					$size = join('x', $size);
				$alt = property_exists($attachment, '_wp_attachment_image_alt') ? (string)$attachment->_wp_attachment_image_alt : get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
				$default_attr = array(
					'src'	=> $src,
					'class'	=> "attachment-$size",
					'alt'	=> trim(strip_tags( $alt )), // Use Alt field first
					'title'	=> trim(strip_tags( $attachment->post_title )),
				);
				if ( empty($default_attr['alt']) )
					$default_attr['alt'] = trim(strip_tags( $attachment->post_excerpt )); // If not, Use the Caption
				if ( empty($default_attr['alt']) )
					$default_attr['alt'] = trim(strip_tags( $attachment->post_title )); // Finally, use the title

				$attr = wp_parse_args($attr, $default_attr);
				$attr = apply_filters( 'wp_get_attachment_image_attributes', $attr, $attachment );
				$attr = array_map( 'esc_attr', $attr );
				$html = rtrim("<img $hwstring");
				foreach ( $attr as $name => $value ) {
					$html .= " $name=" . '"' . $value . '"';
				}
				$html .= ' />';
			}

			return $html;
		}


		// From includes/post-template.php
		function wp_get_attachment_link( $_post, $size = 'thumbnail', $permalink = false, $icon = false, $text = false ) {
			if ( empty( $_post ) || ( 'attachment' != $_post->post_type ) || ! $url = self::wp_get_attachment_url( $_post ) )
				return __( 'Missing Attachment' );

			if ( $permalink )
				$url = self::get_attachment_link( $_post );

			$post_title = esc_attr( $_post->post_title );

			if ( $text )
				$link_text = $text;
			elseif ( $size && 'none' != $size )
				$link_text = self::wp_get_attachment_image( $_post, $size, $icon );
			else
				$link_text = '';

			if ( trim( $link_text ) == '' )
				$link_text = $_post->post_title;

			return apply_filters( 'wp_get_attachment_link', "<a href='$url' title='$post_title'>$link_text</a>", $_post->ID, $size, $permalink, $icon, $text );
		}


		// From includes/post.php
		function get_attached_file( $attachment, $unfiltered = false ) {
			$file = property_exists($attachment, '_wp_attached_file') ? (string)$attachment->_wp_attached_file : get_post_meta( $attachment->ID, '_wp_attached_file', true );
			// If the file is relative, prepend upload dir
			if ( $file && 0 !== strpos($file, '/') && !preg_match('|^.:\\\|', $file) && ( ($uploads = wp_upload_dir()) && false === $uploads['error'] ) )
				$file = $uploads['basedir'] . "/$file";
			if ( $unfiltered )
				return $file;
			return apply_filters( 'get_attached_file', $file, $attachment->ID );
		}


		function wp_get_attachment_metadata( $post, $unfiltered = false ) {

			if ( empty($post) )
				return false;

			$data = property_exists($post, '_wp_attachment_metadata') ? maybe_unserialize((string)$post->_wp_attachment_metadata) : get_post_meta( $post->ID, '_wp_attachment_metadata', true );

			if ( $unfiltered )
				return $data;

			return apply_filters( 'wp_get_attachment_metadata', $data, $post->ID );
		}


		function wp_get_attachment_url( $post ) {

			if ( empty($post) )
				return false;

			if ( 'attachment' != $post->post_type )
				return false;

			$url = '';
			if ( $file = (property_exists($post, '_wp_attached_file') ? (string)$post->_wp_attached_file : get_post_meta( $post->ID, '_wp_attached_file', true)) ) { //Get attached file
				if ( ($uploads = wp_upload_dir()) && false === $uploads['error'] ) { //Get upload directory
					if ( 0 === strpos($file, $uploads['basedir']) ) //Check that the upload base exists in the file location
						$url = str_replace($uploads['basedir'], $uploads['baseurl'], $file); //replace file location with url location
					elseif ( false !== strpos($file, 'wp-content/uploads') )
						$url = $uploads['baseurl'] . substr( $file, strpos($file, 'wp-content/uploads') + 18 );
					else
						$url = $uploads['baseurl'] . "/$file"; //Its a newly uploaded file, therefor $file is relative to the basedir.
				}
			}

			if ( empty($url) ) //If any of the above options failed, Fallback on the GUID as used pre-2.7, not recommended to rely upon this.
				$url = apply_filters('get_the_guid', $post->guid);

			$url = apply_filters( 'wp_get_attachment_url', $url, $post->ID );

			if ( empty( $url ) )
				return false;

			return $url;
		}


		function wp_get_attachment_thumb_file( $post ) {

			if ( empty($post) )
				return false;
			if ( !is_array( $imagedata = self::wp_get_attachment_metadata( $post ) ) )
				return false;

			$file = self::get_attached_file( $post );

			if ( !empty($imagedata['thumb']) && ($thumbfile = str_replace(basename($file), $imagedata['thumb'], $file)) && file_exists($thumbfile) )
				return apply_filters( 'wp_get_attachment_thumb_file', $thumbfile, $post->ID );
			return false;
		}


		function wp_get_attachment_thumb_url( $post ) {

			if ( empty($post) )
				return false;
			if ( !$url = self::wp_get_attachment_url( $post ) )
				return false;

			$sized = self::image_downsize( $post, 'thumbnail' );
			if ( $sized )
				return $sized[0];

			if ( !$thumb = self::wp_get_attachment_thumb_file( $post ) )
				return false;

			$url = str_replace(basename($url), basename($thumb), $url);

			return apply_filters( 'wp_get_attachment_thumb_url', $url, $post->ID );
		}


		function wp_attachment_is_image( $post ) {

			if ( empty($post) )
				return false;
			if ( !$file = self::get_attached_file( $post ) )
				return false;

			$ext = preg_match('/\.([^.]+)$/', $file, $matches) ? strtolower($matches[1]) : false;

			$image_exts = array('jpg', 'jpeg', 'gif', 'png');

			if ( 'image/' == substr($post->post_mime_type, 0, 6) || $ext && 'import' == $post->post_mime_type && in_array($ext, $image_exts) )
				return true;
			return false;
		}


		// From includes/link-template.php
		function get_attachment_link( $object ) {
			global $post, $wp_rewrite;

			$link = false;

			if ( ! $object)
				$object = $post;

			if ( $wp_rewrite->using_permalinks() && ($object->post_parent > 0) && ($object->post_parent != $object->ID) ) {
				$parent = get_post($object->post_parent);
				if ( 'page' == $parent->post_type )
					$parentlink = _get_page_link( $object->post_parent ); // Ignores page_on_front
				else
					$parentlink = get_permalink( $object->post_parent );

				if ( is_numeric($object->post_name) || false !== strpos(get_option('permalink_structure'), '%category%') )
					$name = 'attachment/' . $object->post_name; // <permalink>/<int>/ is paged so we use the explicit attachment marker
				else
					$name = $object->post_name;

				if ( strpos($parentlink, '?') === false )
					$link = user_trailingslashit( trailingslashit($parentlink) . $name );
			}

			if ( ! $link )
				$link = home_url( "/?attachment_id=".$object->ID );

			return apply_filters('attachment_link', $link, $object->ID);
		}

	}

}