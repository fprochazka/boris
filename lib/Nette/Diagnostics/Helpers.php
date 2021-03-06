<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Diagnostics
 */



/**
 * Rendering helpers for Debugger.
 *
 * @author     David Grudl
 * @package Nette\Diagnostics
 */
final class NDebugHelpers
{

	/**
	 * Returns link to editor.
	 * @return NHtml
	 */
	public static function editorLink($file, $line)
	{
		if (NDebugger::$editor && is_file($file)) {
			$dir = dirname(strtr($file, '/', DIRECTORY_SEPARATOR));
			$base = isset($_SERVER['SCRIPT_FILENAME']) ? dirname(dirname(strtr($_SERVER['SCRIPT_FILENAME'], '/', DIRECTORY_SEPARATOR))) : dirname($dir);
			if (substr($dir, 0, strlen($base)) === $base) {
				$dir = '...' . substr($dir, strlen($base));
			}
			return NHtml::el('a')
				->href(strtr(NDebugger::$editor, array('%file' => rawurlencode($file), '%line' => $line)))
				->title("$file:$line")
				->setHtml(htmlSpecialChars(rtrim($dir, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . '<b>' . htmlSpecialChars(basename($file)) . '</b>' . ($line ? ":$line" : ''));
		} else {
			return NHtml::el('span')->setText($file . ($line ? ":$line" : ''));
		}
	}



	public static function findTrace(array $trace, $method, & $index = NULL)
	{
		$m = explode('::', $method);
		foreach ($trace as $i => $item) {
			if (isset($item['function']) && $item['function'] === end($m)
				&& isset($item['class']) === isset($m[1])
				&& (!isset($item['class']) || $item['class'] === $m[0] || $m[0] === '*' || is_subclass_of($item['class'], $m[0]))
			) {
				$index = $i;
				return $item;
			}
		}
	}



	/** @deprecated */
	public static function htmlDump($var)
	{
		trigger_error(__METHOD__ . '() is deprecated; use NDebugDumper::toHtml() instead.', E_USER_WARNING);
		return NDebugDumper::toHtml($var);
	}

	public static function clickableDump($var)
	{
		trigger_error(__METHOD__ . '() is deprecated; use NDebugDumper::toHtml() instead.', E_USER_WARNING);
		return NDebugDumper::toHtml($var);
	}

	/** @deprecated */
	public static function textDump($var)
	{
		trigger_error(__METHOD__ . '() is deprecated; use NDebugDumper::toText() instead.', E_USER_WARNING);
		return NDebugDumper::toText($var);
	}

}
