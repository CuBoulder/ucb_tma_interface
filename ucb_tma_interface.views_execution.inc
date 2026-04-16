<?php
/*
	Views Specific hooks
*/

use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\query\QueryPluginBase;

function ucb_tma_interface_views_query_alter($view, $query) {
	if ($view->id() == 'sitewide_alerts'){
		$now = time();
		$configuration = [
			'type'       => 'LEFT',
			'table'      => 'node__field_expires_at',
			'field'      => 'entity_id',
			'left_table' => 'node_field_data',
			'left_field' => 'nid',
			'operator'   => '=',
		];
		$join = \Drupal\views\Views::pluginManager('join')
			->createInstance('standard', $configuration);
        $rel = $query->addRelationship('expire', $join, 'node_field_data');
        $query->addTable('node__field_expires_at', $rel, $join, 'expire');
		$query->addWhere('','expire.field_expires_at_value',$now,'>=');  
	}
}

