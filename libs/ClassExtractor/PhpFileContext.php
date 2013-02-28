<?php

namespace ClassExtractor;

use Nette;



/**
 * @author  Miloslav Hůla
*/
class PhpFileContext extends Nette\Object
{
	/** @var string  current namespace definition */
	public $namespace = '';

	/** @var string[]  namespace aliases */
	public $aliases = array();

	/** @var current  block level in file */
	public $blockLevel = 0;



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
	 * Expands class name to full path with current namespace.
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
