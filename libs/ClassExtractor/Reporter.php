<?php

namespace ClassExtractor;

use Nette;



/**
 * Just more comfortable stdout and stderr printing.
 *
 * @author  Miloslav Hůla
 */
class Reporter extends Nette\Object
{
	const
		NOTICE = 'notice',
		WARNING = 'warning',
		ERROR = 'error';

	private $lastLineLength = 0;



	public function stdout($msg)
	{
		echo $msg;
		return $this;
	}



	public function stderr($msg)
	{
		file_put_contents('php://stderr', $msg, FILE_APPEND);
		return $this;
	}



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
		$this->cleanLine();
		return $this->stdout("[$level] $msg" . PHP_EOL);
	}



	public function cleanLine()
	{
		if ($this->lastLineLength !== 0) {
			$this->stdout("\r" . str_repeat(' ', $this->lastLineLength) . "\r");
			$this->lastLineLength = 0;
		}
		return $this;
	}



	public function lineMessage($msg)
	{
		$this->cleanLine();
		$this->lastLineLength = strlen($msg = rtrim($msg));
		return $this->stdout($msg);
	}

}
