<?php
/**
 * The call graph of a run made without WordPress, read from the source.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures;

/**
 * Reads the plugin's classes by their tokens and follows, method by method and across classes, what a run made
 * without WordPress can call from its roots:
 *
 * - $this->m(), self::m(), static::m(), parent::m(), X::m(), new X (its constructor), through a class's traits and
 *   parents;
 * - $receiver->m() where the receiver's type can be read: a parameter's or a property's type (declared, or its
 *   docblock's), a local variable assigned from new X, X::m(), $this->m(), $this->property or another such call
 *   (its return type), a caught exception, or a chain of such; an interface type goes to the implementations
 *   registered for it (BINDINGS), never to all of them;
 * - a call whose target cannot be read (call_user_func, $f(), ->$name, new $class) goes to the methods registered
 *   for it (DYNAMIC: the methods that define the closures it calls), and a receiver whose type cannot be read to the
 *   classes registered for it (RECEIVERS); either unregistered is a finding.
 *
 * It does not follow into a method whose first statement is a guard: $this->cli_only( … ) (SwapStep), or
 * $this->needs( … ) naming a method the storage of such a run cannot do (Runner, WebJobs::can()). Closures are read
 * with the method that defines them. In every method it reaches it reports a global function that is not PHP's own
 * (unless ALLOWED), $wpdb, and a wide catch that may take a NotHere; every class it names is loaded, so its file may
 * hold nothing outside the class but the namespace, its imports and "defined( 'ABSPATH' ) || exit;".
 */
final class WebPathScanner {

	/**
	 * Class => source in place of its file (the controls), and classes that exist only there.
	 *
	 * @var array<string, string>
	 */
	private $sources;

	/**
	 * The registries: roots, bindings, receivers, dynamic targets, allowed, the guard methods and the storage's own.
	 *
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * Classes read so far.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $classes = array();

	/**
	 * The class a method is being read as (the one its target names: what $this and static:: are there).
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $config  roots (Class::method[]), bindings (interface => classes), receivers (key "file:method:receiver" => classes), dynamic (key "file:method" => Class::method[]), allowed (key "file:method:what" => reason), cuts (key "file:method:Class::method" => reason: an edge not taken), can (methods the storage does).
	 * @param array<string, string> $sources Class => source.
	 */
	public function __construct( array $config, array $sources = array() ) {
		$this->config  = $config;
		$this->sources = $sources;
	}

	/**
	 * Follow from the roots.
	 *
	 * @return array{methods: string[], edges: array<string, string[]>, problems: string[], loaded: string[], used: array<string, int>}
	 */
	public function run(): array {
		$queue    = $this->config['roots'];
		$seen     = array();
		$edges    = array();
		$problems = array();
		$loaded   = array();
		$used     = array();
		while ( array() !== $queue ) {
			$target = array_shift( $queue );
			if ( isset( $seen[ $target ] ) ) {
				continue;
			}
			$seen[ $target ]   = true;
			list( $cls, $meth ) = explode( '::', $target );
			$class              = $this->class_of( $cls );
			if ( null === $class ) {
				$problems[] = "$target: no such class";
				continue;
			}
			$loaded[ $cls ] = true;
			$found          = $this->method( $cls, $meth );
			if ( null === $found ) {
				if ( '__construct' !== $meth && ! $this->extends_php( $cls ) ) {
					$problems[] = "$target: no such method";
				}
				continue; // PHP's own (an exception's getMessage()): nothing of the plugin's to follow.
			}
			list( $owner, $m ) = $found;
			$def               = $this->class_of( $owner );
			$loaded[ $owner ]  = true;
			$key               = $owner . '::' . $meth;
			$this->current     = $cls; // An inherited or a trait's method is read again for each class it is reached as.
			if ( $this->guarded( $m ) ) {
				continue;
			}
			$file = $def['file'];
			foreach ( $m['globals'] as $call ) {
				if ( ! self::internal( $call ) && ! $this->allowed( $file, $meth, $call, $used ) ) {
					$problems[] = "$file:$meth(): calls $call()";
				}
			}
			if ( $m['wpdb'] && ! $this->allowed( $file, $meth, '$wpdb', $used ) ) {
				$problems[] = "$file:$meth(): reads \$wpdb";
			}
			foreach ( $m['catches'] as $catch ) {
				if ( ! $this->allowed( $file, $meth, 'catch', $used ) ) {
					$problems[] = "$file:$meth(): a wide catch that may take a NotHere ($catch)";
				}
			}
			foreach ( $m['names'] as $name ) {
				$fq = $this->resolve( $name, $def );
				if ( null !== $fq ) {
					$loaded[ $fq ] = true;
				}
			}
			foreach ( $this->targets( $owner, $def, $m, $problems, $used ) as $next ) {
				$cut = $file . ':' . $meth . ':' . $next;
				if ( isset( $this->config['cuts'][ $cut ] ) && '' !== trim( $this->config['cuts'][ $cut ] ) ) {
					$used[ 'cut:' . $cut ] = 1;
					continue;
				}
				$edges[ $key ][] = $next;
				$queue[]         = $next;
			}
			foreach ( $m['constants'] as $constant ) {
				if ( ! $this->allowed( $file, $meth, $constant, $used ) ) {
					$problems[] = "$file:$meth(): reads the constant $constant";
				}
			}
			if ( $m['dynamic'] ) {
				$dkey = $file . ':' . $meth;
				if ( ! isset( $this->config['dynamic'][ $dkey ] ) ) {
					$problems[] = "$file:$meth(): a call whose target cannot be read";
				} else {
					$used[ 'dynamic:' . $dkey ] = 1;
					foreach ( $this->config['dynamic'][ $dkey ] as $next ) {
						$edges[ $key ][] = $next;
						$queue[]         = $next;
					}
				}
			}
		}
		// What a loaded class needs declared first: its parent, its interfaces, its traits, and theirs (a file holding all
		// of them, the runtime, declares them in that order: Jobs\WebRuntime); and the classes its constants' values,
		// its properties' and its parameters' defaults name (evaluated with it).
		$ancestry = array_keys( $loaded );
		while ( array() !== $ancestry ) {
			$class = $this->class_of( (string) array_shift( $ancestry ) );
			if ( null === $class ) {
				continue;
			}
			foreach ( array_merge( array( $class['parent'] ), $class['interfaces'], $class['traits'], $class['statics'] ) as $name ) {
				$fq = '' === $name ? null : $this->resolve( $name, $class );
				if ( null !== $fq && ! isset( $loaded[ $fq ] ) && null !== $this->class_of( $fq ) ) {
					$loaded[ $fq ] = true;
					$ancestry[]    = $fq;
				}
			}
		}
		foreach ( array_keys( $loaded ) as $cls ) {
			$class = $this->class_of( $cls );
			if ( null !== $class && '' !== trim( (string) preg_replace( "/defined\\s*\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\s*\\|\\s*exit\\s*;/", '', $class['outside'] ) ) ) {
				$problems[] = $class['file'] . ': code outside the class';
			}
		}
		$methods = array_keys( $seen );
		sort( $methods );
		$loaded = array_keys( $loaded );
		sort( $loaded );
		return array(
			'methods'  => $methods,
			'edges'    => $edges,
			'problems' => array_values( array_unique( $problems ) ),
			'loaded'   => $loaded,
			'used'     => $used,
		);
	}

