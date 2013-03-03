<?php

namespace ClassExtractor;

use Nette;



/**
 * Envelope for case-insensitive string.
 *
 * @author  Miloslav Hůla
 */
class CaseInsensitiveString extends Nette\Object
{
	/** @var string  original string value */
	public $raw;

	/** @var string  lowercase variation */
	public $lower;



	public function __construct($str)
	{
		$this->raw = $str;
		$this->lower = strtolower($str);
	}



	public function __toString()
	{
		return (string) $this->lower;
	}

}
