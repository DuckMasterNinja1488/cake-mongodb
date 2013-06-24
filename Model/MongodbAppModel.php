<?php

App::uses('AppModel', 'Model');

/**
 * Application model for Cake.
 *
 * Add your application-wide methods in the class below, your models
 * will inherit them.
 *
 * @package       app.Model
 */
class MongodbAppModel extends AppModel {
	public $primaryKey = '_id';
	
	public $actsAs = array(
		'Mongodb.SqlCompatible',
		'Mongodb.Schemaless',
	);
	
	public function hasField($x) {
		if ($this->Behaviors->attached('Schemaless')) {
			return true;
		}
		return parent::hasField($x);
	}
	
/**
 * Returns the column type of a column in the model.
 *
 * @param string $column The name of the model column
 * @return string Column type
 */
	public function getColumnType($column) {
		$type = parent::getColumnType($column);
		if($this->Behaviors->attached('Schemaless') && empty($type)) {
			$type = 'string';
		}
		return $type;
	}
	
}
