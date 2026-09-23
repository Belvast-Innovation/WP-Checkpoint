<?php
/**
 * PHPStan rule: functions a host may take away are called only through
 * Support\HostFunctions.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use WPCheckpoint\Support\HostFunctions;

/**
 * Reports, anywhere but in HostFunctions: a direct call to a governed
 * function; a governed name as a string callback (the first argument of
 * call_user_func(), call_user_func_array(), is_callable() or
 * function_exists(), or a value that may be called later: an array value
 * in an options map, a ternary branch, an assignment, a return). On PHP 8 a
 * disabled function is undefined, so any of these throws on the hosts that
 * disable it.
 *
 * @implements Rule<Node>
 */
final class HostFunctionsRule implements Rule {

	/**
	 * Functions whose first argument is a callback or a function name.
	 */
	const CALLBACK_TAKERS = array( 'call_user_func', 'call_user_func_array', 'is_callable', 'function_exists' );

	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		$class = $scope->getClassReflection();
		if ( null !== $class && HostFunctions::class === $class->getName() ) {
			return array();
		}
		if ( $node instanceof FuncCall && $node->name instanceof Name ) {
			$name = $node->name->toString();
			if ( HostFunctions::governs( $name ) ) {
				return array( self::error( sprintf( 'Call %s() through Support\HostFunctions: hosts disable it, and on PHP 8 a disabled function is undefined.', $name ) ) );
			}
			if ( in_array( strtolower( $name ), self::CALLBACK_TAKERS, true ) && isset( $node->args[0] ) && $node->args[0] instanceof Node\Arg && $node->args[0]->value instanceof String_ && HostFunctions::governs( $node->args[0]->value->value ) ) {
				return array( self::error( sprintf( '%s(\'%s\'): use Support\HostFunctions (available() or its wrapper) instead.', $name, $node->args[0]->value->value ) ) );
			}
		}
		// A governed name as a value that may end up called: an array value (a default option), either branch of a
		// ternary or ?:, the right side of an assignment or ??, a returned value.
		$values = array();
		if ( $node instanceof Node\ArrayItem && null !== $node->key ) {
			// Keyed only: a callback in an options map ('disk_free' => 'disk_free_space'). An unkeyed item is a list of
			// names or the method half of array( Class, 'method' ), which names no PHP function.
			$values[] = $node->value;
		} elseif ( $node instanceof Node\Expr\Ternary ) {
			$values[] = $node->if;
			$values[] = $node->else;
		} elseif ( $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp\Coalesce ) {
			$values[] = $node->expr;
		} elseif ( $node instanceof Node\Expr\BinaryOp\Coalesce ) {
			$values[] = $node->right;
		} elseif ( $node instanceof Node\Stmt\Return_ ) {
			$values[] = $node->expr;
		}
		foreach ( $values as $value ) {
			if ( $value instanceof String_ && HostFunctions::governs( $value->value ) ) {
				return array( self::error( sprintf( '\'%s\' as a callback: use a Support\HostFunctions wrapper instead.', $value->value ) ) );
			}
		}
		return array();
	}

	private static function error( string $message ): IdentifierRuleError {
		return RuleErrorBuilder::message( $message )->identifier( 'wpcheckpoint.hostFunction' )->build();
	}
}
