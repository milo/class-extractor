<?php

use Nette\Utils\Finder,
	ClassExtractor as CE;

$internalClasses = array_merge(get_declared_classes(), get_declared_interfaces());

require __DIR__ . '/libs/Nette/nette.min.php';
Nette\Diagnostics\Debugger::enable(FALSE);
Nette\Diagnostics\Debugger::$strictMode = TRUE;



foreach(glob(__DIR__ . '/libs/ClassExtractor/*.php') as $file) {
	require $file;
}



function usage() {
	echo "Tool for extracting PHP classes from libraries.\n";
	echo "(C) 2013 Miloslav Hůla [https://github.com/milo]\n";
	echo "Version: dev\n";
	echo "\n";
	echo basename(__FILE__) . " -s path -c class [-c class [...]]\n";
	echo "    Required:\n";
	echo "        -s path         libraries to explore (directory or file)\n";
	echo "        -c class        desired class to extract, possible to use\n";
	echo "                        multiple times\n";
	echo "\n";
	echo "    Optional:\n";
	echo "        -d directory    extract dependent files here\n";
	echo "        -t file         tree cache file for parsed data\n";
	echo "        -h              show this help and exit\n";
	echo "\n";
	echo "    Allow this types of dependency (all allowed if not specified):\n";
	echo "        --" . implode("\n        --", array_keys(CE\Dependencies::$types)) . "\n";
}



$options = getopt('s:c:d:t:h', array_keys(CE\Dependencies::$types));
if (array_key_exists('h', $options)) {
	usage();
	exit(2);
}


if (!array_key_exists('s', $options)) {
	echo "Missing -s parameter. Use -h for help.\n";
	exit(2);

} elseif (!file_exists((string) $options['s'])) {
	echo "Source path '$options[s]' does not exist.\n";
	exit(1);
}
$srcDir = realpath($options['s']);


if (!array_key_exists('c', $options)) {
	echo "Missing option -c. Use -h for help.\n";
	exit(2);
}
$classes = (array) $options['c'];


if (array_key_exists('d', $options)) {
	if (!is_dir($options['d'])) {
		echo "Directory '$options[d]' does not exist.\n";
		exit(1);
	}
	$dstDir = realpath($options['d']);
}


if (array_key_exists('t', $options)) {
	$tmpFile = $options['t'];
}


foreach ($options as $opt => $foo) {
	if (array_key_exists($opt, CE\Dependencies::$types)) {
		$dpTypes[$opt] = TRUE;
	}
}



echo "Source: $srcDir\n";
echo "Extract to: " . (isset($dstDir) ? $dstDir : '(only print)') . "\n";
echo "Types of dependency: " . (isset($dpTypes) ? implode(', ', array_keys($dpTypes)) : '(all)') . "\n";
echo "Desired classes to export:\n";
echo "    " . implode("\n    ", $classes) . "\n";
echo "\n";



if (is_file($srcDir)) {
	$findDir = realpath(dirname($srcDir));
	$findMask = basename($srcDir);

} else {
	$findDir = realpath($srcDir);
	$findMask = array('*.php', '*.phtml');
}



if (isset($dpTypes)) {
	CE\Dependencies::$types = array_merge(array_fill_keys(array_keys(CE\Dependencies::$types), FALSE), $dpTypes);
}
$dp = new CE\Dependencies(new CE\Reporter);

