<?php

namespace ClassExtractor;

use Nette,
	ClassExtractor\CaseInsensitiveString AS CIString;



class Dependencies extends Nette\Object
{
	const
		VERSION = 'alfa6';

	/** Type of dependency. */
	const
		TYPE_EXTENDS = 'extends',
		TYPE_IMPLEMENTS = 'implements',
		TYPE_INSTANCE_OF = 'instanceOf',
		TYPE_NEW_OPERATOR = 'newOperator',
		TYPE_STATIC_CALL = 'staticCall',
		TYPE_TYPEHINT = 'typehint';

	/**
	 * Dependencies tree array keys.
	 * @internal
	 */
	const
		CLASSES = 'classes',
		METHODS = 'methods',
		FUNCTIONS = 'functions',
		NAME = 'name',
		DEPENDENCIES = 'dependencies';

	/** @var Reporter */
	private $reporter;

	/**
	 * @var array  structure for dependencies
	 * <code>
	 * $files => array(
	 *     fullFilePath => array(
	 *         self::DEPENDENCIES => array(...),
	 *         self::CLASSES => array(
	 *             lowerClassName => array(
	 *                 self::NAME => 'originalClassName',
	 *                 self::DEPENDENCIES => array(...),
	 *                 self::METHODS => array(
	 *                     lowerMethodName => array(
	 *                         self::NAME => 'originalMethodName',
	 *                         self::DEPENDENCIES => array(...),
	 *                     ),
	 *                 ),
	 *             ),
	 *         ),
	 *         self::FUNCTIONS => array(
	 *             lowerFunctionName => array(
	 *                 self::NAME => 'originalFunctionName',
	 *                 self::DEPENDENCIES => array(...),
	 *             ),
	 *         ),
	 *     ),
	 * );
	 * </code>
	 * where every self::DEPENDENCIES array is:
	 * <code>
	 * array(
	 *     self::TYPE_EXTENDS => array(lowerClassName => originalClassName),
	 *     self::TYPE_... => ...
	 * )
	 * </code>
	 */
	private $files = array();

	/** @var array[lowerClassName => fullFilePath] index */
	private $classes = array();

	/** @var array[lowerFunctionName => fullFilePath] index */
	private $functions = array();

	/** @var array  dependencies from ignored code */
	private $trash;



	/** @var string|NULL  just now scanned file path */
	private $inFile;

	/** @var CIString  just now scanned class name */
	private $inClass;

	/** @var CIString  just now scanned function/method name */
	private $inFunction;

	/** @var reference to any of $this->files self::DEPENDENCIES array */
	private $current;

	/** @var array of $this->current references */
	private $stack;



	/** @var array[lowerClassName => TRUE]  class ignored during dependency queries */
	private $ignoredClasses = array();



	public function __construct(Reporter $reporter)
	{
		$this->reporter = $reporter;
		$this->current = & $this->trash;
	}



	/**
	 * Sets classes which will be ignored during dependency queries.
	 * @param  string[]
	 */
	public function setIgnoredClasses(array $classNames)
	{
		$this->ignoredClasses = array();
		foreach ($classNames as $name) {
			$this->ignoredClasses[strtolower($name)] = TRUE;
		}
	}



