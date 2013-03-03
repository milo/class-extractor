<?php

use Nette\Utils\Finder,
	ClassExtractor as CE;

$internalClasses = array_merge(get_declared_classes(), get_declared_interfaces());

require __DIR__ . '/libs/Nette/nette.min.php';
Nette\Diagnostics\Debugger::enable(FALSE, FALSE);
Nette\Diagnostics\Debugger::$strictMode = TRUE;



foreach(glob(__DIR__ . '/libs/ClassExtractor/*.php') as $file) {
	require $file;
}

$reporter = new CE\Reporter;

$dpTypes = array(
	CE\Dependencies::TYPE_EXTENDS,
	CE\Dependencies::TYPE_IMPLEMENTS,
	CE\Dependencies::TYPE_INSTANCE_OF,
	CE\Dependencies::TYPE_NEW_OPERATOR,
	CE\Dependencies::TYPE_STATIC_CALL,
	CE\Dependencies::TYPE_TYPEHINT,
);



$usage = function() use ($dpTypes) {
	ob_start();
	echo "Tool for extracting PHP classes from libraries.\n";
	echo "(C) 2013 Miloslav Hula [https://github.com/milo]\n";
	echo "Version: dev\n";
	echo "\n";
	echo basename(__FILE__) . " -s path -c class [...]\n";
	echo "    Required:\n";
	echo "        -s {file|dir}   libraries to explore,\n";
	echo "                        possible to use multiple times\n";
	echo "        -c class        desired class to extract,\n";
	echo "                        possible to use multiple times\n";
	echo "\n";
	echo "    Optional:\n";
	echo "        -d dir          extract files with dependencies here\n";
	echo "        -t {file|dir}   parsed dependency tree cache file\n";
	echo "                        or directory for auto-named cache files\n";
	echo "        -r file         HTML report file\n";
	echo "        -q              be quite when parsing and result printing\n";
	echo "        -h              show this help and exit\n";
	echo "\n";
	echo "    Allow this types of dependency (all allowed if not specified):\n";
	echo "        --" . implode("\n        --", $dpTypes) . "\n";
	return ob_get_clean();
};



/* Command line options processing */
$options = getopt('s:c:d:t:r:qh', $dpTypes);
if (array_key_exists('h', $options)) {
	$reporter->stdout($usage());
	exit(2);
}


$srcDirs = array();
if (!array_key_exists('s', $options)) {
	$reporter->stderr("Missing -s parameter. Use -h for help.\n");
	exit(1);
}
foreach ((array) $options['s'] as $opt) {
	if (($src = realpath($opt)) === FALSE) {
		$reporter->warning("Option -s '$opt' is not valid file/directory.");
	} else {
		$srcDirs[$src] = $src;
	}
}
if (!count($srcDirs)) {
	$reporter->stderr("Missing valid -s parameter.\n");
	exit(1);
}


if (!array_key_exists('c', $options)) {
	$reporter->stderr("Missing option -c. Use -h for help.\n");
	exit(1);
}
$extractClasses = (array) $options['c'];


$dstDir = FALSE;
if (array_key_exists('d', $options)) {
	if (!is_dir($options['d'])) {
		echo "Directory '$options[d]' does not exist.\n";
		exit(1);
	}
	$dstDir = realpath($options['d']);
}


$treeFile = FALSE;
if (array_key_exists('t', $options)) {
	$treeFile = $options['t'];
	if (is_dir($treeFile)) {
		$treeFile = rtrim($treeFile, '/\\') . DIRECTORY_SEPARATOR . 'ce-' . substr(sha1(serialize($srcDirs)), 0, 7) . '.tmp';
	}
}


$reportFile = array_key_exists('r', $options) ? $options['r'] : FALSE;
$verbose = !array_key_exists('q', $options);


