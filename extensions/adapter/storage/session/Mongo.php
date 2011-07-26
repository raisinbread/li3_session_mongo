<?php

namespace li3_session_mongo\extensions\adapter\storage\session;

use lithium\core\ConfigException;

/**
 * A basic adapter to store session information (unserialized) in a Mongo instance.
 */
class Mongo extends \lithium\core\Object {
	
	/**
	 * Class dependencies.
	 * 
	 * @var array Keys are dependency names, values are classes.
	 */
	protected $_classes = array(
		'model' => '\li3_session_mongo\models\Session',
	);
	
	/**
	 * Default settings for this session adapter.
	 *
	 * @var array Keys are session ini settings, with the `session.` namespace.
	 */
	protected $_defaults = array(
		'connection'              => 'default',
		'session.use_trans_sid'   => 0,
		'session.save_handler'    => 'user',
		'session.use_cookies'     => 1,
		'session.name'            => null,
		'timeout'                 => 1200,
	);
	
	/**
	 * Class constructor.
	 *
	 * Takes care of setting appropriate configurations for this object.
	 *
	 * @param array $config Unified constructor configuration parameters. You can set
	 *        the `session.*` PHP ini settings here as key/value pairs.
	 */
	public function __construct(array $config = array()) {
		parent::__construct($config + $this->_defaults);
	}
	
	/**
	 * Initialization of the session.
	 *
	 * @return void
	 */
	protected function _init() {
		if (!isset($this->_config['session.name'])) {
			$this->_config['session.name'] = basename(LITHIUM_APP_PATH);
		}
		
		session_set_save_handler(
			array($this, "open"),
			array($this, "close"),
			array($this, "read"),
			array($this, "write"),
			array($this, "destroy"),
			array($this, "gc")
		);
		
		foreach ($this->_config as $key => $value) {
			if (strpos($key, 'session.') === false) {
				continue;
			}
			if (ini_set($key, $value) === false) {
				throw new ConfigException("Could not initialize the session.");
			}
		}
		
		session_start();
	}
	
	/**
	 * Returns the specific Mongo document related to the active session.
	 *
	 * @return /lithium/data/entity/Document Document for the active session.
	 */
	protected function _getEntity() {
		$model = $this->_classes['model'];
		$entity = $model::find('first', array(
			'conditions' => array(
				$model::key() => $this->key(),
			)
		));
		if(!$entity) {
			$entity = $model::create();
			$entity->{$model::key()} = $this->key();
			$entity->expires = time() + $this->_config['timeout'];
			$entity->save();
		}
		return $entity;
	}
	
	/**
	 * Placeholder function for session_set_save_handler.
	 *
	 * @return boolean Success result.
	 */
	public function open($path, $name) {
		return true;
	}
	
	/**
	 * Placeholder function for session_set_save_handler.
	 *
	 * @return boolean Success result.
	 */
	public function close() {
		return true;
	}
	
	/**
	 * Session destruction mechanism.
	 *
	 * @return void
	 */
	public function destroy() {
		$entity = $this->_getEntity();
		$entity->delete();
	}
	
	/**
	 * Session garbage collector.
	 *
	 * @return void
	 */
	public function gc($maxLifetime = null) {
		$model = $this->_classes['model'];
		$expires = $maxLifetime ?: time();
		$entity = $model::remove(array(
				'expires' => array('$lt' => $expires),
		));
	}
	
	/**
	 * Obtain the status of the session.
	 *
	 * @return boolean True if $_SESSION is accessible and if a '_timestamp' key
	 *         has been set, false otherwise.
	 */
	public function isStarted() {
		return (boolean) session_id();
	}
	
	/**
	 * Sets or obtains the session ID.
	 *
	 * @param string $key Optional. If specified, sets the session ID to the value of `$key`.
	 * @return mixed Session ID, or `null` if the session has not been started.
	 */
	public function key($key = null) {
		if ($key) {
			return session_id($key);
		}
		return session_id() ?: null;
	}
	
	/**
	 * Checks if a value has been set in the session.
	 *
	 * @param string $key Key of the entry to be checked.
	 * @param array $options Options array. Not used for this adapter method.
	 * @return closure Function returning boolean `true` if the key exists, `false` otherwise.
	 */
	public function check($key, array $options = array()) {
		$entity = $this->_getEntity();
		return function($self, $params) use ($entity) {
			return isset($entity->sessionData->$key);
		};
	}
	
	/**
	 * Read a value from the session.
	 *
	 * @param null|string $key Key of the entry to be read. If no key is passed, all
	 *        current session data is returned.
	 * @param array $options Options array. Not used for this adapter method.
	 * @return closure Function returning data in the session if successful, `false` otherwise.
	 * @todo Enable 'dot' notation access?
	 */
	public function read($key = null, array $options = array()) {
		$entity = $this->_getEntity();
		return function ($self, $params) use ($entity) {
			$key = $params['key'];
			if(!$key) {
				return $entity->sessionData->to('array');
			}
			
			if(!isset($entity->sessionData->$key)) {
				return null; 
			} else {
				return $entity->sessionData->$key;
			}
		};
	}
	
	/**
	 * Write a value to the session.
	 *
	 * @param string $key Key of the item to be stored.
	 * @param mixed $value The value to be stored.
	 * @param array $options Options array. Not used for this adapter method.
	 * @return closure Function returning boolean `true` on successful write, `false` otherwise.
	 */
	public function write($key, $value, array $options = array()) {
		$entity = $this->_getEntity();
		return function ($self, $params) use ($entity) {
			$data = $entity->sessionData;
			$data[$params['key']] = $params['value'];
			$entity->set(array('sessionData' => $data));
			return $entity->save();
		};
	}
	
	/**
	 * Delete value from the session
	 *
	 * @param string $key The key to be deleted
	 * @param array $options Options array. Not used for this adapter method.
	 * @return closure Function returning boolean `true` if the key no longer exists
	 *         in the session, `false` otherwise
	 */
	public function delete($key, array $options = array()) {
		$entity = $this->_getEntity();
		return function ($self, $params) use ($entity) {
			$data = $entity->sessionData;
			unset($data[$params['key']]);
			$entity->set(array('sessionData' => $data));
			return $entity->save();
		};
	}
	
	/**
	 * Clears all keys from the session.
	 *
	 * @param array $options Options array. Not used for this adapter method.
	 * @return closure Function returning boolean `true` on successful clear, `false` otherwise.
	 */
	public function clear(array $options = array()) {
		$entity = $this->_getEntity();
		return function($self, $params) {
			$entity->set(array('sessionData' => array()));
			return $entity->save();
		};
	}
}