	/**
	 * Adds all dependencies from file.
	 * @param  string  path to file
	 * @return bool
	 */
	public function addFile($path)
	{
		if ($this->enterFile($path) === FALSE) {
			return FALSE;
		}

		$namespace = new NamespaceContext;
		$parser = new PhpParser(file_get_contents($path));

		$blockLevel = 0;
		$classBlockLevel = $functionBlockLevel = NULL;
		$nextBlockIsFunction = FALSE;

		/** Inspired by https://github.com/nette/build-tools/blob/master/tasks/convert52.php#L77 */
		while (($token = $parser->fetch()) !== FALSE) {
			// {
			if ($parser->isCurrent('{', T_DOLLAR_OPEN_CURLY_BRACES)) {
				if ($nextBlockIsFunction) {
					$nextBlockIsFunction = FALSE;
					$functionBlockLevel = $blockLevel;
				}

				$blockLevel++;

			// } but not in "{$var }"
			} elseif ($parser->isCurrent('}') && !$parser->isCurrent(T_ENCAPSED_AND_WHITESPACE)) {
				$blockLevel--;

				if ($classBlockLevel === $blockLevel) {
					if ($nextBlockIsFunction) { // abstract method without body
						$nextBlockIsFunction = FALSE;
						$this->leaveFunction();
					}

					$classBlockLevel = NULL;
					$this->leaveClass();

				} elseif ($functionBlockLevel === $blockLevel) {
					$functionBlockLevel = NULL;
					$this->leaveFunction();
				}

			// namespace NAMESPACE;
			} elseif ($parser->isCurrent(T_NAMESPACE)) {
				$namespace->enter($parser->fetchAll(T_STRING, T_NS_SEPARATOR));

			// use NAMESPACE\CLASS [as NAMESPACE\ALIAS], but skip use closure keyword
			} elseif ($parser->isCurrent(T_USE) && !$parser->isNext('(')) {
				do {
					$class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR);
					$alias = $parser->fetch(T_AS) ? $parser->fetch(T_STRING) : substr($class, strrpos("\\$class", '\\'));
					$namespace->setAlias($class, $alias);
				} while ($parser->fetch(','));
				$parser->fetch(';');

			// class CLASS, interface CLASS
			} elseif ($parser->isCurrent(T_CLASS, T_INTERFACE)) {
				$class = $namespace->absolutize($parser->fetchAll(T_STRING, T_NS_SEPARATOR), FALSE);
				$classBlockLevel = $blockLevel;
				$this->enterClass($class);

				// extends NAMESPACE\CLASS
				if ($parser->fetch(T_EXTENDS)) {
					do {
						$class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR);
						$this->found(self::TYPE_EXTENDS, $namespace->absolutize($class));
					} while ($parser->fetch(','));
				}

				// implements NAMESPACE\CLASS
				if ($parser->fetch(T_IMPLEMENTS)) {
					do {
						$class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR);
						$this->found(self::TYPE_IMPLEMENTS, $namespace->absolutize($class));
					} while ($parser->fetch(','));
				}

