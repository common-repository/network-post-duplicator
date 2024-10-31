<?php
/*
Plugin Name: Network Post Duplicator
Plugin URI: https://jvelgar.com/
Description: Duplica los posts a distintos sitios de la red
Version: 1.4.0
Stable tag: 1.4.0
Author: J.VelGar
Author URI: https://jvelgar.com/
License: GPL2
*/

function npd_duplicate_post_to_site($post_id, $duplicate_post_site_id = null, $site_id) {
	$post = get_post($post_id);

	$new_post = array(
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
		'post_status' => $post->post_status,
		'post_type' => $post->post_type,
		'post_author' => $post->post_author,
		'post_parent' => $post->post_parent,
		'post_excerpt' => $post->post_excerpt,
		'post_password' => $post->post_password,
		'post_name' => $post->post_name,
		'post_date' => $post->post_date,
		'post_date_gmt' => $post->post_date_gmt,
		'post_modified' => $post->post_modified,
		'post_modified_gmt' => $post->post_modified_gmt,
		'comment_status' => $post->comment_status,
		'ping_status' => $post->ping_status,
		'post_mime_type' => $post->post_mime_type,
		'to_ping' => $post->to_ping,
		'pinged' => $post->pinged,
		'menu_order' => $post->menu_order,
		'post_content_filtered' => $post->post_content_filtered,
	);

	if($duplicate_post_site_id){
		$new_post = array_merge($new_post, array('ID' => $duplicate_post_site_id));
		switch_to_blog($site_id);
		$new_post_id = wp_update_post($new_post);
		restore_current_blog();
	} else {
		switch_to_blog($site_id);
		$new_post_id = wp_insert_post($new_post);
		restore_current_blog();
	}


	if ($new_post_id > 0) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		npd_duplicate_post_to_site_categories($post_id, $new_post_id, $site_id, $taxonomies);
		npd_duplicate_post_to_site_terms($post_id, $new_post_id, $site_id);
		npd_duplicate_attachments_to_new_post($new_post_id, $post_id, $site_id);
	}

	return $new_post_id;
}

function npd_duplicate_attachments_to_new_post( $new_post_id, $original_post_id, $site_id ) {

	$attachment_id = get_post_thumbnail_id( $original_post_id );
	if ( ! $attachment_id ) {
		return;
	}

	$file_path       = get_attached_file( $attachment_id );
	$file_url        = wp_get_attachment_url( $attachment_id );
	$file_type_data  = wp_check_filetype( basename( $file_path ), null );
	$file_type       = $file_type_data['type'];
	$timeout_seconds = 5;

	switch_to_blog( $site_id );

	$sideload_result = sideload_media_file( $file_url, $file_type, $timeout_seconds );

	$new_file_path = $sideload_result['file'];
	$new_file_type = $sideload_result['type'];

	// Insert media file into uploads directory.
	$inserted_attachment_id = insert_media_file( $new_file_path, $new_file_type );

	set_post_thumbnail( $new_post_id, $inserted_attachment_id );
	update_post_meta( $new_post_id, '_thumbnail_id', $inserted_attachment_id );
	restore_current_blog();

}

function sideload_media_file( $file_url, $file_type, $timeout_seconds ) {

	require_once( ABSPATH . 'wp-admin/includes/file.php' );
	$temp_file = download_url( $file_url, $timeout_seconds );

	if ( is_wp_error( $temp_file ) ) {
		return false;
	}

	$file = array(
		'name'     => basename( $file_url ),
		'type'     => $file_type,
		'tmp_name' => $temp_file,
		'error'    => 0,
		'size'     => filesize( $temp_file ),
	);

	$overrides = array(
		'test_form'   => false,
		'test_size'   => true,
		'test_upload' => true,
	);

	return wp_handle_sideload( $file, $overrides );
}

