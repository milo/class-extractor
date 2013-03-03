<?php

namespace ClassExtractor;

use Nette;



/**
 * @author  Miloslav Hůla
 */
class NamespaceContext extends Nette\Object
{
	/** @var string  current namespace name */
	protected $namespace = '';

	/** @var string[]  namespace class aliases */
	protected $aliases = array();



	public function __toString()
	{
		return $this->namespace;
	}



	/**
	 * Enters to namespace.
	 * @param  string
	 * @return self
	 */
	public function enter($namespace)
	{
		$this->namespace = (string) $namespace;
	}



	/**
	 * Leaves namespace.
	 * @return string  old namespace
	 */
	 public function leave()
	 {
	 	$old = $this->namespace;
	 	$this->namespace = '';
	 	return $old;
	 }



	/**
	 * Sets namespace alias.
	 * @param  string
	 * @param  string
	 * @return self
	 */
	public function setAlias($namespace, $alias)
	{
		$this->aliases[strtolower($alias)] = $namespace;
		return $this;
	}



	/**
	 * Expands class name to full name with namespace.
	 * @param  string
	 * @param  bool  search in aliases
	 * @return string  expanded identifier
	 */
	public function absolutize($class, $findAlias = TRUE)
	{
		if (substr($class, 0, 1) === '\\') {
			$return = $class;

		} elseif ($findAlias) {
			$parts = explode('\\', $class, 2) + array('', '');
			$alias = strtolower($parts[0]);
			if (isset($this->aliases[$alias])) {
				$return = $this->aliases[$alias] . '\\' . $parts[1];
			}
		}

		if (!isset($return)) {
			$return = $this->namespace . '\\' . $class;
		}

		return trim($return, '\\');
	}

}
