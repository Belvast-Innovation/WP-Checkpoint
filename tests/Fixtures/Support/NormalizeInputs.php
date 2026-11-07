<?php

namespace WPCheckpoint\Tests\Fixtures\Support;

/**
 * Paths for Paths::as_wordpress() and wp_normalize_path() to be given alike (unit test: the bytes core gave for each
 * when written; integration test: core, here and now).
 */
final class NormalizeInputs {

	/**
	 * Input => what wp_normalize_path() of WordPress 7.1.4 gave for it.
	 */
	const CASES = array(
		''                                     => '',
		'/'                                    => '/',
		'//'                                   => '//',
		'///'                                  => '//',
		'/var/www/html'                        => '/var/www/html',
		'/var/www/html/'                       => '/var/www/html/',
		'/var//www///html//'                   => '/var/www/html/',
		'\\var\\www\\html\\'                   => '/var/www/html/',
		'C:\\Users\\site\\wp-content'          => 'C:/Users/site/wp-content',
		'c:\\Users\\site\\wp-content\\'        => 'C:/Users/site/wp-content/',
		'c:/inetpub//wwwroot'                  => 'C:/inetpub/wwwroot',
		'C:'                                   => 'C:',
		'c:'                                   => 'C:',
		'\\\\server\\share\\dir'               => '//server/share/dir',
		'\\\\\\server\\share'                  => '//server/share',
		'/var/www/../www/html/./wp-content'    => '/var/www/../www/html/./wp-content',
		'..\\..\\up'                           => '../../up',
		'php://temp'                           => 'php://temp',
		'php://memory//x\\y'                   => 'php://memory/x/y',
		'phar:///srv/a.phar//inner\\file'      => 'phar:///srv/a.phar/inner/file',
		'phar://c:\\x\\a.phar\\f'              => 'phar://C:/x/a.phar/f',
		'file:///var//www'                     => 'file:///var/www',
		'notawrapper://host//path'             => 'notawrapper:/host/path',
		'PHP://temp'                           => 'PHP:/temp',
		'a\\b//c/'                             => 'a/b/c/',
		'/wp-content/uploads/ünïcödé//ファイル' => '/wp-content/uploads/ünïcödé/ファイル',
	);
}
