<?php
/**
 * Sql Compatible.
 *
 * Attach this behavior to be able to query mongo DBs without using mongo specific syntax.
 * If you don't need this behavior don't attach it and save a few cycles
 *
 * PHP version 5
 *
 * Copyright (c) 2010, Andy Dawson
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @filesource
 * @copyright     Copyright (c) 2010, Andy Dawson
 * @link          www.ad7six.com
 * @package       mongodb
 * @subpackage    mongodb.models.behaviors
 * @since         v 1.0 (24-May-2010)
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

/**
 * SqlCompatibleBehavior class
 *
 * @uses          ModelBehavior
 * @package       mongodb
 * @subpackage    mongodb.models.behaviors
 */
class SqlCompatibleBehavior extends ModelBehavior {

/**
 * name property
 *
 * @var string 'SqlCompatible'
 * @access public
 */
	public $name = 'SqlCompatible';

/**
 * Runtime settings
 *
 * Keyed on model alias
 *
 * @var array
 * @access public
 */
	public $settings = array();

/**
 * defaultSettings property
 *
 * @var array
 * @access protected
 */
	protected $_defaultSettings = array(
		'convertDates' => false,
		'operators' => array(
			'!=' => '$ne',
			'>' => '$gt',
			'>=' => '$gte',
			'<' => '$lt',
			'<=' => '$lte',
			'IN' => '$in',
			'NOT' => '$not',
			'NOT IN' => '$nin'
		)
	);

/**
 * setup method
 *
 * Allow overriding the operator map
 *
 * @param mixed $Model
 * @param array $config array()
 * @return void
 * @access public
 */
	public function setup(&$Model, $config = array()) {
		$this->settings[$Model->alias] = array_merge($this->_defaultSettings, $config);
	}

/**
 * If requested, convert dates from MongoDate objects to standard date strings
 *
 * @param mixed $Model
 * @param mixed $results
 * @param mixed $primary
 * @return void
 * @access public
 */
	public function afterFind(&$Model, $results, $primary) {
		if ($this->settings[$Model->alias]['convertDates']) {
			$this->convertDates($results);
		}
		foreach($results as &$result) {
			foreach($Model->mongoSchema as $field => $conf) {
				if(array_key_exists('default', $conf) && !array_key_exists($field, $result[$Model->alias])) {
					$result[$Model->alias][$field] = $conf['default'];
				}
			}
		}
		return $results;
	}

/**
 * beforeFind method
 *
 * If conditions are an array ensure they are mongified
 *
 * @param mixed $Model
 * @param mixed $query
 * @return void
 * @access public
 */
	public function beforeFind(&$Model, $query) {
		if (is_array($query['conditions']) && $this->_translateConditions($Model, $query['conditions'])) {
			return $query;
		}
		return true;
	}

/**
 * Convert MongoDate objects to strings for the purpose of view simplicity
 *
 * @param mixed $results
 * @return void
 * @access public
 */
	public function convertDates(&$results) {
		if (is_array($results)) {
			foreach($results as &$row) {
				$this->convertDates($row);
			}
		} elseif (is_a($results, 'MongoDate')) {
			$results = date('Y-M-d h:i:s', $results->sec);
		}
	}

/**
 * translateConditions method
 *
 * Loop on conditions and desqlify them
 *
 * @param mixed $Model
 * @param mixed $conditions
 * @return void
 * @access protected
 */
	protected function _translateConditions(&$Model, &$conditions) {
		$return = false;
		foreach(array_keys($conditions) as $key) {
			$value = $conditions[$key];
			
			if (preg_match('/ NOT IN$/i', $key)) {
				$conditions[preg_replace('/ NOT IN$/i', '', $key)]['$nin'] = $value;
				unset($conditions[$key]);
				$return = true;
				continue;
			}
			if (preg_match('/^OR$/i', $key)) {
				unset($conditions[$key]);
				foreach($value as $key => $part) {
					$part = array($key => $part);
					$this->_translateConditions($Model, $part);
					$conditions['$or'][] = $part;
				}
				$return = true;
				continue;
			}
			if (preg_match('/^NOT$/i', $key)) {
				// 'Special' case because it's awkward
				$childKey = key($value);
				$childValue = current($value);

				if (in_array(substr($childKey, -1), array('>', '<', '='))) {
					$parts = explode(' ', $childKey);
					$operator = array_pop($parts);
					if ($operator = $this->_translateOperator($Model, $operator)) {
						$childKey = implode(' ', $parts);
					}
				} else {
					$conditions[$childKey]['$nin'] = (array)$childValue;
					unset($conditions['NOT']);
					$return = true;
					continue;
				}

				$conditions[$childKey]['$not'][$operator] = $childValue;
				unset($conditions['NOT']);
				$return = true;
				continue;
			}
			if (preg_match('/ LIKE$/i', $key)) {
				// 'Special' case because it's awkward
				if ($value[0] === '%') {
					$value = substr($value, 1);
				} else {
					$value = '^' . $value;
				}
				if (substr($value, -1) === '%') {
					$value = substr($value, 0, -1);
				} else {
					$value .= '$';
				}
				$value = str_replace('%', '.*', $value);

				$conditions[substr($key, 0, -5)] = new MongoRegex("/$value/i");
				unset($conditions[$key]);
				$return = true;
				continue;
			}

			if (is_numeric($key) && is_array($value)) {
				if ($this->_translateConditions($Model, $value)) {
					$return = true;
					continue;
				}
			}
			
			foreach($this->settings[$Model->alias]['operators'] as $sqlOp => $mongoOp) {
				if(preg_match('/ '.$sqlOp.'$/i', $key)) {
					unset($conditions[$key]);
					$conditions[preg_replace('/ '.$sqlOp.'$/i', '', $key)][$mongoOp] = $value;
					$return = true;
					continue 2;
				}
			}
			
//			if (!in_array(substr($key, -1), array('>', '<', '='))) {
//				$return = true;
//				continue;
//			}
			
			
//			if (!$return && is_array($value)) {
//				if ($this->_translateConditions($Model, $value)) {
//					$return = true;
//					continue;
//				}
//			}
			
			if (/*$key === $Model->primaryKey && */is_array($value)) {
				//_id=>array(1,2,3) pattern, set  $in operator
				$isMongoOperator = false;
				foreach($value as $idKey => $idValue) {
					//check a mongo operator exists
					if(substr($idKey,0,1) === '$') {
						$isMongoOperator = true;
						continue;
					}
				}
				unset($idKey, $idValue);
				if($isMongoOperator === false) {
					$conditions[$key] = array('$in' => $value);
				}
				$return = true;
				continue;
			}
		}
		return $return;
	}

/**
 * translateOperator method
 *
 * Use the operator map for the model and return what the db really wants to hear
 *
 * @param mixed $Model
 * @param mixed $operator
 * @return string
 * @access protected
 */
	protected function _translateOperator($Model, $operator) {
		if (!empty($this->settings[$Model->alias]['operators'][$operator])) {
			return $this->settings[$Model->alias]['operators'][$operator];
		}
		return '';
	}
}