function insert_media_file( $file_path = '', $file_type = '' ) {

	if ( ! $file_path || ! $file_type ) {
		return;
	}

	$wp_upload_dir = wp_upload_dir();

	$attachment_data = array(
		'guid'           => $wp_upload_dir['url'] . '/' . basename( $file_path ),
		'post_mime_type' => $file_type,
		'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$inserted_attachment_id   = wp_insert_attachment( $attachment_data, $file_path );
	$inserted_attachment_path = get_attached_file( $inserted_attachment_id );

	update_inserted_attachment_metadata( $inserted_attachment_id, $inserted_attachment_path );

	return $inserted_attachment_id;
}

function update_inserted_attachment_metadata( $inserted_attachment_id, $file_path ) {
	require_once( ABSPATH . 'wp-admin/includes/image.php' );
	$attach_data = wp_generate_attachment_metadata( $inserted_attachment_id, $file_path );
	wp_update_attachment_metadata( $inserted_attachment_id, $attach_data );
}


function npd_duplicate_post_to_site_categories($post_id, $new_post_id, $site_id, $taxonomies) {
	foreach ($taxonomies as $tax) {
		$post_categories = wp_get_post_terms($post_id, $tax);
		if (!empty($post_categories)) {
			$arra_categorias = array();
			foreach ($post_categories as $category_id) {
				$category = get_category($category_id);
				$new_category_id = 0;

				// Verify that the term is a category
				if ($category->taxonomy !== $tax) {
					continue;
				}

				// Exclude default category
				if ($category->term_id == 1) {
					continue;
				}

				switch_to_blog($site_id);

				// Check if category already exists in the site
				$term = get_term_by('name', $category->name, $tax);

				if ($term && !is_wp_error($term)) {
					// If the category already exists, get its ID
					$new_category_id = $term->term_id;

				} else {
					// If the category does not exist, create it
					$new_category = wp_insert_term(
						$category->name,
						$tax,
						array(
							'description' => $category->description,
							'parent' => $category->parent
						)
					);

					if (is_array($new_category) && isset($new_category['term_id'])) {
						$new_category_id = $new_category['term_id'];
					}
				}


				if ($new_category_id > 0) {
					$arra_categorias[] = $new_category_id;
				}

				restore_current_blog();
			}

			switch_to_blog($site_id);
			wp_set_post_terms($new_post_id, $arra_categorias, $tax, false);
			restore_current_blog();
		}
	}

}

function npd_duplicate_post_to_site_terms($post_id, $new_post_id, $site_id) {
	$post_tags = wp_get_post_terms($post_id, 'post_tag');

	if (!empty($post_tags)) {
		foreach ($post_tags as $tag_id) {
			$tag = get_tag($tag_id, 'post_tag');
			$new_tag_id = 0;

			switch_to_blog($site_id);

			// Check if tag already exists in the site
			$term = get_term_by('name', $tag->name, 'post_tag');

			if ($term && !is_wp_error($term)) {
				// If the tag already exists, get its ID
				$new_tag_id = $term->term_id;
			} else {
				// If the tag does not exist, create it
				$new_tag = wp_insert_term(
					$tag->name,
					'post_tag',
					array(
						'description' => $tag->description
					)
				);

				if (is_array($new_tag) && isset($new_tag['term_id'])) {
					$new_tag_id = $new_tag['term_id'];
				}
			}

			if ($new_tag_id > 0) {
				// Assign tag to the new post
				wp_set_post_terms($new_post_id, array($new_tag_id), 'post_tag', true);
			}

			restore_current_blog();
		}
	}
}

function npd_duplicate_post_add_meta_box() {
	if(get_option('network-post-duplicator')) {
		$post_types = get_option('network-post-duplicator')['post_types'];
		if (!empty($post_types)) {
			add_meta_box(
				'duplicate_post_meta_box',
				__( 'Network Post Duplicator', 'network-post-duplicator' ),
				'npd_duplicate_post_meta_box_callback',
				$post_types,
				'side'
			);
		}
	}
}
add_action('add_meta_boxes', 'npd_duplicate_post_add_meta_box');


function npd_duplicate_post_meta_box_callback($post) {
	$options = get_option('network-post-duplicator');
	$post_types = $options['post_types'];
	$screen = get_current_screen();
	if (in_array($screen->post_type, $post_types)) {
		$sites = get_sites(array('network' => true));
		$post_id = $post->ID;
		$duplicate_post_sites = get_post_meta($post_id, 'duplicate_post_sites', true);

		?>
        <div>
            <p><strong><?php echo __( 'Selecciona los sitios a los que deseas duplicar este post:', 'network-post-duplicator' )?></strong></p>
            <ul style="list-style: none; margin: 0; padding: 0;">
				<?php foreach ($sites as $site) : ?>
					<?php $site_id = $site->blog_id; ?>
					<?php $site_name = get_blog_details($site_id)->blogname; ?>
                    <li style="margin-bottom: 10px;">
                        <label>
                            <input type="checkbox" name="duplicate_post_sites[]" value="<?php echo esc_html($site_id); ?>" <?php checked(is_array($duplicate_post_sites) && in_array($site_id, (array) $duplicate_post_sites)); ?>>
							<?php echo esc_html($site_name); ?>
                        </label>
                    </li>

				<?php endforeach; ?>
            </ul>
        </div>
		<?php
		wp_nonce_field('duplicate_post', 'duplicate_post_nonce');
	}
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'npd_add_settings_link');

function npd_add_settings_link($links) {
	$settings_link = '<a href="options-general.php?page=network-post-duplicator">'. __( 'Ajustes', 'network-post-duplicator' ).'</a>';
	array_unshift($links, $settings_link);
	return $links;
}

// Actualizar los sitios duplicados existentes y duplicar en los nuevos
function npd_duplicate_post_save_post($post_id) {

	static $running = false;
	if ($running) {
		return;
	}
	$running = true;
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}
	if (!isset($_POST['duplicate_post_nonce']) || !wp_verify_nonce($_POST['duplicate_post_nonce'], 'duplicate_post')) {
		return;
	}
	$duplicate_post_sites = get_post_meta($post_id, 'duplicate_post_sites', true);

	if (isset($_POST['duplicate_post_sites']) && is_array($_POST['duplicate_post_sites'])) {
		$existing_sites = is_array($duplicate_post_sites) ? $duplicate_post_sites : array();

		$options = get_option('network-post-duplicator');
		$sincronizar_post = isset($options['sincronizar_post']) ? $options['sincronizar_post'] : false;

		$sites = array_map('sanitize_text_field', $_POST['duplicate_post_sites']);
		if($sincronizar_post){
			// UPDATE
			foreach ($existing_sites as $site_id) {
				$duplicate_post_site_id = get_post_meta($post_id, 'duplicate_post_site_id_' . $site_id, true);
				if ($duplicate_post_site_id) {
					npd_duplicate_post_to_site($post_id, $duplicate_post_site_id, $site_id);
				}
			}
			// CREATE

			$new_sites = array_diff($sites, $existing_sites);
			foreach ($new_sites as $site_id) {
				$new_post_id = npd_duplicate_post_to_site($post_id, null, $site_id);
				update_post_meta($post_id, 'duplicate_post_site_id_' . $site_id, $new_post_id);
			}
		} else {
			foreach ($sites as $site_id) {
				$new_post_id = npd_duplicate_post_to_site($post_id, null, $site_id);
				update_post_meta($post_id, 'duplicate_post_site_id_' . $site_id, $new_post_id);
			}
        }

		// Update the duplicate_post_sites meta
		update_post_meta($post_id, 'duplicate_post_sites', $sites);
	}

	$running = false;
}
add_action('save_post', 'npd_duplicate_post_save_post');