	/**
	 * Whether a method's first statement is a guard.
	 *
	 * @param array<string, mixed> $m Method.
	 * @return bool
	 */
	private function guarded( array $m ): bool {
		if ( 'cli_only' === $m['first'] ) {
			return true;
		}
		if ( 'needs' === $m['first'] ) {
			foreach ( $m['first_args'] as $needed ) {
				if ( ! in_array( $needed, $this->config['can'], true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a finding is registered (and note that it was used).
	 *
	 * @param string             $file File.
	 * @param string             $meth Method.
	 * @param string             $what What.
	 * @param array<string, int> $used Registry entries used (by reference).
	 * @return bool
	 */
	private function allowed( string $file, string $meth, string $what, array &$used ): bool {
		$key = "$file:$meth:$what";
		if ( isset( $this->config['allowed'][ $key ] ) && '' !== trim( $this->config['allowed'][ $key ] ) ) {
			$used[ 'allowed:' . $key ] = 1;
			return true;
		}
		return false;
	}

	/**
	 * Whether a global function is PHP's own.
	 *
	 * @param string $name Function.
	 * @return bool
	 */
	public static function internal( string $name ): bool {
		$name = ltrim( $name, '\\' );
		if ( in_array( strtolower( $name ), self::LATER_PHP, true ) ) {
			return true;
		}
		foreach ( self::EXTENSIONS as $prefix ) {
			if ( 0 === stripos( $name, $prefix . '_' ) ) {
				return true;
			}
		}
		return function_exists( $name ) && ( new \ReflectionFunction( $name ) )->isInternal();
	}

	/**
	 * PHP's core functions added after PHP 7.4, the oldest PHP this plugin runs on: PHP's own wherever the scan runs
	 * (on 7.4 they are not defined, and a call to one is behind function_exists()).
	 */
	const LATER_PHP = array( 'fsync', 'fdatasync', 'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'get_resource_id', 'fdiv', 'preg_last_error_msg', 'array_find', 'array_find_key', 'array_any', 'array_all' );

	/**
	 * PHP's extensions whose functions ("mysqli_…") and constants ("MYSQLI_…") are PHP's own whether or not the PHP the
	 * scan runs on has the extension loaded (Windows' runners have no mysqli; a PHP run without its php.ini has neither).
	 */
	const EXTENSIONS = array( 'mysqli', 'opcache' );

	/**
	 * The methods a method calls, as Class::method.
	 *
	 * @param string               $owner    Its class.
	 * @param array<string, mixed> $def      The class.
	 * @param array<string, mixed> $m        The method.
	 * @param string[]             $problems Findings (by reference).
	 * @param array<string, int>   $used     Registry entries used (by reference).
	 * @return string[]
	 */
	private function targets( string $owner, array $def, array $m, array &$problems, array &$used ): array {
		$out  = array();
		$file = $def['file'];
		foreach ( $m['calls'] as $call ) {
			$kind = $call['kind'];
			if ( 'own' === $kind ) {
				// $this-> and static:: are the class the method is reached as; self:: the class it is written in (the
				// using class for a trait's).
				$virtual = 'self' !== ( $call['via'] ?? 'this' );
				$as      = $virtual || 'trait' === $def['kind'] ? $this->current : $owner;
				$found   = $this->method( $as, $call['method'] );
				if ( $virtual && null !== $found && empty( $found[1]['private'] ) ) {
					$class = $this->class_of( $as );
					if ( null !== $class && empty( $class['final'] ) ) {
						$problems[] = "$file:{$m['name']}(): calls {$call['method']}() on \$this or static:: in $as, which is not final (a subclass may override it)";
					}
				}
				$out[] = $as . '::' . $call['method'];
				continue;
			}
			if ( 'parent' === $kind ) {
				$fq = '' === $def['parent'] ? null : $this->resolve( $def['parent'], $def );
				if ( null !== $fq ) {
					$out[] = $fq . '::' . $call['method']; // A parent of PHP's: its own method, nothing to follow.
				}
				continue;
			}
			if ( 'static' === $kind || 'new' === $kind ) {
				$fq = $this->resolve( $call['class'], $def );
				if ( null !== $fq ) {
					$out[] = $fq . '::' . ( 'new' === $kind ? '__construct' : $call['method'] );
				}
				continue;
			}
			// An object's method: its receiver's type.
			$types = $this->receiver( $owner, $def, $m, $call['receiver'] );
			if ( null === $types ) {
				$rkey = $file . ':' . $m['name'] . ':' . $call['receiver'];
				if ( isset( $this->config['receivers'][ $rkey ] ) ) {
					$used[ 'receiver:' . $rkey ] = 1;
					$types                       = $this->config['receivers'][ $rkey ];
				} else {
					$problems[] = "$file:{$m['name']}(): the type of {$call['receiver']} (calling {$call['method']}()) cannot be read";
					continue;
				}
			}
			$any = array();
			foreach ( $types as $type ) {
				foreach ( $this->bound( $type, $used ) as $cls ) {
					if ( null !== $this->method( $cls, $call['method'] ) || $this->extends_php( $cls ) ) {
						$any[] = $cls . '::' . $call['method'];
					}
				}
			}
			if ( array() === $any && array() !== $types ) {
				$problems[] = "$file:{$m['name']}(): none of " . implode( ', ', $types ) . " has {$call['method']}()";
			}
			$out = array_merge( $out, $any );
		}
		return $out;
	}

	/**
	 * A type as the classes it stands for: an interface's registered implementations, or itself.
	 *
	 * @param string             $type Class or interface.
	 * @param array<string, int> $used Registry entries used (by reference).
	 * @return string[]
	 */
	private function bound( string $type, array &$used ): array {
		$class = $this->class_of( $type );
		if ( null !== $class && 'interface' === $class['kind'] ) {
			$used[ 'binding:' . $type ] = 1;
			return $this->config['bindings'][ $type ] ?? array();
		}
		return array( $type );
	}

	/**
	 * The types of a receiver as written ("$this->host", "$db", "$this->store->x()"), or null when they cannot be read.
	 *
	 * @param string               $owner    Its class.
	 * @param array<string, mixed> $def      The class.
	 * @param array<string, mixed> $m        The method.
	 * @param string               $receiver The receiver.
	 * @return string[]|null
	 */
	private function receiver( string $owner, array $def, array $m, string $receiver, bool $element = false ) {
		$parts = preg_split( '/->/', $receiver );
		if ( ! is_array( $parts ) || array() === $parts ) {
			return null;
		}
		$first = array_shift( $parts );
		if ( '$this' === $first ) {
			$types = array( '' !== $this->current ? $this->current : $owner );
		} elseif ( 0 === strpos( $first, 'new:' ) ) {
			$fq = $this->resolve( substr( $first, 4 ), $def );
			if ( null === $fq ) {
				return null;
			}
			$types = array( $fq );
		} elseif ( false !== strpos( $first, '::' ) && '()' === substr( $first, -2 ) ) {
			$types = $this->type_of_expression( $owner, $def, 'static:' . substr( $first, 0, -2 ), $m );
			if ( null === $types ) {
				return null;
			}
		} elseif ( isset( $m['locals'][ $first ] ) ) {
			$types = array();
			foreach ( $m['locals'][ $first ] as $type ) {
				$resolved = $this->type_of_expression( $owner, $def, $type, $m );
				if ( null === $resolved ) {
					return null;
				}
				$types = array_merge( $types, $resolved );
			}
			if ( array() === $types ) {
				return array(); // Only PHP's types: nothing of the plugin's to follow.
			}
		} else {
			return null;
		}
		$last = count( $parts ) - 1;
		foreach ( $parts as $n => $part ) {
			$next = array();
			$each = $element && $n === $last;
			foreach ( $types as $type ) {
				$class = $this->class_of( $type );
				if ( null === $class ) {
					return null;
				}
				if ( '()' === substr( $part, -2 ) ) {
					$found = $this->method( $type, substr( $part, 0, -2 ) );
					if ( null === $found || array() === $found[1]['returns'] ) {
						return null;
					}
					foreach ( $found[1]['returns'] as $r ) {
						if ( $each && '[]' !== substr( $r, -2 ) ) {
							continue;
						}
						$next[] = $this->resolve_type( $each ? substr( $r, 0, -2 ) : $r, $this->class_of( $found[0] ), $type );
					}
				} else {
					$prop = $this->property( $type, $part );
					if ( null === $prop ) {
						return null;
					}
					foreach ( $prop[1] as $r ) {
						$next[] = $this->resolve_type( $r, $this->class_of( $prop[0] ), $type );
					}
				}
			}
			$types = array_values( array_filter( $next ) );
			if ( array() === $types ) {
				return null;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * What a local variable was given, as types: "type:X" (declared or documented), "new:X", "static:X::m",
	 * "own:m" ($this->m()), "prop:p" ($this->p), "caught:X".
	 *
	 * @param string               $owner Its class.
	 * @param array<string, mixed> $def   The class.
	 * @param string               $what  What it was given.
	 * @return string[]|null
	 */
	private function type_of_expression( string $owner, array $def, string $what, array $m = array() ) {
		list( $kind, $value ) = explode( ':', $what, 2 );
		if ( 'chain' === $kind || 'each' === $kind ) {
			return $this->receiver( $owner, $def, $m, $value, 'each' === $kind );
		}
		if ( 'clone' === $kind ) {
			return $this->receiver( $owner, $def, $m, $value );
		}
		if ( 'type' === $kind || 'new' === $kind || 'caught' === $kind ) {
			if ( in_array( strtolower( ltrim( $value, '?' ) ), array( 'mixed', 'object' ), true ) ) {
				return null; // Any object at all: what it is cannot be read.
			}
			$t = $this->resolve_type( $value, $def, $owner );
			if ( null === $t && ( in_array( strtolower( ltrim( $value, '?' ) ), self::BUILTIN, true ) || class_exists( ltrim( $value, '\\' ) ) || interface_exists( ltrim( $value, '\\' ) ) ) ) {
				return array(); // A type of PHP's (or a built-in one): nothing of the plugin's to follow.
			}
			return null === $t ? null : array( $t );
		}
		if ( 'own' === $kind ) {
			$found = $this->method( $owner, $value );
			if ( null === $found || array() === $found[1]['returns'] ) {
				return null;
			}
			return array_values( array_filter( array_map( function ( string $r ) use ( $found, $owner ) {
				return $this->resolve_type( $r, $this->class_of( $found[0] ), $owner );
			}, $found[1]['returns'] ) ) ) ?: null;
		}
		if ( 'prop' === $kind ) {
			$prop = $this->property( $owner, $value );
			if ( null === $prop ) {
				return null;
			}
			return array_values( array_filter( array_map( function ( string $r ) use ( $prop, $owner ) {
				return $this->resolve_type( $r, $this->class_of( $prop[0] ), $owner );
			}, $prop[1] ) ) ) ?: null;
		}
		if ( 'static' === $kind ) {
			list( $cls, $meth ) = explode( '::', $value );
			$fq                 = $this->resolve( $cls, $def );
			$found              = null === $fq ? null : $this->method( $fq, $meth );
			if ( null === $found || array() === $found[1]['returns'] ) {
				return null;
			}
			return array_values( array_filter( array_map( function ( string $r ) use ( $found, $fq ) {
				return $this->resolve_type( $r, $this->class_of( $found[0] ), $fq );
			}, $found[1]['returns'] ) ) ) ?: null;
		}
		return null;
	}

	/**
	 * A type as written, resolved to a class (null for a built-in type, which has no methods to follow).
	 *
	 * @param string                    $type    Type.
	 * @param array<string, mixed>|null $def     The class it is written in.
	 * @param string                    $current The class "self" and "static" stand for.
	 * @return string|null
	 */
	private function resolve_type( string $type, $def, string $current ) {
		$type = ltrim( $type, '?' );
		if ( in_array( strtolower( $type ), array( 'self', 'static', '$this' ), true ) ) {
			return $current;
		}
		if ( null === $def || in_array( strtolower( $type ), self::BUILTIN, true ) || '[]' === substr( $type, -2 ) ) {
			return null;
		}
		return $this->resolve( $type, $def );
	}

	/**
	 * PHP's functions that call what they are given, and the position of that argument.
	 */
	const CALLBACKS = array(
		'call_user_func'            => 0,
		'call_user_func_array'      => 0,
		'forward_static_call'       => 0,
		'forward_static_call_array' => 0,
		'array_map'                 => 0,
		'array_filter'              => 1,
		'array_walk'                => 1,
		'array_reduce'              => 1,
		'usort'                     => 1,
		'uasort'                    => 1,
		'uksort'                    => 1,
		'preg_replace_callback'     => 1,
		'iterator_apply'            => 1,
	);

	const BUILTIN = array( 'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void', 'null', 'false', 'true', 'never', 'resource' );

	/**
	 * A class name resolved by a class's namespace and imports, or null when it is not the plugin's.
	 *
	 * @param string               $name Name.
	 * @param array<string, mixed> $def  The class.
	 * @return string|null
	 */
	public function resolve( string $name, array $def ) {
		if ( '' === $name || in_array( strtolower( $name ), array( 'self', 'static', 'parent' ), true ) || in_array( strtolower( $name ), self::BUILTIN, true ) ) {
			return null;
		}
		if ( '\\' === $name[0] ) {
			$fq = ltrim( $name, '\\' );
		} else {
			$first = explode( '\\', $name )[0];
			$fq    = isset( $def['uses'][ strtolower( $first ) ] ) ? $def['uses'][ strtolower( $first ) ] . substr( $name, strlen( $first ) ) : ( '' === $def['namespace'] ? $name : $def['namespace'] . '\\' . $name );
		}
		return null !== $this->class_of( $fq ) ? $fq : null;
	}

	/**
	 * A method of a class, through its traits and parents: [the class that defines it, the method].
	 *
	 * @param string $cls  Class.
	 * @param string $meth Method.
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	public function method( string $cls, string $meth ) {
		$class = $this->class_of( $cls );
		if ( null === $class ) {
			return null;
		}
		if ( isset( $class['methods'][ $meth ] ) ) {
			return array( $cls, $class['methods'][ $meth ] );
		}
		foreach ( $class['traits'] as $trait ) {
			$fq = $this->resolve( $trait, $class );
			if ( null !== $fq ) {
				$found = $this->method( $fq, $meth );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		if ( '' !== $class['parent'] ) {
			$fq = $this->resolve( $class['parent'], $class );
			if ( null !== $fq ) {
				return $this->method( $fq, $meth );
			}
		}
		return null;
	}

	/**
	 * Whether a class extends one of PHP's (its methods not found here are PHP's own).
	 *
	 * @param string $cls Class.
	 * @return bool
	 */
	private function extends_php( string $cls ): bool {
		$class = $this->class_of( $cls );
		if ( null === $class || '' === $class['parent'] ) {
			return false;
		}
		$fq = $this->resolve( $class['parent'], $class );
		return null === $fq ? true : $this->extends_php( $fq );
	}

	/**
	 * A property's types, through traits and parents: [the class that declares it, its types].
	 *
	 * @param string $cls  Class.
	 * @param string $name Property (without "$").
	 * @return array{0: string, 1: string[]}|null
	 */
	private function property( string $cls, string $name ) {
		$class = $this->class_of( $cls );
		if ( null === $class ) {
			return null;
		}
		if ( isset( $class['properties'][ $name ] ) && array() !== $class['properties'][ $name ] ) {
			return array( $cls, $class['properties'][ $name ] );
		}
		foreach ( array_merge( $class['traits'], '' === $class['parent'] ? array() : array( $class['parent'] ) ) as $up ) {
			$fq = $this->resolve( $up, $class );
			if ( null !== $fq ) {
				$found = $this->property( $fq, $name );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * A class, read once (null when the plugin has no such class).
	 *
	 * @param string $cls Class.
	 * @return array<string, mixed>|null
	 */
	public function class_of( string $cls ) {
		if ( array_key_exists( $cls, $this->classes ) ) {
			return $this->classes[ $cls ];
		}
		$source = $this->sources[ $cls ] ?? null;
		$file   = 0 === strpos( $cls, 'WPCheckpoint\\' ) ? 'src/' . str_replace( '\\', '/', substr( $cls, strlen( 'WPCheckpoint\\' ) ) ) . '.php' : '';
		if ( null === $source && '' !== $file && is_file( dirname( __DIR__, 2 ) . '/' . $file ) ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		}
		$this->classes[ $cls ] = null === $source ? null : self::parse( $source, '' === $file ? $cls : $file );
		return $this->classes[ $cls ];
	}

	/**
	 * A class's file read by its tokens.
	 *
	 * @param string $source Source.
	 * @param string $file   Its path (for the findings).
	 * @return array<string, mixed>
	 */
	public static function parse( string $source, string $file ): array {
		$all    = token_get_all( $source );
		$tokens = array();
		foreach ( $all as $t ) {
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				$tokens[] = $t;
			}
		}
		$count = count( $tokens );
		$out   = array(
			'file'       => $file,
			'namespace'  => '',
			'uses'       => array(),
			'outside'    => '',
			'kind'       => '',
			'final'      => false,
			'parent'     => '',
			'interfaces' => array(),
			'traits'     => array(),
			'statics'    => array(),
			'properties' => array(),
			'methods'    => array(),
		);
		$depth = 0;
		$doc   = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_DOC_COMMENT === $t[0] ) {
				$doc = $t[1];
				continue;
			}
			if ( is_array( $t ) && T_NAMESPACE === $t[0] && '' === $out['kind'] ) {
				list( $out['namespace'], $i ) = self::name( $tokens, $i + 1 );
				continue; // At its ";".
			}
			if ( is_array( $t ) && T_USE === $t[0] && 0 === $depth ) {
				list( $name, $j ) = self::name( $tokens, $i + 1 );
				$alias            = substr( $name, (int) strrpos( '\\' . $name, '\\' ) );
				if ( is_array( $tokens[ $j ] ) && T_AS === $tokens[ $j ][0] ) {
					$alias = $tokens[ $j + 1 ][1];
					$j    += 2;
				}
				$out['uses'][ strtolower( $alias ) ] = ltrim( $name, '\\' );
				$i                                   = $j;
				continue;
			}
			if ( is_array( $t ) && in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) && 0 === $depth && '' === $out['kind'] ) {
				$out['kind'] = T_INTERFACE === $t[0] ? 'interface' : ( T_TRAIT === $t[0] ? 'trait' : 'class' );
				for ( $j = $i - 1; $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_FINAL, T_ABSTRACT ), true ); $j-- ) {
					$out['final'] = $out['final'] || T_FINAL === $tokens[ $j ][0];
				}
				$listing = '';
				for ( $j = $i + 1; $j < $count && '{' !== $tokens[ $j ]; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_EXTENDS === $tokens[ $j ][0] && 'class' === $out['kind'] ) {
						$out['parent'] = self::name( $tokens, $j + 1 )[0];
					} elseif ( is_array( $tokens[ $j ] ) && ( T_IMPLEMENTS === $tokens[ $j ][0] || ( T_EXTENDS === $tokens[ $j ][0] && 'interface' === $out['kind'] ) ) ) {
						$listing = 'interfaces';
					} elseif ( 'interfaces' === $listing && ',' !== $tokens[ $j ] && ! ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) ) {
						list( $name, $after ) = self::name( $tokens, $j );
						if ( '' !== $name ) {
							$out['interfaces'][] = $name; // What it implements (or, an interface, extends): loaded with it.
							$j                   = $after - 1;
						}
					}
				}
				continue;
			}
			if ( '' === $out['kind'] && 0 === $depth && ! ( is_array( $t ) && in_array( $t[0], array( T_OPEN_TAG, T_DOC_COMMENT, T_FINAL, T_ABSTRACT ), true ) ) ) {
				$out['outside'] .= ( is_array( $t ) ? $t[1] : $t ) . ' ';
			}
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				continue;
			}
			if ( '}' === $t ) {
				--$depth;
				continue;
			}
			if ( 1 !== $depth || '' === $out['kind'] ) {
				continue;
			}
			// In the class body, outside its methods' bodies: a class named before "::" (a constant's value, a property's
			// default, a parameter's default) is evaluated with the class, so it is loaded with it.
			if ( is_array( $t ) && in_array( $t[0], array_merge( array( T_STRING, T_NS_SEPARATOR ), defined( 'T_NAME_QUALIFIED' ) ? array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) : array() ), true ) && ! ( isset( $tokens[ $i - 1 ] ) && is_array( $tokens[ $i - 1 ] ) && in_array( $tokens[ $i - 1 ][0], array( T_STRING, T_NS_SEPARATOR, T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) ) ) {
				list( $named, $after ) = self::name( $tokens, $i );
				if ( '' !== $named && isset( $tokens[ $after ] ) && is_array( $tokens[ $after ] ) && T_DOUBLE_COLON === $tokens[ $after ][0] ) {
					$out['statics'][] = $named;
				}
			}
			// In the class body.
			if ( is_array( $t ) && T_USE === $t[0] ) {
				for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
					list( $name, $after ) = self::name( $tokens, $j );
					if ( '' !== $name ) {
						$out['traits'][] = $name;
						$j               = $after - 1;
					}
				}
				$i = $j;
				continue;
			}
			if ( is_array( $t ) && T_VARIABLE === $t[0] ) {
				// A property: its declared type just before, or its docblock's.
				$types = array();
				list( $declared ) = self::type_before( $tokens, $i );
				if ( '' !== $declared ) {
					$types = self::types( $declared );
				} elseif ( preg_match( '/@var\s+(\S+)/', $doc, $mm ) ) {
					$types = self::types( $mm[1] );
				}
				$out['properties'][ substr( $t[1], 1 ) ] = $types;
				$doc                                     = '';
				while ( $i < $count && ';' !== $tokens[ $i ] ) {
					++$i;
				}
				continue;
			}
			if ( is_array( $t ) && T_FUNCTION === $t[0] && is_array( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				$m              = self::method_at( $tokens, $i, $doc );
				$doc            = '';
				$out['methods'][ $m['name'] ] = $m;
				$i              = $m['end'];
				continue;
			}
		}
		return $out;
	}

	/**
	 * A name (namespaced) at a position, and where it ends.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Position.
	 * @return array{0: string, 1: int}
	 */
	private static function name( array $tokens, int $i ): array {
		$ids = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$ids[] = T_NAME_QUALIFIED;
			$ids[] = T_NAME_FULLY_QUALIFIED;
			$ids[] = T_NAME_RELATIVE;
		}
		$name = '';
		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $ids, true ) ) {
			$name .= $tokens[ $i ][1];
			++$i;
		}
		return array( $name, $i );
	}

