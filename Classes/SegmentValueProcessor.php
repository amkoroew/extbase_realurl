<?php
class Tx_ExtbaseRealurl_SegmentValueProcessor {

	const CONVERT_MODEL = 'Model';
	const CONVERT_DATETIME = 'DateTime';
	const CONVERT_NULL = 'Nullify';
	const CONVERT_PASSTHROUGH = 'Passthrough';

	const ENCODE_STRING_STANDARD = 'StandardStringPart';

	/**
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * CONSTRUCTOR
	 */
	public function __construct() {
		$this->objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
	}

	/**
	 * Translates one segment value (if this class is set as UserFunc).
	 *
	 * @param $params
	 * @param tx_realurl $reference
	 * @return mixed
	 * @throws Tx_ExtbaseRealurl_RoutingException
	 */
	public function translateSegmentValue(&$params, tx_realurl &$reference) {
		$value = $params['value'];
		$parameters = $params['setup']['parameters'];
		$direction = isset($params['origValue']) ? 'decode' : 'encode';
		$redirection = isset($parameters['redirect']) ? $parameters['redirect'] : NULL;
		$method = isset($parameters['conversionMethod']) ? $parameters['conversionMethod'] : 'Passthrough';
		$conversionMethodName = $direction . $method;
		if (method_exists($this, $conversionMethodName) === FALSE) {
			throw new Tx_ExtbaseRealurl_RoutingException('Invalid conversion method: ' . $parameters['conversionMethod']);
		}
		$translatedValue = call_user_func_array(array($this, $conversionMethodName), array($value, $parameters));
		if ($redirection !== NULL) {
			if ($redirection['method'] === 'NoMatch' && $translatedValue === NULL) {
					// redirection on NoMatch applies, perform redirection
				/** @var $uriBuilder Tx_Extbase_MVC_Web_Routing_UriBuilder */
				$prefix = $redirection['prefix'];
				$uriSegments = array();
				foreach (array('controller', 'action', 'pluginName', 'extensionName') as $parameter) {
					if (isset($redirection[$parameter]) === TRUE && $redirection[$parameter] !== NULL) {
						if ($prefix) {
							$uriSegment = $prefix . '[' . $parameter . ']=' . urlencode($redirection[$parameter]);
						} else {
							$uriSegment = $parameter . '=' . urlencode($redirection[$parameter]);
						}
						array_push($uriSegments, $uriSegment);
					}
				}
				$queryString = '?' . implode('&', $uriSegments);
				$uri = $queryString;
				$this->performRedirect($uri, $redirection['status']);
			}
		}
		return $translatedValue;
	}

	/**
	 * @param string $url
	 * @param integer $status
	 * @return void
	 */
	protected function performRedirect($url, $status = NULL) {
		if ($status) {
			header('HTTP/1.1 ' . $status);
		}
		header('Location: ' . $url);
		exit();
	}

	/**
	 * @param string $string
	 * @param array $parameters
	 * @return string
	 */
	protected function encodeStandardStringPart($string, $parameters) {
		$realurl = new tx_realurl_advanced();
		$converted = $realurl->encodeTitle($string);
		if (TRUE === empty($prospect)) {
			$stringConvertPattern = (isset($parameters['stringConvertPattern']) ? $parameters['stringConvertPattern'] : '/([^a-z0-9\-]{1,})+/i');
			$stringConvertReplacement = isset($parameters['stringConvertReplacement']) ? $parameters['stringConvertReplacement'] : '-';
			$string = preg_replace($stringConvertPattern, $stringConvertReplacement, $string);
			$string = strtolower($string);
			$converted = trim($string, '-');
		}
		return $converted;
	}

	/**
	 * @param integer $uid
	 * @param array $parameters
	 * @return mixed
	 * @throws Tx_ExtbaseRealurl_RoutingException
	 */
	protected function encodeModel($uid, $parameters) {
		$uid = intval($uid);
		if ($uid < 1) {
			return NULL;
		}
		$tableName = $parameters['tableName'];
		$className = $parameters['className'];
		$labelField = $parameters['labelField'];
		$cachedAlias = $this->getAliasFromTableNameAndLabelFieldCache($tableName, $labelField, $uid);
		if ($cachedAlias !== NULL) {
			return $cachedAlias;
		}
		$repositoryClassName = str_replace('_Domain_Model_', '_Domain_Repository_', $className) . 'Repository';
		if (class_exists($repositoryClassName) === FALSE) {
			throw new Tx_ExtbaseRealurl_RoutingException('Class ' . $className . ' does not appear to have a Repository; this is required. '
				. 'The tried Repository class name was ' . $repositoryClassName, 1351541118);
		}
		/** @var $repository Tx_Extbase_Persistence_Repository */
		$repository = $this->objectManager->get($repositoryClassName);
		$query = $repository->createQuery();
		$query->getQuerySettings()->setRespectStoragePage(FALSE);
		$query->matching($query->equals('uid', $uid));
		$object = $query->execute()->getFirst();
		if (!$object && $parameters['noMatch'] !== 'bypass') {
			throw new Tx_ExtbaseRealurl_RoutingException('Unable to fetch ' . $className . ':' . $uid . ' from ' . $repositoryClassName, 1351541402);
		} elseif (!$object) {
			return NULL;
		}
		$propertyName = t3lib_div::underscoredToLowerCamelCase($labelField);
		$propertyValue = Tx_Extbase_Reflection_ObjectAccess::getProperty($object, $propertyName);
		$stringConversionMethod = isset($parameters['stringEncodeMethod']) ? $parameters['stringEncodeMethod'] : self::ENCODE_STRING_STANDARD;
		$stringConversionMethodName = 'encode' . $stringConversionMethod;
		if (method_exists($this, $stringConversionMethodName) === FALSE) {
			throw new Tx_ExtbaseRealurl_RoutingException('String conversion method "' . $stringConversionMethod . '" is invalid', 1351541779);
		}
		$convertedPropertyValue = call_user_func_array(array($this, $stringConversionMethodName), array($propertyValue, $parameters));
		$this->setModelUidAliasCache($uid, $convertedPropertyValue, $tableName, $labelField);
		return $convertedPropertyValue;
	}