/////////////////////////////////////////////////////////////////
function npd_duplicate_post_options_page() {
	wp_enqueue_style( 'my-duplicate-post-admin-style' );
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	$options    = get_option( 'network-post-duplicator' );
	$sincronizar_post = isset( $options['sincronizar_post'] ) ? $options['sincronizar_post'] : false;
	?>
    <div class="wrap">
        <h1><?php echo __( 'Network Post Duplicator', 'network-post-duplicator' ); ?></h1>
        <div class="duplicate-post-options-container">
            <form method="post" action="options.php">
				<?php settings_fields( 'network-post-duplicator' ); ?>
				<?php do_settings_sections( 'network-post-duplicator' ); ?>
                <div class="duplicate-post-options-content">
                    <div class="duplicate-post-options-field">
                        <div class="post-types-checkboxes">
							<?php foreach ( $post_types as $post_type ) : ?>
								<?php if ( $post_type->name === 'page' || $post_type->name === 'attachment' ) { continue; } ?>
								<?php $checked = isset( $options['post_types'] ) && in_array( $post_type->name, $options['post_types'] ) ? 'checked' : ''; ?>
                                <label>
                                    <input type="checkbox" name="network-post-duplicator[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php echo $checked; ?>>
									<?php
									$post_type_label = $post_type->label;
									echo esc_html__( $post_type_label, 'network-post-duplicator' );
									?>
                                </label>
							<?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <h4><?php echo __( 'Opciones de sincronización de posts', 'network-post-duplicator' )?></h4>

                <label>
                    <input type="checkbox" name="network-post-duplicator[sincronizar_post]" value="1" <?php checked( $sincronizar_post, true ); ?>>
					<?php echo __( 'Activar sincronización', 'network-post-duplicator' )?>
                </label>
				<?php submit_button( __( 'Guardar Cambios', 'network-post-duplicator' ) ); ?>
            </form>
        </div>
    </div>
	<?php
}