foreach ($dpTypes as $type) {
	if (array_key_exists($type, $options)) {
		$tmp[] = $type;
	}
}
$dpTypes = empty($tmp) ? $dpTypes : $tmp;



/* Dependency tree building */
ob_start();
echo "Source directories:\n";
echo "    " . implode("\n    ", $srcDirs) . "\n\n";

echo "Desired classes to extract:\n";
echo "    " . implode("\n    ", $extractClasses) . "\n\n";

echo "Extract to:\n";
echo "    " . (empty($dstDir) ? '(only print)' : $dstDir) . "\n\n";
$reporter->stdout(ob_get_clean());


$dp = new CE\Dependencies($reporter);
if ($treeFile === FALSE || !is_file($treeFile) || !($restored = $dp->restore($treeFile))) {
	$reporter->stdout("Building dependency tree...\n");
	foreach ($srcDirs as $srcDir) {
		if (is_file($srcDir)) {
			$findDir = dirname($srcDir);
			$findMask = basename($srcDir);

		} else {
			$findDir = $srcDir;
			$findMask = array('*.php', '*.phtml');
		}

		foreach (Finder::findFiles($findMask)->from($findDir) as $path => $foo) {
			$verbose && $reporter->lineMessage($path);
			$dp->addFile($path);
		}
	}
	$verbose && $reporter->cleanLine();
	$reporter->stdout("...done\n");
}

if ($treeFile !== FALSE && empty($restored)) {
	$dp->store($treeFile);
}



/* Dependent classes extracting */
$dp->setIgnoredClasses($internalClasses);
$dependencies = $dp->queryClass($extractClasses, $dpTypes);
ksort($dependencies);
foreach ($dependencies as $dependency) {
	ksort($dependency->where);
}



if ($verbose) {
	$reporter->stdout("Desired classes dependencies:\n");
	$reporter->stdout("=============================\n");

	foreach ($dependencies as $dependency) {
		$reporter->stdout("$dependency->name\n");
	}
	$reporter->stdout("\n");
}



if ($reportFile) {
	$template = new Nette\Templating\FileTemplate(__DIR__ . '/resources/report.latte');
	$template->registerFilter(new Nette\Latte\Engine);
	$template->registerHelperLoader(array('Nette\Templating\Helpers', 'loader'));

	$template->classes = $extractClasses;
	$template->dpTypes = $dpTypes;
	$template->dependencies = $dependencies;
	$template->created = new DateTime;

	if (@file_put_contents($reportFile, (string) $template) === FALSE) {
		$err = error_get_last();
		$reporter->error("Error occured when writing HTML report '$reportFile': $err[message]");
	} else {
		$reporter->notice("HTML report '$reportFile' saved.");
	}
}



if (!empty($dstDir)) {
	$fnCopy = function($srcFile, $dstFile) {
		$dstDir = dirname($dstFile);
		if (!is_dir($dstDir)) {
			mkdir($dstDir, 0777, TRUE);
		}
		copy($srcFile, $dstFile);
	};

	$fnStripSrc = function($file) use ($srcDirs) {
		foreach ($srcDirs as $srcDir) {
			if (is_file($srcDir)) {
				$srcDir = dirname($srcDir);
			}

			if (strpos($file, $dir = dirname($srcDir)) === 0) {
				return substr($file, strlen($dir));
			}
		}
		return FALSE;
	};


	if (count(glob($dstDir . DIRECTORY_SEPARATOR . '*')) > 0) {
		$reporter->notice("Destination dir is not empty!");
	}

	$dpFiles = $dp->mapToFileNames(array_keys($dependencies));

	$reporter->stdout("Copying " . count($dpFiles) . " files to '$dstDir'.\n");
	foreach ($dpFiles as $file) {
		if (($dstPath = $fnStripSrc($file)) === FALSE) {
			$reporter->error("File '$file' is not in source directories. Skipping.");
			continue;
		}

		$fnCopy($file, $dstDir . $dstPath);
	}
}