if (!isset($tmpFile) || !is_file($tmpFile) || !($restored = $dp->restore($tmpFile))) {
	echo "Building dependency tree...\n";

	$dp->addIgnore($internalClasses);

	/**
	 * Inspired by https://github.com/nette/build-tools/blob/master/tasks/convert52.php#L77
	 */
	foreach (Finder::findFiles($findMask)->from($findDir) as $path => $foo) {
		$context = new CE\PhpFileContext;
		$parser = new CE\PhpParser(file_get_contents($path));
		$dp->entryFile($path);

		$classBlockLevel = NULL;

		while (($token = $parser->fetch()) !== FALSE) {
			// {
			if ($parser->isCurrent('{', T_DOLLAR_OPEN_CURLY_BRACES)) {
				$context->blockLevel++;

			// } but not in "{$var }"
			} elseif ($parser->isCurrent('}') && !$parser->isCurrent(T_ENCAPSED_AND_WHITESPACE)) {
				$context->blockLevel--;
				if ($classBlockLevel === $context->blockLevel) {
					$classBlockLevel = NULL;
					$dp->leaveClass();
				}

			// namespace NAMESPACE;
			} elseif ($parser->isCurrent(T_NAMESPACE)) {
				$context->namespace = $parser->fetchAll(T_STRING, T_NS_SEPARATOR);

			// use NAMESPACE\CLASS [as NAMESPACE\ALIAS], but skip use closure keyword
			} elseif ($parser->isCurrent(T_USE) && !$parser->isNext('(')) {
				do {
					$class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR);
					$as = $parser->fetch(T_AS) ? $parser->fetch(T_STRING) : substr($class, strrpos("\\$class", '\\'));
					$context->setAlias($class, $as);
				} while ($parser->fetch(','));
				$parser->fetch(';');

			// class CLASS, interface CLASS
			} elseif ($parser->isCurrent(T_CLASS, T_INTERFACE)) {
				$class = $context->absolutize($parser->fetchAll(T_STRING, T_NS_SEPARATOR), FALSE);
				$classBlockLevel = $context->blockLevel;
				$dp->entryClass($class);

				// extends NAMESPACE\CLASS
				if ($parser->fetch(T_EXTENDS)) {
					do {
						$class = $context->absolutize($parser->fetchAll(T_STRING, T_NS_SEPARATOR));
						$dp->foundExtends($class);
					} while ($parser->fetch(','));
				}

				// implements NAMESPACE\CLASS
				if ($parser->fetch(T_IMPLEMENTS)) {
					do {
						$class = $context->absolutize($parser->fetchAll(T_STRING, T_NS_SEPARATOR));
						$dp->foundImplements($class);
					} while ($parser->fetch(','));
				}

			// instanceof NAMESPACE\CLASS, new NAMESPACE\CLASS
			} elseif ($parser->isCurrent(T_INSTANCEOF, T_NEW)) {
				$method = $parser->isCurrent(T_INSTANCEOF) ? 'foundInstanceOf' : 'foundNewOperator';
				if (($class = $parser->fetchAll(T_STRING, T_NS_SEPARATOR)) && $class !== 'self' && $class !== 'parent') {
					$dp->{$method}($context->absolutize($class));
				}

			// NAMESPACE\CLASS:: or function(NAMESPACE\CLASS $var)
			} elseif ($parser->isCurrent(T_STRING, T_NS_SEPARATOR)) { // Class:: or typehint
				$class = $token . $parser->fetchAll(T_STRING, T_NS_SEPARATOR);

				if ($class !== 'self' && $class !== 'parent') {
					if ($parser->isNext(T_DOUBLE_COLON)) {
						$dp->foundStaticCall($context->absolutize($class));

					} elseif ($parser->isNext(T_VARIABLE)) {
						$dp->foundTypehint($context->absolutize($class));
					}
				}
			}
		}

		/** @todo $parser->isCurrent(T_CONSTANT_ENCAPSED_STRING) = class name in string */
		/** @todo class_alias() */
		/** @todo traits */

		$dp->leaveFile();
	}
}

if (isset($tmpFile) && empty($restored)) {
	$dp->store($tmpFile);
}


$dpClasses = $dp->getFor($classes);
sort($dpClasses);
$dpFiles = $dp->mapToFileNames($dpClasses);
sort($dpFiles);

if (count($dpClasses)) {
	echo "\n";
	echo "Desired classes depend on " . count($dpClasses) . " classes in " . count($dpFiles) . " files.\n";
	echo "    " . implode("\n    ", $dpClasses) . "\n";
}


if (isset($dstDir) && count($dpFiles)) {
	function copyWithDir($srcFile, $dstFile) {
		$dstDir = dirname($dstFile);
		if (!is_dir($dstDir)) {
			mkdir($dstDir, 0777, TRUE);
		}

		copy($srcFile, $dstFile);
	}

	
	echo "\n";
	if (count(glob("$dstDir/*")) > 0) {
		echo "! Destination dir was not empty. Just for info. !\n";
	}
	echo "Copying " . count($dpFiles) . " files to destination '$dstDir'.\n";

	foreach ($dpFiles as $file) {
		if (strpos($file, dirname($findDir)) !== 0) {
			echo "    File '$file' is not in source directory.\n";
			continue;
		}

		copyWithDir($file, $dstDir . substr($file, strlen(dirname($findDir))));
	}
}
