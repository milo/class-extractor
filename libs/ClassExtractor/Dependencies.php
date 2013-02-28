<?php

namespace ClassExtractor;

use Nette,
	Nette\Utils\Json;



/**
 * Class for building dependecies tree between PHP classes (and interfaces).
 *
 * @author  Miloslav Hůla
 *
 * @method  mixed foundExtends($class)
 * @method  mixed foundImplements($class)
 * @method  mixed foundInstanceOf($class)
 * @method  mixed foundNewOperator($class)
 * @method  mixed foundStaticCall($class)
 * @method  mixed foundTypehint($class)
 */
class Dependencies extends Nette\Object
{
	const VERSION = 'alfa4';

	private $reporter;

	/** @var  mixed  reference to $this->files or $this->stack */
	private $current;

	/** @internal */
	private $global;

	/** @var string  current PHP file path */
	private $currentFile;

	/** @var array[path]  dependecies incoming from file context */
	private $files = array();

	/** @var array[className]  dependecies incoming from class context */
	private $classes = array();

	/** @var array of $this->current references */
	private $stack = array();

	/** @var array[low className => className]  ignored classes */
	private $ignore = array();

	/** @var bool[type]  which dependecy types count when building dependecy tree */
	public static $types = array(
		'extends' => TRUE,
		'implements' => TRUE,
		'instanceOf' => TRUE,
		'newOperator' => TRUE,
		'staticCall' => TRUE,
		'typehint' => TRUE,
	);



	public function __construct(Reporter $reporter)
	{
		$this->reporter = $reporter;
		$this->current = & $this->global;
	}



	/**
	 * Enters into new file context.
	 * @param  string
	 * @return self
	 */
	public function entryFile($path)
	{
		$this->stack[] = & $this->current;
		$this->current = & $this->files[$path];
		$this->currentFile = $path;
		return $this;
	}



	/**
	 * Leaves current file context.
	 * @return self
	 */
	public function leaveFile()
	{
		$this->currentFile = NULL;
		return $this->leave();
	}



	/**
	 * Enters into class definition context.
	 * @param  string  absolute class name (with full namespace)
	 * @return self
	 */
	public function entryClass($class)
	{
		$this->stack[] = & $this->current;
		$this->current = & $this->classes[strtolower($class)];

		if (isset($this->current['definedIn'])) {
			$this->reporter->warning("Class '$class' is defined multiple times in files '{$this->currentFile}' and '{$this->current['definedIn']}'. Skipping the second one.");
		} else {
			$this->current['definedIn'] = $this->currentFile;
		}

		return $this;
	}



	/**
	 * Leaves class definition context.
	 * @return self
	 */
	public function leaveClass()
	{
		return $this->leave();
	}



	private function leave()
	{
		if (count($this->stack) < 1) {
			$this->reporter->warning(__METHOD__ . '(): Stack is empty');
			$this->current = & $this->global;

		} else {
			$idx = count($this->stack) - 1;
			$this->current = & $this->stack[$idx];
			array_pop($this->stack);
		}

		return $this;
	}



	/**
	 * Found some dependecy type.
	 * @see self::$types
	 * @param  string  foundExtends, foundStaticCall, ...
	 * @param  array  class name
	 * @return mixed
	 */
	public function __call($name, $args)
	{
		if (preg_match('#^found([A-Z][a-zA-Z]*)$#', $name, $m) && array_key_exists($key = lcfirst($m[1]), self::$types)) {
			foreach ($args as $arg) {
				$this->found($key, $arg);
			}
			return $this;
		}

		return parent::__call($name, $args);
	}



	private function found($type, $class)
	{
		$this->current[$type][strtolower($class)] = $class;
		return $this;
	}



	/**
	 * Adds classes which will be ignored when building dependecy tree.
	 * Main purpose is for PHP internal classes and interfaces.
	 * @param  string|array
	 * @return self
	 */
	public function addIgnore($classes)
	{
		foreach ((array) $classes as $class) {
			$this->ignore[strtolower($class)] = $class;
		}
		return $this;
	}




	/**
	 * Returns all dependent classes/interfaces for $classNames.
	 * @param  string|array
	 * @return string[]  class/interfaces names.
	 */
	public function getFor($classNames)
	{
		$result = array();
		$this->find(is_array($classNames) ? $classNames : func_get_args(), $result);
		return array_values($result);
	}



