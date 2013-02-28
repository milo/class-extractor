<?php

namespace ClassExtractor;

use Nette\Utils\Tokenizer;



/**
 * Simple but powerful PHP code tokenizer. Stolen from Nette buil-tools.
 * 
 * @see https://github.com/nette/build-tools/blob/master/tasks/convert52.php#L201
 */
class PhpParser extends Tokenizer
{
	public function __construct($code)
	{
		$this->ignored = array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE);
		foreach (token_get_all($code) as $token) {
			$this->tokens[] = is_array($token) ? self::createToken($token[1], $token[0]) : $token;
		}
	}

}
