<?php

namespace ClassExtractor;

use Nette;



/**
 * Just more comfortable stdout printing.
 *
 * @author  Miloslav Hůla
 */
class Reporter extends Nette\Object
{
	const
		NOTICE = 'notice',
		WARNING = 'warning',
		ERROR = 'error';



	public function notice($msg)
	{
		return $this->report(self::NOTICE, $msg);
	}



	public function warning($msg)
	{
		return $this->report(self::WARNING, $msg);
	}



	public function error($msg)
	{
		return $this->report(self::ERROR, $msg);
	}



	protected function report($level, $msg)
	{
		$msg = "[$level] $msg";

		if (PHP_SAPI === 'cli') {
			echo $msg . PHP_EOL;
		} else {
			echo "<pre>$msg</pre>\n";
		}

		return $this;
	}

}