	private function find(array $classes, array &$result)
	{
		foreach ($classes as $camelName) {
			$class = strtolower($camelName);

			if (isset($result[$class]) || isset($this->ignore[$class])) { // cyclic
				continue;
			}

			if (!isset($this->classes[$class])) {
				$this->reporter->error("Definition of '$camelName' not found.");
				continue;
			}

			$result[$class] = $camelName;

			foreach (self::$types as $type => $enabled) {
				if (isset($this->classes[$class][$type]) && $enabled) {
					$this->find($this->classes[$class][$type], $result);
				}
			}
		}

		return $this;
	}



	/**
	 * Map class names to definition files name.
	 * @param  string|array
	 * @return string[]  file names
	 */
	public function mapToFileNames($classNames)
	{
		$classes = is_array($classNames) ? $classNames : func_get_args();
		$result = array();
		foreach ($classes as $camelName) {
			$class = strtolower($camelName);

			if (!isset($this->classes[$class])) {
				$this->reporter->error("Cannot find '$camelName' definition.");
				continue;
			}

			if (!isset($this->classes[$class]['definedIn'])) {
				$this->reporter->error("Definition file for '$camelName' not found.");
				continue;
			}

			$result[$this->classes[$class]['definedIn']] = 1;
		}

		return array_keys($result);
	}



	/** @internal */
	public function buildTree($classNames)
	{
		$classes = is_array($classNames) ? $classNames : func_get_args();

		$antiLoop = $result = array();
		$this->build($classes, $result, $antiLoop);

		return $result;
	}



	private function build(array $classes, array &$result, array &$antiLoop)
	{
		foreach ($classes as $camelName) {
			$class = strtolower($camelName);

			if (isset($result[$class])) {
				continue;
			}

			if (isset($this->ignore[$class])) {
				//$result[$class] = '*IGNORED*';
				continue;
			}

			if (isset($antiLoop[$class])) {
				$result[$class] = array(
					'class' => $camelName,
					'children' => '*RECURSION*',
				);
				continue;
			}

			$result[$class] = array(
				'class' => $camelName,
				'children' => array(),
			);
			$antiLoop[$class] = 1;
			foreach (self::$types as $type => $enabled) {
				if (isset($this->classes[$class][$type]) && $enabled) {
					$this->build($this->classes[$class][$type], $result[$class]['children'], $antiLoop);
				}
			}
			array_pop($antiLoop);
		}

		return $this;
	}



	/** @internal */
	public static function printTree($tree, $space = "|   ", $level = 0)
	{
		foreach ($tree as $leaf) {
			echo str_repeat($space, $level);
			echo $leaf['class'];
			if (is_string($leaf['children'])) {
				echo " => $leaf[children]\n";

			} elseif ($cnt = count($leaf['children'])) {
				echo " ($cnt)\n";
				self::printTree($leaf['children'], $space, $level + 1);

			} else {
				echo "\n";
			}
		}
	}



	/**
	 * Stores current dependecy tree into file.
	 * @param  string  path
	 * @return bool
	 */
	public function store($file)
	{
		$data = array(
			self::VERSION,
			$this->current,
			$this->global,
			$this->currentFile,
			$this->files,
			$this->classes,
			$this->stack,
			$this->ignore,
		);

		$code = "<?php\n\n\$data = " . var_export($data, TRUE) . ';';

		if (@file_put_contents($file, $code) === FALSE) {
			$err = error_get_last();
			$this->reporter->warning("Cannot store dependency tree to '$file': $err[message]");
			return FALSE;
		}

		$this->reporter->notice("Dependency tree stored to '$file'.");
		return TRUE;
	}



	/**
	 * Restores dependecy tree from file.
	 * @return bool
	 */
	public function restore($file)
	{
		if (!@include $file) {
			$err = error_get_last();
			$this->reporter->warning("Cannot restore dependency tree from '$file': $err[message]");
			return FALSE;

		} elseif (!isset($data) || !is_array($data)) {
			$this->reporter->warning("Cannot restore dependency tree from '$file'. Invalid stored data.");
			return FALSE;

		} elseif (!isset($data[0]) || $data[0] !== self::VERSION) {
			$this->reporter->warning("Cannot restore dependency tree from '$file'. Old data version.");
			return FALSE;
		}

		list(
			$foo,
			$this->current,
			$this->global,
			$this->currentFile,
			$this->files,
			$this->classes,
			$this->stack,
			$this->ignore) = $data;

		$this->reporter->notice("Dependency tree restored from '$file'.");
		return TRUE;
	}

}