function npd_duplicate_post_add_options_page() {
	add_options_page(
		'Network Post Duplicator',
		'Network Post Duplicator',
		'manage_options',
		'network-post-duplicator',
		'npd_duplicate_post_options_page'
	);
}
add_action('admin_menu', 'npd_duplicate_post_add_options_page');

function npd_duplicate_post_settings_init() {
	register_setting(
		'network-post-duplicator',
		'network-post-duplicator'
	);

	add_settings_section(
		'duplicate_post_general',
		__( 'Ajustes Generales', 'network-post-duplicator' ),
		'npd_duplicate_post_general_section_callback',
		'network-post-duplicator'
	);


}

function npd_duplicate_post_general_section_callback() {
	$text = '<h4>'.__( 'Esta página permite configurar opciones para el plugin "Network Post Duplicator" en WordPress, como seleccionar los tipos de publicaciones a duplicar.', 'network-post-duplicator' ).'</h4>';
	echo wp_kses_post($text);
}

function npd_duplicate_post_post_types_field_callback() {
	$post_types = get_post_types(array('public' => true), 'objects');
	$options = get_option('network-post-duplicator');
	?>
    <div class="post-types-checkboxes">
		<?php foreach ($post_types as $post_type) {
			// Excluir páginas y medios
			if ($post_type->name === 'page' || $post_type->name === 'attachment') {
				continue;
			}

			$checked = isset($options['post_types']) && in_array($post_type->name, $options['post_types']) ? 'checked' : ''; ?>
            <label>
                <input type="checkbox" name="network-post-duplicator[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php echo $checked; ?>>
				<?php echo esc_html($post_type->label); ?>
            </label>
		<?php } ?>
    </div>
	<?php
}

add_action('admin_init', 'npd_duplicate_post_settings_init');


function npd_load_plugin_textdomain() {
	$domain = 'network-post-duplicator';
	load_plugin_textdomain( $domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	//load_textdomain($domain, $mofile);
}
add_action('plugins_loaded', 'npd_load_plugin_textdomain');



function npd_duplicate_post_admin_styles() {
	wp_register_style( 'my-duplicate-post-admin-style', plugin_dir_url( __FILE__ ) . 'css/style.css' );
}
add_action( 'admin_enqueue_scripts', 'npd_duplicate_post_admin_styles' );
