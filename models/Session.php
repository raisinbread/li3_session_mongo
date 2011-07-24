<?php

namespace li3_session_mongo\models;

use lithium\data\entity\Document;

/**
 * Basic model for session data.
 */
class Session extends \lithium\data\Model {
	/**
	 * Model schema definition.
	 *
	 * @var array Field by field schema description.
	 */
	protected $_schema = array(
		'_id'         => array('type' => 'id'),
		'sessionData' => array('type' => 'object'),
		'expires'     => array('type' => 'integer'),
	);
}