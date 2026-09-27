<?php
/**
 * PHPStan rule: every option and transient name the plugin stores under comes from Support\StoredNames.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports a call of the options or transients API (and of Support\Options,
 * the plugin's wrapper) whose name is neither a constant listed in
 * StoredNames::EXACT nor the result of one of StoredNames' builders. A
 * restore carries the running plugin's rows by that list (Restore\StateCarry);
 * a name written anywhere else would be left out, and the restored site
 * would keep the backup's value of it. Reads of names that do not start
 * with "wpcheckpoint_" are WordPress's own options (date_format) and pass.
 * A name from StoredNames is a constant or the result of one of its
 * BUILDERS, no other method. Also reported: one of these functions' names
 * as a string (a callback: call_user_func( 'update_option', ... ) is out of
 * reach of the first check), and $wpdb->options or $wpdb->sitemeta (rows
 * written past the API). The registry is read through PHPStan's
 * reflection: the plugin's classes exit when loaded outside WordPress.
 * Support\Options itself passes its parameter on; its callers are the
 * ones checked. The rule checks that a name is registered, not the table
 * it lands in: a subsite's option is not carried by a restore either way.
 *
 * @implements Rule<Node>
 */
final class StoredNamesRule implements Rule {

	const REGISTRY = 'WPCheckpoint\\Support\\StoredNames';
	const WRAPPER  = 'WPCheckpoint\\Support\\Options';

	/**
	 * Functions => [position of the name, whether the call only reads].
	 */
	const FUNCTIONS = array(
		'get_option'            => array( 0, true ),
		'update_option'         => array( 0, false ),
		'add_option'            => array( 0, false ),
		'delete_option'         => array( 0, false ),
		'get_site_option'       => array( 0, true ),
		'update_site_option'    => array( 0, false ),
		'add_site_option'       => array( 0, false ),
		'delete_site_option'    => array( 0, false ),
		'get_transient'         => array( 0, true ),
		'set_transient'         => array( 0, false ),
		'delete_transient'      => array( 0, false ),
		'get_site_transient'    => array( 0, true ),
		'set_site_transient'    => array( 0, false ),
		'delete_site_transient' => array( 0, false ),
		'get_blog_option'       => array( 1, true ),
		'update_blog_option'    => array( 1, false ),
		'add_blog_option'       => array( 1, false ),
		'delete_blog_option'    => array( 1, false ),
		'get_network_option'    => array( 1, true ),
		'update_network_option' => array( 1, false ),
		'add_network_option'    => array( 1, false ),
		'delete_network_option' => array( 1, false ),
	);

	/**
	 * Support\Options methods => whether the call only reads.
	 */
	const WRAPPER_METHODS = array(
		'get'    => true,
		'set'    => false,
		'delete' => false,
	);

	/**
	 * Reflection.
	 *
	 * @var ReflectionProvider
	 */
	private $reflection;

	/**
	 * StoredNames::EXACT, once read.
	 *
	 * @var array<string, true>|null
	 */
	private $exact = null;

	/**
	 * StoredNames::BUILDERS, once read.
	 *
	 * @var array<string, true>|null
	 */
	private $builders = null;