	/**
	 * @param mixed $identity
	 * @param array $parameters
	 * @return mixed
	 * @throws Tx_ExtbaseRealurl_RoutingException
	 */
	protected function decodeModel($identity, $parameters) {
		if (ctype_digit($identity) && $identity < 1) {
			return NULL;
		}
		$tableName = $parameters['tableName'];
		$labelField = $parameters['labelField'];
		if (ctype_digit($identity)) {
			return $identity;
		}
		$decodedValue = $this->getModelUidFromAliasCache($identity, $tableName, $labelField);
		if (!$parameters['optional'] && $decodedValue === NULL) {
			throw new Tx_ExtbaseRealurl_RoutingException('Unable to translate a non-optional argument. The identity that was attempted converted was "'
				. $identity . '" and the settings which were insufficient to load an object were: ' . var_export($parameters, TRUE), 1351629172);
		}
		return $decodedValue;
	}

	/**
	 * @param mixed $subject
	 * @param array $parameters
	 * @return mixed
	 */
	protected function encodePassthrough($subject, $parameters) {
		return (string) $subject;
	}

	/**
	 * @param mixed $subject
	 * @param array $parameters
	 * @return mixed
	 */
	protected function decodePassthrough($subject, $parameters) {
		return (string) $subject;
	}

	/**
	 * @param mixed $subject
	 * @param array $parameters
	 * @return mixed
	 */
	protected function encodeNullify($subject, $parameters) {
		return NULL;
	}

	/**
	 * @param mixed $subject
	 * @param array $parameters
	 * @return mixed
	 */
	protected function decodeNullify($subject, $parameters) {
		return NULL;
	}

	/**
	 * @param string $tableName
	 * @param string $labelField
	 * @param integer $uid
	 * @return string
	 */
	protected function getAliasFromTableNameAndLabelFieldCache($tableName, $labelField, $uid) {
		$clause = "tablename = '" . $tableName . "' AND field_alias = '" . $labelField . "' AND value_id = '" . $uid . "'";
		$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('value_alias', 'tx_realurl_uniqalias', $clause);
		if (is_array($rows) && isset($rows[0])) {
			return $rows[0]['value_alias'];
		}
		return NULL;
	}

	/**
	 * @param string $alias
	 * @param string $tableName
	 * @param string $fieldName
	 * @return integer
	 */
	protected function getModelUidFromAliasCache($alias, $tableName, $fieldName) {
		$clause = "tablename = '" . $tableName . "' AND field_alias = '" . $fieldName . "' AND value_alias = '" . $alias . "'";
		$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('value_id', 'tx_realurl_uniqalias', $clause);
		if (is_array($rows) && isset($rows[0])) {
			return $rows[0]['value_id'];
		}
		return NULL;
	}

	/**
	 * @param integer $uid
	 * @param string $alias
	 * @param string $tableName
	 * @param string $fieldName
	 * @return void
	 */
	protected function setModelUidAliasCache($uid, $alias, $tableName, $fieldName) {
		$alreadyCached = $this->getModelUidFromAliasCache($alias, $tableName, $fieldName);
		if ($alreadyCached === NULL) {
			$GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_realurl_uniqalias', array(
				'tstamp' => time(),
				'tablename' => $tableName,
				'field_alias' => $fieldName,
				'value_alias' => $alias,
				'value_id' => $uid
			));
		} else {
			$clause = "tablename = '" . $tableName . "' AND field_alias = '" . $fieldName . "'";
			$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('uid, value_alias', 'tx_realurl_uniqalias', $clause);
			$row = array_shift($rows);
			$row['value_alias'] = $alias;
			$row['tstamp'] = time();
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_realurl_uniqalias', $clause, $row);
		}
	}

}
