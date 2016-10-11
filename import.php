<?php
	// Error reporting
	error_reporting(E_ALL);
	ini_set('display_errors', 1);

	// MODx config
	$modx_db_user = 'root';
	$modx_db_pass = '';
	$modx_db_name = 'modx_database';
	$modx_db_host = 'localhost';
	$modx_db_table = 'modx_site_content';
	$modx_db_table_tmplvar = 'modx_site_tmplvars';
	$modx_db_table_tmplvar_values = 'modx_site_tmplvar_contentvalues';

	// WordPress config
	$wp_db_user = 'root';
	$wp_db_pass = '';
	$wp_db_name = 'wordpress_database';
	$wp_db_host = 'localhost';
	$wp_db_table = 'wp_posts';
	$wp_import_type = 'page';

	/**
	 * Проверяем наличие страницы в БД WordPress
	 * 11.10.2016
	 *
	 * @param $modx_content_id
	 * @return bool
	 */
	function checkThereIsPage($modx_content_id){
		$query = new WP_Query(array(
			'post_type' => 'page',
			'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array(
				array(
					'key'     => 'modx_content_id',
					'value'   => $modx_content_id,
				),
			),
		));

		if (!empty($query->posts[0])) return true;
		return false;
	}

	// Require wp-load and initialize wpdb class
	require_once('wp-load.php');
	$modx = new wpdb($modx_db_user, $modx_db_pass, $modx_db_name, $modx_db_host);
	$wp = new wpdb($wp_db_user, $wp_db_pass, $wp_db_name, $wp_db_host);

	// Сформировать справочник переменных для произвольных полей
	$tmplvars = array();
	$modx_tmplvars = $modx->get_results("SELECT * FROM `$modx_db_table_tmplvar`");
	if(is_array($modx_tmplvars) && count($modx_tmplvars)) {
		foreach($modx_tmplvars as $tv) {
			$tmplvars[$tv->id] = array(
				'name' => $tv->name,
				'caption' => $tv->caption
			);
		}
	}

	// Get MODx content
	$modx_content = $modx->get_results("SELECT * FROM `$modx_db_table`");

	// Parse results
	if(is_array($modx_content) && count($modx_content)) {
		// Setup some arrays to map IDs and parent IDs
		$ids_modx_eq_wp = array();
		$parents = array();

		foreach($modx_content as $c) {
			// Проверяем наличие добавляемой страницы в WordPress
			if (checkThereIsPage($c->id)) continue;

			// Setup our post array for insertion into WP posts table
			$post = array(
				'comment_status' => 'closed',
				'ping_status'    => 'open',
				'post_author'    => 1,
				'post_content'   => $c->content,
				'post_date'      => date('Y-m-d H:i:s', strftime($c->createdon)),
				'post_date_gmt'  => date('Y-m-d H:i:s', strftime($c->createdon)),
				'post_name'      => $c->alias,
				'post_status'    => 'publish',
				'post_title'     => $c->pagetitle,
				'post_type'      => $wp_import_type,
				'menu_order'      => $c->menuindex
			);
			// Insert our post
			$post_id = wp_insert_post($post);

			if (empty($post_id)) continue;

			// Сохраняем ID ModX для избежания дублей при повторном импорте
			update_post_meta($post_id, 'modx_content_id', $c->id);

			// Получаем переменные шаблона (TV)
			$modx_content_tv_value = $modx->get_results(
				"SELECT * FROM `$modx_db_table_tmplvar_values` WHERE contentid = " . $c->id
			);

			if(is_array($modx_content_tv_value) && count($modx_content_tv_value))
				foreach($modx_content_tv_value as $c_tv) {
					if (empty($tmplvars[$c_tv->tmplvarid]['name'])) continue;
					update_post_meta($post_id, $tmplvars[$c_tv->tmplvarid]['name'], $c_tv->value);
				}

			// Push data onto our ids array
			$ids_modx_eq_wp[$c->id] = $post_id;
			$parents[$post_id] = $c->parent;
		}
	}

	// Loop through all our inserted posts and setup our parent/child relationships
	if (!empty($parents)){
		$wp_content = $wp->get_results(
			"SELECT * FROM `$wp_db_table` WHERE `ID` IN (" . implode(',', array_keys($parents)) . ")"
		);

		if(is_array($wp_content) && count($wp_content)) {
			foreach($wp_content as $post) {
				if (empty($parents[$post->ID])) continue;
				$modx_parent_id = $parents[$post->ID];
				if (empty($ids_modx_eq_wp[$modx_parent_id])) continue;
				$wp_parent_id = $ids_modx_eq_wp[$modx_parent_id];
				$wp->query(
					"UPDATE `$wp_db_table` SET `post_parent` = " . $wp_parent_id . " WHERE `ID` = " . $post->ID
				);
			}
		}
	}

	echo 'Импорт завершен';