	public function __construct( ReflectionProvider $reflection ) {
		$this->reflection = $reflection;
	}

	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( $node instanceof Node\Scalar\String_ && isset( self::FUNCTIONS[ strtolower( ltrim( $node->value, '\\' ) ) ] ) ) {
			return array( self::error( sprintf( '\'%s\' as a name: a callback of the options or transients API is out of reach of this rule; call it directly.', $node->value ) ) );
		}
		if ( $node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && 'wpdb' === $node->var->name && $node->name instanceof Node\Identifier && in_array( $node->name->toString(), array( 'options', 'sitemeta' ), true ) ) {
			return array( self::error( sprintf( '$wpdb->%s: write options and site options through the API, with a name from StoredNames.', $node->name->toString() ) ) );
		}
		$call = self::call_of( $node, $scope );
		if ( null === $call ) {
			return array();
		}
		list( $label, $position, $read ) = $call;
		/** @var FuncCall|StaticCall $node */
		$args = $node->getArgs();
		if ( ! isset( $args[ $position ] ) ) {
			return array();
		}
		$class = $scope->getClassReflection();
		if ( null !== $class && self::WRAPPER === $class->getName() ) {
			return array();
		}
		$name = $args[ $position ]->value;
		if ( $name instanceof StaticCall && $name->class instanceof Name && self::REGISTRY === $scope->resolveName( $name->class ) && $name->name instanceof Node\Identifier && isset( $this->builders()[ strtolower( $name->name->toString() ) ] ) ) {
			return array();
		}
		$strings = $scope->getType( $name )->getConstantStrings();
		if ( array() === $strings ) {
			return array( self::error( sprintf( '%s(): a name built at run time; build it with one of StoredNames\' builders.', $label ) ) );
		}
		$exact = $this->exact();
		foreach ( $strings as $string ) {
			$value = $string->getValue();
			if ( isset( $exact[ $value ] ) ) {
				continue;
			}
			if ( $read && 0 !== strncasecmp( $value, 'wpcheckpoint_', 13 ) ) {
				continue; // WordPress's own option.
			}
			return array( self::error( sprintf( '%s(): "%s" is not in StoredNames::EXACT; a restore would not carry it.', $label, $value ) ) );
		}
		return array();
	}

	/**
	 * The call's label, the position of its name and whether it only reads; null for any other node.
	 *
	 * @return array{0: string, 1: int, 2: bool}|null
	 */
	private static function call_of( Node $node, Scope $scope ) {
		if ( $node instanceof FuncCall && $node->name instanceof Name ) {
			$function = strtolower( ltrim( $node->name->toString(), '\\' ) );
			if ( isset( self::FUNCTIONS[ $function ] ) ) {
				return array( $function, self::FUNCTIONS[ $function ][0], self::FUNCTIONS[ $function ][1] );
			}
			return null;
		}
		if ( $node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Node\Identifier && self::WRAPPER === $scope->resolveName( $node->class ) ) {
			$method = strtolower( $node->name->toString() );
			if ( isset( self::WRAPPER_METHODS[ $method ] ) ) {
				return array( 'Options::' . $method, 0, self::WRAPPER_METHODS[ $method ] );
			}
		}
		return null;
	}

	/**
	 * StoredNames::EXACT as a set.
	 *
	 * @return array<string, true>
	 */
	private function exact(): array {
		if ( null === $this->exact ) {
			$this->exact = array();
			if ( $this->reflection->hasClass( self::REGISTRY ) ) {
				$type = $this->reflection->getClass( self::REGISTRY )->getConstant( 'EXACT' )->getValueType();
				foreach ( $type->getConstantArrays() as $array ) {
					foreach ( $array->getValueTypes() as $value ) {
						foreach ( $value->getConstantStrings() as $string ) {
							$this->exact[ $string->getValue() ] = true;
						}
					}
				}
			}
		}
		return $this->exact;
	}

	/**
	 * StoredNames::BUILDERS as a set.
	 *
	 * @return array<string, true>
	 */
	private function builders(): array {
		if ( null === $this->builders ) {
			$this->builders = array();
			if ( $this->reflection->hasClass( self::REGISTRY ) ) {
				$type = $this->reflection->getClass( self::REGISTRY )->getConstant( 'BUILDERS' )->getValueType();
				foreach ( $type->getConstantArrays() as $array ) {
					foreach ( $array->getValueTypes() as $value ) {
						foreach ( $value->getConstantStrings() as $string ) {
							$this->builders[ strtolower( $string->getValue() ) ] = true;
						}
					}
				}
			}
		}
		return $this->builders;
	}

	private static function error( string $message ): IdentifierRuleError {
		return RuleErrorBuilder::message( $message )->identifier( 'wpcheckpoint.storedName' )->build();
	}
}