			// function FUNCTION, but skip closure
			} elseif ($parser->isCurrent(T_FUNCTION) && !$parser->isNext('(')) {
				if ($nextBlockIsFunction) { // abstract method without body
					$this->leaveFunction();
				}

				$parser->fetch('&');
				$function = $parser->fetch(T_STRING);
				$nextBlockIsFunction = TRUE;

				if ($this->inClass === NULL) {
					$function = $namespace->absolutize($function);
				}
				$this->enterFunction($function);

			// instanceof NAMESPACE\CLASS, new NAMESPACE\CLASS
			} elseif ($parser->isCurrent(T_INSTANCEOF, T_NEW)) {
				$type = $parser->isCurrent(T_INSTANCEOF) ? self::TYPE_INSTANCE_OF : self::TYPE_NEW_OPERATOR;
				if (($class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR)) && $class !== 'self' && $class !== 'parent') {
					$this->found($type, $namespace->absolutize($class));
				}

			// NAMESPACE\CLASS:: or function(NAMESPACE\CLASS $var)
			} elseif ($parser->isCurrent(T_STRING, T_NS_SEPARATOR)) { // Class:: or typehint
				$class = $token . $parser->fetchAll(T_STRING, T_NS_SEPARATOR);

				if ($class !== 'self' && $class !== 'parent') {
					if ($parser->isNext(T_DOUBLE_COLON)) {
						$this->found(self::TYPE_STATIC_CALL, $namespace->absolutize($class));

					} elseif ($parser->isNext(T_VARIABLE)) {
						$this->found(self::TYPE_TYPEHINT, $namespace->absolutize($class));
					}
				}
			}
		}

		/** @todo $parser->isCurrent(T_CONSTANT_ENCAPSED_STRING) = class name in string */
		/** @todo class_alias() */
		/** @todo traits - not so easy with all traits aliasing */
		/** @todo namespace {} = namespace enclosed in block */
		/** @todo line counter? */

		$this->leaveFile();

		return TRUE;
	}



	/**
	 * Enters into file.
	 * @param  string
	 * @return bool  FALSE when already entered
	 */
	private function enterFile($path)
	{
		if (isset($this->files[$path])) {
			return FALSE;
		}

		$this->inFile = $path;

		if ($this->inClass !== NULL) {
			$this->reporter->warning("Entering into file '$path' but still in class '{$this->inClass->raw}' definition.");
		}

		if ($this->inFunction !== NULL) {
			$this->reporter->warning("Entering into file '$path' but still in function '{$this->inFunction->raw}' definition.");
		}

		$this->stack[] = & $this->current;
		$this->current = & $this->files[$path][self::DEPENDENCIES];

		return TRUE;
	}



	/**
	 * Leaves file.
	 */
	private function leaveFile()
	{
		$this->inFile = NULL;
		$this->popStack();
	}



	/**
	 * Enters into class definition.
	 * @param  string  class name
	 */
	private function enterClass($name)
	{
		$this->inClass = $class = new CIString($name);

		$this->stack[] = & $this->current;

		if ($this->inFile === NULL) {
			$this->reporter->warning("Entering into class '$name' but not in file. Ignoring the class.");
			$this->current = & $this->trash;

		} elseif (isset($this->classes[$class->lower])) {
			$this->reporter->warning("Class '$name' is already defined in '{$this->classes[$class->lower]}'. Ignoring the definition from '$this->inFile'.");
			$this->current = & $this->trash;

		} else {
			$this->classes[$class->lower] = $this->inFile;

			$ref = & $this->files[$this->inFile][self::CLASSES][$class->lower];
			$ref[self::NAME] = $class->raw;
			$this->current = & $ref[self::DEPENDENCIES];
		}
	}



	/**
	 * Leaves class definition.
	 */
	private function leaveClass()
	{
		$this->inClass = NULL;
		$this->popStack();
		$this->flushTrash();
	}



	/**
	 * Enters into function/method definition.
	 * @param  string  function/method name
	 */
	private function enterFunction($name)
	{
		$this->inFunction = $function = new CIString($name);

		$this->stack[] = & $this->current;

		if ($this->inClass === NULL) { // global function
			if ($this->inFile === NULL) {
				$this->reporter->warning("Entering into function '$name()' but not in file. Ignoring the function.");
				$this->current = & $this->trash;

			} elseif (isset($this->functions[$function->lower])) {
				$this->reporter->warning("Function '$name()' is already defined in '{$this->functions[$function->lower]}'. Ignoring the definition from '$this->inFile'.");
				$this->current = & $this->trash;

			} else {
				$this->functions[$function->lower] = $this->inFile;

				$ref = & $this->files[$this->inFile][self::FUNCTIONS][$function->lower];
				$ref[self::NAME] = $function->raw;
				$this->current = & $ref[self::DEPENDENCIES];
			}

		} else { // class method
			if ($this->inFile === NULL) {
				$this->reporter->warning("Entering into method '{$this->inClass->raw}::$name()' but not in file. Ignoring the method.");
				$this->current = & $this->trash;

			} else {
				$ref = & $this->files[$this->inFile][self::CLASSES][$this->inClass->lower][self::METHODS][$function->lower];
				$ref[self::NAME] = $function->raw;
				$this->current = & $ref[self::DEPENDENCIES];
			}
		}
	}



	/**
	 * Leaves current class definition.
	 */
	private function leaveFunction()
	{
		$this->inFunction = NULL;
		$this->popStack();
		$this->flushTrash();
	}



	/**
	 * Pops last dependencies array reference from stack.
	 */
	private function popStack()
	{
		if (count($this->stack) < 1) {
			$this->reporter->warning(__METHOD__ . '(): Stack is empty');
			$this->current = & $this->trash;

		} else {
			$idx = count($this->stack) - 1;
			$this->current = & $this->stack[$idx];
			array_pop($this->stack);
		}
	}



	/**
	 * Cleans dependencies from ignored code.
	 */
	private function flushTrash()
	{
		$this->trash = NULL;
	}



	/**
	 * Adds new dependency to the list.
	 * @param  string  type of dependency (self::TYPE_*)
	 * @param  string  class name
	 */
	private function found($type, $className)
	{
		$class = new CIString($className);
		$this->current[$type][$class->lower] = $class->raw;
	}



	/**
	 * Store found dependencies into files.
	 * @param  string  path
	 * @return bool
	 */
	public function store($file)
	{
		$data = array(
			self::VERSION,
			$this->files,
			$this->classes,
			$this->functions,
		);

		$code = "<?php\n\n\$data = " . var_export($data, TRUE) . ';';

		if (@file_put_contents($file, $code) === FALSE) {
			$err = error_get_last();
			$this->reporter->warning("Cannot store dependencies to '$file': $err[message]");
			return FALSE;
		}

		$this->reporter->notice("Dependency tree stored to '$file'.");
		return TRUE;
	}



	/**
	 * Restore dependencies from file.
	 * @return bool
	 */
	public function restore($file)
	{
		if (!is_file($file)) {
			$this->reporter->notice("Cannot restore dependency tree from '$file'. File not exists.");
			return FALSE;

		} elseif (!@include $file) {
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
			$version,
			$this->files,
			$this->classes,
			$this->functions
		) = $data;

		$this->reporter->notice("Dependency tree restored from '$file'.");
		return TRUE;
	}



	/**
	 * Query for class dependencies.
	 * @param  string|array  class name(s), possible with '*' wildcard
	 * @param  array of self::TYPE_?  types of dependency
	 * @return array|FALSE
	 */
	public function queryClass($names, array $types)
	{
		$types = array_fill_keys($types, TRUE);

		$result = array();
		foreach ($this->expandClassNames((array) $names) as $class) {
			$this->collectClass($class, $types, $result);
		}

		return $result;
	}



	/**
	 * Expand wildcards in class names and filter outs not defined one.
	 * @param  array  class names
	 * @return CIString[]
	 */
	private function expandClassNames(array $names)
	{
		$result = array();
		foreach ($names as $name) {
			$class = new CIString($name);
			if (isset($this->classes[$class->lower])) {
				$result[$class->lower] = $class;

			} elseif (strpos($class->lower, '*') === FALSE) {
				$this->reporter->notice("Definition of '$name' class not found.");

			} else {
				$re = '(^' . strtr(preg_quote($class->lower), array('\\*' => '.*')) . '$)';
				$found = FALSE;
				foreach ($this->classes as $lowerClass => $file) {
					if (preg_match($re, $lowerClass)) {
						$found = TRUE;
						$result[$lowerClass] = new CIString($this->files[$file][self::CLASSES][$lowerClass][self::NAME]);
					}
				}

				if (!$found) {
					$this->reporter->notice("Definition '$name' does not match to any class.");
				}
			}
		}

		return $result;
	}



	private function collectClass(CIString $class, array $types, array & $result)
	{
		if (isset($this->ignoredClasses[$class->lower])) {
			return;
		}

		if (!isset($this->classes[$class->lower])) {
			$this->reporter->error("Definition of '$class->raw' class not found.");
			return;
		}

		if (!isset($result[$class->lower])) {
			$result[$class->lower] = (object) array(
				'name' => $class->raw,
				'where' => array(),
			);
		}

		$file = $this->classes[$class->lower];
		if (isset($this->files[$file][self::CLASSES][$class->lower][self::DEPENDENCIES])) {
			foreach ($this->files[$file][self::CLASSES][$class->lower][self::DEPENDENCIES] as $type => $dependencies) {
				if (!isset($types[$type])) {
					continue;
				}

				foreach ($dependencies as $lowerClass => $camelClass) {
					if (isset($this->ignoredClasses[$lowerClass])) {
						continue;
					}

					$key = "$lowerClass:$type";
					if (!isset($result[$class->lower]->where[$key])) {
						$result[$class->lower]->where[$key] = (object) array(
							'type' => $type,
							'class' => $camelClass,
						);

						$this->collectClass(new CIString($camelClass), $types, $result);
					}
				}
			}
		}

		if (isset($this->files[$file][self::CLASSES][$class->lower][self::METHODS])) {
			foreach ($this->files[$file][self::CLASSES][$class->lower][self::METHODS] as $lowerMethod => $method) {
				if (isset($method[self::DEPENDENCIES])) {
					foreach ($method[self::DEPENDENCIES] as $type => $dependencies) {
						if (!isset($types[$type])) {
							continue;
						}

						foreach ($dependencies as $lowerClass => $camelClass) {
							if (isset($this->ignoredClasses[$lowerClass])) {
								continue;
							}

							$key = "$lowerClass:$type:$lowerMethod";
							if (!isset($result[$class->lower]->where[$key])) {
								$result[$class->lower]->where[$key] = (object) array(
									'type' => $type,
									'class' => $camelClass,
									'method' => $method[self::NAME],
								);

								$this->collectClass(new CIString($camelClass), $types, $result);
							}
						}
					}
				}
			}
		}
	}



	/**
	 * Maps class name(s) to file name(s).
	 * @param  string|array  class name(s)
	 * @return array  file names
	 */
	public function mapToFileNames($classNames)
	{
		$files = array();
		foreach ((array) $classNames as $name) {
			if (isset($this->classes[$name])) {
				$file = $this->classes[$name];
				$files[$file] = $file;
			}
		}

		return array_values($files);
	}

}