	/**
	 * The type written right before a variable (a parameter's or a property's), and where it starts.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      The variable's position.
	 * @return array{0: string, 1: int}
	 */
	private static function type_before( array $tokens, int $i ): array {
		$j = $i - 1;
		while ( $j >= 0 && ( '&' === $tokens[ $j ] || ( is_array( $tokens[ $j ] ) && T_ELLIPSIS === $tokens[ $j ][0] ) ) ) {
			--$j;
		}
		$type = '';
		while ( $j >= 0 && ( '|' === $tokens[ $j ] || '?' === $tokens[ $j ] || ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NS_SEPARATOR, T_ARRAY, T_CALLABLE ), true ) ) || ( defined( 'T_NAME_QUALIFIED' ) && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) ) ) {
			$type = ( is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ] ) . $type;
			--$j;
		}
		return array( $type, $j + 1 );
	}

	/**
	 * Types written as "A|B|null" (or "?A").
	 *
	 * @param string $written Written.
	 * @return string[]
	 */
	private static function types( string $written ): array {
		return array_values( array_filter( array_map( static function ( string $t ): string {
			return ltrim( trim( $t ), '?' );
		}, explode( '|', $written ) ), static function ( string $t ): bool {
			return '' !== $t && 'null' !== strtolower( $t );
		} ) );
	}

	/**
	 * A method: its parameters' types (declared or documented), its return types, and what its body (closures in it
	 * included) does.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $at     Where "function" is.
	 * @param string            $doc    Its docblock.
	 * @return array<string, mixed>
	 */
	private static function method_at( array $tokens, int $at, string $doc ): array {
		$count  = count( $tokens );
		$name   = $tokens[ $at + 1 ][1];
		$locals = array();
		$params = array();
		$j      = $at + 2;
		$paren  = 0;
		for ( ; $j < $count; $j++ ) {
			if ( '(' === $tokens[ $j ] ) {
				++$paren;
			} elseif ( ')' === $tokens[ $j ] ) {
				--$paren;
				if ( 0 === $paren ) {
					break;
				}
			} elseif ( is_array( $tokens[ $j ] ) && T_VARIABLE === $tokens[ $j ][0] && 1 === $paren ) {
				$declared        = self::type_before( $tokens, $j )[0];
				$params[]        = $tokens[ $j ][1];
				$types           = '' !== $declared ? self::types( $declared ) : array();
				if ( array() === $types && preg_match( '/@param\s+(\S+)\s+' . preg_quote( $tokens[ $j ][1], '/' ) . '\b/', $doc, $mm ) ) {
					$types = self::types( $mm[1] );
				}
				foreach ( $types as $type ) {
					$locals[ $tokens[ $j ][1] ][] = 'type:' . $type;
				}
			}
		}
		$returns = array();
		$k       = $j + 1;
		if ( ':' === $tokens[ $k ] ) {
			list( $ret, $k ) = self::return_type( $tokens, $k + 1 );
			$returns         = self::types( $ret );
		}
		if ( array() === $returns && preg_match( '/@return\s+(\S+)/', $doc, $mm ) ) {
			$returns = self::types( $mm[1] );
		}
		while ( $k < $count && '{' !== $tokens[ $k ] && ';' !== $tokens[ $k ] ) {
			++$k;
		}
		$private = false;
		for ( $b = $at - 1; $b >= 0 && is_array( $tokens[ $b ] ) && in_array( $tokens[ $b ][0], array( T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL ), true ); $b-- ) {
			$private = $private || T_PRIVATE === $tokens[ $b ][0];
		}
		$m = array(
			'name'       => $name,
			'private'    => $private,
			'returns'    => $returns,
			'locals'     => $locals,
			'calls'      => array(),
			'globals'    => array(),
			'names'      => array(),
			'catches'    => array(),
			'wpdb'       => false,
			'dynamic'    => false,
			'constants'  => array(),
			'first'      => '',
			'first_args' => array(),
			'end'        => $k,
		);
		if ( ';' === $tokens[ $k ] ) {
			return $m;
		}
		$level = 0;
		for ( $e = $k; $e < $count; $e++ ) {
			if ( '{' === $tokens[ $e ] || ( is_array( $tokens[ $e ] ) && in_array( $tokens[ $e ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$level;
			} elseif ( '}' === $tokens[ $e ] ) {
				--$level;
				if ( 0 === $level ) {
					break;
				}
			}
		}
		$m['end'] = $e;
		$body     = array_slice( $tokens, $k + 1, $e - $k - 1 );
		$body     = array_values( array_filter( $body, static function ( $t ): bool {
			return ! is_array( $t ) || T_DOC_COMMENT !== $t[0];
		} ) );
		return self::read_body( $m, $body );
	}

	/**
	 * A return type, and where it ends.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $k      Position after ":".
	 * @return array{0: string, 1: int}
	 */
	private static function return_type( array $tokens, int $k ): array {
		$type = '';
		while ( isset( $tokens[ $k ] ) && ( '?' === $tokens[ $k ] || '|' === $tokens[ $k ] || ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_STRING, T_NS_SEPARATOR, T_ARRAY, T_CALLABLE, T_STATIC ), true ) ) || ( defined( 'T_NAME_QUALIFIED' ) && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) ) ) {
			$type .= is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			++$k;
		}
		return array( $type, $k );
	}

	/**
	 * What a body does.
	 *
	 * @param array<string, mixed> $m    The method so far.
	 * @param array<int, mixed>    $body Its tokens.
	 * @return array<string, mixed>
	 */
	private static function read_body( array $m, array $body ): array {
		$count = count( $body );
		$is    = static function ( $t, int $id ): bool {
			return is_array( $t ) && $id === $t[0];
		};
		$ids   = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$ids[] = T_NAME_QUALIFIED;
			$ids[] = T_NAME_FULLY_QUALIFIED;
		}
		// The guard: the first statement.
		if ( $count > 3 && $is( $body[0], T_VARIABLE ) && '$this' === $body[0][1] && $is( $body[1], T_OBJECT_OPERATOR ) && $is( $body[2], T_STRING ) && '(' === $body[3] ) {
			$m['first'] = $body[2][1];
			for ( $q = 4; $q < $count && ')' !== $body[ $q ]; $q++ ) {
				if ( $is( $body[ $q ], T_CONSTANT_ENCAPSED_STRING ) ) {
					$m['first_args'][] = trim( $body[ $q ][1], '\'"' );
				}
			}
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$t    = $body[ $i ];
			$prev = $i > 0 ? $body[ $i - 1 ] : null;
			if ( $is( $t, T_VARIABLE ) && '$wpdb' === $t[1] ) {
				$m['wpdb'] = true;
			}
			// Closure parameters and caught exceptions: local types.
			if ( ( $is( $t, T_FUNCTION ) || ( defined( 'T_FN' ) && $is( $t, T_FN ) ) ) && isset( $body[ $i + 1 ] ) && '(' === $body[ $i + 1 ] ) {
				for ( $q = $i + 2; $q < $count && ')' !== $body[ $q ]; $q++ ) {
					if ( $is( $body[ $q ], T_VARIABLE ) ) {
						$declared = self::type_before( $body, $q )[0];
						foreach ( '' !== $declared ? self::types( $declared ) : array() as $type ) {
							$m['locals'][ $body[ $q ][1] ][] = 'type:' . $type;
						}
					}
				}
			}
			if ( $is( $t, T_CATCH ) ) {
				$type = '';
				$var  = '';
				$wide = '';
				for ( $q = $i + 2; $q < $count && ')' !== $body[ $q ]; $q++ ) {
					if ( $is( $body[ $q ], T_VARIABLE ) ) {
						$var = $body[ $q ][1];
					} elseif ( is_array( $body[ $q ] ) && in_array( $body[ $q ][0], $ids, true ) ) {
						list( $type, $after ) = self::name( $body, $q );
						$m['names'][]         = $type;
						if ( in_array( strtolower( ltrim( $type, '\\' ) ), array( 'throwable', 'exception', 'runtimeexception' ), true ) ) {
							$wide = $type;
						}
						$q = $after - 1;
					}
				}
				if ( '' !== $var && '' !== $type ) {
					$m['locals'][ $var ][] = 'caught:' . $type;
				}
				if ( '' !== $wide && ! self::passes_not_here( $body, $i, $q, $var ) ) {
					$m['catches'][] = $wide;
				}
				$i = $q;
				continue;
			}
			// Assignments: $v = new X / X::m() / $this->m() / $this->p.
			if ( $is( $t, T_VARIABLE ) && isset( $body[ $i + 1 ] ) && '=' === $body[ $i + 1 ] && '$this' !== $t[1] ) {
				$v = $t[1];
				$a = $i + 2;
				if ( $is( $body[ $a ] ?? null, T_NEW ) ) {
					list( $cls ) = self::name( $body, $a + 1 );
					if ( '' !== $cls ) {
						$m['locals'][ $v ][] = 'new:' . $cls;
					}
				} elseif ( is_array( $body[ $a ] ?? null ) && in_array( $body[ $a ][0], $ids, true ) ) {
					list( $cls, $after ) = self::name( $body, $a );
					if ( $is( $body[ $after ] ?? null, T_DOUBLE_COLON ) && $is( $body[ $after + 1 ] ?? null, T_STRING ) && '(' === ( $body[ $after + 2 ] ?? null ) ) {
						$m['locals'][ $v ][] = 'static:' . $cls . '::' . $body[ $after + 1 ][1];
					}
				} elseif ( $is( $body[ $a ] ?? null, T_VARIABLE ) && '$this' === $body[ $a ][1] && $is( $body[ $a + 1 ] ?? null, T_OBJECT_OPERATOR ) && $is( $body[ $a + 2 ] ?? null, T_STRING ) ) {
					$is_call = '(' === ( $body[ $a + 3 ] ?? null );
					$ends    = $is_call ? self::close_of( $body, $a + 3 ) + 1 : $a + 3;
					if ( ';' === ( $body[ $ends ] ?? null ) ) {
						$m['locals'][ $v ][] = ( $is_call ? 'own:' : 'prop:' ) . $body[ $a + 2 ][1];
					}
				}
			}
			if ( $is( $t, T_VARIABLE ) && '=' === ( $body[ $i + 1 ] ?? null ) && '$this' !== $t[1] ) {
				$v = $t[1];
				$a = $i + 2;
				if ( $is( $body[ $a ] ?? null, T_CLONE ) && $is( $body[ $a + 1 ] ?? null, T_VARIABLE ) ) {
					$m['locals'][ $v ][] = 'clone:' . $body[ $a + 1 ][1];
				} elseif ( $is( $body[ $a ] ?? null, T_VARIABLE ) ) {
					$expr = self::call_chain_to_end( $body, $a );
					if ( null !== $expr ) {
						$m['locals'][ $v ][] = 'chain:' . $expr;
					}
				}
			}
			if ( $is( $t, T_FOREACH ) ) {
				$close = self::close_of( $body, $i + 1 );
				for ( $q = $i + 2; $q < $close; $q++ ) {
					if ( $is( $body[ $q ], T_AS ) ) {
						$value = $body[ $close - 1 ];
						$expr  = self::chain_ending_at( $body, $q - 1 );
						if ( null !== $expr && $is( $value, T_VARIABLE ) ) {
							$m['locals'][ $value[1] ][] = 'each:' . $expr;
						}
						break;
					}
				}
			}
			// Dynamic calls.
			if ( $is( $t, T_VARIABLE ) && '(' === ( $body[ $i + 1 ] ?? null ) && ! $is( $prev, T_OBJECT_OPERATOR ) && ! $is( $prev, T_DOUBLE_COLON ) && ! $is( $prev, T_FUNCTION ) ) {
				$m['dynamic'] = true;
			}
			if ( $is( $t, T_OBJECT_OPERATOR ) && ( $is( $body[ $i + 1 ] ?? null, T_VARIABLE ) || '{' === ( $body[ $i + 1 ] ?? null ) ) ) {
				// ->$name( or ->{...}(: a call by a name read at run time (a dynamic property is no call).
				$after = $i + 2;
				if ( '{' === $body[ $i + 1 ] ) {
					$level = 0;
					for ( $after = $i + 1; $after < $count; $after++ ) {
						if ( '{' === $body[ $after ] ) {
							++$level;
						} elseif ( '}' === $body[ $after ] ) {
							--$level;
							if ( 0 === $level ) {
								++$after;
								break;
							}
						}
					}
				}
				if ( '(' === ( $body[ $after ] ?? null ) ) {
					$m['dynamic'] = true;
				}
			}
			if ( $is( $t, T_NEW ) && $is( $body[ $i + 1 ] ?? null, T_VARIABLE ) ) {
				$m['dynamic'] = true;
			}
			// static::m( (T_STATIC is not a name).
			if ( $is( $t, T_STATIC ) && $is( $body[ $i + 1 ] ?? null, T_DOUBLE_COLON ) && $is( $body[ $i + 2 ] ?? null, T_STRING ) && '(' === ( $body[ $i + 3 ] ?? null ) ) {
				$m['calls'][] = array(
					'kind'   => 'own',
					'method' => $body[ $i + 2 ][1],
					'via'    => 'static',
				);
				continue;
			}
			// An object's method: the receiver chain before "->m(".
			if ( $is( $t, T_OBJECT_OPERATOR ) && $is( $body[ $i + 1 ] ?? null, T_STRING ) && '(' === ( $body[ $i + 2 ] ?? null ) ) {
				$receiver = self::receiver_before( $body, $i );
				if ( '$this' === $receiver ) {
					$m['calls'][] = array(
						'kind'   => 'own',
						'method' => $body[ $i + 1 ][1],
						'via'    => 'this',
					);
				} else {
					$m['calls'][] = array( 'kind' => 'object', 'receiver' => $receiver, 'method' => $body[ $i + 1 ][1] );
				}
				continue;
			}
			if ( ( is_array( $t ) && in_array( $t[0], $ids, true ) ) && ! ( is_array( $prev ) && in_array( $prev[0], array_merge( $ids, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST ) ), true ) ) && ! ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && $is( $prev, T_NULLSAFE_OBJECT_OPERATOR ) ) ) {
				list( $name, $after ) = self::name( $body, $i );
				$next                 = $body[ $after ] ?? null;
				if ( $is( $prev, T_NEW ) ) {
					$m['calls'][] = array( 'kind' => 'new', 'class' => $name );
				} elseif ( $is( $next, T_DOUBLE_COLON ) ) {
					$member = $body[ $after + 1 ] ?? null;
					$call   = $is( $member, T_STRING ) && '(' === ( $body[ $after + 2 ] ?? null );
					$lower  = strtolower( $name );
					if ( $call && in_array( $lower, array( 'self', 'static' ), true ) ) {
						$m['calls'][] = array(
							'kind'   => 'own',
							'method' => $member[1],
							'via'    => $lower,
						);
					} elseif ( $call && 'parent' === $lower ) {
						$m['calls'][] = array( 'kind' => 'parent', 'method' => $member[1] );
					} elseif ( $call ) {
						$m['calls'][] = array( 'kind' => 'static', 'class' => $name, 'method' => $member[1] );
					} elseif ( ! in_array( $lower, array( 'self', 'static', 'parent' ), true ) ) {
						$m['names'][] = $name;
					}
					if ( $is( $member, T_VARIABLE ) && '(' === ( $body[ $after + 2 ] ?? null ) ) {
						$m['dynamic'] = true;
					}
				} elseif ( '(' === $next ) {
					$lower = strtolower( ltrim( $name, '\\' ) );
					if ( isset( self::CALLBACKS[ $lower ] ) ) {
						$m = self::callback_argument( $m, $body, $after, self::CALLBACKS[ $lower ] );
					}
					$m['globals'][] = $name;
				} elseif ( $is( $prev, T_INSTANCEOF ) ) {
					$m['names'][] = $name;
				}
				$i = $after - 1;
			}
		}
		$m['globals'] = array_values( array_unique( $m['globals'] ) );
		$m            = self::constants( $m, $body );
		return self::callable_arrays( $m, $body );
	}

	/**
	 * The global constants a body reads that are not PHP's own (a constant WordPress, wp-config.php or this plugin's
	 * main file defines): a name standing alone, not a call, a class, a class constant, a declaration, nor true, false
	 * or null. Whether one is defined where the run is made (wp-config.php's ABSPATH, DB_*, the salts) is for the
	 * registry to say.
	 *
	 * @param array<string, mixed> $m    The method so far.
	 * @param array<int, mixed>    $body Tokens.
	 * @return array<string, mixed>
	 */
	private static function constants( array $m, array $body ): array {
		$internal = array();
		foreach ( get_defined_constants( true ) as $group => $names ) {
			if ( 'user' !== $group ) {
				$internal += $names;
			}
		}
		$code = array();
		foreach ( $body as $t ) {
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code[] = $t;
			}
		}
		$skip = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION, T_CONST, T_INSTANCEOF, T_NS_SEPARATOR, T_USE, T_GOTO, T_CLASS, T_EXTENDS, T_IMPLEMENTS, T_INSTEADOF, T_AS, T_INTERFACE, T_TRAIT );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$skip[] = T_NULLSAFE_OBJECT_OPERATOR;
		}
		foreach ( $code as $i => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$name = '';
			if ( T_STRING === $t[0] ) {
				$name = $t[1];
			} elseif ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $t[0] && 1 === substr_count( $t[1], '\\' ) ) {
				$name = ltrim( $t[1], '\\' );
			}
			if ( '' === $name || in_array( strtolower( $name ), array( 'true', 'false', 'null', 'self', 'static', 'parent' ), true ) ) {
				continue;
			}
			$prev = $code[ $i - 1 ] ?? null;
			$next = $code[ $i + 1 ] ?? null;
			if ( is_array( $prev ) && in_array( $prev[0], $skip, true ) ) {
				continue;
			}
			if ( '(' === $next || ( is_array( $next ) && in_array( $next[0], array( T_DOUBLE_COLON, T_NS_SEPARATOR, T_VARIABLE, T_ELLIPSIS ), true ) ) ) {
				continue; // A call, a class (X::, a type before a parameter or a catch's variable).
			}
			if ( '?' === $prev && is_array( $next ) && T_VARIABLE === $next[0] ) {
				continue;
			}
			if ( '|' === $next || '|' === $prev ) {
				continue; // A union of types (a catch).
			}
			if ( ':' === $prev && ( '{' === $next || ';' === $next || '=>' === $next ) ) {
				continue; // A return type.
			}
			if ( isset( $internal[ $name ] ) ) {
				continue;
			}
			foreach ( self::EXTENSIONS as $prefix ) {
				if ( 0 === strpos( $name, strtoupper( $prefix ) . '_' ) ) {
					continue 2;
				}
			}
			$m['constants'][] = $name;
		}
		$m['constants'] = array_values( array_unique( $m['constants'] ) );
		return $m;
	}

	/**
	 * Every callable array written in a body (array( X::class, 'm' ), [ $this, 'm' ], array( self::class, 'm' )), given
	 * anywhere (an option, a property, an argument): an edge to that method, whether or not it is called here, so that
	 * a method given by name is read like a closure is.
	 *
	 * @param array<string, mixed> $m    The method so far.
	 * @param array<int, mixed>    $body Tokens.
	 * @return array<string, mixed>
	 */
	private static function callable_arrays( array $m, array $body ): array {
		$flat = array();
		foreach ( $body as $t ) {
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$flat[] = is_array( $t ) ? $t[1] : $t;
		}
		$text = implode( "\x00", $flat );
		$name = '[A-Za-z_\\\\][A-Za-z0-9_\\\\]*';
		$pattern = '/(?:array\x00\(|\[)\x00(\$this|' . $name . '\x00::\x00class)\x00,\x00([\'"])([A-Za-z_][A-Za-z0-9_]*)\2\x00(?:,\x00)?[\)\]]/i';
		if ( preg_match_all( $pattern, $text, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $found ) {
				$who = str_replace( "\x00", '', $found[1] );
				if ( '$this' === $who || 0 === strcasecmp( $who, 'self::class' ) || 0 === strcasecmp( $who, 'static::class' ) ) {
					$m['calls'][] = array(
						'kind'   => 'own',
						'method' => $found[3],
						'via'    => 0 === strcasecmp( $who, 'self::class' ) ? 'self' : 'this',
					);
				} else {
					$m['calls'][] = array( 'kind' => 'static', 'class' => substr( $who, 0, -7 ), 'method' => $found[3] );
				}
			}
		}
		return $m;
	}

	/**
	 * What a function that calls its argument is given there: a closure (read with the method), a function's name
	 * (a call to it), array( X::class, 'm' ) or array( $this, 'm' ) (a call to that method); anything else is a call
	 * whose target cannot be read.
	 *
	 * @param array<string, mixed> $m     The method so far.
	 * @param array<int, mixed>    $body  Tokens.
	 * @param int                  $open  The call's "(".
	 * @param int                  $index The argument's position.
	 * @return array<string, mixed>
	 */
	private static function callback_argument( array $m, array $body, int $open, int $index ): array {
		$close = self::close_of( $body, $open );
		$args  = array( array() );
		$level = 0;
		for ( $k = $open + 1; $k < $close; $k++ ) {
			$t = $body[ $k ];
			if ( in_array( $t, array( '(', '[', '{' ), true ) ) {
				++$level;
			} elseif ( in_array( $t, array( ')', ']', '}' ), true ) ) {
				--$level;
			} elseif ( ',' === $t && 0 === $level ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}
		$arg = $args[ $index ] ?? array();
		if ( array() === $arg ) {
			return $m;
		}
		$first = $arg[0];
		$at    = is_array( $first ) && T_STATIC === $first[0] ? 1 : 0;
		if ( is_array( $arg[ $at ] ?? null ) && ( T_FUNCTION === $arg[ $at ][0] || ( defined( 'T_FN' ) && T_FN === $arg[ $at ][0] ) ) ) {
			return $m; // A closure: read with this method.
		}
		if ( 1 === count( $arg ) && is_array( $first ) && T_CONSTANT_ENCAPSED_STRING === $first[0] && 1 === preg_match( '/\A[\'"][A-Za-z_\\\\][A-Za-z0-9_\\\\]*[\'"]\z/', $first[1] ) ) {
			$name = trim( $first[1], '\'"' );
			if ( false === strpos( $name, '::' ) ) {
				$m['globals'][] = $name;
				return $m;
			}
		}
		// array( X::class, 'm' ) or array( $this, 'm' ).
		$flat = array();
		foreach ( $arg as $t ) {
			$flat[] = is_array( $t ) ? $t[1] : $t;
		}
		$text = implode( '', $flat );
		if ( 1 === preg_match( '/\Aarray\((\$this|self::class|static::class|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class),[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\)\z/', $text, $mm ) ) {
			if ( '$this' === $mm[1] || 'self::class' === $mm[1] || 'static::class' === $mm[1] ) {
				$m['calls'][] = array(
					'kind'   => 'own',
					'method' => $mm[3],
					'via'    => 'self::class' === $mm[1] ? 'self' : 'this',
				);
			} else {
				$m['calls'][] = array( 'kind' => 'static', 'class' => $mm[2], 'method' => $mm[3] );
			}
			return $m;
		}
		$m['dynamic'] = true;
		return $m;
	}

	/**
	 * The chain an assignment's right side is, when it is one ending in a call at the end of its statement
	 * ($x->a->m( ... );), as "$x->a->m()"; null otherwise.
	 *
	 * @param array<int, mixed> $body  Tokens.
	 * @param int               $start Where the right side starts.
	 * @return string|null
	 */
	private static function call_chain_to_end( array $body, int $start ) {
		$level = 0;
		$n     = count( $body );
		for ( $e = $start; $e < $n; $e++ ) {
			if ( '(' === $body[ $e ] || '[' === $body[ $e ] ) {
				++$level;
			} elseif ( ')' === $body[ $e ] || ']' === $body[ $e ] ) {
				--$level;
			} elseif ( 0 === $level && ( ';' === $body[ $e ] || ',' === $body[ $e ] ) ) {
				break;
			}
		}
		return self::chain_ending_at( $body, $e - 1 );
	}

	/**
	 * The chain that ends at a token: "$x", "$this->p", "$x->m()" (as receiver_before() reads them); null when it is
	 * not one.
	 *
	 * @param array<int, mixed> $body Tokens.
	 * @param int               $end  Its last token.
	 * @return string|null
	 */
	private static function chain_ending_at( array $body, int $end ) {
		$t = $body[ $end ] ?? null;
		if ( is_array( $t ) && T_VARIABLE === $t[0] ) {
			return $t[1];
		}
		if ( ')' === $t ) {
			$level = 0;
			for ( $k = $end; $k >= 0; $k-- ) {
				if ( ')' === $body[ $k ] ) {
					++$level;
				} elseif ( '(' === $body[ $k ] ) {
					--$level;
					if ( 0 === $level ) {
						break;
					}
				}
			}
			if ( $k > 1 && is_array( $body[ $k - 1 ] ) && T_STRING === $body[ $k - 1 ][0] && is_array( $body[ $k - 2 ] ) && T_OBJECT_OPERATOR === $body[ $k - 2 ][0] ) {
				$before = self::receiver_before( $body, $k - 2 );
				return '?' === $before ? null : $before . '->' . $body[ $k - 1 ][1] . '()';
			}
			return null;
		}
		if ( is_array( $t ) && T_STRING === $t[0] && $end > 0 && is_array( $body[ $end - 1 ] ) && T_OBJECT_OPERATOR === $body[ $end - 1 ][0] ) {
			$before = self::receiver_before( $body, $end - 1 );
			return '?' === $before ? null : $before . '->' . $t[1];
		}
		return null;
	}

	/**
	 * Where a "(" closes.
	 *
	 * @param array<int, mixed> $body Tokens.
	 * @param int               $open The "(".
	 * @return int
	 */
	private static function close_of( array $body, int $open ): int {
		$level = 0;
		for ( $k = $open; $k < count( $body ); $k++ ) {
			if ( '(' === $body[ $k ] ) {
				++$level;
			} elseif ( ')' === $body[ $k ] ) {
				--$level;
				if ( 0 === $level ) {
					return $k;
				}
			}
		}
		return count( $body ) - 1;
	}

	/**
	 * The receiver chain before "->" at $i, as "$x", "$this->p", "$this->m()->p", "$this->p['k']" (an array element,
	 * its index as written), or "?" when it is not such a chain (a parenthesised expression); a call with arguments is
	 * kept as "m()".
	 *
	 * @param array<int, mixed> $body Tokens.
	 * @param int               $i    The "->".
	 * @return string
	 */
	private static function receiver_before( array $body, int $i ): string {
		$parts = array();
		$index = '';
		$j     = $i - 1;
		while ( $j >= 0 ) {
			$t = $body[ $j ];
			if ( ']' === $t ) {
				// An array element: back to its "[", the index kept as written on the part before it.
				$level = 0;
				for ( $k = $j; $k >= 0; $k-- ) {
					if ( ']' === $body[ $k ] ) {
						++$level;
					} elseif ( '[' === $body[ $k ] ) {
						--$level;
						if ( 0 === $level ) {
							break;
						}
					}
				}
				$text = '';
				for ( $c = $k; $c <= $j; $c++ ) {
					$text .= is_array( $body[ $c ] ) ? $body[ $c ][1] : $body[ $c ];
				}
				$index = $text . $index;
				$j     = $k - 1;
				continue;
			}
			if ( '' !== $index ) {
				if ( is_array( $t ) && ( T_VARIABLE === $t[0] || ( T_STRING === $t[0] && $j > 0 && is_array( $body[ $j - 1 ] ) && T_OBJECT_OPERATOR === $body[ $j - 1 ][0] ) ) ) {
					array_unshift( $parts, $t[1] . $index );
					$index = '';
					if ( T_VARIABLE === $t[0] ) {
						break;
					}
					$j -= 2;
					continue;
				}
				return '?';
			}
			if ( is_array( $t ) && T_VARIABLE === $t[0] ) {
				array_unshift( $parts, $t[1] );
				break;
			}
			if ( ')' === $t ) {
				// A call: back to its "(" and the method's name.
				$level = 0;
				for ( $k = $j; $k >= 0; $k-- ) {
					if ( ')' === $body[ $k ] ) {
						++$level;
					} elseif ( '(' === $body[ $k ] ) {
						--$level;
						if ( 0 === $level ) {
							break;
						}
					}
				}
				if ( $k > 0 && is_array( $body[ $k - 1 ] ) && T_STRING === $body[ $k - 1 ][0] && $k > 1 && is_array( $body[ $k - 2 ] ) && T_OBJECT_OPERATOR === $body[ $k - 2 ][0] ) {
					array_unshift( $parts, $body[ $k - 1 ][1] . '()' );
					$j = $k - 3;
					continue;
				}
				if ( $k > 2 && is_array( $body[ $k - 1 ] ) && T_STRING === $body[ $k - 1 ][0] && is_array( $body[ $k - 2 ] ) && T_DOUBLE_COLON === $body[ $k - 2 ][0] ) {
					// A static call starts the chain: X::m().
					$c   = $k - 3;
					$cls = '';
					while ( $c >= 0 && is_array( $body[ $c ] ) && in_array( $body[ $c ][0], array( T_STRING, T_NS_SEPARATOR ), true ) ) {
						$cls = $body[ $c ][1] . $cls;
						--$c;
					}
					array_unshift( $parts, $cls . '::' . $body[ $k - 1 ][1] . '()' );
					break;
				}
				if ( isset( $body[ $k + 1 ] ) && is_array( $body[ $k + 1 ] ) && T_NEW === $body[ $k + 1 ][0] ) {
					// ( new X( ... ) ) starts the chain.
					list( $cls ) = self::name( $body, $k + 2 );
					array_unshift( $parts, 'new:' . $cls );
					break;
				}
				return '?';
			}
			if ( is_array( $t ) && T_STRING === $t[0] && $j > 0 && is_array( $body[ $j - 1 ] ) && T_OBJECT_OPERATOR === $body[ $j - 1 ][0] ) {
				array_unshift( $parts, $t[1] );
				$j -= 2;
				continue;
			}
			return '?';
		}
		return array() === $parts ? '?' : implode( '->', $parts );
	}

	/**
	 * Whether a wide catch lets a NotHere through: a catch ( NotHere $x ) { throw $x; } before it in the same try, or
	 * its own body ends in throwing what it caught, with no return in it.
	 *
	 * @param array<int, mixed> $body The function's tokens.
	 * @param int               $at   Where the catch keyword is.
	 * @param int               $end  Where its ")" is.
	 * @param string            $var  Its variable.
	 * @return bool
	 */
	private static function passes_not_here( array $body, int $at, int $end, string $var ): bool {
		$open  = $end + 1;
		$level = 0;
		$close = $open;
		$n     = count( $body );
		for ( $k = $open; $k < $n; $k++ ) {
			if ( '{' === $body[ $k ] ) {
				++$level;
			} elseif ( '}' === $body[ $k ] ) {
				--$level;
				if ( 0 === $level ) {
					$close = $k;
					break;
				}
			}
		}
		$inner = array_slice( $body, $open + 1, $close - $open - 1 );
		$c     = count( $inner );
		$ret   = false;
		foreach ( $inner as $t ) {
			if ( is_array( $t ) && T_RETURN === $t[0] ) {
				$ret = true;
			}
		}
		if ( ! $ret && $c >= 3 && is_array( $inner[ $c - 3 ] ) && T_THROW === $inner[ $c - 3 ][0] && is_array( $inner[ $c - 2 ] ) && $var === $inner[ $c - 2 ][1] && ';' === $inner[ $c - 1 ] ) {
			return true;
		}
		$k = $at - 1;
		while ( $k >= 0 && '}' === $body[ $k ] ) {
			$level = 0;
			for ( $j = $k; $j >= 0; $j-- ) {
				if ( '}' === $body[ $j ] ) {
					++$level;
				} elseif ( '{' === $body[ $j ] ) {
					--$level;
					if ( 0 === $level ) {
						break;
					}
				}
			}
			$before = $body[ $j - 1 ] ?? null;
			if ( is_array( $before ) && T_TRY === $before[0] ) {
				return false;
			}
			$p = $j - 1;
			while ( $p >= 0 && ! ( is_array( $body[ $p ] ) && T_CATCH === $body[ $p ][0] ) ) {
				--$p;
			}
			if ( $p < 0 ) {
				return false;
			}
			$type = '';
			$name = '';
			for ( $q = $p + 2; $q < $j - 1; $q++ ) {
				if ( is_array( $body[ $q ] ) && T_VARIABLE === $body[ $q ][0] ) {
					$name = $body[ $q ][1];
				} elseif ( is_array( $body[ $q ] ) ) {
					$type .= $body[ $q ][1];
				}
			}
			if ( 'nothere' === strtolower( substr( $type, (int) strrpos( '\\' . $type, '\\' ) ) ) ) {
				$block = array_slice( $body, $j + 1, $k - $j - 1 );
				return 3 === count( $block ) && is_array( $block[0] ) && T_THROW === $block[0][0] && $name === $block[1][1] && ';' === $block[2];
			}
			$k = $p - 1;
		}
		return false;
	}
}
